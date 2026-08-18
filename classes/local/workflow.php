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

namespace local_outcomemap\local;

/**
 * Governed workflow constants and transition validation.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class workflow {
    /**
     * Value for draft.
     */
    public const DRAFT = 'draft';
    /**
     * Value for needs review.
     */
    public const NEEDS_REVIEW = 'needs_review';
    /**
     * Value for approved.
     */
    public const APPROVED = 'approved';
    /**
     * Value for retired.
     */
    public const RETIRED = 'retired';

    /**
     * @var string[] All supported governance states.
     */
    public const STATES = [self::DRAFT, self::NEEDS_REVIEW, self::APPROVED, self::RETIRED];

    /**
     * Whether governed records require approval by a different user.
     *
     * Treat an unset setting as enabled so upgrades preserve the existing
     * governance model until an administrator explicitly disables it.
     *
     * @return bool
     */
    public static function requires_independent_approval(): bool {
        $configured = get_config('local_outcomemap', 'requireapproval');
        return $configured === false ? true : (bool) $configured;
    }

    /**
     * Whether a newly created question mapping submits itself.
     *
     * Off unless an administrator opts in, so the draft-then-submit boundary
     * stays the default. With independent approval also disabled, this carries
     * a mapping all the way to approved at creation.
     *
     * @return bool
     */
    public static function autosubmits_question_mappings(): bool {
        return (bool) get_config('local_outcomemap', 'autosubmitquestionmappings');
    }

    /**
     * Return the visible action label for the submission boundary.
     *
     * @return string
     */
    public static function submit_action_label(): string {
        return get_string(self::requires_independent_approval() ? 'submitreview' : 'finalize', 'local_outcomemap');
    }

    /**
     * Return the visible success message for the submission boundary.
     *
     * @return string
     */
    public static function submission_success_message(): string {
        return get_string(
            self::requires_independent_approval() ? 'submittedforreview' : 'finalized',
            'local_outcomemap'
        );
    }

    /**
     * Return an approval-mode-aware workflow status label for interactive UI.
     *
     * Canonical state names must still be used for storage, audit, and exports.
     *
     * @param string $status Canonical workflow status.
     * @return string
     */
    public static function status_label(string $status): string {
        self::require_valid($status);
        if (!self::requires_independent_approval()) {
            if ($status === self::NEEDS_REVIEW) {
                return get_string('status_pending', 'local_outcomemap');
            }
            if ($status === self::APPROVED) {
                return get_string('status_finalized', 'local_outcomemap');
            }
        }
        return get_string('status_' . $status, 'local_outcomemap');
    }

    /**
     * Enforce creator/approver separation when independent approval is enabled.
     *
     * @param int $createdby User who created the governed record.
     * @param int $actorid User attempting to approve it.
     * @return void
     */
    public static function require_approver_separation(int $createdby, int $actorid): void {
        if (self::requires_independent_approval() && $createdby === $actorid) {
            throw new validation_exception('creatorcannotapprove', 'createdby', $actorid);
        }
    }

    /**
     * Validate a workflow state.
     *
     * @param string $status Status to validate.
     * @return void
     */
    public static function require_valid(string $status): void {
        if (!in_array($status, self::STATES, true)) {
            throw new validation_exception('invalidstatus', 'status', $status);
        }
    }

    /**
     * Validate a state transition.
     *
     * Approved records are immutable; retirement is represented by a replacement
     * version in versioned services rather than mutating the approved row.
     *
     * @param string $from Existing status.
     * @param string $to Requested status.
     * @return void
     */
    public static function require_transition(string $from, string $to): void {
        self::require_valid($from);
        self::require_valid($to);

        $allowed = [
            self::DRAFT => [self::DRAFT, self::NEEDS_REVIEW, self::RETIRED],
            self::NEEDS_REVIEW => [self::DRAFT, self::NEEDS_REVIEW, self::APPROVED, self::RETIRED],
            self::APPROVED => [self::APPROVED],
            self::RETIRED => [self::RETIRED],
        ];
        if (!in_array($to, $allowed[$from], true)) {
            throw new validation_exception('invalidtransition', 'status', $from . ':' . $to);
        }
    }
}
