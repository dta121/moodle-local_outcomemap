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
 * Verified accreditation snapshot downloads.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\local\service\accreditation_export_service;

// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Portable bootstrap path.
$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);
require_once($CFG->libdir . '/filelib.php');

require_login();
require_sesskey();
$context = context_system::instance();
require_capability('local/outcomemap:exportaccreditation', $context);
$id = required_param('id', PARAM_INT);
$format = optional_param('format', 'json', PARAM_ALPHA);
$includeevidence = optional_param('evidence', 0, PARAM_BOOL);
$snapshot = $DB->get_record('local_outcomemap_snapshot', ['id' => $id], '*', MUST_EXIST);
$stem = accreditation_export_service::filename_stem($snapshot);

if ($format === 'csv') {
    $content = accreditation_export_service::summary_csv($id);
    accreditation_export_service::record_export($id, 'csv');
    send_file(
        $content,
        $stem . '-summary.csv',
        0,
        0,
        true,
        true,
        'text/csv; charset=utf-8'
    );
}
if ($format !== 'json') {
    throw new moodle_exception(
        'invalidfield',
        'local_outcomemap',
        '',
        (object) ['field' => 'format', 'detail' => $format]
    );
}
$content = accreditation_export_service::json($id, $includeevidence);
accreditation_export_service::record_export($id, 'json', $includeevidence);
send_file(
    $content,
    $stem . ($includeevidence ? '-evidence' : '') . '.json',
    0,
    0,
    true,
    true,
    'application/json; charset=utf-8'
);
