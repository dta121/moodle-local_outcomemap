<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\reportbuilder\local;

use lang_string;

/**
 * Query-free option lists shared by reporting filters.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class filter_options {
    /**
     * Governed workflow states.
     *
     * @return array
     */
    public static function workflow_states(): array {
        return [
            'draft' => new lang_string('status_draft', 'local_outcomemap'),
            'needs_review' => new lang_string('status_needs_review', 'local_outcomemap'),
            'approved' => new lang_string('status_approved', 'local_outcomemap'),
            'retired' => new lang_string('status_retired', 'local_outcomemap'),
        ];
    }

    /**
     * Framework owner types.
     *
     * @return array
     */
    public static function owner_types(): array {
        return [
            'institution' => new lang_string('owner_institution', 'local_outcomemap'),
            'program' => new lang_string('owner_program', 'local_outcomemap'),
            'catalog_course' => new lang_string('owner_catalog_course', 'local_outcomemap'),
        ];
    }

    /**
     * Mapping roles.
     *
     * @return array
     */
    public static function mapping_roles(): array {
        return [
            'teaches' => new lang_string('mappingrole_teaches', 'local_outcomemap'),
            'practices' => new lang_string('mappingrole_practices', 'local_outcomemap'),
            'assesses' => new lang_string('mappingrole_assesses', 'local_outcomemap'),
            'remediates' => new lang_string('mappingrole_remediates', 'local_outcomemap'),
            'alignment_only' => new lang_string('mappingrole_alignment_only', 'local_outcomemap'),
        ];
    }

    /**
     * Outcome relationship types.
     *
     * @return array
     */
    public static function relation_types(): array {
        return [
            'is_child_of' => new lang_string('relation_is_child_of', 'local_outcomemap'),
            'aligns_to' => new lang_string('relation_aligns_to', 'local_outcomemap'),
            'contributes_to' => new lang_string('relation_contributes_to', 'local_outcomemap'),
            'replaced_by' => new lang_string('relation_replaced_by', 'local_outcomemap'),
            'related_to' => new lang_string('relation_related_to', 'local_outcomemap'),
        ];
    }

    /**
     * Student-result states.
     *
     * @return array
     */
    public static function result_states(): array {
        return [
            'not_assessed' => new lang_string('resultstate_not_assessed', 'local_outcomemap'),
            'insufficient_evidence' => new lang_string('resultstate_insufficient_evidence', 'local_outcomemap'),
            'calculation_pending' => new lang_string('resultstate_calculation_pending', 'local_outcomemap'),
            'calculated' => new lang_string('resultstate_calculated', 'local_outcomemap'),
            'superseded' => new lang_string('resultstate_superseded', 'local_outcomemap'),
        ];
    }

    /**
     * Stored snapshot aggregate states.
     *
     * @return array
     */
    public static function aggregate_states(): array {
        return [
            'calculated' => new lang_string('resultstate_calculated', 'local_outcomemap'),
            'not_calculated' => new lang_string('resultstate_not_calculated', 'local_outcomemap'),
        ];
    }

    /**
     * Result scopes.
     *
     * @return array
     */
    public static function result_scopes(): array {
        return [
            'quiz_attempt' => new lang_string('resultscope_quiz_attempt', 'local_outcomemap'),
            'assessment' => new lang_string('resultscope_assessment', 'local_outcomemap'),
            'course' => new lang_string('resultscope_course', 'local_outcomemap'),
        ];
    }

    /**
     * Remediation target types.
     *
     * @return array
     */
    public static function remediation_targets(): array {
        return [
            'course_module' => new lang_string('target_course_module', 'local_outcomemap'),
            'course_section' => new lang_string('target_course_section', 'local_outcomemap'),
            'external_url' => new lang_string('target_external_url', 'local_outcomemap'),
        ];
    }

    /**
     * Remediation purposes.
     *
     * @return array
     */
    public static function remediation_purposes(): array {
        return [
            'review' => new lang_string('remediationpurpose_review', 'local_outcomemap'),
            'practice' => new lang_string('remediationpurpose_practice', 'local_outcomemap'),
            'reassessment' => new lang_string('remediationpurpose_reassessment', 'local_outcomemap'),
        ];
    }
}
