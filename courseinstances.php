<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\course_instance_form;
use local_outcomemap\local\service\course_instance_service;
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

admin_externalpage_setup('local_outcomemap_courseinstances');
$url = new moodle_url('/local/outcomemap/courseinstances.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'submit' && $id) {
    require_sesskey();
    course_instance_service::submit_for_review($id);
    redirect($url, workflow::submission_success_message());
}
if ($action === 'add') {
    $formurl = new moodle_url($url, ['action' => 'add']);
    $form = new course_instance_form($formurl);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        course_instance_service::create((array) $data);
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('addcourseinstance', 'local_outcomemap'));
    echo html_writer::tag('p', get_string(
        workflow::requires_independent_approval() ? 'courseinstances_intro' : 'courseinstances_intro_finalization',
        'local_outcomemap'
    ));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('courseinstances_heading', 'local_outcomemap'));
echo html_writer::tag('p', get_string(
        workflow::requires_independent_approval() ? 'courseinstances_intro' : 'courseinstances_intro_finalization',
        'local_outcomemap'
    ));
echo html_writer::tag('p', get_string('courseinstances_coursevisibility', 'local_outcomemap'), [
    'class' => 'text-muted',
]);
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'add']), get_string('addcourseinstance', 'local_outcomemap'));
$table = new html_table();
$table->caption = get_string('courseinstances_heading', 'local_outcomemap');
$table->head = [get_string('catalogcourse', 'local_outcomemap'), get_string('moodlecourse', 'local_outcomemap'),
    get_string('periodcode', 'local_outcomemap'), get_string('status', 'local_outcomemap'),
    get_string('confirmed', 'local_outcomemap'), get_string('actions', 'local_outcomemap')];
foreach (course_instance_service::list_all() as $record) {
    $actions = [];
    if ($record->status === workflow::DRAFT) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'submit', 'id' => $record->id,
            'sesskey' => sesskey()]), workflow::submit_action_label());
    }
    $table->data[] = [s($record->catalogcode), format_string($record->moodlename), s($record->periodcode),
        workflow::status_label($record->status), $record->confirmed ? get_string('yes') : get_string('no'),
        implode(' | ', $actions)];
}
echo html_writer::div(html_writer::table($table), 'table-responsive');
echo $OUTPUT->footer();
