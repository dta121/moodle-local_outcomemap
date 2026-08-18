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
 * Event observer registrations for local_outcomemap.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\question_created',
        'callback' => '\local_outcomemap\observer::question_created',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\local_outcomemap\observer::quiz_attempt_submitted',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_regraded',
        'callback' => '\local_outcomemap\observer::quiz_attempt_regraded',
    ],
    [
        'eventname' => '\mod_quiz\event\question_manually_graded',
        'callback' => '\local_outcomemap\observer::quiz_question_manually_graded',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_deleted',
        'callback' => '\local_outcomemap\observer::quiz_attempt_deleted',
    ],
];
