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
$PAGE->set_context($context);
$PAGE->set_course($course);
$url = new moodle_url('/local/outcomemap/contentmapping.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('contentmapping_heading', 'local_outcomemap'));
$PAGE->set_heading($course->fullname);

$canmapcourse = has_capability('local/outcomemap:mapcourse', $context)
    && has_capability('moodle/course:update', $context);
$canmapactivities = has_capability('local/outcomemap:mapactivities', $context)
    && has_capability('moodle/course:manageactivities', $context);
$canmanage = $canmapcourse || $canmapactivities;

$action = optional_param('action', '', PARAM_ALPHA);
$targettype = optional_param('targettype', '', PARAM_ALPHANUMEXT);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'submit' && $id) {
    require_sesskey();
    content_mapping_service::submit_for_review($targettype, $id);
    redirect($url, workflow::submission_success_message());
}

if ($action === 'quickmap') {
    require_sesskey();
    if (!$canmanage) {
        throw new required_capability_exception($context, 'local/outcomemap:mapcourse', 'nopermissions', '');
    }
    $targets = optional_param_array('targets', [], PARAM_ALPHANUMEXT);
    $quickdata = [
        'cinstid' => required_param('cinstid', PARAM_INT),
        'itemverid' => required_param('itemverid', PARAM_INT),
        'role' => required_param('role', PARAM_ALPHANUMEXT),
        'weight' => trim(optional_param('weight', '', PARAM_RAW)),
        'effectivefrom' => time(),
    ];
    if (!$targets) {
        redirect(
            $url,
            get_string('quickmap_notargets', 'local_outcomemap'),
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
        try {
            if ($matches[1] === 'cm') {
                content_mapping_service::create_course_module($quickdata + ['cmid' => $targetid]);
            } else {
                content_mapping_service::create_section($quickdata + ['sectionid' => $targetid]);
            }
            $created++;
        } catch (moodle_exception $e) {
            // One rejected target must not discard the rest of the selection.
            $failures[] = $e->getMessage();
        }
    }
    $message = get_string('quickmap_created', 'local_outcomemap', $created);
    if ($failures) {
        $message .= ' ' . get_string('quickmap_skipped', 'local_outcomemap', count($failures))
            . ' ' . implode(' ', array_unique($failures));
    }
    redirect(
        $url,
        $message,
        null,
        $failures ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}

if (in_array($action, ['add', 'edit', 'newversion'], true)) {
    if (!$canmanage) {
        throw new required_capability_exception(
            $context,
            'local/outcomemap:mapcourse',
            'nopermissions',
            ''
        );
    }
    $options = content_mapping_service::editor_options($courseid);
    if (!$options['instances']) {
        redirect(
            $url,
            get_string('nocourseinstance', 'local_outcomemap'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    $formurl = new moodle_url($url, ['action' => $action, 'targettype' => $targettype, 'id' => $id]);
    $form = new content_mapping_form($formurl, ['options' => $options]);
    if ($form->is_cancelled()) {
        redirect($url);
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
        redirect($url, get_string('saved', 'local_outcomemap'));
    }
    if ($id) {
        $record = content_mapping_service::get($targettype, $id);
        if ($targettype === content_mapping_service::TARGET_MODULE) {
            $record->cmid = $record->cmid;
        } else {
            $record->sectionid = $record->sectionid;
        }
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

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('contentmapping_heading', 'local_outcomemap'));
if ($canmanage) {
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'add']),
        get_string('addcontentmapping', 'local_outcomemap')
    );
}
$mappings = content_mapping_service::list_for_course($courseid);
$modinfo = get_fast_modinfo($courseid);

// Group existing mappings by their target so every row can show its own state
// without issuing a query per activity.
$bytarget = ['cm' => [], 'sec' => []];
foreach ($mappings['modules'] as $record) {
    $bytarget['cm'][(int) $record->cmid][] = $record;
}
foreach ($mappings['sections'] as $record) {
    $bytarget['sec'][(int) $record->sectionid][] = $record;
}

/**
 * Render the existing mappings and per-mapping actions for one target.
 *
 * @param array $records Mapping records for the target.
 * @param string $type Mapping target type.
 * @param bool $canedit Whether the current user may edit this target's mappings.
 * @param moodle_url $url Page URL.
 * @return string Rendered HTML.
 */
function local_outcomemap_render_target_mappings(
    array $records,
    string $type,
    bool $canedit,
    moodle_url $url
): string {
    global $OUTPUT;

    if (!$records) {
        return html_writer::span(get_string('coverage_uncovered', 'local_outcomemap'), 'text-muted');
    }
    $items = [];
    foreach ($records as $record) {
        $label = html_writer::tag('strong', s(
            $record->frameworkcode . '.' . $record->outcomecode . ' v' . $record->outcomeversion
        ));
        $meta = get_string('mappingrole_' . $record->role, 'local_outcomemap')
            . ' · ' . workflow::status_label($record->status)
            . ' · ' . s($record->periodcode);
        if ($record->weight !== null) {
            $meta .= ' · ' . get_string('weight', 'local_outcomemap') . ' ' . s($record->weight);
        }
        $actions = [];
        if ($canedit && $record->status === workflow::DRAFT) {
            $actions[] = html_writer::link(new moodle_url($url, [
                'action' => 'edit', 'targettype' => $type, 'id' => $record->id,
            ]), get_string('edit'));
            $actions[] = html_writer::link(new moodle_url($url, [
                'action' => 'submit', 'targettype' => $type, 'id' => $record->id, 'sesskey' => sesskey(),
            ]), workflow::submit_action_label());
        } else if ($canedit && $record->status === workflow::APPROVED) {
            $actions[] = html_writer::link(new moodle_url($url, [
                'action' => 'newversion', 'targettype' => $type, 'id' => $record->id,
            ]), get_string('newmappingversion', 'local_outcomemap'));
        }
        $line = $label . ' ' . html_writer::span($meta, 'text-muted small');
        if ($actions) {
            $line .= ' ' . html_writer::span(implode(' · ', $actions), 'small');
        }
        $items[] = html_writer::div($line, 'local-outcomemap-mapping-item');
    }
    return implode('', $items);
}

$options = $canmanage ? content_mapping_service::editor_options($courseid) : ['instances' => []];
$quickmapavailable = $canmanage && !empty($options['instances']) && !empty($options['outcomes']);

// Outcome options cover every approved outcome on the site, so surface the ones
// this course actually owns first rather than making the user hunt for them.
$groupedoutcomes = [];
if ($quickmapavailable) {
    $own = coverage_service::course_outcome_baseline($courseid);
    $courseoutcomes = [];
    $otheroutcomes = [];
    foreach ($options['outcomes'] as $itemverid => $label) {
        if (isset($own[(int) $itemverid])) {
            $courseoutcomes[$itemverid] = $label;
        } else {
            $otheroutcomes[$itemverid] = $label;
        }
    }
    if ($courseoutcomes) {
        $groupedoutcomes[get_string('quickmap_courseoutcomes', 'local_outcomemap')] = $courseoutcomes;
    }
    if ($otheroutcomes) {
        $groupedoutcomes[get_string('quickmap_otheroutcomes', 'local_outcomemap')] = $otheroutcomes;
    }
}

$table = new html_table();
$table->caption = get_string('contentmapping_heading', 'local_outcomemap');
$table->head = [
    $quickmapavailable ? get_string('quickmap_select', 'local_outcomemap') : '',
    get_string('target', 'local_outcomemap'),
    get_string('mappedoutcomes', 'local_outcomemap'),
];
$table->attributes['class'] = 'generaltable local-outcomemap-contentmap';

$rolelabels = [];
foreach (content_mapping_service::ROLES as $role) {
    $rolelabels[$role] = get_string('mappingrole_' . $role, 'local_outcomemap');
}

/**
 * Build one table row for a mapping target.
 *
 * @param string $key Target key such as cm-12 or sec-3.
 * @param string $name Display name.
 * @param string $indentclass CSS class controlling indentation.
 * @param array $records Existing mapping records.
 * @param string $type Mapping target type.
 * @param bool $canedit Whether the user may map this target.
 * @param bool $selectable Whether a quick-map checkbox is offered.
 * @param moodle_url $url Page URL.
 * @return html_table_row
 */
function local_outcomemap_target_row(
    string $key,
    string $name,
    string $indentclass,
    array $records,
    string $type,
    bool $canedit,
    bool $selectable,
    moodle_url $url
): html_table_row {
    $checkbox = '';
    if ($selectable && $canedit) {
        $checkbox = html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'targets[]',
            'value' => $key,
            'id' => 'omtarget-' . $key,
            'class' => 'local-outcomemap-target-check',
        ]);
    }
    $label = html_writer::label(
        format_string($name),
        $checkbox ? 'omtarget-' . $key : null,
        true,
        ['class' => $indentclass]
    );
    $row = new html_table_row([
        $checkbox,
        $label,
        local_outcomemap_render_target_mappings($records, $type, $canedit, $url),
    ]);
    if (!$records) {
        $row->attributes['class'] = 'local-outcomemap-uncovered';
    }
    return $row;
}

$activitycount = 0;
foreach ($modinfo->get_section_info_all() as $section) {
    $sectionid = (int) $section->id;
    $table->data[] = local_outcomemap_target_row(
        'sec-' . $sectionid,
        get_section_name($courseid, $section->section),
        'fw-bold',
        $bytarget['sec'][$sectionid] ?? [],
        content_mapping_service::TARGET_SECTION,
        $canmapcourse,
        $quickmapavailable,
        $url
    );
    foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
        $cm = $modinfo->get_cm($cmid);
        if ($cm->deletioninprogress || !$cm->uservisible) {
            continue;
        }
        $activitycount++;
        $cmcontext = context_module::instance($cm->id);
        $canedit = has_capability('local/outcomemap:mapactivities', $cmcontext)
            && has_capability('moodle/course:manageactivities', $cmcontext);
        $name = $cm->get_formatted_name();
        $icon = $OUTPUT->pix_icon('monologo', '', $cm->modname, ['class' => 'icon']);
        $row = local_outcomemap_target_row(
            'cm-' . (int) $cm->id,
            $name,
            'ps-4',
            $bytarget['cm'][(int) $cm->id] ?? [],
            content_mapping_service::TARGET_MODULE,
            $canedit,
            $quickmapavailable,
            $url
        );
        $row->cells[1]->text = $icon . ' ' . $row->cells[1]->text;
        $table->data[] = $row;
    }
}

if ($quickmapavailable) {
    $roleselect = html_writer::select($rolelabels, 'role', content_mapping_service::ROLE_TEACHES, false, [
        'id' => 'omrole', 'class' => 'custom-select form-select',
    ]);
    $outcomeselect = html_writer::select($groupedoutcomes, 'itemverid', '', ['' => 'choosedots'], [
        'id' => 'omitemverid', 'class' => 'custom-select form-select',
    ]);
    $instanceselect = html_writer::select($options['instances'], 'cinstid', '', false, [
        'id' => 'omcinstid', 'class' => 'custom-select form-select',
    ]);
    $controls = html_writer::div(
        html_writer::div(
            html_writer::label(get_string('outcomeversion', 'local_outcomemap'), 'omitemverid')
                . $outcomeselect,
            'col-md-5'
        )
        . html_writer::div(
            html_writer::label(get_string('mappingrole', 'local_outcomemap'), 'omrole') . $roleselect,
            'col-md-3'
        )
        . html_writer::div(
            html_writer::label(get_string('periodcode', 'local_outcomemap'), 'omcinstid') . $instanceselect,
            'col-md-2'
        )
        . html_writer::div(
            html_writer::label(get_string('weight', 'local_outcomemap'), 'omweight')
                . html_writer::empty_tag('input', [
                    'type' => 'text',
                    'name' => 'weight',
                    'id' => 'omweight',
                    'class' => 'form-control',
                    'size' => 6,
                ]),
            'col-md-2'
        ),
        'row g-2 align-items-end mb-2'
    );
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'quickmap']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    echo html_writer::div(
        html_writer::tag('h3', get_string('quickmap_heading', 'local_outcomemap'), ['class' => 'h5'])
            . html_writer::div(get_string('quickmap_help', 'local_outcomemap'), 'text-muted mb-2')
            . $controls,
        'card card-body mb-3'
    );
    echo html_writer::div(html_writer::table($table), 'table-responsive');
    echo html_writer::div(
        html_writer::tag('button', get_string('quickmap_submit', 'local_outcomemap'), [
            'type' => 'submit',
            'class' => 'btn btn-primary',
        ]),
        'mb-3'
    );
    echo html_writer::end_tag('form');
} else {
    echo html_writer::div(html_writer::table($table), 'table-responsive');
    if ($canmanage && empty($options['instances'])) {
        echo $OUTPUT->notification(
            get_string('nocourseinstance', 'local_outcomemap'),
            \core\output\notification::NOTIFY_WARNING
        );
    }
}
if (!$activitycount) {
    echo $OUTPUT->notification(
        get_string('nocourseactivities', 'local_outcomemap'),
        \core\output\notification::NOTIFY_INFO
    );
}
echo $OUTPUT->footer();
