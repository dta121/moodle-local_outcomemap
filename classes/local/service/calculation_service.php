<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Deterministic evidence ingestion and outcome calculation engine.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\canonical_json;
use local_outcomemap\local\decimal;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Implements the ADR 0003 calculation order for quiz evidence.
 *
 * The engine is idempotent: reprocessing the same graded state is a no-op,
 * regrades supersede evidence and result versions without deleting history,
 * and identical canonical inputs always produce identical numerators,
 * denominators, percentages, bands, hashes, and lineage.
 */
final class calculation_service extends base_service {
    /**
     * Algorithm identifier per ADR 0003.
     */
    public const ALGO_VERSION = 'outcomemap-v1';

    /**
     * Direct evidence type.
     */
    public const TYPE_DIRECT = 'direct';

    /**
     * Inherited (propagated) evidence type.
     */
    public const TYPE_INHERITED = 'inherited';

    /**
     * Graded evidence state.
     */
    public const GRADING_GRADED = 'graded';

    /**
     * Evidence awaiting (manual) grading.
     */
    public const GRADING_PENDING = 'pending';

    /**
     * Result states.
     */
    public const STATE_NOT_ASSESSED = 'not_assessed';
    /**
     * Result state identifier.
     */
    public const STATE_INSUFFICIENT = 'insufficient_evidence';
    /**
     * Result state identifier.
     */
    public const STATE_PENDING = 'calculation_pending';
    /**
     * Result state identifier.
     */
    public const STATE_CALCULATED = 'calculated';
    /**
     * Result state identifier.
     */
    public const STATE_SUPERSEDED = 'superseded';

    /**
     * Result scopes delivered by this milestone.
     */
    public const SCOPE_QUIZ_ATTEMPT = 'quiz_attempt';
    /**
     * Result scope identifier.
     */
    public const SCOPE_ASSESSMENT = 'assessment';
    /**
     * Result scope identifier.
     */
    public const SCOPE_COURSE = 'course';

    /**
     * Recalculate all outcome results affected by one quiz attempt.
     *
     * Runs as a system process: it only reads approved governed records and
     * never turns a draft into an approved state.
     *
     * @param int $quizattemptid Quiz attempt ID.
     * @param string|null $correlationid Correlation UUID for audit lineage.
     * @return array Summary counts keyed by action.
     */
    public static function recalculate_attempt(int $quizattemptid, ?string $correlationid = null): array {
        global $DB;
        $attempt = $DB->get_record('quiz_attempts', ['id' => $quizattemptid]);
        if (!$attempt || (int) $attempt->preview === 1) {
            return ['skipped' => 1];
        }
        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        return self::recalculate_user_assessment(
            (int) $quiz->course,
            (int) $cm->id,
            (int) $attempt->userid,
            $correlationid
        );
    }

    /**
     * Recalculate one user's results for one quiz assessment and its course scope.
     *
     * @param int $courseid Moodle course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid User ID.
     * @param string|null $correlationid Correlation UUID.
     * @return array Summary counts keyed by action.
     */
    public static function recalculate_user_assessment(
        int $courseid,
        int $cmid,
        int $userid,
        ?string $correlationid = null
    ): array {
        global $DB;
        $correlationid = $correlationid ?? uuid::generate();
        $summary = ['results' => 0, 'unchanged' => 0, 'unconfigured' => 0];
        $instances = $DB->get_records('local_outcomemap_cinst', [
            'moodlecourseid' => $courseid,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
        ]);
        foreach ($instances as $cinst) {
            $selection = policy_service::resolve(policy_service::TYPE_ATTEMPT_SELECTION, (int) $cinst->id, $cmid);
            $calculation = policy_service::resolve(policy_service::TYPE_CALCULATION, (int) $cinst->id, $cmid);
            if ($selection === null || $calculation === null) {
                $summary['unconfigured']++;
                continue;
            }
            $selected = self::select_attempts($cmid, $userid, $selection);
            $evidencebyattempt = [];
            foreach ($selected as $attempt) {
                $evidencebyattempt[(int) $attempt->id] = self::ingest_attempt_evidence(
                    $cinst,
                    $attempt,
                    $cmid,
                    $selection,
                    $correlationid
                );
            }
            // Attempt-scope results for each selected attempt.
            foreach ($evidencebyattempt as $attemptid => $evidence) {
                $summary = self::merge(
                    $summary,
                    self::persist_scope_results(
                        $cinst,
                        $userid,
                        self::SCOPE_QUIZ_ATTEMPT,
                        $attemptid,
                        $evidence,
                        $calculation,
                        $correlationid
                    )
                );
            }
            // Assessment-scope result over the selected attempt set.
            $assessmentevidence = array_merge(...array_values($evidencebyattempt + [[]]));
            $summary = self::merge(
                $summary,
                self::persist_scope_results(
                    $cinst,
                    $userid,
                    self::SCOPE_ASSESSMENT,
                    $cmid,
                    $assessmentevidence,
                    $calculation,
                    $correlationid
                )
            );
            // Course-to-date scope across every mapped assessment in the
            // instance, governed by the policy resolved at course level.
            $coursecalculation = policy_service::resolve(
                policy_service::TYPE_CALCULATION,
                (int) $cinst->id,
                null
            );
            if ($coursecalculation !== null) {
                $courseevidence = self::collect_course_evidence($cinst, $userid);
                $summary = self::merge(
                    $summary,
                    self::persist_scope_results(
                        $cinst,
                        $userid,
                        self::SCOPE_COURSE,
                        (int) $cinst->id,
                        $courseevidence,
                        $coursecalculation,
                        $correlationid
                    )
                );
            }
        }
        return $summary;
    }

    /**
     * Mark nonfrozen results depending on a question version's evidence stale.
     *
     * Called when governed mappings change so reconciliation recalculates.
     *
     * @param int $questionversionid Question-version ID.
     * @return int Number of results marked stale.
     */
    public static function mark_stale_for_question_version(int $questionversionid): int {
        global $DB;
        $sql = "SELECT DISTINCT r.id
                  FROM {local_outcomemap_result} r
                  JOIN {local_outcomemap_evidence} e
                        ON e.userid = r.userid AND e.cinstid = r.cinstid AND e.itemverid = r.itemverid
                 WHERE e.questionversionid = :questionversionid AND r.state <> :frozen AND r.supersededby IS NULL";
        $ids = $DB->get_fieldset_sql($sql, [
            'questionversionid' => $questionversionid,
            'frozen' => 'frozen',
        ]);
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'res');
            $DB->set_field_select('local_outcomemap_result', 'stale', 1, "id $insql", $params);
        }
        return count($ids);
    }

    /**
     * Select the governed attempts for one user and quiz.
     *
     * Candidates are completed non-preview attempts. Every ordering ends with
     * the attempt ID as the deterministic tie breaker.
     *
     * @param int $cmid Quiz course-module ID.
     * @param int $userid User ID.
     * @param \stdClass $selection Resolved attempt-selection policy.
     * @return \stdClass[] Selected quiz attempts.
     */
    public static function select_attempts(int $cmid, int $userid, \stdClass $selection): array {
        global $DB;
        $quizid = $DB->get_field('course_modules', 'instance', ['id' => $cmid], MUST_EXIST);
        $candidates = $DB->get_records_select(
            'quiz_attempts',
            'quiz = :quiz AND userid = :userid AND state = :state AND preview = 0',
            ['quiz' => $quizid, 'userid' => $userid, 'state' => 'finished'],
            'timefinish ASC, id ASC'
        );
        if (!$candidates) {
            return [];
        }
        $candidates = array_values($candidates);
        $method = $selection->config['method'];
        if ($method === policy_service::METHOD_QUIZ_GRADE) {
            $method = self::quiz_grade_method((int) $DB->get_field('quiz', 'grademethod', ['id' => $quizid]));
        }
        switch ($method) {
            case policy_service::METHOD_FIRST_COMPLETED:
                return [reset($candidates)];
            case policy_service::METHOD_LATEST_COMPLETED:
                return [end($candidates)];
            case policy_service::METHOD_HIGHEST_GRADED:
                usort($candidates, static function (\stdClass $a, \stdClass $b): int {
                    $agrade = $a->sumgrades === null ? null : decimal::canonical($a->sumgrades, 'sumgrades');
                    $bgrade = $b->sumgrades === null ? null : decimal::canonical($b->sumgrades, 'sumgrades');
                    if ($agrade !== $bgrade) {
                        if ($agrade === null) {
                            return 1;
                        }
                        if ($bgrade === null) {
                            return -1;
                        }
                        $cmp = decimal::cmp($bgrade, $agrade);
                        if ($cmp !== 0) {
                            return $cmp;
                        }
                    }
                    return [$b->timefinish, $b->id] <=> [$a->timefinish, $a->id];
                });
                return [reset($candidates)];
            case policy_service::METHOD_ALL_COMPLETED:
                return $candidates;
            default:
                throw new validation_exception('invalidpolicyconfig', 'method', $method);
        }
    }

    /**
     * Map a Moodle quiz grade method onto a selection method.
     *
     * @param int $grademethod Quiz grade method constant value.
     * @return string Selection method.
     */
    private static function quiz_grade_method(int $grademethod): string {
        return match ($grademethod) {
            2 => policy_service::METHOD_ALL_COMPLETED, // QUIZ_GRADEAVERAGE.
            3 => policy_service::METHOD_FIRST_COMPLETED, // QUIZ_ATTEMPTFIRST.
            4 => policy_service::METHOD_LATEST_COMPLETED, // QUIZ_ATTEMPTLAST.
            default => policy_service::METHOD_HIGHEST_GRADED, // QUIZ_GRADEHIGHEST.
        };
    }

    /**
     * Ingest direct and inherited evidence for one attempt idempotently.
     *
     * Question-engine values are read from DML records at stored precision.
     *
     * @param \stdClass $cinst Course-instance record.
     * @param \stdClass $attempt Quiz attempt record.
     * @param int $cmid Quiz course-module ID.
     * @param \stdClass $selection Resolved attempt-selection policy.
     * @param string $correlationid Correlation UUID.
     * @return \stdClass[] Active evidence rows for the attempt.
     */
    public static function ingest_attempt_evidence(
        \stdClass $cinst,
        \stdClass $attempt,
        int $cmid,
        \stdClass $selection,
        string $correlationid
    ): array {
        global $DB;
        $at = (int) ($attempt->timefinish ?: $attempt->timemodified);
        $questionattempts = $DB->get_records(
            'question_attempts',
            ['questionusageid' => $attempt->uniqueid],
            'slot ASC'
        );
        $active = [];
        foreach ($questionattempts as $qa) {
            $questionversion = $DB->get_record('question_versions', ['questionid' => $qa->questionid]);
            if (!$questionversion) {
                continue;
            }
            $mappings = $DB->get_records_select(
                'local_outcomemap_qmap',
                "questionversionid = :qv AND role = 'assesses' AND status = :status
                    AND effectivefrom <= :at1 AND (effectiveto IS NULL OR effectiveto > :at2)",
                ['qv' => $questionversion->id, 'status' => workflow::APPROVED, 'at1' => $at, 'at2' => $at],
                'id ASC'
            );
            if (!$mappings) {
                continue;
            }
            $grading = $DB->get_records_select(
                'question_attempt_steps',
                'questionattemptid = :qaid AND fraction IS NOT NULL',
                ['qaid' => $qa->id],
                'sequencenumber DESC',
                'id, fraction, timecreated',
                0,
                1
            );
            $gradedstep = $grading ? reset($grading) : null;
            $fraction = $gradedstep === null ? null : decimal::canonical($gradedstep->fraction, 'fraction');
            $maxmark = decimal::canonical($qa->maxmark, 'maxmark');
            // Every direct observation is stored before propagation so the
            // direct-versus-inherited dedupe at a destination sees the full
            // deliberate mapping set for this question attempt.
            $directrows = [];
            foreach ($mappings as $mapping) {
                $direct = self::store_evidence(
                    $cinst,
                    $attempt,
                    $cmid,
                    $qa,
                    $questionversion,
                    $mapping,
                    $selection,
                    $fraction,
                    $maxmark,
                    $gradedstep,
                    $at,
                    null,
                    null,
                    decimal::ONE,
                    $correlationid
                );
                $directrows[] = [$mapping, $direct];
                $active[] = $direct;
            }
            foreach ($directrows as [$mapping, $direct]) {
                foreach (self::propagation_targets((int) $mapping->itemverid, $at) as $target) {
                    $inherited = self::store_evidence(
                        $cinst,
                        $attempt,
                        $cmid,
                        $qa,
                        $questionversion,
                        $mapping,
                        $selection,
                        $fraction,
                        $maxmark,
                        $gradedstep,
                        $at,
                        $direct,
                        $target,
                        $target->cumulativeweight,
                        $correlationid
                    );
                    if ($inherited !== null) {
                        $active[] = $inherited;
                    }
                }
            }
        }
        return $active;
    }

    /**
     * Store or reuse one evidence row keyed by its canonical dedupe hash.
     *
     * @param \stdClass $cinst Course instance.
     * @param \stdClass $attempt Quiz attempt.
     * @param int $cmid Assessment course-module ID.
     * @param \stdClass $qa Question attempt row.
     * @param \stdClass $questionversion Question version row.
     * @param \stdClass $mapping Approved assesses mapping.
     * @param \stdClass $selection Attempt-selection policy.
     * @param string|null $fraction Canonical graded fraction or null.
     * @param string $maxmark Canonical maximum mark.
     * @param \stdClass|null $gradedstep Latest graded step or null.
     * @param int $at Scope timestamp.
     * @param \stdClass|null $source Direct evidence row for inherited rows.
     * @param \stdClass|null $target Propagation target for inherited rows.
     * @param string $relationweight Cumulative relation weight.
     * @param string $correlationid Correlation UUID.
     * @return \stdClass|null The active evidence row, or null when skipped.
     */
    private static function store_evidence(
        \stdClass $cinst,
        \stdClass $attempt,
        int $cmid,
        \stdClass $qa,
        \stdClass $questionversion,
        \stdClass $mapping,
        \stdClass $selection,
        ?string $fraction,
        string $maxmark,
        ?\stdClass $gradedstep,
        int $at,
        ?\stdClass $source,
        ?\stdClass $target,
        string $relationweight,
        string $correlationid
    ): ?\stdClass {
        global $DB;
        $itemverid = $target === null ? (int) $mapping->itemverid : (int) $target->itemverid;
        $relationpath = $target === null ? null : canonical_json::encode($target->relationids);
        // One lineage may contribute once per destination: a deliberate direct
        // mapping of the same question attempt at the destination outweighs an
        // inherited path, and only the selected best path is stored.
        if ($target !== null) {
            $duplicate = $DB->record_exists_select(
                'local_outcomemap_evidence',
                'questionattemptid = :qaid AND itemverid = :itemverid AND policyid = :policyid
                    AND evidencetype = :direct AND supersededby IS NULL',
                [
                    'qaid' => $qa->id,
                    'itemverid' => $itemverid,
                    'policyid' => $selection->id,
                    'direct' => self::TYPE_DIRECT,
                ]
            );
            if ($duplicate) {
                return null;
            }
        }
        $gradingstate = $fraction === null ? self::GRADING_PENDING : self::GRADING_GRADED;
        $gradingtime = $gradedstep === null ? null : (int) $gradedstep->timecreated;
        $weight = decimal::require_canonical($mapping->weight, 'weight');
        $possible = decimal::mul(decimal::mul($maxmark, $weight), $relationweight);
        $earned = null;
        $rawmark = null;
        if ($fraction !== null) {
            $rawmark = decimal::mul($fraction, $maxmark);
            $earned = decimal::mul(decimal::mul($rawmark, $weight), $relationweight);
        }
        $dedupekey = hash('sha256', canonical_json::encode([
            'algo' => self::ALGO_VERSION,
            'questionattemptid' => (int) $qa->id,
            'questionversionid' => (int) $questionversion->id,
            'mappingid' => (int) $mapping->id,
            'itemverid' => $itemverid,
            'policyid' => (int) $selection->id,
            'relationpath' => $relationpath,
            'gradingstate' => $gradingstate,
            'gradingtime' => $gradingtime,
            'fraction' => $fraction,
            'maxmark' => $maxmark,
        ]));
        $existing = $DB->get_record('local_outcomemap_evidence', ['dedupekey' => $dedupekey]);
        if ($existing) {
            return $existing;
        }
        // The lineage identifier stays stable across regrades of the same
        // observation and mapping.
        if ($source !== null) {
            $lineageuuid = $source->lineageuuid;
        } else {
            $lineageuuid = $DB->get_field_select(
                'local_outcomemap_evidence',
                'lineageuuid',
                'questionattemptid = :qaid AND mappingid = :mappingid AND evidencetype = :type',
                ['qaid' => $qa->id, 'mappingid' => $mapping->id, 'type' => self::TYPE_DIRECT],
                IGNORE_MULTIPLE
            ) ?: uuid::generate();
        }
        $now = time();
        $record = (object) [
            'uuid' => uuid::generate(),
            'lineageuuid' => $lineageuuid,
            'dedupekey' => $dedupekey,
            'sourceevidenceid' => $source === null ? null : (int) $source->id,
            'relationpathjson' => $relationpath,
            'cinstid' => (int) $cinst->id,
            'userid' => (int) $attempt->userid,
            'assessmentcmid' => $cmid,
            'quizattemptid' => (int) $attempt->id,
            'questionusageid' => (int) $attempt->uniqueid,
            'slot' => (int) $qa->slot,
            'questionattemptid' => (int) $qa->id,
            'questionversionid' => (int) $questionversion->id,
            'questionid' => (int) $qa->questionid,
            'itemverid' => $itemverid,
            'mappingid' => (int) $mapping->id,
            'policyid' => (int) $selection->id,
            'evidencetype' => $source === null ? self::TYPE_DIRECT : self::TYPE_INHERITED,
            'rawfraction' => $fraction,
            'rawmark' => $rawmark,
            'maxmark' => $maxmark,
            'mappingweight' => $weight,
            'relationweight' => $relationweight,
            'weightedearned' => $earned,
            'weightedpossible' => $possible,
            'gradingstate' => $gradingstate,
            'attempttime' => $at,
            'gradingtime' => $gradingtime,
            'supersededby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record('local_outcomemap_evidence', $record);
            $record->id = $id;
            // A changed grading revision supersedes the prior authoritative
            // observation without deleting the audit trail. A new deliberate
            // direct mapping also supersedes previously propagated evidence at
            // the same destination so one observation never counts twice.
            $select = 'questionattemptid = :qaid AND itemverid = :itemverid AND policyid = :policyid
                AND supersededby IS NULL AND id <> :id AND (mappingid = :mappingid';
            $params = [
                'qaid' => $qa->id,
                'mappingid' => $mapping->id,
                'itemverid' => $itemverid,
                'policyid' => $selection->id,
                'id' => $id,
            ];
            if ($source === null) {
                $select .= ' OR evidencetype = :inherited';
                $params['inherited'] = self::TYPE_INHERITED;
            }
            $select .= ')';
            $previous = $DB->get_records_select('local_outcomemap_evidence', $select, $params);
            foreach ($previous as $old) {
                $DB->set_field('local_outcomemap_evidence', 'supersededby', $id, ['id' => $old->id]);
                audit_writer::write(
                    'supersede',
                    'evidence',
                    (int) $old->id,
                    $old->uuid,
                    $old,
                    $record,
                    null,
                    \context_course::instance((int) $cinst->moodlecourseid),
                    null,
                    $correlationid
                );
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
        return $record;
    }

    /**
     * Resolve deduplicated propagation targets for a directly assessed outcome.
     *
     * Traverses approved effective contributes_to relations; when several
     * paths reach one destination the greatest absolute cumulative weight
     * wins, then the lexicographically smallest relation-ID sequence.
     *
     * @param int $itemverid Directly assessed outcome-version ID.
     * @param int $at Scope timestamp.
     * @return \stdClass[] Targets with itemverid, cumulativeweight, relationids.
     */
    public static function propagation_targets(int $itemverid, int $at): array {
        global $DB;
        $itemid = (int) $DB->get_field('local_outcomemap_itemver', 'itemid', ['id' => $itemverid], MUST_EXIST);
        $best = [];
        $queue = [[$itemid, decimal::ONE, []]];
        $visited = [$itemid => true];
        while ($queue) {
            [$currentitem, $weight, $path] = array_shift($queue);
            $relations = $DB->get_records_select(
                'local_outcomemap_rel',
                "sourceitemid = :source AND type = 'contributes_to' AND status = :status
                    AND effectivefrom <= :at1 AND (effectiveto IS NULL OR effectiveto > :at2)",
                ['source' => $currentitem, 'status' => workflow::APPROVED, 'at1' => $at, 'at2' => $at],
                'id ASC'
            );
            foreach ($relations as $relation) {
                $targetitem = (int) $relation->targetitemid;
                $newpath = array_merge($path, [(int) $relation->id]);
                if (count($newpath) > 20) {
                    continue; // Corruption defence beyond cycle detection.
                }
                $relationweight = $relation->weight === null
                    ? decimal::ONE
                    : decimal::require_canonical($relation->weight, 'weight');
                $cumulative = decimal::mul($weight, $relationweight);
                $current = $best[$targetitem] ?? null;
                $better = $current === null;
                if (!$better) {
                    $cmp = decimal::cmp($cumulative, $current['weight']);
                    $better = $cmp > 0 || ($cmp === 0 && $newpath < $current['path']);
                }
                if ($better) {
                    $best[$targetitem] = ['weight' => $cumulative, 'path' => $newpath];
                }
                if (!isset($visited[$targetitem])) {
                    $visited[$targetitem] = true;
                    $queue[] = [$targetitem, $cumulative, $newpath];
                }
            }
        }
        $targets = [];
        ksort($best);
        foreach ($best as $targetitem => $info) {
            $targetversion = $DB->get_records_select(
                'local_outcomemap_itemver',
                'itemid = :itemid AND status = :status
                    AND effectivefrom <= :at1 AND (effectiveto IS NULL OR effectiveto > :at2)',
                ['itemid' => $targetitem, 'status' => workflow::APPROVED, 'at1' => $at, 'at2' => $at],
                'version DESC',
                '*',
                0,
                1
            );
            if (!$targetversion) {
                continue;
            }
            $targetversion = reset($targetversion);
            $targets[] = (object) [
                'itemverid' => (int) $targetversion->id,
                'cumulativeweight' => $info['weight'],
                'relationids' => $info['path'],
            ];
        }
        return $targets;
    }

    /**
     * Aggregate an evidence set and persist versioned results per outcome.
     *
     * @param \stdClass $cinst Course instance.
     * @param int $userid User ID.
     * @param string $scopetype Result scope type.
     * @param int $scopeid Result scope ID.
     * @param \stdClass[] $evidence Active evidence rows in the scope.
     * @param \stdClass $calculation Resolved calculation policy.
     * @param string $correlationid Correlation UUID.
     * @return array Summary counts.
     */
    private static function persist_scope_results(
        \stdClass $cinst,
        int $userid,
        string $scopetype,
        int $scopeid,
        array $evidence,
        \stdClass $calculation,
        string $correlationid
    ): array {
        $summary = ['results' => 0, 'unchanged' => 0, 'unconfigured' => 0];
        $byoutcome = [];
        foreach ($evidence as $row) {
            if ($row->supersededby !== null) {
                continue;
            }
            $byoutcome[(int) $row->itemverid][] = $row;
        }
        foreach ($byoutcome as $itemverid => $rows) {
            $changed = self::persist_result(
                $cinst,
                $userid,
                $scopetype,
                $scopeid,
                $itemverid,
                $rows,
                $calculation,
                $correlationid
            );
            $summary[$changed ? 'results' : 'unchanged']++;
        }
        return $summary;
    }

    /**
     * Calculate and persist one outcome result following the fixed state order.
     *
     * @param \stdClass $cinst Course instance.
     * @param int $userid User ID.
     * @param string $scopetype Result scope type.
     * @param int $scopeid Result scope ID.
     * @param int $itemverid Outcome-version ID.
     * @param \stdClass[] $rows Active evidence rows for the outcome.
     * @param \stdClass $calculation Resolved calculation policy.
     * @param string $correlationid Correlation UUID.
     * @return bool Whether a new result version was created.
     */
    private static function persist_result(
        \stdClass $cinst,
        int $userid,
        string $scopetype,
        int $scopeid,
        int $itemverid,
        array $rows,
        \stdClass $calculation,
        string $correlationid
    ): bool {
        global $DB;
        // Aggregate in stable evidence UUID order.
        usort($rows, static fn(\stdClass $a, \stdClass $b): int => strcmp($a->uuid, $b->uuid));
        $config = $calculation->config;
        $pending = array_filter($rows, static fn(\stdClass $row): bool =>
            $row->gradingstate !== self::GRADING_GRADED);
        $graded = array_filter($rows, static fn(\stdClass $row): bool =>
            $row->gradingstate === self::GRADING_GRADED);

        $numerator = decimal::ZERO;
        $denominator = decimal::ZERO;
        $distinct = [];
        foreach ($graded as $row) {
            $numerator = decimal::add($numerator, decimal::canonical($row->weightedearned, 'weightedearned'));
            $denominator = decimal::add($denominator, decimal::canonical($row->weightedpossible, 'weightedpossible'));
            $distinct[(int) $row->questionversionid] = true;
        }
        $distinctitems = count($distinct);
        $percentage = null;
        $bandid = null;
        if (!$rows) {
            $state = self::STATE_NOT_ASSESSED;
        } else if ($pending && !empty($config['requiremanualgrading'])) {
            $state = self::STATE_PENDING;
        } else if (
            !$graded
                || $distinctitems < (int) ($config['minitems'] ?? 1)
                || decimal::is_zero($denominator)
                || (isset($config['minweightedpossible'])
                    && decimal::cmp($denominator, $config['minweightedpossible']) < 0)
        ) {
            $state = self::STATE_INSUFFICIENT;
        } else {
            $state = self::STATE_CALCULATED;
            $percentage = decimal::div(decimal::mul($numerator, '100'), $denominator);
            $band = policy_service::match_band($calculation->bands, $percentage);
            $bandid = $band === null ? null : (int) $band->id;
        }

        $resultkey = hash('sha256', canonical_json::encode([
            'scopetype' => $scopetype,
            'scopeid' => $scopeid,
            'userid' => $userid,
            'cinstid' => (int) $cinst->id,
            'itemverid' => $itemverid,
            'policyuuid' => $calculation->policyuuid,
        ]));
        $lineage = [];
        foreach ($rows as $row) {
            $lineage[] = [
                'uuid' => $row->uuid,
                'lineage' => $row->lineageuuid,
                'type' => $row->evidencetype,
                'earned' => $row->weightedearned === null
                    ? null : decimal::canonical($row->weightedearned, 'weightedearned'),
                'possible' => decimal::canonical($row->weightedpossible, 'weightedpossible'),
                'path' => $row->relationpathjson,
            ];
        }
        $lineagejson = canonical_json::encode($lineage);
        $inputhash = hash('sha256', canonical_json::encode([
            'algo' => self::ALGO_VERSION,
            'plugin' => (string) get_config('local_outcomemap', 'version'),
            'policy' => [(int) $calculation->id, $calculation->confighash],
            'evidence' => $lineage,
            'state' => $state,
        ]));
        $current = $DB->get_records_select(
            'local_outcomemap_result',
            'resultkey = :resultkey AND supersededby IS NULL',
            ['resultkey' => $resultkey],
            'version DESC',
            '*',
            0,
            1
        );
        $current = $current ? reset($current) : null;
        if ($current && $current->inputhash === $inputhash) {
            if ((int) $current->stale === 1) {
                $DB->set_field('local_outcomemap_result', 'stale', 0, ['id' => $current->id]);
            }
            return false;
        }
        $now = time();
        $record = (object) [
            'uuid' => uuid::generate(),
            'resultkey' => $resultkey,
            'version' => $current === null ? 1 : ((int) $current->version) + 1,
            'cinstid' => (int) $cinst->id,
            'userid' => $userid,
            'scopetype' => $scopetype,
            'scopeid' => $scopeid,
            'periodcode' => $cinst->periodcode,
            'itemverid' => $itemverid,
            'policyid' => (int) $calculation->id,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'percentage' => $percentage,
            'distinctitems' => $distinctitems,
            'bandid' => $bandid,
            'state' => $state,
            'stale' => 0,
            'algoversion' => self::ALGO_VERSION,
            'inputhash' => $inputhash,
            'lineagejson' => $lineagejson,
            'lineagehash' => hash('sha256', $lineagejson),
            'supersededby' => null,
            'timecalculated' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record('local_outcomemap_result', $record);
            $record->id = $id;
            if ($current !== null) {
                $DB->update_record('local_outcomemap_result', (object) [
                    'id' => $current->id,
                    'state' => self::STATE_SUPERSEDED,
                    'supersededby' => $id,
                    'stale' => 0,
                    'timemodified' => $now,
                ]);
            }
            audit_writer::write(
                'calculate',
                'result',
                $id,
                $record->uuid,
                $current,
                $record,
                null,
                \context_course::instance((int) $cinst->moodlecourseid),
                null,
                $correlationid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
        return true;
    }

    /**
     * Collect the active course-to-date evidence honouring attempt selection.
     *
     * @param \stdClass $cinst Course instance.
     * @param int $userid User ID.
     * @return \stdClass[] Active evidence rows across assessments.
     */
    private static function collect_course_evidence(\stdClass $cinst, int $userid): array {
        global $DB;
        $cmids = $DB->get_fieldset_sql(
            'SELECT DISTINCT assessmentcmid FROM {local_outcomemap_evidence}
              WHERE cinstid = :cinstid AND userid = :userid',
            ['cinstid' => $cinst->id, 'userid' => $userid]
        );
        $evidence = [];
        foreach ($cmids as $cmid) {
            $selection = policy_service::resolve(policy_service::TYPE_ATTEMPT_SELECTION, (int) $cinst->id, (int) $cmid);
            if ($selection === null) {
                continue;
            }
            $selected = self::select_attempts((int) $cmid, $userid, $selection);
            if (!$selected) {
                continue;
            }
            [$insql, $params] = $DB->get_in_or_equal(array_map(
                static fn(\stdClass $attempt): int => (int) $attempt->id,
                $selected
            ), SQL_PARAMS_NAMED, 'att');
            $params += ['cinstid' => $cinst->id, 'userid' => $userid, 'cmid' => $cmid];
            $rows = $DB->get_records_select(
                'local_outcomemap_evidence',
                "cinstid = :cinstid AND userid = :userid AND assessmentcmid = :cmid
                    AND supersededby IS NULL AND quizattemptid $insql",
                $params
            );
            $evidence = array_merge($evidence, array_values($rows));
        }
        return $evidence;
    }

    /**
     * Merge two summary count arrays.
     *
     * @param array $a Summary counts.
     * @param array $b Summary counts.
     * @return array Combined counts.
     */
    private static function merge(array $a, array $b): array {
        foreach ($b as $key => $count) {
            $a[$key] = ($a[$key] ?? 0) + $count;
        }
        return $a;
    }
}
