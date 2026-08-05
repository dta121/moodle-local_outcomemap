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
 * Plugin callbacks for local_outcomemap.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\workflow;

/**
 * Build snapshot form selectors without per-row lookups.
 *
 * @return array
 */
function local_outcomemap_snapshot_options(): array {
    global $DB;
    $programs = [];
    foreach ($DB->get_records('local_outcomemap_program', ['status' => workflow::APPROVED], 'code, name') as $program) {
        $programs[(int) $program->id] = $program->code . ' — ' . format_string($program->name);
    }
    $cohorts = [];
    foreach ($DB->get_records('cohort', null, 'name, idnumber') as $cohort) {
        $label = format_string($cohort->name);
        if ($cohort->idnumber !== '') {
            $label .= ' [' . s($cohort->idnumber) . ']';
        }
        $cohorts[(int) $cohort->id] = $label;
    }
    return [
        'programs' => $programs,
        'cohorts' => $cohorts,
        'periods' => local_outcomemap_snapshot_periods(),
    ];
}

/**
 * Reporting periods that actually resolve to course instances, per program.
 *
 * A capture is built from the approved, confirmed course instances carrying the
 * chosen period code, so a period that matches none produces an empty capture
 * and the attempt fails. Offering only the periods that resolve makes that
 * outcome unreachable from the form, rather than something the operator finds
 * out by submitting.
 *
 * The filters mirror aggregate_service::course_instances() exactly; if that
 * query changes, this one has to change with it.
 *
 * @return array<int,array<string,int>> Course-instance counts by program ID then period code.
 */
function local_outcomemap_snapshot_periods(): array {
    global $DB;
    $now = time();
    $sql = "SELECT pc.programid AS programid, ci.periodcode AS periodcode,
                   COUNT(DISTINCT ci.id) AS instances
              FROM {local_outcomemap_progcourse} pc
              JOIN {local_outcomemap_course} cc ON cc.id = pc.courseid
              JOIN {local_outcomemap_cinst} ci ON ci.courseid = cc.id
              JOIN {course} mc ON mc.id = ci.moodlecourseid
             WHERE pc.status = :pcstatus AND ci.status = :cistatus AND ci.confirmed = 1
               AND pc.effectivefrom <= :at1
               AND (pc.effectiveto IS NULL OR pc.effectiveto > :at2)
          GROUP BY pc.programid, ci.periodcode
          ORDER BY pc.programid, ci.periodcode";
    $periods = [];
    // A recordset, not get_records_sql(): that keys rows by the first column, and
    // programid repeats across periods, so every period but one would vanish.
    $rs = $DB->get_recordset_sql($sql, [
        'pcstatus' => workflow::APPROVED,
        'cistatus' => workflow::APPROVED,
        'at1' => $now,
        'at2' => $now,
    ]);
    foreach ($rs as $row) {
        $periods[(int) $row->programid][(string) $row->periodcode] = (int) $row->instances;
    }
    $rs->close();
    return $periods;
}

/**
 * Add an explicit outcome-mapping entry point to standard activity settings forms.
 *
 * @param moodleform_mod $formwrapper Module form wrapper.
 * @param MoodleQuickForm $mform QuickForm instance.
 */
function local_outcomemap_coursemodule_standard_elements(
    moodleform_mod $formwrapper,
    MoodleQuickForm $mform
): void {
    global $COURSE;

    $options = content_mapping_service::module_form_options((int) $COURSE->id);
    if (!$options) {
        return;
    }
    $roles = [];
    foreach (content_mapping_service::ROLES as $role) {
        $roles[$role] = get_string('mappingrole_' . $role, 'local_outcomemap');
    }
    $mform->addElement('header', 'outcomemapsection', get_string('modulemapping_heading', 'local_outcomemap'));
    $mform->addElement('static', 'outcomemapintro', '', get_string('modulemapping_intro', 'local_outcomemap'));
    $mform->addElement(
        'select',
        'outcomemap_cinstid',
        get_string('courseinstance', 'local_outcomemap'),
        $options['instances']
    );
    $mform->addElement(
        'autocomplete',
        'outcomemap_itemverid',
        get_string('outcomeversion', 'local_outcomemap'),
        $options['outcomes']
    );
    $mform->addElement('select', 'outcomemap_role', get_string('mappingrole', 'local_outcomemap'), $roles);
    $mform->addElement('text', 'outcomemap_weight', get_string('mappingweight', 'local_outcomemap'));
    $mform->setType('outcomemap_weight', PARAM_RAW_TRIMMED);
    $mform->addHelpButton('outcomemap_weight', 'mappingweight', 'local_outcomemap');
    $mform->addElement('text', 'outcomemap_priority', get_string('priority', 'local_outcomemap'));
    $mform->setType('outcomemap_priority', PARAM_INT);
    $mform->setDefault('outcomemap_priority', 0);
    $mform->addElement(
        'textarea',
        'outcomemap_notes',
        get_string('notes', 'local_outcomemap'),
        ['rows' => 2, 'cols' => 60]
    );
    $mform->setType('outcomemap_notes', PARAM_TEXT);
    $mform->addElement(
        'date_time_selector',
        'outcomemap_effectivefrom',
        get_string('effectivefrom', 'local_outcomemap')
    );
    $mform->setDefault('outcomemap_effectivefrom', time());
    $mform->addElement(
        'date_time_selector',
        'outcomemap_effectiveto',
        get_string('effectiveto', 'local_outcomemap'),
        ['optional' => true]
    );
}

/**
 * Validate activity mapping fields before a new course-module ID exists.
 *
 * @param moodleform_mod $formwrapper Module form wrapper.
 * @param array $data Submitted form data.
 * @return array Validation errors.
 */
function local_outcomemap_coursemodule_validation(moodleform_mod $formwrapper, array $data): array {
    global $COURSE;
    return content_mapping_service::validate_module_form_data((int) $COURSE->id, $data);
}

/**
 * Persist the explicit activity mapping after Moodle has assigned the module ID.
 *
 * @param stdClass $moduleinfo Saved module information.
 * @param stdClass $course Course record.
 * @return stdClass Unmodified module information.
 */
function local_outcomemap_coursemodule_edit_post_actions(stdClass $moduleinfo, stdClass $course): stdClass {
    if (!empty($moduleinfo->outcomemap_itemverid)) {
        content_mapping_service::save_module_form_mapping((int) $moduleinfo->coursemodule, (array) $moduleinfo);
    }
    return $moduleinfo;
}

/**
 * Whether the companion question-bank plugin is installed and enabled.
 *
 * The question mapping page links into `qbank_outcomemap` for per-question and
 * bulk editing, so it is offered only when that plugin can serve those pages.
 *
 * @return bool
 */
function local_outcomemap_qbank_available(): bool {
    if (!core_component::get_component_directory('qbank_outcomemap')) {
        return false;
    }
    return \core\plugininfo\qbank::is_plugin_enabled('qbank_outcomemap');
}

/**
 * Add course outcome-mapping pages to course navigation.
 *
 * @param navigation_node $navigation Course navigation node.
 * @param stdClass $course Course record.
 * @param context_course $context Course context.
 */
function local_outcomemap_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    $canviewdefinitions = has_capability('local/outcomemap:viewdefinitions', $context);
    $canviewresults = has_capability('local/outcomemap:viewownresults', $context);
    $canviewallresults = has_capability('local/outcomemap:viewallresults', $context);
    $canreleaseresults = has_capability('local/outcomemap:managepolicies', $context)
        && has_capability('moodle/course:update', $context);
    if (!$canviewdefinitions && !$canviewresults && !$canreleaseresults && !$canviewallresults) {
        return;
    }
    if ($canviewresults) {
        $navigation->add(
            get_string('nav_outcomeresults', 'local_outcomemap'),
            new moodle_url('/local/outcomemap/results.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_outcomemap_results'
        );
    }
    if (!$canviewdefinitions && !$canreleaseresults && !$canviewallresults) {
        return;
    }
    $node = $navigation->add(
        get_string('courseoutcomemapping', 'local_outcomemap'),
        null,
        navigation_node::TYPE_CONTAINER,
        null,
        'local_outcomemap'
    );
    // Cohort attainment is other people's results, so it follows the all-results
    // capability rather than the definitions one the mapping pages use.
    if ($canviewallresults) {
        $node->add(
            get_string('nav_attainment', 'local_outcomemap'),
            new moodle_url('/local/outcomemap/attainment.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING
        );
    }
    if ($canviewdefinitions) {
        $node->add(
            get_string('nav_coverage', 'local_outcomemap'),
            new moodle_url('/local/outcomemap/coverage.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING
        );
        $node->add(
            get_string('nav_contentmapping', 'local_outcomemap'),
            new moodle_url('/local/outcomemap/contentmapping.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING
        );
        if (local_outcomemap_qbank_available()) {
            $node->add(
                get_string('nav_questionmapping', 'local_outcomemap'),
                new moodle_url('/local/outcomemap/questionmapping.php', ['courseid' => $course->id]),
                navigation_node::TYPE_SETTING
            );
        }
        if (\local_outcomemap\local\feature::remediation_enabled()) {
            $node->add(
                get_string('nav_remediation', 'local_outcomemap'),
                new moodle_url('/local/outcomemap/remediation.php', ['courseid' => $course->id]),
                navigation_node::TYPE_SETTING
            );
        }
    }
    if ($canreleaseresults) {
        $node->add(
            get_string('nav_manualrelease', 'local_outcomemap'),
            new moodle_url('/local/outcomemap/manualrelease.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING
        );
    }
}
