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

namespace local_outcomemap\reportbuilder\local;

use local_outcomemap\local\feature;
use local_outcomemap\reportbuilder\datasource;

/**
 * Registry of the plugin's custom Report Builder data sources.
 *
 * The reports entry point, the example seeder, and the tests all read this one
 * ordered list so a new source never has to be registered twice.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sources {
    /**
     * Every governed source in reporting order, keyed by its short name.
     *
     * Sources belonging to a disabled optional feature are omitted so no entry
     * point offers a report that the feature switch has withdrawn.
     *
     * @return array<string,class-string<secured_datasource>>
     */
    public static function all(): array {
        $sources = [
            'outcome_definitions' => datasource\outcome_definitions::class,
            'mapping_coverage' => datasource\mapping_coverage::class,
            'assessment_coverage' => datasource\assessment_coverage::class,
            'student_attainment' => datasource\student_attainment::class,
            'course_aggregates' => datasource\course_aggregates::class,
            'program_aggregates' => datasource\program_aggregates::class,
            'remediation_engagement' => datasource\remediation_engagement::class,
            'audit_history' => datasource\audit_history::class,
        ];
        if (!feature::remediation_enabled()) {
            unset($sources['remediation_engagement']);
        }
        return $sources;
    }

    /**
     * Translated display name of one source.
     *
     * @param string $key Short source name.
     * @return string
     */
    public static function name(string $key): string {
        return get_string('report_source_' . $key, 'local_outcomemap');
    }
}
