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
 * Course coverage projection service.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\workflow;

/**
 * Builds course coverage projections from bulk-loaded mapping records.
 */
final class coverage_service extends base_service {
    /** Taught and assessed by mapped content. */
    public const STATUS_FULL = 'full';

    /** Assessed, but no teaching content is mapped. */
    public const STATUS_ASSESSED_ONLY = 'assessedonly';

    /** Taught, but no assessing content is mapped. */
    public const STATUS_TAUGHT = 'taught';

    /** No content mapped at all. */
    public const STATUS_NONE = 'none';

    /**
     * Classify one matrix row by the roles of the content mapped to it.
     *
     * The assessing role is what makes an outcome measurable, so it is the axis
     * that separates a complete outcome from a gap. Every other role counts as
     * teaching content for this purpose.
     *
     * @param \stdClass $row Matrix row produced by {@see matrix()}.
     * @return string One of this class's STATUS_* values.
     */
    public static function row_status(\stdClass $row): string {
        $taught = false;
        $assessed = false;
        foreach (array_merge($row->sections, $row->modules) as $mapping) {
            if ($mapping->role === content_mapping_service::ROLE_ASSESSES) {
                $assessed = true;
            } else {
                $taught = true;
            }
        }
        if ($taught && $assessed) {
            return self::STATUS_FULL;
        }
        if ($assessed) {
            return self::STATUS_ASSESSED_ONLY;
        }
        return $taught ? self::STATUS_TAUGHT : self::STATUS_NONE;
    }

    /**
     * Build a course coverage matrix without per-target queries.
     *
     * Every outcome the course is responsible for is represented, including
     * outcomes nothing maps to yet: an uncovered outcome is the finding a
     * coverage report exists to surface. Rows carry a `covered` flag so callers
     * can distinguish "no mapping" from "not applicable".
     *
     * @param int $courseid Moodle course identifier.
     * @return array<int,object> Rows keyed by exact outcome-version ID.
     */
    public static function matrix(int $courseid): array {
        $rows = self::course_outcome_baseline($courseid);
        $mappings = content_mapping_service::list_for_course($courseid);
        foreach (['sections', 'modules'] as $collection) {
            foreach ($mappings[$collection] as $mapping) {
                $itemverid = (int) $mapping->itemverid;
                if (!isset($rows[$itemverid])) {
                    // Mapped outside the course's own frameworks, or under an
                    // outcome version that is no longer current. Still reported.
                    $rows[$itemverid] = (object) [
                        'itemverid' => $itemverid,
                        'frameworkcode' => $mapping->frameworkcode,
                        'outcomecode' => $mapping->outcomecode,
                        'outcomeversion' => (int) $mapping->outcomeversion,
                        'statement' => $mapping->outcomestatement,
                        'sections' => [],
                        'modules' => [],
                        'covered' => false,
                    ];
                }
                $rows[$itemverid]->{$collection}[] = $mapping;
                $rows[$itemverid]->covered = true;
            }
        }
        uasort($rows, static function (\stdClass $a, \stdClass $b): int {
            return [$a->frameworkcode, $a->outcomecode, $a->outcomeversion]
                <=> [$b->frameworkcode, $b->outcomecode, $b->outcomeversion];
        });
        return $rows;
    }

    /**
     * Return the currently effective outcomes the course is responsible for.
     *
     * Scope is the approved frameworks owned by the catalog courses this Moodle
     * course is associated with through an approved, confirmed course instance —
     * the same association that makes a mapping valid in the first place.
     *
     * @param int $courseid Moodle course identifier.
     * @return array<int,object> Uncovered baseline rows keyed by outcome-version ID.
     */
    public static function course_outcome_baseline(int $courseid): array {
        global $DB;

        $now = time();
        $records = $DB->get_records_sql(
            "SELECT v.id AS itemverid, f.code AS frameworkcode, i.code AS outcomecode,
                    v.version AS outcomeversion, v.statement AS outcomestatement
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
           ORDER BY f.code, i.code, v.version",
            [
                'ownertype' => framework_service::OWNER_COURSE,
                'courseid' => $courseid,
                'cinststatus' => workflow::APPROVED,
                'fstatus' => workflow::APPROVED,
                'istatus' => workflow::APPROVED,
                'vstatus' => workflow::APPROVED,
                'at1' => $now,
                'at2' => $now,
            ]
        );
        $rows = [];
        foreach ($records as $record) {
            $rows[(int) $record->itemverid] = (object) [
                'itemverid' => (int) $record->itemverid,
                'frameworkcode' => $record->frameworkcode,
                'outcomecode' => $record->outcomecode,
                'outcomeversion' => (int) $record->outcomeversion,
                'statement' => $record->outcomestatement,
                'sections' => [],
                'modules' => [],
                'covered' => false,
            ];
        }
        return $rows;
    }
}
