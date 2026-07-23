<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\form;

/** Catalog course editor. */
final class catalog_course_form extends \moodleform {
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('text', 'code', get_string('code', 'local_outcomemap'), ['maxlength' => 100]);
        $mform->setType('code', PARAM_TEXT);
        $mform->addRule('code', null, 'required');
        $mform->addElement('text', 'name', get_string('name', 'local_outcomemap'), ['maxlength' => 255, 'size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');
        $mform->addElement('textarea', 'description', get_string('description', 'local_outcomemap'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);
        $mform->addElement('text', 'siskey', get_string('siskey', 'local_outcomemap'), ['maxlength' => 255]);
        $mform->setType('siskey', PARAM_TEXT);
        $this->add_action_buttons();
    }
}
