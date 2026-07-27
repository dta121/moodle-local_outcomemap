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
                    r.timecalculated, r.periodcode,
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
                    'items' => 0,
                    'lastcalculated' => 0,
                ];
            }
            $row = $rows[$key];
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

        foreach ($rows as $row) {
            $row->average = $row->percentages
                ? array_sum($row->percentages) / count($row->percentages)
                : null;
            $row->unassessed = $row->learners - $row->calculated;
            usort($row->bands, static fn($a, $b) => $a->sortorder <=> $b->sortorder);
            // The lowest band is the one to act on, so surface its share directly.
            $row->lowestband = $row->bands ? reset($row->bands) : null;
            unset($row->percentages);
        }

        return (object) [
            'rows' => array_values($rows),
            'learners' => count($learners),
            'periodcodes' => array_values(array_unique(array_map(
                static fn($i) => $i->periodcode, $instances))),
            'hasinstance' => true,
        ];
    }
}
