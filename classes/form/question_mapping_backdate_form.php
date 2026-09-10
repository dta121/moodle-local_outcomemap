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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Corrects the effective start of every approved mapping on one quiz.
 *
 * A mapping made through the page takes effect when it is created, so an exam
 * that learners sat earlier produces no evidence until the mappings are held to
 * have governed it. This form states the corrected start once for the whole
 * quiz; the service moves each question's mappings as a complete set and
 * records the reason against every row.
 */
final class question_mapping_backdate_form extends \moodleform {
    /**
     * Define the form.
     */
    public function definition(): void {
        $mform = $this->_form;
        $summary = $this->_customdata['summary'];

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'action', 'backdate');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement(
            'static',
            'summary',
            '',
            get_string('questionmapping_backdate_summary', 'local_outcomemap', $summary)
        );
        $mform->addElement(
            'date_time_selector',
            'effectivefrom',
            get_string('questionmapping_backdate_date', 'local_outcomemap')
        );
        $mform->addHelpButton('effectivefrom', 'questionmapping_backdate_date', 'local_outcomemap');
        $mform->addElement(
            'textarea',
            'reason',
            get_string('correctionreason', 'local_outcomemap'),
            ['rows' => 3, 'cols' => 70]
        );
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(true, get_string('questionmapping_backdate_submit', 'local_outcomemap'));
    }

    /**
     * Refuse a start that would move nothing, so the page never reports a
     * successful correction of zero mappings.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (trim((string) ($data['reason'] ?? '')) === '') {
            $errors['reason'] = get_string('required');
        }
        $earliest = (int) $this->_customdata['summary']->earliestts;
        if ((int) $data['effectivefrom'] >= $earliest) {
            $errors['effectivefrom'] = get_string(
                'questionmapping_backdate_notearlier',
                'local_outcomemap',
                userdate($earliest)
            );
        }
        return $errors;
    }
}
