<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\relation_form;
use local_outcomemap\local\service\relation_service;
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

admin_externalpage_setup('local_outcomemap_relations');
$url = new moodle_url('/local/outcomemap/relations.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'submit' && $id) {
    require_sesskey();
    relation_service::submit_for_review($id);
    redirect($url, get_string('submittedforreview', 'local_outcomemap'));
}
if (in_array($action, ['add', 'edit', 'newversion'], true)) {
    $form = new relation_form($url);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        if ($action === 'newversion') {
            relation_service::create_version($id, (array) $data);
        } else if ($data->id) {
            relation_service::update_draft((int) $data->id, (array) $data);
        } else {
            relation_service::create((array) $data);
        }
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($id) {
        $record = $DB->get_record('local_outcomemap_rel', ['id' => $id], '*', MUST_EXIST);
        if ($action === 'newversion') {
            $record->id = 0;
            $record->effectivefrom = time();
            $record->effectiveto = null;
        }
        $form->set_data($record);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($action === 'newversion' ? 'newrelationversion' :
        ($id ? 'editrelation' : 'addrelation'), 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('relations_heading', 'local_outcomemap'));
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'add']), get_string('addrelation', 'local_outcomemap'));
$table = new html_table();
$table->head = [get_string('sourceoutcome', 'local_outcomemap'), get_string('relationtype', 'local_outcomemap'),
    get_string('targetoutcome', 'local_outcomemap'), get_string('weight', 'local_outcomemap'),
    get_string('version', 'local_outcomemap'), get_string('status', 'local_outcomemap'), get_string('actions', 'local_outcomemap')];
foreach (relation_service::list_all() as $record) {
    $actions = [];
    if ($record->status === workflow::DRAFT) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'edit', 'id' => $record->id]), get_string('edit'));
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'submit', 'id' => $record->id,
            'sesskey' => sesskey()]), get_string('submitreview', 'local_outcomemap'));
    }
    if ($record->status === workflow::APPROVED) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'newversion', 'id' => $record->id]),
            get_string('newrelationversion', 'local_outcomemap'));
    }
    $table->data[] = [s($record->sourceframework . '.' . $record->sourcecode),
        get_string('relation_' . $record->type, 'local_outcomemap'), s($record->targetframework . '.' . $record->targetcode),
        $record->weight === null ? '' : s($record->weight), (int) $record->version,
        get_string('status_' . $record->status, 'local_outcomemap'), implode(' | ', $actions)];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
