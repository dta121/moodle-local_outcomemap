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

/**
 * Move a catalog course from one program to another.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class program_course_move_form extends \moodleform {
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        global $DB;
        $mform = $this->_form;
        $membership = $this->_customdata['membership'];
        $course = $DB->get_record(
            'local_outcomemap_course',
            ['id' => $membership->courseid],
            'id, code, name',
            MUST_EXIST
        );
        $from = $DB->get_record(
            'local_outcomemap_program',
            ['id' => $membership->programid],
            'id, code, name',
            MUST_EXIST
        );

        // The reader arrived from one course card, so what is being moved is already
        // settled and is stated rather than asked for again.
        $mform->addElement(
            'static',
            'movingwhat',
            get_string('catalogcourse', 'local_outcomemap'),
            $course->code . ' — ' . format_string($course->name)
        );
        $mform->addElement(
            'static',
            'movingfrom',
            get_string('membershipmovefrom', 'local_outcomemap'),
            $from->code . ' — ' . format_string($from->name)
        );

        // Only the programs it could actually move into: the one it is leaving is
        // not a destination, and neither is one that already contains it.
        $options = ['' => get_string('owner_choose', 'local_outcomemap')];
        foreach ($DB->get_records('local_outcomemap_program', null, 'code ASC', 'id, code, name') as $program) {
            if ((int) $program->id === (int) $membership->programid) {
                continue;
            }
            $taken = $DB->record_exists_select(
                'local_outcomemap_progcourse',
                'programid = :programid AND courseid = :courseid AND status <> :retired',
                [
                    'programid' => (int) $program->id,
                    'courseid' => (int) $membership->courseid,
                    'retired' => \local_outcomemap\local\workflow::RETIRED,
                ]
            );
            if ($taken) {
                continue;
            }
            $options[(int) $program->id] = $program->code . ' — ' . $program->name;
        }
        $mform->addElement(
            'autocomplete',
            'targetprogramid',
            get_string('membershipmoveto', 'local_outcomemap'),
            $options
        );
        $mform->setType('targetprogramid', PARAM_INT);
        $mform->addRule('targetprogramid', null, 'required');

        $mform->addElement('text', 'reason', get_string('reason', 'local_outcomemap'), ['size' => 60]);
        $mform->setType('reason', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('membershipmove', 'local_outcomemap'));
    }

    /**
     * Require a destination that is still on offer.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (empty($data['targetprogramid'])) {
            $errors['targetprogramid'] = get_string('membershipmovenotarget', 'local_outcomemap');
        }
        return $errors;
    }
}
