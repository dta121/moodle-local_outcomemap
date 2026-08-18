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
 * Transactional service for governed outcome frameworks.
 */
final class framework_service extends base_service {
    /**
     * Database table name.
     */
    private const TABLE = 'local_outcomemap_fw';
    /**
     * Framework owner type.
     */
    public const OWNER_INSTITUTION = 'institution';
    /**
     * Framework owner type.
     */
    public const OWNER_PROGRAM = 'program';
    /**
     * Framework owner type.
     */
    public const OWNER_COURSE = 'catalog_course';
    /**
     * Framework owner type.
     */
    public const OWNER_TYPES = [self::OWNER_INSTITUTION, self::OWNER_PROGRAM, self::OWNER_COURSE];

    /**
     * Create a draft framework.
     */
    public static function create(array $data): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageframeworks');
        [$ownertype, $ownerid] = self::validate_owner($data['ownertype'] ?? '', $data['ownerid'] ?? null);
        $now = time();
        $record = (object) [
            'uuid' => uuid::normalize_or_generate($data['uuid'] ?? null),
            'code' => input::required_text($data['code'] ?? '', 'code', 100),
            'name' => input::required_text($data['name'] ?? '', 'name', 255),
            'description' => input::optional_multiline($data['description'] ?? null),
            'ownertype' => $ownertype,
            'ownerid' => $ownerid,
            'status' => workflow::DRAFT,
            'createdby' => $actorid,
            'modifiedby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        self::require_unique($record->uuid, $ownertype, $ownerid, $record->code);
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record(self::TABLE, $record);
            $record->id = $id;
            audit_writer::write(
                'create',
                'framework',
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
     * Update a draft framework.
     */
    public static function update(int $id, array $data): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageframeworks');
        $before = self::get_required(self::TABLE, $id, 'framework');
        if (!in_array($before->status, [workflow::DRAFT, workflow::APPROVED], true)) {
            throw new validation_exception('approvedimmutable', 'framework', $id);
        }
        if ($before->status === workflow::APPROVED) {
            // An approved framework keeps its identity: the code prefixes every
            // outcome label and is captured verbatim inside frozen accreditation
            // snapshots, and the owner decides which outcomes it may hold. Only
            // the descriptive fields, which nothing references, may still change.
            $data = array_intersect_key($data, ['name' => true, 'description' => true, 'reason' => true]);
        }
        [$ownertype, $ownerid] = self::validate_owner(
            $data['ownertype'] ?? $before->ownertype,
            array_key_exists('ownerid', $data) ? $data['ownerid'] : $before->ownerid
        );
        $after = clone $before;
        $after->code = input::required_text($data['code'] ?? $before->code, 'code', 100);
        $after->name = input::required_text($data['name'] ?? $before->name, 'name', 255);
        $after->description = input::optional_multiline($data['description'] ?? $before->description);
        $after->ownertype = $ownertype;
        $after->ownerid = $ownerid;
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        self::require_unique($after->uuid, $ownertype, $ownerid, $after->code, $id);
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                'update',
                'framework',
                $id,
                $after->uuid,
                $before,
                $after,
                $data['reason'] ?? null,
                \context_system::instance(),
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Submits the record for review.
     */
    public static function submit_for_review(int $id, ?string $reason = null): void {
        self::change_status(
            $id,
            workflow::DRAFT,
            workflow::NEEDS_REVIEW,
            'submit_review',
            $reason,
            'local/outcomemap:manageframeworks',
            false
        );
    }

    /**
     * Approves the record.
     */
    public static function approve(int $id, ?string $reason = null): void {
        $capability = workflow::requires_independent_approval()
            ? 'local/outcomemap:approve'
            : 'local/outcomemap:manageframeworks';
        self::change_status(
            $id,
            workflow::NEEDS_REVIEW,
            workflow::APPROVED,
            'approve',
            $reason,
            $capability,
            true
        );
    }

    /**
     * Lists all records.
     */
    public static function list_all(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        return $DB->get_records(self::TABLE, null, 'ownertype ASC, code ASC');
    }

    /**
     * Changes the record workflow status.
     */
    private static function change_status(
        int $id,
        string $required,
        string $status,
        string $action,
        ?string $reason,
        string $capability,
        bool $separateapprover
    ): void {
        global $DB;
        $actorid = self::require_system($capability);
        $before = self::get_required(self::TABLE, $id, 'framework');
        if ($before->status !== $required) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':' . $status);
        }
        if ($separateapprover) {
            workflow::require_approver_separation((int) $before->createdby, $actorid);
        }
        $after = clone $before;
        $after->status = $status;
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                $action,
                'framework',
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
     * Validates the record owner.
     */
    private static function validate_owner($ownertype, $ownerid): array {
        $ownertype = input::required_text($ownertype, 'ownertype', 20);
        if (!in_array($ownertype, self::OWNER_TYPES, true)) {
            throw new validation_exception('invalidowner', 'ownertype', $ownertype);
        }
        if ($ownertype === self::OWNER_INSTITUTION) {
            if ($ownerid !== null && $ownerid !== '' && (int) $ownerid !== 0) {
                throw new validation_exception('invalidowner', 'ownerid', $ownerid);
            }
            return [$ownertype, null];
        }
        $ownerid = input::positive_int($ownerid, 'ownerid');
        $table = $ownertype === self::OWNER_PROGRAM ? 'local_outcomemap_program' : 'local_outcomemap_course';
        self::get_required($table, $ownerid, $ownertype);
        return [$ownertype, $ownerid];
    }

    /**
     * Requires unique record identifiers.
     */
    private static function require_unique(
        string $uuidvalue,
        string $ownertype,
        ?int $ownerid,
        string $code,
        int $excludeid = 0
    ): void {
        global $DB;
        if (
            $DB->record_exists_select(
                self::TABLE,
                'uuid = :uuid AND id <> :id',
                ['uuid' => $uuidvalue, 'id' => $excludeid]
            )
        ) {
            throw new validation_exception('duplicateuuid', 'uuid', $uuidvalue);
        }
        $select = 'ownertype = :ownertype AND code = :code AND id <> :id';
        $params = ['ownertype' => $ownertype, 'code' => $code, 'id' => $excludeid];
        if ($ownerid === null) {
            $select .= ' AND ownerid IS NULL';
        } else {
            $select .= ' AND ownerid = :ownerid';
            $params['ownerid'] = $ownerid;
        }
        if ($DB->record_exists_select(self::TABLE, $select, $params)) {
            throw new validation_exception('duplicatecode', 'code', $code);
        }
    }
}
