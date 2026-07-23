<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\form\csv_commit_form;
use local_outcomemap\form\csv_import_form;
use local_outcomemap\local\service\foundation_import_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->libdir . '/formslib.php');

admin_externalpage_setup('local_outcomemap_import');
$url = new moodle_url('/local/outcomemap/csvimport.php');
$action = optional_param('action', '', PARAM_ALPHA);
$entity = optional_param('entity', foundation_import_service::PROGRAMS, PARAM_ALPHANUMEXT);

if ($action === 'template') {
    require_capability('local/outcomemap:manageframeworks', context_system::instance());
    $content = foundation_import_service::template($entity);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="outcomemap-' . $entity . '.csv"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

$commitrequested = optional_param('commit', 0, PARAM_BOOL);
if ($commitrequested) {
    $commitform = new csv_commit_form($url);
    if ($commitform->is_cancelled()) {
        $submitted = $commitform->get_submitted_data();
        if (!empty($submitted->importid)) {
            foundation_import_service::cleanup((int) $submitted->importid);
        }
        redirect($url);
    }
    if ($data = $commitform->get_data()) {
        try {
            $count = foundation_import_service::commit((int) $data->importid, $data->entity, $data->previewhash);
        } finally {
            foundation_import_service::cleanup((int) $data->importid);
        }
        redirect($url, get_string('importcommitted', 'local_outcomemap', $count));
    }
}

$form = new csv_import_form($url);
$preview = null;
$importid = 0;
if ($form->is_cancelled()) {
    redirect($url);
}
if ($data = $form->get_data()) {
    $importid = foundation_import_service::load(
        $form->get_file_content('csvfile'),
        $data->encoding,
        $data->delimiter,
    );
    $entity = $data->entity;
    $preview = foundation_import_service::preview($importid, $entity);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('csvimport_heading', 'local_outcomemap'));
if ($preview === null) {
    $form->display();
    echo $OUTPUT->heading(get_string('downloadtemplate', 'local_outcomemap'), 3);
    $links = [];
    foreach (foundation_import_service::ENTITIES as $templateentity) {
        $links[] = html_writer::link(new moodle_url($url, ['action' => 'template', 'entity' => $templateentity]),
            get_string('entity_' . $templateentity, 'local_outcomemap'));
    }
    echo html_writer::alist($links);
} else {
    echo $OUTPUT->heading(get_string('importpreview', 'local_outcomemap'), 3);
    $table = new html_table();
    $table->head = array_merge([get_string('rownumber', 'local_outcomemap')],
        foundation_import_service::HEADERS[$entity], [get_string('validation', 'local_outcomemap')]);
    foreach ($preview->rows as $row) {
        $cells = [(int) $row->number];
        foreach (foundation_import_service::HEADERS[$entity] as $header) {
            $cells[] = s($row->data[$header]);
        }
        $cells[] = $row->errors ? s(implode('; ', $row->errors)) : get_string('valid', 'local_outcomemap');
        $table->data[] = $cells;
    }
    echo html_writer::table($table);
    if ($preview->valid) {
        echo $OUTPUT->notification(get_string('importvalid', 'local_outcomemap'), 'notifysuccess');
        $commitform = new csv_commit_form($url);
        $commitform->set_data([
            'importid' => $importid,
            'entity' => $entity,
            'previewhash' => $preview->hash,
            'commit' => 1,
        ]);
        $commitform->display();
    } else {
        foundation_import_service::cleanup($importid);
        echo $OUTPUT->notification(get_string('importinvalid', 'local_outcomemap'), 'notifyproblem');
        echo $OUTPUT->continue_button($url);
    }
}
echo $OUTPUT->footer();
