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

use local_outcomemap\local\service\foundation_import_service;

/**
 * Foundation CSV upload form.
 */
final class csv_import_form extends \moodleform {
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        $mform = $this->_form;
        $entities = [];
        foreach (foundation_import_service::ENTITIES as $entity) {
            $entities[$entity] = get_string('entity_' . $entity, 'local_outcomemap');
        }
        $mform->addElement('select', 'entity', get_string('csventity', 'local_outcomemap'), $entities);
        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('csvfile', 'local_outcomemap'),
            null,
            [
                'accepted_types' => ['.csv', 'text/csv'],
                'maxbytes' => foundation_import_service::MAX_IMPORT_BYTES,
                'maxfiles' => 1,
            ]
        );
        $mform->addRule('csvfile', null, 'required');
        $mform->addElement(
            'select',
            'delimiter',
            get_string('csvdelimiter', 'local_outcomemap'),
            \csv_import_reader::get_delimiter_list()
        );
        $mform->setDefault('delimiter', 'comma');
        $mform->addElement('select', 'encoding', get_string('encoding', 'local_outcomemap'), \core_text::get_encodings());
        $mform->setDefault('encoding', 'UTF-8');
        $this->add_action_buttons(false, get_string('preview', 'local_outcomemap'));
    }
}
