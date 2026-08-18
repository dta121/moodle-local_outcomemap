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

/**
 * Program-course membership editor.
 */
final class program_course_form extends \moodleform {
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        global $DB;
        $mform = $this->_form;
        $programs = $DB->get_records_menu('local_outcomemap_program', null, 'code', 'id,code');
        $courses = $DB->get_records_menu('local_outcomemap_course', null, 'code', 'id,code');
        $mform->addElement('autocomplete', 'programid', get_string('program', 'local_outcomemap'), $programs);
        $mform->addRule('programid', null, 'required');
        $mform->addElement('autocomplete', 'courseid', get_string('catalogcourse', 'local_outcomemap'), $courses);
        $mform->addRule('courseid', null, 'required');
        $mform->addElement('date_time_selector', 'effectivefrom', get_string('effectivefrom', 'local_outcomemap'));
        $mform->addElement(
            'date_time_selector',
            'effectiveto',
            get_string('effectiveto', 'local_outcomemap'),
            ['optional' => true]
        );
        $mform->setDefault('effectivefrom', time());
        $this->add_action_buttons();
    }
}
