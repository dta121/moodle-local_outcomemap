<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Accreditation snapshot management and independent freeze workflow.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\form\snapshot_form;
use local_outcomemap\local\service\snapshot_service;
use local_outcomemap\local\workflow;

$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

/**
 * Build snapshot form selectors without per-row lookups.
 *
 * @return array
 */
function local_outcomemap_snapshot_options(): array {
    global $DB;
    $programs = [];
    foreach ($DB->get_records('local_outcomemap_program', ['status' => workflow::APPROVED], 'code, name') as $program) {
        $programs[(int) $program->id] = $program->code . ' — ' . format_string($program->name);
    }
    $cohorts = [];
    foreach ($DB->get_records('cohort', null, 'name, idnumber') as $cohort) {
        $label = format_string($cohort->name);
        if ($cohort->idnumber !== '') {
            $label .= ' [' . s($cohort->idnumber) . ']';
        }
        $cohorts[(int) $cohort->id] = $label;
    }
    return ['programs' => $programs, 'cohorts' => $cohorts];
}

admin_externalpage_setup('local_outcomemap_snapshots');
$context = context_system::instance();
require_capability('local/outcomemap:managesnapshots', $context);
$url = new moodle_url('/local/outcomemap/snapshots.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirmed = optional_param('confirm', 0, PARAM_BOOL);

if ($action === 'freeze' && $id) {
    if (!$confirmed) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmfreezesnapshot', 'local_outcomemap'),
            new moodle_url($url, [
                'action' => 'freeze',
                'id' => $id,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            $url
        );
        echo $OUTPUT->footer();
        exit;
    }
    require_sesskey();
    snapshot_service::freeze($id);
    redirect($url, get_string('snapshotfrozen', 'local_outcomemap'));
}

if (in_array($action, ['add', 'correct'], true)) {
    $previous = $action === 'correct' && $id ? snapshot_service::get($id) : null;
    $formurl = new moodle_url($url, ['action' => $action, 'id' => $id]);
    $form = new snapshot_form($formurl, [
        'options' => local_outcomemap_snapshot_options(),
        'iscorrection' => $previous !== null,
    ]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        snapshot_service::create_draft([
            'programid' => (int) $data->programid,
            'periodcode' => $data->periodcode,
            'cohortid' => empty($data->cohortid) ? null : (int) $data->cohortid,
            'notes' => $data->notes ?? null,
            'previousid' => empty($data->previousid) ? null : (int) $data->previousid,
            'correctionreason' => $data->correctionreason ?? null,
        ]);
        redirect($url, get_string('snapshotcreated', 'local_outcomemap'));
    }
    if ($previous !== null) {
        $form->set_data((object) [
            'previousid' => (int) $previous->id,
            'programid' => (int) $previous->programid,
            'periodcode' => (string) $previous->periodcode,
            'cohortid' => $previous->cohortid,
            'notes' => $previous->notes,
        ]);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($previous === null ? 'createsnapshot' : 'correctsnapshot',
        'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'view' && $id) {
    $snapshot = snapshot_service::get($id);
    $items = snapshot_service::items($id);
    snapshot_service::verify($snapshot, $items);
    $counts = [];
    foreach ($items as $item) {
        if (!isset($counts[$item->itemtype])) {
            $counts[$item->itemtype] = ['total' => 0, 'suppressed' => 0];
        }
        $counts[$item->itemtype]['total']++;
        $counts[$item->itemtype]['suppressed'] += (int) $item->suppressed;
    }
    ksort($counts, SORT_STRING);

    $snapshotheading = get_string('snapshot', 'local_outcomemap') . ' '
        . s($snapshot->snapshotuuid) . ' v' . (int) $snapshot->version;
    echo $OUTPUT->header();
    echo $OUTPUT->heading($snapshotheading);
    $details = new html_table();
    $details->caption = $snapshotheading;
    $details->attributes['aria-label'] = get_string('snapshot', 'local_outcomemap');
    $details->data = [
        [get_string('program', 'local_outcomemap'), (int) $snapshot->programid],
        [get_string('periodcode', 'local_outcomemap'), s($snapshot->periodcode)],
        [get_string('status', 'local_outcomemap'),
            get_string('snapshotstatus_' . $snapshot->status, 'local_outcomemap')],
        [get_string('populationat', 'local_outcomemap'), userdate($snapshot->populationat)],
        [get_string('populationsource', 'local_outcomemap'),
            get_string('population_' . $snapshot->populationsource, 'local_outcomemap')],
        [get_string('populationcount', 'local_outcomemap'), (int) $snapshot->populationcount],
        [get_string('suppressionthreshold', 'local_outcomemap'), (int) $snapshot->suppressionthreshold],
        [get_string('retentionbasis', 'local_outcomemap'),
            get_string('retention_' . $snapshot->retentionbasis, 'local_outcomemap')],
        [get_string('payloadhash', 'local_outcomemap'), s($snapshot->payloadhash)],
        [get_string('manifesthash', 'local_outcomemap'), s($snapshot->manifesthash ?? get_string('none'))],
        [get_string('createdby', 'local_outcomemap'), (int) $snapshot->createdby],
        [get_string('approvedby', 'local_outcomemap'),
            $snapshot->approvedby === null ? get_string('none') : (int) $snapshot->approvedby],
    ];
    echo html_writer::div(html_writer::table($details), 'table-responsive');
    $itemtable = new html_table();
    $itemtable->caption = get_string('snapshotitems_caption', 'local_outcomemap');
    $itemtable->head = [
        get_string('objecttype', 'local_outcomemap'),
        get_string('itemcount', 'local_outcomemap'),
        get_string('exportsuppressed', 'local_outcomemap'),
    ];
    foreach ($counts as $type => $count) {
        $itemtable->data[] = [s($type), $count['total'], $count['suppressed']];
    }
    echo html_writer::div(html_writer::table($itemtable), 'table-responsive');
    if ($snapshot->status === snapshot_service::STATUS_FROZEN
            && has_capability('local/outcomemap:exportaccreditation', $context)) {
        echo $OUTPUT->single_button(new moodle_url('/local/outcomemap/export.php', [
            'id' => $snapshot->id,
            'format' => 'json',
        ]), get_string('exportpackagejson', 'local_outcomemap'));
        echo $OUTPUT->single_button(new moodle_url('/local/outcomemap/export.php', [
            'id' => $snapshot->id,
            'format' => 'csv',
        ]), get_string('exportsummarycsv', 'local_outcomemap'));
        if (has_capability('local/outcomemap:viewallresults', $context)) {
            echo $OUTPUT->single_button(new moodle_url('/local/outcomemap/export.php', [
                'id' => $snapshot->id,
                'format' => 'json',
                'evidence' => 1,
            ]), get_string('exportevidencedetail', 'local_outcomemap'));
        }
    }
    echo $OUTPUT->single_button($url, get_string('back'));
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('snapshots_heading', 'local_outcomemap'));
echo html_writer::tag('p', get_string('snapshots_intro', 'local_outcomemap'));
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'add']),
    get_string('createsnapshot', 'local_outcomemap'));
$table = new html_table();
$table->caption = get_string('snapshotlist_caption', 'local_outcomemap');
$table->head = [
    get_string('snapshotuuid', 'local_outcomemap'),
    get_string('version', 'local_outcomemap'),
    get_string('program', 'local_outcomemap'),
    get_string('periodcode', 'local_outcomemap'),
    get_string('status', 'local_outcomemap'),
    get_string('populationcount', 'local_outcomemap'),
    get_string('itemcount', 'local_outcomemap'),
    get_string('timecreated', 'local_outcomemap'),
    get_string('actions', 'local_outcomemap'),
];
foreach (snapshot_service::list_all() as $snapshot) {
    $actions = [html_writer::link(new moodle_url($url, [
        'action' => 'view',
        'id' => $snapshot->id,
    ]), get_string('view'))];
    if ($snapshot->status === snapshot_service::STATUS_DRAFT) {
        $actions[] = html_writer::link(new moodle_url($url, [
            'action' => 'freeze',
            'id' => $snapshot->id,
        ]), get_string('freezesnapshot', 'local_outcomemap'));
    } else if ($snapshot->status === snapshot_service::STATUS_FROZEN) {
        $actions[] = html_writer::link(new moodle_url($url, [
            'action' => 'correct',
            'id' => $snapshot->id,
        ]), get_string('correctsnapshot', 'local_outcomemap'));
    }
    $table->data[] = [
        s($snapshot->snapshotuuid),
        (int) $snapshot->version,
        s($snapshot->programcode) . ' — ' . format_string($snapshot->programname),
        s($snapshot->periodcode),
        get_string('snapshotstatus_' . $snapshot->status, 'local_outcomemap'),
        (int) $snapshot->populationcount,
        (int) $snapshot->itemcount,
        userdate($snapshot->timecreated),
        implode(' | ', $actions),
    ];
}
echo html_writer::div(html_writer::table($table), 'table-responsive');
echo $OUTPUT->footer();
