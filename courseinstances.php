<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\course_instance_form;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\workflow;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

admin_externalpage_setup('local_outcomemap_courseinstances');
$url = new moodle_url('/local/outcomemap/courseinstances.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'submit' && $id) {
    require_sesskey();
    course_instance_service::submit_for_review($id);
    redirect($url, get_string('submittedforreview', 'local_outcomemap'));
}
if ($action === 'add') {
    $form = new course_instance_form($url);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        course_instance_service::create((array) $data);
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('addcourseinstance', 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('courseinstances_heading', 'local_outcomemap'));
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'add']), get_string('addcourseinstance', 'local_outcomemap'));
$table = new html_table();
$table->head = [get_string('catalogcourse', 'local_outcomemap'), get_string('moodlecourse', 'local_outcomemap'),
    get_string('periodcode', 'local_outcomemap'), get_string('status', 'local_outcomemap'),
    get_string('confirmed', 'local_outcomemap'), get_string('actions', 'local_outcomemap')];
foreach (course_instance_service::list_all() as $record) {
    $actions = [];
    if ($record->status === workflow::DRAFT) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'submit', 'id' => $record->id,
            'sesskey' => sesskey()]), get_string('submitreview', 'local_outcomemap'));
    }
    $table->data[] = [s($record->catalogcode), format_string($record->moodlename), s($record->periodcode),
        get_string('status_' . $record->status, 'local_outcomemap'), $record->confirmed ? get_string('yes') : get_string('no'),
        implode(' | ', $actions)];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
