<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\program_form;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\workflow;
use local_outcomemap\output\programs_page;

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

admin_externalpage_setup('local_outcomemap_programs');
$url = new moodle_url('/local/outcomemap/programs.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'submit' && $id) {
    require_sesskey();
    program_service::submit_for_review($id);
    redirect($url, workflow::submission_success_message());
}

if ($action === 'edit' || $action === 'add') {
    $formurl = new moodle_url($url, ['action' => $action]);
    if ($id) {
        $formurl->param('id', $id);
    }
    $form = new program_form($formurl);
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
    echo html_writer::div(get_string('programform_subtitle', 'local_outcomemap'), 'lom-page-subtitle');
    echo html_writer::start_div('lom-program-form');
    $form->display();
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
$page = new programs_page();
echo $OUTPUT->render_from_template('local_outcomemap/programs_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
