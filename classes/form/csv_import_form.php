<?php
// This file is part of Moodle - http://moodle.org/

namespace local_outcomemap\form;

use local_outcomemap\local\service\foundation_import_service;

/** Foundation CSV upload form. */
final class csv_import_form extends \moodleform {
    public function definition(): void {
        $mform = $this->_form;
        $entities = [];
        foreach (foundation_import_service::ENTITIES as $entity) {
            $entities[$entity] = get_string('entity_' . $entity, 'local_outcomemap');
        }
        $mform->addElement('select', 'entity', get_string('csventity', 'local_outcomemap'), $entities);
        $mform->addElement('filepicker', 'csvfile', get_string('csvfile', 'local_outcomemap'), null,
            ['accepted_types' => ['.csv', 'text/csv']]);
        $mform->addRule('csvfile', null, 'required');
        $mform->addElement('select', 'delimiter', get_string('csvdelimiter', 'local_outcomemap'),
            \csv_import_reader::get_delimiter_list());
        $mform->setDefault('delimiter', 'comma');
        $mform->addElement('select', 'encoding', get_string('encoding', 'local_outcomemap'), \core_text::get_encodings());
        $mform->setDefault('encoding', 'UTF-8');
        $this->add_action_buttons(false, get_string('preview', 'local_outcomemap'));
    }
}
