<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\form;

/** Outcome and outcome-version editor. */
final class outcome_form extends \moodleform {
    public function definition(): void {
        global $DB;
        $mform = $this->_form;
        $frameworks = $DB->get_records_menu('local_outcomemap_fw', null, 'code', 'id,code');
        $mform->addElement('hidden', 'versionid', 0);
        $mform->setType('versionid', PARAM_INT);
        $mform->addElement('hidden', 'itemid', 0);
        $mform->setType('itemid', PARAM_INT);
        $mform->addElement('autocomplete', 'frameworkid', get_string('framework', 'local_outcomemap'), $frameworks);
        $mform->addRule('frameworkid', null, 'required');
        $mform->addElement('text', 'code', get_string('code', 'local_outcomemap'), ['maxlength' => 100]);
        $mform->setType('code', PARAM_TEXT);
        $mform->addRule('code', null, 'required');
        $mform->addElement('textarea', 'statement', get_string('statement', 'local_outcomemap'), ['rows' => 4, 'cols' => 70]);
        $mform->setType('statement', PARAM_TEXT);
        $mform->addRule('statement', null, 'required');
        $mform->addElement('text', 'shortstatement', get_string('shortstatement', 'local_outcomemap'), ['maxlength' => 255, 'size' => 60]);
        $mform->setType('shortstatement', PARAM_TEXT);
        $mform->addElement('text', 'bloomlevel', get_string('bloomlevel', 'local_outcomemap'), ['maxlength' => 50]);
        $mform->setType('bloomlevel', PARAM_TEXT);
        $mform->addElement('date_time_selector', 'effectivefrom', get_string('effectivefrom', 'local_outcomemap'));
        $mform->addElement('date_time_selector', 'effectiveto', get_string('effectiveto', 'local_outcomemap'), ['optional' => true]);
        $mform->setDefault('effectivefrom', time());
        $mform->addElement('textarea', 'changereason', get_string('changereason', 'local_outcomemap'), ['rows' => 3, 'cols' => 70]);
        $mform->setType('changereason', PARAM_TEXT);
        $this->add_action_buttons();
    }
}
