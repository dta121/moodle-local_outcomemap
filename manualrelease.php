<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Course-scoped manual learner-feedback release controls.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\local\service\policy_service;

$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid, MUST_EXIST);
require_capability('local/outcomemap:managepolicies', $context);
require_capability('moodle/course:update', $context);

$url = new moodle_url('/local/outcomemap/manualrelease.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('manualrelease_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

$instances = $DB->get_records('local_outcomemap_cinst', [
    'moodlecourseid' => $courseid,
    'status' => \local_outcomemap\local\workflow::APPROVED,
    'confirmed' => 1,
]);
$quizcmids = $DB->get_fieldset_sql(
    "SELECT cm.id
       FROM {course_modules} cm
       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
      WHERE cm.course = :courseid",
    ['modname' => 'quiz', 'courseid' => $courseid]
);
$requests = [];
foreach ($instances as $instance) {
    $requests['course:' . $instance->id] = [
        'cinstid' => (int) $instance->id,
        'cmid' => null,
    ];
    foreach ($quizcmids as $cmid) {
        $requests['assessment:' . $instance->id . ':' . $cmid] = [
            'cinstid' => (int) $instance->id,
            'cmid' => (int) $cmid,
        ];
    }
}
$resolved = policy_service::resolve_many(policy_service::TYPE_RELEASE, $requests);
$policies = [];
foreach ($resolved as $policy) {
    if ($policy === null || ($policy->config['mode'] ?? null) !== policy_service::RELEASE_MANUAL) {
        continue;
    }
    if ($policy->scopetype === policy_service::SCOPE_ASSESSMENT) {
        $policycontext = context_module::instance((int) $policy->scopeid, MUST_EXIST);
    } else if ($policy->scopetype === policy_service::SCOPE_COURSE_INSTANCE) {
        $policycontext = $context;
    } else {
        $policycontext = context_system::instance();
    }
    if (has_capability('local/outcomemap:managepolicies', $policycontext)) {
        $policies[(int) $policy->id] = $policy;
    }
}
$releasetimes = policy_service::manual_release_times(array_keys($policies));
foreach ($policies as $policyid => $policy) {
    $policy->manualreleasedat = $releasetimes[$policyid] ?? null;
}

$action = optional_param('action', '', PARAM_ALPHA);
$policyid = optional_param('policyid', 0, PARAM_INT);
$confirmed = optional_param('confirm', 0, PARAM_BOOL);
if ($action === 'release' && $policyid) {
    if (!isset($policies[$policyid])) {
        throw new invalid_parameter_exception('The policy does not govern this course or is not releasable here.');
    }
    if (!$confirmed) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmmanualrelease', 'local_outcomemap'),
            new moodle_url($url, [
                'action' => 'release',
                'policyid' => $policyid,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            $url
        );
        echo $OUTPUT->footer();
        exit;
    }
    require_sesskey();
    policy_service::release_manual($policyid);
    redirect($url, get_string('manualreleased', 'local_outcomemap'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manualrelease_heading', 'local_outcomemap'));
echo html_writer::div(get_string('manualrelease_intro', 'local_outcomemap'), 'mb-3');
if (!$policies) {
    echo $OUTPUT->notification(get_string('manualrelease_empty', 'local_outcomemap'), 'info');
} else {
    $table = new html_table();
    $table->caption = get_string('manualrelease_caption', 'local_outcomemap');
    $table->head = [
        get_string('name', 'local_outcomemap'),
        get_string('policyscope', 'local_outcomemap'),
        get_string('status', 'local_outcomemap'),
        get_string('actions', 'local_outcomemap'),
    ];
    foreach ($policies as $policy) {
        $released = $policy->manualreleasedat === null
            ? get_string('manualrelease_pending', 'local_outcomemap')
            : get_string('manualrelease_at', 'local_outcomemap', userdate($policy->manualreleasedat));
        $actions = $policy->manualreleasedat === null
            ? html_writer::link(new moodle_url($url, [
                'action' => 'release',
                'policyid' => $policy->id,
            ]), get_string('manualrelease', 'local_outcomemap'))
            : get_string('none', 'local_outcomemap');
        $table->data[] = [
            format_string($policy->name),
            get_string('policyscope_' . $policy->scopetype, 'local_outcomemap'),
            $released,
            $actions,
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
