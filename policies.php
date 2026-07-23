<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site-administration policy management page.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\form\policy_form;
use local_outcomemap\local\service\policy_service;
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
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

/**
 * Build governed-scope selectors for the policy editor.
 *
 * @return array Catalog-course, course-instance, and assessment options.
 */
function local_outcomemap_policy_editor_options(): array {
    global $DB;

    $catalogcourses = [];
    foreach ($DB->get_records('local_outcomemap_course', null, 'code, name') as $record) {
        $catalogcourses[(int) $record->id] = $record->code . ' — ' . format_string($record->name);
    }

    $courseinstances = [];
    $assessments = [];
    $sql = "SELECT ci.id, ci.moodlecourseid, ci.periodcode, cc.code, c.shortname, c.fullname
              FROM {local_outcomemap_cinst} ci
              JOIN {local_outcomemap_course} cc ON cc.id = ci.courseid
              JOIN {course} c ON c.id = ci.moodlecourseid
          ORDER BY cc.code, ci.periodcode, c.fullname";
    foreach ($DB->get_records_sql($sql) as $record) {
        $courselabel = $record->code . ' / ' . $record->periodcode . ' — ' . format_string($record->fullname);
        $courseinstances[(int) $record->id] = $courselabel;
    }
    // Milestone 4 evidence is quiz-based, so one set-based query supplies the
    // assessment-level policy choices without loading every course modinfo.
    $assessmentsql = "SELECT DISTINCT cm.id, c.shortname, q.name
                        FROM {local_outcomemap_cinst} ci
                        JOIN {course} c ON c.id = ci.moodlecourseid
                        JOIN {course_modules} cm ON cm.course = c.id
                        JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                        JOIN {quiz} q ON q.id = cm.instance
                    ORDER BY c.shortname, q.name, cm.id";
    foreach ($DB->get_records_sql($assessmentsql, ['modname' => 'quiz']) as $record) {
        $assessments[(int) $record->id] = $record->shortname . ' — ' . format_string($record->name)
            . ' [cmid ' . (int) $record->id . ']';
    }
    asort($assessments, SORT_NATURAL | SORT_FLAG_CASE);
    return [
        'catalogcourses' => $catalogcourses,
        'courseinstances' => $courseinstances,
        'assessments' => $assessments,
    ];
}

/**
 * Convert editor fields into the policy service's typed payload.
 *
 * @param stdClass $data Submitted form data.
 * @return array Service payload.
 */
function local_outcomemap_policy_payload(stdClass $data): array {
    $scopeid = match ($data->scopetype) {
        policy_service::SCOPE_CATALOG_COURSE => (int) $data->catalogcourseid,
        policy_service::SCOPE_COURSE_INSTANCE => (int) $data->courseinstanceid,
        policy_service::SCOPE_ASSESSMENT => (int) $data->assessmentid,
        default => null,
    };
    if ($data->policytype === policy_service::TYPE_ATTEMPT_SELECTION) {
        $config = ['method' => $data->attemptmethod];
        $bands = [];
    } else if ($data->policytype === policy_service::TYPE_RELEASE) {
        $config = ['mode' => $data->releasemode];
        if ($data->releasemode === policy_service::RELEASE_SCHEDULED) {
            $config['releaseat'] = (int) $data->releaseat;
        }
        $bands = [];
    } else {
        $config = [
            'minitems' => (int) $data->minitems,
            'requiremanualgrading' => !empty($data->requiremanualgrading),
            'displayscale' => (int) $data->displayscale,
        ];
        if (trim((string) $data->minweightedpossible) !== '') {
            $config['minweightedpossible'] = $data->minweightedpossible;
        }
        $bands = [];
        foreach (array_keys((array) ($data->bandcode ?? [])) as $index) {
            $code = trim((string) ($data->bandcode[$index] ?? ''));
            $name = trim((string) ($data->bandname[$index] ?? ''));
            $description = trim((string) ($data->banddescription[$index] ?? ''));
            $min = trim((string) ($data->bandminpercent[$index] ?? ''));
            $max = trim((string) ($data->bandmaxpercent[$index] ?? ''));
            if ($code === '' && $name === '' && $description === '' && $min === '' && $max === '') {
                continue;
            }
            $bands[] = [
                'code' => $code,
                'name' => $name,
                'description' => $description === '' ? null : $description,
                'minpercent' => $min === '' ? null : $min,
                'mininclusive' => !empty($data->bandmininclusive[$index]),
                'maxpercent' => $max === '' ? null : $max,
                'maxinclusive' => !empty($data->bandmaxinclusive[$index]),
            ];
        }
    }
    return [
        'policytype' => $data->policytype,
        'scopetype' => $data->scopetype,
        'scopeid' => $scopeid,
        'name' => $data->name,
        'config' => $config,
        'bands' => $bands,
        'effectivefrom' => (int) $data->effectivefrom,
        'effectiveto' => empty($data->effectiveto) ? null : (int) $data->effectiveto,
        'reason' => $data->reason ?? null,
    ];
}

/**
 * Populate editor-specific fields from a stored policy version.
 *
 * @param stdClass $record Policy with decoded configuration and bands.
 * @return stdClass Form defaults.
 */
function local_outcomemap_policy_form_data(stdClass $record): stdClass {
    if ($record->scopetype === policy_service::SCOPE_CATALOG_COURSE) {
        $record->catalogcourseid = $record->scopeid;
    } else if ($record->scopetype === policy_service::SCOPE_COURSE_INSTANCE) {
        $record->courseinstanceid = $record->scopeid;
    } else if ($record->scopetype === policy_service::SCOPE_ASSESSMENT) {
        $record->assessmentid = $record->scopeid;
    }
    if ($record->policytype === policy_service::TYPE_ATTEMPT_SELECTION) {
        $record->attemptmethod = $record->config['method'] ?? '';
    } else if ($record->policytype === policy_service::TYPE_RELEASE) {
        $record->releasemode = $record->config['mode'] ?? '';
        $record->releaseat = $record->config['releaseat'] ?? time();
    } else {
        $record->minitems = $record->config['minitems'] ?? 1;
        $record->minweightedpossible = $record->config['minweightedpossible'] ?? '';
        $record->requiremanualgrading = !empty($record->config['requiremanualgrading']);
        $record->displayscale = $record->config['displayscale'] ?? 1;
        foreach (array_values($record->bands) as $index => $band) {
            $record->bandcode[$index] = $band->code;
            $record->bandname[$index] = $band->name;
            $record->banddescription[$index] = $band->description;
            $record->bandminpercent[$index] = $band->minpercent;
            $record->bandmininclusive[$index] = $band->mininclusive;
            $record->bandmaxpercent[$index] = $band->maxpercent;
            $record->bandmaxinclusive[$index] = $band->maxinclusive;
        }
    }
    return $record;
}

/**
 * Resolve a human-readable policy scope.
 *
 * @param stdClass $record Policy record.
 * @param array $options Editor options.
 * @return string Scope label.
 */
function local_outcomemap_policy_scope_label(stdClass $record, array $options): string {
    if ($record->scopetype === policy_service::SCOPE_INSTITUTION) {
        return get_string('policyscope_institution', 'local_outcomemap');
    }
    $optionkey = match ($record->scopetype) {
        policy_service::SCOPE_CATALOG_COURSE => 'catalogcourses',
        policy_service::SCOPE_COURSE_INSTANCE => 'courseinstances',
        policy_service::SCOPE_ASSESSMENT => 'assessments',
        default => '',
    };
    return $options[$optionkey][(int) $record->scopeid]
        ?? get_string('unknownscope', 'local_outcomemap', (int) $record->scopeid);
}

/**
 * Summarize typed configuration for the policy table.
 *
 * @param stdClass $record Policy with decoded configuration and bands.
 * @return string Plain-text summary.
 */
function local_outcomemap_policy_config_summary(stdClass $record): string {
    if ($record->policytype === policy_service::TYPE_ATTEMPT_SELECTION) {
        return get_string('attemptmethod_' . ($record->config['method'] ?? ''), 'local_outcomemap');
    }
    if ($record->policytype === policy_service::TYPE_RELEASE) {
        $mode = $record->config['mode'] ?? '';
        $summary = get_string('releasemode_' . $mode, 'local_outcomemap');
        if ($mode === policy_service::RELEASE_SCHEDULED && !empty($record->config['releaseat'])) {
            $summary .= ': ' . userdate((int) $record->config['releaseat']);
        } else if ($mode === policy_service::RELEASE_MANUAL) {
            $summary .= '; ' . ($record->manualreleasedat === null
                ? get_string('manualrelease_pending', 'local_outcomemap')
                : get_string('manualrelease_at', 'local_outcomemap', userdate($record->manualreleasedat)));
        }
        return $summary;
    }
    $summary = [
        get_string('minimumdistinctitems_value', 'local_outcomemap', $record->config['minitems'] ?? 1),
    ];
    if (isset($record->config['minweightedpossible'])) {
        $summary[] = get_string('minimumweightedpossible_value', 'local_outcomemap',
            $record->config['minweightedpossible']);
    }
    $summary[] = get_string('displayprecision_value', 'local_outcomemap', $record->config['displayscale'] ?? 1);
    $summary[] = get_string('manualgrading_value', 'local_outcomemap',
        !empty($record->config['requiremanualgrading']) ? get_string('yes') : get_string('no'));
    $summary[] = get_string('performancebandcount', 'local_outcomemap', count($record->bands));
    return implode('; ', $summary);
}

admin_externalpage_setup('local_outcomemap_policies');
$url = new moodle_url('/local/outcomemap/policies.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirmed = optional_param('confirm', 0, PARAM_BOOL);
$options = local_outcomemap_policy_editor_options();

if ($action === 'submit' && $id) {
    require_sesskey();
    policy_service::submit_for_review($id);
    redirect($url, get_string('submittedforreview', 'local_outcomemap'));
}
if ($action === 'delete' && $id) {
    if (!$confirmed) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmdeletepolicy', 'local_outcomemap'),
            new moodle_url($url, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
            $url
        );
        echo $OUTPUT->footer();
        exit;
    }
    require_sesskey();
    policy_service::delete_draft($id);
    redirect($url, get_string('policydeleted', 'local_outcomemap'));
}
if ($action === 'release' && $id) {
    if (!$confirmed) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmmanualrelease', 'local_outcomemap'),
            new moodle_url($url, ['action' => 'release', 'id' => $id, 'confirm' => 1,
                'sesskey' => sesskey()]),
            $url
        );
        echo $OUTPUT->footer();
        exit;
    }
    require_sesskey();
    policy_service::release_manual($id);
    redirect($url, get_string('manualreleased', 'local_outcomemap'));
}

if (in_array($action, ['add', 'edit', 'newversion'], true)) {
    $record = $id ? local_outcomemap_policy_form_data(policy_service::get($id)) : null;
    $repeatcount = $record ? max(1, count($record->bands)) : 1;
    $formurl = new moodle_url($url, ['action' => $action, 'id' => $id]);
    $form = new policy_form($formurl, [
        'options' => $options,
        'repeatcount' => $repeatcount,
        'lockedscope' => $action === 'newversion',
    ]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $payload = local_outcomemap_policy_payload($data);
        if ($action === 'newversion') {
            policy_service::create_version($id, $payload);
        } else if ($action === 'edit') {
            policy_service::update_draft($id, $payload);
        } else {
            policy_service::create($payload);
        }
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($record) {
        if ($action === 'newversion') {
            $record->id = 0;
            $record->effectivefrom = time();
            $record->effectiveto = null;
            $record->reason = null;
        }
        $form->set_data($record);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($action === 'newversion' ? 'newpolicyversion' :
        ($action === 'edit' ? 'editpolicy' : 'addpolicy'), 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'view' && $id) {
    $record = policy_service::get($id);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($record->name));
    $details = new html_table();
    $details->data = [
        [get_string('policytype', 'local_outcomemap'),
            get_string('policytype_' . $record->policytype, 'local_outcomemap')],
        [get_string('policyscope', 'local_outcomemap'), local_outcomemap_policy_scope_label($record, $options)],
        [get_string('version', 'local_outcomemap'), (int) $record->version],
        [get_string('status', 'local_outcomemap'), get_string('status_' . $record->status, 'local_outcomemap')],
        [get_string('policyconfiguration', 'local_outcomemap'), local_outcomemap_policy_config_summary($record)],
        [get_string('effectivefrom', 'local_outcomemap'), userdate($record->effectivefrom)],
        [get_string('effectiveto', 'local_outcomemap'), $record->effectiveto
            ? userdate($record->effectiveto) : get_string('noenddate', 'local_outcomemap')],
    ];
    echo html_writer::table($details);
    if ($record->bands) {
        echo $OUTPUT->heading(get_string('performancebands', 'local_outcomemap'), 3);
        $bands = new html_table();
        $bands->head = [
            get_string('code', 'local_outcomemap'),
            get_string('name', 'local_outcomemap'),
            get_string('description', 'local_outcomemap'),
            get_string('minimum', 'local_outcomemap'),
            get_string('maximum', 'local_outcomemap'),
        ];
        foreach ($record->bands as $band) {
            $bands->data[] = [
                s($band->code),
                format_string($band->name),
                s($band->description ?? ''),
                ($band->minpercent === null ? '−∞' : s($band->minpercent))
                    . ((int) $band->mininclusive
                        ? ' (' . get_string('inclusive', 'local_outcomemap') . ')'
                        : ' (' . get_string('exclusive', 'local_outcomemap') . ')'),
                ($band->maxpercent === null ? '+∞' : s($band->maxpercent))
                    . ((int) $band->maxinclusive
                        ? ' (' . get_string('inclusive', 'local_outcomemap') . ')'
                        : ' (' . get_string('exclusive', 'local_outcomemap') . ')'),
            ];
        }
        echo html_writer::table($bands);
    }
    echo $OUTPUT->single_button($url, get_string('back'));
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('policies_heading', 'local_outcomemap'));
echo html_writer::div(get_string('policies_intro', 'local_outcomemap'), 'mb-3');
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'add']), get_string('addpolicy', 'local_outcomemap'));
$table = new html_table();
$table->head = [
    get_string('name', 'local_outcomemap'),
    get_string('policytype', 'local_outcomemap'),
    get_string('policyscope', 'local_outcomemap'),
    get_string('policyconfiguration', 'local_outcomemap'),
    get_string('version', 'local_outcomemap'),
    get_string('status', 'local_outcomemap'),
    get_string('effectiverange', 'local_outcomemap'),
    get_string('actions', 'local_outcomemap'),
];
foreach (policy_service::list_all() as $record) {
    $actions = [
        html_writer::link(new moodle_url($url, ['action' => 'view', 'id' => $record->id]), get_string('view')),
    ];
    if ($record->status === workflow::DRAFT) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'edit', 'id' => $record->id]),
            get_string('edit'));
        $actions[] = html_writer::link(new moodle_url($url, [
            'action' => 'submit',
            'id' => $record->id,
            'sesskey' => sesskey(),
        ]), get_string('submitreview', 'local_outcomemap'));
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'delete', 'id' => $record->id]),
            get_string('delete'));
    } else if ($record->status === workflow::APPROVED) {
        $actions[] = html_writer::link(new moodle_url($url, ['action' => 'newversion', 'id' => $record->id]),
            get_string('newpolicyversion', 'local_outcomemap'));
        if ($record->policytype === policy_service::TYPE_RELEASE
                && ($record->config['mode'] ?? null) === policy_service::RELEASE_MANUAL
                && $record->manualreleasedat === null) {
            $actions[] = html_writer::link(new moodle_url($url, [
                'action' => 'release',
                'id' => $record->id,
            ]), get_string('manualrelease', 'local_outcomemap'));
        }
    }
    $effectiverange = userdate($record->effectivefrom) . ' — '
        . ($record->effectiveto ? userdate($record->effectiveto) : get_string('noenddate', 'local_outcomemap'));
    $table->data[] = [
        format_string($record->name),
        get_string('policytype_' . $record->policytype, 'local_outcomemap'),
        local_outcomemap_policy_scope_label($record, $options),
        s(local_outcomemap_policy_config_summary($record)),
        (int) $record->version,
        get_string('status_' . $record->status, 'local_outcomemap'),
        $effectiverange,
        implode(' | ', $actions),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
