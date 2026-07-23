<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local;

/**
 * Append-only audit writer used inside mutation transactions.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class audit_writer {
    /**
     * Append an audit event.
     *
     * @param string $action Stable action name.
     * @param string $objecttype Stable object type.
     * @param int|null $objectid Local object ID.
     * @param string|null $objectuuid Stable object UUID.
     * @param mixed $before Previous state or null.
     * @param mixed $after New state or null.
     * @param string|null $reason Human-entered reason.
     * @param \context $context Authoritative Moodle context.
     * @param int|null $actorid Actor ID; null for system tasks.
     * @param string|null $correlationid Correlation UUID.
     * @return int Audit row ID.
     */
    public static function write(
        string $action,
        string $objecttype,
        ?int $objectid,
        ?string $objectuuid,
        $before,
        $after,
        ?string $reason,
        \context $context,
        ?int $actorid,
        ?string $correlationid = null
    ): int {
        global $DB;

        $record = (object) [
            'eventuuid' => uuid::generate(),
            'actorid' => $actorid,
            'contextid' => $context->id,
            'action' => input::required_text($action, 'action', 50),
            'objecttype' => input::required_text($objecttype, 'objecttype', 50),
            'objectid' => $objectid,
            'objectuuid' => $objectuuid === null ? null : uuid::normalize($objectuuid),
            'beforejson' => $before === null ? null : canonical_json::encode($before),
            'afterjson' => $after === null ? null : canonical_json::encode($after),
            'reason' => input::optional_multiline($reason),
            'correlationid' => $correlationid === null ? uuid::generate() : uuid::normalize($correlationid),
            'iphash' => null,
            'timecreated' => time(),
        ];
        return $DB->insert_record('local_outcomemap_audit', $record);
    }
}
