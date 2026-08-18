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
 * Course question outcome mapping page.
 *
 * Lists the course's quizzes, drills into the question versions each quiz uses,
 * and maps outcomes onto those versions without leaving the course.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\api\context_resolver;
use local_outcomemap\api\outcome_search;
use local_outcomemap\api\question_mappings;
use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\service\question_browser_service;
use local_outcomemap\local\service\question_mapping_service;
use local_outcomemap\local\workflow;

// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Portable bootstrap path.
$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    // Windows junctions resolve __DIR__ to the repository target rather than
    // the Moodle local-plugin directory. The webroot loader remains portable
    // across Moodle's classic and 5.2 public-directory layouts.
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);
require_once($CFG->dirroot . '/local/outcomemap/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$search = trim(optional_param('q', '', PARAM_TEXT));
$outcomequery = trim(optional_param('oq', '', PARAM_TEXT));
$questionfilter = optional_param('cf', 'all', PARAM_ALPHA);
$expandall = optional_param('expand', 1, PARAM_BOOL);
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/outcomemap:viewdefinitions', $context);

if (!in_array($questionfilter, ['all', 'unmapped', 'assessed'], true)) {
    $questionfilter = 'all';
}

$url = new moodle_url('/local/outcomemap/questionmapping.php', ['courseid' => $courseid]);
$stateparams = [
    'courseid' => $courseid,
    'cmid' => $cmid ?: null,
    'q' => $search,
    'oq' => $outcomequery,
    'cf' => $questionfilter,
    'expand' => $expandall ? 1 : 0,
];
$stateurl = new moodle_url('/local/outcomemap/questionmapping.php', array_filter(
    $stateparams,
    static fn($value): bool => $value !== null && $value !== ''
));

$PAGE->set_context($context);
$PAGE->set_course($course);
// The canonical URL must stay free of view state: Moodle matches the course
// navigation node against $PAGE->url with URL_MATCH_EXACT, and that match is
// what renders the report selector shared with the other course pages.
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('questionmapping_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

// The companion question-bank plugin owns the per-question editor this page
// links to, so the page is unavailable without it even by direct URL.
if (!local_outcomemap_qbank_available()) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('questionmapping_needsqbank', 'local_outcomemap'),
        \core\output\notification::NOTIFY_WARNING
    );
    echo $OUTPUT->continue_button(new moodle_url('/local/outcomemap/coverage.php', ['courseid' => $courseid]));
    echo $OUTPUT->footer();
    exit;
}

$canmap = has_capability('local/outcomemap:mapquestions', $context);

if ($action === 'refilter') {
    require_sesskey();
    redirect($stateurl);
}

if ($action === 'delete' && $id) {
    require_sesskey();
    question_mapping_service::delete_draft($id);
    redirect($stateurl, get_string('mappingremoved', 'local_outcomemap'));
}

if ($action === 'submit' && $id) {
    require_sesskey();
    question_mapping_service::submit_for_review($id);
    redirect($stateurl, workflow::submission_success_message());
}

if ($action === 'apply') {
    require_sesskey();
    if (!$canmap) {
        throw new required_capability_exception($context, 'local/outcomemap:mapquestions', 'nopermissions', '');
    }
    // A question can appear twice on one page — once in its own slot and again in
    // a random slot's pool — so the posted selection is deduplicated rather than
    // creating the same pair twice and reporting half the work as skipped.
    $selected = array_unique(optional_param_array('questions', [], PARAM_ALPHANUMEXT));
    $outcomeuuids = array_unique(optional_param_array('outcomes', [], PARAM_ALPHANUMEXT));
    $role = required_param('role', PARAM_ALPHANUMEXT);
    $weight = trim(optional_param('weight', '', PARAM_RAW));

    // The role names a language string in the result message, so reject an
    // unknown value here rather than failing later on a missing string.
    if (!in_array($role, question_mapping_service::ROLES, true)) {
        throw new moodle_exception('invalidmappingrole', 'local_outcomemap', '', $role);
    }

    if (!$selected || !$outcomeuuids) {
        redirect(
            $stateurl,
            get_string('apply_incomplete', 'local_outcomemap'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    // A weight is never inferred for an assessed mapping: the operator states it
    // once, and the service still rejects any question whose assessed weights
    // would not total exactly 1.0000000000 on approval.
    if ($role === content_mapping_service::ROLE_ASSESSES && $weight === '') {
        redirect(
            $stateurl,
            get_string('questionmapping_weightrequired', 'local_outcomemap'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    $created = 0;
    $failures = [];
    $effectivefrom = time();
    foreach ($selected as $value) {
        // Values are rendered as qv-<questionversionid>; anything else is ignored.
        if (!preg_match('/^qv-([0-9]+)$/', (string) $value, $matches)) {
            continue;
        }
        $questionversionid = (int) $matches[1];
        try {
            $questioncontext = context_resolver::for_question_version($questionversionid);
        } catch (moodle_exception $e) {
            $failures[] = $e->getMessage();
            continue;
        }
        foreach ($outcomeuuids as $outcomeuuid) {
            try {
                // The posted outcome must be visible from the question's own bank
                // context, so a tampered selection cannot reach a framework the
                // course has no claim on. This repeats the bulk action's check.
                outcome_search::require_visible_version($questioncontext, (string) $outcomeuuid, $effectivefrom);
                question_mappings::create_draft(
                    $questionversionid,
                    (string) $outcomeuuid,
                    $role,
                    $weight === '' ? null : $weight,
                    null,
                    $effectivefrom
                );
                $created++;
            } catch (moodle_exception $e) {
                // One rejected pair must not discard the rest of the selection.
                $failures[] = $e->getMessage();
            }
        }
    }
    $message = get_string('apply_created', 'local_outcomemap', (object) [
        'count' => $created,
        'role' => get_string('mappingrole_' . $role, 'local_outcomemap'),
    ]);
    if ($failures) {
        $message .= ' ' . get_string('apply_skipped', 'local_outcomemap', count($failures))
            . ' ' . implode(' ', array_unique($failures));
    }
    redirect(
        $stateurl,
        $message,
        null,
        $failures ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}

/**
 * Render the outcome chips for one question version.
 *
 * @param array $records Mapping records for the question version.
 * @param bool $canedit Whether the user may change this question's mappings.
 * @param moodle_url $stateurl Page URL carrying the current view state.
 * @return string Rendered HTML.
 */
function local_outcomemap_question_chips(array $records, bool $canedit, moodle_url $stateurl): string {
    if (!$records) {
        return html_writer::span(get_string('notmapped', 'local_outcomemap'), 'lom-map-unmapped');
    }
    $chips = '';
    foreach ($records as $record) {
        $isassess = $record->role === content_mapping_service::ROLE_ASSESSES;
        $rolelabel = get_string('mappingrole_' . $record->role, 'local_outcomemap');
        $title = $record->frameworkcode . '.' . $record->outcomecode . ' v' . $record->outcomeversion
            . ' — ' . $rolelabel . ' · ' . workflow::status_label($record->status);
        if ($record->weight !== null) {
            $title .= ' · ' . get_string('weight', 'local_outcomemap') . ' ' . $record->weight;
        }
        $inner = s($record->frameworkcode . '.' . $record->outcomecode)
            . html_writer::span(core_text::substr($rolelabel, 0, 1), 'lom-map-chip-role');
        // Only a draft can be removed or submitted; approved mappings are history.
        if ($canedit && $record->status === workflow::DRAFT) {
            $inner .= html_writer::link(
                new moodle_url($stateurl, [
                    'action' => 'submit',
                    'id' => $record->id,
                    'sesskey' => sesskey(),
                ]),
                '✓',
                [
                    'class' => 'lom-map-chip-remove',
                    'title' => get_string('submitreview', 'local_outcomemap'),
                    'aria-label' => get_string('submitreview', 'local_outcomemap') . ': ' . $title,
                ]
            );
            $inner .= html_writer::link(
                new moodle_url($stateurl, [
                    'action' => 'delete',
                    'id' => $record->id,
                    'sesskey' => sesskey(),
                ]),
                '×',
                [
                    'class' => 'lom-map-chip-remove',
                    'title' => get_string('removemapping', 'local_outcomemap'),
                    'aria-label' => get_string('removemapping', 'local_outcomemap') . ': ' . $title,
                ]
            );
        }
        $chips .= html_writer::span(
            $inner,
            'lom-map-chip ' . ($isassess ? 'lom-map-chip-assess' : 'lom-map-chip-teach'),
            ['title' => $title]
        );
    }
    return $chips;
}

echo $OUTPUT->header();

// Toolbar: the sibling course reports, so the three pages navigate as a set.
$toolbaractions = html_writer::link(
    new moodle_url('/local/outcomemap/coverage.php', ['courseid' => $courseid]),
    get_string('coverage_heading', 'local_outcomemap'),
    ['class' => 'btn btn-outline-secondary btn-sm']
);
$toolbaractions .= html_writer::link(
    new moodle_url('/local/outcomemap/contentmapping.php', ['courseid' => $courseid]),
    get_string('contentmapping_heading', 'local_outcomemap'),
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::div(
    html_writer::span(get_string('coverage_report', 'local_outcomemap'), 'lom-cov-toolbar-label')
        . html_writer::span(get_string('questionmapping_heading', 'local_outcomemap'), 'lom-cov-chip')
        . html_writer::div($toolbaractions, 'lom-cov-toolbar-actions'),
    'lom-cov-toolbar'
);
echo html_writer::tag('h2', get_string('questionmapping_heading', 'local_outcomemap'), ['class' => 'lom-cov-title']);

// Quiz picker.
if (!$cmid) {
    $quizzes = question_browser_service::quizzes($courseid);
    echo html_writer::div(get_string('questionmapping_subtitle', 'local_outcomemap'), 'lom-cov-subtitle');
    if (!$quizzes) {
        echo $OUTPUT->notification(
            get_string('questionmapping_noquizzes', 'local_outcomemap'),
            \core\output\notification::NOTIFY_INFO
        );
        echo $OUTPUT->footer();
        exit;
    }
    $head = html_writer::div(
        html_writer::span(get_string('assessment', 'local_outcomemap'), 'lom-q-c-name')
        . html_writer::span(get_string('questionmapping_slots', 'local_outcomemap'), 'lom-q-c-count')
        . html_writer::span(get_string('questionmapping_mapped', 'local_outcomemap'), 'lom-q-c-count')
        . html_writer::span(get_string('questionmapping_assessed', 'local_outcomemap'), 'lom-q-c-count')
        . html_writer::span('', 'lom-q-c-go'),
        'lom-cov-row lom-cov-head'
    );
    $body = '';
    foreach ($quizzes as $quiz) {
        $quizurl = new moodle_url($url, ['cmid' => $quiz->cmid]);
        $slotlabel = $quiz->randomslots
            ? get_string('questionmapping_slotswithrandom', 'local_outcomemap', (object) [
                'slots' => $quiz->slotcount,
                'random' => $quiz->randomslots,
            ])
            : (string) $quiz->slotcount;
        $body .= html_writer::div(
            html_writer::span(
                html_writer::link($quizurl, format_string($quiz->name), ['class' => 'lom-q-name'])
                    . html_writer::span(format_string($quiz->sectionname), 'lom-q-section'),
                'lom-q-c-name'
            )
            . html_writer::span($slotlabel, 'lom-q-c-count')
            . html_writer::span(
                $quiz->questioncount
                    ? get_string('questionmapping_ofn', 'local_outcomemap', (object) [
                        'count' => $quiz->mappedcount,
                        'total' => $quiz->questioncount,
                    ])
                    : '—',
                'lom-q-c-count'
            )
            . html_writer::span($quiz->assessedcount ?: '—', 'lom-q-c-count')
            . html_writer::span(
                html_writer::link($quizurl, get_string('questionmapping_open', 'local_outcomemap'), [
                    'class' => 'btn btn-sm btn-outline-secondary',
                ]),
                'lom-q-c-go'
            ),
            'lom-cov-row'
        );
    }
    echo html_writer::div($head . $body, 'lom-q-table');
    echo $OUTPUT->footer();
    exit;
}

// Quiz detail.
$detail = question_browser_service::quiz_detail($courseid, $cmid);

$backurl = new moodle_url($url);
echo html_writer::div(
    html_writer::link($backurl, get_string('questionmapping_allquizzes', 'local_outcomemap'), ['class' => 'lom-q-back'])
        . ' / ' . html_writer::span(format_string($detail->name), 'lom-q-crumb'),
    'lom-q-breadcrumb'
);

$banklinks = [];
foreach ($detail->banks as $bank) {
    if ($bank->cmid !== null) {
        $banklinks[] = html_writer::link(
            new moodle_url('/question/edit.php', ['cmid' => $bank->cmid]),
            format_string($bank->name)
        );
    } else {
        $banklinks[] = format_string($bank->name);
    }
}
echo html_writer::div(
    get_string('questionmapping_subtitle_quiz', 'local_outcomemap')
        . ($banklinks
            ? ' ' . get_string('questionmapping_banks', 'local_outcomemap') . ' ' . implode(', ', $banklinks)
            : ''),
    'lom-cov-subtitle'
);

// Outcomes are scoped to what this course may claim — institution frameworks,
// its catalog course, and its programs — matching the question bank's own bulk
// action rather than the site-wide list the content mapping page offers.
/**
 * Pagination size.
 */
const LOM_OUTCOME_PAGE_SIZE = 200;
$outcomes = $canmap ? outcome_search::search($context, $outcomequery, null, LOM_OUTCOME_PAGE_SIZE) : [];
// Report the true total rather than paginating: selections are checkboxes in one
// form, so paging the list would silently drop ticks made on an earlier page.
$outcometotal = count($outcomes) >= LOM_OUTCOME_PAGE_SIZE
    ? outcome_search::count($context, $outcomequery)
    : count($outcomes);
$canapply = $canmap && $outcomes !== [];

$needle = core_text::strtolower($search);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'lom-map']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cf', 'value' => $questionfilter]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'expand', 'value' => $expandall ? 1 : 0]);
echo html_writer::start_div('lom-map-layout');

// Left panel.
echo html_writer::start_div('lom-map-content');
$chips = '';
foreach (['all', 'unmapped', 'assessed'] as $key) {
    $chipurl = new moodle_url($stateurl, ['cf' => $key]);
    $chips .= html_writer::link(
        $chipurl,
        get_string('questionfilter_' . $key, 'local_outcomemap'),
        ['class' => 'lom-map-filter' . ($questionfilter === $key ? ' lom-map-filter-active' : '')]
    );
}
echo html_writer::div(
    html_writer::div(
        html_writer::label(
            get_string('questionmapping_search', 'local_outcomemap'),
            'lom-q-q',
            false,
            ['class' => 'visually-hidden']
        )
        . html_writer::empty_tag('input', [
            'type' => 'search',
            'id' => 'lom-q-q',
            'name' => 'q',
            'value' => $search,
            'placeholder' => get_string('questionmapping_searchplaceholder', 'local_outcomemap'),
            'class' => 'form-control form-control-sm',
        ])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'oq', 'value' => $outcomequery])
        . html_writer::tag('button', get_string('search'), [
            'type' => 'submit',
            'name' => 'action',
            'value' => 'refilter',
            'class' => 'btn btn-sm btn-secondary',
        ]),
        'lom-map-search'
    )
    . html_writer::div($chips, 'lom-map-filters')
    . html_writer::link(
        new moodle_url($stateurl, ['expand' => $expandall ? 0 : 1]),
        get_string($expandall ? 'collapseall' : 'expandall', 'local_outcomemap'),
        ['class' => 'lom-map-collapse']
    ),
    'lom-map-toolbar'
);

/**
 * Decide whether one question row survives the search and filter.
 *
 * @param \stdClass $question Question row with mappings.
 * @param string $needle Lower-cased search term.
 * @param string $filter Active question filter.
 * @return bool
 */
function local_outcomemap_question_visible(\stdClass $question, string $needle, string $filter): bool {
    if ($needle !== '' && core_text::strpos(core_text::strtolower($question->name), $needle) === false) {
        return false;
    }
    if ($filter === 'unmapped' && $question->mappings) {
        return false;
    }
    if ($filter === 'assessed') {
        foreach ($question->mappings as $record) {
            if ($record->role === content_mapping_service::ROLE_ASSESSES) {
                return true;
            }
        }
        return false;
    }
    return true;
}

$rendered = 0;
$slothtml = '';
foreach ($detail->slots as $slot) {
    $visible = [];
    foreach ($slot->questions as $question) {
        if (local_outcomemap_question_visible($question, $needle, $questionfilter)) {
            $visible[] = $question;
        }
    }
    if (!$visible) {
        continue;
    }
    $rendered += count($visible);

    $summary = html_writer::span('', 'lom-map-chev')
        . html_writer::span(
            // Slot display numbers are author-editable free text, so escape them.
            get_string('questionmapping_slotlabel', 'local_outcomemap', s($slot->displaynumber)),
            'lom-map-section-name'
        )
        . ($slot->random
            ? html_writer::span(
                get_string('questionmapping_randomfrom', 'local_outcomemap', s((string) $slot->poolname)),
                'lom-q-slot-kind'
            )
            : '')
        . html_writer::span(
            get_string('questionmapping_maxmark', 'local_outcomemap', format_float($slot->maxmark, -1)),
            'lom-map-section-count'
        )
        . html_writer::span(
            get_string('nitems', 'local_outcomemap', count($visible)),
            'lom-map-section-count'
        );

    $rows = '';
    foreach ($visible as $question) {
        $check = ($canapply && $question->canedit)
            ? html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'questions[]',
                'value' => 'qv-' . $question->questionversionid,
                'class' => 'lom-map-check',
            ])
            : html_writer::span('', 'lom-map-check-spacer');
        if ($question->missing) {
            $rows .= html_writer::div(
                html_writer::span('', 'lom-map-check-spacer')
                . html_writer::span('', 'lom-map-icon lom-q-qtype')
                . html_writer::span(
                    format_string($question->name)
                        . html_writer::span(
                            get_string('questionmapping_missingquestion', 'local_outcomemap'),
                            'lom-q-missing'
                        ),
                    'lom-map-node-name'
                ),
                'lom-map-row lom-q-row-missing'
            );
            continue;
        }
        $rows .= html_writer::tag(
            'label',
            $check
            . html_writer::span(s($question->qtype), 'lom-map-icon lom-q-qtype')
            . html_writer::span(
                format_string($question->name)
                    . html_writer::span('v' . $question->questionversion, 'lom-q-version'),
                'lom-map-node-name'
            )
            . html_writer::span(
                local_outcomemap_question_chips($question->mappings, $question->canedit, $stateurl),
                'lom-map-chips'
            ),
            ['class' => 'lom-map-row']
        );
    }
    if ($slot->pooltruncated) {
        $rows .= html_writer::div(
            get_string('questionmapping_pooltruncated', 'local_outcomemap'),
            'lom-q-truncated'
        );
    }

    $attributes = ['class' => 'lom-map-section'];
    if ($expandall) {
        $attributes['open'] = 'open';
    }
    $slothtml .= html_writer::tag(
        'details',
        html_writer::tag('summary', $summary, ['class' => 'lom-map-section-head']) . $rows,
        $attributes
    );
}

if ($rendered === 0) {
    echo html_writer::div(get_string('questionmapping_nomatches', 'local_outcomemap'), 'lom-map-empty');
} else {
    echo $slothtml;
}
echo html_writer::end_div();

// Right panel.
echo html_writer::start_div('lom-map-apply');
if (!$canapply) {
    echo html_writer::div(
        html_writer::div(get_string('questionmapping_applyunavailable', 'local_outcomemap'), 'lom-map-apply-hint'),
        'lom-map-apply-body'
    );
} else {
    echo html_writer::div(
        html_writer::div(get_string('apply_heading', 'local_outcomemap'), 'lom-map-apply-title')
            . html_writer::div(get_string('questionmapping_applyhint', 'local_outcomemap'), 'lom-map-apply-hint'),
        'lom-map-apply-head'
    );
    echo html_writer::start_div('lom-map-apply-body');

    echo html_writer::div(get_string('mappingrole', 'local_outcomemap'), 'lom-map-label');
    $roleoptions = '';
    foreach (question_mapping_service::ROLES as $index => $role) {
        $roleoptions .= html_writer::tag(
            'label',
            html_writer::empty_tag('input', [
                'type' => 'radio',
                'name' => 'role',
                'value' => $role,
                'class' => 'lom-map-role-input',
            ] + ($index === 0 ? ['checked' => 'checked'] : []))
            . html_writer::span(get_string('mappingrole_' . $role, 'local_outcomemap'), 'lom-map-role-text'),
            ['class' => 'lom-map-role']
        );
    }
    echo html_writer::div($roleoptions, 'lom-map-roles');

    echo html_writer::div(get_string('outcomestoapply', 'local_outcomemap'), 'lom-map-label');
    echo html_writer::div(
        html_writer::label(
            get_string('filteroutcomes', 'local_outcomemap'),
            'lom-q-oq',
            false,
            ['class' => 'visually-hidden']
        )
        . html_writer::empty_tag('input', [
            'type' => 'search',
            'id' => 'lom-q-oq',
            'name' => 'oq',
            'value' => $outcomequery,
            'placeholder' => get_string('filteroutcomes', 'local_outcomemap'),
            'class' => 'form-control form-control-sm',
        ])
        . html_writer::tag('button', get_string('search'), [
            'type' => 'submit',
            'name' => 'action',
            'value' => 'refilter',
            'class' => 'btn btn-sm btn-secondary',
        ]),
        'lom-map-search'
    );

    // Group by framework: the data's own grouping, as the coverage report uses.
    $groups = [];
    foreach ($outcomes as $outcome) {
        $groups[$outcome->frameworkcode][] = $outcome;
    }
    $listhtml = '';
    foreach ($groups as $frameworkcode => $groupoutcomes) {
        $listhtml .= html_writer::div(s($frameworkcode), 'lom-map-outgroup');
        foreach ($groupoutcomes as $outcome) {
            $label = $outcome->code . ' v' . $outcome->version . ' — '
                . ($outcome->shortstatement ?: shorten_text($outcome->statement, 90));
            $listhtml .= html_writer::tag(
                'label',
                html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'outcomes[]',
                    'value' => $outcome->versionuuid,
                    'class' => 'lom-map-check',
                ])
                . html_writer::span(s($label), 'lom-map-out-label'),
                ['class' => 'lom-map-out', 'title' => s($outcome->statement)]
            );
        }
    }
    if ($listhtml === '') {
        $listhtml = html_writer::div(get_string('nooutcomematches', 'local_outcomemap'), 'lom-map-outempty');
    }
    echo html_writer::div($listhtml, 'lom-map-outlist');
    if ($outcometotal > count($outcomes)) {
        echo html_writer::div(
            get_string('questionmapping_outcomestruncated', 'local_outcomemap', (object) [
                'shown' => count($outcomes),
                'total' => $outcometotal,
            ]),
            'lom-map-apply-hint'
        );
    }

    // The assessed weight is one explicit value for the whole selection. It is
    // not divided across the selected questions: a weight splits one question's
    // marks across the outcomes that question assesses.
    echo html_writer::div(
        html_writer::tag(
            'label',
            html_writer::span(get_string('questionmapping_weight', 'local_outcomemap'), 'lom-map-label')
                . html_writer::empty_tag('input', [
                    'type' => 'text',
                    'name' => 'weight',
                    'id' => 'lom-q-weight',
                    'class' => 'form-control form-control-sm',
                    'placeholder' => '1.0000000000',
                ]),
            ['for' => 'lom-q-weight']
        )
        . html_writer::div(get_string('questionmapping_weighthelp', 'local_outcomemap'), 'lom-map-apply-hint'),
        'lom-q-weightbox'
    );

    echo html_writer::tag('button', get_string('questionmapping_apply', 'local_outcomemap'), [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'apply',
        'class' => 'btn btn-primary lom-map-apply-btn',
    ]);
    echo html_writer::div(get_string('apply_note', 'local_outcomemap'), 'lom-map-apply-note');
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
