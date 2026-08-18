<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_outcomemap\form;

use local_outcomemap\local\service\content_mapping_service;

/**
 * Course-module and section mapping editor.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class content_mapping_form extends \moodleform {
    /**
     * * Defines the form elements.
     */
    public function definition(): void {
        $mform = $this->_form;
        $options = $this->_customdata['options'];
        $roles = [];
        foreach (content_mapping_service::ROLES as $role) {
            $roles[$role] = get_string('mappingrole_' . $role, 'local_outcomemap');
        }
        $targettypes = [
            content_mapping_service::TARGET_MODULE => get_string('target_course_module', 'local_outcomemap'),
            content_mapping_service::TARGET_SECTION => get_string('target_course_section', 'local_outcomemap'),
        ];
        $mform->addElement('select', 'cinstid', get_string('courseinstance', 'local_outcomemap'), $options['instances']);
        $mform->addRule('cinstid', null, 'required');
        $mform->addElement('select', 'targettype', get_string('targettype', 'local_outcomemap'), $targettypes);
        $mform->addElement('autocomplete', 'cmid', get_string('coursemodule', 'local_outcomemap'), $options['modules']);
        $mform->hideIf('cmid', 'targettype', 'neq', content_mapping_service::TARGET_MODULE);
        $mform->addElement('autocomplete', 'sectionid', get_string('coursesection', 'local_outcomemap'), $options['sections']);
        $mform->hideIf('sectionid', 'targettype', 'neq', content_mapping_service::TARGET_SECTION);
        $mform->addElement(
            'autocomplete',
            'itemverid',
            get_string('outcomeversion', 'local_outcomemap'),
            $options['outcomes']
        );
        $mform->addRule('itemverid', null, 'required');
        $mform->addElement('select', 'role', get_string('mappingrole', 'local_outcomemap'), $roles);
        $mform->addElement('text', 'weight', get_string('mappingweight', 'local_outcomemap'));
        $mform->setType('weight', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('weight', 'mappingweight', 'local_outcomemap');
        $mform->addElement('text', 'priority', get_string('priority', 'local_outcomemap'));
        $mform->setType('priority', PARAM_INT);
        $mform->setDefault('priority', 0);
        $mform->addElement('textarea', 'notes', get_string('notes', 'local_outcomemap'), ['rows' => 3, 'cols' => 70]);
        $mform->setType('notes', PARAM_TEXT);
        $mform->addElement('date_time_selector', 'effectivefrom', get_string('effectivefrom', 'local_outcomemap'));
        $mform->setDefault('effectivefrom', time());
        $mform->addElement(
            'date_time_selector',
            'effectiveto',
            get_string('effectiveto', 'local_outcomemap'),
            ['optional' => true]
        );
        $this->add_action_buttons();
    }

    /**
     * Validates submitted content mapping data.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Validation errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if ($data['targettype'] === content_mapping_service::TARGET_MODULE && empty($data['cmid'])) {
            $errors['cmid'] = get_string('required');
        }
        if ($data['targettype'] === content_mapping_service::TARGET_SECTION && empty($data['sectionid'])) {
            $errors['sectionid'] = get_string('required');
        }
        return $errors;
    }
}
