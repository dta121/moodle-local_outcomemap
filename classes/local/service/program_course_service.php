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
use local_outcomemap\local\effective_dates;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Transactional service for program-course memberships.
 */
final class program_course_service extends base_service {
    /**
     * Database table name.
     */
    private const TABLE = 'local_outcomemap_progcourse';

    /**
     * Create a draft effective-dated membership.
     */
    public static function create(array $data): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageprograms');
        $programid = input::positive_int($data['programid'] ?? 0, 'programid');
        $courseid = input::positive_int($data['courseid'] ?? 0, 'courseid');
        self::get_required('local_outcomemap_program', $programid, 'program');
        self::get_required('local_outcomemap_course', $courseid, 'catalog_course');
        $from = input::positive_int($data['effectivefrom'] ?? 0, 'effectivefrom');
        $to = input::optional_timestamp($data['effectiveto'] ?? null, 'effectiveto');
        effective_dates::validate($from, $to);
        $now = time();
        $record = (object) [
            'uuid' => uuid::normalize_or_generate($data['uuid'] ?? null),
            'programid' => $programid,
            'courseid' => $courseid,
            'status' => workflow::DRAFT,
            'effectivefrom' => $from,
            'effectiveto' => $to,
            'createdby' => $actorid,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
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
                'program_course',
                $id,
                $record->uuid,
                null,
                $record,
                null,
                \context_system::instance(),
                $actorid
            );
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Submits the record for review.
     */
    public static function submit_for_review(int $id, ?string $reason = null): void {
        self::set_status($id, workflow::DRAFT, workflow::NEEDS_REVIEW, 'submit_review', $reason, false);
    }

    /**
     * Approves the record.
     */
    public static function approve(int $id, ?string $reason = null): void {
        global $DB;
        $actorid = self::require_approval_system('local/outcomemap:manageprograms');
        $before = self::get_required(self::TABLE, $id, 'program_course');
        if ($before->status !== workflow::NEEDS_REVIEW) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':approved');
        }
        workflow::require_approver_separation((int) $before->createdby, $actorid);
        self::require_no_approved_overlap($before);
        $after = clone $before;
        $after->status = workflow::APPROVED;
        $after->approvedby = $actorid;
        $after->approvedat = time();
        $after->timemodified = $after->approvedat;
        $transaction = $DB->start_delegated_transaction();
        try {
            self::require_no_approved_overlap($before);
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                'approve',
                'program_course',
                $id,
                $after->uuid,
                $before,
                $after,
                $reason,
                \context_system::instance(),
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Take a catalog course out of a program.
     *
     * What that means depends on whether the membership ever took effect. A draft
     * or in-review membership governed nothing and was never captured by any
     * accreditation snapshot, so a mistaken attachment is deleted outright. An
     * approved one may already be recorded in a frozen snapshot, so the row has to
     * survive as history and is retired instead — which the curriculum page treats
     * the same way, since it lists only non-retired memberships.
     *
     * @param int $id Membership ID.
     * @param string|null $reason Why it is being removed.
     * @return void
     */
    public static function remove(int $id, ?string $reason = null): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageprograms');
        $before = self::get_required(self::TABLE, $id, 'program_course');
        if ($before->status === workflow::RETIRED) {
            return;
        }
        $context = \context_system::instance();
        $transaction = $DB->start_delegated_transaction();
        try {
            if ($before->status === workflow::APPROVED) {
                $after = clone $before;
                $after->status = workflow::RETIRED;
                $after->timemodified = time();
                $DB->update_record(self::TABLE, $after);
                audit_writer::write(
                    'retire',
                    'program_course',
                    $id,
                    $before->uuid,
                    $before,
                    $after,
                    $reason,
                    $context,
                    $actorid
                );
            } else {
                $DB->delete_records(self::TABLE, ['id' => $id]);
                audit_writer::write(
                    'delete',
                    'program_course',
                    $id,
                    $before->uuid,
                    $before,
                    null,
                    $reason,
                    $context,
                    $actorid
                );
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Move a catalog course from the program it is in to another one.
     *
     * A move is the correction for attaching a course to the wrong program, so it
     * is the two halves — leaving the old program and joining the new one — done
     * together, keeping the effective dates the reader already set. The new
     * membership starts as a draft like any other, because which program teaches a
     * course is exactly the kind of claim this plugin governs.
     *
     * @param int $id Membership to move.
     * @param int $targetprogramid Program to move it into.
     * @param string|null $reason Why it is being moved.
     * @return int The new membership ID.
     */
    public static function move(int $id, int $targetprogramid, ?string $reason = null): int {
        global $DB;
        self::require_system('local/outcomemap:manageprograms');
        $before = self::get_required(self::TABLE, $id, 'program_course');
        $targetprogramid = input::positive_int($targetprogramid, 'programid');
        self::get_required('local_outcomemap_program', $targetprogramid, 'program');
        if ((int) $before->programid === $targetprogramid) {
            throw new validation_exception('membershipsameprogram', 'programid', $targetprogramid);
        }
        $duplicate = $DB->record_exists_select(
            self::TABLE,
            'programid = :programid AND courseid = :courseid AND status <> :retired',
            [
                'programid' => $targetprogramid,
                'courseid' => (int) $before->courseid,
                'retired' => workflow::RETIRED,
            ]
        );
        if ($duplicate) {
            throw new validation_exception('membershipalreadyintarget', 'programid', $targetprogramid);
        }
        // Both halves open their own delegated transaction, and a nested rollback
        // already forces this outer one back, so a catch here would only replace the
        // real failure with "Transactions already disposed". The exception is left
        // to propagate to the caller, which reports it.
        $transaction = $DB->start_delegated_transaction();
        self::remove($id, $reason);
        $newid = self::create([
            'programid' => $targetprogramid,
            'courseid' => (int) $before->courseid,
            'effectivefrom' => (int) $before->effectivefrom,
            'effectiveto' => $before->effectiveto === null ? null : (int) $before->effectiveto,
        ]);
        $transaction->allow_commit();
        return $newid;
    }

    /**
     * Return every membership with its program and catalog course descriptors.
     *
     * The program name and type travel with the row so a page can group
     * memberships under their catalog course without a query per row.
     *
     * @return \stdClass[] Memberships keyed by id.
     */
    public static function list_all(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        $sql = "SELECT pc.*, p.code AS programcode, p.name AS programname,
                       p.programtype AS programtype, c.code AS coursecode, c.name AS coursename
                  FROM {local_outcomemap_progcourse} pc
                  JOIN {local_outcomemap_program} p ON p.id = pc.programid
                  JOIN {local_outcomemap_course} c ON c.id = pc.courseid
              ORDER BY p.code, c.code, pc.effectivefrom DESC";
        return $DB->get_records_sql($sql);
    }

    /**
     * Sets the record workflow status.
     */
    private static function set_status(
        int $id,
        string $required,
        string $status,
        string $action,
        ?string $reason,
        bool $approving
    ): void {
        global $DB;
        $actorid = $approving
            ? self::require_approval_system('local/outcomemap:manageprograms')
            : self::require_system('local/outcomemap:manageprograms');
        $before = self::get_required(self::TABLE, $id, 'program_course');
        if ($before->status !== $required) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':' . $status);
        }
        $after = clone $before;
        $after->status = $status;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                $action,
                'program_course',
                $id,
                $after->uuid,
                $before,
                $after,
                $reason,
                \context_system::instance(),
                $actorid
            );
            if ($status === workflow::NEEDS_REVIEW && !workflow::requires_independent_approval()) {
                self::approve($id, $reason);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Requires nonoverlapping approved effective dates.
     */
    private static function require_no_approved_overlap(\stdClass $candidate): void {
        global $DB;
        [$overlapsql, $params] = effective_dates::overlap_sql(
            '',
            (int) $candidate->effectivefrom,
            $candidate->effectiveto === null ? null : (int) $candidate->effectiveto,
            'pc'
        );
        $params += [
            'programid' => $candidate->programid,
            'courseid' => $candidate->courseid,
            'status' => workflow::APPROVED,
            'id' => $candidate->id,
        ];
        $sql = 'programid = :programid AND courseid = :courseid AND status = :status AND id <> :id AND ' . $overlapsql;
        if ($DB->record_exists_select(self::TABLE, $sql, $params)) {
            throw new validation_exception('effectiverangeoverlap', 'effectivefrom');
        }
    }
}
