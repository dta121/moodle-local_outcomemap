<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
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
$table->head = [get_string('objecttype', 'local_outcomemap'), get_string('count')];
foreach ($counts as $type => $count) {
    $table->data[] = [get_string('nav_' . $type, 'local_outcomemap'), $count];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
