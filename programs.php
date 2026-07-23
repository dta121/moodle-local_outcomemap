<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\program_form;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\workflow;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

admin_externalpage_setup('local_outcomemap_programs');
$url = new moodle_url('/local/outcomemap/programs.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'submit' && $id) {
    require_sesskey();
    program_service::submit_for_review($id);
    redirect($url, get_string('submittedforreview', 'local_outcomemap'));
}

if ($action === 'edit' || $action === 'add') {
    $form = new program_form($url);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        if ($data->id) {
            program_service::update((int) $data->id, (array) $data);
        } else {
            program_service::create((array) $data);
        }
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($id) {
        $form->set_data($DB->get_record('local_outcomemap_program', ['id' => $id], '*', MUST_EXIST));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($id ? 'editprogram' : 'addprogram', 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('programs_heading', 'local_outcomemap'));
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'add']), get_string('addprogram', 'local_outcomemap'));
$table = new html_table();
$table->head = [get_string('code', 'local_outcomemap'), get_string('name', 'local_outcomemap'),
    get_string('status', 'local_outcomemap'), get_string('actions', 'local_outcomemap')];
foreach (program_service::list_all() as $record) {
    $actions = [];
    if ($record->status === workflow::DRAFT) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'edit', 'id' => $record->id]),
            get_string('edit'));
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'submit', 'id' => $record->id,
            'sesskey' => sesskey()]), get_string('submitreview', 'local_outcomemap'));
    }
    $table->data[] = [s($record->code), format_string($record->name),
        get_string('status_' . $record->status, 'local_outcomemap'), implode(' | ', $actions)];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
