<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\reportbuilder\local;

use stdClass;

/**
 * Pure formatting callbacks for Report Builder columns.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class format {
    /**
     * Format a preloaded Moodle user name without database access.
     *
     * @param string|null $firstname First selected field.
     * @param stdClass $user Preloaded name fields.
     * @return string
     */
    public static function user_fullname(?string $firstname, stdClass $user): string {
        if ($firstname === null) {
            return '';
        }

        return fullname($user);
    }

    /**
     * Return one scalar from a preloaded canonical snapshot-item payload.
     *
     * Columns using this callback preload payloadjson, a literal payloadfield,
     * and an optional live fallback in their SELECT. The callback only decodes
     * those selected values and never performs DML. Frozen payload data wins so
     * later renames cannot relabel historical reports.
     *
     * @param string|null $payloadjson Canonical snapitem payload JSON.
     * @param stdClass $record Preloaded payloadfield and fallback aliases.
     * @return mixed
     */
    public static function snapshot_payload_value(?string $payloadjson, stdClass $record) {
        $fallback = $record->fallback ?? null;
        if ($payloadjson === null || !isset($record->payloadfield)) {
            return $fallback;
        }

        $decoded = json_decode($payloadjson, true);
        if (!is_array($decoded) || !is_array($decoded['payload'] ?? null)) {
            return $fallback;
        }

        $field = (string) $record->payloadfield;
        if (!array_key_exists($field, $decoded['payload'])) {
            return $fallback;
        }

        $value = $decoded['payload'][$field];
        return is_scalar($value) || $value === null ? $value : $fallback;
    }
}
