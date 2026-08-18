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
 * Explicit commit form bound to a validated preview hash.
 */
final class csv_commit_form extends \moodleform {
    /**
     * Defines the form fields.
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'importid');
        $mform->setType('importid', PARAM_INT);
        $mform->addElement('hidden', 'entity');
        $mform->setType('entity', PARAM_ALPHANUMEXT);
        $mform->addElement('hidden', 'previewhash');
        $mform->setType('previewhash', PARAM_ALPHANUM);
        $mform->addElement('hidden', 'commit', 1);
        $mform->setType('commit', PARAM_BOOL);
        $this->add_action_buttons(true, get_string('commitimport', 'local_outcomemap'));
    }
}
