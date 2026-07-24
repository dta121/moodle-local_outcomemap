<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\relation_form;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\output\relations_page;

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
$sourceitemid = optional_param('sourceitemid', 0, PARAM_INT);
$targetitemid = optional_param('targetitemid', 0, PARAM_INT);
$relationtype = optional_param('relationtype', '', PARAM_ALPHANUMEXT);

if ($action === 'submit' && $id) {
    require_sesskey();
    relation_service::submit_for_review($id);
    redirect($url, get_string('submittedforreview', 'local_outcomemap'));
}
if ($action === 'exportcsv') {
    $page = new relations_page();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="outcome-relations.csv"');
    $stream = fopen('php://output', 'w');
    fwrite($stream, "\xEF\xBB\xBF");
    foreach ($page->csv_rows() as $row) {
        fputcsv($stream, $row, ',', '"', '');
    }
    fclose($stream);
    exit;
}

if (in_array($action, ['add', 'edit', 'newversion'], true)) {
    $formurl = new moodle_url($url, ['action' => $action]);
    if ($id) {
        $formurl->param('id', $id);
    }
    $form = new relation_form($formurl);
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
    } else if ($action === 'add' && !$form->is_submitted()
            && $sourceitemid && $targetitemid
            && in_array($relationtype, relation_service::TYPES, true)) {
        $form->set_data((object) [
            'sourceitemid' => $sourceitemid,
            'targetitemid' => $targetitemid,
            'type' => $relationtype,
            'effectivefrom' => time(),
        ]);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($action === 'newversion' ? 'newrelationversion' :
        ($id ? 'editrelation' : 'addrelation'), 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
$page = new relations_page();
echo $OUTPUT->render_from_template('local_outcomemap/relations_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
