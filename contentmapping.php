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
 * Course content mapping management page.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\form\content_mapping_form;
use local_outcomemap\local\service\content_mapping_service;
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
require_once($CFG->libdir . '/formslib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/outcomemap:viewdefinitions', $context);
$PAGE->set_context($context);
$PAGE->set_course($course);
$url = new moodle_url('/local/outcomemap/contentmapping.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('contentmapping_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

$action = optional_param('action', '', PARAM_ALPHA);
$targettype = optional_param('targettype', '', PARAM_ALPHANUMEXT);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'submit' && $id) {
    require_sesskey();
    content_mapping_service::submit_for_review($targettype, $id);
    redirect($url, get_string('submittedforreview', 'local_outcomemap'));
}

if (in_array($action, ['add', 'edit', 'newversion'], true)) {
    $options = content_mapping_service::editor_options($courseid);
    if (!$options['instances']) {
        redirect(
            $url,
            get_string('nocourseinstance', 'local_outcomemap'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    $formurl = new moodle_url($url, ['action' => $action, 'targettype' => $targettype, 'id' => $id]);
    $form = new content_mapping_form($formurl, ['options' => $options]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $targettype = $data->targettype;
        $payload = (array) $data;
        if ($targettype === content_mapping_service::TARGET_MODULE) {
            $payload['cmid'] = (int) $data->cmid;
        } else {
            $payload['sectionid'] = (int) $data->sectionid;
        }
        if ($action === 'newversion') {
            content_mapping_service::create_version($targettype, $id, $payload);
        } else if ($action === 'edit') {
            content_mapping_service::update_draft($targettype, $id, $payload);
        } else if ($targettype === content_mapping_service::TARGET_MODULE) {
            content_mapping_service::create_course_module($payload);
        } else {
            content_mapping_service::create_section($payload);
        }
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($id) {
        $record = content_mapping_service::get($targettype, $id);
        if ($targettype === content_mapping_service::TARGET_MODULE) {
            $record->cmid = $record->cmid;
        } else {
            $record->sectionid = $record->sectionid;
        }
        if ($action === 'newversion') {
            $record->effectivefrom = time();
            $record->effectiveto = null;
        }
        $form->set_data($record);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($action === 'newversion' ? 'newmappingversion' :
        ($action === 'edit' ? 'editcontentmapping' : 'addcontentmapping'), 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('contentmapping_heading', 'local_outcomemap'));
echo $OUTPUT->single_button(
    new moodle_url($url, ['action' => 'add']),
    get_string('addcontentmapping', 'local_outcomemap')
);
$mappings = content_mapping_service::list_for_course($courseid);
$modinfo = get_fast_modinfo($courseid);
$table = new html_table();
$table->head = [get_string('target', 'local_outcomemap'), get_string('outcomeversion', 'local_outcomemap'),
    get_string('mappingrole', 'local_outcomemap'), get_string('weight', 'local_outcomemap'),
    get_string('periodcode', 'local_outcomemap'), get_string('status', 'local_outcomemap'),
    get_string('actions', 'local_outcomemap')];
foreach (
    ['sections' => content_mapping_service::TARGET_SECTION,
        'modules' => content_mapping_service::TARGET_MODULE] as $collection => $type
) {
    foreach ($mappings[$collection] as $record) {
        if ($type === content_mapping_service::TARGET_MODULE) {
            $cm = $modinfo->get_cm($record->cmid);
            $target = $cm->get_formatted_name();
        } else {
            $target = get_section_name($courseid, $record->sectionnumber);
        }
        $actions = [];
        if ($record->status === workflow::DRAFT) {
            $actions[] = html_writer::link(new moodle_url($url, [
                'action' => 'edit', 'targettype' => $type, 'id' => $record->id,
            ]), get_string('edit'));
            $actions[] = html_writer::link(new moodle_url($url, [
                'action' => 'submit', 'targettype' => $type, 'id' => $record->id, 'sesskey' => sesskey(),
            ]), get_string('submitreview', 'local_outcomemap'));
        } else if ($record->status === workflow::APPROVED) {
            $actions[] = html_writer::link(new moodle_url($url, [
                'action' => 'newversion', 'targettype' => $type, 'id' => $record->id,
            ]), get_string('newmappingversion', 'local_outcomemap'));
        }
        $table->data[] = [format_string($target), s($record->frameworkcode . '.' . $record->outcomecode
            . ' v' . $record->outcomeversion), get_string('mappingrole_' . $record->role, 'local_outcomemap'),
            $record->weight === null ? '' : s($record->weight), s($record->periodcode),
            get_string('status_' . $record->status, 'local_outcomemap'), implode(' | ', $actions)];
    }
}
echo html_writer::table($table);
echo $OUTPUT->footer();
