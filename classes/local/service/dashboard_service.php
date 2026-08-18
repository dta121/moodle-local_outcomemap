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

namespace local_outcomemap\local\service;

use local_outcomemap\local\workflow;

/**
 * Aggregates the readiness signals the dashboard reports on.
 *
 * The dashboard answers one question: could this site produce a defensible
 * accreditation report today, and if not, what is in the way. Every figure here
 * is therefore a gap rather than an inventory total, and each one is scoped
 * exactly as the page that fixes it scopes its own work — an outcome only counts
 * as a coverage gap in a delivery that is actually authorised to collect
 * evidence for it. Counts describe the current draft state; a frozen snapshot
 * remains the only authoritative record.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dashboard_service extends base_service {
    /**
     * Share of in-scope outcomes that must be fully covered to call a program report-ready.
     */
    public const READY_THRESHOLD = 90;

    /**
     * Grouped governance changes shown in the activity feed.
     */
    private const ACTIVITY_LIMIT = 6;

    /**
     * Audit rows the feed will read before it stops grouping.
     */
    private const ACTIVITY_SCAN_LIMIT = 2000;

    /**
     * Build every dashboard figure.
     *
     * @param int|null $at Effective timestamp, defaulting to now.
     * @return array Readiness signals: gaps, programs, activity, and inventory.
     */
    public static function summary(?int $at = null): array {
        self::require_system('local/outcomemap:viewdefinitions');
        $at = $at ?? time();
        $coverage = self::coverage_rows($at);

        return [
            'unaligned' => self::unaligned_outcomes($at),
            'unframedcourses' => self::courses_without_outcomes(),
            'pendingapproval' => self::outcomes_awaiting_approval(),
            'nocontent' => self::count_status($coverage, coverage_service::STATUS_NONE),
            'taughtnotassessed' => self::count_status($coverage, coverage_service::STATUS_TAUGHT),
            'worstdelivery' => self::worst_delivery($coverage),
            'programs' => self::program_readiness($coverage, $at),
            'activity' => self::activity(),
            'inventory' => self::inventory(),
        ];
    }

    /**
     * Course-level outcomes that roll up to nothing.
     *
     * A course outcome with no approved relation contributes to no program, so
     * evidence collected against it can never reach an accreditation report.
     *
     * @param int $at Effective timestamp.
     * @return int
     */
    private static function unaligned_outcomes(int $at): int {
        global $DB;
        return (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {local_outcomemap_item} i
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE f.ownertype = :ownertype
                AND f.status = :fstatus
                AND i.status = :istatus
                AND NOT EXISTS (SELECT 1
                                  FROM {local_outcomemap_rel} r
                                 WHERE r.sourceitemid = i.id
                                   AND r.status = :rstatus
                                   AND r.effectivefrom <= :at1
                                   AND (r.effectiveto IS NULL OR r.effectiveto > :at2))",
            [
                'ownertype' => framework_service::OWNER_COURSE,
                'fstatus' => workflow::APPROVED,
                'istatus' => workflow::APPROVED,
                'rstatus' => workflow::APPROVED,
                'at1' => $at,
                'at2' => $at,
            ]
        );
    }

    /**
     * Catalog courses that own no approved outcome at all.
     *
     * @return \stdClass[] Course code and name, ordered by code.
     */
    private static function courses_without_outcomes(): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT c.id, c.code, c.name
               FROM {local_outcomemap_course} c
              WHERE c.status <> :cretired
                AND NOT EXISTS (SELECT 1
                                  FROM {local_outcomemap_fw} f
                                  JOIN {local_outcomemap_item} i ON i.frameworkid = f.id
                                 WHERE f.ownertype = :ownertype
                                   AND f.ownerid = c.id
                                   AND f.status = :fstatus
                                   AND i.status = :istatus)
           ORDER BY c.code ASC",
            [
                'cretired' => workflow::RETIRED,
                'ownertype' => framework_service::OWNER_COURSE,
                'fstatus' => workflow::APPROVED,
                'istatus' => workflow::APPROVED,
            ]
        ));
    }

    /**
     * Outcome versions still short of approval.
     *
     * A draft statement governs nothing, so it is work in progress rather than a
     * defect. It is reported so it does not sit forgotten.
     *
     * @return int
     */
    private static function outcomes_awaiting_approval(): int {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(
            [workflow::DRAFT, workflow::NEEDS_REVIEW],
            SQL_PARAMS_NAMED,
            'status'
        );
        return (int) $DB->count_records_select('local_outcomemap_itemver', "status {$insql}", $params);
    }

    /**
     * Classify every outcome a confirmed delivery is responsible for.
     *
     * Scope and classification deliberately mirror
     * {@see coverage_service::course_outcome_baseline()} and
     * {@see coverage_service::row_status()}, so a gap counted here is the same
     * gap the course's own coverage page shows. The unit is one outcome in one
     * delivery: the same outcome may be complete in one period and uncovered in
     * another. Section and activity mappings are counted in one statement each;
     * question mappings are resolved through the quiz module so fixed questions
     * and random pools mean the same thing here as on the question mapping page.
     *
     * @param int $at Effective timestamp.
     * @return \stdClass[] Rows with programid, itemverid, and a coverage status.
     */
    private static function coverage_rows(int $at): array {
        global $DB;
        $mapped = static function (string $table, string $alias, bool $assessing): string {
            $comparison = $assessing ? '=' : '<>';
            return "(SELECT COUNT(1)
                       FROM {{$table}} {$alias}
                      WHERE {$alias}.cinstid = ci.id
                        AND {$alias}.itemverid = v.id
                        AND {$alias}.role {$comparison} :{$alias}role
                        AND {$alias}.status = :{$alias}status
                        AND {$alias}.effectivefrom <= :{$alias}at1
                        AND ({$alias}.effectiveto IS NULL OR {$alias}.effectiveto > :{$alias}at2))";
        };
        $assessed = $mapped('local_outcomemap_cmmap', 'acm', true)
            . ' + ' . $mapped('local_outcomemap_secmap', 'asm', true);
        $taught = $mapped('local_outcomemap_cmmap', 'tcm', false)
            . ' + ' . $mapped('local_outcomemap_secmap', 'tsm', false);

        $params = [
            'ownertype' => framework_service::OWNER_COURSE,
            'cinststatus' => workflow::APPROVED,
            'fstatus' => workflow::APPROVED,
            'istatus' => workflow::APPROVED,
            'vstatus' => workflow::APPROVED,
            'at1' => $at,
            'at2' => $at,
            'pcretired' => workflow::RETIRED,
            'pcat1' => $at,
            'pcat2' => $at,
        ];
        foreach (['acm' => true, 'asm' => true, 'tcm' => false, 'tsm' => false] as $alias => $assessing) {
            $params[$alias . 'role'] = content_mapping_service::ROLE_ASSESSES;
            $params[$alias . 'status'] = workflow::APPROVED;
            $params[$alias . 'at1'] = $at;
            $params[$alias . 'at2'] = $at;
        }

        // One outcome delivered by one course instance may serve several
        // programs. Each membership is reported so a shared course counts
        // against every program that depends on it.
        $records = $DB->get_recordset_sql(
            "SELECT ci.id AS cinstid, v.id AS itemverid, pc.programid,
                    ci.moodlecourseid, ci.periodcode, cc.code AS coursecode,
                    {$assessed} AS assessed,
                    {$taught} AS taught
               FROM {local_outcomemap_cinst} ci
               JOIN {local_outcomemap_course} cc ON cc.id = ci.courseid
               JOIN {local_outcomemap_fw} f
                 ON f.ownertype = :ownertype AND f.ownerid = ci.courseid
               JOIN {local_outcomemap_item} i ON i.frameworkid = f.id
               JOIN {local_outcomemap_itemver} v ON v.itemid = i.id
          LEFT JOIN {local_outcomemap_progcourse} pc
                 ON pc.courseid = ci.courseid
                AND pc.status <> :pcretired
                AND pc.effectivefrom <= :pcat1
                AND (pc.effectiveto IS NULL OR pc.effectiveto > :pcat2)
              WHERE ci.status = :cinststatus
                AND ci.confirmed = 1
                AND f.status = :fstatus
                AND i.status = :istatus
                AND v.status = :vstatus
                AND v.effectivefrom <= :at1
                AND (v.effectiveto IS NULL OR v.effectiveto > :at2)",
            $params
        );
        $rawrecords = [];
        $courseids = [];
        foreach ($records as $record) {
            $rawrecords[] = $record;
            $courseids[(int) $record->moodlecourseid] = (int) $record->moodlecourseid;
        }
        $records->close();

        $questionassessed = [];
        foreach ($courseids as $courseid) {
            foreach (question_browser_service::assessment_coverage($courseid, $at) as $itemverid => $mappings) {
                if ($mappings) {
                    $questionassessed[$courseid][(int) $itemverid] = true;
                }
            }
        }

        $rows = [];
        foreach ($rawrecords as $record) {
            $assessedcount = (int) $record->assessed
                + (int) isset($questionassessed[(int) $record->moodlecourseid][(int) $record->itemverid]);
            $rows[] = (object) [
                'cinstid' => (int) $record->cinstid,
                'itemverid' => (int) $record->itemverid,
                'programid' => $record->programid === null ? null : (int) $record->programid,
                'moodlecourseid' => (int) $record->moodlecourseid,
                'periodcode' => (string) $record->periodcode,
                'coursecode' => (string) $record->coursecode,
                'status' => self::classify((int) $record->taught, $assessedcount),
            ];
        }
        return $rows;
    }

    /**
     * Identify the delivery holding the most coverage gaps.
     *
     * Coverage is fixed one delivery at a time, so a site-wide total is only
     * actionable once it names somewhere to start.
     *
     * @param \stdClass[] $coverage Coverage rows.
     * @return array|null Delivery identity and its gap counts, or null when clear.
     */
    private static function worst_delivery(array $coverage): ?array {
        $deliveries = [];
        foreach ($coverage as $row) {
            $gap = $row->status === coverage_service::STATUS_NONE
                || $row->status === coverage_service::STATUS_TAUGHT;
            if (!$gap) {
                continue;
            }
            $key = $row->cinstid;
            if (!isset($deliveries[$key])) {
                $deliveries[$key] = [
                    'moodlecourseid' => $row->moodlecourseid,
                    'periodcode' => $row->periodcode,
                    'coursecode' => $row->coursecode,
                    'nocontent' => [],
                    'notassessed' => [],
                ];
            }
            $bucket = $row->status === coverage_service::STATUS_NONE ? 'nocontent' : 'notassessed';
            $deliveries[$key][$bucket][$row->itemverid] = true;
        }
        if ($deliveries === []) {
            return null;
        }
        $worst = null;
        foreach ($deliveries as $delivery) {
            $delivery['nocontent'] = count($delivery['nocontent']);
            $delivery['notassessed'] = count($delivery['notassessed']);
            $delivery['total'] = $delivery['nocontent'] + $delivery['notassessed'];
            if (
                $worst === null
                    || $delivery['total'] > $worst['total']
                    || ($delivery['total'] === $worst['total']
                        && $delivery['coursecode'] < $worst['coursecode'])
            ) {
                $worst = $delivery;
            }
        }
        return $worst;
    }

    /**
     * Apply the coverage page's own status rule to mapping counts.
     *
     * @param int $taught Non-assessing mappings.
     * @param int $assessed Assessing mappings.
     * @return string A {@see coverage_service} STATUS_* value.
     */
    private static function classify(int $taught, int $assessed): string {
        if ($taught > 0 && $assessed > 0) {
            return coverage_service::STATUS_FULL;
        }
        if ($assessed > 0) {
            return coverage_service::STATUS_ASSESSED_ONLY;
        }
        return $taught > 0 ? coverage_service::STATUS_TAUGHT : coverage_service::STATUS_NONE;
    }

    /**
     * Count distinct outcome-in-delivery rows holding one coverage status.
     *
     * @param \stdClass[] $coverage Coverage rows.
     * @param string $status Coverage status.
     * @return int
     */
    private static function count_status(array $coverage, string $status): int {
        $seen = [];
        foreach ($coverage as $row) {
            if ($row->status === $status) {
                $seen[$row->cinstid . ':' . $row->itemverid] = true;
            }
        }
        return count($seen);
    }

    /**
     * Report whether each program could produce a snapshot today.
     *
     * Readiness is the share of the outcomes its confirmed deliveries are
     * responsible for that are both taught and assessed, because only an
     * assessed outcome yields attainment and only attainment reaches a
     * snapshot. A program with no outcome framework has not started rather
     * than scoring zero, which is a different problem with a different fix.
     *
     * @param \stdClass[] $coverage Coverage rows.
     * @param int $at Effective timestamp.
     * @return array[] One row per program, ordered by code.
     */
    private static function program_readiness(array $coverage, int $at): array {
        $totals = [];
        foreach ($coverage as $row) {
            if ($row->programid === null) {
                continue;
            }
            $key = $row->cinstid . ':' . $row->itemverid;
            $totals[$row->programid][$key] = $row->status === coverage_service::STATUS_FULL;
        }

        $rows = [];
        foreach (program_service::list_with_summary($at) as $program) {
            $programid = (int) $program->id;
            $scoped = $totals[$programid] ?? [];
            $inscope = count($scoped);
            $complete = count(array_filter($scoped));
            $hasoutcomes = (int) $program->outcomecount > 0;
            if (!$hasoutcomes) {
                $state = 'none';
                $percent = 0;
            } else {
                $percent = $inscope === 0 ? 0 : (int) round($complete / $inscope * 100);
                $state = $percent >= self::READY_THRESHOLD && $inscope > 0 ? 'ready' : 'gaps';
            }
            $rows[] = [
                'programid' => $programid,
                'code' => (string) $program->code,
                'name' => (string) $program->name,
                'programtype' => (string) $program->programtype,
                'status' => (string) $program->status,
                'outcomecount' => (int) $program->outcomecount,
                'coursecount' => (int) $program->coursecount,
                'inscope' => $inscope,
                'complete' => $complete,
                'percent' => $percent,
                'state' => $state,
            ];
        }
        return $rows;
    }

    /**
     * Summarise the newest governance changes.
     *
     * Calculation events are excluded: recalculation is machine bookkeeping that
     * would bury every human decision under thousands of rows. What remains is
     * collapsed per day, action, and object type, because a correlation groups
     * one bulk operation but leaves a session of individual edits as a row each,
     * and thirty separate "outcome relation created" lines report nothing that
     * "thirty outcome relations created" does not. Grouping happens here rather
     * than in SQL so the day boundary is the reader's own, and the recordset is
     * capped so the cost does not grow with the audit history.
     *
     * @return array[] Newest first: timestamp, action, objecttype, and count.
     */
    private static function activity(): array {
        global $DB;
        $records = $DB->get_recordset_select(
            'local_outcomemap_audit',
            'objecttype <> :result',
            ['result' => 'result'],
            'timecreated DESC, id DESC',
            'id, action, objecttype, timecreated'
        );
        $groups = [];
        $scanned = 0;
        foreach ($records as $record) {
            $timecreated = (int) $record->timecreated;
            $key = implode(':', [
                userdate($timecreated, '%Y-%m-%d'),
                (string) $record->action,
                (string) $record->objecttype,
            ]);
            if (!isset($groups[$key])) {
                if (count($groups) >= self::ACTIVITY_LIMIT) {
                    break;
                }
                $groups[$key] = [
                    'action' => (string) $record->action,
                    'objecttype' => (string) $record->objecttype,
                    'count' => 0,
                    'timecreated' => $timecreated,
                ];
            }
            $groups[$key]['count']++;
            if (++$scanned >= self::ACTIVITY_SCAN_LIMIT) {
                break;
            }
        }
        $records->close();
        return array_values($groups);
    }

    /**
     * Current governed record counts.
     *
     * @return array<string,int>
     */
    private static function inventory(): array {
        global $DB;
        return [
            'programs' => $DB->count_records('local_outcomemap_program'),
            'catalogcourses' => $DB->count_records('local_outcomemap_course'),
            'courseinstances' => $DB->count_records('local_outcomemap_cinst'),
            'frameworks' => $DB->count_records('local_outcomemap_fw'),
            'outcomes' => $DB->count_records('local_outcomemap_item'),
            'relations' => $DB->count_records('local_outcomemap_rel'),
        ];
    }
}
