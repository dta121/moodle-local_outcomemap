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

use local_outcomemap\local\csv_safety;
use local_outcomemap\local\highlight;
use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\service\coverage_service;
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

$courseid = required_param('courseid', PARAM_INT);
$search = trim(optional_param('q', '', PARAM_TEXT));
$filter = optional_param('filter', 'all', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/outcomemap:viewdefinitions', $context);

$validfilters = ['all', coverage_service::STATUS_NONE, coverage_service::STATUS_TAUGHT, coverage_service::STATUS_FULL];
if (!in_array($filter, $validfilters, true)) {
    $filter = 'all';
}

$matrix = coverage_service::matrix($courseid);
$modinfo = get_fast_modinfo($courseid);

/**
 * Describe one mapping as a display label carrying its governance state.
 *
 * @param \stdClass $mapping Mapping record.
 * @param int $courseid Moodle course ID.
 * @param course_modinfo $modinfo Course module info.
 * @return string Plain-text label.
 */
function local_outcomemap_mapping_label(\stdClass $mapping, int $courseid, course_modinfo $modinfo): string {
    if (isset($mapping->questioncount)) {
        return get_string('coverage_questionmapping', 'local_outcomemap', (object) [
            'quiz' => $mapping->label,
            'count' => $mapping->questioncount,
        ]);
    } else if (isset($mapping->cmid)) {
        $cm = $modinfo->get_cm((int) $mapping->cmid);
        $name = $cm->get_formatted_name();
    } else {
        $name = get_section_name($courseid, (int) $mapping->sectionnumber);
    }
    return $name;
}

// Project the matrix into display rows once so the counts, the filtered table,
// and the CSV export can never disagree about an outcome's status.
$rows = [];
foreach ($matrix as $itemverid => $row) {
    $taught = [];
    $assessed = [];
    foreach (array_merge($row->sections, $row->modules, $row->questions ?? []) as $mapping) {
        $entry = (object) [
            'label' => local_outcomemap_mapping_label($mapping, $courseid, $modinfo),
            'role' => $mapping->role,
            'status' => $mapping->status,
        ];
        if ($mapping->role === content_mapping_service::ROLE_ASSESSES) {
            $assessed[] = $entry;
        } else {
            $taught[] = $entry;
        }
    }
    $rows[$itemverid] = (object) [
        'itemverid' => (int) $itemverid,
        'code' => $row->frameworkcode . '.' . $row->outcomecode,
        'frameworkcode' => $row->frameworkcode,
        'version' => (int) $row->outcomeversion,
        'statement' => (string) $row->statement,
        'statusid' => coverage_service::row_status($row),
        'taught' => $taught,
        'assessed' => $assessed,
    ];
}

$counts = [
    'all' => count($rows),
    coverage_service::STATUS_FULL => 0,
    coverage_service::STATUS_TAUGHT => 0,
    coverage_service::STATUS_NONE => 0,
];
foreach ($rows as $row) {
    if ($row->statusid === coverage_service::STATUS_FULL) {
        $counts[coverage_service::STATUS_FULL]++;
    } else if ($row->statusid === coverage_service::STATUS_NONE) {
        $counts[coverage_service::STATUS_NONE]++;
    } else {
        // Taught-only and assessed-only are both incomplete coverage.
        $counts[coverage_service::STATUS_TAUGHT]++;
    }
}

$needle = core_text::strtolower($search);
$visible = [];
foreach ($rows as $itemverid => $row) {
    if ($filter === coverage_service::STATUS_FULL && $row->statusid !== coverage_service::STATUS_FULL) {
        continue;
    }
    if ($filter === coverage_service::STATUS_NONE && $row->statusid !== coverage_service::STATUS_NONE) {
        continue;
    }
    if ($filter === coverage_service::STATUS_TAUGHT
            && !in_array($row->statusid, [coverage_service::STATUS_TAUGHT, coverage_service::STATUS_ASSESSED_ONLY], true)) {
        continue;
    }
    if ($needle !== '') {
        $haystack = core_text::strtolower($row->code . ' ' . $row->statement);
        if (core_text::strpos($haystack, $needle) === false) {
            continue;
        }
    }
    $visible[$itemverid] = $row;
}

if ($action === 'export') {
    require_once($CFG->libdir . '/csvlib.class.php');
    $exporter = new csv_export_writer();
    $exporter->set_filename(clean_filename($course->shortname . '-outcome-coverage'));
    $exporter->add_data([
        get_string('outcomeversion', 'local_outcomemap'),
        get_string('version', 'local_outcomemap'),
        get_string('statement', 'local_outcomemap'),
        get_string('coveragestatus', 'local_outcomemap'),
        get_string('coverage_taughtin', 'local_outcomemap'),
        get_string('coverage_assessedby', 'local_outcomemap'),
    ]);
    foreach ($visible as $row) {
        // Statements, codes, and activity names are staff-entered free text,
        // so they are neutralized against spreadsheet formula execution.
        $exporter->add_data(csv_safety::row([
            $row->code,
            'v' . $row->version,
            $row->statement,
            get_string('coveragestatus_' . $row->statusid, 'local_outcomemap'),
            implode('; ', array_map(fn($e) => $e->label, $row->taught)),
            implode('; ', array_map(fn($e) => $e->label, $row->assessed)),
        ]));
    }
    $exporter->download_file();
    exit;
}

$PAGE->set_context($context);
$PAGE->set_course($course);
$url = new moodle_url('/local/outcomemap/coverage.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coverage_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

$statusmeta = [
    coverage_service::STATUS_FULL => 'lom-cov-badge-full',
    coverage_service::STATUS_ASSESSED_ONLY => 'lom-cov-badge-partial',
    coverage_service::STATUS_TAUGHT => 'lom-cov-badge-partial',
    coverage_service::STATUS_NONE => 'lom-cov-badge-none',
];

echo $OUTPUT->header();

// Toolbar: report identity on the left, page actions on the right.
$actions = '';
if (has_capability('local/outcomemap:mapcourse', $context)
        || has_capability('local/outcomemap:mapactivities', $context)) {
    $actions .= html_writer::link(
        new moodle_url('/local/outcomemap/contentmapping.php', ['courseid' => $courseid]),
        get_string('contentmapping_heading', 'local_outcomemap'),
        ['class' => 'btn btn-outline-secondary btn-sm']
    );
}
if ($visible) {
    $exporturl = new moodle_url($url, ['action' => 'export', 'filter' => $filter, 'q' => $search]);
    $actions .= html_writer::link(
        $exporturl,
        get_string('coverage_exportcsv', 'local_outcomemap'),
        ['class' => 'btn btn-outline-secondary btn-sm']
    );
}
echo html_writer::div(
    html_writer::span(get_string('coverage_report', 'local_outcomemap'), 'lom-cov-toolbar-label')
        . html_writer::span(get_string('coverage_heading', 'local_outcomemap'), 'lom-cov-chip')
        . html_writer::div($actions, 'lom-cov-toolbar-actions'),
    'lom-cov-toolbar'
);

echo html_writer::tag('h2', get_string('coverage_heading', 'local_outcomemap'), ['class' => 'lom-cov-title']);
echo html_writer::div(get_string('coverage_subtitle', 'local_outcomemap'), 'lom-cov-subtitle');

if (!$rows) {
    echo $OUTPUT->notification(
        get_string('nocoveragemappings', 'local_outcomemap'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    exit;
}

// Summary cards.
$total = $counts['all'];
$cards = [
    [coverage_service::STATUS_FULL, 'lom-cov-card-full', $counts[coverage_service::STATUS_FULL]],
    [coverage_service::STATUS_TAUGHT, 'lom-cov-card-partial', $counts[coverage_service::STATUS_TAUGHT]],
    [coverage_service::STATUS_NONE, 'lom-cov-card-none', $counts[coverage_service::STATUS_NONE]],
];
$cardhtml = '';
foreach ($cards as [$statusid, $cardclass, $value]) {
    $pct = $total ? round($value / $total * 100) : 0;
    $cardhtml .= html_writer::div(
        html_writer::div(get_string('coveragecard_' . $statusid, 'local_outcomemap'), 'lom-cov-card-label')
        . html_writer::div(
            html_writer::span($value, 'lom-cov-card-value')
                . html_writer::span(
                    get_string('coveragecardof_' . $statusid, 'local_outcomemap', $total),
                    'lom-cov-card-of'
                ),
            'lom-cov-card-figure'
        )
        . html_writer::div(
            html_writer::div('', 'lom-cov-bar-fill', ['style' => 'width:' . $pct . '%']),
            'lom-cov-bar'
        )
        . html_writer::div(get_string('coveragenote_' . $statusid, 'local_outcomemap'), 'lom-cov-card-note'),
        'lom-cov-card ' . $cardclass
    );
}
echo html_writer::div($cardhtml, 'lom-cov-cards');

// Filter chips and search, as one GET form so both survive a page load.
$chips = '';
foreach (['all', coverage_service::STATUS_NONE, coverage_service::STATUS_TAUGHT, coverage_service::STATUS_FULL] as $key) {
    $chipurl = new moodle_url($url, ['filter' => $key, 'q' => $search]);
    $chips .= html_writer::link(
        $chipurl,
        get_string('coveragefilter_' . $key, 'local_outcomemap')
            . html_writer::span($counts[$key], 'lom-cov-chip-count'),
        [
            'class' => 'lom-cov-filter' . ($filter === $key ? ' lom-cov-filter-active' : ''),
            'aria-current' => $filter === $key ? 'true' : null,
        ]
    );
}
$searchform = html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'lom-cov-search'])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter', 'value' => $filter])
    . html_writer::label(
        get_string('coverage_searchlabel', 'local_outcomemap'),
        'lom-cov-q',
        false,
        ['class' => 'sr-only visually-hidden']
    )
    . html_writer::empty_tag('input', [
        'type' => 'search',
        'id' => 'lom-cov-q',
        'name' => 'q',
        'value' => $search,
        'placeholder' => get_string('coverage_searchplaceholder', 'local_outcomemap'),
        'class' => 'form-control form-control-sm',
    ])
    . html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-sm btn-secondary'])
    . ($search !== '' ? html_writer::link(
        new moodle_url($url, ['filter' => $filter]),
        get_string('coverage_clearsearch', 'local_outcomemap'),
        ['class' => 'btn btn-sm btn-link']
    ) : '')
    . html_writer::end_tag('form');
echo html_writer::div(
    html_writer::div($chips, 'lom-cov-filters') . $searchform,
    'lom-cov-controls'
);

if (!$visible) {
    echo html_writer::div(get_string('coverage_nomatches', 'local_outcomemap'), 'lom-cov-empty');
    echo $OUTPUT->footer();
    exit;
}

// Group by framework: the data's own grouping, rather than a naming convention.
$groups = [];
foreach ($visible as $row) {
    $groups[$row->frameworkcode][] = $row;
}
$grouptotals = [];
foreach ($rows as $row) {
    $grouptotals[$row->frameworkcode]['total'] = ($grouptotals[$row->frameworkcode]['total'] ?? 0) + 1;
    if ($row->statusid === coverage_service::STATUS_FULL) {
        $grouptotals[$row->frameworkcode]['full'] = ($grouptotals[$row->frameworkcode]['full'] ?? 0) + 1;
    }
}

foreach ($groups as $frameworkcode => $grouprows) {
    $grouptotal = (int) ($grouptotals[$frameworkcode]['total'] ?? count($grouprows));
    $groupfull = (int) ($grouptotals[$frameworkcode]['full'] ?? 0);
    $pct = $grouptotal ? round($groupfull / $grouptotal * 100) : 0;
    $barclass = $pct === 100 ? 'lom-cov-bar-full' : ($pct >= 50 ? 'lom-cov-bar-partial' : 'lom-cov-bar-none');

    $summary = html_writer::span(s($frameworkcode), 'lom-cov-group-title')
        . html_writer::span(
            get_string('coveragegroup_sub', 'local_outcomemap', $grouptotal),
            'lom-cov-group-sub'
        )
        . html_writer::span(
            html_writer::div(
                html_writer::div('', 'lom-cov-bar-fill ' . $barclass, ['style' => 'width:' . $pct . '%']),
                'lom-cov-bar lom-cov-bar-inline'
            )
            . html_writer::span(
                get_string('coveragegroup_covered', 'local_outcomemap', [
                    'full' => $groupfull,
                    'total' => $grouptotal,
                ]),
                'lom-cov-group-count'
            ),
            'lom-cov-group-meta'
        );

    $head = html_writer::div(
        html_writer::span(get_string('outcomeversion', 'local_outcomemap'), 'lom-cov-c-code')
        . html_writer::span(get_string('statement', 'local_outcomemap'), 'lom-cov-c-statement')
        . html_writer::span(get_string('coveragestatus', 'local_outcomemap'), 'lom-cov-c-status')
        . html_writer::span(get_string('coverage_taughtin', 'local_outcomemap'), 'lom-cov-c-taught')
        . html_writer::span(get_string('coverage_assessedby', 'local_outcomemap'), 'lom-cov-c-assessed'),
        'lom-cov-row lom-cov-head'
    );

    $body = '';
    foreach ($grouprows as $row) {
        $taughthtml = '';
        foreach ($row->taught as $entry) {
            $taughthtml .= html_writer::div(
                format_string($entry->label)
                    . html_writer::span(
                        get_string('mappingrole_' . $entry->role, 'local_outcomemap')
                            . ' · ' . workflow::status_label($entry->status),
                        'lom-cov-meta'
                    ),
                'lom-cov-entry'
            );
        }
        if (!$row->taught) {
            $taughthtml = html_writer::span(get_string('coverage_nottaught', 'local_outcomemap'), 'lom-cov-missing');
        }
        $assessedhtml = '';
        foreach ($row->assessed as $entry) {
            $assessedhtml .= html_writer::div(
                format_string($entry->label)
                    . html_writer::span(workflow::status_label($entry->status), 'lom-cov-meta'),
                'lom-cov-entry'
            );
        }
        if (!$row->assessed) {
            $assessedhtml = has_capability('local/outcomemap:mapactivities', $context)
                ? html_writer::link(
                    new moodle_url('/local/outcomemap/contentmapping.php', ['courseid' => $courseid]),
                    get_string('coverage_mapcontent', 'local_outcomemap'),
                    ['class' => 'lom-cov-maplink']
                )
                : html_writer::span(get_string('coverage_notassessed', 'local_outcomemap'), 'lom-cov-missing');
        }

        $body .= html_writer::div(
            html_writer::span(
                html_writer::span(s($row->code), 'lom-cov-code')
                    . html_writer::span('v' . $row->version, 'lom-cov-ver'),
                'lom-cov-c-code'
            )
            . html_writer::span(
                highlight::mark($row->statement, $needle),
                'lom-cov-c-statement'
            )
            . html_writer::span(
                html_writer::span(
                    get_string('coveragestatus_' . $row->statusid, 'local_outcomemap'),
                    'lom-cov-badge ' . $statusmeta[$row->statusid]
                ),
                'lom-cov-c-status'
            )
            . html_writer::span($taughthtml, 'lom-cov-c-taught')
            . html_writer::span($assessedhtml, 'lom-cov-c-assessed'),
            'lom-cov-row'
        );
    }

    echo html_writer::tag(
        'details',
        html_writer::tag('summary', $summary, ['class' => 'lom-cov-group-head']) . $head . $body,
        ['class' => 'lom-cov-group', 'open' => 'open']
    );
}

echo $OUTPUT->footer();
