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
 * Privacy-minimises canonical audit payloads before they are persisted.
 *
 * Audit identity, action, object, actor, reason, correlation, and timestamp
 * remain first-class columns. Payloads retain governed transition context but
 * never duplicate direct user identifiers. Learner evidence and result events
 * use explicit allowlists so scores, attempts, lineage, and derived identifiers
 * cannot survive in append-only JSON.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class audit_payload {
    /**
     * Direct Moodle-user reference fields removed at every nesting level.
     */
    private const USER_REFERENCE_KEYS = [
        'userid',
        'userids',
        'createdby',
        'modifiedby',
        'approvedby',
        'confirmedby',
        'actorid',
    ];

    /**
     * Non-personal transition fields retained for learner-owned objects.
     */
    private const LEARNER_SUMMARY_FIELDS = [
        'evidence' => [
            'cinstid',
            'itemverid',
            'mappingid',
            'policyid',
            'evidencetype',
            'gradingstate',
        ],
        'result' => [
            'version',
            'cinstid',
            'scopetype',
            'periodcode',
            'itemverid',
            'policyid',
            'state',
            'stale',
            'algoversion',
        ],
    ];

    /**
     * Minimise and encode one audit payload.
     *
     * @param string $objecttype Stable audit object type.
     * @param mixed $value Before/after value, or null.
     * @return string|null Canonical privacy-minimised JSON.
     */
    public static function encode(string $objecttype, $value): ?string {
        if ($value === null) {
            return null;
        }
        return canonical_json::encode(self::minimise($objecttype, $value));
    }

    /**
     * Minimise an existing canonical JSON payload during a schema upgrade.
     *
     * Invalid historical JSON is preserved rather than silently replaced; all
     * payloads emitted by the supported writer are canonical valid JSON.
     *
     * @param string $objecttype Stable audit object type.
     * @param string|null $json Existing JSON.
     * @return string|null Privacy-minimised JSON.
     */
    public static function minimise_json(string $objecttype, ?string $json): ?string {
        if ($json === null || $json === '') {
            return $json;
        }
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        return canonical_json::encode(self::minimise($objecttype, $decoded));
    }

    /**
     * Return the privacy-minimised structure for one object type.
     *
     * @param string $objecttype Stable audit object type.
     * @param mixed $value Payload value.
     * @return mixed Minimized structure.
     */
    public static function minimise(string $objecttype, $value) {
        $value = self::to_structure($value);
        if (isset(self::LEARNER_SUMMARY_FIELDS[$objecttype]) && is_array($value)) {
            $summary = [];
            foreach (self::LEARNER_SUMMARY_FIELDS[$objecttype] as $field) {
                if (array_key_exists($field, $value)) {
                    $summary[$field] = $value[$field];
                }
            }
            $value = $summary;
        }
        return self::remove_user_references($value);
    }

    /**
     * Convert nested objects to arrays without changing scalar values.
     */
    private static function to_structure($value) {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::to_structure($child);
        }
        return $value;
    }

    /**
     * Remove direct user-reference keys recursively.
     */
    private static function remove_user_references($value) {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), self::USER_REFERENCE_KEYS, true)) {
                unset($value[$key]);
                continue;
            }
            $value[$key] = self::remove_user_references($child);
        }
        return $value;
    }
}
