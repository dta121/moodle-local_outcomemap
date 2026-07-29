<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\task;

use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\workflow;

/**
 * Scheduled reconciliation of stale and missing outcome evidence.
 *
 * Detects graded attempts without current evidence and results marked stale
 * by mapping or policy changes, then recalculates idempotently in bounded
 * batches. Prior valid results are only superseded, never lost.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_evidence extends \core\task\scheduled_task {
    /** Maximum recalculation tuples per run. */
    private const BATCH = 200;

    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskreconcile', 'local_outcomemap');
    }

    /**
     * Run the reconciliation.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        $tuples = [];

        // Stale results queued by mapping or policy changes. Collected through a
        // recordset because get_records_sql() keys rows by their first column:
        // courseid repeats across learners, so all but one learner per course
        // would be silently dropped from the batch.
        $stale = $DB->get_recordset_sql(
            "SELECT DISTINCT ci.moodlecourseid AS courseid, e.assessmentcmid AS cmid, r.userid
               FROM {local_outcomemap_result} r
               JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
               JOIN {local_outcomemap_evidence} e
                     ON e.userid = r.userid AND e.cinstid = r.cinstid AND e.itemverid = r.itemverid
              WHERE r.stale = 1 AND r.supersededby IS NULL",
            [],
            '',
            '*',
            0,
            self::BATCH
        );
        $this->collect($stale, $tuples);

        // Learners with a finished attempt on a mapped quiz but no evidence yet
        // for that assessment. Deliberately not "attempts without evidence":
        // ingestion only reads the attempts the governing attempt-selection
        // policy selects, so under any single-attempt method every other
        // finished attempt would stay evidence-free permanently and this query
        // would hand back the same learners on every run, forever.
        $missing = $DB->get_recordset_sql(
            "SELECT DISTINCT cm.course AS courseid, cm.id AS cmid, qa.userid
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {course_modules} cm ON cm.instance = q.id
               JOIN {modules} md ON md.id = cm.module AND md.name = 'quiz'
               JOIN {local_outcomemap_cinst} ci
                     ON ci.moodlecourseid = cm.course AND ci.status = :approved AND ci.confirmed = 1
              WHERE qa.state = 'finished' AND qa.preview = 0
                AND EXISTS (
                    SELECT 1
                      FROM {question_attempts} sqa
                      JOIN {question_versions} sqv ON sqv.questionid = sqa.questionid
                      JOIN {local_outcomemap_qmap} sm ON sm.questionversionid = sqv.id
                           AND sm.role = 'assesses' AND sm.status = :mapapproved
                     WHERE sqa.questionusageid = qa.uniqueid
                       -- In force when the attempt finished, which is the only
                       -- mapping ingestion will read. Without this the query
                       -- keeps returning learners whose mappings postdate their
                       -- attempts, and no run can ever satisfy them.
                       AND sm.effectivefrom <= COALESCE(NULLIF(qa.timefinish, 0), qa.timemodified)
                       AND (sm.effectiveto IS NULL
                            OR sm.effectiveto > COALESCE(NULLIF(qa.timefinish, 0), qa.timemodified)))
                AND NOT EXISTS (
                    SELECT 1 FROM {local_outcomemap_evidence} e
                     WHERE e.cinstid = ci.id AND e.userid = qa.userid
                       AND e.assessmentcmid = cm.id AND e.supersededby IS NULL)",
            ['approved' => workflow::APPROVED, 'mapapproved' => workflow::APPROVED],
            '',
            '*',
            0,
            self::BATCH
        );
        $this->collect($missing, $tuples);

        $processed = 0;
        $errors = 0;
        foreach (array_slice($tuples, 0, self::BATCH) as $tuple) {
            try {
                calculation_service::recalculate_user_assessment(
                    (int) $tuple->courseid, (int) $tuple->cmid, (int) $tuple->userid);
                $processed++;
            } catch (\Throwable $e) {
                // Errors never erase prior valid results; surface and continue.
                $errors++;
                mtrace('local_outcomemap reconciliation error: ' . $e->getMessage());
            }
        }
        set_config('reconcile_lastrun', time(), 'local_outcomemap');
        set_config('reconcile_lastprocessed', $processed, 'local_outcomemap');
        set_config('reconcile_lasterrors', $errors, 'local_outcomemap');
        mtrace("local_outcomemap reconciliation: {$processed} recalculated, {$errors} errors.");
    }

    /**
     * Add every course, assessment, and learner tuple of a recordset to the batch.
     *
     * @param \moodle_recordset $recordset Recordset of courseid, cmid, and userid rows.
     * @param array $tuples Batch keyed by tuple, added to in place.
     * @return void
     */
    private function collect(\moodle_recordset $recordset, array &$tuples): void {
        foreach ($recordset as $row) {
            $tuples[$row->courseid . ':' . $row->cmid . ':' . $row->userid] = $row;
        }
        $recordset->close();
    }
}
