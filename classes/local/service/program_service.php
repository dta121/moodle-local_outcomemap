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

/**
 * Transactional service for stable academic programs.
 *
 * @package local_outcomemap
 */
final class program_service extends base_service {
    private const TABLE = 'local_outcomemap_program';

    public const TYPE_GRADUATE = 'graduate';
    public const TYPE_UNDERGRADUATE = 'undergraduate';
    public const TYPE_SPECIALIZATION = 'specialization';

    public const CREDENTIAL_DEGREE = 'degree';
    public const CREDENTIAL_CERTIFICATE = 'certificate';

    /** @var string[] Supported governed program types. */
    public const PROGRAM_TYPES = [
        self::TYPE_GRADUATE,
        self::TYPE_UNDERGRADUATE,
        self::TYPE_SPECIALIZATION,
    ];

    /** @var string[] Supported credentials awarded by a program. */
    public const CREDENTIALS = [
        self::CREDENTIAL_DEGREE,
        self::CREDENTIAL_CERTIFICATE,
    ];

    /** Create a draft program. */
    public static function create(array $data): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageprograms');
        $now = time();
        $programtype = self::normalize_program_type($data['programtype'] ?? null);
        $record = (object) [
            'uuid' => uuid::normalize_or_generate($data['uuid'] ?? null),
            'code' => input::required_text($data['code'] ?? '', 'code', 100),
            'name' => input::required_text($data['name'] ?? '', 'name', 255),
            'description' => input::optional_multiline($data['description'] ?? null),
            'externalid' => input::optional_text($data['externalid'] ?? null, 'externalid', 255),
            'programtype' => $programtype,
            'credential' => self::normalize_credential($data['credential'] ?? null, $programtype),
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
            audit_writer::write('create', 'program', $id, $record->uuid, null, $record, null,
                \context_system::instance(), $actorid);
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Update a draft program. */
    public static function update(int $id, array $data): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageprograms');
        $before = self::get_required(self::TABLE, $id, 'program');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'program', $id);
        }
        $after = clone $before;
        $after->code = input::required_text($data['code'] ?? $before->code, 'code', 100);
        $after->name = input::required_text($data['name'] ?? $before->name, 'name', 255);
        $after->description = input::optional_multiline($data['description'] ?? $before->description);
        $after->externalid = input::optional_text($data['externalid'] ?? $before->externalid, 'externalid', 255);
        $after->programtype = array_key_exists('programtype', $data)
            ? self::normalize_program_type($data['programtype'])
            : self::normalize_program_type($before->programtype ?? null);
        $after->credential = array_key_exists('credential', $data)
            ? self::normalize_credential($data['credential'], $after->programtype)
            : self::normalize_credential($before->credential ?? null, $after->programtype);
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        self::require_unique($after->uuid, $after->code, $id);

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write('update', 'program', $id, $after->uuid, $before, $after,
                $data['reason'] ?? null, \context_system::instance(), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Submit a draft program for review. */
    public static function submit_for_review(int $id, ?string $reason = null): void {
        self::change_status($id, workflow::DRAFT, workflow::NEEDS_REVIEW, 'submit_review', $reason,
            'local/outcomemap:manageprograms', false);
    }

    /** Approve a reviewed program. */
    public static function approve(int $id, ?string $reason = null): void {
        self::change_status($id, workflow::NEEDS_REVIEW, workflow::APPROVED, 'approve', $reason,
            'local/outcomemap:approve', true);
    }

    /** Retire a program. */
    public static function retire(int $id, string $reason): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageprograms');
        $before = self::get_required(self::TABLE, $id, 'program');
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
            audit_writer::write('retire', 'program', $id, $after->uuid, $before, $after, $reason,
                \context_system::instance(), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Return programs ordered by code. */
    public static function list_all(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        return $DB->get_records(self::TABLE, null, 'code ASC');
    }

    /**
     * Normalize and validate a program type while retaining compatibility with older callers.
     *
     * @param mixed $value Candidate program type.
     * @return string Canonical program type.
     */
    public static function normalize_program_type($value): string {
        $value = clean_param((string) ($value ?? ''), PARAM_ALPHA);
        if ($value === '') {
            $value = self::TYPE_GRADUATE;
        }
        if (!in_array($value, self::PROGRAM_TYPES, true)) {
            throw new validation_exception('invalidprogramtype', 'programtype', $value);
        }
        return $value;
    }

    /**
     * Normalize and validate the credential awarded by a program.
     *
     * Older callers default to a degree, except specializations, which default
     * to a certificate in keeping with the program-type definition.
     *
     * @param mixed $value Candidate credential.
     * @param string|null $programtype Normalized program type, when known.
     * @return string Canonical credential.
     */
    public static function normalize_credential($value, ?string $programtype = null): string {
        $value = clean_param((string) ($value ?? ''), PARAM_ALPHA);
        if ($value === '') {
            $value = $programtype === self::TYPE_SPECIALIZATION
                ? self::CREDENTIAL_CERTIFICATE
                : self::CREDENTIAL_DEGREE;
        }
        if (!in_array($value, self::CREDENTIALS, true)) {
            throw new validation_exception('invalidcredential', 'credential', $value);
        }
        return $value;
    }

    /**
     * Return programs with current governed course, framework, and outcome counts.
     *
     * Counts are loaded in one query so presentation code does not issue queries
     * per program. Retired records and memberships outside their effective range
     * are excluded from the summary.
     */
    public static function list_with_summary(?int $time = null): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        $time = $time ?? time();
        $sql = "SELECT p.*,
                       (SELECT COUNT(DISTINCT pc.courseid)
                          FROM {local_outcomemap_progcourse} pc
                         WHERE pc.programid = p.id
                           AND pc.status <> :pcretired
                           AND pc.effectivefrom <= :pcfrom
                           AND (pc.effectiveto IS NULL OR pc.effectiveto > :pcto)) AS coursecount,
                       (SELECT COUNT(1)
                          FROM {local_outcomemap_fw} fw
                         WHERE fw.ownertype = :fwownertype
                           AND fw.ownerid = p.id
                           AND fw.status <> :fwretired) AS frameworkcount,
                       (SELECT COUNT(1)
                          FROM {local_outcomemap_item} item
                          JOIN {local_outcomemap_fw} itemfw ON itemfw.id = item.frameworkid
                         WHERE itemfw.ownertype = :itemfwownertype
                           AND itemfw.ownerid = p.id
                           AND itemfw.status <> :itemfwretired
                           AND item.status <> :itemretired) AS outcomecount
                  FROM {local_outcomemap_program} p
              ORDER BY p.code ASC";
        return $DB->get_records_sql($sql, [
            'pcretired' => workflow::RETIRED,
            'pcfrom' => $time,
            'pcto' => $time,
            'fwownertype' => framework_service::OWNER_PROGRAM,
            'fwretired' => workflow::RETIRED,
            'itemfwownertype' => framework_service::OWNER_PROGRAM,
            'itemfwretired' => workflow::RETIRED,
            'itemretired' => workflow::RETIRED,
        ]);
    }

    private static function change_status(int $id, string $required, string $status, string $action,
            ?string $reason, string $capability, bool $separateapprover): void {
        global $DB;
        $actorid = self::require_system($capability);
        $before = self::get_required(self::TABLE, $id, 'program');
        if ($before->status !== $required) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':' . $status);
        }
        if ($separateapprover && (int) $before->createdby === $actorid) {
            throw new validation_exception('creatorcannotapprove', 'createdby', $actorid);
        }
        $after = clone $before;
        $after->status = $status;
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write($action, 'program', $id, $after->uuid, $before, $after, $reason,
                \context_system::instance(), $actorid);
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
