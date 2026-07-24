<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\form;

use local_outcomemap\local\decimal;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\suppression_service;
use local_outcomemap\local\validation_exception;

/**
 * Versioned calculation-policy editor.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class policy_form extends \moodleform {
    /**
     * Defines the form elements.
     */
    public function definition(): void {
        $mform = $this->_form;
        $options = $this->_customdata['options'];
        $lockedscope = !empty($this->_customdata['lockedscope']);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('text', 'name', get_string('name', 'local_outcomemap'), [
            'maxlength' => 255,
            'size' => 60,
        ]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');

        $policytypes = [
            policy_service::TYPE_ATTEMPT_SELECTION => get_string('policytype_attempt_selection', 'local_outcomemap'),
            policy_service::TYPE_CALCULATION => get_string('policytype_calculation', 'local_outcomemap'),
            policy_service::TYPE_RELEASE => get_string('policytype_release', 'local_outcomemap'),
            policy_service::TYPE_ACCREDITATION => get_string('policytype_accreditation', 'local_outcomemap'),
        ];
        $mform->addElement('select', 'policytype', get_string('policytype', 'local_outcomemap'), $policytypes);

        $scopetypes = [
            policy_service::SCOPE_INSTITUTION => get_string('policyscope_institution', 'local_outcomemap'),
            policy_service::SCOPE_PROGRAM => get_string('policyscope_program', 'local_outcomemap'),
            policy_service::SCOPE_CATALOG_COURSE => get_string('policyscope_catalog_course', 'local_outcomemap'),
            policy_service::SCOPE_COURSE_INSTANCE => get_string('policyscope_course_instance', 'local_outcomemap'),
            policy_service::SCOPE_ASSESSMENT => get_string('policyscope_assessment', 'local_outcomemap'),
        ];
        $mform->addElement('select', 'scopetype', get_string('policyscope', 'local_outcomemap'), $scopetypes);
        $mform->setDefault('scopetype', policy_service::SCOPE_INSTITUTION);
        $mform->addElement('autocomplete', 'programid', get_string('program', 'local_outcomemap'),
            [0 => get_string('choosedots')] + $options['programs']);
        $mform->hideIf('programid', 'scopetype', 'neq', policy_service::SCOPE_PROGRAM);
        $mform->addElement('autocomplete', 'catalogcourseid', get_string('catalogcourse', 'local_outcomemap'),
            [0 => get_string('choosedots')] + $options['catalogcourses']);
        $mform->hideIf('catalogcourseid', 'scopetype', 'neq', policy_service::SCOPE_CATALOG_COURSE);
        $mform->addElement('autocomplete', 'courseinstanceid', get_string('courseinstance', 'local_outcomemap'),
            [0 => get_string('choosedots')] + $options['courseinstances']);
        $mform->hideIf('courseinstanceid', 'scopetype', 'neq', policy_service::SCOPE_COURSE_INSTANCE);
        $mform->addElement('autocomplete', 'assessmentid', get_string('assessment', 'local_outcomemap'),
            [0 => get_string('choosedots')] + $options['assessments']);
        $mform->hideIf('assessmentid', 'scopetype', 'neq', policy_service::SCOPE_ASSESSMENT);

        if ($lockedscope) {
            $mform->freeze([
                'policytype',
                'scopetype',
                'programid',
                'catalogcourseid',
                'courseinstanceid',
                'assessmentid',
            ]);
        }

        $mform->addElement('header', 'policyconfiguration', get_string('policyconfiguration', 'local_outcomemap'));
        $methods = [
            '' => get_string('choosedots'),
            policy_service::METHOD_FIRST_COMPLETED => get_string('attemptmethod_first_completed', 'local_outcomemap'),
            policy_service::METHOD_LATEST_COMPLETED => get_string('attemptmethod_latest_completed', 'local_outcomemap'),
            policy_service::METHOD_HIGHEST_GRADED => get_string('attemptmethod_highest_graded', 'local_outcomemap'),
            policy_service::METHOD_QUIZ_GRADE => get_string('attemptmethod_quiz_grade', 'local_outcomemap'),
            policy_service::METHOD_ALL_COMPLETED => get_string('attemptmethod_all_completed', 'local_outcomemap'),
        ];
        $mform->addElement('select', 'attemptmethod', get_string('attemptselectionmethod', 'local_outcomemap'), $methods);
        $mform->hideIf('attemptmethod', 'policytype', 'neq', policy_service::TYPE_ATTEMPT_SELECTION);

        $releasemodes = [
            '' => get_string('choosedots'),
            policy_service::RELEASE_FULLY_GRADED => get_string('releasemode_fully_graded', 'local_outcomemap'),
            policy_service::RELEASE_GRADE_VISIBLE => get_string('releasemode_grade_visible', 'local_outcomemap'),
            policy_service::RELEASE_QUIZ_CLOSED => get_string('releasemode_quiz_closed', 'local_outcomemap'),
            policy_service::RELEASE_SCHEDULED => get_string('releasemode_scheduled', 'local_outcomemap'),
            policy_service::RELEASE_MANUAL => get_string('releasemode_manual', 'local_outcomemap'),
        ];
        $mform->addElement('select', 'releasemode', get_string('feedbackreleasemode', 'local_outcomemap'),
            $releasemodes);
        $mform->hideIf('releasemode', 'policytype', 'neq', policy_service::TYPE_RELEASE);
        $mform->addElement('date_time_selector', 'releaseat', get_string('feedbackreleaseat', 'local_outcomemap'));
        $mform->hideIf('releaseat', 'policytype', 'neq', policy_service::TYPE_RELEASE);
        $mform->hideIf('releaseat', 'releasemode', 'neq', policy_service::RELEASE_SCHEDULED);

        $mform->addElement('text', 'mincohortsize', get_string('minimumcohortsize', 'local_outcomemap'));
        $mform->setType('mincohortsize', PARAM_INT);
        $mform->hideIf('mincohortsize', 'policytype', 'neq', policy_service::TYPE_ACCREDITATION);
        $mform->addElement('select', 'populationsource', get_string('populationsource', 'local_outcomemap'), [
            '' => get_string('choosedots'),
            suppression_service::POPULATION_ACTIVE_ENROLMENTS =>
                get_string('population_active_enrolments_at_freeze', 'local_outcomemap'),
            suppression_service::POPULATION_MOODLE_COHORT =>
                get_string('population_moodle_cohort_at_freeze', 'local_outcomemap'),
        ]);
        $mform->hideIf('populationsource', 'policytype', 'neq', policy_service::TYPE_ACCREDITATION);
        $mform->addElement('select', 'retentionbasis', get_string('retentionbasis', 'local_outcomemap'), [
            '' => get_string('choosedots'),
            suppression_service::RETENTION_ANONYMISED =>
                get_string('retention_institutional_record_anonymised', 'local_outcomemap'),
            suppression_service::RETENTION_PRIVACY_DELETION =>
                get_string('retention_privacy_deletion', 'local_outcomemap'),
        ]);
        $mform->hideIf('retentionbasis', 'policytype', 'neq', policy_service::TYPE_ACCREDITATION);
        $mform->addElement('static', 'aggregationmethod', get_string('aggregationmethod', 'local_outcomemap'),
            get_string('aggregation_sum_numerators_denominators', 'local_outcomemap'));
        $mform->hideIf('aggregationmethod', 'policytype', 'neq', policy_service::TYPE_ACCREDITATION);
        $mform->addElement('static', 'correctionmethod', get_string('correctionmethod', 'local_outcomemap'),
            get_string('correction_new_snapshot_version', 'local_outcomemap'));
        $mform->hideIf('correctionmethod', 'policytype', 'neq', policy_service::TYPE_ACCREDITATION);

        $mform->addElement('text', 'minitems', get_string('minimumdistinctitems', 'local_outcomemap'));
        $mform->setType('minitems', PARAM_INT);
        $mform->setDefault('minitems', 1);
        $mform->hideIf('minitems', 'policytype', 'neq', policy_service::TYPE_CALCULATION);
        $mform->addElement('text', 'minweightedpossible', get_string('minimumweightedpossible', 'local_outcomemap'));
        $mform->setType('minweightedpossible', PARAM_RAW_TRIMMED);
        $mform->hideIf('minweightedpossible', 'policytype', 'neq', policy_service::TYPE_CALCULATION);
        $mform->addElement('advcheckbox', 'requiremanualgrading',
            get_string('requiremanualgrading', 'local_outcomemap'));
        $mform->hideIf('requiremanualgrading', 'policytype', 'neq', policy_service::TYPE_CALCULATION);
        $mform->addElement('select', 'displayscale', get_string('displayprecision', 'local_outcomemap'),
            array_combine(range(0, decimal::SCALE), range(0, decimal::SCALE)));
        $mform->setDefault('displayscale', 1);
        $mform->hideIf('displayscale', 'policytype', 'neq', policy_service::TYPE_CALCULATION);

        $mform->addElement('static', 'bandsintro', '', get_string('performancebands_help', 'local_outcomemap'));
        $mform->hideIf('bandsintro', 'policytype', 'neq', policy_service::TYPE_CALCULATION);
        $repeat = [
            $mform->createElement('header', 'bandheader', get_string('performancebandnumber', 'local_outcomemap')),
            $mform->createElement('text', 'bandcode', get_string('bandcode_number', 'local_outcomemap'), [
                'maxlength' => 50,
            ]),
            $mform->createElement('text', 'bandname', get_string('bandname_number', 'local_outcomemap'), [
                'maxlength' => 255,
                'size' => 60,
            ]),
            $mform->createElement('textarea', 'banddescription',
                get_string('banddescription_number', 'local_outcomemap'), ['rows' => 3, 'cols' => 60]),
            $mform->createElement('text', 'bandminpercent', get_string('bandminimum_number', 'local_outcomemap')),
            $mform->createElement('advcheckbox', 'bandmininclusive',
                get_string('bandminimuminclusive_number', 'local_outcomemap')),
            $mform->createElement('text', 'bandmaxpercent', get_string('bandmaximum_number', 'local_outcomemap')),
            $mform->createElement('advcheckbox', 'bandmaxinclusive',
                get_string('bandmaximuminclusive_number', 'local_outcomemap')),
            $mform->createElement('submit', 'deleteband', get_string('deleteperformanceband', 'local_outcomemap'), [], false),
        ];
        $repeatoptions = [];
        foreach (['bandheader', 'bandcode', 'bandname', 'banddescription', 'bandminpercent',
                'bandmininclusive', 'bandmaxpercent', 'bandmaxinclusive', 'deleteband'] as $elementname) {
            $repeatoptions[$elementname]['hideif'] = ['policytype', 'neq', policy_service::TYPE_CALCULATION];
        }
        $repeatoptions['bandcode']['type'] = PARAM_TEXT;
        $repeatoptions['bandname']['type'] = PARAM_TEXT;
        $repeatoptions['banddescription']['type'] = PARAM_TEXT;
        $repeatoptions['bandminpercent']['type'] = PARAM_RAW_TRIMMED;
        $repeatoptions['bandmaxpercent']['type'] = PARAM_RAW_TRIMMED;
        $repeatoptions['bandmininclusive']['default'] = 1;
        $repeatoptions['bandmaxinclusive']['default'] = 0;
        $this->repeat_elements(
            $repeat,
            max(1, (int) $this->_customdata['repeatcount']),
            $repeatoptions,
            'bandcount',
            'addband',
            1,
            get_string('addperformanceband', 'local_outcomemap'),
            true,
            'deleteband'
        );
        $mform->hideIf('addband', 'policytype', 'neq', policy_service::TYPE_CALCULATION);

        $mform->addElement('header', 'effectivedates', get_string('effectivedates', 'local_outcomemap'));
        $mform->addElement('date_time_selector', 'effectivefrom', get_string('effectivefrom', 'local_outcomemap'));
        $mform->setDefault('effectivefrom', time());
        $mform->addElement('date_time_selector', 'effectiveto', get_string('effectiveto', 'local_outcomemap'), [
            'optional' => true,
        ]);
        $mform->addElement('textarea', 'reason', get_string('changereason', 'local_outcomemap'), [
            'rows' => 3,
            'cols' => 60,
        ]);
        $mform->setType('reason', PARAM_TEXT);

        $this->add_action_buttons();
    }

    /**
     * Validates conditional scope, configuration, and band inputs.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Validation errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $scopetype = $data['scopetype'] ?? '';
        $scopefields = [
            policy_service::SCOPE_PROGRAM => 'programid',
            policy_service::SCOPE_CATALOG_COURSE => 'catalogcourseid',
            policy_service::SCOPE_COURSE_INSTANCE => 'courseinstanceid',
            policy_service::SCOPE_ASSESSMENT => 'assessmentid',
        ];
        if (isset($scopefields[$scopetype]) && empty($data[$scopefields[$scopetype]])) {
            $errors[$scopefields[$scopetype]] = get_string('required');
        }

        $policytype = $data['policytype'] ?? '';
        if ($policytype === policy_service::TYPE_ACCREDITATION) {
            if (!in_array($scopetype, [policy_service::SCOPE_PROGRAM, policy_service::SCOPE_INSTITUTION], true)) {
                $errors['scopetype'] = get_string('invalidaccreditationscope', 'local_outcomemap',
                    (object) ['detail' => $scopetype]);
            }
            if (filter_var($data['mincohortsize'] ?? null, FILTER_VALIDATE_INT) === false
                    || (int) $data['mincohortsize'] < 1) {
                $errors['mincohortsize'] = get_string('invalidminimumcohortsize', 'local_outcomemap');
            }
            if (!in_array($data['populationsource'] ?? '', suppression_service::POPULATION_SOURCES, true)) {
                $errors['populationsource'] = get_string('required');
            }
            if (!in_array($data['retentionbasis'] ?? '', suppression_service::RETENTION_BASES, true)) {
                $errors['retentionbasis'] = get_string('required');
            }
            return $errors;
        }
        if ($scopetype === policy_service::SCOPE_PROGRAM) {
            $errors['scopetype'] = get_string('invalidfield', 'local_outcomemap',
                (object) ['field' => 'scopetype', 'detail' => $scopetype]);
        }

        if ($policytype === policy_service::TYPE_ATTEMPT_SELECTION) {
            if (!in_array($data['attemptmethod'] ?? '', policy_service::METHODS, true)) {
                $errors['attemptmethod'] = get_string('required');
            }
            return $errors;
        }
        if (($data['policytype'] ?? '') === policy_service::TYPE_RELEASE) {
            $mode = $data['releasemode'] ?? '';
            if (!in_array($mode, policy_service::RELEASE_MODES, true)) {
                $errors['releasemode'] = get_string('required');
            } else if ($mode === policy_service::RELEASE_SCHEDULED && empty($data['releaseat'])) {
                $errors['releaseat'] = get_string('required');
            }
            return $errors;
        }
        if (($data['policytype'] ?? '') !== policy_service::TYPE_CALCULATION) {
            $errors['policytype'] = get_string('required');
            return $errors;
        }
        if (filter_var($data['minitems'] ?? null, FILTER_VALIDATE_INT) === false || (int) $data['minitems'] < 1) {
            $errors['minitems'] = get_string('invalidminimumitems', 'local_outcomemap');
        }
        if (isset($data['minweightedpossible']) && trim((string) $data['minweightedpossible']) !== '') {
            try {
                decimal::require_canonical($data['minweightedpossible'], 'minweightedpossible');
            } catch (validation_exception $e) {
                $errors['minweightedpossible'] = get_string('invaliddecimal', 'local_outcomemap');
            }
        }

        $previousmax = null;
        $previousmaxinclusive = false;
        $seencodes = [];
        foreach (array_keys($data['bandcode'] ?? []) as $index) {
            $code = trim((string) ($data['bandcode'][$index] ?? ''));
            $name = trim((string) ($data['bandname'][$index] ?? ''));
            $description = trim((string) ($data['banddescription'][$index] ?? ''));
            $min = trim((string) ($data['bandminpercent'][$index] ?? ''));
            $max = trim((string) ($data['bandmaxpercent'][$index] ?? ''));
            if ($code === '' && $name === '' && $description === '' && $min === '' && $max === '') {
                continue;
            }
            if ($code === '') {
                $errors['bandcode[' . $index . ']'] = get_string('required');
            } else if (isset($seencodes[$code])) {
                $errors['bandcode[' . $index . ']'] = get_string('duplicatebandcode', 'local_outcomemap',
                    (object) ['detail' => $code]);
            }
            $seencodes[$code] = true;
            if ($name === '') {
                $errors['bandname[' . $index . ']'] = get_string('required');
            }
            $canonicalmin = null;
            $canonicalmax = null;
            try {
                $canonicalmin = $min === '' ? null : decimal::require_canonical($min, 'minpercent');
            } catch (validation_exception $e) {
                $errors['bandminpercent[' . $index . ']'] = get_string('invaliddecimal', 'local_outcomemap');
            }
            try {
                $canonicalmax = $max === '' ? null : decimal::require_canonical($max, 'maxpercent');
            } catch (validation_exception $e) {
                $errors['bandmaxpercent[' . $index . ']'] = get_string('invaliddecimal', 'local_outcomemap');
            }
            if ($canonicalmin !== null && $canonicalmax !== null
                    && decimal::cmp($canonicalmin, $canonicalmax) > 0) {
                $errors['bandmaxpercent[' . $index . ']'] = get_string('bandrangeinvalid', 'local_outcomemap');
            }
            if (count($seencodes) > 1 && ($previousmax === null || $canonicalmin === null)) {
                $errors['bandminpercent[' . $index . ']'] = get_string('bandsoverlap', 'local_outcomemap',
                    (object) ['detail' => $code]);
            } else if ($previousmax !== null && $canonicalmin !== null) {
                $comparison = decimal::cmp($canonicalmin, $previousmax);
                if ($comparison < 0 || ($comparison === 0 && $previousmaxinclusive
                        && !empty($data['bandmininclusive'][$index]))) {
                    $errors['bandminpercent[' . $index . ']'] = get_string('bandsoverlap', 'local_outcomemap',
                        (object) ['detail' => $code]);
                }
            }
            $previousmax = $canonicalmax;
            $previousmaxinclusive = !empty($data['bandmaxinclusive'][$index]);
        }
        return $errors;
    }
}
