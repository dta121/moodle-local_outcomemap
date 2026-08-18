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

use local_outcomemap\form\catalog_course_form;
use local_outcomemap\form\program_course_form;
use local_outcomemap\form\program_course_move_form;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\program_course_service;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

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

admin_externalpage_setup('local_outcomemap_courses');
$url = new moodle_url('/local/outcomemap/catalogcourses.php');
$action = optional_param('action', '', PARAM_ALPHA);
$type = optional_param('type', 'course', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'submit' && $id) {
    require_sesskey();
    if ($type === 'membership') {
        program_course_service::submit_for_review($id);
    } else {
        catalog_course_service::submit_for_review($id);
    }
    redirect($url, workflow::submission_success_message());
}

if ($action === 'removemembership' && $id) {
    require_sesskey();
    $membership = $DB->get_record_sql(
        'SELECT pc.*, p.code AS programcode, c.code AS coursecode
           FROM {local_outcomemap_progcourse} pc
           JOIN {local_outcomemap_program} p ON p.id = pc.programid
           JOIN {local_outcomemap_course} c ON c.id = pc.courseid
          WHERE pc.id = :id',
        ['id' => $id],
        MUST_EXIST
    );
    $back = new moodle_url('/local/outcomemap/curriculum.php', ['program' => (int) $membership->programid]);
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        program_course_service::remove($id);
        redirect($back, get_string('membershipremoved', 'local_outcomemap', (object) [
            'course' => s($membership->coursecode),
            'program' => s($membership->programcode),
        ]));
    }
    // Taking a course out of a program is destructive, so it keeps its own
    // confirmation step. An approved membership is retired rather than deleted,
    // and the prompt says which of the two is about to happen.
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string(
            $membership->status === workflow::APPROVED
                ? 'membershipretireconfirm'
                : 'membershipremoveconfirm',
            'local_outcomemap',
            (object) ['course' => s($membership->coursecode), 'program' => s($membership->programcode)]
        ),
        new moodle_url($url, ['action' => 'removemembership', 'id' => $id, 'confirm' => 1,
            'sesskey' => sesskey()]),
        $back
    );
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'movemembership' && $id) {
    $membership = $DB->get_record('local_outcomemap_progcourse', ['id' => $id], '*', MUST_EXIST);
    $formurl = new moodle_url($url, ['action' => 'movemembership', 'id' => $id]);
    $form = new program_course_move_form($formurl, ['membership' => $membership]);
    $back = new moodle_url('/local/outcomemap/curriculum.php', ['program' => (int) $membership->programid]);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        try {
            program_course_service::move($id, (int) $data->targetprogramid, $data->reason ?: null);
            redirect(
                new moodle_url(
                    '/local/outcomemap/curriculum.php',
                    ['program' => (int) $data->targetprogramid]
                ),
                get_string('membershipmoved', 'local_outcomemap')
            );
        } catch (validation_exception $e) {
            \core\notification::error($e->getMessage());
        }
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('membershipmove', 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'addmembership') {
    // A membership is added from one program's course list or one course's card,
    // so whichever side the reader came from is preselected rather than searched
    // for again in the form.
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $programid = optional_param('programid', 0, PARAM_INT);
    $formurl = new moodle_url($url, ['action' => 'addmembership']);
    if ($courseid) {
        $formurl->param('courseid', $courseid);
    }
    if ($programid) {
        $formurl->param('programid', $programid);
    }
    $form = new program_course_form($formurl);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        program_course_service::create((array) $data);
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    $prefill = [];
    if ($courseid && $DB->record_exists('local_outcomemap_course', ['id' => $courseid])) {
        $prefill['courseid'] = $courseid;
    }
    if ($programid && $DB->record_exists('local_outcomemap_program', ['id' => $programid])) {
        $prefill['programid'] = $programid;
    }
    if ($prefill) {
        $form->set_data($prefill);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('addprogramcourse', 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'edit' || $action === 'add') {
    $formurl = new moodle_url($url, ['action' => $action]);
    if ($id) {
        $formurl->param('id', $id);
    }
    $form = new catalog_course_form($formurl);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        if ($data->id) {
            catalog_course_service::update((int) $data->id, (array) $data);
        } else {
            catalog_course_service::create((array) $data);
        }
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($id) {
        $form->set_data($DB->get_record('local_outcomemap_course', ['id' => $id], '*', MUST_EXIST));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($id ? 'editcatalogcourse' : 'addcatalogcourse', 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// Catalog courses are read on the Curriculum page, under the programs that
// contain them; a course in no program is offered there for attachment. This
// script keeps the governed add, edit, membership, and submit forms.
redirect(new moodle_url('/local/outcomemap/curriculum.php'));
