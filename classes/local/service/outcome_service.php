<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\effective_dates;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/** Transactional service for stable outcomes and effective-dated versions. */
final class outcome_service extends base_service {
    private const ITEM_TABLE = 'local_outcomemap_item';
    private const VERSION_TABLE = 'local_outcomemap_itemver';

    /** Create a stable outcome and its initial draft version. */
    public static function create(array $data): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageframeworks');
        $frameworkid = input::positive_int($data['frameworkid'] ?? 0, 'frameworkid');
        self::get_required('local_outcomemap_fw', $frameworkid, 'framework');
        $code = input::required_text($data['code'] ?? '', 'code', 100);
        if ($DB->record_exists(self::ITEM_TABLE, ['frameworkid' => $frameworkid, 'code' => $code])) {
            throw new validation_exception('duplicatecode', 'code', $code);
        }
        $from = input::positive_int($data['effectivefrom'] ?? 0, 'effectivefrom');
        $to = input::optional_timestamp($data['effectiveto'] ?? null, 'effectiveto');
        effective_dates::validate($from, $to);
        $now = time();
        $item = (object) [
            'uuid' => uuid::normalize_or_generate($data['uuid'] ?? null),
            'frameworkid' => $frameworkid,
            'code' => $code,
            'status' => workflow::DRAFT,
            'createdby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $version = (object) [
            'uuid' => uuid::normalize_or_generate($data['versionuuid'] ?? null),
            'version' => 1,
            'statement' => self::required_statement($data['statement'] ?? ''),
            'shortstatement' => input::optional_text($data['shortstatement'] ?? null, 'shortstatement', 255),
            'bloomlevel' => input::optional_text($data['bloomlevel'] ?? null, 'bloomlevel', 50),
            'status' => workflow::DRAFT,
            'effectivefrom' => $from,
            'effectiveto' => $to,
            'changereason' => input::optional_multiline($data['changereason'] ?? null),
            'createdby' => $actorid,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
        ];
        self::require_unique_uuid($item->uuid, $version->uuid);
        $correlationid = uuid::generate();
        $transaction = $DB->start_delegated_transaction();
        try {
            $itemid = $DB->insert_record(self::ITEM_TABLE, $item);
            $item->id = $itemid;
            $version->itemid = $itemid;
            $versionid = $DB->insert_record(self::VERSION_TABLE, $version);
            $version->id = $versionid;
            $context = \context_system::instance();
            audit_writer::write('create', 'outcome', $itemid, $item->uuid, null, $item, null,
                $context, $actorid, $correlationid);
            audit_writer::write('create', 'outcome_version', $versionid, $version->uuid, null, $version,
                $version->changereason, $context, $actorid, $correlationid);
            $transaction->allow_commit();
            return $itemid;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Update a draft version and, for an initial draft, its stable code. */
    public static function update_draft(int $versionid, array $data): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageframeworks');
        $beforeversion = self::get_required(self::VERSION_TABLE, $versionid, 'outcome_version');
        if ($beforeversion->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'outcome_version', $versionid);
        }
        $beforeitem = self::get_required(self::ITEM_TABLE, (int) $beforeversion->itemid, 'outcome');
        $afteritem = clone $beforeitem;
        if ($beforeitem->status === workflow::DRAFT && array_key_exists('code', $data)) {
            $afteritem->code = input::required_text($data['code'], 'code', 100);
            if ($DB->record_exists_select(self::ITEM_TABLE,
                    'frameworkid = :frameworkid AND code = :code AND id <> :id', [
                        'frameworkid' => $afteritem->frameworkid,
                        'code' => $afteritem->code,
                        'id' => $afteritem->id,
                    ])) {
                throw new validation_exception('duplicatecode', 'code', $afteritem->code);
            }
        }
        $afterversion = clone $beforeversion;
        $afterversion->statement = self::required_statement($data['statement'] ?? $beforeversion->statement);
        $afterversion->shortstatement = input::optional_text(
            $data['shortstatement'] ?? $beforeversion->shortstatement, 'shortstatement', 255);
        $afterversion->bloomlevel = input::optional_text(
            $data['bloomlevel'] ?? $beforeversion->bloomlevel, 'bloomlevel', 50);
        $afterversion->effectivefrom = input::positive_int(
            $data['effectivefrom'] ?? $beforeversion->effectivefrom, 'effectivefrom');
        $afterversion->effectiveto = input::optional_timestamp(
            array_key_exists('effectiveto', $data) ? $data['effectiveto'] : $beforeversion->effectiveto,
            'effectiveto');
        $afterversion->changereason = input::optional_multiline(
            $data['changereason'] ?? $beforeversion->changereason);
        $afterversion->timemodified = time();
        $afteritem->timemodified = $afterversion->timemodified;
        effective_dates::validate((int) $afterversion->effectivefrom,
            $afterversion->effectiveto === null ? null : (int) $afterversion->effectiveto);
        $transaction = $DB->start_delegated_transaction();
        try {
            if ($afteritem != $beforeitem) {
                $DB->update_record(self::ITEM_TABLE, $afteritem);
            }
            $DB->update_record(self::VERSION_TABLE, $afterversion);
            audit_writer::write('update', 'outcome_version', $versionid, $afterversion->uuid,
                $beforeversion, $afterversion, $data['changereason'] ?? null,
                \context_system::instance(), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Create the next draft version of an existing stable outcome. */
    public static function create_version(int $itemid, array $data): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageframeworks');
        $item = self::get_required(self::ITEM_TABLE, $itemid, 'outcome');
        if ($item->status !== workflow::APPROVED) {
            throw new validation_exception('invalidtransition', 'status', $item->status . ':new_version');
        }
        $from = input::positive_int($data['effectivefrom'] ?? 0, 'effectivefrom');
        $to = input::optional_timestamp($data['effectiveto'] ?? null, 'effectiveto');
        effective_dates::validate($from, $to);
        $maxversion = (int) $DB->get_field_sql(
            'SELECT MAX(version) FROM {local_outcomemap_itemver} WHERE itemid = :itemid', ['itemid' => $itemid]);
        $now = time();
        $record = (object) [
            'uuid' => uuid::normalize_or_generate($data['versionuuid'] ?? null),
            'itemid' => $itemid,
            'version' => $maxversion + 1,
            'statement' => self::required_statement($data['statement'] ?? ''),
            'shortstatement' => input::optional_text($data['shortstatement'] ?? null, 'shortstatement', 255),
            'bloomlevel' => input::optional_text($data['bloomlevel'] ?? null, 'bloomlevel', 50),
            'status' => workflow::DRAFT,
            'effectivefrom' => $from,
            'effectiveto' => $to,
            'changereason' => input::optional_multiline($data['changereason'] ?? null),
            'createdby' => $actorid,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
        ];
        if ($DB->record_exists(self::VERSION_TABLE, ['uuid' => $record->uuid])) {
            throw new validation_exception('duplicateuuid', 'versionuuid', $record->uuid);
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record(self::VERSION_TABLE, $record);
            $record->id = $id;
            audit_writer::write('create_version', 'outcome_version', $id, $record->uuid, null, $record,
                $record->changereason, \context_system::instance(), $actorid);
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Submit an outcome version for independent review. */
    public static function submit_for_review(int $versionid, ?string $reason = null): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageframeworks');
        $beforeversion = self::get_required(self::VERSION_TABLE, $versionid, 'outcome_version');
        if ($beforeversion->status !== workflow::DRAFT) {
            throw new validation_exception('invalidtransition', 'status', $beforeversion->status . ':needs_review');
        }
        $item = self::get_required(self::ITEM_TABLE, (int) $beforeversion->itemid, 'outcome');
        $afterversion = clone $beforeversion;
        $afterversion->status = workflow::NEEDS_REVIEW;
        $afterversion->timemodified = time();
        $afteritem = clone $item;
        if ($item->status === workflow::DRAFT) {
            $afteritem->status = workflow::NEEDS_REVIEW;
            $afteritem->timemodified = $afterversion->timemodified;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::VERSION_TABLE, $afterversion);
            if ($afteritem != $item) {
                $DB->update_record(self::ITEM_TABLE, $afteritem);
            }
            audit_writer::write('submit_review', 'outcome_version', $versionid, $afterversion->uuid,
                $beforeversion, $afterversion, $reason, \context_system::instance(), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Approve an outcome version after rechecking dates and framework state. */
    public static function approve(int $versionid, ?string $reason = null): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:approve');
        $beforeversion = self::get_required(self::VERSION_TABLE, $versionid, 'outcome_version');
        if ($beforeversion->status !== workflow::NEEDS_REVIEW) {
            throw new validation_exception('invalidtransition', 'status', $beforeversion->status . ':approved');
        }
        if ((int) $beforeversion->createdby === $actorid) {
            throw new validation_exception('creatorcannotapprove', 'createdby', $actorid);
        }
        $item = self::get_required(self::ITEM_TABLE, (int) $beforeversion->itemid, 'outcome');
        $framework = self::get_required('local_outcomemap_fw', (int) $item->frameworkid, 'framework');
        if ($framework->status !== workflow::APPROVED) {
            throw new validation_exception('invalidfield', 'frameworkid', 'framework must be approved');
        }
        self::require_no_approved_overlap($beforeversion);
        $afterversion = clone $beforeversion;
        $afterversion->status = workflow::APPROVED;
        $afterversion->approvedby = $actorid;
        $afterversion->approvedat = time();
        $afterversion->timemodified = $afterversion->approvedat;
        $afteritem = clone $item;
        if ($item->status !== workflow::APPROVED) {
            $afteritem->status = workflow::APPROVED;
            $afteritem->timemodified = $afterversion->approvedat;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            self::require_no_approved_overlap($beforeversion);
            $DB->update_record(self::VERSION_TABLE, $afterversion);
            if ($afteritem != $item) {
                $DB->update_record(self::ITEM_TABLE, $afteritem);
            }
            audit_writer::write('approve', 'outcome_version', $versionid, $afterversion->uuid,
                $beforeversion, $afterversion, $reason, \context_system::instance(), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Return outcomes and all versions for administration. */
    public static function list_all(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        $sql = "SELECT v.id, v.uuid AS versionuuid, v.version, v.statement, v.shortstatement,
                       v.bloomlevel, v.status AS versionstatus, v.effectivefrom, v.effectiveto,
                       i.id AS itemid, i.uuid AS itemuuid, i.code, i.status AS itemstatus,
                       f.id AS frameworkid, f.code AS frameworkcode, f.name AS frameworkname
                  FROM {local_outcomemap_itemver} v
                  JOIN {local_outcomemap_item} i ON i.id = v.itemid
                  JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              ORDER BY f.code, i.code, v.version DESC";
        return $DB->get_records_sql($sql);
    }

    private static function required_statement($value): string {
        $value = clean_param((string) $value, PARAM_TEXT);
        if ($value === '') {
            throw new validation_exception('requiredfield', 'statement');
        }
        return $value;
    }

    private static function require_unique_uuid(string $itemuuid, string $versionuuid): void {
        global $DB;
        if ($DB->record_exists(self::ITEM_TABLE, ['uuid' => $itemuuid])
                || $DB->record_exists(self::VERSION_TABLE, ['uuid' => $versionuuid])) {
            throw new validation_exception('duplicateuuid', 'uuid');
        }
    }

    private static function require_no_approved_overlap(\stdClass $candidate): void {
        global $DB;
        [$overlapsql, $params] = effective_dates::overlap_sql('', (int) $candidate->effectivefrom,
            $candidate->effectiveto === null ? null : (int) $candidate->effectiveto, 'iv');
        $params += [
            'itemid' => $candidate->itemid,
            'status' => workflow::APPROVED,
            'id' => $candidate->id,
        ];
        $select = 'itemid = :itemid AND status = :status AND id <> :id AND ' . $overlapsql;
        if ($DB->record_exists_select(self::VERSION_TABLE, $select, $params)) {
            throw new validation_exception('effectiverangeoverlap', 'effectivefrom');
        }
    }
}
