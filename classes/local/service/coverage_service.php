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

/**
 * Builds course coverage projections from bulk-loaded mapping records.
 */
final class coverage_service extends base_service {
    /**
     * Build a course coverage matrix without per-target queries.
     *
     * @param int $courseid Moodle course identifier.
     * @return array<int,object> Rows keyed by exact outcome-version ID.
     */
    public static function matrix(int $courseid): array {
        $mappings = content_mapping_service::list_for_course($courseid);
        $rows = [];
        foreach (['sections', 'modules'] as $collection) {
            foreach ($mappings[$collection] as $mapping) {
                $itemverid = (int) $mapping->itemverid;
                if (!isset($rows[$itemverid])) {
                    $rows[$itemverid] = (object) [
                        'itemverid' => $itemverid,
                        'frameworkcode' => $mapping->frameworkcode,
                        'outcomecode' => $mapping->outcomecode,
                        'outcomeversion' => (int) $mapping->outcomeversion,
                        'statement' => $mapping->outcomestatement,
                        'sections' => [],
                        'modules' => [],
                    ];
                }
                $rows[$itemverid]->{$collection}[] = $mapping;
            }
        }
        uasort($rows, static function (\stdClass $a, \stdClass $b): int {
            return [$a->frameworkcode, $a->outcomecode, $a->outcomeversion]
                <=> [$b->frameworkcode, $b->outcomecode, $b->outcomeversion];
        });
        return $rows;
    }
}
