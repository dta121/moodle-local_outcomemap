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

use local_outcomemap\local\csv_safety;
use local_outcomemap\local\highlight;
use local_outcomemap\local\service\attainment_report_service as report_service;
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
$view = optional_param('view', report_service::VIEW_SUMMARY, PARAM_ALPHA);
$cohort = optional_param('cohort', report_service::COHORT_ALL, PARAM_ALPHA);
$lens = optional_param('lens', report_service::LENS_EDUCATOR, PARAM_ALPHA);
$search = trim(optional_param('q', '', PARAM_TEXT));
$traceid = optional_param('trace', 0, PARAM_INT);
$detailid = optional_param('detail', 0, PARAM_INT);
$sheets = optional_param('sheets', 0, PARAM_BOOL);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
// Cohort attainment is other people's results, so this is the all-results
// capability rather than the definitions one the mapping pages use.
require_capability('local/outcomemap:viewallresults', $context);

if (!in_array($view, report_service::VIEWS, true)) {
    $view = report_service::VIEW_SUMMARY;
}

$report = report_service::report($courseid, $cohort, $lens);
// The service refuses a cohort or lens the data cannot honestly support, so read
// the selections back rather than trusting the request.
$cohort = $report->hasinstance ? $report->cohort : report_service::COHORT_ALL;
$lens = $report->hasinstance ? $report->lens : report_service::LENS_EDUCATOR;

// Views the data can fill. Resolved before any link is built, so a link can
// never carry a view this course would silently swap out from under the reader.
$viewoptions = [];
foreach (report_service::VIEWS as $key) {
    if (!$report->hasinstance) {
        break;
    }
    if ($key === report_service::VIEW_ROLLUP && !$report->rollup->available) {
        continue;
    }
    if ($key === report_service::VIEW_MAP && count($report->tiers) < 2) {
        continue;
    }
    $viewoptions[$key] = get_string('oa_view_' . $key, 'local_outcomemap');
}
if (!isset($viewoptions[$view])) {
    $view = report_service::VIEW_SUMMARY;
}

$url = new moodle_url('/local/outcomemap/attainment.php', ['courseid' => $courseid]);
$state = ['view' => $view, 'cohort' => $cohort, 'lens' => $lens, 'q' => $search];
/**
 * Build a link back to this page with some of the view state replaced.
 *
 * @param array $overrides Parameters to change.
 * @return moodle_url
 */
$link = static function (array $overrides = []) use ($url, $state, $traceid): moodle_url {
    $params = $state;
    if ($traceid) {
        $params['trace'] = $traceid;
    }
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '' || $value === 0) {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }
    return new moodle_url($url, $params);
};

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('attainment_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

// ---------------------------------------------------------------------------
// States in which there is nothing to report, each naming its own cause.
// ---------------------------------------------------------------------------

if (!$report->hasinstance) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nocourseinstance', 'local_outcomemap'),
        \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->footer();
    exit;
}

/**
 * Explain an empty report by naming the gate the evidence pipeline stopped at.
 *
 * The two conditions readers assume — mappings approved, assessments completed —
 * are often both satisfied while a third, the mapping's effective window, is
 * what actually fails.
 *
 * @param int $courseid Moodle course ID.
 * @return string
 */
$diagnosis = static function (int $courseid): string {
    $why = course_attainment_service::diagnose($courseid);
    return html_writer::tag('h3', get_string('attainment_whyheading', 'local_outcomemap'),
            ['class' => 'lom-oa-why-title'])
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
            'lom-oa-why'
        );
};

if (!$report->tiers) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('attainment_noresults', 'local_outcomemap'),
        \core\output\notification::NOTIFY_INFO);
    echo $diagnosis($courseid);
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
// Shared vocabulary. Every figure on the page passes through these, so a
// withheld result cannot leak into one view because a caller forgot to check.
// ---------------------------------------------------------------------------

$policy = $report->policy;
$target = $policy->target;
$needle = core_text::strtolower($search);
$nodes = $report->nodes;

/**
 * Name one level, preferring the frameworks that make it up.
 *
 * @param stdClass $tier Level.
 * @return string
 */
$tiername = static function (stdClass $tier): string {
    if (count($tier->frameworks) === 1) {
        return (string) reset($tier->frameworks);
    }
    $owners = array_map(
        static fn(string $type): string => get_string('oa_level_' . $type, 'local_outcomemap'),
        $tier->ownertypes
    );
    return implode(' · ', $owners);
};

/**
 * Short code line for a level, used where the full name will not fit.
 *
 * @param stdClass $tier Level.
 * @return string
 */
$tiercodes = static function (stdClass $tier): string {
    return implode(' · ', array_keys($tier->frameworks));
};

/**
 * Whether a figure must be withheld rather than shown with a caveat.
 *
 * @param stdClass $stats Tally.
 * @return bool
 */
$withheld = static fn(stdClass $stats): bool => report_service::is_withheld($report, $stats);

/**
 * Whether a figure rests on fewer learners than the governing floor allows.
 *
 * @param stdClass $stats Tally.
 * @return bool
 */
$isthin = static fn(stdClass $stats): bool => $policy->floor !== null
    && $stats->graded > 0 && $stats->graded < $policy->floor;

/**
 * The attainment rate as it may be shown under the current lens.
 *
 * @param stdClass $stats Tally.
 * @return string
 */
$metlabel = static function (stdClass $stats) use ($withheld): string {
    if ($withheld($stats)) {
        return get_string('oa_withheld', 'local_outcomemap');
    }
    return $stats->metpct === null ? '—' : number_format($stats->metpct, 1) . '%';
};

/**
 * Colour class for an attainment rate against the governing benchmark.
 *
 * @param stdClass $stats Tally.
 * @return string
 */
$metclass = static function (stdClass $stats) use ($target, $withheld): string {
    if ($withheld($stats) || $stats->metpct === null) {
        return 'lom-oa-quiet';
    }
    if ($target === null) {
        return 'lom-oa-plain';
    }
    return $stats->metpct + 0.05 >= $target ? 'lom-oa-ok' : 'lom-oa-below';
};

/**
 * Render the band spread as a single stacked bar.
 *
 * Bands run strongest first, so the share that reached the standard grows from
 * the left and the benchmark marker reads as a finishing line.
 *
 * @param stdClass $stats Tally.
 * @param string $extra Extra class on the bar.
 * @return string
 */
$bar = static function (stdClass $stats, string $extra = '') use ($target, $withheld, $policy): string {
    $segments = '';
    if ($withheld($stats)) {
        $segments = html_writer::span('', 'lom-oa-seg lom-oa-seg-empty', [
            'style' => 'width:100%',
            'title' => get_string('oa_withheldbar', 'local_outcomemap', $policy->floor),
        ]);
    } else if (!$stats->graded || !$stats->bands) {
        $segments = html_writer::span('', 'lom-oa-seg lom-oa-seg-empty', [
            'style' => 'width:100%',
            'title' => get_string('oa_notmeasured', 'local_outcomemap'),
        ]);
    } else {
        // Strongest band first, so the share that reached the standard grows from
        // the left and the benchmark marker reads as a finishing line.
        foreach (array_reverse($stats->bands) as $band) {
            // html_writer escapes attribute values, so the raw name goes in here.
            $segments .= html_writer::span('', 'lom-oa-seg lom-oa-seg-' . $band->rank, [
                'style' => 'width:' . round($band->count / $stats->graded * 100, 2) . '%',
                'title' => $band->name . ': ' . $band->count,
            ]);
        }
    }
    $marker = $target === null ? '' : html_writer::span('', 'lom-oa-target', [
        'style' => 'left:' . round($target, 2) . '%',
        'title' => get_string('oa_targetmarker', 'local_outcomemap', report_service::pct($target)),
    ]);
    return html_writer::span($segments . $marker, trim('lom-oa-bar ' . $extra));
};

/**
 * One-line description of where the learners landed.
 *
 * @param stdClass $stats Tally.
 * @return string
 */
$bandline = static function (stdClass $stats) use ($withheld, $policy): string {
    if ($withheld($stats)) {
        return get_string('oa_bandwithheld', 'local_outcomemap', (object) [
            'graded' => $stats->graded, 'floor' => $policy->floor,
        ]);
    }
    if (!$stats->graded) {
        return get_string('oa_notmeasured', 'local_outcomemap');
    }
    $parts = [];
    foreach (array_reverse($stats->bands) as $band) {
        $parts[] = $band->count . ' ' . s(core_text::strtolower($band->name));
    }
    return $parts ? implode(' · ', $parts) : get_string('oa_nobands', 'local_outcomemap');
};

/**
 * Diagnostic tags for one outcome.
 *
 * These are internal judgements rather than evidence, so the accreditation lens
 * keeps only the two that describe the evidence itself.
 *
 * @param stdClass $node Outcome node.
 * @return string
 */
$flags = static function (stdClass $node) use ($report, $cohort, $target, $policy, $isthin, $withheld): string {
    $stats = $node->stats[$cohort];
    $tags = [];
    if (!$stats->graded) {
        $tags[] = [$node->assessedcontent === false
            ? get_string('oa_flag_unassessed', 'local_outcomemap')
            : get_string('oa_flag_pending', 'local_outcomemap'), 'lom-oa-tag-quiet'];
    } else if ($isthin($stats)) {
        $tags[] = [get_string('oa_flag_thin', 'local_outcomemap', (object) [
            'graded' => $stats->graded, 'floor' => $policy->floor,
        ]), 'lom-oa-tag-accent'];
    }
    if ($withheld($stats)) {
        $tags[] = [get_string('oa_flag_withheld', 'local_outcomemap'), 'lom-oa-tag-outline'];
    }
    if ($target !== null && $stats->metpct !== null && !$withheld($stats)
            && $stats->metpct + 0.05 < $target) {
        $tags[] = [get_string('oa_flag_belowtarget', 'local_outcomemap'), 'lom-oa-tag-outline'];
    }
    if ($report->lens !== report_service::LENS_ACCREDITATION && $report->cohortrule !== null) {
        $done = $node->stats[report_service::COHORT_COMPLETED];
        $notdone = $node->stats[report_service::COHORT_NOTCOMPLETED];
        if ($target !== null && $done->metpct !== null && $done->judged > 0
                && $done->metpct + 0.05 < $target) {
            $tags[] = [get_string('oa_flag_completersshort', 'local_outcomemap'), 'lom-oa-tag-accent'];
        }
        if ($done->judged > 0 && $notdone->judged > 0
                && abs($done->metpct - $notdone->metpct) < report_service::ALIKE_SPREAD) {
            $tags[] = [get_string('oa_flag_alike', 'local_outcomemap'), 'lom-oa-tag-accent2'];
        }
    }
    $html = '';
    foreach ($tags as [$label, $class]) {
        $html .= html_writer::span($label, 'lom-oa-tag ' . $class);
    }
    return $html ? html_writer::div($html, 'lom-oa-tags') : '';
};

/**
 * The statement to show, highlighted when it answers the search.
 *
 * @param stdClass $node Outcome node.
 * @return string
 */
$statement = static fn(stdClass $node): string
    => highlight::mark($node->shortstatement ?: $node->statement, $needle);

/**
 * Whether one outcome answers the current search.
 *
 * @param stdClass $node Outcome node.
 * @return bool
 */
$matches = static function (stdClass $node) use ($needle): bool {
    if ($needle === '') {
        return true;
    }
    $haystack = core_text::strtolower(
        $node->frameworkcode . '.' . $node->code . ' ' . $node->statement . ' ' . $node->shortstatement
    );
    return core_text::strpos($haystack, $needle) !== false;
};

/**
 * Whether an outcome or anything underneath it answers the search.
 *
 * @param int $itemid Outcome item ID.
 * @return bool
 */
$branchmatches = static function (int $itemid) use (&$branchmatches, $nodes, $matches): bool {
    if (!isset($nodes[$itemid])) {
        return false;
    }
    if ($matches($nodes[$itemid])) {
        return true;
    }
    foreach ($nodes[$itemid]->children as $childid) {
        if ($branchmatches($childid)) {
            return true;
        }
    }
    return false;
};

// ---------------------------------------------------------------------------
// CSV export: the same figures, per level, with the cohort split kept intact.
// ---------------------------------------------------------------------------

if ($action === 'export') {
    require_once($CFG->libdir . '/csvlib.class.php');
    $exporter = new csv_export_writer();
    $exporter->set_filename(clean_filename($course->shortname . '-outcome-attainment'));
    $header = [
        get_string('oa_col_level', 'local_outcomemap'),
        get_string('framework', 'local_outcomemap'),
        get_string('outcomeversion', 'local_outcomemap'),
        get_string('statement', 'local_outcomemap'),
        get_string('attainment_state', 'local_outcomemap'),
        get_string('oa_col_graded', 'local_outcomemap'),
        get_string('oa_col_judged', 'local_outcomemap'),
        get_string('oa_col_met', 'local_outcomemap'),
        get_string('oa_col_metpct', 'local_outcomemap'),
        get_string('oa_col_mean', 'local_outcomemap'),
        get_string('attainment_banddistribution', 'local_outcomemap'),
    ];
    if ($report->cohortrule !== null) {
        $header[] = get_string('oa_col_completedpct', 'local_outcomemap');
        $header[] = get_string('oa_col_notcompletedpct', 'local_outcomemap');
    }
    $exporter->add_data($header);
    foreach ($report->tiers as $tier) {
        foreach ($tier->nodes as $node) {
            $stats = $node->stats[$cohort];
            $bands = [];
            foreach ($stats->bands as $band) {
                $bands[] = $band->name . ': ' . $band->count;
            }
            $row = [
                $tiername($tier),
                $node->frameworkcode,
                $node->code . ' v' . $node->version,
                $node->statement,
                get_string('attainmentstate_' . $node->state, 'local_outcomemap'),
                $stats->graded,
                $stats->judged,
                $stats->met,
                $stats->metpct === null ? '' : number_format($stats->metpct, 2, '.', ''),
                $stats->mean === null ? '' : number_format($stats->mean, 2, '.', ''),
                implode('; ', $bands),
            ];
            if ($report->cohortrule !== null) {
                foreach ([report_service::COHORT_COMPLETED, report_service::COHORT_NOTCOMPLETED] as $key) {
                    $split = $node->stats[$key];
                    $row[] = $split->metpct === null
                        ? '' : number_format($split->metpct, 2, '.', '');
                }
            }
            // Statements and band names are staff-entered free text, so they
            // are neutralized against spreadsheet formula execution before the
            // download; genuine values pass through unchanged.
            $exporter->add_data(csv_safety::row($row));
        }
    }
    $exporter->download_file();
    exit;
}

echo $OUTPUT->header();
echo html_writer::start_div('lom-oa');

// ---------------------------------------------------------------------------
// Masthead: who this is about, and the one question it answers.
// ---------------------------------------------------------------------------

$kicker = array_filter([
    $report->program ? s($report->program->code) . ' ' . format_string($report->program->name) : '',
    format_string($course->shortname),
    get_string('oa_kickerperiod', 'local_outcomemap', implode(', ', array_map('s', $report->periodcodes))),
]);
$headline = $report->headline;
$toplabel = $tiername($report->toptier);

$actions = html_writer::link($link(['action' => 'export']),
    get_string('coverage_exportcsv', 'local_outcomemap'), ['class' => 'lom-oa-btn']);
if ($report->toptier->measurable) {
    $actions .= html_writer::link($link(['sheets' => 1]),
        get_string('oa_opensheets', 'local_outcomemap'), ['class' => 'lom-oa-btn lom-oa-btn-primary']);
}

echo html_writer::div(
    html_writer::div(
        html_writer::div(implode(' · ', $kicker), 'lom-oa-kicker')
        . html_writer::tag('h1', get_string('oa_question', 'local_outcomemap'), ['class' => 'lom-oa-h1'])
        . html_writer::tag('p', get_string('oa_lede', 'local_outcomemap', (object) [
            'learners' => $report->learners,
            'levels' => count($report->tiers),
            'top' => s($toplabel),
            'outcomes' => count($nodes),
        ]), ['class' => 'lom-oa-lede']),
        'lom-oa-masthead-text'
    ) . html_writer::div($actions, 'lom-oa-masthead-actions lom-oa-noprint'),
    'lom-oa-masthead'
);

// ---------------------------------------------------------------------------
// The headline strip: the figure, what stands behind it, and how to read it.
// ---------------------------------------------------------------------------

$evidence = '';
foreach ($report->tiers as $tier) {
    $stats = $tier->stats[$cohort];
    $evidence .= html_writer::div(
        html_writer::span(s($tiername($tier)), 'lom-oa-ev-label')
        . html_writer::span(
            get_string('oa_evidencerow', 'local_outcomemap', (object) [
                'measured' => $stats->measured,
                'outcomes' => $stats->outcomes,
                'metpct' => $withheld($stats) ? get_string('oa_withheld', 'local_outcomemap')
                    : ($stats->metpct === null ? '—' : number_format($stats->metpct, 1) . '%'),
            ]),
            'lom-oa-ev-value'
        ),
        'lom-oa-ev-row'
    );
}
$evidence .= html_writer::div(
    html_writer::span(get_string('oa_evidencegap', 'local_outcomemap'), 'lom-oa-ev-label')
    . html_writer::span(count($report->gaps->unassessed), 'lom-oa-ev-value'),
    'lom-oa-ev-row'
);

$care = html_writer::tag('p', $report->unweightedmean === null
    ? get_string('oa_care_nomean', 'local_outcomemap')
    : get_string('oa_care', 'local_outcomemap', (object) [
        'old' => report_service::pct($report->unweightedmean),
        'outcomes' => count($nodes),
        'levels' => count($report->tiers),
    ]));
if ($policy->available) {
    $care .= html_writer::tag('p', get_string('oa_care_policy', 'local_outcomemap', (object) [
        'criterion' => report_service::pct($policy->criterion),
        'target' => report_service::pct($policy->target),
        'floor' => $policy->floor,
    ]));
} else {
    $care .= html_writer::tag('p', $policy->unreadable
        ? get_string('oa_care_badpolicy', 'local_outcomemap')
        : get_string('oa_care_nopolicy', 'local_outcomemap'));
}

echo html_writer::div(
    html_writer::div(
        html_writer::div(get_string('oa_headlinelabel', 'local_outcomemap'), 'lom-oa-strip-label')
        . html_writer::div(
            html_writer::span(
                $withheld($headline) ? get_string('oa_withheld', 'local_outcomemap')
                    : ($headline->metpct === null ? '—' : number_format($headline->metpct, 1) . '%'),
                'lom-oa-figure'
            ) . html_writer::span(get_string('oa_headlineof', 'local_outcomemap', s($toplabel)),
                'lom-oa-figure-of'),
            'lom-oa-figure-line'
        )
        . html_writer::tag('p', $headline->judged
            ? get_string('oa_headlinesentence', 'local_outcomemap', (object) [
                'met' => $headline->met,
                'judged' => $headline->judged,
                'top' => s($toplabel),
                'cohort' => get_string('oa_cohortphrase_' . $cohort, 'local_outcomemap',
                    $report->cohortcounts[$cohort]),
                'mean' => report_service::pct($headline->mean),
            ])
            : get_string('oa_headlinenone', 'local_outcomemap', s($toplabel))),
        'lom-oa-strip-cell lom-oa-strip-headline'
    )
    . html_writer::div(
        html_writer::div(get_string('oa_evidencelabel', 'local_outcomemap'), 'lom-oa-strip-label')
        . $evidence,
        'lom-oa-strip-cell'
    )
    . html_writer::div(
        html_writer::div(get_string('oa_carelabel', 'local_outcomemap'), 'lom-oa-strip-label lom-oa-accented')
        . html_writer::div($care, 'lom-oa-care'),
        'lom-oa-strip-cell'
    ),
    'lom-oa-strip'
);

// An approved accreditation policy that no longer normalises is a governance
// problem, not a reporting one: the figures below stand, but nothing can be
// compared to a benchmark until somebody approves a replacement.
if ($policy->unreadable) {
    echo html_writer::div(
        $OUTPUT->notification(
            get_string('oa_policyunreadable', 'local_outcomemap', (object) [
                'program' => $report->program === null ? '' : s($report->program->name),
                'field' => $policy->problemfield === null
                    ? get_string('oa_policyfieldunknown', 'local_outcomemap')
                    : s($policy->problemfield),
            ]),
            \core\output\notification::NOTIFY_WARNING
        ),
        'lom-oa-diagnosis'
    );
}

// Outcomes are in scope but nothing has been calculated for any of them. The
// report still has coverage findings worth reading, so it carries on rather
// than stopping — but it names the gate the evidence pipeline stopped at first.
if (!$report->learners) {
    echo html_writer::div(
        $OUTPUT->notification(get_string('attainment_noresults', 'local_outcomemap'),
            \core\output\notification::NOTIFY_INFO)
        . $diagnosis($courseid),
        'lom-oa-diagnosis'
    );
}

// ---------------------------------------------------------------------------
// Controls. Every one of them is a link, so the whole report is a URL and any
// reading of it can be sent to somebody else exactly as it was read.
// ---------------------------------------------------------------------------

/**
 * Render one segmented control.
 *
 * @param string $label Control label.
 * @param array $options Value to text.
 * @param string $selected Selected value.
 * @param string $param Query parameter the control sets.
 * @return string
 */
$segmented = static function (string $label, array $options, string $selected, string $param)
        use ($link): string {
    $buttons = '';
    foreach ($options as $value => $text) {
        $buttons .= html_writer::link(
            $link([$param => $value, 'detail' => null]),
            $text,
            [
                'class' => 'lom-oa-seg-opt' . ($selected === $value ? ' lom-oa-seg-on' : ''),
                'aria-current' => $selected === $value ? 'true' : null,
            ]
        );
    }
    return html_writer::div(
        html_writer::span($label, 'lom-oa-control-label')
            . html_writer::div($buttons, 'lom-oa-segbox'),
        'lom-oa-control'
    );
};

$controls = $segmented(get_string('oa_controlview', 'local_outcomemap'), $viewoptions, $view, 'view');

if ($report->cohortrule !== null) {
    $cohortoptions = [];
    foreach (report_service::COHORTS as $key) {
        $cohortoptions[$key] = get_string('oa_cohort_' . $key, 'local_outcomemap');
    }
    $controls .= $segmented(get_string('oa_controlcohort', 'local_outcomemap'),
        $cohortoptions, $cohort, 'cohort');
}

$lensoptions = [];
foreach ($report->lenses as $key) {
    $lensoptions[$key] = get_string('oa_lens_' . $key, 'local_outcomemap');
}
$controls .= $segmented(get_string('oa_controllens', 'local_outcomemap'), $lensoptions, $lens, 'lens');

$searchform = html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $url->out_omit_querystring(),
        'class' => 'lom-oa-search',
    ])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => $view])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cohort', 'value' => $cohort])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lens', 'value' => $lens])
    . html_writer::label(get_string('attainment_searchlabel', 'local_outcomemap'), 'lom-oa-q',
        false, ['class' => 'sr-only visually-hidden'])
    . html_writer::empty_tag('input', [
        'type' => 'search',
        'id' => 'lom-oa-q',
        'name' => 'q',
        'value' => $search,
        'placeholder' => get_string('coverage_searchplaceholder', 'local_outcomemap'),
        'class' => 'lom-oa-input',
    ])
    . html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'lom-oa-btn'])
    . ($search !== '' ? html_writer::link($link(['q' => null]),
        get_string('coverage_clearsearch', 'local_outcomemap'), ['class' => 'lom-oa-btn-ghost']) : '')
    . html_writer::end_tag('form');

echo html_writer::div(
    html_writer::div($controls . html_writer::div($searchform, 'lom-oa-control lom-oa-control-end'),
        'lom-oa-controlbar-inner'),
    'lom-oa-controlbar lom-oa-noprint'
);

// The two notes that say what the current reading actually covers.
$notes = '';
if ($report->cohortrule !== null) {
    $notes .= html_writer::div(
        html_writer::span(get_string('oa_cohort_' . $cohort, 'local_outcomemap'), 'lom-oa-note-title')
        . html_writer::span(
            get_string('oa_cohortnote_' . $cohort, 'local_outcomemap', (object) [
                'count' => $report->cohortcounts[$cohort],
                'total' => $report->cohortcounts[report_service::COHORT_ALL],
                'rule' => $report->cohortrule === 'completion'
                    ? get_string('oa_rule_completion', 'local_outcomemap')
                    : get_string('oa_rule_gradepass', 'local_outcomemap',
                        report_service::pct($report->cohortrulevalue)),
            ]),
            'lom-oa-note-body'
        ),
        'lom-oa-note'
    );
}
$notes .= html_writer::div(
    html_writer::span(get_string('oa_lensnotelabel', 'local_outcomemap',
        get_string('oa_lens_' . $lens, 'local_outcomemap')), 'lom-oa-note-kicker')
    . html_writer::span(get_string('oa_lensnote_' . $lens, 'local_outcomemap',
        (object) ['floor' => $policy->floor, 'top' => s($toplabel)]), 'lom-oa-note-body'),
    'lom-oa-note lom-oa-note-quiet'
);
echo html_writer::div($notes, 'lom-oa-notes');

echo html_writer::start_div('lom-oa-body');

// ---------------------------------------------------------------------------
// Summary: the top level as cards, what to look at first, and where the
// evidence runs out.
// ---------------------------------------------------------------------------

if ($view === report_service::VIEW_SUMMARY) {
    echo html_writer::div(
        html_writer::tag('h2', get_string('oa_toplevelheading', 'local_outcomemap', (object) [
            'count' => count($report->toptier->nodes), 'level' => s($toplabel),
        ]), ['class' => 'lom-oa-h2'])
        . html_writer::span($target === null
            ? get_string('oa_notarget', 'local_outcomemap')
            : get_string('oa_targetline', 'local_outcomemap', (object) [
                'target' => report_service::pct($target),
                'criterion' => report_service::pct($policy->criterion),
            ]), 'lom-oa-sectionnote'),
        'lom-oa-sectionhead'
    );

    $cards = '';
    foreach ($report->toptier->nodes as $node) {
        $stats = $node->stats[$cohort];
        $legend = '';
        if (!$withheld($stats) && $stats->bands) {
            foreach (array_reverse($stats->bands) as $band) {
                $legend .= html_writer::span(
                    html_writer::span('', 'lom-oa-swatch lom-oa-seg-' . $band->rank)
                        . s($band->name) . ' ' . $band->count,
                    'lom-oa-legend-item'
                );
            }
        }
        $rests = $node->children
            ? get_string('oa_restson', 'local_outcomemap', (object) [
                'count' => count($node->children),
                'codes' => implode(', ', array_map(
                    static fn(int $id): string => $nodes[$id]->code,
                    array_slice(array_values($node->children), 0, 6)
                )),
            ])
            : get_string('oa_restsonnothing', 'local_outcomemap');

        $cards .= html_writer::div(
            html_writer::div(
                html_writer::span(s($node->frameworkcode . '.' . $node->code), 'lom-oa-card-code')
                . html_writer::span(get_string('oa_gradedof', 'local_outcomemap', (object) [
                    'graded' => $stats->graded,
                    'total' => $report->cohortcounts[$cohort],
                ]), 'lom-oa-card-n'),
                'lom-oa-card-top'
            )
            . html_writer::div($statement($node), 'lom-oa-card-title')
            . ($node->shortstatement
                ? html_writer::tag('p', s($node->statement), ['class' => 'lom-oa-card-full'])
                : '')
            . html_writer::div(
                html_writer::span($metlabel($stats), 'lom-oa-card-figure ' . $metclass($stats))
                . html_writer::span($withheld($stats)
                    ? get_string('oa_withheldsentence', 'local_outcomemap', (object) [
                        'graded' => $stats->graded, 'floor' => $policy->floor,
                    ])
                    : ($stats->judged
                        ? get_string('oa_cardsentence', 'local_outcomemap', (object) [
                            'met' => $stats->met,
                            'judged' => $stats->judged,
                            'mean' => report_service::pct($stats->mean),
                        ])
                        : get_string('oa_cardnone', 'local_outcomemap')), 'lom-oa-card-sentence'),
                'lom-oa-card-result'
            )
            . $bar($stats)
            . ($legend ? html_writer::div($legend, 'lom-oa-legend') : '')
            . $flags($node)
            . html_writer::div(
                html_writer::span($rests, 'lom-oa-card-feeds')
                . html_writer::link($link(['detail' => $node->itemid]),
                    get_string('oa_tracelink', 'local_outcomemap'),
                    ['class' => 'lom-oa-btn-ghost lom-oa-noprint']),
                'lom-oa-card-foot'
            ),
            'lom-oa-card'
        );
    }
    echo html_writer::div($cards, 'lom-oa-cards');

    echo html_writer::empty_tag('hr', ['class' => 'lom-oa-rule']);

    // What to look at first.
    $priorities = '';
    foreach ($report->priorities as $finding) {
        $priorities .= html_writer::div(
            html_writer::span(s($finding->code), 'lom-oa-pri-code')
            . html_writer::div(
                html_writer::div(get_string('oapri_' . $finding->key . '_headline',
                    'local_outcomemap', $finding->args), 'lom-oa-pri-headline')
                . html_writer::div(get_string('oapri_' . $finding->key . '_why',
                    'local_outcomemap', $finding->args), 'lom-oa-pri-why')
                . html_writer::div(get_string('oapri_' . $finding->key . '_action_' . $lens,
                    'local_outcomemap', $finding->args), 'lom-oa-pri-action'),
                'lom-oa-pri-text'
            ),
            'lom-oa-pri'
        );
    }
    if ($priorities === '') {
        $priorities = html_writer::div(get_string('oa_nopriorities', 'local_outcomemap'),
            'lom-oa-empty');
    }

    // Where the evidence runs out.
    $gapgroups = '';
    foreach ($report->gaps->hollow as $hollow) {
        $gapgroups .= html_writer::div(
            html_writer::div(
                html_writer::span(s($hollow->node->frameworkcode . '.' . $hollow->node->code
                    . ' — ' . ($hollow->node->shortstatement
                        ?: shorten_text($hollow->node->statement, 70))), 'lom-oa-gap-title')
                . html_writer::span(get_string('oa_gapcount', 'local_outcomemap', (object) [
                    'missing' => count($hollow->missing),
                    'total' => count($hollow->node->children),
                ]), 'lom-oa-gap-count'),
                'lom-oa-gap-head'
            )
            . html_writer::div(get_string('oa_gaphollow', 'local_outcomemap', (object) [
                'code' => s($hollow->node->code),
                'missing' => count($hollow->missing),
                'total' => count($hollow->node->children),
            ]), 'lom-oa-gap-body')
            . html_writer::div(get_string('oa_gapaffected', 'local_outcomemap', implode(', ',
                array_map(static fn(int $id): string => s($nodes[$id]->code),
                    array_slice($hollow->missing, 0, 20)))), 'lom-oa-gap-codes'),
            'lom-oa-gap'
        );
    }
    if ($report->gaps->unassessed) {
        $gapgroups .= html_writer::div(
            html_writer::div(
                html_writer::span(get_string('oa_gapunassessed', 'local_outcomemap'), 'lom-oa-gap-title')
                . html_writer::span(get_string('oa_gapofall', 'local_outcomemap', (object) [
                    'count' => count($report->gaps->unassessed),
                    'total' => $report->gaps->leaves,
                ]), 'lom-oa-gap-count'),
                'lom-oa-gap-head'
            )
            . html_writer::div(get_string('oa_gapunassessedbody', 'local_outcomemap'), 'lom-oa-gap-body')
            . html_writer::div(get_string('oa_gapaffected', 'local_outcomemap', implode(', ',
                array_map(static fn(stdClass $n): string => s($n->code),
                    array_slice($report->gaps->unassessed, 0, 20)))), 'lom-oa-gap-codes'),
            'lom-oa-gap'
        );
    }
    if ($report->gaps->thin) {
        $gapgroups .= html_writer::div(
            html_writer::div(
                html_writer::span(get_string('oa_gapthin', 'local_outcomemap'), 'lom-oa-gap-title')
                . html_writer::span(count($report->gaps->thin), 'lom-oa-gap-count'),
                'lom-oa-gap-head'
            )
            . html_writer::div(get_string('oa_gapthinbody', 'local_outcomemap', $policy->floor),
                'lom-oa-gap-body')
            . html_writer::div(get_string('oa_gapaffected', 'local_outcomemap', implode(', ',
                array_map(static fn(stdClass $n): string
                    => s($n->code) . ' (n=' . $n->stats[$cohort]->graded . ')',
                    array_slice($report->gaps->thin, 0, 20)))), 'lom-oa-gap-codes'),
            'lom-oa-gap'
        );
    }
    if ($gapgroups === '') {
        $gapgroups = html_writer::div(get_string('oa_nogaps', 'local_outcomemap'), 'lom-oa-empty');
    }

    echo html_writer::div(
        html_writer::div(
            html_writer::tag('h3', get_string('oa_priorities_' . $lens, 'local_outcomemap'),
                ['class' => 'lom-oa-h3'])
            . html_writer::tag('p', get_string('oa_prioritiessub_' . $lens, 'local_outcomemap'),
                ['class' => 'lom-oa-sectionnote'])
            . $priorities,
            'lom-oa-half'
        )
        . html_writer::div(
            html_writer::tag('h3', get_string('oa_gapsheading', 'local_outcomemap'),
                ['class' => 'lom-oa-h3'])
            . html_writer::tag('p', get_string('oa_gapsintro', 'local_outcomemap', (object) [
                'unassessed' => count($report->gaps->unassessed),
                'total' => $report->gaps->leaves,
                'thin' => count($report->gaps->thin),
            ]), ['class' => 'lom-oa-sectionnote'])
            . html_writer::div($gapgroups, 'lom-oa-gaps'),
            'lom-oa-half'
        ),
        'lom-oa-columns'
    );
}

// ---------------------------------------------------------------------------
// Ledger: every outcome, one level inside another.
// ---------------------------------------------------------------------------

if ($view === report_service::VIEW_LEDGER) {
    // The program lens acts on claims, not on unit coursework, so the deepest
    // level is collapsed out rather than shown and ignored.
    $maxtier = $lens === report_service::LENS_PROGRAM
        ? max(0, count($report->tiers) - 2)
        : PHP_INT_MAX;

    /**
     * Render one ledger row and everything underneath it.
     *
     * @param int $itemid Outcome item ID.
     * @param array $seen Item IDs already on this path.
     * @return string
     */
    $ledgerrow = static function (int $itemid, array $seen = []) use (
        &$ledgerrow, $nodes, $cohort, $maxtier, $branchmatches, $statement,
        $metlabel, $metclass, $bar, $bandline, $flags, $link, $report, $tiercodes
    ): string {
        if (isset($seen[$itemid]) || !isset($nodes[$itemid]) || !$branchmatches($itemid)) {
            return '';
        }
        $seen[$itemid] = true;
        $node = $nodes[$itemid];
        $stats = $node->stats[$cohort];
        $children = '';
        if ($node->tier < $maxtier) {
            foreach ($node->children as $childid) {
                $children .= $ledgerrow($childid, $seen);
            }
        }
        $row = html_writer::div(
            html_writer::div(
                html_writer::span(s($node->code), 'lom-oa-lcode')
                . html_writer::span('v' . $node->version, 'lom-oa-lver')
                . html_writer::div(s($tiercodes($report->tiers[$node->tier])), 'lom-oa-llevel'),
                'lom-oa-lcell lom-oa-lcell-code'
            )
            . html_writer::div(
                html_writer::div($statement($node), 'lom-oa-lstatement')
                . ($node->shortstatement
                    ? html_writer::div(s($node->statement), 'lom-oa-lfull') : '')
                . $flags($node),
                'lom-oa-lcell lom-oa-lcell-text'
            )
            . html_writer::div(
                html_writer::div($stats->graded . ' / ' . $report->cohortcounts[$cohort],
                    'lom-oa-ln' . ($stats->graded ? '' : ' lom-oa-quiet'))
                . html_writer::div($stats->graded
                    ? get_string('oa_graded', 'local_outcomemap')
                    : get_string('oa_notmeasuredshort', 'local_outcomemap'), 'lom-oa-lnnote'),
                'lom-oa-lcell lom-oa-lcell-n'
            )
            . html_writer::div(
                $bar($stats) . html_writer::div($bandline($stats), 'lom-oa-lband'),
                'lom-oa-lcell lom-oa-lcell-bar'
            )
            . html_writer::div(
                html_writer::span($metlabel($stats), 'lom-oa-lmet ' . $metclass($stats))
                . html_writer::link($link(['detail' => $node->itemid]),
                    get_string('oa_detail', 'local_outcomemap'),
                    ['class' => 'lom-oa-btn-ghost lom-oa-noprint']),
                'lom-oa-lcell lom-oa-lcell-met'
            ),
            'lom-oa-lrow'
        );
        if ($children === '') {
            return html_writer::div($row, 'lom-oa-lnode');
        }
        return html_writer::tag(
            'details',
            html_writer::tag('summary', $row, ['class' => 'lom-oa-lsummary']) . $children,
            ['class' => 'lom-oa-lnode lom-oa-lnode-parent', 'open' => 'open']
        );
    };

    $rows = '';
    foreach ($report->toptier->nodes as $node) {
        $rows .= $ledgerrow($node->itemid);
    }
    echo html_writer::div(
        html_writer::tag('h2', get_string('oa_ledgerheading', 'local_outcomemap'), ['class' => 'lom-oa-h2'])
        . html_writer::span(get_string('oa_ledgersub', 'local_outcomemap'), 'lom-oa-sectionnote'),
        'lom-oa-sectionhead'
    );
    echo html_writer::div(
        html_writer::span(get_string('oa_col_outcome', 'local_outcomemap'), 'lom-oa-lcell lom-oa-lcell-code')
        . html_writer::span(get_string('oa_col_statement', 'local_outcomemap'),
            'lom-oa-lcell lom-oa-lcell-text')
        . html_writer::span(get_string('oa_col_graded', 'local_outcomemap'),
            'lom-oa-lcell lom-oa-lcell-n')
        . html_writer::span(get_string('oa_col_landed', 'local_outcomemap'),
            'lom-oa-lcell lom-oa-lcell-bar')
        . html_writer::span(get_string('oa_col_reached', 'local_outcomemap'),
            'lom-oa-lcell lom-oa-lcell-met'),
        'lom-oa-lrow lom-oa-lhead'
    );
    echo $rows !== '' ? html_writer::div($rows, 'lom-oa-ledger')
        : html_writer::div(get_string('coverage_nomatches', 'local_outcomemap'), 'lom-oa-empty');
}

// ---------------------------------------------------------------------------
// Alignment map: one column per level, with a trace through the graph.
// ---------------------------------------------------------------------------

if ($view === report_service::VIEW_MAP) {
    $lit = null;
    if ($traceid && isset($nodes[$traceid])) {
        $lit = [$traceid => true];
        $walk = static function (int $itemid, string $direction) use (&$walk, $nodes, &$lit): void {
            foreach ($nodes[$itemid]->{$direction} as $nextid) {
                if (isset($lit[$nextid]) || !isset($nodes[$nextid])) {
                    continue;
                }
                $lit[$nextid] = true;
                $walk($nextid, $direction);
            }
        };
        $walk($traceid, 'parents');
        $walk($traceid, 'children');
    }

    echo html_writer::div(
        html_writer::tag('h2', get_string('oa_mapheading', 'local_outcomemap'), ['class' => 'lom-oa-h2'])
        . html_writer::span(get_string('oa_mapsub', 'local_outcomemap'), 'lom-oa-sectionnote'),
        'lom-oa-sectionhead'
    );
    echo html_writer::div(
        html_writer::span($lit === null
            ? get_string('oa_tracenone', 'local_outcomemap')
            : get_string('oa_tracing', 'local_outcomemap', (object) [
                'code' => s($nodes[$traceid]->frameworkcode . '.' . $nodes[$traceid]->code),
                'count' => count($lit) - 1,
            ]), 'lom-oa-tracenote')
        . ($lit === null ? '' : html_writer::link(
            $link(['trace' => null]),
            get_string('oa_cleartrace', 'local_outcomemap'),
            ['class' => 'lom-oa-btn']
        )),
        'lom-oa-tracebar lom-oa-noprint'
    );

    $columns = '';
    foreach (array_reverse($report->tiers) as $tier) {
        $items = '';
        foreach ($tier->nodes as $node) {
            if (!$matches($node)) {
                continue;
            }
            $stats = $node->stats[$cohort];
            $on = $lit === null || isset($lit[$node->itemid]);
            $classes = ['lom-oa-mapnode'];
            if (!$on) {
                $classes[] = 'lom-oa-mapnode-off';
            }
            if ($traceid === $node->itemid) {
                $classes[] = 'lom-oa-mapnode-traced';
            }
            $classes[] = 'lom-oa-mapedge-' . ($stats->metpct === null ? 'none'
                : ($target !== null && $stats->metpct + 0.05 < $target ? 'below' : 'ok'));
            $upward = $node->parents
                ? get_string('oa_mapupward', 'local_outcomemap', implode(', ', array_map(
                    static fn(int $id): string => $nodes[$id]->code,
                    array_slice(array_values($node->parents), 0, 4)
                )))
                : get_string('oa_mapupwardnone', 'local_outcomemap');
            $items .= html_writer::link(
                $link(['trace' => $traceid === $node->itemid ? null : $node->itemid]),
                html_writer::div(
                    html_writer::span(s($node->code), 'lom-oa-mapcode')
                    . html_writer::span($metlabel($stats), 'lom-oa-mapmet ' . $metclass($stats)),
                    'lom-oa-maprow'
                )
                . html_writer::div($statement($node), 'lom-oa-maplabel')
                . html_writer::div($upward, 'lom-oa-mapup'),
                ['class' => implode(' ', $classes)]
            );
        }
        $columns .= html_writer::div(
            html_writer::div(
                html_writer::div(s($tiername($tier)), 'lom-oa-mapcol-title')
                . html_writer::div(get_string('oa_mapcolsub', 'local_outcomemap', (object) [
                    'total' => count($tier->nodes),
                    'measured' => $tier->measurable,
                ]), 'lom-oa-mapcol-sub'),
                'lom-oa-mapcol-head'
            ) . html_writer::div($items ?: html_writer::div(
                get_string('coverage_nomatches', 'local_outcomemap'), 'lom-oa-empty'), 'lom-oa-mapcol-body'),
            'lom-oa-mapcol'
        );
    }
    echo html_writer::div($columns, 'lom-oa-map');
}

// ---------------------------------------------------------------------------
// Program rollup: the same outcome across every course that claims it.
// ---------------------------------------------------------------------------

if ($view === report_service::VIEW_ROLLUP) {
    echo html_writer::div(
        html_writer::tag('h2', get_string('oa_rollupheading', 'local_outcomemap',
            s($report->program->name)), ['class' => 'lom-oa-h2'])
        . html_writer::span(get_string('oa_rollupsub', 'local_outcomemap'), 'lom-oa-sectionnote'),
        'lom-oa-sectionhead'
    );
    $head = html_writer::tag('th', get_string('course'), ['style' => 'width:16rem']);
    foreach ($report->rollup->outcomes as $outcome) {
        $head .= html_writer::tag('th', s($outcome->code), [
            'class' => 'lom-oa-center', 'title' => $outcome->statement,
        ]);
    }
    $head .= html_writer::tag('th', get_string('attainment_learners', 'local_outcomemap'),
        ['class' => 'lom-oa-right']);

    $body = '';
    foreach ($report->rollup->courses as $rollupcourse) {
        $cells = html_writer::tag('td',
            html_writer::div(s($rollupcourse->code . ' ' . $rollupcourse->name), 'lom-oa-rollup-name')
            . html_writer::div($rollupcourse->current
                ? get_string('oa_rollupthis', 'local_outcomemap')
                : get_string('oa_rollupother', 'local_outcomemap'), 'lom-oa-rollup-note'));
        foreach ($report->rollup->outcomes as $outcome) {
            $stats = $rollupcourse->cells[$outcome->itemid];
            $cells .= html_writer::tag('td',
                html_writer::div($stats->graded ? $metlabel($stats) : '—',
                    'lom-oa-rollup-met ' . $metclass($stats))
                . html_writer::div($stats->graded
                    ? get_string('oa_rollupn', 'local_outcomemap', $stats->graded)
                    : get_string('oa_rollupnotclaimed', 'local_outcomemap'), 'lom-oa-rollup-n'),
                ['class' => 'lom-oa-center']);
        }
        $cells .= html_writer::tag('td', $rollupcourse->learners, ['class' => 'lom-oa-right']);
        $body .= html_writer::tag('tr', $cells,
            ['class' => $rollupcourse->current ? 'lom-oa-rollup-current' : '']);
    }
    echo html_writer::tag('table',
        html_writer::tag('thead', html_writer::tag('tr', $head))
        . html_writer::tag('tbody', $body),
        ['class' => 'lom-oa-table']);
    echo html_writer::div(
        html_writer::div(get_string('oa_rollupnote1', 'local_outcomemap'), 'lom-oa-note')
        . html_writer::div(get_string('oa_rollupnote2', 'local_outcomemap',
            implode(', ', array_map('s', $report->periodcodes))), 'lom-oa-note'),
        'lom-oa-notes'
    );
}

echo html_writer::end_div();

// ---------------------------------------------------------------------------
// Provenance and definitions: what these numbers are, in the reader's language.
// ---------------------------------------------------------------------------

$definitions = [
    ['oa_def_reached', $policy->available
        ? get_string('oa_def_reached_policy', 'local_outcomemap', report_service::pct($policy->criterion))
        : get_string('oa_def_reached_band', 'local_outcomemap')],
    ['oa_def_mean', get_string('oa_def_mean_body', 'local_outcomemap')],
    ['oa_def_graded', get_string('oa_def_graded_body', 'local_outcomemap')],
];
if ($report->cohortrule !== null) {
    $definitions[] = ['oa_def_completed', $report->cohortrule === 'completion'
        ? get_string('oa_def_completed_completion', 'local_outcomemap')
        : get_string('oa_def_completed_gradepass', 'local_outcomemap',
            report_service::pct($report->cohortrulevalue))];
}
if ($policy->floor !== null) {
    $definitions[] = ['oa_def_thin',
        get_string('oa_def_thin_body', 'local_outcomemap', $policy->floor)];
}
$definitions[] = ['oa_def_unassessed', get_string('oa_def_unassessed_body', 'local_outcomemap')];

$deflist = '';
foreach ($definitions as [$termkey, $body]) {
    $deflist .= html_writer::div(
        html_writer::span(get_string($termkey, 'local_outcomemap'), 'lom-oa-def-term')
        . html_writer::span($body, 'lom-oa-def-body'),
        'lom-oa-def'
    );
}

echo html_writer::empty_tag('hr', ['class' => 'lom-oa-rule']);
echo html_writer::div(
    html_writer::div(
        html_writer::tag('h4', get_string('oa_provenance', 'local_outcomemap'), ['class' => 'lom-oa-h4'])
        . html_writer::tag('p', get_string('oa_provenancebody', 'local_outcomemap', (object) [
            'periods' => implode(', ', array_map('s', $report->periodcodes)),
        ]), ['class' => 'lom-oa-fineprint'])
        . html_writer::tag('p', $policy->available
            ? get_string('oa_provenancepolicy', 'local_outcomemap', (object) [
                'name' => s($policy->name),
                'version' => $policy->version,
                'program' => s($report->program->name),
                'criterion' => report_service::pct($policy->criterion),
                'target' => report_service::pct($policy->target),
                'floor' => $policy->floor,
            ])
            : ($policy->unreadable
                ? get_string('oa_provenancebadpolicy', 'local_outcomemap', (object) [
                    'field' => $policy->problemfield === null
                        ? get_string('oa_policyfieldunknown', 'local_outcomemap')
                        : s($policy->problemfield),
                ])
                : get_string('oa_provenancenopolicy', 'local_outcomemap')),
            ['class' => 'lom-oa-fineprint']),
        'lom-oa-half'
    )
    . html_writer::div(
        html_writer::tag('h4', get_string('oa_definitions', 'local_outcomemap'), ['class' => 'lom-oa-h4'])
        . $deflist,
        'lom-oa-half'
    ),
    'lom-oa-columns lom-oa-footmatter'
);

// ---------------------------------------------------------------------------
// Detail panel: one outcome, all three cohorts, and what it rests on.
// ---------------------------------------------------------------------------

if ($detailid && isset($nodes[$detailid])) {
    $node = $nodes[$detailid];
    $done = $node->stats[report_service::COHORT_COMPLETED];
    $notdone = $node->stats[report_service::COHORT_NOTCOMPLETED];

    $cohortrows = '';
    $keys = $report->cohortrule === null
        ? [report_service::COHORT_ALL]
        : report_service::COHORTS;
    foreach ($keys as $key) {
        $stats = $node->stats[$key];
        $cohortrows .= html_writer::div(
            html_writer::div(
                html_writer::span(get_string('oa_cohort_' . $key, 'local_outcomemap'), 'lom-oa-note-title')
                . html_writer::span($metlabel($stats), 'lom-oa-lmet ' . $metclass($stats)),
                'lom-oa-drawer-cohorthead'
            )
            . $bar($stats)
            . html_writer::div(get_string('oa_drawercohortline', 'local_outcomemap', (object) [
                'graded' => $stats->graded,
                'total' => $report->cohortcounts[$key],
                'bands' => $bandline($stats),
                'mean' => report_service::pct($stats->mean),
            ]), 'lom-oa-drawer-cohortline'),
            'lom-oa-drawer-cohort'
        );
    }

    // What the split tells you, in order of how much it should change a decision.
    if ($withheld($node->stats[report_service::COHORT_ALL])) {
        $reading = get_string('oa_read_withheld', 'local_outcomemap', $policy->floor);
    } else if (!$node->stats[report_service::COHORT_ALL]->judged) {
        $reading = get_string('oa_read_nothing', 'local_outcomemap');
    } else if ($report->cohortrule === null) {
        $reading = get_string('oa_read_nosplit', 'local_outcomemap');
    } else if (!$done->judged || !$notdone->judged) {
        $reading = get_string('oa_read_onecohort', 'local_outcomemap');
    } else if (abs($done->metpct - $notdone->metpct) < report_service::ALIKE_SPREAD) {
        $reading = get_string('oa_read_alike', 'local_outcomemap', (object) [
            'completed' => report_service::pct($done->metpct),
            'notcompleted' => report_service::pct($notdone->metpct),
        ]);
    } else if ($target !== null && $done->metpct + 0.05 < $target) {
        $reading = get_string('oa_read_completersshort', 'local_outcomemap', (object) [
            'completed' => report_service::pct($done->metpct),
            'target' => report_service::pct($target),
        ]);
    } else {
        $reading = get_string('oa_read_separates', 'local_outcomemap', (object) [
            'completed' => report_service::pct($done->metpct),
            'notcompleted' => report_service::pct($notdone->metpct),
            'spread' => report_service::pct($done->metpct - $notdone->metpct),
        ]);
    }

    $sources = '';
    foreach ($node->sources as $source) {
        $sources .= html_writer::div(
            html_writer::span(s($source->name), 'lom-oa-src-name')
            . html_writer::span($source->detail
                ? get_string('oa_srcquestions', 'local_outcomemap', $source->detail) : '',
                'lom-oa-src-n'),
            'lom-oa-src'
        );
    }
    if ($sources === '') {
        $sources = html_writer::div($node->children
            ? get_string('oa_srcinherited', 'local_outcomemap')
            : get_string('oa_srcnone', 'local_outcomemap'), 'lom-oa-empty');
    }

    $lineage = '';
    $related = $node->children ?: $node->parents;
    $lineagetitle = $node->children
        ? get_string('oa_lineagedown', 'local_outcomemap')
        : get_string('oa_lineageup', 'local_outcomemap');
    foreach ($related as $relatedid) {
        if (!isset($nodes[$relatedid])) {
            continue;
        }
        $relnode = $nodes[$relatedid];
        $relstats = $relnode->stats[$cohort];
        $lineage .= html_writer::div(
            html_writer::span(s($relnode->code), 'lom-oa-lin-code')
            . html_writer::span(s($relnode->shortstatement
                ?: shorten_text($relnode->statement, 110)), 'lom-oa-lin-label')
            . html_writer::span($metlabel($relstats), 'lom-oa-lin-met ' . $metclass($relstats)),
            'lom-oa-lin'
        );
    }
    if ($lineage === '') {
        $lineage = html_writer::div(get_string('oa_lineagenone', 'local_outcomemap'), 'lom-oa-empty');
    }

    $canmap = has_capability('local/outcomemap:mapcourse', $context)
        || has_capability('local/outcomemap:mapactivities', $context);
    $fix = '';
    if ($canmap && $node->assessedcontent === false) {
        $fix = html_writer::link(
            new moodle_url('/local/outcomemap/contentmapping.php', ['courseid' => $courseid]),
            get_string('attainment_mapactivity', 'local_outcomemap'),
            ['class' => 'lom-oa-btn lom-oa-btn-primary']
        );
    }

    echo html_writer::div(
        html_writer::link($link(['detail' => null]), '', [
            'class' => 'lom-oa-drawer-scrim',
            'aria-label' => get_string('closebuttontitle'),
        ])
        . html_writer::div(
            html_writer::div(
                html_writer::div(
                    html_writer::div(get_string('oa_drawerkicker', 'local_outcomemap', (object) [
                        'level' => s($tiername($report->tiers[$node->tier])),
                        'code' => s($node->frameworkcode . '.' . $node->code),
                        'version' => $node->version,
                    ]), 'lom-oa-kicker')
                    . html_writer::tag('h3', s($node->shortstatement ?: $node->statement),
                        ['class' => 'lom-oa-h3']),
                    'lom-oa-drawer-titles'
                )
                . html_writer::link($link(['detail' => null]),
                    get_string('closebuttontitle'), ['class' => 'lom-oa-btn']),
                'lom-oa-drawer-head'
            )
            . html_writer::tag('p', s($node->statement), ['class' => 'lom-oa-drawer-statement'])
            . html_writer::div(get_string('oa_drawercohorts', 'local_outcomemap'), 'lom-oa-strip-label')
            . $cohortrows
            . html_writer::div(
                html_writer::div(get_string('oa_drawerreading', 'local_outcomemap'), 'lom-oa-drawer-h')
                . html_writer::tag('p', $reading, ['class' => 'lom-oa-drawer-body']),
                'lom-oa-drawer-block lom-oa-drawer-block-strong'
            )
            . html_writer::div(
                html_writer::div(get_string('oa_drawersources', 'local_outcomemap'), 'lom-oa-strip-label')
                . $sources . $fix,
                'lom-oa-drawer-block'
            )
            . html_writer::div(
                html_writer::div($lineagetitle, 'lom-oa-strip-label') . $lineage,
                'lom-oa-drawer-block'
            ),
            'lom-oa-drawer'
        ),
        'lom-oa-drawer-wrap lom-oa-noprint'
    );
}

// ---------------------------------------------------------------------------
// Summary sheets: one page per top-level outcome, the shape a reviewer asks for.
// ---------------------------------------------------------------------------

if ($sheets) {
    // The sheets are the submission itself rather than a view of it, so the
    // suppression floor binds here whatever lens the reader arrived under. A
    // figure printed above the sentence that says it was withheld would be the
    // one place on this page a reader could quote something the export refuses.
    $sheetlabel = static function (stdClass $stats) use ($policy, $isthin): string {
        if ($policy->floor !== null && $isthin($stats)) {
            return get_string('oa_withheld', 'local_outcomemap');
        }
        return $stats->metpct === null ? '—' : number_format($stats->metpct, 1) . '%';
    };
    // Evidence is inherited from wherever it is actually mapped, which for a
    // program outcome is usually two levels down rather than one.
    $subtreesources = static function (int $itemid, array $seen = []) use (&$subtreesources, $nodes): array {
        if (isset($seen[$itemid]) || !isset($nodes[$itemid])) {
            return [];
        }
        $seen[$itemid] = true;
        $names = [];
        foreach ($nodes[$itemid]->sources as $source) {
            $names[$source->name] = $source->name;
        }
        foreach ($nodes[$itemid]->children as $childid) {
            $names += $subtreesources($childid, $seen);
        }
        return $names;
    };

    $pages = '';
    foreach ($report->toptier->nodes as $node) {
        $all = $node->stats[report_service::COHORT_ALL];
        $done = $node->stats[report_service::COHORT_COMPLETED];
        $notdone = $node->stats[report_service::COHORT_NOTCOMPLETED];
        $stats = [
            [get_string('oa_col_reached', 'local_outcomemap'), $sheetlabel($all),
                $target === null ? get_string('oa_notargetshort', 'local_outcomemap')
                    : get_string('oa_targetshort', 'local_outcomemap', report_service::pct($target))],
            [get_string('oa_col_graded', 'local_outcomemap'),
                $all->graded . ' / ' . $report->cohortcounts[report_service::COHORT_ALL],
                $isthin($all) ? get_string('oa_belowfloor', 'local_outcomemap')
                    : get_string('oa_graded', 'local_outcomemap')],
        ];
        if ($report->cohortrule !== null) {
            $stats[] = [get_string('oa_cohort_completed', 'local_outcomemap'), $sheetlabel($done),
                get_string('oa_gradedn', 'local_outcomemap', $done->graded)];
            $stats[] = [get_string('oa_cohort_notcompleted', 'local_outcomemap'), $sheetlabel($notdone),
                get_string('oa_gradedn', 'local_outcomemap', $notdone->graded)];
        }
        $statcells = '';
        foreach ($stats as [$label, $value, $note]) {
            $statcells .= html_writer::div(
                html_writer::div($label, 'lom-oa-sheet-statlabel')
                . html_writer::div($value, 'lom-oa-sheet-statvalue')
                . html_writer::div($note, 'lom-oa-sheet-statnote'),
                'lom-oa-sheet-stat'
            );
        }
        $narrative = $isthin($all)
            ? get_string('oa_sheet_thin', 'local_outcomemap', (object) [
                'graded' => $all->graded, 'floor' => $policy->floor,
            ])
            : ($all->metpct === null
                ? get_string('oa_sheet_none', 'local_outcomemap')
                : ($target !== null && $all->metpct + 0.05 >= $target
                    ? get_string('oa_sheet_meets', 'local_outcomemap',
                        implode(', ', array_map('s', $report->periodcodes)))
                    : get_string('oa_sheet_below', 'local_outcomemap',
                        implode(', ', array_map('s', $report->periodcodes)))));
        $evidencenames = $subtreesources($node->itemid);

        $pages .= html_writer::div(
            html_writer::div(
                html_writer::span(get_string('oa_sheetkicker', 'local_outcomemap', (object) [
                    'code' => s($node->frameworkcode . '.' . $node->code),
                    'level' => s($toplabel),
                ]), 'lom-oa-kicker')
                . html_writer::span(get_string('oa_sheetmeta', 'local_outcomemap', (object) [
                    'periods' => implode(', ', array_map('s', $report->periodcodes)),
                    'policy' => $policy->available
                        ? s($policy->name) . ' v' . $policy->version
                        : get_string('oa_nopolicyshort', 'local_outcomemap'),
                    'course' => s($course->shortname),
                ]), 'lom-oa-sheet-meta'),
                'lom-oa-sheet-head'
            )
            . html_writer::tag('h3', s($node->shortstatement ?: $node->statement),
                ['class' => 'lom-oa-h3'])
            . html_writer::tag('p', s($node->statement), ['class' => 'lom-oa-sheet-statement'])
            . html_writer::div($statcells, 'lom-oa-sheet-stats')
            . html_writer::tag('p', $narrative, ['class' => 'lom-oa-sheet-narrative'])
            . html_writer::div($evidencenames
                ? get_string('oa_sheetevidence', 'local_outcomemap',
                    s(implode('; ', array_slice($evidencenames, 0, 12))))
                : get_string('oa_sheetnoevidence', 'local_outcomemap'), 'lom-oa-sheet-evidence'),
            'lom-oa-sheet'
        );
    }
    echo html_writer::div(
        html_writer::div(
            html_writer::span(get_string('oa_sheetsintro', 'local_outcomemap'), 'lom-oa-sectionnote')
            . html_writer::link($link(['sheets' => null]),
                get_string('closebuttontitle'), ['class' => 'lom-oa-btn']),
            'lom-oa-sheets-bar lom-oa-noprint'
        ) . $pages,
        'lom-oa-sheets'
    );
}

echo html_writer::end_div();
echo $OUTPUT->footer();
