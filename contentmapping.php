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
 * Course content mapping management page.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\form\content_mapping_form;
use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\service\coverage_service;
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
require_once($CFG->libdir . '/formslib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/outcomemap:viewdefinitions', $context);

$canmapcourse = has_capability('local/outcomemap:mapcourse', $context)
    && has_capability('moodle/course:update', $context);
$canmapactivities = has_capability('local/outcomemap:mapactivities', $context)
    && has_capability('moodle/course:manageactivities', $context);
$canmanage = $canmapcourse || $canmapactivities;

$action = optional_param('action', '', PARAM_ALPHA);
$targettype = optional_param('targettype', '', PARAM_ALPHANUMEXT);
$id = optional_param('id', 0, PARAM_INT);
$search = trim(optional_param('q', '', PARAM_TEXT));
$outcomequery = trim(optional_param('oq', '', PARAM_TEXT));
$contentfilter = optional_param('cf', 'all', PARAM_ALPHA);
$expandall = optional_param('expand', 1, PARAM_BOOL);

if (!in_array($contentfilter, ['all', 'unmapped', 'assessments'], true)) {
    $contentfilter = 'all';
}

$stateparams = [
    'courseid' => $courseid,
    'q' => $search,
    'oq' => $outcomequery,
    'cf' => $contentfilter,
    'expand' => $expandall ? 1 : 0,
];
$url = new moodle_url('/local/outcomemap/contentmapping.php', ['courseid' => $courseid]);
$stateurl = new moodle_url('/local/outcomemap/contentmapping.php', $stateparams);

if ($action === 'submit' && $id) {
    require_sesskey();
    content_mapping_service::submit_for_review($targettype, $id);
    redirect($stateurl, workflow::submission_success_message());
}

if ($action === 'delete' && $id) {
    require_sesskey();
    content_mapping_service::delete_draft($targettype, $id);
    redirect($stateurl, get_string('mappingremoved', 'local_outcomemap'));
}

// The outcome filter and content search live inside the apply form, so their
// submit buttons post and redirect back to a plain GET view of the same state.
if ($action === 'refilter') {
    require_sesskey();
    redirect($stateurl);
}

if ($action === 'apply') {
    require_sesskey();
    if (!$canmanage) {
        throw new required_capability_exception($context, 'local/outcomemap:mapcourse', 'nopermissions', '');
    }
    $targets = optional_param_array('targets', [], PARAM_ALPHANUMEXT);
    $itemverids = optional_param_array('itemverids', [], PARAM_INT);
    $role = required_param('role', PARAM_ALPHANUMEXT);
    $cinstid = required_param('cinstid', PARAM_INT);
    $weight = trim(optional_param('weight', '', PARAM_RAW));

    if (!$targets || !$itemverids) {
        redirect(
            $stateurl,
            get_string('apply_incomplete', 'local_outcomemap'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    $created = 0;
    $failures = [];
    foreach ($targets as $target) {
        // Values are rendered as cm-<id> or sec-<id>; anything else is ignored.
        if (!preg_match('/^(cm|sec)-([0-9]+)$/', (string) $target, $matches)) {
            continue;
        }
        $targetid = (int) $matches[2];
        foreach ($itemverids as $itemverid) {
            $data = [
                'cinstid' => $cinstid,
                'itemverid' => (int) $itemverid,
                'role' => $role,
                'weight' => $weight,
                'effectivefrom' => time(),
            ];
            try {
                if ($matches[1] === 'cm') {
                    content_mapping_service::create_course_module($data + ['cmid' => $targetid]);
                } else {
                    content_mapping_service::create_section($data + ['sectionid' => $targetid]);
                }
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

$PAGE->set_context($context);
$PAGE->set_course($course);
// The canonical URL must stay free of view state: Moodle matches the course
// navigation node against $PAGE->url with URL_MATCH_EXACT, and that match is
// what renders the report selector shared with the other course pages.
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('contentmapping_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

// The single-mapping editor form is retained for editing an existing mapping.
if (in_array($action, ['add', 'edit', 'newversion'], true)) {
    if (!$canmanage) {
        throw new required_capability_exception($context, 'local/outcomemap:mapcourse', 'nopermissions', '');
    }
    $options = content_mapping_service::editor_options($courseid);
    if (!$options['instances']) {
        redirect(
            $stateurl,
            get_string('nocourseinstance', 'local_outcomemap'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    $formurl = new moodle_url($url, ['action' => $action, 'targettype' => $targettype, 'id' => $id]);
    $form = new content_mapping_form($formurl, ['options' => $options]);
    if ($form->is_cancelled()) {
        redirect($stateurl);
    }
    if ($data = $form->get_data()) {
        $targettype = $data->targettype;
        $payload = (array) $data;
        if ($targettype === content_mapping_service::TARGET_MODULE) {
            $payload['cmid'] = (int) $data->cmid;
        } else {
            $payload['sectionid'] = (int) $data->sectionid;
        }
        if ($action === 'newversion') {
            content_mapping_service::create_version($targettype, $id, $payload);
        } else if ($action === 'edit') {
            content_mapping_service::update_draft($targettype, $id, $payload);
        } else if ($targettype === content_mapping_service::TARGET_MODULE) {
            content_mapping_service::create_course_module($payload);
        } else {
            content_mapping_service::create_section($payload);
        }
        redirect($stateurl, get_string('saved', 'local_outcomemap'));
    }
    if ($id) {
        $record = content_mapping_service::get($targettype, $id, $courseid);
        if ($action === 'newversion') {
            $record->effectivefrom = time();
            $record->effectiveto = null;
        }
        $form->set_data($record);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($action === 'newversion' ? 'newmappingversion' :
        ($action === 'edit' ? 'editcontentmapping' : 'addcontentmapping'), 'local_outcomemap'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

$mappings = content_mapping_service::list_for_course($courseid);
$modinfo = get_fast_modinfo($courseid);

// Group existing mappings by target so each row renders without a query.
$bytarget = ['cm' => [], 'sec' => []];
foreach ($mappings['modules'] as $record) {
    $bytarget['cm'][(int) $record->cmid][] = $record;
}
foreach ($mappings['sections'] as $record) {
    $bytarget['sec'][(int) $record->sectionid][] = $record;
}

$options = $canmanage ? content_mapping_service::editor_options($courseid) : ['instances' => [], 'outcomes' => []];
$canapply = $canmanage && !empty($options['instances']) && !empty($options['outcomes']);

$needle = core_text::strtolower($search);

/**
 * Render the mapping chips for one target.
 *
 * @param array $records Mapping records for the target.
 * @param string $type Mapping target type.
 * @param bool $canedit Whether the user may change this target's mappings.
 * @param moodle_url $stateurl Page URL carrying the current view state.
 * @return string Rendered HTML.
 */
function local_outcomemap_chips(array $records, string $type, bool $canedit, moodle_url $stateurl): string {
    if (!$records) {
        return html_writer::span(get_string('notmapped', 'local_outcomemap'), 'lom-map-unmapped');
    }
    $chips = '';
    foreach ($records as $record) {
        $isassess = $record->role === content_mapping_service::ROLE_ASSESSES;
        $rolelabel = get_string('mappingrole_' . $record->role, 'local_outcomemap');
        $title = $record->frameworkcode . '.' . $record->outcomecode . ' v' . $record->outcomeversion
            . ' — ' . $rolelabel . ' · ' . workflow::status_label($record->status);
        $inner = s($record->frameworkcode . '.' . $record->outcomecode)
            . html_writer::span(core_text::substr($rolelabel, 0, 1), 'lom-map-chip-role');
        // Only a draft can be submitted or removed; approved mappings are governed history.
        if ($canedit && $record->status === workflow::DRAFT) {
            $inner .= html_writer::link(
                new moodle_url($stateurl, [
                    'action' => 'submit',
                    'targettype' => $type,
                    'id' => $record->id,
                    'sesskey' => sesskey(),
                ]),
                workflow::submit_action_label(),
                [
                    'class' => 'lom-map-chip-submit',
                    'aria-label' => workflow::submit_action_label() . ': ' . $title,
                ]
            );
            $inner .= html_writer::link(
                new moodle_url($stateurl, [
                    'action' => 'delete',
                    'targettype' => $type,
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

// Build the section/activity tree, applying the search and content filter.
$tree = [];
$totalitems = 0;
$mappeditems = 0;
foreach ($modinfo->get_section_info_all() as $section) {
    $sectionid = (int) $section->id;
    $sectionname = get_section_name($courseid, $section->section);
    $children = [];
    foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
        $cm = $modinfo->get_cm($cmid);
        if ($cm->deletioninprogress || !$cm->uservisible) {
            continue;
        }
        $records = $bytarget['cm'][(int) $cm->id] ?? [];
        $totalitems++;
        if ($records) {
            $mappeditems++;
        }
        if (
            $needle !== ''
                && core_text::strpos(core_text::strtolower($cm->get_formatted_name()), $needle) === false
        ) {
            continue;
        }
        if ($contentfilter === 'unmapped' && $records) {
            continue;
        }
        if ($contentfilter === 'assessments' && !plugin_supports('mod', $cm->modname, FEATURE_GRADE_HAS_GRADE, false)) {
            continue;
        }
        $cmcontext = context_module::instance($cm->id);
        $children[] = (object) [
            'key' => 'cm-' . (int) $cm->id,
            'name' => $cm->get_formatted_name(),
            'modname' => $cm->modname,
            'records' => $records,
            'type' => content_mapping_service::TARGET_MODULE,
            'canedit' => has_capability('local/outcomemap:mapactivities', $cmcontext)
                && has_capability('moodle/course:manageactivities', $cmcontext),
        ];
    }
    $sectionmatches = $needle === '' || core_text::strpos(core_text::strtolower($sectionname), $needle) !== false;
    if (!$children && !($sectionmatches && $contentfilter === 'all')) {
        continue;
    }
    $tree[] = (object) [
        'key' => 'sec-' . $sectionid,
        'name' => $sectionname,
        'records' => $bytarget['sec'][$sectionid] ?? [],
        'children' => $children,
        'canedit' => $canmapcourse,
    ];
}

echo $OUTPUT->header();

// Toolbar.
$toolbaractions = html_writer::link(
    new moodle_url('/local/outcomemap/coverage.php', ['courseid' => $courseid]),
    get_string('coverage_heading', 'local_outcomemap'),
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::div(
    html_writer::span(get_string('coverage_report', 'local_outcomemap'), 'lom-cov-toolbar-label')
        . html_writer::span(get_string('contentmapping_heading', 'local_outcomemap'), 'lom-cov-chip')
        . html_writer::div($toolbaractions, 'lom-cov-toolbar-actions'),
    'lom-cov-toolbar'
);
echo html_writer::tag('h2', get_string('contentmapping_heading', 'local_outcomemap'), ['class' => 'lom-cov-title']);
echo html_writer::div(
    get_string('contentmapping_subtitle', 'local_outcomemap')
        . ' ' . get_string('contentmapping_stats', 'local_outcomemap', [
            'mapped' => $mappeditems,
            'total' => $totalitems,
        ]),
    'lom-cov-subtitle'
);

if (!$canapply && $canmanage && empty($options['instances'])) {
    echo $OUTPUT->notification(
        get_string('nocourseinstance', 'local_outcomemap'),
        \core\output\notification::NOTIFY_WARNING
    );
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'lom-map']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cf', 'value' => $contentfilter]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'expand', 'value' => $expandall ? 1 : 0]);
echo html_writer::start_div('lom-map-layout');

// Left panel.
echo html_writer::start_div('lom-map-content');
$chips = '';
foreach (['all', 'unmapped', 'assessments'] as $key) {
    $chipurl = new moodle_url($stateurl, ['cf' => $key]);
    $chips .= html_writer::link(
        $chipurl,
        get_string('contentfilter_' . $key, 'local_outcomemap'),
        ['class' => 'lom-map-filter' . ($contentfilter === $key ? ' lom-map-filter-active' : '')]
    );
}
echo html_writer::div(
    html_writer::div(
        html_writer::label(
            get_string('contentsearch', 'local_outcomemap'),
            'lom-map-q',
            false,
            ['class' => 'sr-only visually-hidden']
        )
        . html_writer::empty_tag('input', [
            'type' => 'search',
            'id' => 'lom-map-q',
            'name' => 'q',
            'value' => $search,
            'placeholder' => get_string('contentsearchplaceholder', 'local_outcomemap'),
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

if (!$tree) {
    echo html_writer::div(get_string('contentnomatches', 'local_outcomemap'), 'lom-map-empty');
} else {
    foreach ($tree as $section) {
        $sectionchips = local_outcomemap_chips(
            $section->records,
            content_mapping_service::TARGET_SECTION,
            $section->canedit,
            $stateurl
        );
        $summary = html_writer::span('', 'lom-map-chev')
            . html_writer::span(format_string($section->name), 'lom-map-section-name')
            . html_writer::span(
                get_string('nitems', 'local_outcomemap', count($section->children)),
                'lom-map-section-count'
            )
            . html_writer::span($sectionchips, 'lom-map-chips');

        $rows = '';
        // The section itself is a mapping target, offered as its own row so the
        // summary stays a pure disclosure control.
        if ($canapply && $section->canedit) {
            $rows .= html_writer::tag(
                'label',
                html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'targets[]',
                    'value' => $section->key,
                    'class' => 'lom-map-check',
                ])
                . html_writer::span('§', 'lom-map-icon')
                . html_writer::span(
                    get_string('wholesection', 'local_outcomemap', format_string($section->name)),
                    'lom-map-node-name lom-map-node-section'
                ),
                ['class' => 'lom-map-row lom-map-row-sectiontarget']
            );
        }
        foreach ($section->children as $child) {
            $check = ($canapply && $child->canedit)
                ? html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'targets[]',
                    'value' => $child->key,
                    'class' => 'lom-map-check',
                ])
                : html_writer::span('', 'lom-map-check-spacer');
            $rows .= html_writer::tag(
                'label',
                $check
                . html_writer::span(
                    $OUTPUT->pix_icon('monologo', '', $child->modname, ['class' => 'icon']),
                    'lom-map-icon'
                )
                . html_writer::span(format_string($child->name), 'lom-map-node-name')
                . html_writer::span(
                    local_outcomemap_chips($child->records, $child->type, $child->canedit, $stateurl),
                    'lom-map-chips'
                ),
                ['class' => 'lom-map-row']
            );
        }

        $attributes = ['class' => 'lom-map-section'];
        if ($expandall) {
            $attributes['open'] = 'open';
        }
        echo html_writer::tag(
            'details',
            html_writer::tag('summary', $summary, ['class' => 'lom-map-section-head']) . $rows,
            $attributes
        );
    }
}
echo html_writer::end_div();

// Right panel.
echo html_writer::start_div('lom-map-apply');
if (!$canapply) {
    echo html_writer::div(
        html_writer::div(get_string('apply_unavailable', 'local_outcomemap'), 'lom-map-apply-hint'),
        'lom-map-apply-body'
    );
} else {
    echo html_writer::div(
        html_writer::div(get_string('apply_heading', 'local_outcomemap'), 'lom-map-apply-title')
            . html_writer::div(get_string('apply_hint', 'local_outcomemap'), 'lom-map-apply-hint'),
        'lom-map-apply-head'
    );
    echo html_writer::start_div('lom-map-apply-body');

    // Role: the plugin supports five roles, all offered in the design's control.
    echo html_writer::div(get_string('mappingrole', 'local_outcomemap'), 'lom-map-label');
    $roleoptions = '';
    foreach (content_mapping_service::ROLES as $index => $role) {
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

    // Outcomes, grouped by framework with the course's own outcomes first.
    echo html_writer::div(get_string('outcomestoapply', 'local_outcomemap'), 'lom-map-label');
    echo html_writer::div(
        html_writer::label(
            get_string('filteroutcomes', 'local_outcomemap'),
            'lom-map-oq',
            false,
            ['class' => 'sr-only visually-hidden']
        )
        . html_writer::empty_tag('input', [
            'type' => 'search',
            'id' => 'lom-map-oq',
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

    $own = coverage_service::course_outcome_baseline($courseid);
    $groups = [];
    $outneedle = core_text::strtolower($outcomequery);
    foreach ($options['outcomes'] as $itemverid => $label) {
        if ($outneedle !== '' && core_text::strpos(core_text::strtolower($label), $outneedle) === false) {
            continue;
        }
        $groupkey = isset($own[(int) $itemverid]) ? 'course' : 'other';
        $groups[$groupkey][$itemverid] = $label;
    }
    $listhtml = '';
    foreach (['course' => 'quickmap_courseoutcomes', 'other' => 'quickmap_otheroutcomes'] as $groupkey => $stringkey) {
        if (empty($groups[$groupkey])) {
            continue;
        }
        $listhtml .= html_writer::div(get_string($stringkey, 'local_outcomemap'), 'lom-map-outgroup');
        foreach ($groups[$groupkey] as $itemverid => $label) {
            $listhtml .= html_writer::tag(
                'label',
                html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'itemverids[]',
                    'value' => (int) $itemverid,
                    'class' => 'lom-map-check',
                ])
                . html_writer::span(s($label), 'lom-map-out-label'),
                ['class' => 'lom-map-out']
            );
        }
    }
    if ($listhtml === '') {
        $listhtml = html_writer::div(get_string('nooutcomematches', 'local_outcomemap'), 'lom-map-outempty');
    }
    echo html_writer::div($listhtml, 'lom-map-outlist');

    // Reporting period and weight, collapsed by default as in the design.
    $instanceselect = html_writer::select($options['instances'], 'cinstid', '', false, [
        'id' => 'lom-map-cinst', 'class' => 'custom-select form-select form-select-sm',
    ]);
    echo html_writer::tag(
        'details',
        html_writer::tag('summary', get_string('periodandweight', 'local_outcomemap'), ['class' => 'lom-map-adv-head'])
            . html_writer::div(
                html_writer::tag(
                    'label',
                    get_string('periodcode', 'local_outcomemap') . $instanceselect,
                    ['for' => 'lom-map-cinst', 'class' => 'lom-map-adv-field']
                )
                . html_writer::tag(
                    'label',
                    get_string('weight', 'local_outcomemap')
                        . html_writer::empty_tag('input', [
                            'type' => 'text',
                            'name' => 'weight',
                            'id' => 'lom-map-weight',
                            'class' => 'form-control form-control-sm',
                        ]),
                    ['for' => 'lom-map-weight', 'class' => 'lom-map-adv-field']
                ),
                'lom-map-adv-grid'
            ),
        ['class' => 'lom-map-adv']
    );

    echo html_writer::tag('button', get_string('applymappings', 'local_outcomemap'), [
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
