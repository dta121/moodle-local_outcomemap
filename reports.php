<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Entry point for the plugin's Moodle Report Builder data sources.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_outcomemap_reports');
require_capability('local/outcomemap:viewdefinitions', context_system::instance());

$sources = [
    'outcome_definitions',
    'mapping_coverage',
    'assessment_coverage',
    'student_attainment',
    'course_aggregates',
    'program_aggregates',
    'remediation_engagement',
    'audit_history',
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reports_heading', 'local_outcomemap'));
echo html_writer::tag('p', get_string('reports_intro', 'local_outcomemap'));
$list = [];
foreach ($sources as $source) {
    $list[] = get_string('report_source_' . $source, 'local_outcomemap');
}
echo html_writer::alist($list);
echo $OUTPUT->single_button(
    new moodle_url('/reportbuilder/index.php'),
    get_string('reportbuildernav', 'local_outcomemap')
);
echo $OUTPUT->footer();
