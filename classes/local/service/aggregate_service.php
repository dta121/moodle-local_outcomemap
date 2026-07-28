<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\decimal;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Deterministic course and program accreditation aggregation.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class aggregate_service {
    /**
     * Return approved course instances in a program and reporting period.
     *
     * @param int $programid Program ID.
     * @param string $periodcode Reporting-period code.
     * @param int|null $at Effective membership timestamp.
     * @return \stdClass[] Course instances keyed by ID.
     */
    public static function course_instances(int $programid, string $periodcode, ?int $at = null): array {
        global $DB;
        $at = $at ?? time();
        $periodcode = trim($periodcode);
        if ($periodcode === '') {
            throw new validation_exception('requiredfield', 'periodcode');
        }
        $sql = "SELECT ci.*, cc.uuid AS courseuuid, cc.code AS coursecode,
                       cc.name AS coursename, mc.fullname AS moodlecoursename,
                       pc.uuid AS membershipuuid
                  FROM {local_outcomemap_progcourse} pc
                  JOIN {local_outcomemap_course} cc ON cc.id = pc.courseid
                  JOIN {local_outcomemap_cinst} ci ON ci.courseid = cc.id
                  JOIN {course} mc ON mc.id = ci.moodlecourseid
                 WHERE pc.programid = :programid AND pc.status = :pcstatus
                   AND pc.effectivefrom <= :at1
                   AND (pc.effectiveto IS NULL OR pc.effectiveto > :at2)
                   AND ci.periodcode = :periodcode AND ci.status = :cistatus
                   AND ci.confirmed = 1
              ORDER BY cc.code, ci.id";
        return $DB->get_records_sql($sql, [
            'programid' => $programid,
            'pcstatus' => workflow::APPROVED,
            'cistatus' => workflow::APPROVED,
            'at1' => $at,
            'at2' => $at,
            'periodcode' => $periodcode,
        ]);
    }

    /**
     * Load authoritative current course-scope learner results.
     *
     * @param int[] $cinstids Included course-instance IDs.
     * @param int[] $userids Included population user IDs.
     * @return \stdClass[] Current result rows with stable version metadata.
     */
    public static function load_results(array $cinstids, array $userids): array {
        global $DB;
        $cinstids = array_values(array_unique(array_filter(array_map('intval', $cinstids))));
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        if (!$cinstids || !$userids) {
            return [];
        }
        $records = [];
        foreach (array_chunk($cinstids, 200) as $cinstchunk) {
            [$cinstsql, $cinstparams] = $DB->get_in_or_equal($cinstchunk, SQL_PARAMS_NAMED, 'agc');
            foreach (array_chunk($userids, 400) as $userchunk) {
                [$usersql, $userparams] = $DB->get_in_or_equal($userchunk, SQL_PARAMS_NAMED, 'agu');
                $params = $cinstparams + $userparams + [
                    'scope' => calculation_service::SCOPE_COURSE,
                ];
                $sql = "SELECT r.*, ci.uuid AS cinstuuid, ci.periodcode AS cinstperiod,
                               cc.uuid AS courseuuid, cc.code AS coursecode,
                               i.uuid AS outcomeuuid, i.code AS outcomecode,
                               v.uuid AS outcomeversionuuid, v.version AS outcomeversion,
                               v.statement AS outcomestatement, f.uuid AS frameworkuuid,
                               f.code AS frameworkcode, p.policyuuid, p.version AS policyversion,
                               p.confighash AS policyconfighash, b.code AS bandcode
                          FROM {local_outcomemap_result} r
                          JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
                          JOIN {local_outcomemap_course} cc ON cc.id = ci.courseid
                          JOIN {local_outcomemap_itemver} v ON v.id = r.itemverid
                          JOIN {local_outcomemap_item} i ON i.id = v.itemid
                          JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
                          JOIN {local_outcomemap_policy} p ON p.id = r.policyid
                     LEFT JOIN {local_outcomemap_band} b ON b.id = r.bandid
                         WHERE r.cinstid $cinstsql AND r.userid $usersql
                           AND r.scopetype = :scope AND r.supersededby IS NULL
                           AND r.stale = 0";
                foreach ($DB->get_records_sql($sql, $params) as $record) {
                    $records[(int) $record->id] = $record;
                }
            }
        }
        $records = array_values($records);
        usort($records, static fn(\stdClass $a, \stdClass $b): int =>
            [(int) $a->cinstid, (int) $a->itemverid, (int) $a->userid, (string) $a->uuid]
            <=> [(int) $b->cinstid, (int) $b->itemverid, (int) $b->userid, (string) $b->uuid]
        );
        return $records;
    }

    /**
     * Build deterministic course and program aggregate rows.
     *
     * Percentages are never averaged. Canonical numerators and denominators
     * are summed and divided once per aggregate row, yielding the pooled score
     * of the row. The attainment rate is a separate statistic: each learner's
     * own numerators and denominators are pooled first, that learner is judged
     * once against the policy's achievement criterion, and the rate is the
     * share of assessed learners who met it. A learner contributing results in
     * several course instances therefore counts once in a program row.
     *
     * @param \stdClass[] $results Current result records.
     * @param \stdClass $policy Approved accreditation policy.
     * @return array{course:array,program:array}
     */
    public static function aggregate(array $results, \stdClass $policy): array {
        $config = suppression_service::config_of($policy);
        $course = [];
        $program = [];
        foreach ($results as $result) {
            $coursekey = (int) $result->cinstid . ':' . (int) $result->itemverid;
            $programkey = (string) $result->outcomeversionuuid;
            if (!isset($course[$coursekey])) {
                $course[$coursekey] = self::empty_bucket($result, 'course');
            }
            if (!isset($program[$programkey])) {
                $program[$programkey] = self::empty_bucket($result, 'program');
            }
            self::add_result($course[$coursekey], $result);
            self::add_result($program[$programkey], $result);
        }
        foreach ($course as &$bucket) {
            self::finish_bucket($bucket, $config);
        }
        unset($bucket);
        foreach ($program as &$bucket) {
            self::finish_bucket($bucket, $config);
        }
        unset($bucket);
        ksort($course, SORT_STRING);
        ksort($program, SORT_STRING);
        return ['course' => array_values($course), 'program' => array_values($program)];
    }

    /**
     * Create an empty aggregate accumulator.
     *
     * @param \stdClass $result Source result.
     * @param string $scope Aggregate scope.
     * @return array
     */
    private static function empty_bucket(\stdClass $result, string $scope): array {
        return [
            'scope' => $scope,
            'cinstid' => $scope === 'course' ? (int) $result->cinstid : null,
            'cinstuuid' => $scope === 'course' ? (string) $result->cinstuuid : null,
            'courseuuid' => $scope === 'course' ? (string) $result->courseuuid : null,
            'coursecode' => $scope === 'course' ? (string) $result->coursecode : null,
            'periodcode' => (string) $result->cinstperiod,
            'itemverid' => (int) $result->itemverid,
            'outcomeuuid' => (string) $result->outcomeuuid,
            'outcomeversionuuid' => (string) $result->outcomeversionuuid,
            'outcomeversion' => (int) $result->outcomeversion,
            'outcomecode' => (string) $result->outcomecode,
            'frameworkuuid' => (string) $result->frameworkuuid,
            'frameworkcode' => (string) $result->frameworkcode,
            'numerator' => decimal::ZERO,
            'denominator' => decimal::ZERO,
            'percentage' => null,
            'subjectcount' => 0,
            'calculatedcount' => 0,
            'assessedcount' => 0,
            'metcount' => 0,
            'notmetcount' => 0,
            'attainmentpercent' => null,
            'achievementminpercent' => null,
            'benchmarkpercent' => null,
            'benchmarkmet' => null,
            'statecounts' => [],
            'subjects' => [],
            'resultuuids' => [],
            'suppressed' => false,
        ];
    }

    /**
     * Add one learner result to an aggregate accumulator.
     *
     * @param array $bucket Aggregate accumulator.
     * @param \stdClass $result Result row.
     */
    private static function add_result(array &$bucket, \stdClass $result): void {
        $userid = (int) $result->userid;
        if (!isset($bucket['subjects'][$userid])) {
            $bucket['subjects'][$userid] = [
                'numerator' => decimal::ZERO,
                'denominator' => decimal::ZERO,
                'calculatedcount' => 0,
            ];
        }
        $bucket['resultuuids'][] = (string) $result->uuid;
        $state = (string) $result->state;
        $bucket['statecounts'][$state] = ($bucket['statecounts'][$state] ?? 0) + 1;
        if ($state !== calculation_service::STATE_CALCULATED || $result->percentage === null) {
            return;
        }
        $numerator = decimal::canonical($result->numerator, 'numerator');
        $denominator = decimal::canonical($result->denominator, 'denominator');
        $bucket['numerator'] = decimal::add($bucket['numerator'], $numerator);
        $bucket['denominator'] = decimal::add($bucket['denominator'], $denominator);
        $bucket['calculatedcount']++;
        $subject = &$bucket['subjects'][$userid];
        $subject['numerator'] = decimal::add($subject['numerator'], $numerator);
        $subject['denominator'] = decimal::add($subject['denominator'], $denominator);
        $subject['calculatedcount']++;
        unset($subject);
    }

    /**
     * Finalize one aggregate row, judge each learner, and apply suppression.
     *
     * @param array $bucket Aggregate accumulator.
     * @param array $config Canonical accreditation configuration.
     */
    private static function finish_bucket(array &$bucket, array $config): void {
        $bucket['subjectcount'] = count($bucket['subjects']);
        // Learners are judged in ascending ID order so the met counts, and the
        // payload hash derived from them, do not depend on result ordering.
        ksort($bucket['subjects'], SORT_NUMERIC);
        $assessed = 0;
        $met = 0;
        foreach ($bucket['subjects'] as $subject) {
            if ($subject['calculatedcount'] === 0 || decimal::is_zero($subject['denominator'])) {
                continue;
            }
            $assessed++;
            $subjectpercentage = decimal::div(
                decimal::mul($subject['numerator'], '100'),
                $subject['denominator']
            );
            if (suppression_service::meets_criterion($subjectpercentage, $config)) {
                $met++;
            }
        }
        unset($bucket['subjects']);
        sort($bucket['resultuuids'], SORT_STRING);
        ksort($bucket['statecounts'], SORT_STRING);
        if ($bucket['calculatedcount'] > 0 && !decimal::is_zero($bucket['denominator'])) {
            $bucket['percentage'] = decimal::div(
                decimal::mul($bucket['numerator'], '100'),
                $bucket['denominator']
            );
        }
        $bucket['assessedcount'] = $assessed;
        $bucket['metcount'] = $met;
        $bucket['notmetcount'] = $assessed - $met;
        $bucket['attainmentpercent'] = $assessed === 0 ? null : decimal::div(
            decimal::mul(decimal::canonical((string) $met, 'metcount'), '100'),
            decimal::canonical((string) $assessed, 'assessedcount')
        );
        // The criterion and benchmark travel with the row so a frozen snapshot
        // records the standard it was judged against, not just the outcome.
        $bucket['achievementminpercent'] = $config['achievementminpercent'];
        $bucket['benchmarkpercent'] = $config['benchmarkpercent'];
        $bucket['benchmarkmet'] = suppression_service::meets_benchmark($bucket['attainmentpercent'], $config);
        $bucket['suppressed'] = $bucket['subjectcount'] < $config['mincohortsize'];
    }
}
