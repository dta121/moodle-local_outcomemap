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

use local_outcomemap\form\course_instance_form;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\workflow;
use local_outcomemap\output\course_instances_page;

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

admin_externalpage_setup('local_outcomemap_courseinstances');
$url = new moodle_url('/local/outcomemap/courseinstances.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
// The catalog courses page links here for one course, so the list opens filtered
// to it rather than making the reader retype a code they just clicked.
$catalogcode = trim(optional_param('catalog', '', PARAM_TEXT));
// The curriculum page is where associating a Moodle course is usually asked for, and
// the reader is in the middle of reading one program there. The program is carried as
// its id and the return URL is rebuilt here rather than passed in, so the parameter
// cannot send anyone anywhere but back to the page they came from.
$returnprogram = optional_param('returnprogram', 0, PARAM_INT);
$returnurl = $returnprogram > 0
    ? new moodle_url('/local/outcomemap/curriculum.php', ['program' => $returnprogram])
    : $url;

if ($action === 'submit' && $id) {
    require_sesskey();
    course_instance_service::submit_for_review($id);
    redirect($url, workflow::submission_success_message());
}
if ($action === 'delete' && $id) {
    require_sesskey();
    $record = course_instance_service::get($id);
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        course_instance_service::delete($id);
        redirect($url, get_string('courseinstanceremoved', 'local_outcomemap'));
    }
    // Deleting is destructive, so it keeps its own confirmation step. This is
    // not the governance step that adding no longer needs.
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('courseinstancedeleteconfirm', 'local_outcomemap', (object) [
            'catalog' => s($record->catalogcode ?? ''),
            'period' => s($record->periodcode),
        ]),
        new moodle_url($url, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    exit;
}
if ($action === 'add') {
    // Associating is always asked for about one catalog course, so the course the
    // reader clicked opens already chosen. It used to open on whichever course sorted
    // first, which reads as the right form filled in with the wrong course.
    $catalogcourseid = optional_param('courseid', 0, PARAM_INT);
    $formurl = new moodle_url($url, ['action' => 'add']);
    if ($catalogcourseid > 0) {
        $formurl->param('courseid', $catalogcourseid);
    }
    if ($returnprogram > 0) {
        $formurl->param('returnprogram', $returnprogram);
    }
    $form = new course_instance_form($formurl);
    if ($form->is_cancelled()) {
        redirect($returnurl);
    }
    if ($data = $form->get_data()) {
        course_instance_service::create_confirmed((array) $data);
        redirect($returnurl, get_string(
            workflow::requires_independent_approval() ? 'saved' : 'courseinstanceready',
            'local_outcomemap'
        ));
    }
    if ($catalogcourseid > 0) {
        $form->set_data(['courseid' => $catalogcourseid]);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('addcourseinstance', 'local_outcomemap'));
    echo html_writer::div(get_string(
        workflow::requires_independent_approval() ? 'courseinstances_intro' : 'courseinstances_intro_finalization',
        'local_outcomemap'
    ), 'lom-page-subtitle');
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
$page = new course_instances_page($catalogcode);
echo $OUTPUT->render_from_template('local_outcomemap/course_instances_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
