<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\form;

use local_outcomemap\local\service\framework_service;

/** Framework editor. */
final class framework_form extends \moodleform {
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
        $mform->addElement('select', 'ownertype', get_string('ownertype', 'local_outcomemap'), [
            framework_service::OWNER_INSTITUTION => get_string('owner_institution', 'local_outcomemap'),
            framework_service::OWNER_PROGRAM => get_string('owner_program', 'local_outcomemap'),
            framework_service::OWNER_COURSE => get_string('owner_catalog_course', 'local_outcomemap'),
        ]);
        $mform->addElement('text', 'ownerid', get_string('owner', 'local_outcomemap'));
        $mform->setType('ownerid', PARAM_INT);
        $mform->disabledIf('ownerid', 'ownertype', 'eq', framework_service::OWNER_INSTITUTION);
        $this->add_action_buttons();
    }
}
