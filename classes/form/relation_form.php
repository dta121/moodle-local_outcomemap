<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\form;

use local_outcomemap\local\service\relation_service;

/** Outcome relationship editor. */
final class relation_form extends \moodleform {
    public function definition(): void {
        global $DB;
        $mform = $this->_form;
        $records = $DB->get_records_sql(
            'SELECT i.id, ' . $DB->sql_concat('f.code', "'.'", 'i.code') . ' AS displayname
               FROM {local_outcomemap_item} i
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
           ORDER BY f.code, i.code');
        $outcomes = [];
        foreach ($records as $record) {
            $outcomes[$record->id] = $record->displayname;
        }
        $types = [];
        foreach (relation_service::TYPES as $type) {
            $types[$type] = get_string('relation_' . $type, 'local_outcomemap');
        }
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('autocomplete', 'sourceitemid', get_string('sourceoutcome', 'local_outcomemap'), $outcomes);
        $mform->addRule('sourceitemid', null, 'required');
        $mform->addElement('autocomplete', 'targetitemid', get_string('targetoutcome', 'local_outcomemap'), $outcomes);
        $mform->addRule('targetitemid', null, 'required');
        $mform->addElement('select', 'type', get_string('relationtype', 'local_outcomemap'), $types);
        $mform->addElement('text', 'weight', get_string('weight', 'local_outcomemap'));
        $mform->setType('weight', PARAM_RAW_TRIMMED);
        $mform->disabledIf('weight', 'type', 'neq', relation_service::CONTRIBUTES_TO);
        $mform->addElement('date_time_selector', 'effectivefrom', get_string('effectivefrom', 'local_outcomemap'));
        $mform->addElement('date_time_selector', 'effectiveto', get_string('effectiveto', 'local_outcomemap'), ['optional' => true]);
        $mform->setDefault('effectivefrom', time());
        $mform->addElement('textarea', 'notes', get_string('notes', 'local_outcomemap'), ['rows' => 3, 'cols' => 70]);
        $mform->setType('notes', PARAM_TEXT);
        $this->add_action_buttons();
    }
}
