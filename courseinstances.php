<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\course_instance_form;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\workflow;
use local_outcomemap\output\course_instances_page;

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

admin_externalpage_setup('local_outcomemap_courseinstances');
$url = new moodle_url('/local/outcomemap/courseinstances.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
// The catalog courses page links here for one course, so the list opens filtered
// to it rather than making the reader retype a code they just clicked.
$catalogcode = trim(optional_param('catalog', '', PARAM_TEXT));

if ($action === 'submit' && $id) {
    require_sesskey();
    course_instance_service::submit_for_review($id);
    redirect($url, workflow::submission_success_message());
}
if ($action === 'delete' && $id) {
    require_sesskey();
    $record = course_instance_service::get($id);
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        course_instance_service::delete($id);
        redirect($url, get_string('courseinstanceremoved', 'local_outcomemap'));
    }
    // Deleting is destructive, so it keeps its own confirmation step. This is
    // not the governance step that adding no longer needs.
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('courseinstancedeleteconfirm', 'local_outcomemap', (object) [
            'catalog' => s($record->catalogcode ?? ''),
            'period' => s($record->periodcode),
        ]),
        new moodle_url($url, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    exit;
}
if ($action === 'add') {
    $formurl = new moodle_url($url, ['action' => 'add']);
    $form = new course_instance_form($formurl);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        course_instance_service::create_confirmed((array) $data);
        redirect($url, get_string(
            workflow::requires_independent_approval() ? 'saved' : 'courseinstanceready',
            'local_outcomemap'
        ));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('addcourseinstance', 'local_outcomemap'));
    echo html_writer::div(get_string(
        workflow::requires_independent_approval() ? 'courseinstances_intro' : 'courseinstances_intro_finalization',
        'local_outcomemap'
    ), 'lom-page-subtitle');
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
$page = new course_instances_page($catalogcode);
echo $OUTPUT->render_from_template('local_outcomemap/course_instances_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
