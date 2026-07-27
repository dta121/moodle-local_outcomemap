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

use core_reportbuilder\datasource;
use core_reportbuilder\local\models\report as report_model;
use core_reportbuilder\permission as report_permission;
use local_outcomemap\reportbuilder\local\sources;

$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_outcomemap_reports');
require_capability('local/outcomemap:viewdefinitions', context_system::instance());

/**
 * Load the custom reports built on one data source that the viewer may open.
 *
 * Report Builder access is independent of this page, so every candidate is
 * rechecked rather than assumed visible from the plugin capability alone.
 *
 * @param string $class Data source class name.
 * @return array<int,string> Report names keyed by report ID.
 */
function local_outcomemap_source_reports(string $class): array {
    global $DB;
    $reports = [];
    $records = $DB->get_records('reportbuilder_report', [
        'source' => $class,
        'type' => datasource::TYPE_CUSTOM_REPORT,
    ], 'name, id');
    foreach ($records as $record) {
        $model = new report_model(0, $record);
        if (report_permission::can_view_report($model)) {
            $reports[(int) $record->id] = (string) $record->name;
        }
    }
    return $reports;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reports_heading', 'local_outcomemap'));
echo html_writer::tag('p', get_string('reports_intro', 'local_outcomemap'));

$table = new html_table();
$table->caption = get_string('reports_heading', 'local_outcomemap');
$table->head = [
    get_string('reports_source', 'local_outcomemap'),
    get_string('reports_existing', 'local_outcomemap'),
];
foreach (sources::all() as $key => $class) {
    $links = [];
    foreach (local_outcomemap_source_reports($class) as $reportid => $name) {
        $links[] = html_writer::link(
            new moodle_url('/reportbuilder/view.php', ['id' => $reportid]),
            format_string($name)
        );
    }
    $table->data[] = [
        sources::name($key),
        $links === []
            ? html_writer::span(get_string('reports_none', 'local_outcomemap'), 'text-muted')
            : html_writer::alist($links),
    ];
}
echo html_writer::div(html_writer::table($table), 'table-responsive');
echo html_writer::tag('p', get_string('reports_seedhint', 'local_outcomemap'));
echo $OUTPUT->single_button(
    new moodle_url('/reportbuilder/index.php'),
    get_string('reportbuildernav', 'local_outcomemap')
);
echo $OUTPUT->footer();
