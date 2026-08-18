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
 * Site administration navigation for local_outcomemap.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Outcome mapping spans programs, courses, frameworks, and accreditation
// reporting, so it sits alongside Competencies in the General section rather
// than buried under Local plugins. The third argument inserts the category
// immediately before Competencies, which core registers directly after
// Analytics; if that sibling is ever absent the category is simply appended.
$ADMIN->add('root', new admin_category(
    'local_outcomemap',
    get_string('pluginname', 'local_outcomemap')
), 'competencies');

$pages = [
    'local_outcomemap_dashboard' => ['dashboard', 'viewdefinitions'],
    'local_outcomemap_curriculum' => ['curriculum', 'manageprograms'],
    'local_outcomemap_frameworks' => ['frameworks', 'manageframeworks'],
    'local_outcomemap_policies' => ['policies', 'managepolicies'],
    'local_outcomemap_snapshots' => ['snapshots', 'managesnapshots'],
    'local_outcomemap_reports' => ['reports', 'viewdefinitions'],
    'local_outcomemap_import' => ['csvimport', 'manageframeworks'],
];
if (\local_outcomemap\local\workflow::requires_independent_approval()) {
    $pages['local_outcomemap_approvals'] = ['approvalqueue', 'approve'];
}

// These scripts are no longer read on their own: programs, catalog courses, and
// course instances are read on Curriculum, and outcome relations are the matrix
// view of Outcomes and alignment. Each still owns the governed add, edit, and
// submit forms those pages link to, so they stay registered for
// admin_externalpage_setup() and hidden from the navigation.
$hidden = [
    'local_outcomemap_programs' => ['programs', 'manageprograms'],
    'local_outcomemap_courses' => ['catalogcourses', 'managecatalogcourses'],
    'local_outcomemap_courseinstances' => ['courseinstances', 'managecatalogcourses'],
    'local_outcomemap_relations' => ['relations', 'manageframeworks'],
];

foreach ($pages + $hidden as $pageid => [$script, $capability]) {
    $ADMIN->add('local_outcomemap', new admin_externalpage(
        $pageid,
        get_string('nav_' . $script, 'local_outcomemap'),
        new moodle_url('/local/outcomemap/' . $script . '.php'),
        'local/outcomemap:' . $capability,
        isset($hidden[$pageid]),
    ));
}

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_outcomemap_settings',
        get_string('nav_settings', 'local_outcomemap')
    );
    $settings->add(new admin_setting_configcheckbox(
        'local_outcomemap/requireapproval',
        get_string('requireapproval', 'local_outcomemap'),
        get_string('requireapproval_desc', 'local_outcomemap'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_outcomemap/autocopyquestionmappings',
        get_string('autocopyquestionmappings', 'local_outcomemap'),
        get_string(
            \local_outcomemap\local\workflow::requires_independent_approval()
                ? 'autocopyquestionmappings_desc'
                : 'autocopyquestionmappings_desc_finalization',
            'local_outcomemap'
        ),
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_outcomemap/enableremediation',
        get_string('enableremediation', 'local_outcomemap'),
        get_string('enableremediation_desc', 'local_outcomemap'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_outcomemap/autosubmitquestionmappings',
        get_string('autosubmitquestionmappings', 'local_outcomemap'),
        get_string(
            \local_outcomemap\local\workflow::requires_independent_approval()
                ? 'autosubmitquestionmappings_desc'
                : 'autosubmitquestionmappings_desc_finalization',
            'local_outcomemap'
        ),
        0
    ));
    $ADMIN->add('local_outcomemap', $settings);
}
