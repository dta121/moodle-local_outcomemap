<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\form;

/** Moodle course-instance association editor. */
final class course_instance_form extends \moodleform {
    public function definition(): void {
        global $DB;
        $mform = $this->_form;
        $catalogcourses = $DB->get_records_menu('local_outcomemap_course', null, 'code', 'id,code');
        $moodlecourses = $DB->get_records_select_menu('course', 'id <> :siteid', ['siteid' => SITEID],
            'shortname', 'id,shortname');
        $mform->addElement('autocomplete', 'courseid', get_string('catalogcourse', 'local_outcomemap'), $catalogcourses);
        $mform->addRule('courseid', null, 'required');
        $mform->addElement('autocomplete', 'moodlecourseid', get_string('moodlecourse', 'local_outcomemap'), $moodlecourses);
        $mform->addRule('moodlecourseid', null, 'required');
        $mform->addElement('text', 'periodcode', get_string('periodcode', 'local_outcomemap'), ['maxlength' => 100]);
        $mform->setType('periodcode', PARAM_TEXT);
        $mform->addRule('periodcode', null, 'required');
        $mform->addElement('text', 'externalid', get_string('externalid', 'local_outcomemap'), ['maxlength' => 255]);
        $mform->setType('externalid', PARAM_TEXT);
        $this->add_action_buttons();
    }
}
