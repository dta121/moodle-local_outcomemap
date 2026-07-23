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
 * Course outcome coverage page.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\local\service\coverage_service;

$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    // Windows junctions resolve __DIR__ to the repository target rather than
    // the Moodle local-plugin directory. The webroot loader remains portable
    // across Moodle's classic and 5.2 public-directory layouts.
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/outcomemap:viewdefinitions', $context);
$PAGE->set_context($context);
$PAGE->set_course($course);
$url = new moodle_url('/local/outcomemap/coverage.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coverage_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

$matrix = coverage_service::matrix($courseid);
$modinfo = get_fast_modinfo($courseid);
$table = new html_table();
$table->head = [
    get_string('outcomeversion', 'local_outcomemap'),
    get_string('statement', 'local_outcomemap'),
    get_string('coursesections', 'local_outcomemap'),
    get_string('coursemodules', 'local_outcomemap'),
];
foreach ($matrix as $row) {
    $sections = [];
    foreach ($row->sections as $mapping) {
        $name = get_section_name($courseid, (int) $mapping->sectionnumber);
        $sections[] = format_string($name) . ' — '
            . get_string('mappingrole_' . $mapping->role, 'local_outcomemap') . ' ('
            . get_string('status_' . $mapping->status, 'local_outcomemap') . ')';
    }
    $modules = [];
    foreach ($row->modules as $mapping) {
        $cm = $modinfo->get_cm((int) $mapping->cmid);
        $modules[] = $cm->get_formatted_name() . ' — '
            . get_string('mappingrole_' . $mapping->role, 'local_outcomemap') . ' ('
            . get_string('status_' . $mapping->status, 'local_outcomemap') . ')';
    }
    $table->data[] = [
        s($row->frameworkcode . '.' . $row->outcomecode . ' v' . $row->outcomeversion),
        format_text($row->statement, FORMAT_PLAIN),
        implode(html_writer::empty_tag('br'), $sections),
        implode(html_writer::empty_tag('br'), $modules),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coverage_heading', 'local_outcomemap'));
if ($matrix) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(
        get_string('nocoveragemappings', 'local_outcomemap'),
        \core\output\notification::NOTIFY_INFO
    );
}
echo $OUTPUT->footer();
