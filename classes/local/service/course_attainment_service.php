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
 * Cohort-level outcome attainment for one Moodle course.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\workflow;

/**
 * Summarises stored course-scope results across a course's learners.
 *
 * Reads only authoritative, non-superseded results at
 * {@see calculation_service::SCOPE_COURSE}. Nothing is recomputed here: a
 * figure on this page is the same figure the calculation engine stored, so a
 * report and a learner's own page can never disagree.
 */
final class course_attainment_service extends base_service {
    /** Measured, and most assessed learners sit above the lowest band. */
    public const STATE_ATTAINED = 'attained';

    /** Measured, and at least half of the assessed learners sit in the lowest band. */
    public const STATE_ATTENTION = 'attention';

    /** Assessing content is mapped, but no result has been stored yet. */
    public const STATE_PENDING = 'pending';

    /** No assessing content is mapped, so no result can ever be stored. */
    public const STATE_UNASSESSED = 'unassessed';

    /**
     * Share of assessed learners in the lowest band that flags an outcome.
     *
     * A display threshold for sorting readers towards the outcomes worth reading
     * first, not a governed one: the pass decision itself lives in the bands the
     * calculation policy defined.
     */
    public const ATTENTION_SHARE = 0.5;

    /**
     * Explain why a course holds no outcome results.
     *
     * Reports each gate the evidence pipeline applies, in the order it applies
     * them, so an empty page names its own cause instead of listing the two
     * causes readers guess at. The in-force test deliberately mirrors
     * {@see calculation_service::ingest_attempt_evidence()} rather than
     * approximating it: a diagnosis that disagreed with the engine would be
     * worse than none.
     *
     * @param int $courseid Moodle course ID.
     * @param int|null $at Evaluation timestamp; defaults to now.
     * @return \stdClass Counts, boundary timestamps, and the resolved cause.
     */
    public static function diagnose(int $courseid, ?int $at = null): \stdClass {
        global $DB;
        $context = \context_course::instance($courseid, MUST_EXIST);
        require_capability('local/outcomemap:viewallresults', $context);
        $at = $at ?? time();

        $quizjoin = "FROM {quiz_attempts} qa
                     JOIN {quiz} q ON q.id = qa.quiz
                     JOIN {course_modules} cm ON cm.instance = q.id
                     JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                    WHERE cm.course = :courseid AND qa.state = 'finished' AND qa.preview = 0";
        $params = ['courseid' => $courseid];
        $attempts = (int) $DB->get_field_sql("SELECT COUNT(qa.id) $quizjoin", $params);
        $lastfinish = (int) $DB->get_field_sql("SELECT MAX(qa.timefinish) $quizjoin", $params);

        // Attempts reachable by an approved assessed mapping, ignoring dates.
        $mappingjoin = "JOIN {question_attempts} sqa ON sqa.questionusageid = qa.uniqueid
                        JOIN {question_versions} sqv ON sqv.questionid = sqa.questionid
                        JOIN {local_outcomemap_qmap} sm ON sm.questionversionid = sqv.id
                             AND sm.role = :role AND sm.status = :status";
        $mapparams = $params + ['role' => content_mapping_service::ROLE_ASSESSES,
            'status' => workflow::APPROVED];
        $mapped = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT qa.id) FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {course_modules} cm ON cm.instance = q.id
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
               $mappingjoin
              WHERE cm.course = :courseid AND qa.state = 'finished' AND qa.preview = 0",
            $mapparams
        );
        $mappings = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT sm.id) FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {course_modules} cm ON cm.instance = q.id
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
               $mappingjoin
              WHERE cm.course = :courseid AND qa.state = 'finished' AND qa.preview = 0",
            $mapparams
        );
        $firstmapping = (int) $DB->get_field_sql(
            "SELECT MIN(sm.effectivefrom) FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {course_modules} cm ON cm.instance = q.id
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
               $mappingjoin
              WHERE cm.course = :courseid AND qa.state = 'finished' AND qa.preview = 0",
            $mapparams
        );

        // The engine's own test: in force at the attempt's finish time.
        $inforce = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT qa.id) FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {course_modules} cm ON cm.instance = q.id
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
               $mappingjoin
              WHERE cm.course = :courseid AND qa.state = 'finished' AND qa.preview = 0
                AND sm.effectivefrom <= COALESCE(NULLIF(qa.timefinish, 0), qa.timemodified)
                AND (sm.effectiveto IS NULL
                     OR sm.effectiveto > COALESCE(NULLIF(qa.timefinish, 0), qa.timemodified))",
            $mapparams
        );

        $instances = $DB->get_records('local_outcomemap_cinst', [
            'moodlecourseid' => $courseid,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
        ], 'periodcode, id', 'id, periodcode');
        $missingpolicies = [];
        $evidence = 0;
        foreach ($instances as $instance) {
            foreach ([policy_service::TYPE_ATTEMPT_SELECTION, policy_service::TYPE_CALCULATION] as $type) {
                if (policy_service::resolve($type, (int) $instance->id, null, $at) === null) {
                    $missingpolicies[$type] = $type;
                }
            }
            $evidence += $DB->count_records('local_outcomemap_evidence', ['cinstid' => $instance->id]);
        }

        $cause = 'unknown';
        if (!$instances) {
            $cause = 'noinstance';
        } else if ($missingpolicies) {
            $cause = 'nopolicy';
        } else if ($mappings === 0) {
            $cause = 'nomappings';
        } else if ($attempts === 0) {
            $cause = 'noattempts';
        } else if ($inforce === 0) {
            $cause = 'notinforce';
        } else if ($evidence === 0) {
            $cause = 'notreconciled';
        } else {
            $cause = 'pendingcalculation';
        }

        return (object) [
            'cause' => $cause,
            'attempts' => $attempts,
            'mappedattempts' => $mapped,
            'mappings' => $mappings,
            'inforceattempts' => $inforce,
            'evidence' => $evidence,
            'lastattemptfinish' => $lastfinish ?: null,
            'firstmappingfrom' => $firstmapping ?: null,
            'missingpolicies' => array_values($missingpolicies),
        ];
    }

    /**
     * Summarise attainment for every outcome the course's learners hold results for.
     *
     * @param int $courseid Moodle course ID.
     * @param int|null $at Evaluation timestamp; defaults to now.
     * @return \stdClass Course instances, outcome rows, and cohort totals.
     */
    public static function summary(int $courseid, ?int $at = null): \stdClass {
        global $DB;
        $context = \context_course::instance($courseid, MUST_EXIST);
        require_capability('local/outcomemap:viewallresults', $context);
        $at = $at ?? time();

        $empty = (object) [
            'rows' => [],
            'learners' => 0,
            'periodcodes' => [],
            'hasinstance' => false,
            'hasalignmentpaths' => false,
            'outcomes' => 0,
            'measured' => 0,
            'average' => null,
            'counts' => [
                self::STATE_ATTENTION => 0,
                self::STATE_ATTAINED => 0,
                self::STATE_PENDING => 0,
                self::STATE_UNASSESSED => 0,
            ],
            'coverageknown' => false,
        ];

        $instances = $DB->get_records('local_outcomemap_cinst', [
            'moodlecourseid' => $courseid,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
        ], 'periodcode, id', 'id, periodcode');
        if (!$instances) {
            return $empty;
        }
        [$cinstsql, $params] = $DB->get_in_or_equal(array_keys($instances), SQL_PARAMS_NAMED, 'ci');
        $params['coursescope'] = calculation_service::SCOPE_COURSE;

        // One row per learner per outcome version, with the band that the
        // governing calculation policy assigned when the result was stored.
        $records = $DB->get_records_sql(
            "SELECT r.id, r.userid, r.itemverid, r.percentage, r.state, r.distinctitems,
                    r.timecalculated, r.periodcode, r.policyid,
                    v.itemid, v.statement, v.shortstatement, v.version AS outcomeversion,
                    i.code AS outcomecode, f.code AS frameworkcode, f.name AS frameworkname,
                    f.ownertype, b.code AS bandcode, b.name AS bandname, b.sortorder AS bandorder
               FROM {local_outcomemap_result} r
               JOIN {local_outcomemap_itemver} v ON v.id = r.itemverid
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
          LEFT JOIN {local_outcomemap_band} b ON b.id = r.bandid
              WHERE r.cinstid $cinstsql
                AND r.supersededby IS NULL
                AND r.scopetype = :coursescope
           ORDER BY f.code, i.code, r.userid",
            $params
        );

        $rows = [];
        $learners = [];
        foreach ($records as $record) {
            $key = (int) $record->itemid;
            if (!isset($rows[$key])) {
                $rows[$key] = (object) [
                    'itemid' => $key,
                    'code' => $record->outcomecode,
                    'frameworkcode' => $record->frameworkcode,
                    'frameworkname' => $record->frameworkname,
                    'ownertype' => $record->ownertype,
                    'version' => (int) $record->outcomeversion,
                    'statement' => (string) $record->statement,
                    'shortstatement' => $record->shortstatement,
                    'learners' => 0,
                    'calculated' => 0,
                    'bands' => [],
                    'percentages' => [],
                    'policyids' => [],
                    'items' => 0,
                    'lastcalculated' => 0,
                ];
            }
            $row = $rows[$key];
            $row->policyids[(int) $record->policyid] = true;
            $row->learners++;
            $learners[(int) $record->userid] = true;
            $row->lastcalculated = max($row->lastcalculated, (int) $record->timecalculated);
            if ($record->percentage === null) {
                continue;
            }
            $row->calculated++;
            $row->percentages[] = (float) $record->percentage;
            $row->items += (int) $record->distinctitems;
            $band = $record->bandcode ?? '';
            if (!isset($row->bands[$band])) {
                $row->bands[$band] = (object) [
                    'code' => $band,
                    'name' => $record->bandname ?? get_string('resultstate_' . $record->state, 'local_outcomemap'),
                    'sortorder' => (int) ($record->bandorder ?? 99),
                    'count' => 0,
                ];
            }
            $row->bands[$band]->count++;
        }

        // An outcome the course is responsible for but holds no result for is the
        // finding this report exists to surface, so it gets a row of its own
        // rather than being silently absent.
        foreach (self::course_outcomes($courseid, $at) as $itemid => $outcome) {
            $rows[$itemid] ??= $outcome;
        }
        uasort($rows, static fn(\stdClass $a, \stdClass $b): int
            => [$a->frameworkcode, $a->code] <=> [$b->frameworkcode, $b->code]);

        // Splitting "no result yet" from "no result ever" needs the mapping side
        // of the pipeline, which is a different capability. Without it the two
        // collapse into the pending state rather than misreporting either.
        $assessed = $rows && has_capability('local/outcomemap:viewdefinitions', $context)
            ? self::assessed_outcomes($courseid)
            : null;

        $lowestbands = self::lowest_band_order($rows);
        $alignmentpaths = self::alignment_paths(array_keys($rows), $at);
        $hasalignmentpaths = false;
        $counts = [
            self::STATE_ATTENTION => 0,
            self::STATE_ATTAINED => 0,
            self::STATE_PENDING => 0,
            self::STATE_UNASSESSED => 0,
        ];
        $measured = [];
        foreach ($rows as $row) {
            $row->average = $row->percentages
                ? array_sum($row->percentages) / count($row->percentages)
                : null;
            $row->unassessed = $row->learners - $row->calculated;
            usort($row->bands, static fn($a, $b) => $a->sortorder <=> $b->sortorder);
            // The band to act on is the lowest one the governing policy defines,
            // not merely the lowest one anybody landed in: when every assessed
            // learner clears the bottom band, nobody is in it and the share is 0.
            $row->lowestband = null;
            $lowestorder = $lowestbands[$row->itemid] ?? null;
            foreach ($row->bands as $band) {
                if ($lowestorder !== null && $band->sortorder === $lowestorder) {
                    $row->lowestband = $band;
                    break;
                }
            }
            $row->lowshare = $row->calculated && $row->lowestband
                ? $row->lowestband->count / $row->calculated
                : 0.0;
            $row->assessedcontent = $assessed === null ? null : isset($assessed[$row->itemid]);
            if ($row->calculated) {
                $row->state = $row->lowshare >= self::ATTENTION_SHARE
                    ? self::STATE_ATTENTION
                    : self::STATE_ATTAINED;
                $measured[] = $row->average;
            } else {
                $row->state = $row->assessedcontent === false
                    ? self::STATE_UNASSESSED
                    : self::STATE_PENDING;
            }
            $counts[$row->state]++;
            $row->alignmentpaths = $alignmentpaths[$row->itemid] ?? [];
            $hasalignmentpaths = $hasalignmentpaths || (bool) $row->alignmentpaths;
            unset($row->percentages, $row->policyids);
        }

        return (object) [
            'rows' => array_values($rows),
            'learners' => count($learners),
            'periodcodes' => array_values(array_unique(array_map(
                static fn($i) => $i->periodcode, $instances))),
            'hasinstance' => true,
            'hasalignmentpaths' => $hasalignmentpaths,
            'outcomes' => count($rows),
            'measured' => count($measured),
            'average' => $measured ? array_sum($measured) / count($measured) : null,
            'counts' => $counts,
            'coverageknown' => $assessed !== null,
        ];
    }

    /**
     * Resolve the sort order of the lowest band each row's policies define.
     *
     * Read from the band definitions rather than from the bands learners landed
     * in, because the two differ in exactly the case that matters: an outcome
     * every assessed learner passed has no result in its bottom band at all.
     *
     * @param array<int,\stdClass> $rows Report rows carrying their policy IDs.
     * @return array<int,int> Lowest defined sort order keyed by outcome item ID.
     */
    private static function lowest_band_order(array $rows): array {
        global $DB;
        $policyids = [];
        foreach ($rows as $row) {
            foreach (array_keys($row->policyids ?? []) as $policyid) {
                $policyids[(int) $policyid] = true;
            }
        }
        if (!$policyids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($policyids), SQL_PARAMS_NAMED, 'pol');
        $orders = [];
        $records = $DB->get_records_sql(
            "SELECT policyid, MIN(sortorder) AS lowestorder
               FROM {local_outcomemap_band}
              WHERE policyid $insql
           GROUP BY policyid",
            $params
        );
        foreach ($records as $record) {
            $orders[(int) $record->policyid] = (int) $record->lowestorder;
        }
        $result = [];
        foreach ($rows as $row) {
            // Several policy versions can govern one row's results, so the bottom
            // band is the lowest any of them defines.
            foreach (array_keys($row->policyids ?? []) as $policyid) {
                if (!isset($orders[(int) $policyid])) {
                    continue;
                }
                $order = $orders[(int) $policyid];
                $result[$row->itemid] = isset($result[$row->itemid])
                    ? min($result[$row->itemid], $order)
                    : $order;
            }
        }
        return $result;
    }

    /**
     * Return the outcomes this course is responsible for, as empty report rows.
     *
     * Scope is the currently effective approved outcomes of the frameworks owned
     * by the catalog courses this Moodle course is linked to through an approved,
     * confirmed instance — the same association that makes a mapping valid.
     *
     * @param int $courseid Moodle course ID.
     * @param int $at Effective timestamp.
     * @return array<int,\stdClass> Zeroed rows keyed by stable outcome item ID.
     */
    private static function course_outcomes(int $courseid, int $at): array {
        global $DB;
        $records = $DB->get_records_sql(
            "SELECT v.id AS itemverid, i.id AS itemid, i.code AS outcomecode,
                    v.version AS outcomeversion, v.statement, v.shortstatement,
                    f.code AS frameworkcode, f.name AS frameworkname, f.ownertype
               FROM {local_outcomemap_cinst} ci
               JOIN {local_outcomemap_fw} f
                 ON f.ownertype = :ownertype AND f.ownerid = ci.courseid
               JOIN {local_outcomemap_item} i ON i.frameworkid = f.id
               JOIN {local_outcomemap_itemver} v ON v.itemid = i.id
              WHERE ci.moodlecourseid = :courseid
                AND ci.status = :cinststatus
                AND ci.confirmed = 1
                AND f.status = :fstatus
                AND i.status = :istatus
                AND v.status = :vstatus
                AND v.effectivefrom <= :at1
                AND (v.effectiveto IS NULL OR v.effectiveto > :at2)
           ORDER BY f.code, i.code, v.version DESC",
            [
                'ownertype' => framework_service::OWNER_COURSE,
                'courseid' => $courseid,
                'cinststatus' => workflow::APPROVED,
                'fstatus' => workflow::APPROVED,
                'istatus' => workflow::APPROVED,
                'vstatus' => workflow::APPROVED,
                'at1' => $at,
                'at2' => $at,
            ]
        );
        $rows = [];
        foreach ($records as $record) {
            $itemid = (int) $record->itemid;
            // Ordered version-descending, so the first row wins if an item somehow
            // has two effective versions at once.
            $rows[$itemid] ??= (object) [
                'itemid' => $itemid,
                'code' => $record->outcomecode,
                'frameworkcode' => $record->frameworkcode,
                'frameworkname' => $record->frameworkname,
                'ownertype' => $record->ownertype,
                'version' => (int) $record->outcomeversion,
                'statement' => (string) $record->statement,
                'shortstatement' => $record->shortstatement,
                'learners' => 0,
                'calculated' => 0,
                'bands' => [],
                'percentages' => [],
                'policyids' => [],
                'items' => 0,
                'lastcalculated' => 0,
            ];
        }
        return $rows;
    }

    /**
     * Return the outcomes some approved assessing mapping covers in this course.
     *
     * Delegates to the coverage projection rather than re-deriving it: a report
     * that disagreed with the coverage page about what is assessed would send
     * readers to fix a gap that page says does not exist.
     *
     * @param int $courseid Moodle course ID.
     * @return array<int,bool> Stable outcome item IDs, keyed for lookup.
     */
    private static function assessed_outcomes(int $courseid): array {
        global $DB;
        $itemverids = [];
        foreach (coverage_service::matrix($courseid) as $itemverid => $row) {
            $status = coverage_service::row_status($row);
            if ($status === coverage_service::STATUS_FULL
                    || $status === coverage_service::STATUS_ASSESSED_ONLY) {
                $itemverids[] = (int) $itemverid;
            }
        }
        if (!$itemverids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($itemverids, SQL_PARAMS_NAMED, 'iv');
        $items = $DB->get_fieldset_sql(
            "SELECT DISTINCT itemid FROM {local_outcomemap_itemver} WHERE id $insql",
            $params
        );
        return array_fill_keys(array_map('intval', $items), true);
    }

    /**
     * Resolve terminal higher-level alignment paths for outcome rows in bulk.
     *
     * Alignment paths provide curriculum context only. The propagates flag is
     * true solely when every edge is contributes_to, matching the calculation
     * engine's evidence-propagation rule; an aligns_to edge never becomes an
     * attainment claim just because it leads to a higher-level outcome.
     *
     * @param int[] $sourceitemids Stable outcome item IDs shown in the report.
     * @param int $at Effective timestamp.
     * @return array<int, array> Terminal paths keyed by source item ID.
     */
    private static function alignment_paths(array $sourceitemids, int $at): array {
        global $DB;
        $sourceitemids = array_values(array_unique(array_map('intval', $sourceitemids)));
        if (!$sourceitemids) {
            return [];
        }

        [$typesql, $params] = $DB->get_in_or_equal([
            relation_service::ALIGNS_TO,
            relation_service::CONTRIBUTES_TO,
        ], SQL_PARAMS_NAMED, 'relationtype');
        $params += [
            'status' => workflow::APPROVED,
            'at1' => $at,
            'at2' => $at,
        ];
        $relations = $DB->get_records_select(
            'local_outcomemap_rel',
            "type $typesql AND status = :status AND effectivefrom <= :at1
                AND (effectiveto IS NULL OR effectiveto > :at2)",
            $params,
            'sourceitemid, id'
        );
        if (!$relations) {
            return [];
        }

        $targetids = array_values(array_unique(array_map(
            static fn($relation): int => (int) $relation->targetitemid,
            $relations
        )));
        [$targetsql, $targetparams] = $DB->get_in_or_equal($targetids, SQL_PARAMS_NAMED, 'target');
        $targets = $DB->get_records_sql(
            "SELECT i.id, i.code, f.code AS frameworkcode, f.name AS frameworkname, f.ownertype
               FROM {local_outcomemap_item} i
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE i.id $targetsql",
            $targetparams
        );

        $versionparams = $targetparams + [
            'versionstatus' => workflow::APPROVED,
            'versionat1' => $at,
            'versionat2' => $at,
        ];
        $versions = $DB->get_records_select(
            'local_outcomemap_itemver',
            "itemid $targetsql AND status = :versionstatus AND effectivefrom <= :versionat1
                AND (effectiveto IS NULL OR effectiveto > :versionat2)",
            $versionparams,
            'itemid, version DESC',
            'id, itemid, statement, shortstatement, version'
        );
        $currentversions = [];
        foreach ($versions as $version) {
            $itemid = (int) $version->itemid;
            $currentversions[$itemid] ??= $version;
        }

        $edges = [];
        foreach ($relations as $relation) {
            $targetid = (int) $relation->targetitemid;
            if (!isset($targets[$targetid], $currentversions[$targetid])) {
                continue;
            }
            $target = $targets[$targetid];
            $version = $currentversions[$targetid];
            $edges[(int) $relation->sourceitemid][] = (object) [
                'relationid' => (int) $relation->id,
                'relationtype' => (string) $relation->type,
                'itemid' => $targetid,
                'frameworkcode' => (string) $target->frameworkcode,
                'frameworkname' => (string) $target->frameworkname,
                'ownertype' => (string) $target->ownertype,
                'code' => (string) $target->code,
                'version' => (int) $version->version,
                'statement' => (string) $version->statement,
                'shortstatement' => $version->shortstatement,
            ];
        }

        $walk = static function (int $current, array $path, array $seen, bool $propagates, int $depth)
                use (&$walk, $edges): array {
            if ($depth >= 20) {
                return $path ? [(object) ['targets' => $path, 'propagates' => $propagates]] : [];
            }
            $next = [];
            foreach ($edges[$current] ?? [] as $edge) {
                if (!isset($seen[$edge->itemid])) {
                    $next[] = $edge;
                }
            }
            if (!$next) {
                return $path ? [(object) ['targets' => $path, 'propagates' => $propagates]] : [];
            }
            $paths = [];
            foreach ($next as $edge) {
                $newseen = $seen;
                $newseen[$edge->itemid] = true;
                $paths = array_merge($paths, $walk(
                    $edge->itemid,
                    array_merge($path, [$edge]),
                    $newseen,
                    $propagates && $edge->relationtype === relation_service::CONTRIBUTES_TO,
                    $depth + 1
                ));
            }
            return $paths;
        };

        $result = [];
        foreach ($sourceitemids as $sourceitemid) {
            $paths = $walk($sourceitemid, [], [$sourceitemid => true], true, 0);
            $unique = [];
            foreach ($paths as $path) {
                $key = implode('>', array_map(static fn($target): int => $target->itemid, $path->targets));
                $unique[$key] = $path;
            }
            $result[$sourceitemid] = array_values($unique);
        }
        return $result;
    }
}
