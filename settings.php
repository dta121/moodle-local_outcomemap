<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site administration navigation for local_outcomemap.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add('localplugins', new admin_category('local_outcomemap',
    get_string('pluginname', 'local_outcomemap')));

$pages = [
    'local_outcomemap_dashboard' => ['dashboard', 'viewdefinitions'],
    'local_outcomemap_programs' => ['programs', 'manageprograms'],
    'local_outcomemap_courses' => ['catalogcourses', 'managecatalogcourses'],
    'local_outcomemap_courseinstances' => ['courseinstances', 'managecatalogcourses'],
    'local_outcomemap_frameworks' => ['frameworks', 'manageframeworks'],
    'local_outcomemap_relations' => ['relations', 'manageframeworks'],
    'local_outcomemap_approvals' => ['approvalqueue', 'approve'],
    'local_outcomemap_import' => ['csvimport', 'manageframeworks'],
];

foreach ($pages as $pageid => [$script, $capability]) {
    $ADMIN->add('local_outcomemap', new admin_externalpage(
        $pageid,
        get_string('nav_' . $script, 'local_outcomemap'),
        new moodle_url('/local/outcomemap/' . $script . '.php'),
        'local/outcomemap:' . $capability,
    ));
}

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_outcomemap_settings',
        get_string('nav_settings', 'local_outcomemap'));
    $settings->add(new admin_setting_configcheckbox(
        'local_outcomemap/autocopyquestionmappings',
        get_string('autocopyquestionmappings', 'local_outcomemap'),
        get_string('autocopyquestionmappings_desc', 'local_outcomemap'),
        1
    ));
    $ADMIN->add('local_outcomemap', $settings);
}
