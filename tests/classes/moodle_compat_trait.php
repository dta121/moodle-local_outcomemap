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

namespace local_outcomemap\tests;

/**
 * Bridges core API differences across the Moodle versions this plugin supports.
 *
 * The plugin declares Moodle 4.5 as its minimum but is also expected to run on
 * Moodle 5.x, where several question and quiz APIs changed shape. Tests use
 * these helpers so they exercise real behaviour on every supported version
 * instead of being skipped on the declared minimum.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait moodle_compat_trait {
    /**
     * Return a context ID that can own a question category in this Moodle.
     *
     * Moodle 5.0 introduced mod_qbank and moved shared question banks into that
     * activity. On 4.5 no such module exists and question categories live
     * directly in the course context.
     *
     * @param \stdClass $course Course the questions belong to.
     * @return int Context ID for question-category creation.
     */
    protected function question_bank_contextid(\stdClass $course): int {
        global $CFG;

        if (file_exists($CFG->dirroot . '/mod/qbank/version.php')) {
            $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
            return (int) \context_module::instance($qbank->cmid)->id;
        }
        return (int) \context_course::instance($course->id)->id;
    }

    /**
     * Submit and grade a quiz attempt.
     *
     * Moodle 5.0 split process_finish() into a separate submission step and
     * grading step; 4.5 performs both in one call.
     *
     * @param object $attemptobj Quiz attempt wrapper.
     * @param int $timestamp Submission time.
     * @return void
     */
    protected function finish_quiz_attempt(object $attemptobj, int $timestamp): void {
        if (method_exists($attemptobj, 'process_submit')) {
            $attemptobj->process_submit($timestamp, false);
            $attemptobj->process_grade_submission($timestamp);
            return;
        }
        $attemptobj->process_finish($timestamp, false);
    }
}
