<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\form;

/**
 * Accreditation snapshot capture form.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class snapshot_form extends \moodleform {
    /** Define fields. */
    public function definition(): void {
        $mform = $this->_form;
        $options = $this->_customdata['options'];
        $iscorrection = !empty($this->_customdata['iscorrection']);

        $mform->addElement('hidden', 'previousid', 0);
        $mform->setType('previousid', PARAM_INT);
        $mform->addElement('autocomplete', 'programid', get_string('program', 'local_outcomemap'),
            [0 => get_string('choosedots')] + $options['programs']);
        $mform->addRule('programid', null, 'required');
        // Only the periods that resolve to course instances are offered, so an
        // empty capture cannot be requested by mistyping an academic year. The
        // program/period pair is still checked in validation(), because one
        // period rarely covers every program.
        $mform->addElement('select', 'periodcode', get_string('periodcode', 'local_outcomemap'),
            ['' => get_string('choosedots')] + self::period_choices($options, $this->_customdata));
        $mform->setType('periodcode', PARAM_TEXT);
        $mform->addRule('periodcode', null, 'required');
        $mform->addHelpButton('periodcode', 'snapshotperiod', 'local_outcomemap');
        $mform->addElement('autocomplete', 'cohortid', get_string('cohort', 'local_outcomemap'),
            [0 => get_string('none')] + $options['cohorts']);
        $mform->addHelpButton('cohortid', 'snapshotcohort', 'local_outcomemap');
        $mform->addElement('textarea', 'notes', get_string('snapshotnotes', 'local_outcomemap'), [
            'rows' => 4,
            'cols' => 70,
        ]);
        $mform->setType('notes', PARAM_TEXT);
        $mform->addElement('textarea', 'correctionreason', get_string('correctionreason', 'local_outcomemap'), [
            'rows' => 4,
            'cols' => 70,
        ]);
        $mform->setType('correctionreason', PARAM_TEXT);
        if (!$iscorrection) {
            $mform->hideIf('correctionreason', 'previousid', 'eq', 0);
        } else {
            $mform->addRule('correctionreason', null, 'required');
            $mform->freeze(['programid', 'periodcode']);
        }
        $this->add_action_buttons(true, get_string(
            $iscorrection ? 'correctsnapshot' : 'createsnapshot',
            'local_outcomemap'
        ));
    }

    /**
     * Selectable reporting periods, labelled with the programs they cover.
     *
     * A correction freezes the period, so the period it was captured under is
     * always included even if it no longer resolves — otherwise the frozen field
     * would render empty and the correction could not be submitted.
     *
     * @param array $options Form options, including periods by program.
     * @param array $customdata Form custom data.
     * @return array<string,string> Period code to label.
     */
    private static function period_choices(array $options, array $customdata): array {
        $programs = $options['programs'] ?? [];
        $labels = [];
        foreach (($options['periods'] ?? []) as $programid => $periods) {
            foreach ($periods as $periodcode => $instances) {
                $code = strtok((string) ($programs[$programid] ?? $programid), ' ');
                $labels[(string) $periodcode][$code] = (int) $instances;
            }
        }
        $choices = [];
        foreach ($labels as $periodcode => $bycode) {
            $parts = [];
            foreach ($bycode as $code => $instances) {
                $parts[] = $code . ' ' . get_string($instances === 1
                    ? 'snapshotperiodcourse' : 'snapshotperiodcourses', 'local_outcomemap', $instances);
            }
            $choices[$periodcode] = $periodcode . ' — ' . implode(', ', $parts);
        }
        ksort($choices, SORT_NATURAL);
        $current = (string) ($customdata['currentperiod'] ?? '');
        if ($current !== '' && !isset($choices[$current])) {
            $choices[$current] = $current;
        }
        return $choices;
    }

    /**
     * Validate the program/period pair, correction metadata, and lineage identity.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array
     */
    public function validation($data, $files): array {
        global $DB;
        $errors = parent::validation($data, $files);
        $programid = (int) ($data['programid'] ?? 0);
        $periodcode = trim((string) ($data['periodcode'] ?? ''));
        $previousid = (int) ($data['previousid'] ?? 0);
        if (!empty($previousid) && trim((string) ($data['correctionreason'] ?? '')) === '') {
            $errors['correctionreason'] = get_string('snapshotcorrectionrequired', 'local_outcomemap');
        }
        if ($programid === 0 || $periodcode === '' || isset($errors['periodcode'])) {
            return $errors;
        }

        $periods = $this->_customdata['options']['periods'] ?? [];
        $valid = array_keys($periods[$programid] ?? []);
        // A correction keeps the period it was captured under, so it is judged
        // against the original capture rather than what resolves today.
        if (empty($previousid) && !in_array($periodcode, $valid, true)) {
            sort($valid, SORT_NATURAL);
            $errors['periodcode'] = get_string('snapshotperiodunresolved', 'local_outcomemap', [
                'program' => s((string) ($this->_customdata['options']['programs'][$programid] ?? $programid)),
                'period' => s($periodcode),
                'valid' => $valid ? s(implode(', ', $valid))
                    : get_string('snapshotperiodnonevalid', 'local_outcomemap'),
            ]);
            return $errors;
        }

        // Without a previous version, create_draft() mints a fresh lineage at
        // version one without checking whether this program and period are
        // already captured. Two independent version-one records for one period
        // leave nothing to say which is authoritative, so send the operator to
        // the correction action instead.
        if (empty($previousid)) {
            $existing = $DB->get_records('local_outcomemap_snapshot',
                ['programid' => $programid, 'periodcode' => $periodcode], 'version DESC, id DESC');
            if ($existing) {
                $latest = reset($existing);
                $errors['periodcode'] = get_string('snapshotperiodcaptured', 'local_outcomemap', [
                    'period' => s($periodcode),
                    'id' => (int) $latest->id,
                    'version' => (int) $latest->version,
                    'status' => s($latest->status),
                ]);
            }
        }
        return $errors;
    }
}
