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
use local_outcomemap\output\snapshot_report;
use local_outcomemap\output\snapshots_page;

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

if ($action === 'delete' && $id) {
    $snapshot = snapshot_service::summary($id);
    if (!$confirmed) {
        // Withdrawal destroys a governed record, so the confirmation names the
        // exact version and the number of immutable rows that go with it.
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmdeletesnapshot', 'local_outcomemap', (object) [
                'program' => s($snapshot->programcode),
                'period' => s($snapshot->periodcode),
                'version' => get_string('snapreport_shortversion', 'local_outcomemap',
                    (int) $snapshot->version),
                'rows' => number_format((int) $snapshot->itemcount),
            ]),
            new moodle_url($url, [
                'action' => 'delete',
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
    snapshot_service::delete($id);
    redirect($url, get_string('snapshotdeleted', 'local_outcomemap'));
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
        // A capture holds every evidence, result, and aggregate row of the
        // reporting period in memory before hashing it, so a real accreditation
        // period needs more than the default request allowance.
        raise_memory_limit(MEMORY_HUGE);
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
    // The report model loads and verifies the capture, so a snapshot whose hashes
    // no longer match cannot render as though it were sound. Grouping and the
    // subject filter only change how the frozen rows are presented, so they are
    // plain GET state rather than anything the record stores.
    $report = new snapshot_report(
        $id,
        optional_param('group', snapshot_report::GROUP_FRAMEWORK, PARAM_ALPHA),
        optional_param('subjects', snapshot_report::SUBJECTS_ALL, PARAM_ALPHA)
    );
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_outcomemap/snapshot_report',
        $report->export_for_template($OUTPUT));
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
$page = new snapshots_page();
echo $OUTPUT->render_from_template('local_outcomemap/snapshots_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
