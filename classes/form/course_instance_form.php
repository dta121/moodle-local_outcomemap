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
 * Moodle course-instance association editor.
 */
final class course_instance_form extends \moodleform {
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        global $DB;
        $mform = $this->_form;
        // Both lists open on a placeholder rather than on whichever record happens to
        // sort first. Without one, a field nobody touched still read as answered, the
        // required rule could never fire, and the association silently claimed the
        // first catalog course and the first Moodle course on the site.
        $choose = ['' => get_string('owner_choose', 'local_outcomemap')];
        $catalogcourses = $DB->get_records_menu('local_outcomemap_course', null, 'code', 'id,code');
        $moodlecourses = $DB->get_records_select_menu(
            'course',
            'id <> :siteid',
            ['siteid' => SITEID],
            'shortname',
            'id,shortname'
        );
        $mform->addElement(
            'autocomplete',
            'courseid',
            get_string('catalogcourse', 'local_outcomemap'),
            $choose + $catalogcourses
        );
        $mform->setType('courseid', PARAM_INT);
        $mform->addRule('courseid', null, 'required');
        $mform->addElement(
            'autocomplete',
            'moodlecourseid',
            get_string('moodlecourse', 'local_outcomemap'),
            $choose + $moodlecourses
        );
        $mform->setType('moodlecourseid', PARAM_INT);
        $mform->addRule('moodlecourseid', null, 'required');
        $mform->addElement('text', 'periodcode', get_string('periodcode', 'local_outcomemap'), ['maxlength' => 100]);
        $mform->setType('periodcode', PARAM_TEXT);
        $mform->addRule('periodcode', null, 'required');
        $mform->addElement('text', 'externalid', get_string('externalid', 'local_outcomemap'), ['maxlength' => 255]);
        $mform->setType('externalid', PARAM_TEXT);
        $this->add_action_buttons();
    }
}
