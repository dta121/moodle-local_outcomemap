<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Verified accreditation snapshot downloads.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\local\service\accreditation_export_service;

$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);
require_once($CFG->libdir . '/filelib.php');

require_login();
$context = context_system::instance();
require_capability('local/outcomemap:exportaccreditation', $context);
$id = required_param('id', PARAM_INT);
$format = optional_param('format', 'json', PARAM_ALPHA);
$includeevidence = optional_param('evidence', 0, PARAM_BOOL);
$snapshot = $DB->get_record('local_outcomemap_snapshot', ['id' => $id], '*', MUST_EXIST);
$stem = accreditation_export_service::filename_stem($snapshot);

if ($format === 'csv') {
    send_file(
        accreditation_export_service::summary_csv($id),
        $stem . '-summary.csv',
        0,
        0,
        true,
        true,
        'text/csv; charset=utf-8'
    );
}
if ($format !== 'json') {
    throw new moodle_exception('invalidfield', 'local_outcomemap', '',
        (object) ['field' => 'format', 'detail' => $format]);
}
send_file(
    accreditation_export_service::json($id, $includeevidence),
    $stem . ($includeevidence ? '-evidence' : '') . '.json',
    0,
    0,
    true,
    true,
    'application/json; charset=utf-8'
);
