<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\task;

use local_outcomemap\local\service\calculation_service;

/**
 * Ad hoc recalculation of outcome results after attempt or grading changes.
 *
 * Event observers enqueue this task with duplicate suppression; the heavy
 * calculation never runs in the submitting or grading request.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recalculate_attempt extends \core\task\adhoc_task {
    /**
     * Queue a deduplicated recalculation for one quiz attempt.
     *
     * @param int $quizattemptid Quiz attempt ID.
     * @return void
     */
    public static function queue_for_attempt(int $quizattemptid): void {
        $task = new self();
        $task->set_custom_data(['quizattemptid' => $quizattemptid]);
        $task->set_component('local_outcomemap');
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Queue a deduplicated recalculation for one user and assessment.
     *
     * @param int $courseid Moodle course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid User ID.
     * @return void
     */
    public static function queue_for_user_assessment(int $courseid, int $cmid, int $userid): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid, 'cmid' => $cmid, 'userid' => $userid]);
        $task->set_component('local_outcomemap');
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Execute the recalculation.
     *
     * @return void
     */
    public function execute(): void {
        $data = (object) ($this->get_custom_data() ?? new \stdClass());
        if (!empty($data->quizattemptid)) {
            calculation_service::recalculate_attempt((int) $data->quizattemptid);
            return;
        }
        if (!empty($data->courseid) && !empty($data->cmid) && !empty($data->userid)) {
            calculation_service::recalculate_user_assessment(
                (int) $data->courseid, (int) $data->cmid, (int) $data->userid);
        }
    }
}
