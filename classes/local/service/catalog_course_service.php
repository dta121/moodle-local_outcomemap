<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/** Transactional service for stable catalog courses. */
final class catalog_course_service extends base_service {
    private const TABLE = 'local_outcomemap_course';

    /** Create a draft catalog course. */
    public static function create(array $data): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $now = time();
        $record = (object) [
            'uuid' => uuid::normalize_or_generate($data['uuid'] ?? null),
            'code' => input::required_text($data['code'] ?? '', 'code', 100),
            'name' => input::required_text($data['name'] ?? '', 'name', 255),
            'description' => input::optional_multiline($data['description'] ?? null),
            'siskey' => input::optional_text($data['siskey'] ?? null, 'siskey', 255),
            'status' => workflow::DRAFT,
            'createdby' => $actorid,
            'modifiedby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        self::require_unique($record->uuid, $record->code);
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record(self::TABLE, $record);
            $record->id = $id;
            audit_writer::write('create', 'catalog_course', $id, $record->uuid, null, $record, null,
                \context_system::instance(), $actorid);
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Update a draft catalog course. */
    public static function update(int $id, array $data): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $before = self::get_required(self::TABLE, $id, 'catalog_course');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'catalog_course', $id);
        }
        $after = clone $before;
        $after->code = input::required_text($data['code'] ?? $before->code, 'code', 100);
        $after->name = input::required_text($data['name'] ?? $before->name, 'name', 255);
        $after->description = input::optional_multiline($data['description'] ?? $before->description);
        $after->siskey = input::optional_text($data['siskey'] ?? $before->siskey, 'siskey', 255);
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        self::require_unique($after->uuid, $after->code, $id);
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write('update', 'catalog_course', $id, $after->uuid, $before, $after,
                $data['reason'] ?? null, \context_system::instance(), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    public static function submit_for_review(int $id, ?string $reason = null): void {
        self::change_status($id, workflow::DRAFT, workflow::NEEDS_REVIEW, 'submit_review', $reason,
            'local/outcomemap:managecatalogcourses', false);
    }

    public static function approve(int $id, ?string $reason = null): void {
        $capability = workflow::requires_independent_approval()
            ? 'local/outcomemap:approve'
            : 'local/outcomemap:managecatalogcourses';
        self::change_status($id, workflow::NEEDS_REVIEW, workflow::APPROVED, 'approve', $reason,
            $capability, true);
    }

    public static function retire(int $id, string $reason): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $before = self::get_required(self::TABLE, $id, 'catalog_course');
        if ($before->status === workflow::RETIRED) {
            return;
        }
        $after = clone $before;
        $after->status = workflow::RETIRED;
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write('retire', 'catalog_course', $id, $after->uuid, $before, $after, $reason,
                \context_system::instance(), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    public static function list_all(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        return $DB->get_records(self::TABLE, null, 'code ASC');
    }

    private static function change_status(int $id, string $required, string $status, string $action,
            ?string $reason, string $capability, bool $separateapprover): void {
        global $DB;
        $actorid = self::require_system($capability);
        $before = self::get_required(self::TABLE, $id, 'catalog_course');
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
            audit_writer::write($action, 'catalog_course', $id, $after->uuid, $before, $after, $reason,
                \context_system::instance(), $actorid);
            if ($status === workflow::NEEDS_REVIEW && !workflow::requires_independent_approval()) {
                self::approve($id, $reason);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    private static function require_unique(string $uuidvalue, string $code, int $excludeid = 0): void {
        global $DB;
        if ($DB->record_exists_select(self::TABLE, 'uuid = :uuid AND id <> :id',
                ['uuid' => $uuidvalue, 'id' => $excludeid])) {
            throw new validation_exception('duplicateuuid', 'uuid', $uuidvalue);
        }
        if ($DB->record_exists_select(self::TABLE, 'code = :code AND id <> :id',
                ['code' => $code, 'id' => $excludeid])) {
            throw new validation_exception('duplicatecode', 'code', $code);
        }
    }
}
