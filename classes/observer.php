<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap;

use local_outcomemap\local\service\question_mapping_service;

/**
 * Core event observers.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {
    /**
     * Copy approved outcome mappings to a newly created question version as drafts.
     *
     * The event carries the question ID only, so the concrete version is
     * resolved from core. Copies are drafts and require review before they can
     * generate approved evidence; nothing is auto-approved.
     *
     * @param \core\event\question_created $event Question created event.
     * @return void
     */
    public static function question_created(\core\event\question_created $event): void {
        global $DB;
        if (get_config('local_outcomemap', 'autocopyquestionmappings') === '0') {
            return;
        }
        $version = $DB->get_record('question_versions', ['questionid' => $event->objectid]);
        if (!$version || (int) $version->version <= 1) {
            return;
        }
        try {
            question_mapping_service::copy_to_version((int) $version->id);
        } catch (\moodle_exception $e) {
            // Question creation must never fail because mapping governance
            // rules or capabilities block an automatic copy; the qbank UI can
            // still offer an explicit copy later.
            debugging('local_outcomemap: automatic question-mapping copy skipped: '
                . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
