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
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
// Cohort attainment is other people's results, so this is the all-results
// capability rather than the definitions one the mapping pages use.
require_capability('local/outcomemap:viewallresults', $context);

if (!in_array($filter, ['all', 'attention', 'strong', 'unassessed'], true)) {
    $filter = 'all';
}

$summary = course_attainment_service::summary($courseid);

$needle = core_text::strtolower($search);
$visible = [];
foreach ($summary->rows as $row) {
    $label = $row->frameworkcode . '.' . $row->code;
    if ($needle !== '') {
        $haystack = core_text::strtolower($label . ' ' . $row->statement);
        if (core_text::strpos($haystack, $needle) === false) {
            continue;
        }
    }
    // "Attention" is the share of assessed learners sitting in the lowest band;
    // half is an arbitrary display threshold, not a governed one.
    $lowshare = $row->calculated && $row->lowestband
        ? $row->lowestband->count / $row->calculated
        : 0.0;
    $row->lowshare = $lowshare;
    if ($filter === 'attention' && !($row->calculated && $lowshare >= 0.5)) {
        continue;
    }
    if ($filter === 'strong' && !($row->calculated && $lowshare < 0.5)) {
        continue;
    }
    if ($filter === 'unassessed' && $row->calculated) {
        continue;
    }
    $visible[] = $row;
}

$counts = ['all' => 0, 'attention' => 0, 'strong' => 0, 'unassessed' => 0];
foreach ($summary->rows as $row) {
    $counts['all']++;
    $share = $row->calculated && $row->lowestband ? $row->lowestband->count / $row->calculated : 0.0;
    if (!$row->calculated) {
        $counts['unassessed']++;
    } else if ($share >= 0.5) {
        $counts['attention']++;
    } else {
        $counts['strong']++;
    }
}

$url = new moodle_url('/local/outcomemap/attainment.php', ['courseid' => $courseid]);

if ($action === 'export' && $visible) {
    require_once($CFG->libdir . '/csvlib.class.php');
    $exporter = new csv_export_writer();
    $exporter->set_filename(clean_filename($course->shortname . '-outcome-attainment'));
    $exporter->add_data([
        get_string('framework', 'local_outcomemap'),
        get_string('outcomeversion', 'local_outcomemap'),
        get_string('statement', 'local_outcomemap'),
        get_string('attainment_learners', 'local_outcomemap'),
        get_string('attainment_assessed', 'local_outcomemap'),
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
            $row->learners,
            $row->calculated,
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
        new moodle_url($url, ['action' => 'export', 'filter' => $filter, 'q' => $search]),
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
if (!$summary->rows) {
    // An empty page names its own cause: the two conditions readers assume
    // (mappings approved, assessments completed) are often both satisfied while
    // a third one, the mapping's effective window, is what actually fails.
    $why = course_attainment_service::diagnose($courseid);
    echo $OUTPUT->notification(get_string('attainment_noresults', 'local_outcomemap'),
        \core\output\notification::NOTIFY_INFO);
    echo html_writer::tag('h3', get_string('attainment_whyheading', 'local_outcomemap'),
        ['class' => 'lom-cov-title']);
    echo html_writer::div(
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
        'lom-cov-subtitle'
    );
    echo $OUTPUT->footer();
    exit;
}

// Filter chips and search, as one GET form so both survive a page load.
$chips = '';
foreach (['all', 'attention', 'strong', 'unassessed'] as $key) {
    $chips .= html_writer::link(
        new moodle_url($url, ['filter' => $key, 'q' => $search]),
        get_string('attainmentfilter_' . $key, 'local_outcomemap')
            . html_writer::span($counts[$key], 'lom-cov-chip-count'),
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
    . ($search !== '' ? html_writer::link(new moodle_url($url, ['filter' => $filter]),
        get_string('coverage_clearsearch', 'local_outcomemap'), ['class' => 'btn btn-sm btn-link']) : '')
    . html_writer::end_tag('form');
echo html_writer::div(html_writer::div($chips, 'lom-cov-filters') . $searchform, 'lom-cov-controls');

if (!$visible) {
    echo html_writer::div(get_string('coverage_nomatches', 'local_outcomemap'), 'lom-cov-empty');
    echo $OUTPUT->footer();
    exit;
}

// Group by framework so unit, course, and programme outcomes read as levels.
$groups = [];
foreach ($visible as $row) {
    $groups[$row->frameworkcode][] = $row;
}

foreach ($groups as $frameworkcode => $grouprows) {
    $summaryhtml = html_writer::span(s($frameworkcode), 'lom-cov-group-title')
        . html_writer::span(
            get_string('attainment_groupsub', 'local_outcomemap', count($grouprows)),
            'lom-cov-group-sub'
        );

    $head = html_writer::div(
        html_writer::span(get_string('outcomeversion', 'local_outcomemap'), 'lom-att-c-code')
        . html_writer::span(get_string('statement', 'local_outcomemap'), 'lom-att-c-statement')
        . html_writer::span(get_string('attainment_assessed', 'local_outcomemap'), 'lom-att-c-n')
        . html_writer::span(get_string('attainment_average', 'local_outcomemap'), 'lom-att-c-avg')
        . html_writer::span(get_string('attainment_banddistribution', 'local_outcomemap'), 'lom-att-c-bands'),
        'lom-cov-row lom-cov-head'
    );

    $body = '';
    foreach ($grouprows as $row) {
        $bandhtml = '';
        if (!$row->calculated) {
            $bandhtml = html_writer::span(
                get_string('attainment_nonecalculated', 'local_outcomemap'),
                'lom-cov-missing'
            );
        } else {
            $bar = '';
            foreach ($row->bands as $index => $band) {
                $pct = $band->count / $row->calculated * 100;
                // The first band in sort order is the lowest, so colour it as
                // the one needing attention and the last as the strongest.
                $class = $index === 0 ? 'lom-att-seg-low' : ($index === count($row->bands) - 1
                    ? 'lom-att-seg-high' : 'lom-att-seg-mid');
                $bar .= html_writer::div('', 'lom-att-seg ' . $class, [
                    'style' => 'width:' . round($pct, 2) . '%',
                    'title' => s($band->name) . ': ' . $band->count,
                ]);
            }
            $legend = [];
            foreach ($row->bands as $band) {
                $legend[] = s($band->name) . ' ' . $band->count;
            }
            $bandhtml = html_writer::div($bar, 'lom-att-bar')
                . html_writer::span(implode(' · ', $legend), 'lom-cov-meta');
        }

        $body .= html_writer::div(
            html_writer::span(
                html_writer::span(s($row->code), 'lom-cov-code')
                    . html_writer::span('v' . $row->version, 'lom-cov-ver'),
                'lom-att-c-code'
            )
            . html_writer::span(
                s($row->shortstatement ?: shorten_text($row->statement, 110)),
                'lom-att-c-statement'
            )
            . html_writer::span(
                $row->calculated . ' / ' . $row->learners,
                'lom-att-c-n'
            )
            . html_writer::span(
                $row->average === null
                    ? html_writer::span('—', 'lom-cov-missing')
                    : html_writer::span(number_format($row->average, 1) . '%', 'lom-att-avg'),
                'lom-att-c-avg'
            )
            . html_writer::span($bandhtml, 'lom-att-c-bands'),
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
