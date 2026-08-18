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
 * Shared presentation of a course-instance's governance and delivery state.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\workflow;

/**
 * Classify and label a course-instance association for display.
 *
 * An association is read in two registers at once: its governance state, which
 * decides whether it may govern mappings and results, and the delivery window of
 * the Moodle shell behind it, which decides whether anyone is learning in it right
 * now. Both the Course instances list and the Curriculum page say the same thing
 * about the same association, so the rule lives here rather than in each page.
 */
final class instance_state {
    /**
     * Association is finalized and inside its delivery window.
     */
    public const PHASE_ACTIVE = 'active';

    /**
     * Association is finalized but its Moodle course has ended.
     */
    public const PHASE_ENDED = 'ended';

    /**
     * Association is finalized and its Moodle course has not started.
     */
    public const PHASE_UPCOMING = 'upcoming';

    /**
     * Association has not been confirmed and cannot govern anything yet.
     */
    public const PHASE_DRAFT = 'draft';

    /**
     * Association is retired.
     */
    public const PHASE_RETIRED = 'retired';

    /**
     * Classify an association by governance state and delivery window.
     *
     * @param \stdClass $instance Association with its Moodle course window.
     * @param int $now Reference time.
     * @return string One of the PHASE_* constants.
     */
    public static function phase(\stdClass $instance, int $now): string {
        if ($instance->status === workflow::RETIRED) {
            return self::PHASE_RETIRED;
        }
        if ($instance->status !== workflow::APPROVED || (int) $instance->confirmed !== 1) {
            return self::PHASE_DRAFT;
        }
        $end = (int) $instance->moodleenddate;
        if ($end > 0 && $end < $now) {
            return self::PHASE_ENDED;
        }
        if ((int) $instance->moodlestartdate > $now) {
            return self::PHASE_UPCOMING;
        }
        return self::PHASE_ACTIVE;
    }

    /**
     * Return the badge label, pairing the governance status with the phase.
     *
     * @param \stdClass $instance Association record.
     * @param string $phase Resolved lifecycle phase.
     * @return string
     */
    public static function label(\stdClass $instance, string $phase): string {
        $status = workflow::status_label($instance->status);
        if ($phase === self::PHASE_RETIRED) {
            return $status;
        }
        $suffix = $phase === self::PHASE_DRAFT
            ? ($instance->status === workflow::NEEDS_REVIEW ? 'awaiting' : 'unconfirmed')
            : $phase;
        return get_string('instances_state', 'local_outcomemap', (object) [
            'status' => $status,
            'phase' => get_string('instances_phase_' . $suffix, 'local_outcomemap'),
        ]);
    }

    /**
     * Return the CSS state suffix for the badge.
     *
     * @param \stdClass $instance Association record.
     * @param string $phase Resolved lifecycle phase.
     * @return string
     */
    public static function cssclass(\stdClass $instance, string $phase): string {
        if ($phase === self::PHASE_DRAFT) {
            return $instance->status === workflow::NEEDS_REVIEW ? 'review' : 'draft';
        }
        if ($phase === self::PHASE_ACTIVE) {
            return 'active';
        }
        return $phase === self::PHASE_RETIRED ? 'retired' : 'ended';
    }

    /**
     * Describe the delivery window of the Moodle course shell.
     *
     * @param \stdClass $instance Association with its Moodle course window.
     * @return string
     */
    public static function window(\stdClass $instance): string {
        $start = (int) $instance->moodlestartdate;
        $end = (int) $instance->moodleenddate;
        $format = get_string('strftimedate', 'core_langconfig');
        if ($start > 0 && $end > 0) {
            return get_string('instances_window', 'local_outcomemap', (object) [
                'from' => userdate($start, $format),
                'to' => userdate($end, $format),
            ]);
        }
        if ($start > 0) {
            return get_string('instances_window_open', 'local_outcomemap', userdate($start, $format));
        }
        if ($end > 0) {
            return get_string('instances_window_until', 'local_outcomemap', userdate($end, $format));
        }
        return get_string('instances_window_none', 'local_outcomemap');
    }

    /**
     * Describe how many learners hold an active enrolment in the Moodle shell.
     *
     * @param \stdClass $instance Association with its enrolment count.
     * @return string
     */
    public static function enrolled(\stdClass $instance): string {
        $count = (int) $instance->enrolledcount;
        if ($count === 0) {
            return get_string('instances_enrolled_none', 'local_outcomemap');
        }
        return get_string(
            $count === 1 ? 'instances_enrolled_one' : 'instances_enrolled',
            'local_outcomemap',
            $count
        );
    }
}
