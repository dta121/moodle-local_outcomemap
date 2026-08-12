<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Program-level attainment export for the student information system.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\decimal;
use local_outcomemap\local\workflow;

/**
 * Builds one learner's released program-outcome attainment across courses.
 *
 * The SIS shows a learner what their DEGREE certifies, so the unit here is the
 * program outcome pooled across every course instance feeding it — not the
 * per-course rows the in-Moodle report renders. Each contribution is taken
 * from {@see student_result_service::report_for()}, which runs the full
 * release evaluation for the target learner, so nothing Moodle currently
 * withholds from that learner (unreleased, stale, hidden grades) can reach
 * the export. Pooling sums the canonical stored numerators and denominators
 * and divides once per outcome; per-row percentages are never re-averaged
 * (ADR 0003).
 *
 * Thresholds are the calculation policies' own band boundaries. When the
 * contributions to an outcome were judged against different ladders, the
 * outcome reports no threshold rather than an invented one, mirroring the
 * single-voice rule in the learner report.
 */
final class attainment_export_service {
    /**
     * Fallback-state precedence when an outcome has rows but none calculated.
     *
     * Strongest claim first: evidence that exists but does not suffice beats a
     * pending calculation, which beats a stale figure, which beats a figure
     * Moodle is withholding, which beats nothing at all. "Missing evidence is
     * not assessed, not zero" (spec §4) — none of these carries a percentage.
     */
    private const FALLBACK_STATES = [
        calculation_service::STATE_INSUFFICIENT,
        calculation_service::STATE_PENDING,
        student_result_service::STATE_STALE,
        student_result_service::STATE_NOT_RELEASED,
    ];

    /**
     * Return the learner's pooled program-outcome attainment.
     *
     * Carries NO capability check of its own: the external function checks
     * local/outcomemap:exportattainment at system context before calling.
     *
     * @param int $userid Learner's Moodle user ID.
     * @param string|null $programcode Restrict to one program code, or null for all.
     * @param int|null $at Evaluation timestamp; defaults to now.
     * @return array{generatedat:int,algoversion:string,programs:array}
     */
    public static function get_user_program_attainment(
        int $userid,
        ?string $programcode = null,
        ?int $at = null
    ): array {
        global $DB;
        $at = $at ?? time();
        $programcode = $programcode === null ? null : trim($programcode);
        if ($programcode === '') {
            $programcode = null;
        }
        $empty = [
            'generatedat' => $at,
            'algoversion' => calculation_service::ALGO_VERSION,
            'programs' => [],
        ];

        // Every Moodle course where this learner holds a current result in an
        // approved, confirmed instance. Result-driven on purpose: a learner
        // with no results has no report to release, and the per-course pass
        // below re-derives everything else (including not-assessed rows for
        // outcomes the program promises but no course has fed yet).
        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT ci.moodlecourseid
               FROM {local_outcomemap_result} r
               JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
              WHERE r.userid = :userid AND r.supersededby IS NULL
                AND ci.status = :cistatus AND ci.confirmed = 1",
            ['userid' => $userid, 'cistatus' => workflow::APPROVED]
        );
        if (!$courseids) {
            return $empty;
        }

        // Learner-safe per-course reports; only program-tier rows pool.
        $rows = [];
        $cinstids = [];
        foreach ($courseids as $moodlecourseid) {
            $report = student_result_service::report_for($userid, (int) $moodlecourseid, $at);
            foreach ($report['rows'] as $row) {
                if (($row['tier'] ?? '') !== student_result_service::TIER_PROGRAM) {
                    continue;
                }
                $rows[] = $row;
                $cinstids[(int) $row['cinstid']] = (int) $row['cinstid'];
            }
        }

        // Attribute each contribution to its catalog course, so two instances
        // of one course (a retake in a later period) count as one course and
        // only the preferred contribution pools.
        $catalogbycinst = [];
        if ($cinstids) {
            $instances = $DB->get_records_list(
                'local_outcomemap_cinst',
                'id',
                array_values($cinstids),
                '',
                'id, courseid'
            );
            foreach ($instances as $instance) {
                $catalogbycinst[(int) $instance->id] = (int) $instance->courseid;
            }
        }
        $catalogids = array_values(array_unique($catalogbycinst));
        if (!$catalogids) {
            return $empty;
        }

        // The learner's programs: approved programs holding an approved,
        // currently effective membership for any catalog course the learner
        // has results in — the same membership rule the per-course report
        // used to admit program-tier rows.
        [$catalogsql, $catalogparams] = $DB->get_in_or_equal($catalogids, SQL_PARAMS_NAMED, 'expcourse');
        $programparams = $catalogparams + [
            'pstatus' => workflow::APPROVED,
            'pcstatus' => workflow::APPROVED,
            'at1' => $at,
            'at2' => $at,
        ];
        $programfilter = '';
        if ($programcode !== null) {
            $programfilter = ' AND p.code = :programcode';
            $programparams['programcode'] = $programcode;
        }
        $programs = $DB->get_records_sql(
            "SELECT p.id, p.code, p.name
               FROM {local_outcomemap_program} p
              WHERE p.status = :pstatus{$programfilter}
                AND EXISTS (
                    SELECT 1
                      FROM {local_outcomemap_progcourse} pc
                     WHERE pc.programid = p.id AND pc.courseid $catalogsql
                       AND pc.status = :pcstatus
                       AND pc.effectivefrom <= :at1
                       AND (pc.effectiveto IS NULL OR pc.effectiveto > :at2)
                )
           ORDER BY p.code",
            $programparams
        );
        if (!$programs) {
            return $empty;
        }
        $programids = array_map('intval', array_keys($programs));

        // How many catalog courses each program currently promises — the "of
        // M courses" denominator in the evidence line.
        [$progsql, $progparams] = $DB->get_in_or_equal($programids, SQL_PARAMS_NAMED, 'expprog');
        $totals = $DB->get_records_sql(
            "SELECT pc.programid, COUNT(DISTINCT pc.courseid) AS coursestotal
               FROM {local_outcomemap_progcourse} pc
              WHERE pc.programid $progsql AND pc.status = :pcstatus
                AND pc.effectivefrom <= :at1
                AND (pc.effectiveto IS NULL OR pc.effectiveto > :at2)
           GROUP BY pc.programid",
            $progparams + ['pcstatus' => workflow::APPROVED, 'at1' => $at, 'at2' => $at]
        );

        // The programs' promised outcomes: current effective approved wording
        // of every approved item in a program-owned approved framework. Keyed
        // by stable item so a historical result version still lands on the
        // outcome it belongs to.
        $outcomerecords = $DB->get_records_sql(
            "SELECT v.id AS itemverid, v.itemid, v.version, v.statement, v.shortstatement,
                    i.code, f.ownerid AS programid
               FROM {local_outcomemap_itemver} v
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE f.ownertype = :owner AND f.ownerid $progsql
                AND f.status = :fapproved AND i.status = :iapproved AND v.status = :vapproved
                AND v.effectivefrom <= :at1 AND (v.effectiveto IS NULL OR v.effectiveto > :at2)
           ORDER BY i.code, v.version DESC",
            $progparams + [
                'owner' => framework_service::OWNER_PROGRAM,
                'fapproved' => workflow::APPROVED,
                'iapproved' => workflow::APPROVED,
                'vapproved' => workflow::APPROVED,
                'at1' => $at,
                'at2' => $at,
            ]
        );
        $outcomesbyprogram = [];
        foreach ($outcomerecords as $record) {
            $programid = (int) $record->programid;
            $itemid = (int) $record->itemid;
            // Rows arrive newest version first per code; keep the first.
            if (isset($outcomesbyprogram[$programid][$itemid])) {
                continue;
            }
            $outcomesbyprogram[$programid][$itemid] = [
                'itemid' => $itemid,
                'code' => (string) $record->code,
                'statement' => (string) $record->statement,
                'shortstatement' => (string) ($record->shortstatement ?? $record->statement),
            ];
        }

        // One preferred contribution per (outcome item, catalog course).
        $byitemcourse = [];
        foreach ($rows as $row) {
            $catalogid = $catalogbycinst[(int) $row['cinstid']] ?? null;
            if ($catalogid === null) {
                continue;
            }
            $key = (int) $row['itemid'] . ':' . $catalogid;
            $byitemcourse[$key] = isset($byitemcourse[$key])
                ? self::prefer($byitemcourse[$key], $row)
                : $row;
        }
        $rowsbyitem = [];
        foreach ($byitemcourse as $key => $row) {
            [$itemid] = explode(':', $key);
            $rowsbyitem[(int) $itemid][] = $row;
        }

        $out = [];
        foreach ($programs as $program) {
            $programid = (int) $program->id;
            $coursestotal = isset($totals[$programid]) ? (int) $totals[$programid]->coursestotal : 0;
            $outcomes = [];
            $ladders = [];
            foreach ($outcomesbyprogram[$programid] ?? [] as $itemid => $outcome) {
                $pooled = self::pool_outcome($outcome, $rowsbyitem[$itemid] ?? [], $coursestotal);
                $outcomes[] = $pooled;
                if ($pooled['expectedpercent'] !== null || $pooled['strongpercent'] !== null) {
                    $ladders[$pooled['expectedpercent'] . '|' . $pooled['strongpercent']] = [
                        'expected' => $pooled['expectedpercent'],
                        'strong' => $pooled['strongpercent'],
                    ];
                }
            }
            if (!$outcomes) {
                // A program that promises no outcomes has nothing to certify;
                // an empty block would only invite the SIS to render a
                // heading over nothing.
                continue;
            }
            usort($outcomes, static fn(array $a, array $b): int =>
                [$a['code'], $a['itemid']] <=> [$b['code'], $b['itemid']]);
            // One ladder across every outcome lets the page speak of "the 70%
            // mark" in one voice; mixed ladders make that claim untrue, so the
            // program withholds it and each outcome keeps its own.
            $ladder = count($ladders) === 1 ? reset($ladders) : ['expected' => null, 'strong' => null];
            $out[] = [
                'code' => (string) $program->code,
                'name' => (string) $program->name,
                'expectedpercent' => $ladder['expected'],
                'strongpercent' => $ladder['strong'],
                'outcomes' => $outcomes,
            ];
        }

        return [
            'generatedat' => $at,
            'algoversion' => calculation_service::ALGO_VERSION,
            'programs' => $out,
        ];
    }

    /**
     * Choose the contribution that represents one catalog course.
     *
     * A released calculated figure beats any placeholder state; among
     * calculated figures the most recent wins (a retake supersedes the first
     * sitting); ties break on cinstid so the choice is deterministic.
     *
     * @param array $a One learner-safe report row.
     * @param array $b Another learner-safe report row for the same item and course.
     * @return array The preferred row.
     */
    public static function prefer(array $a, array $b): array {
        $rank = static fn(array $row): array => [
            $row['state'] === calculation_service::STATE_CALCULATED && $row['percentage'] !== null ? 1 : 0,
            (int) ($row['timecalculated'] ?? 0),
            (int) ($row['cinstid'] ?? 0),
        ];
        return ($rank($a) <=> $rank($b)) >= 0 ? $a : $b;
    }

    /**
     * Pool one program outcome's per-course contributions.
     *
     * Sums canonical numerators and denominators across contributing courses
     * and divides once — never averaging per-course percentages. Rows in any
     * non-calculated state contribute no figures; they only decide the pooled
     * state when nothing calculated exists at all.
     *
     * @param array $outcome Outcome descriptor: itemid, code, statement, shortstatement.
     * @param array $rowsbycourse One preferred report row per contributing catalog course.
     * @param int $coursestotal Catalog courses the program currently promises.
     * @return array Pooled display-safe outcome row.
     */
    public static function pool_outcome(array $outcome, array $rowsbycourse, int $coursestotal): array {
        $numerator = decimal::ZERO;
        $denominator = decimal::ZERO;
        $gradeditems = 0;
        $coursesassessed = 0;
        $timecalculated = null;
        $ladders = [];
        $states = [];
        foreach ($rowsbycourse as $row) {
            $states[(string) $row['state']] = true;
            if ($row['state'] !== calculation_service::STATE_CALCULATED
                    || $row['percentage'] === null
                    || $row['weightedearned'] === null
                    || $row['weightedpossible'] === null) {
                continue;
            }
            $numerator = decimal::add($numerator, decimal::canonical($row['weightedearned'], 'weightedearned'));
            $denominator = decimal::add($denominator, decimal::canonical($row['weightedpossible'], 'weightedpossible'));
            $gradeditems += (int) ($row['distinctitems'] ?? 0);
            $coursesassessed++;
            $timecalculated = max($timecalculated ?? 0, (int) ($row['timecalculated'] ?? 0));
            $ladders[($row['expectedpercent'] ?? '') . '|' . ($row['strongpercent'] ?? '')] = [
                'expected' => $row['expectedpercent'],
                'strong' => $row['strongpercent'],
            ];
        }

        $percentage = null;
        if ($coursesassessed > 0 && !decimal::is_zero($denominator)) {
            $state = calculation_service::STATE_CALCULATED;
            $percentage = decimal::div(decimal::mul($numerator, '100'), $denominator);
        } else if ($coursesassessed > 0) {
            // Calculated rows whose pooled weight is zero carry no judgement.
            // persist_result should never produce them; fail toward "not
            // enough evidence" rather than toward a percentage.
            $state = calculation_service::STATE_INSUFFICIENT;
            $coursesassessed = 0;
            $gradeditems = 0;
            $timecalculated = null;
            $ladders = [];
        } else {
            $state = calculation_service::STATE_NOT_ASSESSED;
            foreach (self::FALLBACK_STATES as $candidate) {
                if (isset($states[$candidate])) {
                    $state = $candidate;
                    break;
                }
            }
        }
        $ladder = count($ladders) === 1 ? reset($ladders) : ['expected' => null, 'strong' => null];

        return [
            'itemid' => (int) $outcome['itemid'],
            'code' => (string) $outcome['code'],
            'statement' => (string) $outcome['statement'],
            'shortstatement' => (string) $outcome['shortstatement'],
            'state' => $state,
            'percentage' => $percentage,
            'coursesassessed' => $coursesassessed,
            'coursestotal' => $coursestotal,
            'gradeditems' => $gradeditems,
            'expectedpercent' => $ladder['expected'],
            'strongpercent' => $ladder['strong'],
            'timecalculated' => $timecalculated,
        ];
    }
}
