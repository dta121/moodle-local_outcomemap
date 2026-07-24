<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\decimal;
use local_outcomemap\local\effective_dates;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/** Transactional service for versioned directed outcome relationships. */
final class relation_service extends base_service {
    private const TABLE = 'local_outcomemap_rel';
    public const IS_CHILD_OF = 'is_child_of';
    public const ALIGNS_TO = 'aligns_to';
    public const CONTRIBUTES_TO = 'contributes_to';
    public const REPLACED_BY = 'replaced_by';
    public const RELATED_TO = 'related_to';
    public const TYPES = [
        self::IS_CHILD_OF,
        self::ALIGNS_TO,
        self::CONTRIBUTES_TO,
        self::REPLACED_BY,
        self::RELATED_TO,
    ];
    private const ACYCLIC_TYPES = [self::IS_CHILD_OF, self::CONTRIBUTES_TO];

    /** Create a version-one draft relation. */
    public static function create(array $data): int {
        $record = self::build_record($data, uuid::normalize_or_generate($data['relationuuid'] ?? null), 1);
        return self::insert($record, 'create');
    }

    /** Create the next draft version of an approved relation identity. */
    public static function create_version(int $relationid, array $data): int {
        global $DB;
        self::require_system('local/outcomemap:manageframeworks');
        $previous = self::get_required(self::TABLE, $relationid, 'relation');
        if ($previous->status !== workflow::APPROVED) {
            throw new validation_exception('invalidtransition', 'status', $previous->status . ':new_version');
        }
        $maxversion = (int) $DB->get_field_sql(
            'SELECT MAX(version) FROM {local_outcomemap_rel} WHERE relationuuid = :uuid',
            ['uuid' => $previous->relationuuid]);
        $data['sourceitemid'] = $previous->sourceitemid;
        $data['targetitemid'] = $previous->targetitemid;
        $data['type'] = $previous->type;
        $record = self::build_record($data, $previous->relationuuid, $maxversion + 1);
        return self::insert($record, 'create_version');
    }

    /** Update a draft relation version. */
    public static function update_draft(int $id, array $data): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageframeworks');
        $before = self::get_required(self::TABLE, $id, 'relation');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'relation', $id);
        }
        $merged = array_merge((array) $before, $data);
        $after = self::build_record($merged, $before->relationuuid, (int) $before->version);
        $after->id = $id;
        $after->createdby = $before->createdby;
        $after->timecreated = $before->timecreated;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write('update', 'relation', $id, $after->relationuuid, $before, $after,
                $data['reason'] ?? null, \context_system::instance(), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Submit a relation version for review. */
    public static function submit_for_review(int $id, ?string $reason = null): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageframeworks');
        $before = self::get_required(self::TABLE, $id, 'relation');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':needs_review');
        }
        $after = clone $before;
        $after->status = workflow::NEEDS_REVIEW;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write('submit_review', 'relation', $id, $after->relationuuid, $before, $after,
                $reason, \context_system::instance(), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Approve a relation after effective-range and graph-cycle validation. */
    public static function approve(int $id, ?string $reason = null): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:approve');
        $before = self::get_required(self::TABLE, $id, 'relation');
        if ($before->status !== workflow::NEEDS_REVIEW) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':approved');
        }
        if ((int) $before->createdby === $actorid) {
            throw new validation_exception('creatorcannotapprove', 'createdby', $actorid);
        }
        self::require_approved_outcomes($before);
        self::require_no_approved_overlap($before);
        self::require_acyclic($before);
        $after = clone $before;
        $after->status = workflow::APPROVED;
        $after->approvedby = $actorid;
        $after->approvedat = time();
        $after->timemodified = $after->approvedat;
        $transaction = $DB->start_delegated_transaction();
        try {
            self::require_no_approved_overlap($before);
            self::require_acyclic($before);
            $DB->update_record(self::TABLE, $after);
            audit_writer::write('approve', 'relation', $id, $after->relationuuid, $before, $after,
                $reason, \context_system::instance(), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Return all relationship versions with display codes. */
    public static function list_all(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        $sql = "SELECT r.*, source.code AS sourcecode, target.code AS targetcode,
                       sf.code AS sourceframework, tf.code AS targetframework
                  FROM {local_outcomemap_rel} r
                  JOIN {local_outcomemap_item} source ON source.id = r.sourceitemid
                  JOIN {local_outcomemap_item} target ON target.id = r.targetitemid
                  JOIN {local_outcomemap_fw} sf ON sf.id = source.frameworkid
                  JOIN {local_outcomemap_fw} tf ON tf.id = target.frameworkid
              ORDER BY sf.code, source.code, r.type, tf.code, target.code, r.version DESC";
        return $DB->get_records_sql($sql);
    }

    /**
     * Return all relation versions with endpoint framework and latest wording details.
     *
     * This read model is intentionally presentation-oriented and capability-aware.
     * It keeps relation pages and other consumers from reading governed tables
     * directly or issuing endpoint queries per row.
     */
    public static function list_detailed(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        $sql = "SELECT r.*,
                       source.code AS sourcecode, source.status AS sourceitemstatus,
                       target.code AS targetcode, target.status AS targetitemstatus,
                       sf.id AS sourceframeworkid, sf.code AS sourceframework,
                       sf.name AS sourceframeworkname, sf.ownertype AS sourceownertype,
                       sf.ownerid AS sourceownerid,
                       tf.id AS targetframeworkid, tf.code AS targetframework,
                       tf.name AS targetframeworkname, tf.ownertype AS targetownertype,
                       tf.ownerid AS targetownerid,
                       sv.id AS sourceversionid, sv.version AS sourceversion,
                       sv.statement AS sourcestatement, sv.status AS sourceversionstatus,
                       tv.id AS targetversionid, tv.version AS targetversion,
                       tv.statement AS targetstatement, tv.status AS targetversionstatus
                  FROM {local_outcomemap_rel} r
                  JOIN {local_outcomemap_item} source ON source.id = r.sourceitemid
                  JOIN {local_outcomemap_item} target ON target.id = r.targetitemid
                  JOIN {local_outcomemap_fw} sf ON sf.id = source.frameworkid
                  JOIN {local_outcomemap_fw} tf ON tf.id = target.frameworkid
                  JOIN {local_outcomemap_itemver} sv
                    ON sv.itemid = source.id
                   AND sv.version = (SELECT MAX(sv2.version)
                                       FROM {local_outcomemap_itemver} sv2
                                      WHERE sv2.itemid = source.id)
                  JOIN {local_outcomemap_itemver} tv
                    ON tv.itemid = target.id
                   AND tv.version = (SELECT MAX(tv2.version)
                                       FROM {local_outcomemap_itemver} tv2
                                      WHERE tv2.itemid = target.id)
              ORDER BY sf.code, source.code, r.type, tf.code, target.code, r.version DESC";
        return $DB->get_records_sql($sql);
    }

    /** Validate whether a candidate edge would be acyclic. */
    public static function validate_acyclic(int $sourceitemid, int $targetitemid, string $type,
            int $effectivefrom, ?int $effectiveto, int $excludeid = 0): bool {
        $candidate = (object) [
            'id' => $excludeid,
            'sourceitemid' => $sourceitemid,
            'targetitemid' => $targetitemid,
            'type' => $type,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => $effectiveto,
        ];
        try {
            self::require_acyclic($candidate);
            return true;
        } catch (validation_exception $e) {
            if ($e->errorcode === 'cycle') {
                return false;
            }
            throw $e;
        }
    }

    private static function build_record(array $data, string $relationuuid, int $version): \stdClass {
        $sourceitemid = input::positive_int($data['sourceitemid'] ?? 0, 'sourceitemid');
        $targetitemid = input::positive_int($data['targetitemid'] ?? 0, 'targetitemid');
        if ($sourceitemid === $targetitemid) {
            throw new validation_exception('selfrelation', 'targetitemid');
        }
        self::get_required('local_outcomemap_item', $sourceitemid, 'source_outcome');
        self::get_required('local_outcomemap_item', $targetitemid, 'target_outcome');
        $type = input::required_text($data['type'] ?? '', 'type', 30);
        if (!in_array($type, self::TYPES, true)) {
            throw new validation_exception('invalidrelationtype', 'type', $type);
        }
        $weightinput = $data['weight'] ?? null;
        if ($type === self::CONTRIBUTES_TO) {
            if ($weightinput === null || trim((string) $weightinput) === '') {
                throw new validation_exception('weightrequired', 'weight');
            }
            $weight = decimal::positive($weightinput);
        } else {
            if ($weightinput !== null && trim((string) $weightinput) !== '') {
                throw new validation_exception('weightnotallowed', 'weight');
            }
            $weight = null;
        }
        $from = input::positive_int($data['effectivefrom'] ?? 0, 'effectivefrom');
        $to = input::optional_timestamp($data['effectiveto'] ?? null, 'effectiveto');
        effective_dates::validate($from, $to);
        $now = time();
        return (object) [
            'relationuuid' => uuid::normalize($relationuuid),
            'version' => $version,
            'sourceitemid' => $sourceitemid,
            'targetitemid' => $targetitemid,
            'type' => $type,
            'weight' => $weight,
            'status' => workflow::DRAFT,
            'effectivefrom' => $from,
            'effectiveto' => $to,
            'notes' => input::optional_multiline($data['notes'] ?? null),
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
        ];
    }

    private static function insert(\stdClass $record, string $action): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageframeworks');
        $record->createdby = $actorid;
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record(self::TABLE, $record);
            $record->id = $id;
            audit_writer::write($action, 'relation', $id, $record->relationuuid, null, $record,
                $record->notes, \context_system::instance(), $actorid);
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    private static function require_approved_outcomes(\stdClass $candidate): void {
        $source = self::get_required('local_outcomemap_item', (int) $candidate->sourceitemid, 'source_outcome');
        $target = self::get_required('local_outcomemap_item', (int) $candidate->targetitemid, 'target_outcome');
        if ($source->status !== workflow::APPROVED || $target->status !== workflow::APPROVED) {
            throw new validation_exception('invalidfield', 'outcome', 'both outcomes must be approved');
        }
    }

    private static function require_no_approved_overlap(\stdClass $candidate): void {
        global $DB;
        [$overlapsql, $params] = effective_dates::overlap_sql('', (int) $candidate->effectivefrom,
            $candidate->effectiveto === null ? null : (int) $candidate->effectiveto, 'rel');
        $params += [
            'uuid' => $candidate->relationuuid,
            'status' => workflow::APPROVED,
            'id' => $candidate->id,
        ];
        $select = 'relationuuid = :uuid AND status = :status AND id <> :id AND ' . $overlapsql;
        if ($DB->record_exists_select(self::TABLE, $select, $params)) {
            throw new validation_exception('effectiverangeoverlap', 'effectivefrom');
        }
    }

    private static function require_acyclic(\stdClass $candidate): void {
        global $DB;
        if (!in_array($candidate->type, self::ACYCLIC_TYPES, true)) {
            return;
        }
        [$insql, $typeparams] = $DB->get_in_or_equal(self::ACYCLIC_TYPES, SQL_PARAMS_NAMED, 'rtype');
        [$overlapsql, $dateparams] = effective_dates::overlap_sql('', (int) $candidate->effectivefrom,
            $candidate->effectiveto === null ? null : (int) $candidate->effectiveto, 'graph');
        $select = 'status = :status AND type ' . $insql . ' AND id <> :excludeid AND ' . $overlapsql;
        $params = ['status' => workflow::APPROVED, 'excludeid' => (int) ($candidate->id ?? 0)]
            + $typeparams + $dateparams;
        $edges = $DB->get_records_select(self::TABLE, $select, $params, '', 'id,sourceitemid,targetitemid');
        $adjacency = [];
        foreach ($edges as $edge) {
            $adjacency[(int) $edge->sourceitemid][] = (int) $edge->targetitemid;
        }
        $start = (int) $candidate->targetitemid;
        $destination = (int) $candidate->sourceitemid;
        $stack = [$start];
        $visited = [];
        while ($stack) {
            $node = array_pop($stack);
            if ($node === $destination) {
                throw new validation_exception('cycle', 'relation');
            }
            if (isset($visited[$node])) {
                continue;
            }
            $visited[$node] = true;
            foreach ($adjacency[$node] ?? [] as $next) {
                $stack[] = $next;
            }
        }
    }
}
