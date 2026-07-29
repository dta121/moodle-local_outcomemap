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
 * Course outcome attainment across the cohort.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\local\highlight;
use local_outcomemap\local\service\course_attainment_service;

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
$groupmode = optional_param('group', 'framework', PARAM_ALPHA);
$showpaths = optional_param('paths', 0, PARAM_BOOL);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
// Cohort attainment is other people's results, so this is the all-results
// capability rather than the definitions one the mapping pages use.
require_capability('local/outcomemap:viewallresults', $context);

$states = [
    course_attainment_service::STATE_ATTENTION,
    course_attainment_service::STATE_ATTAINED,
    course_attainment_service::STATE_PENDING,
    course_attainment_service::STATE_UNASSESSED,
];
if (!in_array($filter, array_merge(['all'], $states), true)) {
    $filter = 'all';
}
// Framework groups the outcomes as authored; the other two follow the approved
// alignment edges up one level and to the top, which is how a reader asking
// "how is the programme doing" arrives at unit evidence.
$groupmodes = ['framework', 'aligned', 'terminal'];
if (!in_array($groupmode, $groupmodes, true)) {
    $groupmode = 'framework';
}

$summary = course_attainment_service::summary($courseid);
$canmap = has_capability('local/outcomemap:mapcourse', $context)
    || has_capability('local/outcomemap:mapactivities', $context);

$alignmentpathtext = static function (stdClass $row): array {
    $labels = [];
    foreach ($row->alignmentpaths ?? [] as $path) {
        $targets = array_map(
            static fn($target): string => $target->frameworkcode . '.' . $target->code
                . ' — ' . $target->statement,
            $path->targets
        );
        $labels[] = get_string(
            $path->propagates ? 'attainment_evidencerollup' : 'attainment_alignmentonly',
            'local_outcomemap'
        ) . ': ' . implode(' -> ', $targets);
    }
    return $labels;
};

$needle = core_text::strtolower($search);
$visible = [];
foreach ($summary->rows as $row) {
    if ($needle !== '') {
        $alignmentsearch = [];
        foreach ($row->alignmentpaths ?? [] as $path) {
            foreach ($path->targets as $target) {
                $alignmentsearch[] = $target->frameworkcode . '.' . $target->code . ' ' . $target->statement;
            }
        }
        $haystack = core_text::strtolower(
            $row->frameworkcode . '.' . $row->code . ' ' . $row->statement
                . ' ' . implode(' ', $alignmentsearch)
        );
        if (core_text::strpos($haystack, $needle) === false) {
            continue;
        }
    }
    if ($filter !== 'all' && $row->state !== $filter) {
        continue;
    }
    $visible[] = $row;
}

$url = new moodle_url('/local/outcomemap/attainment.php', ['courseid' => $courseid]);
$viewparams = ['filter' => $filter, 'q' => $search, 'group' => $groupmode, 'paths' => $showpaths ? 1 : 0];

if ($action === 'export' && $visible) {
    require_once($CFG->libdir . '/csvlib.class.php');
    $exporter = new csv_export_writer();
    $exporter->set_filename(clean_filename($course->shortname . '-outcome-attainment'));
    $exporter->add_data([
        get_string('framework', 'local_outcomemap'),
        get_string('outcomeversion', 'local_outcomemap'),
        get_string('statement', 'local_outcomemap'),
        get_string('attainment_higheralignment', 'local_outcomemap'),
        get_string('attainment_state', 'local_outcomemap'),
        get_string('attainment_learners', 'local_outcomemap'),
        get_string('attainment_assessed', 'local_outcomemap'),
        get_string('attainment_cohort', 'local_outcomemap'),
        get_string('attainment_average', 'local_outcomemap'),
        get_string('attainment_banddistribution', 'local_outcomemap'),
    ]);
    foreach ($visible as $row) {
        $bands = [];
        foreach ($row->bands as $band) {
            $bands[] = $band->name . ': ' . $band->count;
        }
        $exporter->add_data([
            $row->frameworkcode,
            $row->code . ' v' . $row->version,
            $row->statement,
            implode('; ', $alignmentpathtext($row)),
            get_string('attainmentstate_' . $row->state, 'local_outcomemap'),
            $row->learners,
            $row->calculated,
            $summary->learners,
            $row->average === null ? '' : number_format($row->average, 2, '.', ''),
            implode('; ', $bands),
        ]);
    }
    $exporter->download_file();
    exit;
}

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('attainment_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

$actions = html_writer::link(
    new moodle_url('/local/outcomemap/coverage.php', ['courseid' => $courseid]),
    get_string('coverage_heading', 'local_outcomemap'),
    ['class' => 'btn btn-outline-secondary btn-sm']
);
if ($visible) {
    $actions .= html_writer::link(
        new moodle_url($url, $viewparams + ['action' => 'export']),
        get_string('coverage_exportcsv', 'local_outcomemap'),
        ['class' => 'btn btn-outline-secondary btn-sm']
    );
}
echo html_writer::div(
    html_writer::span(get_string('coverage_report', 'local_outcomemap'), 'lom-cov-toolbar-label')
        . html_writer::span(get_string('attainment_heading', 'local_outcomemap'), 'lom-cov-chip')
        . html_writer::div($actions, 'lom-cov-toolbar-actions'),
    'lom-cov-toolbar'
);
echo html_writer::tag('h2', get_string('attainment_heading', 'local_outcomemap'), ['class' => 'lom-cov-title']);
echo html_writer::div(
    get_string('attainment_subtitle', 'local_outcomemap', (object) [
        'learners' => $summary->learners,
        'periods' => $summary->periodcodes
            ? implode(', ', array_map('s', $summary->periodcodes))
            : '-',
    ]),
    'lom-cov-subtitle'
);

if (!$summary->hasinstance) {
    echo $OUTPUT->notification(get_string('nocourseinstance', 'local_outcomemap'),
        \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->footer();
    exit;
}

// An empty report names its own cause: the two conditions readers assume
// (mappings approved, assessments completed) are often both satisfied while a
// third one, the mapping's effective window, is what actually fails.
$diagnosis = static function (int $courseid): string {
    $why = course_attainment_service::diagnose($courseid);
    return html_writer::tag('h3', get_string('attainment_whyheading', 'local_outcomemap'),
            ['class' => 'lom-att-whytitle'])
        . html_writer::div(
            get_string('attainment_why_' . $why->cause, 'local_outcomemap', (object) [
                'mappings' => $why->mappings,
                'attempts' => $why->attempts,
                'inforce' => $why->inforceattempts,
                'from' => $why->firstmappingfrom === null ? '-' : userdate($why->firstmappingfrom),
                'finish' => $why->lastattemptfinish === null ? '-' : userdate($why->lastattemptfinish),
                'policies' => implode(', ', array_map(
                    fn(string $type): string => get_string('policytype_' . $type, 'local_outcomemap'),
                    $why->missingpolicies
                )),
            ]),
            'lom-att-why'
        );
};

if (!$summary->rows) {
    echo $OUTPUT->notification(get_string('attainment_noresults', 'local_outcomemap'),
        \core\output\notification::NOTIFY_INFO);
    echo $diagnosis($courseid);
    echo $OUTPUT->footer();
    exit;
}

// Summary cards: what is measured, how it stands, and what is blocking the rest.
$average = $summary->average === null ? '—' : number_format($summary->average, 1) . '%';
$cards = [
    [
        'label' => get_string('attainmentcard_measured', 'local_outcomemap'),
        'value' => $summary->measured,
        'of' => get_string('attainmentcardof_measured', 'local_outcomemap', $summary->outcomes),
        'note' => get_string('attainmentcardnote_measured', 'local_outcomemap'),
        'class' => $summary->measured === $summary->outcomes ? 'lom-cov-card-full' : '',
    ],
    [
        'label' => get_string('attainmentcard_average', 'local_outcomemap'),
        'value' => $average,
        'of' => $summary->measured
            ? get_string('attainmentcardof_average', 'local_outcomemap', $summary->measured)
            : '',
        'note' => get_string('attainmentcardnote_average', 'local_outcomemap'),
        // Nothing measured means nothing to colour: a green dash would read as
        // a clean result rather than an absent one.
        'class' => $summary->measured === 0 ? ''
            : ($summary->counts[course_attainment_service::STATE_ATTENTION]
                ? 'lom-cov-card-partial' : 'lom-cov-card-full'),
    ],
    [
        'label' => get_string('attainmentcard_pending', 'local_outcomemap'),
        'value' => $summary->counts[course_attainment_service::STATE_PENDING],
        'of' => get_string('attainmentcardof_pending', 'local_outcomemap'),
        'note' => get_string('attainmentcardnote_pending', 'local_outcomemap'),
        'class' => $summary->counts[course_attainment_service::STATE_PENDING]
            ? 'lom-cov-card-partial' : 'lom-cov-card-full',
    ],
    [
        'label' => get_string('attainmentcard_unassessed', 'local_outcomemap'),
        'value' => $summary->coverageknown
            ? $summary->counts[course_attainment_service::STATE_UNASSESSED]
            : '?',
        'of' => get_string('attainmentcardof_unassessed', 'local_outcomemap'),
        'note' => get_string(
            $summary->coverageknown
                ? 'attainmentcardnote_unassessed'
                : 'attainmentcardnote_unknowncoverage',
            'local_outcomemap'
        ),
        'class' => $summary->counts[course_attainment_service::STATE_UNASSESSED]
            ? 'lom-cov-card-none' : 'lom-cov-card-full',
    ],
];
$cardhtml = '';
foreach ($cards as $card) {
    $cardhtml .= html_writer::div(
        html_writer::div($card['label'], 'lom-cov-card-label')
        . html_writer::div(
            html_writer::span($card['value'], 'lom-cov-card-value')
                . html_writer::span($card['of'], 'lom-cov-card-of'),
            'lom-cov-card-figure'
        )
        . html_writer::div($card['note'], 'lom-cov-card-note'),
        trim('lom-cov-card ' . $card['class'])
    );
}
echo html_writer::div($cardhtml, 'lom-cov-cards lom-att-cards');

if ($summary->measured === 0) {
    echo $OUTPUT->notification(get_string('attainment_noresults', 'local_outcomemap'),
        \core\output\notification::NOTIFY_INFO);
    echo $diagnosis($courseid);
}

// Group-by control, then the filter chips, search, and alignment-path toggle.
// Two of the three groupings read the alignment edges, so a course with none is
// offered the framework view alone rather than two empty tabs.
if ($summary->hasalignmentpaths) {
    $groupbuttons = '';
    foreach ($groupmodes as $mode) {
        $groupbuttons .= html_writer::link(
            new moodle_url($url, ['group' => $mode, 'filter' => $filter, 'q' => $search,
                'paths' => $showpaths ? 1 : 0]),
            get_string('attainmentgroup_' . $mode, 'local_outcomemap'),
            [
                'class' => 'lom-att-modebtn' . ($groupmode === $mode ? ' lom-att-modebtn-active' : ''),
                'aria-current' => $groupmode === $mode ? 'true' : null,
            ]
        );
    }
    echo html_writer::div(
        html_writer::span(get_string('attainment_groupby', 'local_outcomemap'), 'lom-cov-toolbar-label')
            . html_writer::div($groupbuttons, 'lom-att-modes')
            . html_writer::span(
                get_string('attainmentgroupsub_' . $groupmode, 'local_outcomemap'),
                'lom-att-modesub'
            ),
        'lom-att-modebar'
    );
} else {
    $groupmode = 'framework';
}

$chips = '';
foreach (array_merge(['all'], $states) as $key) {
    $count = $key === 'all' ? $summary->outcomes : $summary->counts[$key];
    $chips .= html_writer::link(
        new moodle_url($url, ['filter' => $key, 'q' => $search, 'group' => $groupmode,
            'paths' => $showpaths ? 1 : 0]),
        get_string('attainmentfilter_' . $key, 'local_outcomemap')
            . html_writer::span($count, 'lom-cov-chip-count'),
        [
            'class' => 'lom-cov-filter' . ($filter === $key ? ' lom-cov-filter-active' : ''),
            'aria-current' => $filter === $key ? 'true' : null,
        ]
    );
}
$searchform = html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $url->out_omit_querystring(),
        'class' => 'lom-cov-search',
    ])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter', 'value' => $filter])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'group', 'value' => $groupmode])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'paths', 'value' => $showpaths ? 1 : 0])
    . html_writer::label(get_string('attainment_searchlabel', 'local_outcomemap'), 'lom-att-q',
        false, ['class' => 'sr-only visually-hidden'])
    . html_writer::empty_tag('input', [
        'type' => 'search',
        'id' => 'lom-att-q',
        'name' => 'q',
        'value' => $search,
        'placeholder' => get_string('coverage_searchplaceholder', 'local_outcomemap'),
        'class' => 'form-control form-control-sm',
    ])
    . html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-sm btn-secondary'])
    . ($search !== '' ? html_writer::link(
        new moodle_url($url, ['filter' => $filter, 'group' => $groupmode, 'paths' => $showpaths ? 1 : 0]),
        get_string('coverage_clearsearch', 'local_outcomemap'), ['class' => 'btn btn-sm btn-link']) : '')
    . html_writer::end_tag('form');
$pathstoggle = $summary->hasalignmentpaths ? html_writer::link(
    new moodle_url($url, ['filter' => $filter, 'q' => $search, 'group' => $groupmode,
        'paths' => $showpaths ? 0 : 1]),
    html_writer::span($showpaths ? '☑' : '☐', 'lom-att-toggle-box')
        . get_string('attainment_showpaths', 'local_outcomemap'),
    [
        'class' => 'lom-att-toggle' . ($showpaths ? ' lom-att-toggle-on' : ''),
        'aria-pressed' => $showpaths ? 'true' : 'false',
    ]
) : '';
echo html_writer::div(
    html_writer::div($chips, 'lom-cov-filters') . $searchform . $pathstoggle,
    'lom-cov-controls'
);

if ($showpaths && $summary->hasalignmentpaths) {
    echo html_writer::div(
        get_string('attainment_alignmentnote', 'local_outcomemap'),
        'lom-att-alignment-note'
    );
}

if (!$visible) {
    echo html_writer::div(get_string('coverage_nomatches', 'local_outcomemap'), 'lom-cov-empty');
    echo $OUTPUT->footer();
    exit;
}

// One group per framework, or per higher-level outcome the rows align to. An
// outcome supporting two higher-level outcomes is reported under both, because
// each of those outcomes is answered by this evidence.
$groups = [];
$addrow = static function (string $key, string $title, string $sub, string $sort, stdClass $row) use (&$groups) {
    $groups[$key] ??= (object) ['title' => $title, 'sub' => $sub, 'sort' => $sort, 'rows' => []];
    $groups[$key]->rows[] = $row;
};
foreach ($visible as $row) {
    if ($groupmode === 'framework') {
        $addrow(
            'fw:' . $row->frameworkcode,
            $row->frameworkcode,
            (string) $row->frameworkname,
            $row->frameworkcode,
            $row
        );
        continue;
    }
    $targets = [];
    foreach ($row->alignmentpaths as $path) {
        if (!$path->targets) {
            continue;
        }
        // One level up for the aligned view; the end of the chain for the top view.
        $target = $groupmode === 'aligned'
            ? $path->targets[0]
            : $path->targets[count($path->targets) - 1];
        $targets[$target->itemid] = $target;
    }
    if (!$targets) {
        // Sorted last: an outcome that answers no higher-level outcome is a
        // curriculum gap, not the lead finding of an attainment report.
        $addrow('none', get_string('attainment_groupunaligned', 'local_outcomemap'), '', "\xff", $row);
        continue;
    }
    foreach ($targets as $target) {
        $label = $target->frameworkcode . '.' . $target->code;
        $addrow(
            't:' . $target->itemid,
            $label . ' — ' . ($target->shortstatement ?: shorten_text($target->statement, 90)),
            (string) $target->frameworkname,
            $label,
            $row
        );
    }
}
uasort($groups, static fn(stdClass $a, stdClass $b): int => strnatcasecmp($a->sort, $b->sort));

foreach ($groups as $group) {
    $measured = array_values(array_filter($group->rows, static fn($r): bool => (bool) $r->calculated));
    $attention = array_filter(
        $group->rows,
        static fn($r): bool => $r->state === course_attainment_service::STATE_ATTENTION
    );
    $groupaverage = $measured
        ? array_sum(array_map(static fn($r): float => (float) $r->average, $measured)) / count($measured)
        : null;

    $subparts = array_filter([
        $group->sub,
        get_string('attainment_groupsub', 'local_outcomemap', count($group->rows)),
    ], static fn(string $part): bool => $part !== '');
    $summaryhtml = html_writer::span(s($group->title), 'lom-cov-group-title')
        . html_writer::span(s(implode(' · ', $subparts)), 'lom-cov-group-sub')
        . html_writer::span(
            ($groupaverage === null ? '' : html_writer::span(
                get_string('attainment_groupaverage', 'local_outcomemap',
                    number_format($groupaverage, 1)),
                'lom-att-group-avg ' . ($attention ? 'lom-att-warn' : 'lom-att-ok')
            ))
            . html_writer::span(
                $measured
                    ? get_string('attainment_groupmeasured', 'local_outcomemap', (object) [
                        'measured' => count($measured),
                        'total' => count($group->rows),
                    ])
                    : get_string('attainment_groupnoresults', 'local_outcomemap'),
                'lom-cov-group-count'
            ),
            'lom-cov-group-meta'
        );

    $head = html_writer::div(
        html_writer::span(get_string('outcomeversion', 'local_outcomemap'), 'lom-att-c-code')
        . html_writer::span(get_string('statement', 'local_outcomemap'), 'lom-att-c-statement')
        . html_writer::span(get_string('attainment_assessed', 'local_outcomemap'), 'lom-att-c-n')
        . html_writer::span(get_string('attainment_result', 'local_outcomemap'), 'lom-att-c-result'),
        'lom-cov-row lom-cov-head'
    );

    $body = '';
    foreach ($group->rows as $row) {
        // Framework groups already name the framework; the alignment views do not.
        $codelabel = $groupmode === 'framework'
            ? $row->code
            : $row->frameworkcode . '.' . $row->code;

        $supportshtml = '';
        if ($showpaths) {
            foreach ($row->alignmentpaths as $path) {
                $pathchips = [];
                foreach ($path->targets as $target) {
                    $pathchips[] = html_writer::span(
                        s($target->frameworkcode . '.' . $target->code),
                        'lom-att-chip ' . ($path->propagates
                            ? 'lom-att-chip-rollup' : 'lom-att-chip-alignment'),
                        ['title' => $target->statement]
                    );
                }
                $supportshtml .= html_writer::span(
                    html_writer::span(
                        get_string(
                            $path->propagates
                                ? 'attainment_evidencerollup' : 'attainment_alignmentonly',
                            'local_outcomemap'
                        ),
                        'lom-att-supports-label'
                    ) . implode(html_writer::span('→', 'lom-att-chip-arrow'), $pathchips),
                    'lom-att-supports'
                );
            }
        }

        if ($row->calculated) {
            $bar = '';
            foreach ($row->bands as $index => $band) {
                $pct = $band->count / $row->calculated * 100;
                // The first band in sort order is the lowest, so colour it as
                // the one needing attention and the last as the strongest.
                $class = $index === 0 ? 'lom-att-seg-low' : ($index === count($row->bands) - 1
                    ? 'lom-att-seg-high' : 'lom-att-seg-mid');
                $bar .= html_writer::span('', 'lom-att-seg ' . $class, [
                    'style' => 'width:' . round($pct, 2) . '%',
                    'title' => s($band->name) . ': ' . $band->count,
                ]);
            }
            $legend = '';
            foreach ($row->bands as $index => $band) {
                $class = $index === 0 ? 'lom-att-seg-low' : ($index === count($row->bands) - 1
                    ? 'lom-att-seg-high' : 'lom-att-seg-mid');
                $legend .= html_writer::span(
                    html_writer::span('', 'lom-att-swatch ' . $class)
                        . s($band->name) . ' ' . $band->count,
                    'lom-att-legend-item'
                );
            }
            $resulthtml = html_writer::span(
                    html_writer::span(
                        number_format($row->average, 1) . '%',
                        'lom-att-avg ' . ($row->state === course_attainment_service::STATE_ATTENTION
                            ? 'lom-att-warn' : 'lom-att-ok')
                    ) . html_writer::span(get_string('attainment_average', 'local_outcomemap'),
                        'lom-att-avg-label'),
                    'lom-att-avg-line'
                )
                . html_writer::span($bar, 'lom-att-bar')
                . html_writer::span($legend, 'lom-att-legend');
        } else {
            $known = $summary->coverageknown;
            $reasonkey = !$known
                ? 'attainment_nonecalculated'
                : 'attainmentreason_' . $row->state;
            $reasonclass = !$known
                ? 'lom-att-reason-neutral'
                : ($row->state === course_attainment_service::STATE_UNASSESSED
                    ? 'lom-att-reason-none' : 'lom-att-reason-pending');
            $resulthtml = html_writer::span(
                get_string($reasonkey, 'local_outcomemap'),
                'lom-att-reason ' . $reasonclass
            );
            if ($canmap && $row->state === course_attainment_service::STATE_UNASSESSED && $known) {
                $resulthtml .= html_writer::link(
                    new moodle_url('/local/outcomemap/contentmapping.php', ['courseid' => $courseid]),
                    get_string('attainment_mapactivity', 'local_outcomemap'),
                    ['class' => 'lom-att-fix']
                );
            }
        }

        $body .= html_writer::div(
            html_writer::span(
                html_writer::span(s($codelabel), 'lom-cov-code')
                    . html_writer::span('v' . $row->version, 'lom-cov-ver'),
                'lom-att-c-code'
            )
            . html_writer::span(
                html_writer::span(
                    highlight::mark($row->shortstatement ?: $row->statement, $needle),
                    'lom-att-statement-text'
                ) . $supportshtml,
                'lom-att-c-statement'
            )
            . html_writer::span(
                html_writer::span(
                    $row->calculated . ' / ' . $summary->learners,
                    'lom-att-n-value' . ($row->calculated ? '' : ' lom-att-n-zero')
                ) . html_writer::span(get_string('attainment_assessed', 'local_outcomemap'),
                    'lom-att-n-label'),
                'lom-att-c-n'
            )
            . html_writer::span($resulthtml, 'lom-att-c-result'),
            'lom-cov-row'
        );
    }

    echo html_writer::tag(
        'details',
        html_writer::tag('summary', $summaryhtml, ['class' => 'lom-cov-group-head']) . $head . $body,
        ['class' => 'lom-cov-group', 'open' => 'open']
    );
}

echo html_writer::div(get_string('attainment_note', 'local_outcomemap'), 'lom-cov-subtitle');
echo $OUTPUT->footer();
