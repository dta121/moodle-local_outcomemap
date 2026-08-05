<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Current learner's released course learning outcome results.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\local\service\student_result_service;
use local_outcomemap\output\student_results;

$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid, MUST_EXIST);
require_capability('local/outcomemap:viewownresults', $context);

$url = new moodle_url('/local/outcomemap/results.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('outcomeresults_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

$report = student_result_service::get_own_report($courseid);
$view = new student_results($report);

echo $OUTPUT->header();
// The template opens with its own summary heading and closes with the
// data-handling note, so neither is echoed here as well.
echo $OUTPUT->render_from_template('local_outcomemap/student_results', $view->export_for_template($OUTPUT));
echo $OUTPUT->footer();
