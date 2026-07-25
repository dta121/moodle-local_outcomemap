<?php
// This file is part of Moodle - http://moodle.org/

$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    // Windows junctions resolve __DIR__ to the repository target rather than
    // the Moodle local-plugin directory. The webroot loader remains portable
    // across Moodle's classic and 5.2 public-directory layouts.
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_outcomemap_dashboard');
require_capability('local/outcomemap:viewdefinitions', context_system::instance());

$counts = [
    'programs' => $DB->count_records('local_outcomemap_program'),
    'catalogcourses' => $DB->count_records('local_outcomemap_course'),
    'courseinstances' => $DB->count_records('local_outcomemap_cinst'),
    'frameworks' => $DB->count_records('local_outcomemap_fw'),
    'outcomes' => $DB->count_records('local_outcomemap_item'),
    'relations' => $DB->count_records('local_outcomemap_rel'),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('dashboard_heading', 'local_outcomemap'));
echo html_writer::tag('p', get_string('dashboard_summary', 'local_outcomemap'));
$table = new html_table();
$table->caption = get_string('dashboard_summary', 'local_outcomemap');
$table->head = [get_string('objecttype', 'local_outcomemap'), get_string('count')];
foreach ($counts as $type => $count) {
    $table->data[] = [get_string('nav_' . $type, 'local_outcomemap'), $count];
}
echo html_writer::div(html_writer::table($table), 'table-responsive');
echo $OUTPUT->footer();
