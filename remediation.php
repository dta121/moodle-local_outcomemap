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
 * Outcome remediation management page.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\form\remediation_form;
use local_outcomemap\local\feature;
use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\service\remediation_service;
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
// The navigation entry is withdrawn when remediation is off, so reaching this
// page means a direct URL.
feature::require_enabled(feature::remediation_enabled(), 'remediationdisabled');
$PAGE->set_context($context);
$PAGE->set_course($course);
$url = new moodle_url('/local/outcomemap/remediation.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('remediation_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

$canmanage = has_capability('local/outcomemap:mapcourse', $context)
    && has_capability('moodle/course:update', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
if ($action === 'submit' && $id) {
    if (!$canmanage) {
        require_capability('local/outcomemap:mapcourse', $context);
        require_capability('moodle/course:update', $context);
    }
    require_sesskey();
    remediation_service::submit_for_review($id);
    redirect($url, workflow::submission_success_message());
}
if (in_array($action, ['add', 'edit', 'newversion'], true)) {
    if (!$canmanage) {
        require_capability('local/outcomemap:mapcourse', $context);
        require_capability('moodle/course:update', $context);
    }
    $options = content_mapping_service::editor_options($courseid);
    $options['bands'] = remediation_service::band_options_for_course($courseid);
    if (!$options['instances']) {
        redirect(
            $url,
            get_string('nocourseinstance', 'local_outcomemap'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    $form = new remediation_form(new moodle_url($url, ['action' => $action, 'id' => $id]), ['options' => $options]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $payload = (array) $data;
        if ($data->targettype === remediation_service::TARGET_MODULE) {
            $payload['targetid'] = (int) $data->cmid;
        } else if ($data->targettype === remediation_service::TARGET_SECTION) {
            $payload['targetid'] = (int) $data->sectionid;
        } else {
            $payload['targetid'] = null;
        }
        if ($action === 'newversion') {
            remediation_service::create_version($id, $payload);
        } else if ($action === 'edit') {
            remediation_service::update_draft($id, $payload);
        } else {
            remediation_service::create($payload);
        }
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($id) {
        $record = remediation_service::get($id, $courseid);
        if ($record->targettype === remediation_service::TARGET_MODULE) {
            $record->cmid = $record->targetid;
        } else if ($record->targettype === remediation_service::TARGET_SECTION) {
            $record->sectionid = $record->targetid;
        }
        if ($action === 'newversion') {
            $record->effectivefrom = time();
            $record->effectiveto = null;
        }
        $form->set_data($record);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($action === 'newversion' ? 'newremediationversion' :
        ($action === 'edit' ? 'editremediation' : 'addremediation'), 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('remediation_heading', 'local_outcomemap'));
if ($canmanage) {
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'add']),
        get_string('addremediation', 'local_outcomemap')
    );
}
$table = new html_table();
$table->caption = get_string('remediation_heading', 'local_outcomemap');
$table->head = [get_string('outcomeversion', 'local_outcomemap'), get_string('title', 'local_outcomemap'),
    get_string('performanceband', 'local_outcomemap'), get_string('remediationpurpose', 'local_outcomemap'),
    get_string('target', 'local_outcomemap'), get_string('priority', 'local_outcomemap'),
    get_string('displayorder', 'local_outcomemap'), get_string('status', 'local_outcomemap'),
    get_string('actions', 'local_outcomemap')];
$modinfo = get_fast_modinfo($courseid);
foreach (remediation_service::list_for_course($courseid) as $record) {
    if ($record->targettype === remediation_service::TARGET_MODULE) {
        $target = $modinfo->get_cm($record->targetid)->get_formatted_name();
    } else if ($record->targettype === remediation_service::TARGET_SECTION) {
        $section = $modinfo->get_section_info_by_id($record->targetid, MUST_EXIST);
        $target = get_section_name($courseid, $section->section);
    } else {
        $externalurl = clean_param((string) $record->externalurl, PARAM_URL);
        $scheme = strtolower((string) parse_url($externalurl, PHP_URL_SCHEME));
        $host = (string) parse_url($externalurl, PHP_URL_HOST);
        $target = $externalurl !== '' && $host !== '' && in_array($scheme, ['http', 'https'], true)
            ? html_writer::link(new moodle_url($externalurl), s($externalurl))
            : s((string) $record->externalurl);
    }
    $actions = [];
    if ($canmanage && $record->status === workflow::DRAFT) {
        $actions[] = html_writer::link(
            new moodle_url($url, ['action' => 'edit', 'id' => $record->id]),
            get_string('edit')
        );
        $actions[] = $OUTPUT->single_button(new moodle_url($url, [
            'action' => 'submit', 'id' => $record->id,
        ]), workflow::submit_action_label(), 'post');
    } else if ($canmanage && $record->status === workflow::APPROVED) {
        $actions[] = html_writer::link(
            new moodle_url($url, ['action' => 'newversion', 'id' => $record->id]),
            get_string('newremediationversion', 'local_outcomemap')
        );
    }
    $table->data[] = [
        s($record->frameworkcode . '.' . $record->outcomecode . ' v' . $record->outcomeversion),
        format_string($record->title),
        $record->bandid === null ? get_string('anyperformanceband', 'local_outcomemap')
            : format_string($record->bandname) . ' (' . s($record->bandcode) . ')',
        get_string('remediationpurpose_' . $record->purpose, 'local_outcomemap'),
        $target,
        (int) $record->priority,
        (int) $record->sortorder,
        workflow::status_label($record->status),
        html_writer::div(implode(' ', $actions), 'd-flex flex-wrap align-items-center gap-2'),
    ];
}
echo html_writer::div(html_writer::table($table), 'table-responsive');
echo $OUTPUT->footer();
