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
 * Learning Outcome Mapping plugin component.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Transactional service for Moodle course-instance associations.
 */
final class course_instance_service extends base_service {
    /**
     * Database table name.
     */
    private const TABLE = 'local_outcomemap_cinst';

    /**
     * Create an association and finalize it where governance allows.
     *
     * An association is a factual link between a catalog course and a Moodle
     * course, so when a site has disabled independent approval there is nothing
     * for a second pair of eyes to weigh and the draft-then-submit step is pure
     * ceremony. With independent approval enabled the record is left a draft for
     * a reviewer, exactly as before.
     *
     * @param array $data Association data.
     * @return int The new association ID.
     */
    public static function create_confirmed(array $data): int {
        $id = self::create($data);
        if (!workflow::requires_independent_approval()) {
            // The submit_for_review() call confirms in the same transaction when
            // independent approval is off.
            self::submit_for_review($id);
        }
        return $id;
    }

    /**
     * Load one association with its catalog course and Moodle course names.
     *
     * @param int $id Association ID.
     * @return \stdClass Association record.
     */
    public static function get(int $id): \stdClass {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        $record = $DB->get_record_sql(
            "SELECT ci.*, c.code AS catalogcode, c.name AS catalogname, mc.fullname AS moodlename
               FROM {local_outcomemap_cinst} ci
               JOIN {local_outcomemap_course} c ON c.id = ci.courseid
               JOIN {course} mc ON mc.id = ci.moodlecourseid
              WHERE ci.id = :id",
            ['id' => $id]
        );
        if (!$record) {
            throw new validation_exception('recordnotfound', 'course_instance', $id);
        }
        return $record;
    }

    /**
     * Report why an association cannot be deleted.
     *
     * Deleting is offered only to undo a mistake, so anything already built on
     * the association blocks it: mapped content, calculated evidence or results,
     * remediation, a frozen snapshot row, or a policy scoped to it.
     *
     * @param int $id Association ID.
     * @return string[] Human-readable blockers; empty when deletion is safe.
     */
    public static function deletion_blockers(int $id): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        self::get_required(self::TABLE, $id, 'course_instance');
        $counts = [
            'blocker_contentmappings' => $DB->count_records('local_outcomemap_cmmap', ['cinstid' => $id])
                + $DB->count_records('local_outcomemap_secmap', ['cinstid' => $id]),
            'blocker_evidence' => $DB->count_records('local_outcomemap_evidence', ['cinstid' => $id]),
            'blocker_results' => $DB->count_records('local_outcomemap_result', ['cinstid' => $id]),
            'blocker_remediation' => $DB->count_records('local_outcomemap_remed', ['cinstid' => $id]),
            'blocker_snapshots' => $DB->count_records('local_outcomemap_snapitem', ['cinstid' => $id]),
            'blocker_policies' => $DB->count_records('local_outcomemap_policy', [
                'scopetype' => policy_service::SCOPE_COURSE_INSTANCE,
                'scopeid' => $id,
            ]),
        ];
        $blockers = [];
        foreach ($counts as $identifier => $count) {
            if ($count > 0) {
                $blockers[] = get_string($identifier, 'local_outcomemap', $count);
            }
        }
        return $blockers;
    }

    /**
     * Delete an association that nothing depends on.
     *
     * Approved associations are normally immutable, but an association carries no
     * governed judgement of its own: once nothing references it, removing it
     * erases no history. The deletion is audited like any other change.
     *
     * @param int $id Association ID.
     * @param string|null $reason Optional audit reason.
     * @return void
     */
    public static function delete(int $id, ?string $reason = null): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $before = self::get_required(self::TABLE, $id, 'course_instance');
        $blockers = self::deletion_blockers($id);
        if ($blockers) {
            throw new validation_exception('courseinstanceinuse', 'id', implode(' ', $blockers));
        }
        $context = $DB->record_exists('course', ['id' => $before->moodlecourseid])
            ? \context_course::instance($before->moodlecourseid)
            : \context_system::instance();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records(self::TABLE, ['id' => $id]);
            audit_writer::write(
                'delete',
                'course_instance',
                $id,
                $before->uuid,
                $before,
                null,
                $reason,
                $context,
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Create an unconfirmed draft course-instance association.
     */
    public static function create(array $data): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $courseid = input::positive_int($data['courseid'] ?? 0, 'courseid');
        self::get_required('local_outcomemap_course', $courseid, 'catalog_course');
        $moodlecourseid = input::positive_int($data['moodlecourseid'] ?? 0, 'moodlecourseid');
        if (!$DB->record_exists('course', ['id' => $moodlecourseid])) {
            throw new validation_exception('moodlecoursenotfound', 'moodlecourseid', $moodlecourseid);
        }
        $periodcode = input::required_text($data['periodcode'] ?? '', 'periodcode', 100);
        if ($DB->record_exists(self::TABLE, ['moodlecourseid' => $moodlecourseid, 'periodcode' => $periodcode])) {
            throw new validation_exception('courseinstanceexists', 'periodcode', $periodcode);
        }
        $now = time();
        $record = (object) [
            'uuid' => uuid::normalize_or_generate($data['uuid'] ?? null),
            'courseid' => $courseid,
            'moodlecourseid' => $moodlecourseid,
            'periodcode' => $periodcode,
            'externalid' => input::optional_text($data['externalid'] ?? null, 'externalid', 255),
            'status' => workflow::DRAFT,
            'confirmed' => 0,
            'confirmedby' => null,
            'confirmedat' => null,
            'createdby' => $actorid,
            'modifiedby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        if ($DB->record_exists(self::TABLE, ['uuid' => $record->uuid])) {
            throw new validation_exception('duplicateuuid', 'uuid', $record->uuid);
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record(self::TABLE, $record);
            $record->id = $id;
            audit_writer::write(
                'create',
                'course_instance',
                $id,
                $record->uuid,
                null,
                $record,
                null,
                \context_course::instance($moodlecourseid),
                $actorid
            );
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Submit an association for independent confirmation.
     */
    public static function submit_for_review(int $id, ?string $reason = null): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $before = self::get_required(self::TABLE, $id, 'course_instance');
        if ($before->status !== workflow::DRAFT || (int) $before->confirmed === 1) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':needs_review');
        }
        $after = clone $before;
        $after->status = workflow::NEEDS_REVIEW;
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                'submit_review',
                'course_instance',
                $id,
                $after->uuid,
                $before,
                $after,
                $reason,
                \context_course::instance($after->moodlecourseid),
                $actorid
            );
            if (!workflow::requires_independent_approval()) {
                self::confirm($id, $reason);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Confirm an association in its authoritative course context.
     */
    public static function confirm(int $id, ?string $reason = null): void {
        global $DB, $USER;
        $before = self::get_required(self::TABLE, $id, 'course_instance');
        $context = \context_course::instance($before->moodlecourseid);
        if (workflow::requires_independent_approval()) {
            if (
                !has_capability('local/outcomemap:approve', $context)
                    && !has_capability('local/outcomemap:managecatalogcourses', \context_system::instance())
            ) {
                require_capability('local/outcomemap:approve', $context);
            }
            $actorid = (int) $USER->id;
        } else {
            $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        }
        if ($before->status !== workflow::NEEDS_REVIEW || (int) $before->confirmed === 1) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':approved');
        }
        workflow::require_approver_separation((int) $before->createdby, $actorid);
        if (!$DB->record_exists('course', ['id' => $before->moodlecourseid])) {
            throw new validation_exception('moodlecoursenotfound', 'moodlecourseid', $before->moodlecourseid);
        }
        $after = clone $before;
        $after->status = workflow::APPROVED;
        $after->confirmed = 1;
        $after->confirmedby = $actorid;
        $after->confirmedat = time();
        $after->modifiedby = $actorid;
        $after->timemodified = $after->confirmedat;
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                'confirm',
                'course_instance',
                $id,
                $after->uuid,
                $before,
                $after,
                $reason,
                $context,
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Move approved associations onto a different reporting period.
     *
     * The period code decides which associations a capture covers, because
     * aggregate_service::course_instances() matches it exactly. An association
     * seeded with a course code rather than an academic period therefore cannot
     * be captured alongside its siblings, and no new version can express the
     * change: an association carries no version history of its own.
     *
     * Approved associations are otherwise immutable, so this is deliberately a
     * correction rather than an edit — it is audited per row with a required
     * reason, and it asserts that the association always belonged to the period
     * now named. Existing results keep the period they were reported under;
     * recalculation writes the new one, which is the honest record of when each
     * figure was produced.
     *
     * @param int[] $ids Approved association IDs to move together.
     * @param string $periodcode New reporting period code.
     * @param string $reason Required audit reason.
     * @return int Number of associations moved.
     */
    public static function correct_periodcode(array $ids, string $periodcode, string $reason): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $reason = trim($reason);
        if ($reason === '') {
            throw new validation_exception('requiredfield', 'reason');
        }
        $periodcode = input::required_text($periodcode, 'periodcode', 100);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return 0;
        }
        $records = [];
        foreach ($ids as $id) {
            $record = self::get_required(self::TABLE, $id, 'course_instance');
            if ($record->status !== workflow::APPROVED || (int) $record->confirmed !== 1) {
                throw new validation_exception(
                    'invalidtransition',
                    'status',
                    $record->status . ':correct_periodcode'
                );
            }
            $records[$id] = $record;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($records as $id => $before) {
                if ((string) $before->periodcode === $periodcode) {
                    continue;
                }
                // One Moodle course may hold only one association per period, so a
                // move that would collide has to fail before anything is written.
                $clash = $DB->get_record_select(
                    self::TABLE,
                    'moodlecourseid = :cid AND periodcode = :period AND id <> :id',
                    ['cid' => $before->moodlecourseid, 'period' => $periodcode, 'id' => $id],
                    'id',
                    IGNORE_MULTIPLE
                );
                if ($clash) {
                    throw new validation_exception('courseinstanceexists', 'periodcode', $periodcode);
                }
                $after = clone $before;
                $after->periodcode = $periodcode;
                $after->modifiedby = $actorid;
                $after->timemodified = time();
                $DB->update_record(self::TABLE, $after);
                audit_writer::write(
                    'correct_periodcode',
                    'course_instance',
                    $id,
                    $after->uuid,
                    $before,
                    $after,
                    $reason,
                    \context_course::instance($after->moodlecourseid),
                    $actorid
                );
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
        return count($records);
    }

    /**
     * Lists all records.
     */
    public static function list_all(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        $sql = "SELECT ci.*, c.code AS catalogcode, c.name AS catalogname, mc.fullname AS moodlename
                  FROM {local_outcomemap_cinst} ci
                  JOIN {local_outcomemap_course} c ON c.id = ci.courseid
                  JOIN {course} mc ON mc.id = ci.moodlecourseid
              ORDER BY ci.periodcode DESC, c.code ASC";
        return $DB->get_records_sql($sql);
    }

    /**
     * Return every association with the Moodle course facts a reader needs.
     *
     * The delivery window and active enrolment count come from the Moodle course
     * shell itself, so an administrator can tell an association that is running
     * from one that has ended without opening each course. Ordering groups the
     * associations under their catalog course, newest reporting period first.
     *
     * @return \stdClass[] Associations keyed by id.
     */
    public static function list_with_summary(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        $sql = "SELECT ci.*, c.code AS catalogcode, c.name AS catalogname,
                       mc.fullname AS moodlename, mc.shortname AS moodleshortname,
                       mc.visible AS moodlevisible, mc.startdate AS moodlestartdate,
                       mc.enddate AS moodleenddate,
                       (SELECT COUNT(DISTINCT ue.userid)
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                         WHERE e.courseid = mc.id
                           AND e.status = :enrolenabled
                           AND ue.status = :useractive) AS enrolledcount
                  FROM {local_outcomemap_cinst} ci
                  JOIN {local_outcomemap_course} c ON c.id = ci.courseid
                  JOIN {course} mc ON mc.id = ci.moodlecourseid
              ORDER BY c.code ASC, ci.periodcode DESC";
        return $DB->get_records_sql($sql, [
            'enrolenabled' => ENROL_INSTANCE_ENABLED,
            'useractive' => ENROL_USER_ACTIVE,
        ]);
    }
}
