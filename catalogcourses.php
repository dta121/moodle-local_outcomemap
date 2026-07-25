<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\catalog_course_form;
use local_outcomemap\form\program_course_form;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\program_course_service;
use local_outcomemap\local\workflow;

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
require_once($CFG->libdir . '/formslib.php');

admin_externalpage_setup('local_outcomemap_courses');
$url = new moodle_url('/local/outcomemap/catalogcourses.php');
$action = optional_param('action', '', PARAM_ALPHA);
$type = optional_param('type', 'course', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'submit' && $id) {
    require_sesskey();
    if ($type === 'membership') {
        program_course_service::submit_for_review($id);
    } else {
        catalog_course_service::submit_for_review($id);
    }
    redirect($url, workflow::submission_success_message());
}

if ($action === 'addmembership') {
    $formurl = new moodle_url($url, ['action' => 'addmembership']);
    $form = new program_course_form($formurl);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        program_course_service::create((array) $data);
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('addprogramcourse', 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'edit' || $action === 'add') {
    $formurl = new moodle_url($url, ['action' => $action]);
    if ($id) {
        $formurl->param('id', $id);
    }
    $form = new catalog_course_form($formurl);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        if ($data->id) {
            catalog_course_service::update((int) $data->id, (array) $data);
        } else {
            catalog_course_service::create((array) $data);
        }
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($id) {
        $form->set_data($DB->get_record('local_outcomemap_course', ['id' => $id], '*', MUST_EXIST));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($id ? 'editcatalogcourse' : 'addcatalogcourse', 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('catalogcourses_heading', 'local_outcomemap'));
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'add']), get_string('addcatalogcourse', 'local_outcomemap'));
$table = new html_table();
$table->caption = get_string('catalogcourses_heading', 'local_outcomemap');
$table->head = [get_string('code', 'local_outcomemap'), get_string('name', 'local_outcomemap'),
    get_string('status', 'local_outcomemap'), get_string('actions', 'local_outcomemap')];
foreach (catalog_course_service::list_all() as $record) {
    $actions = [];
    if ($record->status === workflow::DRAFT) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'edit', 'id' => $record->id]), get_string('edit'));
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'submit', 'id' => $record->id,
            'sesskey' => sesskey()]), workflow::submit_action_label());
    }
    $table->data[] = [s($record->code), format_string($record->name),
        workflow::status_label($record->status), implode(' | ', $actions)];
}
echo html_writer::div(html_writer::table($table), 'table-responsive');
echo $OUTPUT->heading(get_string('programcoursememberships', 'local_outcomemap'), 3);
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'addmembership']), get_string('addprogramcourse', 'local_outcomemap'));
$membershiptable = new html_table();
$membershiptable->caption = get_string('programcoursememberships', 'local_outcomemap');
$membershiptable->head = [get_string('program', 'local_outcomemap'), get_string('catalogcourse', 'local_outcomemap'),
    get_string('effectivefrom', 'local_outcomemap'), get_string('effectiveto', 'local_outcomemap'),
    get_string('status', 'local_outcomemap'), get_string('actions', 'local_outcomemap')];
foreach (program_course_service::list_all() as $record) {
    $actions = [];
    if ($record->status === workflow::DRAFT) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'submit', 'type' => 'membership',
            'id' => $record->id, 'sesskey' => sesskey()]), workflow::submit_action_label());
    }
    $membershiptable->data[] = [s($record->programcode), s($record->coursecode), userdate($record->effectivefrom),
        $record->effectiveto ? userdate($record->effectiveto) : get_string('none', 'local_outcomemap'),
        workflow::status_label($record->status), implode(' | ', $actions)];
}
echo html_writer::div(html_writer::table($membershiptable), 'table-responsive');
echo $OUTPUT->footer();
