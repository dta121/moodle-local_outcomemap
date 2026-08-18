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

use local_outcomemap\form\relation_form;
use local_outcomemap\local\csv_safety;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\local\workflow;
use local_outcomemap\output\outcomes_hierarchy;
use local_outcomemap\output\relations_page;

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
    redirect($url, workflow::submission_success_message());
}
if ($action === 'exportcsv') {
    $page = new relations_page();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="outcome-relations.csv"');
    $stream = fopen('php://output', 'w');
    fwrite($stream, "\xEF\xBB\xBF");
    foreach ($page->csv_rows() as $row) {
        // Outcome statements are staff-entered free text, so every cell is
        // neutralized against spreadsheet formula execution before download.
        fputcsv($stream, csv_safety::row($row), ',', '"', '');
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
    } else if (
        $action === 'add' && !$form->is_submitted()
            && $sourceitemid && $targetitemid
            && in_array($relationtype, relation_service::TYPES, true)
    ) {
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

// Alignments are read on the Outcomes and alignment page, whose matrix view is
// this list. This script keeps the governed add, edit, new-version, submit, and
// CSV actions that the matrix links to.
redirect(new moodle_url('/local/outcomemap/frameworks.php', [
    'view' => outcomes_hierarchy::VIEW_MATRIX,
]));
