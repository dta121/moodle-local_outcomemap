<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Learner-safe outcome-result report service.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\decimal;
use local_outcomemap\local\feature;
use local_outcomemap\local\workflow;

/**
 * Builds the current learner's released CLO report from authoritative results.
 */
final class student_result_service {
    /** Synthetic state used when a governed result has not been released. */
    public const STATE_NOT_RELEASED = 'not_released';

    /** Read-time state used instead of exposing stale calculated values. */
    public const STATE_STALE = 'stale';

    /** Programme-level outcome, reached from this course by alignment. */
    public const TIER_PROGRAM = 'program';

    /** Course-level outcome — the skills the course itself promises. */
    public const TIER_COURSE = 'course';

    /** Unit-level outcome, a step within one course-level outcome. */
    public const TIER_UNIT = 'unit';

    /**
     * Return one display-safe row per relevant course learning outcome.
     *
     * Course-scope results are preferred. If no course result exists, the most
     * recent assessment result is used; otherwise a not-assessed course row is
     * synthesized. Stored results retain their exact historical outcome and
     * calculation-policy versions. No question or evidence detail is returned.
     *
     * @param int $courseid Moodle course ID.
     * @param int|null $at Evaluation timestamp; defaults to now.
     * @return array Report data containing courseid, generatedat, and rows.
     */
    public static function get_own_report(int $courseid, ?int $at = null): array {
        global $USER;
        get_course($courseid);
        $context = \context_course::instance($courseid, MUST_EXIST);
        require_capability('local/outcomemap:viewownresults', $context);
        return self::report_for((int) $USER->id, $courseid, $at);
    }

    /**
     * Build the learner-safe report for a specific user.
     *
     * Carries NO capability check of its own: the caller vouches for its
     * authority over $userid's results. The learner-facing page reaches this
     * through get_own_report above; the SIS attainment export reaches it after
     * checking local/outcomemap:exportattainment at system context. Every
     * release gate — lineage verification, module visibility, grade
     * visibility, quiz review state — is still evaluated against $userid, so
     * an authorized caller sees exactly what that learner would see, never
     * more.
     *
     * @param int $userid Learner whose released results are reported.
     * @param int $courseid Moodle course ID.
     * @param int|null $at Evaluation timestamp; defaults to now.
     * @return array Report data containing courseid, generatedat, and rows.
     */
    public static function report_for(int $userid, int $courseid, ?int $at = null): array {
        global $CFG, $DB;
        get_course($courseid);
        $at = $at ?? time();

        $instances = $DB->get_records('local_outcomemap_cinst', [
            'moodlecourseid' => $courseid,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
        ], 'periodcode, id');
        if (!$instances) {
            return ['courseid' => $courseid, 'generatedat' => $at,
                'expectedpercent' => null, 'strongpercent' => null, 'rows' => []];
        }

        $catalogids = [];
        foreach ($instances as $instance) {
            $catalogids[(int) $instance->courseid] = (int) $instance->courseid;
        }
        [$catalogsql, $catalogparams] = $DB->get_in_or_equal(
            array_values($catalogids),
            SQL_PARAMS_NAMED,
            'clocourse'
        );
        // The learner's outcomes are those their catalog course owns — both course
        // and unit level — plus the outcomes of every programme that course belongs
        // to, so a report can show attainment rolling up to programme level.
        $programids = $DB->get_fieldset_sql(
            "SELECT DISTINCT programid
               FROM {local_outcomemap_progcourse}
              WHERE courseid $catalogsql AND status = :pcapproved
                AND effectivefrom <= :pcat1 AND (effectiveto IS NULL OR effectiveto > :pcat2)",
            $catalogparams + ['pcapproved' => workflow::APPROVED, 'pcat1' => $at, 'pcat2' => $at]
        );
        $ownerclauses = ["(f.ownertype = :ownertype AND f.ownerid $catalogsql)"];
        $outcomeparams = $catalogparams + [
            'ownertype' => framework_service::OWNER_COURSE,
            'approved1' => workflow::APPROVED,
            'approved2' => workflow::APPROVED,
            'approved3' => workflow::APPROVED,
            'at1' => $at,
            'at2' => $at,
        ];
        if ($programids) {
            [$programsql, $programparams] = $DB->get_in_or_equal(
                array_map('intval', $programids), SQL_PARAMS_NAMED, 'ploprog');
            $ownerclauses[] = "(f.ownertype = :programowner AND f.ownerid $programsql)";
            $outcomeparams += $programparams + ['programowner' => framework_service::OWNER_PROGRAM];
        }
        $outcomesql = "SELECT v.id, v.itemid, v.shortstatement, v.statement,
                              i.code, f.ownerid AS catalogcourseid, f.ownertype,
                              f.code AS frameworkcode
                         FROM {local_outcomemap_itemver} v
                         JOIN {local_outcomemap_item} i ON i.id = v.itemid
                         JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
                        WHERE (" . implode(' OR ', $ownerclauses) . ")
                          AND f.status = :approved1 AND i.status = :approved2
                          AND v.status = :approved3
                          AND v.effectivefrom <= :at1
                          AND (v.effectiveto IS NULL OR v.effectiveto > :at2)
                     ORDER BY i.code, v.version DESC";
        $outcomes = $DB->get_records_sql($outcomesql, $outcomeparams);

        // Current effective CLO versions supply unassessed rows. The stable
        // item ID is the key so a historical result does not produce a second
        // row when a newer wording version becomes effective.
        $relevant = [];
        foreach ($instances as $instance) {
            foreach ($outcomes as $outcome) {
                // A programme framework's ownerid is the programme, not the catalog
                // course, and the query already limited those to programmes this
                // course belongs to. Course-owned outcomes still match by owner.
                $isprogramme = $outcome->ownertype === framework_service::OWNER_PROGRAM;
                if (!$isprogramme && (int) $outcome->catalogcourseid !== (int) $instance->courseid) {
                    continue;
                }
                $key = (int) $instance->id . ':' . (int) $outcome->itemid;
                if (isset($relevant[$key])) {
                    continue;
                }
                $relevant[$key] = (object) [
                    'key' => $key,
                    'cinstid' => (int) $instance->id,
                    'periodcode' => (string) $instance->periodcode,
                    'itemid' => (int) $outcome->itemid,
                    'itemverid' => (int) $outcome->id,
                    'code' => (string) $outcome->code,
                    'shortstatement' => (string) ($outcome->shortstatement ?? $outcome->statement),
                    'statement' => (string) $outcome->statement,
                    'frameworkcode' => (string) $outcome->frameworkcode,
                    'tier' => self::tier((string) $outcome->ownertype, (string) $outcome->frameworkcode),
                ];
            }
        }

        [$cinstsql, $cinstparams] = $DB->get_in_or_equal(
            array_map('intval', array_keys($instances)),
            SQL_PARAMS_NAMED,
            'resultcinst'
        );
        $resultparams = $cinstparams + [
            'userid' => $userid,
            'coursescope' => calculation_service::SCOPE_COURSE,
            'assessmentscope' => calculation_service::SCOPE_ASSESSMENT,
            'courseowner' => framework_service::OWNER_COURSE,
        ];
        // Stored programme-level results are admitted on the same programme
        // membership used to pick the outcome list above.
        $programresultclause = '';
        if ($programids) {
            [$progresultsql, $progresultparams] = $DB->get_in_or_equal(
                array_map('intval', $programids), SQL_PARAMS_NAMED, 'plores');
            $programresultclause = "OR (f.ownertype = :programresultowner AND f.ownerid {$progresultsql})";
            $resultparams += $progresultparams + ['programresultowner' => framework_service::OWNER_PROGRAM];
        }
        // Result rows own exact version references. Do not require those
        // historical versions or policies to remain effective at view time.
        $resultsql = "SELECT r.*, p.configjson, b.name AS bandname, b.description AS banddescription,
                             v.itemid AS resultitemid, v.shortstatement AS resultshortstatement,
                             v.statement AS resultstatement, i.code AS resultcode,
                             f.code AS resultframeworkcode, f.ownertype AS resultownertype
                        FROM {local_outcomemap_result} r
                        JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
                        JOIN {local_outcomemap_itemver} v ON v.id = r.itemverid
                        JOIN {local_outcomemap_item} i ON i.id = v.itemid
                        JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
                        JOIN {local_outcomemap_policy} p ON p.id = r.policyid
                   LEFT JOIN {local_outcomemap_band} b ON b.id = r.bandid
                       WHERE r.userid = :userid AND r.cinstid $cinstsql
                         AND r.supersededby IS NULL
                         AND r.scopetype IN (:coursescope, :assessmentscope)
                         AND (
                             (f.ownertype = :courseowner AND f.ownerid = ci.courseid)
                             {$programresultclause}
                         )
                    ORDER BY r.timecalculated DESC, r.id DESC";
        $results = $DB->get_records_sql($resultsql, $resultparams);
        $candidates = [];
        foreach ($results as $result) {
            $key = (int) $result->cinstid . ':' . (int) $result->resultitemid;
            $candidates[$key][] = $result;
        }

        $selected = [];
        foreach ($candidates as $key => $rows) {
            $result = self::preferred_result($rows);
            if ($result === null || !isset($instances[(int) $result->cinstid])) {
                continue;
            }
            $selected[$key] = $result;
            $instance = $instances[(int) $result->cinstid];
            // Exact stored wording replaces the current-version descriptor for
            // this stable CLO, preserving historical report resolvability.
            $relevant[$key] = (object) [
                'key' => $key,
                'cinstid' => (int) $result->cinstid,
                'periodcode' => (string) ($result->periodcode ?? $instance->periodcode),
                'itemid' => (int) $result->resultitemid,
                'itemverid' => (int) $result->itemverid,
                'code' => (string) $result->resultcode,
                'shortstatement' => (string) ($result->resultshortstatement ?? $result->resultstatement),
                'statement' => (string) $result->resultstatement,
                'frameworkcode' => (string) $result->resultframeworkcode,
                'tier' => self::tier(
                    (string) $result->resultownertype,
                    (string) $result->resultframeworkcode
                ),
            ];
        }
        if (!$relevant) {
            return ['courseid' => $courseid, 'generatedat' => $at,
                'expectedpercent' => null, 'strongpercent' => null, 'rows' => []];
        }
        uasort($relevant, static function(\stdClass $left, \stdClass $right): int {
            return [$left->code, $left->periodcode, $left->cinstid, $left->itemid]
                <=> [$right->code, $right->periodcode, $right->cinstid, $right->itemid];
        });
        foreach ($relevant as $key => $outcome) {
            if (!array_key_exists($key, $selected)) {
                $selected[$key] = null;
            }
        }

        // Every stored lineage UUID must resolve to matching current evidence.
        // Missing or mismatched provenance makes the release decision fail
        // closed rather than weakening visibility or grading gates.
        $expectedlineage = [];
        $lineagecomplete = [];
        $lineageuuids = [];
        foreach ($relevant as $key => $outcome) {
            $result = $selected[$key];
            $expectedlineage[$key] = [];
            $lineagecomplete[$key] = true;
            if ($result === null) {
                continue;
            }
            if (!hash_equals((string) $result->lineagehash, hash('sha256', (string) $result->lineagejson))) {
                $lineagecomplete[$key] = false;
            }
            $lineage = json_decode($result->lineagejson, true);
            if (!is_array($lineage)) {
                $lineagecomplete[$key] = false;
                continue;
            }
            foreach ($lineage as $entry) {
                $uuid = is_array($entry) ? trim((string) ($entry['uuid'] ?? '')) : '';
                if ($uuid === '' || isset($expectedlineage[$key][$uuid])) {
                    $lineagecomplete[$key] = false;
                    continue;
                }
                $expectedlineage[$key][$uuid] = true;
                $lineageuuids[$uuid] = true;
            }
            if ((int) $result->distinctitems > 0 && !$expectedlineage[$key]) {
                $lineagecomplete[$key] = false;
            }
        }

        $evidencebyuuid = [];
        if ($lineageuuids) {
            [$evidencesql, $evidenceparams] = $DB->get_in_or_equal(
                array_keys($lineageuuids),
                SQL_PARAMS_NAMED,
                'evidenceuuid'
            );
            $evidence = $DB->get_records_select(
                'local_outcomemap_evidence',
                "uuid $evidencesql",
                $evidenceparams,
                '',
                'id, uuid, cinstid, userid, itemverid, assessmentcmid, quizattemptid, questionusageid, supersededby'
            );
            foreach ($evidence as $record) {
                $evidencebyuuid[$record->uuid] = $record;
            }
        }

        $scopes = [];
        $allattemptids = [];
        $allcmids = [];
        foreach ($relevant as $key => $outcome) {
            $result = $selected[$key];
            $cmids = [];
            $attemptidsbycm = [];
            $usageidsbyattempt = [];
            if ($result !== null && $result->scopetype === calculation_service::SCOPE_ASSESSMENT) {
                $cmids[(int) $result->scopeid] = (int) $result->scopeid;
            }
            foreach (array_keys($expectedlineage[$key]) as $uuid) {
                if (!isset($evidencebyuuid[$uuid])) {
                    $lineagecomplete[$key] = false;
                    continue;
                }
                $evidence = $evidencebyuuid[$uuid];
                if ((int) $evidence->cinstid !== (int) $outcome->cinstid
                        || (int) $evidence->userid !== $userid
                        || $result === null
                        || (int) $evidence->itemverid !== (int) $result->itemverid
                        || $evidence->supersededby !== null
                        || (int) $evidence->assessmentcmid < 1
                        || (int) $evidence->quizattemptid < 1
                        || (int) $evidence->questionusageid < 1) {
                    $lineagecomplete[$key] = false;
                    continue;
                }
                $cmid = (int) $evidence->assessmentcmid;
                $attemptid = (int) $evidence->quizattemptid;
                if ($result->scopetype === calculation_service::SCOPE_ASSESSMENT
                        && $cmid !== (int) $result->scopeid) {
                    $lineagecomplete[$key] = false;
                    continue;
                }
                $cmids[$cmid] = $cmid;
                $attemptidsbycm[$cmid][$attemptid] = $attemptid;
                $usageid = (int) $evidence->questionusageid;
                $usageidsbyattempt[$attemptid][$usageid] = $usageid;
                $allattemptids[$attemptid] = $attemptid;
            }
            $scopes[$key] = [
                'cmids' => array_values($cmids),
                'attemptidsbycm' => $attemptidsbycm,
                'usageidsbyattempt' => $usageidsbyattempt,
                'lineagecomplete' => $lineagecomplete[$key],
            ];
            $allcmids += $cmids;
        }

        $attempts = $allattemptids
            ? $DB->get_records_list(
                'quiz_attempts',
                'id',
                array_values($allattemptids),
                '',
                'id, quiz, userid, uniqueid, state, preview, timefinish'
            )
            : [];
        $modinfo = get_fast_modinfo($courseid, $userid);
        $cms = $modinfo->get_cms();
        foreach ($scopes as $key => $scope) {
            foreach ($scope['cmids'] as $cmid) {
                if (!isset($cms[$cmid]) || $cms[$cmid]->modname !== 'quiz') {
                    $scopes[$key]['lineagecomplete'] = false;
                    continue;
                }
                foreach ($scope['attemptidsbycm'][$cmid] ?? [] as $attemptid) {
                    $usageids = $scope['usageidsbyattempt'][$attemptid] ?? [];
                    if (!isset($attempts[$attemptid])
                            || (int) $attempts[$attemptid]->userid !== $userid
                            || (int) $attempts[$attemptid]->quiz !== (int) $cms[$cmid]->instance
                            || $attempts[$attemptid]->state !== 'finished'
                            || !empty($attempts[$attemptid]->preview)
                            || count($usageids) !== 1
                            || !isset($usageids[(int) $attempts[$attemptid]->uniqueid])) {
                        $scopes[$key]['lineagecomplete'] = false;
                    }
                }
            }
        }

        $gradevisible = self::grade_visibility($courseid, $userid, array_values($allcmids), $at);
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $quizcloserecords = quiz_get_user_timeclose($courseid);
        $quizclosetimes = [];
        foreach ($allcmids as $cmid) {
            if (!isset($cms[$cmid]) || $cms[$cmid]->modname !== 'quiz') {
                continue;
            }
            $quizid = (int) $cms[$cmid]->instance;
            $quizclosetimes[$cmid] = isset($quizcloserecords[$quizid])
                ? (int) $quizcloserecords[$quizid]->usertimeclose : 0;
        }

        // A course aggregate must satisfy the resolved release policy for
        // every contributing assessment. This prevents a broad policy from
        // bypassing a more restrictive assessment policy.
        $policyrequests = [];
        $releasekeys = [];
        $releasecmids = [];
        foreach ($relevant as $key => $outcome) {
            $cmids = $scopes[$key]['cmids'];
            if (!$cmids) {
                $requestkey = $key . '|0';
                $policyrequests[$requestkey] = ['cinstid' => $outcome->cinstid, 'cmid' => null];
                $releasekeys[$key][] = $requestkey;
                $releasecmids[$requestkey] = null;
                continue;
            }
            foreach ($cmids as $cmid) {
                $requestkey = $key . '|' . $cmid;
                $policyrequests[$requestkey] = ['cinstid' => $outcome->cinstid, 'cmid' => $cmid];
                $releasekeys[$key][] = $requestkey;
                $releasecmids[$requestkey] = $cmid;
            }
        }
        $releasepolicies = policy_service::resolve_many(policy_service::TYPE_RELEASE, $policyrequests, $at);
        $releasepolicyids = [];
        foreach ($releasepolicies as $policy) {
            if ($policy !== null) {
                $releasepolicyids[(int) $policy->id] = (int) $policy->id;
            }
        }
        $manualreleases = policy_service::manual_release_times(array_values($releasepolicyids));

        $rows = [];
        $remediationrequests = [];
        foreach ($relevant as $key => $outcome) {
            $result = $selected[$key];
            $scope = $scopes[$key];
            $combined = (object) [
                'released' => true,
                'mode' => null,
                'releasedat' => null,
            ];
            foreach ($releasekeys[$key] as $requestkey) {
                $cmid = $releasecmids[$requestkey];
                $policy = $releasepolicies[$requestkey] ?? null;
                $scopeattempts = [];
                $assessmentcmids = [];
                $accessible = $scope['lineagecomplete'];
                if ($cmid !== null) {
                    $assessmentcmids = [$cmid];
                    $accessible = $accessible && isset($cms[$cmid]) && $cms[$cmid]->uservisible;
                    foreach ($scope['attemptidsbycm'][$cmid] ?? [] as $attemptid) {
                        if (isset($attempts[$attemptid])) {
                            $scopeattempts[] = $attempts[$attemptid];
                        }
                    }
                } else {
                    $assessmentcmids = $scope['cmids'];
                    foreach ($scope['attemptidsbycm'] as $attemptids) {
                        foreach ($attemptids as $attemptid) {
                            if (isset($attempts[$attemptid])) {
                                $scopeattempts[] = $attempts[$attemptid];
                            }
                        }
                    }
                }
                $decision = release_service::evaluate($policy, [
                    'accessible' => $accessible,
                    'lineagecomplete' => $scope['lineagecomplete'],
                    'assessmentcmids' => $assessmentcmids,
                    'attempts' => $scopeattempts,
                    'gradevisible' => $gradevisible,
                    'quizclosetimes' => $quizclosetimes,
                    'manualreleaseat' => $policy === null
                        ? null : ($manualreleases[(int) $policy->id] ?? null),
                ], $at);
                if (!$decision->released) {
                    $combined->released = false;
                    $combined->releasedat = null;
                    break;
                }
                if ($decision->releasedat !== null) {
                    $combined->releasedat = $combined->releasedat === null
                        ? (int) $decision->releasedat
                        : max((int) $combined->releasedat, (int) $decision->releasedat);
                }
            }
            $row = self::safe_row($outcome, $result, $combined, $cms);
            $rows[$key] = $row;
            if ($row['state'] === calculation_service::STATE_CALCULATED && $row['percentage'] !== null) {
                $remediationrequests[$key] = [
                    'cinstid' => $outcome->cinstid,
                    'itemverid' => $outcome->itemverid,
                    'resultid' => (int) $result->id,
                    'bandid' => $row['bandid'],
                    'percentage' => $row['percentage'],
                ];
            }
        }
        if ($remediationrequests && feature::remediation_enabled()) {
            $recommendations = self::select_accessible_remediation(
                $courseid,
                $remediationrequests,
                $at,
                $modinfo,
                $cms
            );
            foreach ($recommendations as $key => $items) {
                $rows[$key]['remediation'] = $items;
            }
        }

        // Curriculum context: which higher-level outcomes each row feeds, and
        // the band thresholds it was judged against. Both are definitional
        // metadata rather than evidence, so they are safe at learner level and
        // let a report group unit outcomes under the course outcome they build.
        $parents = self::parent_map(array_map(
            static fn(array $row): int => (int) $row['itemid'],
            $rows
        ), $at);
        $policyids = [];
        foreach (array_keys($rows) as $key) {
            if ($selected[$key] !== null) {
                $policyids[(int) $selected[$key]->policyid] = (int) $selected[$key]->policyid;
            }
        }
        $ladders = self::band_ladders(array_values($policyids));
        $fallback = self::fallback_ladder($ladders, $instances, $at);
        $distinct = [];
        foreach ($rows as $key => $row) {
            $result = $selected[$key];
            $ladder = $result === null
                ? $fallback
                : ($ladders[(int) $result->policyid] ?? $fallback);
            $rows[$key]['parentitemids'] = $parents[(int) $row['itemid']] ?? [];
            $rows[$key]['expectedpercent'] = $ladder['expected'];
            $rows[$key]['strongpercent'] = $ladder['strong'];
            $distinct[$ladder['expected'] . '|' . $ladder['strong']] = $ladder;
        }

        return [
            'courseid' => $courseid,
            'generatedat' => $at,
            // A single ladder across every row lets a report speak of "the 70%
            // mark" in one voice. Mixed calculation policies make that claim
            // untrue, so the report withholds it and rows keep their own.
            'expectedpercent' => count($distinct) === 1 ? reset($distinct)['expected'] : null,
            'strongpercent' => count($distinct) === 1 ? reset($distinct)['strong'] : null,
            'rows' => array_values($rows),
        ];
    }

    /**
     * Classify one outcome into the display hierarchy.
     *
     * This deliberately repeats the rule used by the staff hierarchy view in
     * \local_outcomemap\output\outcomes_hierarchy, so a learner and their
     * teacher never disagree about whether an outcome is a course skill or a
     * unit step: programme frameworks own programme outcomes, a course
     * framework whose code ends in ULO owns unit outcomes, and anything else a
     * course owns is a course outcome.
     *
     * @param string $ownertype Framework owner type.
     * @param string $frameworkcode Framework code.
     * @return string One of the TIER_* constants.
     */
    private static function tier(string $ownertype, string $frameworkcode): string {
        if ($ownertype === framework_service::OWNER_PROGRAM) {
            return self::TIER_PROGRAM;
        }
        return preg_match('/ULO$/i', $frameworkcode) === 1 ? self::TIER_UNIT : self::TIER_COURSE;
    }

    /**
     * Map each reported outcome to the reported outcomes directly above it.
     *
     * Only single edges between rows of this report are followed, because the
     * report groups adjacent tiers rather than tracing full paths. Both
     * relation types count: an aligns_to edge is curriculum structure even
     * though it never propagates evidence, and the grouping makes no
     * attainment claim about the parent.
     *
     * @param int[] $itemids Stable outcome item IDs present in the report.
     * @param int $at Effective timestamp.
     * @return array<int, int[]> Parent item IDs keyed by source item ID.
     */
    private static function parent_map(array $itemids, int $at): array {
        global $DB;
        $itemids = array_values(array_unique(array_map('intval', $itemids)));
        if (count($itemids) < 2) {
            return [];
        }
        [$sourcesql, $sourceparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'relsource');
        [$targetsql, $targetparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'reltarget');
        [$typesql, $typeparams] = $DB->get_in_or_equal([
            relation_service::ALIGNS_TO,
            relation_service::CONTRIBUTES_TO,
        ], SQL_PARAMS_NAMED, 'reltype');
        $relations = $DB->get_records_select(
            'local_outcomemap_rel',
            "sourceitemid $sourcesql AND targetitemid $targetsql AND type $typesql
                AND status = :relstatus AND effectivefrom <= :relat1
                AND (effectiveto IS NULL OR effectiveto > :relat2)",
            $sourceparams + $targetparams + $typeparams + [
                'relstatus' => workflow::APPROVED,
                'relat1' => $at,
                'relat2' => $at,
            ],
            'sourceitemid, id',
            'id, sourceitemid, targetitemid'
        );
        $map = [];
        foreach ($relations as $relation) {
            $sourceid = (int) $relation->sourceitemid;
            $targetid = (int) $relation->targetitemid;
            if ($sourceid === $targetid) {
                continue;
            }
            // aligns_to and contributes_to commonly mirror each other, so the
            // parent list is a set rather than one entry per edge.
            $map[$sourceid][$targetid] = $targetid;
        }
        return array_map('array_values', $map);
    }

    /**
     * Read the achievement thresholds out of each policy's band ladder.
     *
     * The thresholds are the ladder's interior boundaries: the floor of the
     * second band is the point at which the policy stops calling an outcome
     * unmet, and the floor of the top band is the point at which it calls it
     * exceeded. The bottom band's own floor is deliberately excluded — it is
     * the start of the scale, not an achievement threshold, and a policy that
     * writes it as 0 rather than leaving it open must not be read as passing
     * everyone. A ladder of one band therefore yields no thresholds at all,
     * which is correct: it draws no distinction to report.
     *
     * @param int[] $policyids Calculation policy IDs.
     * @return array<int, array{expected: ?string, strong: ?string}> Keyed by policy ID.
     */
    private static function band_ladders(array $policyids): array {
        global $DB;
        $policyids = array_values(array_unique(array_map('intval', $policyids)));
        if (!$policyids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($policyids, SQL_PARAMS_NAMED, 'bandpolicy');
        $bands = $DB->get_records_select(
            'local_outcomemap_band',
            "policyid $insql",
            $params,
            'policyid, sortorder',
            'id, policyid, minpercent, sortorder'
        );
        $ladders = array_fill_keys($policyids, ['expected' => null, 'strong' => null]);
        $bottom = [];
        foreach ($bands as $band) {
            $policyid = (int) $band->policyid;
            // Rows arrive in sortorder, which policy_service validates as an
            // ascending non-overlapping ladder, so the first row of a policy is
            // its bottom band.
            if (!array_key_exists($policyid, $bottom)) {
                $bottom[$policyid] = true;
                continue;
            }
            if ($band->minpercent === null) {
                continue;
            }
            $boundary = decimal::canonical($band->minpercent, 'minpercent');
            $ladder = $ladders[$policyid];
            if ($ladder['expected'] === null || decimal::cmp($boundary, $ladder['expected']) < 0) {
                $ladders[$policyid]['expected'] = $boundary;
            }
            if ($ladder['strong'] === null || decimal::cmp($boundary, $ladder['strong']) > 0) {
                $ladders[$policyid]['strong'] = $boundary;
            }
        }
        return $ladders;
    }

    /**
     * Resolve the ladder to use for rows with no stored result of their own.
     *
     * An unassessed row has no policy reference, so the report falls back to
     * the calculation policy that governs the course instance now. When the
     * learner already has results, their ladder is preferred over a resolved
     * one so the page cannot describe a threshold their figures were not
     * judged against.
     *
     * @param array $ladders Ladders keyed by policy ID.
     * @param \stdClass[] $instances Approved confirmed course instances.
     * @param int $at Effective timestamp.
     * @return array{expected: ?string, strong: ?string} Ladder for unassessed rows.
     */
    private static function fallback_ladder(array $ladders, array $instances, int $at): array {
        $empty = ['expected' => null, 'strong' => null];
        if (count($ladders) === 1) {
            return reset($ladders);
        }
        if ($ladders) {
            // Several ladders already in play: no single one can be assumed.
            return $empty;
        }
        $requests = [];
        foreach ($instances as $instance) {
            $requests[(int) $instance->id] = ['cinstid' => (int) $instance->id, 'cmid' => null];
        }
        if (!$requests) {
            return $empty;
        }
        $policies = policy_service::resolve_many(policy_service::TYPE_CALCULATION, $requests, $at);
        $policyids = [];
        foreach ($policies as $policy) {
            if ($policy !== null) {
                $policyids[(int) $policy->id] = (int) $policy->id;
            }
        }
        $resolved = self::band_ladders(array_values($policyids));
        return count($resolved) === 1 ? reset($resolved) : $empty;
    }

    /**
     * Select remediation only for rows released by this report invocation.
     *
     * Keeping this selector private prevents learner-context callers from
     * supplying arbitrary outcome, band, or percentage values to probe
     * governed recommendations. The requests are built above exclusively
     * from authoritative, non-stale results after all release checks pass.
     *
     * @param int $courseid Moodle course ID.
     * @param array $requests Released result descriptors keyed like report rows.
     * @param int $at Effective timestamp.
     * @param \course_modinfo $modinfo Learner-scoped course information.
     * @param array $cms Learner-scoped course modules keyed by ID.
     * @return array Display-safe recommendations keyed like $requests.
     */
    private static function select_accessible_remediation(
        int $courseid,
        array $requests,
        int $at,
        \course_modinfo $modinfo,
        array $cms
    ): array {
        global $DB;

        $output = [];
        $normalized = [];
        $cinstids = [];
        $itemverids = [];
        foreach ($requests as $key => $request) {
            $output[$key] = [];
            $request = (array) $request;
            $cinstid = (int) ($request['cinstid'] ?? 0);
            $itemverid = (int) ($request['itemverid'] ?? 0);
            $resultid = (int) ($request['resultid'] ?? 0);
            if ($cinstid < 1 || $itemverid < 1 || !array_key_exists('percentage', $request)
                    || $request['percentage'] === null) {
                continue;
            }
            $normalized[$key] = [
                'cinstid' => $cinstid,
                'itemverid' => $itemverid,
                'resultid' => $resultid > 0 ? $resultid : null,
                'bandid' => empty($request['bandid']) ? null : (int) $request['bandid'],
                'percentage' => decimal::canonical($request['percentage'], 'percentage'),
            ];
            $cinstids[$cinstid] = $cinstid;
            $itemverids[$itemverid] = $itemverid;
        }
        if (!$normalized) {
            return $output;
        }

        [$cinstsql, $cinstparams] = $DB->get_in_or_equal(
            array_values($cinstids),
            SQL_PARAMS_NAMED,
            'rcinst'
        );
        [$itemsql, $itemparams] = $DB->get_in_or_equal(
            array_values($itemverids),
            SQL_PARAMS_NAMED,
            'ritem'
        );
        $params = $cinstparams + $itemparams + [
            'courseid' => $courseid,
            'status' => workflow::APPROVED,
            'at1' => $at,
            'at2' => $at,
        ];
        $sql = "SELECT r.*
                  FROM {local_outcomemap_remed} r
                  JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
                 WHERE ci.moodlecourseid = :courseid
                   AND r.cinstid $cinstsql AND r.itemverid $itemsql
                   AND r.status = :status
                   AND r.effectivefrom <= :at1
                   AND (r.effectiveto IS NULL OR r.effectiveto > :at2)
              ORDER BY r.priority DESC, r.sortorder ASC, r.id ASC";
        $records = $DB->get_records_sql($sql, $params);
        $byoutcome = [];
        foreach ($records as $record) {
            $byoutcome[(int) $record->cinstid . ':' . (int) $record->itemverid][] = $record;
        }

        $courseformat = course_get_format($courseid);
        foreach ($normalized as $key => $request) {
            $outcomekey = $request['cinstid'] . ':' . $request['itemverid'];
            foreach ($byoutcome[$outcomekey] ?? [] as $record) {
                if ($record->bandid !== null && (int) $record->bandid !== $request['bandid']) {
                    continue;
                }
                if ($record->minpercent !== null
                        && decimal::cmp($request['percentage'], (string) $record->minpercent) < 0) {
                    continue;
                }
                if ($record->maxpercent !== null
                        && decimal::cmp($request['percentage'], (string) $record->maxpercent) > 0) {
                    continue;
                }

                $url = null;
                if ($record->targettype === remediation_service::TARGET_MODULE) {
                    $cmid = (int) $record->targetid;
                    if (isset($cms[$cmid]) && $cms[$cmid]->uservisible && $cms[$cmid]->url !== null) {
                        $url = $cms[$cmid]->url->out(false);
                    }
                } else if ($record->targettype === remediation_service::TARGET_SECTION) {
                    $section = $modinfo->get_section_info_by_id((int) $record->targetid);
                    if ($section !== null && $section->uservisible) {
                        $sectionurl = $courseformat->get_view_url($section, ['navigation' => true]);
                        $url = $sectionurl === null ? null : $sectionurl->out(false);
                    }
                } else if ($record->targettype === remediation_service::TARGET_EXTERNAL) {
                    $externalurl = clean_param(trim((string) $record->externalurl), PARAM_URL);
                    $scheme = strtolower((string) parse_url($externalurl, PHP_URL_SCHEME));
                    $host = (string) parse_url($externalurl, PHP_URL_HOST);
                    if ($externalurl !== '' && $host !== '' && in_array($scheme, ['http', 'https'], true)) {
                        $url = $externalurl;
                    }
                }
                if ($url === null) {
                    continue;
                }
                $item = [
                    'title' => (string) $record->title,
                    'explanation' => $record->explanation === null ? null : (string) $record->explanation,
                    'url' => $url,
                    'required' => (bool) $record->required,
                    'purpose' => (string) $record->purpose,
                    'priority' => (int) $record->priority,
                    'sortorder' => (int) $record->sortorder,
                ];
                if ($request['resultid'] !== null) {
                    $item = [
                        'recommendationid' => (int) $record->id,
                        'resultid' => $request['resultid'],
                        'title' => $item['title'],
                        'explanation' => $item['explanation'],
                        'targeturl' => $url,
                        'url' => (new \moodle_url('/local/outcomemap/remediationopen.php', [
                            'id' => (int) $record->id,
                            'resultid' => $request['resultid'],
                            'sesskey' => sesskey(),
                        ]))->out(false),
                        'required' => $item['required'],
                        'purpose' => $item['purpose'],
                        'priority' => $item['priority'],
                        'sortorder' => $item['sortorder'],
                    ];
                }
                $output[$key][] = $item;
            }
        }
        return $output;
    }

    /**
     * Select the preferred current result for one stable CLO.
     *
     * @param \stdClass[] $results Candidate current result versions.
     * @return \stdClass|null Preferred result.
     */
    private static function preferred_result(array $results): ?\stdClass {
        usort($results, static function(\stdClass $left, \stdClass $right): int {
            $leftcourse = $left->scopetype === calculation_service::SCOPE_COURSE ? 1 : 0;
            $rightcourse = $right->scopetype === calculation_service::SCOPE_COURSE ? 1 : 0;
            return [$rightcourse, (int) $right->timecalculated, (int) $right->id]
                <=> [$leftcourse, (int) $left->timecalculated, (int) $left->id];
        });
        return $results ? reset($results) : null;
    }

    /**
     * Build the display-safe result row.
     *
     * @param \stdClass $outcome Relevant CLO descriptor.
     * @param \stdClass|null $result Stored result, or null when not assessed.
     * @param \stdClass $decision Combined release decision.
     * @param array $cms Course-module info keyed by ID.
     * @return array Safe row with no question or evidence fields.
     */
    private static function safe_row(
        \stdClass $outcome,
        ?\stdClass $result,
        \stdClass $decision,
        array $cms
    ): array {
        $scopetype = $result->scopetype ?? calculation_service::SCOPE_COURSE;
        $scopeid = $result === null ? $outcome->cinstid : (int) $result->scopeid;
        $scopename = null;
        if ($scopetype === calculation_service::SCOPE_ASSESSMENT && isset($cms[$scopeid])) {
            $scopename = $cms[$scopeid]->get_formatted_name();
        }
        $base = [
            'code' => $outcome->code,
            'shortstatement' => $outcome->shortstatement,
            'statement' => $outcome->statement,
            'itemid' => (int) $outcome->itemid,
            'tier' => $outcome->tier,
            'frameworkcode' => $outcome->frameworkcode,
            'parentitemids' => [],
            'expectedpercent' => null,
            'strongpercent' => null,
            'periodcode' => $outcome->periodcode,
            // The course instance this row was judged in. scopeid cannot carry
            // it alone — an assessment-scope row's scopeid is the course-module
            // ID — and cross-course pooling needs the instance to attribute
            // each contribution to one catalog course.
            'cinstid' => (int) $outcome->cinstid,
            'scopetype' => $scopetype,
            'scopeid' => $scopeid,
            'scopename' => $scopename,
            'state' => self::STATE_NOT_RELEASED,
            'percentage' => null,
            'displayscale' => 1,
            'bandname' => null,
            'bandfeedback' => null,
            'bandid' => null,
            'distinctitems' => null,
            'weightedearned' => null,
            'weightedpossible' => null,
            'timecalculated' => null,
            'releasedat' => $decision->releasedat,
            'remediation' => [],
        ];
        if (!$decision->released) {
            return $base;
        }
        if ($result === null) {
            $base['state'] = calculation_service::STATE_NOT_ASSESSED;
            return $base;
        }
        if ((int) $result->stale === 1) {
            $base['state'] = self::STATE_STALE;
            return $base;
        }
        $config = json_decode($result->configjson, true) ?? [];
        $base['state'] = (string) $result->state;
        $base['displayscale'] = (int) ($config['displayscale'] ?? 1);
        $base['distinctitems'] = (int) $result->distinctitems;
        // Both sides of the stored fraction, so a pooled figure can sum the
        // canonical values instead of un-rounding a per-row percentage — ADR
        // 0003 forbids rounding per row and re-summing. The same sensitivity
        // class as the percentage: it is this learner's own weighted score.
        $base['weightedearned'] = decimal::canonical($result->numerator, 'numerator');
        $base['weightedpossible'] = decimal::canonical($result->denominator, 'denominator');
        $base['timecalculated'] = (int) $result->timecalculated;
        if ($result->state === calculation_service::STATE_CALCULATED && $result->percentage !== null) {
            $base['percentage'] = decimal::canonical($result->percentage, 'percentage');
            $base['bandid'] = $result->bandid === null ? null : (int) $result->bandid;
            $base['bandname'] = $result->bandname === null ? null : (string) $result->bandname;
            $base['bandfeedback'] = $result->banddescription === null
                ? null : (string) $result->banddescription;
        }
        return $base;
    }

    /**
     * Bulk-load whether each quiz grade is currently visible to the learner.
     *
     * @param int $courseid Moodle course ID.
     * @param int $userid Learner ID.
     * @param int[] $cmids Course-module IDs.
     * @param int $at Evaluation timestamp.
     * @return array Visibility booleans keyed by course-module ID.
     */
    private static function grade_visibility(int $courseid, int $userid, array $cmids, int $at): array {
        global $DB;
        if (!$cmids) {
            return [];
        }
        [$cmsql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'gradecm');
        $params += ['courseid' => $courseid, 'userid' => $userid, 'modname' => 'quiz'];
        $sql = "SELECT cm.id AS cmid, gi.hidden AS itemhidden,
                       gg.hidden AS gradehidden, gg.finalgrade
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {grade_items} gi ON gi.courseid = cm.course
                       AND gi.itemtype = 'mod' AND gi.itemmodule = 'quiz'
                       AND gi.iteminstance = cm.instance AND gi.itemnumber = 0
             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                 WHERE cm.course = :courseid AND cm.id $cmsql";
        $records = $DB->get_records_sql($sql, $params);
        $visibility = array_fill_keys($cmids, false);
        foreach ($records as $record) {
            $itemhidden = (int) $record->itemhidden;
            $gradehidden = (int) ($record->gradehidden ?? 0);
            $visibility[(int) $record->cmid] = $record->finalgrade !== null
                && !self::hidden_at($itemhidden, $at)
                && !self::hidden_at($gradehidden, $at);
        }
        return $visibility;
    }

    /**
     * Apply Moodle's grade hidden/hidden-until semantics at a timestamp.
     *
     * @param int $hidden Hidden value (0, 1, or timestamp).
     * @param int $at Evaluation timestamp.
     * @return bool Whether hidden.
     */
    private static function hidden_at(int $hidden, int $at): bool {
        return $hidden === 1 || ($hidden > 1 && $hidden > $at);
    }
}
