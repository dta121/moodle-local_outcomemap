<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local;

/**
 * Governed workflow constants and transition validation.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class workflow {
    public const DRAFT = 'draft';
    public const NEEDS_REVIEW = 'needs_review';
    public const APPROVED = 'approved';
    public const RETIRED = 'retired';

    /** @var string[] All supported governance states. */
    public const STATES = [self::DRAFT, self::NEEDS_REVIEW, self::APPROVED, self::RETIRED];

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
