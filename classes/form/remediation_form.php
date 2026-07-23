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

use local_outcomemap\local\service\remediation_service;

/**
 * Remediation recommendation editor.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remediation_form extends \moodleform {
    /**
     * Defines the form elements.
     */
    public function definition(): void {
        $mform = $this->_form;
        $options = $this->_customdata['options'];
        $targettypes = [
            remediation_service::TARGET_MODULE => get_string('target_course_module', 'local_outcomemap'),
            remediation_service::TARGET_SECTION => get_string('target_course_section', 'local_outcomemap'),
            remediation_service::TARGET_EXTERNAL => get_string('target_external_url', 'local_outcomemap'),
        ];
        $mform->addElement('select', 'cinstid', get_string('courseinstance', 'local_outcomemap'), $options['instances']);
        $mform->addRule('cinstid', null, 'required');
        $mform->addElement(
            'autocomplete',
            'itemverid',
            get_string('outcomeversion', 'local_outcomemap'),
            $options['outcomes']
        );
        $mform->addRule('itemverid', null, 'required');
        $mform->addElement('select', 'targettype', get_string('targettype', 'local_outcomemap'), $targettypes);
        $mform->addElement('autocomplete', 'cmid', get_string('coursemodule', 'local_outcomemap'), $options['modules']);
        $mform->hideIf('cmid', 'targettype', 'neq', remediation_service::TARGET_MODULE);
        $mform->addElement('autocomplete', 'sectionid', get_string('coursesection', 'local_outcomemap'), $options['sections']);
        $mform->hideIf('sectionid', 'targettype', 'neq', remediation_service::TARGET_SECTION);
        $mform->addElement('url', 'externalurl', get_string('externalurl', 'local_outcomemap'), ['size' => 70]);
        $mform->setType('externalurl', PARAM_URL);
        $mform->hideIf('externalurl', 'targettype', 'neq', remediation_service::TARGET_EXTERNAL);
        $mform->addElement('text', 'title', get_string('title', 'local_outcomemap'), ['size' => 70]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required');
        $mform->addElement(
            'textarea',
            'explanation',
            get_string('explanation', 'local_outcomemap'),
            ['rows' => 4, 'cols' => 70]
        );
        $mform->setType('explanation', PARAM_TEXT);
        $mform->addElement('text', 'priority', get_string('priority', 'local_outcomemap'));
        $mform->setType('priority', PARAM_INT);
        $mform->setDefault('priority', 0);
        $mform->addElement('advcheckbox', 'required', get_string('requiredremediation', 'local_outcomemap'));
        $mform->addElement('text', 'minpercent', get_string('minpercent', 'local_outcomemap'));
        $mform->setType('minpercent', PARAM_RAW_TRIMMED);
        $mform->addElement('text', 'maxpercent', get_string('maxpercent', 'local_outcomemap'));
        $mform->setType('maxpercent', PARAM_RAW_TRIMMED);
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
     * Validates submitted remediation data.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Validation errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if ($data['targettype'] === remediation_service::TARGET_MODULE && empty($data['cmid'])) {
            $errors['cmid'] = get_string('required');
        } else if ($data['targettype'] === remediation_service::TARGET_SECTION && empty($data['sectionid'])) {
            $errors['sectionid'] = get_string('required');
        } else if ($data['targettype'] === remediation_service::TARGET_EXTERNAL && empty($data['externalurl'])) {
            $errors['externalurl'] = get_string('required');
        }
        return $errors;
    }
}
