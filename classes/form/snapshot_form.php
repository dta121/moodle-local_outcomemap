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
        $mform->addElement('text', 'periodcode', get_string('periodcode', 'local_outcomemap'), [
            'maxlength' => 100,
            'size' => 30,
        ]);
        $mform->setType('periodcode', PARAM_TEXT);
        $mform->addRule('periodcode', null, 'required');
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
     * Validate required correction metadata.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!empty($data['previousid']) && trim((string) ($data['correctionreason'] ?? '')) === '') {
            $errors['correctionreason'] = get_string('snapshotcorrectionrequired', 'local_outcomemap');
        }
        return $errors;
    }
}
