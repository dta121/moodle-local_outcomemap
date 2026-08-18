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

namespace local_outcomemap\reportbuilder\local;

use context;
use core\context_helper;

/**
 * Shared Report Builder access checks.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access {
    /**
     * Check an all-of capability set followed by an optional any-of set.
     *
     * @param context $context Access context.
     * @param string[] $required Capabilities all required in the context.
     * @param string[] $anyof Capabilities of which at least one is required.
     * @return bool
     */
    public static function can_view(context $context, array $required, array $anyof = []): bool {
        foreach ($required as $capability) {
            if (!has_capability($capability, $context)) {
                return false;
            }
        }

        return $anyof === [] || has_any_capability($anyof, $context);
    }

    /**
     * Resolve an already bulk-loaded context set to the contexts the user may access.
     *
     * Each record must contain the aliases returned by
     * context_helper::get_preload_record_columns_sql(). Contexts are preloaded in
     * the set query before exact Moodle capability evaluation, so this method does
     * not perform one database query per report row or per context.
     *
     * @param \stdClass[] $records Preloaded context records keyed arbitrarily.
     * @param string[] $required Capabilities all required in each context.
     * @param string[] $anyof Capabilities of which at least one is required.
     * @return int[] Allowed context IDs keyed by context ID.
     */
    public static function allowed_context_ids(array $records, array $required, array $anyof = []): array {
        $allowed = [];
        foreach ($records as $record) {
            // Priming the context cache strips the ctx* columns from the record
            // it is given, so preload from a copy and leave the caller's records
            // intact for the scope IDs they still have to read.
            $preloadable = clone $record;
            context_helper::preload_from_record($preloadable);
            $context = context::instance_by_id((int) $record->ctxid, MUST_EXIST);
            if (self::can_view($context, $required, $anyof)) {
                $allowed[$context->id] = $context->id;
            }
        }
        return $allowed;
    }
}
