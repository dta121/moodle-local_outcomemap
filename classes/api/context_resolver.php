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
 * Learning Outcome Mapping plugin component.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\api;

use local_outcomemap\local\validation_exception;

/**
 * Public authority for resolving domain records to Moodle contexts.
 */
final class context_resolver {
    /**
     * Public API version.
     */
    public const API_VERSION = '1.0';

    /**
     * Resolve a centrally governed foundation entity.
     */
    public static function for_governed_definition(): \context_system {
        return \context_system::instance();
    }

    /**
     * Resolve a course-instance association to its authoritative course context.
     *
     * @param int $courseinstanceid Courseinstanceid.
     */
    public static function for_course_instance(int $courseinstanceid): \context_course {
        global $DB;
        $moodlecourseid = $DB->get_field(
            'local_outcomemap_cinst',
            'moodlecourseid',
            ['id' => $courseinstanceid]
        );
        if (!$moodlecourseid) {
            throw new validation_exception('recordnotfound', 'course_instance', $courseinstanceid);
        }
        return \context_course::instance((int) $moodlecourseid, MUST_EXIST);
    }

    /**
     * Resolve a Moodle course-module target after validating it exists.
     *
     * @param int $cmid Cmid.
     */
    public static function for_course_module(int $cmid): \context_module {
        return \context_module::instance($cmid, MUST_EXIST);
    }

    /**
     * Resolve the actual question-category context for a question version.
     *
     * @param int $questionversionid Questionversionid.
     */
    public static function for_question_version(int $questionversionid): \context {
        global $DB;
        $sql = "SELECT qc.contextid
                  FROM {question_versions} qv
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE qv.id = :questionversionid";
        $contextid = $DB->get_field_sql($sql, ['questionversionid' => $questionversionid]);
        if (!$contextid) {
            throw new validation_exception('recordnotfound', 'question_version', $questionversionid);
        }
        return \context::instance_by_id((int) $contextid, MUST_EXIST);
    }

    /**
     * Resolve and require a capability without accepting a browser context ID.
     *
     * @param int $courseinstanceid Courseinstanceid.
     * @param string $capability Capability.
     */
    public static function require_for_course_instance(int $courseinstanceid, string $capability): \context_course {
        $context = self::for_course_instance($courseinstanceid);
        require_capability($capability, $context);
        return $context;
    }
}
