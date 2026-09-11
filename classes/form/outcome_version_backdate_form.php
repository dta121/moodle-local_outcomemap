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
 * Corrects the effective start of the approved outcome versions in a framework.
 *
 * Propagation resolves the target outcome version in force when an attempt
 * finished, so outcomes created after learners sat their assessments never
 * receive rolled-up results until their versions are held to have governed
 * from the right date. This form states that date once for a framework, or for
 * every framework, and the service audits each version it moves.
 */
final class outcome_version_backdate_form extends \moodleform {
    /**
     * Define the form.
     */
    public function definition(): void {
        $mform = $this->_form;
        $summary = $this->_customdata['summary'];

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'correctdates');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement(
            'static',
            'summary',
            '',
            get_string('hier_correctdates_summary', 'local_outcomemap', $summary)
        );
        if ($summary->skipped > 0) {
            $mform->addElement(
                'static',
                'skipped',
                '',
                get_string('hier_correctdates_skipped', 'local_outcomemap', $summary->skipped)
            );
        }
        $mform->addElement(
            'date_time_selector',
            'effectivefrom',
            get_string('hier_correctdates_date', 'local_outcomemap')
        );
        $mform->addHelpButton('effectivefrom', 'hier_correctdates_date', 'local_outcomemap');
        $mform->addElement(
            'textarea',
            'reason',
            get_string('correctionreason', 'local_outcomemap'),
            ['rows' => 3, 'cols' => 70]
        );
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(true, get_string('hier_correctdates_submit', 'local_outcomemap'));
    }

    /**
     * Refuse a start that would move nothing.
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
                'hier_correctdates_notearlier',
                'local_outcomemap',
                userdate($earliest)
            );
        }
        return $errors;
    }
}
