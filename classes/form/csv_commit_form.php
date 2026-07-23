<?php
// This file is part of Moodle - http://moodle.org/

namespace local_outcomemap\form;

/** Explicit commit form bound to a validated preview hash. */
final class csv_commit_form extends \moodleform {
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'importid');
        $mform->setType('importid', PARAM_INT);
        $mform->addElement('hidden', 'entity');
        $mform->setType('entity', PARAM_ALPHANUMEXT);
        $mform->addElement('hidden', 'previewhash');
        $mform->setType('previewhash', PARAM_ALPHANUM);
        $mform->addElement('hidden', 'commit', 1);
        $mform->setType('commit', PARAM_BOOL);
        $this->add_action_buttons(true, get_string('commitimport', 'local_outcomemap'));
    }
}
