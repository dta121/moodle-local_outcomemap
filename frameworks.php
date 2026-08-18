<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Learning Outcome Mapping plugin component.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\form\framework_form;
use local_outcomemap\form\outcome_form;
use local_outcomemap\local\csv_safety;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;
use local_outcomemap\output\outcomes_hierarchy;

// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Portable bootstrap path.
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

admin_externalpage_setup('local_outcomemap_frameworks');
$url = new moodle_url('/local/outcomemap/frameworks.php');
$action = optional_param('action', '', PARAM_ALPHA);
$type = optional_param('type', 'framework', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$view = optional_param('view', 'program', PARAM_ALPHA);

// A framework reached from the program or catalog course it will belong to carries
// that owner with it, so the reader is not asked to re-state what the link already
// said. An owner that does not resolve is dropped rather than reported: the full
// form it falls back to is the one that lets any owner be chosen anyway.
$ownercontext = null;
$ownertype = optional_param('ownertype', '', PARAM_ALPHAEXT);
$ownerid = optional_param('ownerid', 0, PARAM_INT);
$ownertables = [
    framework_service::OWNER_PROGRAM => 'local_outcomemap_program',
    framework_service::OWNER_COURSE => 'local_outcomemap_course',
];
if ($ownerid > 0 && isset($ownertables[$ownertype])) {
    $owner = $DB->get_record($ownertables[$ownertype], ['id' => $ownerid], 'id, code, name');
    if ($owner !== false) {
        $ownercontext = (object) [
            'ownertype' => $ownertype,
            'ownerid' => (int) $owner->id,
            'code' => $owner->code,
            'name' => $owner->name,
        ];
    }
}

if ($action === 'submit' && $id) {
    require_sesskey();
    if ($type === 'outcome') {
        outcome_service::submit_for_review($id);
    } else {
        framework_service::submit_for_review($id);
    }
    redirect($url, workflow::submission_success_message());
}

if ($action === 'approveversion' && $id) {
    require_sesskey();
    try {
        outcome_service::approve($id);
        redirect($url, get_string('approved', 'local_outcomemap'));
    } catch (validation_exception $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'savemap') {
    require_sesskey();
    require_capability('local/outcomemap:manageframeworks', context_system::instance());
    $itemid = required_param('itemid', PARAM_INT);
    $targets = optional_param_array('targets', [], PARAM_INT);
    $existing = outcomes_hierarchy::current_targets($itemid);
    try {
        $created = 0;
        foreach ($targets as $targetid) {
            if (in_array((int) $targetid, $existing, true)) {
                continue;
            }
            $relationid = relation_service::create([
                'sourceitemid' => $itemid,
                'targetitemid' => (int) $targetid,
                'type' => relation_service::ALIGNS_TO,
                'effectivefrom' => time(),
                'notes' => get_string('hier_maprelationnote', 'local_outcomemap'),
            ]);
            relation_service::submit_for_review($relationid);
            $created++;
        }
        redirect($url, $created
            ? get_string(
                workflow::requires_independent_approval() ? 'hier_mapsaved' : 'hier_mapsaved_finalized',
                'local_outcomemap',
                $created
            )
            : get_string('hier_mapnone', 'local_outcomemap'));
    } catch (validation_exception $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'savenewversion') {
    require_sesskey();
    require_capability('local/outcomemap:manageframeworks', context_system::instance());
    $itemid = required_param('itemid', PARAM_INT);
    $statement = required_param('statement', PARAM_TEXT);
    $latest = $DB->get_record_sql(
        'SELECT v.* FROM {local_outcomemap_itemver} v
          WHERE v.itemid = :itemid
            AND v.version = (SELECT MAX(v2.version) FROM {local_outcomemap_itemver} v2 WHERE v2.itemid = v.itemid)',
        ['itemid' => $itemid],
        MUST_EXIST
    );
    try {
        $versionid = outcome_service::create_version($itemid, [
            'statement' => $statement,
            'shortstatement' => $latest->shortstatement,
            'bloomlevel' => $latest->bloomlevel,
            'effectivefrom' => time(),
            'changereason' => get_string('hier_editreason', 'local_outcomemap'),
        ]);
        outcome_service::submit_for_review($versionid);
        redirect($url, get_string(
            workflow::requires_independent_approval() ? 'hier_versionsaved' : 'hier_versionsaved_finalized',
            'local_outcomemap'
        ));
    } catch (validation_exception $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'exportcsv') {
    $hierarchy = new outcomes_hierarchy();
    $rows = $hierarchy->csv_rows();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="outcomes-hierarchy.csv"');
    $stream = fopen('php://output', 'w');
    fwrite($stream, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        // Outcome statements are staff-entered free text, so every cell is
        // neutralized against spreadsheet formula execution before download.
        fputcsv($stream, csv_safety::row($row), ',', '"', '');
    }
    fclose($stream);
    exit;
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
                  WHERE v.id = :id',
                ['id' => $id],
                MUST_EXIST
            );
        } else {
            $record = $DB->get_record_sql(
                'SELECT i.id AS itemid, i.frameworkid, i.code
                   FROM {local_outcomemap_item} i WHERE i.id = :id',
                ['id' => $id],
                MUST_EXIST
            );
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
    $existing = $id ? $DB->get_record('local_outcomemap_fw', ['id' => $id], '*', MUST_EXIST) : null;
    $form = new framework_form($url, [
        'identitylocked' => $existing !== null && $existing->status === workflow::APPROVED,
        'owner' => $existing === null ? $ownercontext : null,
    ]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    $rejected = null;
    if ($data = $form->get_data()) {
        // A rejected framework is a correctable mistake, so it is reported the way
        // every other action on this page reports one rather than as an uncaught
        // exception page that loses what the reader had typed.
        try {
            if ($data->id) {
                framework_service::update((int) $data->id, (array) $data);
            } else {
                framework_service::create((array) $data);
            }
            // The two hierarchy views are different lenses: a catalog-course framework
            // is only ever listed under its course. Redirecting to the default view
            // would show the reader a page their new framework is not on.
            redirect(new moodle_url($url, [
                'view' => $data->ownertype === framework_service::OWNER_COURSE ? 'course' : 'program',
            ]), get_string('saved', 'local_outcomemap'));
        } catch (validation_exception $e) {
            \core\notification::error($e->getMessage());
            $rejected = $data;
        }
    }
    // The rejected submission wins over the stored record: it is what the reader
    // is still correcting.
    if ($rejected !== null) {
        $form->set_data($rejected);
    } else if ($existing !== null) {
        $form->set_data($existing);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($id ? 'editframework' : 'addframework', 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
$hierarchy = new outcomes_hierarchy($view);
echo $OUTPUT->render_from_template(
    'local_outcomemap/outcomes_hierarchy',
    $hierarchy->export_for_template($OUTPUT)
);

echo $OUTPUT->footer();
