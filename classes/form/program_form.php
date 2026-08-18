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

/**
 * Learning Outcome Mapping plugin component.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\form;

use local_outcomemap\local\service\program_service;

/**
 * Program editor.
 */
final class program_form extends \moodleform {
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $typeelements = [];
        foreach (program_service::PROGRAM_TYPES as $programtype) {
            $label = \html_writer::span('', 'lom-program-type-swatch', ['aria-hidden' => 'true']);
            $label .= \html_writer::span(
                \html_writer::span(
                    get_string('programtype_' . $programtype, 'local_outcomemap'),
                    'lom-program-type-title'
                ) . \html_writer::span(
                    get_string('programtype_' . $programtype . '_desc', 'local_outcomemap'),
                    'lom-program-type-description'
                ),
                'lom-program-type-copy'
            );
            $typeelements[] = $mform->createElement(
                'radio',
                'programtype',
                '',
                $label,
                $programtype,
                [
                    'class' => 'lom-program-type-option lom-program-type-' . $programtype,
                    'aria-label' => get_string('programtype_' . $programtype, 'local_outcomemap'),
                ]
            );
        }
        $mform->addGroup(
            $typeelements,
            'programtype',
            get_string('programtype', 'local_outcomemap'),
            '',
            false
        );
        $mform->addRule('programtype', null, 'required', null, 'client');
        $mform->setDefault('programtype', program_service::TYPE_GRADUATE);

        $mform->addElement('text', 'code', get_string('code', 'local_outcomemap'), [
            'maxlength' => 100,
            'placeholder' => get_string('programcode_placeholder', 'local_outcomemap'),
        ]);
        $mform->setType('code', PARAM_TEXT);
        $mform->addRule('code', null, 'required');
        $mform->addElement('text', 'name', get_string('name', 'local_outcomemap'), [
            'maxlength' => 255,
            'size' => 60,
            'placeholder' => get_string('programname_placeholder', 'local_outcomemap'),
        ]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');
        $mform->addElement('textarea', 'description', get_string('description', 'local_outcomemap'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        $credentialelements = [];
        foreach (program_service::CREDENTIALS as $credential) {
            $credentialelements[] = $mform->createElement(
                'radio',
                'credential',
                '',
                get_string('credential_' . $credential, 'local_outcomemap'),
                $credential,
                [
                    'class' => 'lom-credential-option',
                    'aria-label' => get_string('credential_' . $credential, 'local_outcomemap'),
                ]
            );
        }
        $mform->addGroup(
            $credentialelements,
            'credential',
            get_string('credentialawarded', 'local_outcomemap'),
            '',
            false
        );
        $mform->addRule('credential', null, 'required', null, 'client');
        $mform->setDefault('credential', program_service::CREDENTIAL_DEGREE);

        $mform->addElement('text', 'externalid', get_string('externalid', 'local_outcomemap'), ['maxlength' => 255]);
        $mform->setType('externalid', PARAM_TEXT);
        $this->add_action_buttons();
    }
}
