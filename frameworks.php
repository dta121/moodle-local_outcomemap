<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\framework_form;
use local_outcomemap\form\outcome_form;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\workflow;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

admin_externalpage_setup('local_outcomemap_frameworks');
$url = new moodle_url('/local/outcomemap/frameworks.php');
$action = optional_param('action', '', PARAM_ALPHA);
$type = optional_param('type', 'framework', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'submit' && $id) {
    require_sesskey();
    if ($type === 'outcome') {
        outcome_service::submit_for_review($id);
    } else {
        framework_service::submit_for_review($id);
    }
    redirect($url, get_string('submittedforreview', 'local_outcomemap'));
}

if (in_array($action, ['addoutcome', 'editoutcome', 'newversion'], true)) {
    $form = new outcome_form($url);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        if ($data->versionid) {
            outcome_service::update_draft((int) $data->versionid, (array) $data);
        } else if ($data->itemid) {
            outcome_service::create_version((int) $data->itemid, (array) $data);
        } else {
            outcome_service::create((array) $data);
        }
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($id) {
        if ($action === 'editoutcome') {
            $record = $DB->get_record_sql(
                'SELECT v.*, v.id AS versionid, i.id AS itemid, i.frameworkid, i.code
                   FROM {local_outcomemap_itemver} v JOIN {local_outcomemap_item} i ON i.id = v.itemid
                  WHERE v.id = :id', ['id' => $id], MUST_EXIST);
        } else {
            $record = $DB->get_record_sql(
                'SELECT i.id AS itemid, i.frameworkid, i.code
                   FROM {local_outcomemap_item} i WHERE i.id = :id', ['id' => $id], MUST_EXIST);
            $record->effectivefrom = time();
        }
        $form->set_data($record);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($action === 'newversion' ? 'newoutcomeversion' :
        ($id ? 'editoutcome' : 'addoutcome'), 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'addframework' || $action === 'editframework') {
    $form = new framework_form($url);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        if ($data->id) {
            framework_service::update((int) $data->id, (array) $data);
        } else {
            framework_service::create((array) $data);
        }
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($id) {
        $form->set_data($DB->get_record('local_outcomemap_fw', ['id' => $id], '*', MUST_EXIST));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($id ? 'editframework' : 'addframework', 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('frameworks_heading', 'local_outcomemap'));
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'addframework']), get_string('addframework', 'local_outcomemap'));
$fwtable = new html_table();
$fwtable->head = [get_string('code', 'local_outcomemap'), get_string('name', 'local_outcomemap'),
    get_string('ownertype', 'local_outcomemap'), get_string('status', 'local_outcomemap'), get_string('actions', 'local_outcomemap')];
foreach (framework_service::list_all() as $record) {
    $actions = [];
    if ($record->status === workflow::DRAFT) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'editframework', 'id' => $record->id]), get_string('edit'));
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'submit', 'type' => 'framework',
            'id' => $record->id, 'sesskey' => sesskey()]), get_string('submitreview', 'local_outcomemap'));
    }
    $fwtable->data[] = [s($record->code), format_string($record->name),
        get_string('owner_' . $record->ownertype, 'local_outcomemap'),
        get_string('status_' . $record->status, 'local_outcomemap'), implode(' | ', $actions)];
}
echo html_writer::table($fwtable);
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'addoutcome']), get_string('addoutcome', 'local_outcomemap'));
$outcometable = new html_table();
$outcometable->head = [get_string('framework', 'local_outcomemap'), get_string('code', 'local_outcomemap'),
    get_string('version', 'local_outcomemap'), get_string('statement', 'local_outcomemap'),
    get_string('status', 'local_outcomemap'), get_string('actions', 'local_outcomemap')];
foreach (outcome_service::list_all() as $record) {
    $actions = [];
    if ($record->versionstatus === workflow::DRAFT) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'editoutcome', 'id' => $record->id]), get_string('edit'));
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'submit', 'type' => 'outcome',
            'id' => $record->id, 'sesskey' => sesskey()]), get_string('submitreview', 'local_outcomemap'));
    }
    if ($record->versionstatus === workflow::APPROVED) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'newversion', 'id' => $record->itemid]),
            get_string('newoutcomeversion', 'local_outcomemap'));
    }
    $outcometable->data[] = [s($record->frameworkcode), s($record->code), (int) $record->version,
        s($record->statement), get_string('status_' . $record->versionstatus, 'local_outcomemap'), implode(' | ', $actions)];
}
echo html_writer::table($outcometable);
echo $OUTPUT->footer();
