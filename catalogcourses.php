<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\catalog_course_form;
use local_outcomemap\form\program_course_form;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\program_course_service;
use local_outcomemap\local\workflow;
use local_outcomemap\output\catalog_courses_page;

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
    // A membership is added from one catalog course's card, so that course is
    // preselected rather than searched for again in the form.
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $formurl = new moodle_url($url, ['action' => 'addmembership']);
    if ($courseid) {
        $formurl->param('courseid', $courseid);
    }
    $form = new program_course_form($formurl);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        program_course_service::create((array) $data);
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($courseid && $DB->record_exists('local_outcomemap_course', ['id' => $courseid])) {
        $form->set_data(['courseid' => $courseid]);
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
$page = new catalog_courses_page();
echo $OUTPUT->render_from_template('local_outcomemap/catalog_courses_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
