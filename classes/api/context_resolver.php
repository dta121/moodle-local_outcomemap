<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\api;

use local_outcomemap\local\validation_exception;

/** Public authority for resolving domain records to Moodle contexts. */
final class context_resolver {
    public const API_VERSION = '1.0';

    /** Resolve a centrally governed foundation entity. */
    public static function for_governed_definition(): \context_system {
        return \context_system::instance();
    }

    /** Resolve a course-instance association to its authoritative course context. */
    public static function for_course_instance(int $courseinstanceid): \context_course {
        global $DB;
        $moodlecourseid = $DB->get_field('local_outcomemap_cinst', 'moodlecourseid',
            ['id' => $courseinstanceid]);
        if (!$moodlecourseid) {
            throw new validation_exception('recordnotfound', 'course_instance', $courseinstanceid);
        }
        return \context_course::instance((int) $moodlecourseid, MUST_EXIST);
    }

    /** Resolve a Moodle course-module target after validating it exists. */
    public static function for_course_module(int $cmid): \context_module {
        return \context_module::instance($cmid, MUST_EXIST);
    }

    /** Resolve the actual question-category context for a question version. */
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

    /** Resolve and require a capability without accepting a browser context ID. */
    public static function require_for_course_instance(int $courseinstanceid, string $capability): \context_course {
        $context = self::for_course_instance($courseinstanceid);
        require_capability($capability, $context);
        return $context;
    }
}
