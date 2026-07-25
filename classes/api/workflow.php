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

namespace local_outcomemap\api;

use local_outcomemap\local\workflow as internal_workflow;

/**
 * Stable workflow presentation contract for companion plugins.
 *
 * Canonical states remain owned by local_outcomemap. This facade lets a
 * companion render the configured review/finalization terminology without
 * importing internal implementation classes.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class workflow {
    public const API_VERSION = '1.0';
    public const DRAFT = 'draft';
    public const NEEDS_REVIEW = 'needs_review';
    public const APPROVED = 'approved';
    public const RETIRED = 'retired';

    /** Whether a separate approver is required. */
    public static function requires_independent_approval(): bool {
        return internal_workflow::requires_independent_approval();
    }

    /** Return the configured label for the explicit submission boundary. */
    public static function submit_action_label(): string {
        return internal_workflow::submit_action_label();
    }

    /** Return the configured success message for that boundary. */
    public static function submission_success_message(): string {
        return internal_workflow::submission_success_message();
    }

    /** Return a mode-aware label for a canonical workflow state. */
    public static function status_label(string $status): string {
        return internal_workflow::status_label($status);
    }
}
