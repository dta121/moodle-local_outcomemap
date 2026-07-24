<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;

/**
 * Append-only explicit remediation engagement events.
 *
 * Engagement records a learner choosing an already released and accessible
 * recommendation. It is analytics only: it is never mastery evidence and does
 * not alter evidence, results, bands, completion, or snapshot calculations.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remediation_engagement_service extends base_service {
    /** Engagement event table. */
    private const TABLE = 'local_outcomemap_remed_event';

    /** The learner explicitly opened the recommendation target. */
    public const EVENT_OPENED = 'opened';

    /** Supported explicit event types. */
    public const EVENTS = [self::EVENT_OPENED];

    /**
     * Record an explicit open and return the currently authorized target URL.
     *
     * The learner report is rebuilt before writing so manually supplied IDs,
     * withdrawn releases, stale results, and newly inaccessible targets fail
     * closed. Every successful invocation appends one event; no inferred event
     * is written from page views or Moodle activity completion.
     *
     * @param int $recommendationid Exact governed recommendation version ID.
     * @param int $resultid Exact released learner-result version ID.
     * @return string Authorized HTTP(S) destination.
     */
    public static function record_open(int $recommendationid, int $resultid): string {
        global $DB, $USER;

        $recommendationid = input::positive_int($recommendationid, 'remediationid');
        $resultid = input::positive_int($resultid, 'resultid');
        $sql = "SELECT r.id, ci.moodlecourseid
                  FROM {local_outcomemap_remed} r
                  JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
                 WHERE r.id = :id";
        $recommendation = $DB->get_record_sql($sql, ['id' => $recommendationid], IGNORE_MISSING);
        if ($recommendation === false) {
            throw new validation_exception('remediationnotavailable', 'remediationid', $recommendationid);
        }
        $context = \context_course::instance((int) $recommendation->moodlecourseid, MUST_EXIST);
        require_capability('local/outcomemap:viewownresults', $context);

        $destination = null;
        $report = student_result_service::get_own_report((int) $recommendation->moodlecourseid);
        foreach ($report['rows'] as $row) {
            foreach ($row['remediation'] as $item) {
                if ((int) ($item['recommendationid'] ?? 0) !== $recommendationid
                        || (int) ($item['resultid'] ?? 0) !== $resultid) {
                    continue;
                }
                $candidate = clean_param(trim((string) ($item['targeturl'] ?? '')), PARAM_URL);
                $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
                if ($candidate !== '' && in_array($scheme, ['http', 'https'], true)) {
                    $destination = $candidate;
                }
                break 2;
            }
        }
        if ($destination === null) {
            throw new validation_exception('remediationnotavailable', 'remediationid', $recommendationid);
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->insert_record(self::TABLE, (object) [
                'eventuuid' => uuid::generate(),
                'remediationid' => $recommendationid,
                'resultid' => $resultid,
                'userid' => (int) $USER->id,
                'eventtype' => self::EVENT_OPENED,
                'occurredat' => $now,
                'timecreated' => $now,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }

        return $destination;
    }
}
