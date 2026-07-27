<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Record explicit learner engagement and open an authorized recommendation.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\local\feature;
use local_outcomemap\local\service\remediation_engagement_service;

$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);

require_login();
require_sesskey();
feature::require_enabled(feature::remediation_enabled(), 'remediationdisabled');
$recommendationid = required_param('id', PARAM_INT);
$resultid = required_param('resultid', PARAM_INT);
$destination = remediation_engagement_service::record_open($recommendationid, $resultid);
header('Referrer-Policy: no-referrer');
redirect($destination);
