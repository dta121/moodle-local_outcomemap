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
 * Question-version mapping service.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\api\context_resolver;
use local_outcomemap\api\outcome_search;
use local_outcomemap\local\audit_writer;
use local_outcomemap\local\decimal;
use local_outcomemap\local\effective_dates;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Governed mappings from exact question versions to exact outcome versions.
 *
 * Weight rules follow the specification: an `assesses` mapping requires an
 * explicit positive weight and non-assessment roles carry no evidence weight.
 * Because assessed weights are only meaningful as a set, approving one
 * `assesses` mapping approves every pending `assesses` mapping of the same
 * question version in one transaction, and approval is blocked unless the
 * resulting approved weights total exactly 1.0000000000 throughout every
 * affected effective segment. No default weight is ever inferred.
 */
final class question_mapping_service extends base_service {
    /**
     * Mapping table name.
     */
    private const TABLE = 'local_outcomemap_qmap';

    /**
     * Supported mapping roles.
     */
    public const ROLES = content_mapping_service::ROLES;

    /**
     * Create a version-one draft question-version mapping.
     *
     * @param array $data Mapping data including questionversionid, itemverid, and role.
     * @return int The new mapping record ID.
     */
    public static function create(array $data): int {
        $record = self::build_record($data, uuid::normalize_or_generate($data['mappinguuid'] ?? null), 1);
        $id = self::insert($record, 'create', $data['reason'] ?? null);
        self::maybe_autosubmit($record, $id, $data['reason'] ?? null);
        return $id;
    }

    /**
     * Submit a freshly created mapping when the site opts out of manual submission.
     *
     * An assessed mapping cannot be submitted on its own unless the question
     * version's assessed weights already total exactly 1.0000000000, so a
     * multi-outcome set stays draft until its final member is created and the
     * total lands — at which point the existing set-approval carries the whole
     * group through together. Submission failures are therefore an expected
     * intermediate state, not an error, and leave the record a draft.
     *
     * Copies are excluded: a mapping carried onto a new question version is
     * deliberately a draft until someone confirms it still applies.
     *
     * @param \stdClass $record The inserted mapping record.
     * @param int $id The new mapping ID.
     * @param string|null $reason Optional audit reason.
     * @return void
     */
    private static function maybe_autosubmit(\stdClass $record, int $id, ?string $reason): void {
        if (!workflow::autosubmits_question_mappings() || $record->sourceqmapid !== null) {
            return;
        }
        try {
            self::submit_for_review($id, $reason);
        } catch (validation_exception $e) {
            // The set is not complete yet; the mapping stays a draft.
            debugging('local_outcomemap: question mapping ' . $id . ' left as a draft by autosubmit: '
                . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Update a draft mapping.
     *
     * @param int $id Mapping record ID.
     * @param array $data Updated mapping data.
     * @return void
     */
    public static function update_draft(int $id, array $data): void {
        global $DB;
        $initial = self::get_required(self::TABLE, $id, 'question_mapping');
        self::require_mutation_capabilities((int) $initial->questionversionid, (int) $initial->questionid);
        $locks = self::acquire_bulk_locks([(int) $initial->questionid]);
        try {
            $before = self::get_required(self::TABLE, $id, 'question_mapping');
            if ($before->status !== workflow::DRAFT) {
                throw new validation_exception('approvedimmutable', 'question_mapping', $id);
            }
            $data['questionversionid'] = $before->questionversionid;
            $after = self::build_record(
                array_merge((array) $before, $data),
                $before->mappinguuid,
                (int) $before->version
            );
            $actorid = self::require_mutation_capabilities(
                (int) $after->questionversionid,
                (int) $after->questionid
            );
            $after->id = $id;
            $after->createdby = $before->createdby;
            $after->timecreated = $before->timecreated;
            $after->timemodified = time();
            self::require_no_existing_copied_scope($after);
            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->update_record(self::TABLE, $after);
                audit_writer::write(
                    'update',
                    'question_mapping',
                    $id,
                    $after->mappinguuid,
                    $before,
                    $after,
                    $data['reason'] ?? null,
                    context_resolver::for_question_version((int) $after->questionversionid),
                    $actorid
                );
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                self::rollback($transaction, $e);
            }
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * Delete a draft mapping.
     *
     * @param int $id Mapping record ID.
     * @param string|null $reason Optional deletion reason.
     * @return void
     */
    public static function delete_draft(int $id, ?string $reason = null): void {
        global $DB;
        $before = self::get_required(self::TABLE, $id, 'question_mapping');
        $actorid = self::require_mutation_capabilities((int) $before->questionversionid, (int) $before->questionid);
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'question_mapping', $id);
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records(self::TABLE, ['id' => $id]);
            audit_writer::write(
                'delete',
                'question_mapping',
                $id,
                $before->mappinguuid,
                $before,
                null,
                $reason,
                context_resolver::for_question_version((int) $before->questionversionid),
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Correct when an approved mapping took effect.
     *
     * A mapping authored for an existing course describes what its assessments
     * have measured all along, but effectivefrom defaults to the moment of
     * authoring, so evidence is attributed to nothing and the course reports no
     * results. A new version cannot express this: versions of one mapping may
     * not overlap, and the correction has to reach back over the period the
     * current version already covers.
     *
     * This is a correction, not a re-decision, so the mapping keeps its UUID
     * and version and the audit event records both the previous start and the
     * stated reason. A reason is mandatory: moving the start changes which
     * attempts the mapping is held to have governed.
     *
     * Corrections apply as one set. Moving the rows of a multi-outcome assessed
     * set one at a time would leave the corrected start covered by only part of
     * the set, whose weights total less than one, so each row would be rejected
     * on the way to a state that is perfectly valid once complete.
     *
     * @param int[] $ids Approved mapping record IDs to correct together.
     * @param int $effectivefrom Corrected effective start.
     * @param string $reason Why the recorded start was wrong.
     * @return int Number of mappings corrected.
     */
    public static function correct_effectivefrom(array $ids, int $effectivefrom, string $reason): int {
        global $DB;
        $reason = trim($reason);
        if ($reason === '') {
            throw new validation_exception('requiredfield', 'reason');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return 0;
        }
        $records = [];
        $questionids = [];
        foreach ($ids as $id) {
            $record = self::get_required(self::TABLE, $id, 'question_mapping');
            if ($record->status !== workflow::APPROVED) {
                throw new validation_exception('invalidtransition', 'status', $record->status . ':correct');
            }
            $records[$id] = $record;
            $questionids[(int) $record->questionid] = (int) $record->questionid;
        }
        $locks = self::acquire_bulk_locks(array_values($questionids));
        try {
            $corrected = [];
            $questionversionids = [];
            foreach ($records as $id => $before) {
                $after = clone $before;
                $after->effectivefrom = $effectivefrom;
                $after->timemodified = time();
                effective_dates::validate(
                    (int) $after->effectivefrom,
                    $after->effectiveto === null ? null : (int) $after->effectiveto
                );
                self::require_mutation_capabilities(
                    (int) $after->questionversionid,
                    (int) $after->questionid
                );
                $corrected[$id] = $after;
                if ($after->role === content_mapping_service::ROLE_ASSESSES) {
                    $questionversionids[(int) $after->questionversionid] = (int) $after->questionversionid;
                }
            }
            global $USER;
            $actorid = (int) $USER->id;
            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($corrected as $id => $after) {
                    self::require_no_approved_overlap($after);
                    $DB->update_record(self::TABLE, $after);
                    audit_writer::write(
                        'correct_effectivefrom',
                        'question_mapping',
                        $id,
                        $after->mappinguuid,
                        $records[$id],
                        $after,
                        $reason,
                        context_resolver::for_question_version((int) $after->questionversionid),
                        $actorid
                    );
                }
                // Validated only once the whole set has moved, at which point
                // the corrected start is a boundary the weights must total one at.
                foreach ($questionversionids as $questionversionid) {
                    $batch = $DB->get_records(self::TABLE, [
                        'questionversionid' => $questionversionid,
                        'role' => content_mapping_service::ROLE_ASSESSES,
                        'status' => workflow::APPROVED,
                    ]);
                    self::require_valid_assessed_total($questionversionid, array_values($batch));
                    // Results calculated without these mappings in force are wrong.
                    calculation_service::mark_stale_for_question_version($questionversionid);
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                self::rollback($transaction, $e);
            }
            return count($corrected);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * Create the next draft version of an approved mapping.
     *
     * @param int $id Approved mapping record ID.
     * @param array $data New version data.
     * @return int The new mapping record ID.
     */
    public static function create_version(int $id, array $data): int {
        global $DB;
        $previous = self::get_required(self::TABLE, $id, 'question_mapping');
        self::require_mutation_capabilities((int) $previous->questionversionid, (int) $previous->questionid);
        if ($previous->status !== workflow::APPROVED) {
            throw new validation_exception('invalidtransition', 'status', $previous->status . ':new_version');
        }
        $data['questionversionid'] = $previous->questionversionid;
        $data['itemverid'] = $previous->itemverid;
        $maxversion = (int) $DB->get_field_sql(
            'SELECT MAX(version) FROM {local_outcomemap_qmap} WHERE mappinguuid = :mappinguuid',
            ['mappinguuid' => $previous->mappinguuid]
        );
        $record = self::build_record(
            array_merge((array) $previous, $data),
            $previous->mappinguuid,
            $maxversion + 1
        );
        return self::insert($record, 'create_version', $data['reason'] ?? null);
    }

    /**
     * Submit a draft mapping for review.
     *
     * @param int $id Mapping record ID.
     * @param string|null $reason Optional submission reason.
     * @return void
     */
    public static function submit_for_review(int $id, ?string $reason = null): void {
        global $DB;
        $before = self::get_required(self::TABLE, $id, 'question_mapping');
        $actorid = self::require_mutation_capabilities((int) $before->questionversionid, (int) $before->questionid);
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':needs_review');
        }
        $batch = [$before];
        if (
            !workflow::requires_independent_approval()
                && $before->role === content_mapping_service::ROLE_ASSESSES
        ) {
            $batch = array_values($DB->get_records(self::TABLE, [
                'questionversionid' => $before->questionversionid,
                'role' => content_mapping_service::ROLE_ASSESSES,
                'status' => workflow::DRAFT,
            ], 'id ASC'));
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $context = context_resolver::for_question_version((int) $before->questionversionid);
            foreach ($batch as $record) {
                self::validate_record($record);
                $after = clone $record;
                $after->status = workflow::NEEDS_REVIEW;
                $after->timemodified = time();
                $DB->update_record(self::TABLE, $after);
                audit_writer::write(
                    'submit_review',
                    'question_mapping',
                    (int) $record->id,
                    $after->mappinguuid,
                    $record,
                    $after,
                    $reason,
                    $context,
                    $actorid
                );
            }
            if (!workflow::requires_independent_approval()) {
                $batchids = array_map(static fn(\stdClass $record): int => (int) $record->id, $batch);
                self::approve_batch($id, $reason, $batchids);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Approve a mapping after rechecking references, overlaps, and assessed weights.
     *
     * Approving an `assesses` mapping approves the full pending assessed set of
     * the question version together so weight totals stay valid as one unit.
     *
     * @param int $id Mapping record ID.
     * @param string|null $reason Optional approval reason.
     * @return void
     */
    public static function approve(int $id, ?string $reason = null): void {
        self::approve_batch($id, $reason);
    }

    /**
     * Approve a pending mapping batch.
     *
     * The optional restricted IDs are used only by disabled-mode automatic
     * finalization so pre-existing pending assessed mappings are not absorbed
     * into a newly submitted batch. Explicit approval retains the established
     * behavior of approving the full pending assessed set.
     *
     * @param int $id Mapping record ID.
     * @param string|null $reason Optional approval reason.
     * @param int[]|null $restrictedids Exact records submitted by the current operation.
     * @return void
     */
    private static function approve_batch(int $id, ?string $reason = null, ?array $restrictedids = null): void {
        global $DB, $USER;
        $candidate = self::get_required(self::TABLE, $id, 'question_mapping');
        if ($candidate->status !== workflow::NEEDS_REVIEW) {
            throw new validation_exception('invalidtransition', 'status', $candidate->status . ':approved');
        }
        $context = context_resolver::for_question_version((int) $candidate->questionversionid);
        if (workflow::requires_independent_approval()) {
            require_capability('local/outcomemap:approve', $context);
            self::require_question_capability((int) $candidate->questionid, 'view');
            $actorid = (int) $USER->id;
        } else {
            $actorid = self::require_mutation_capabilities(
                (int) $candidate->questionversionid,
                (int) $candidate->questionid
            );
        }
        $batch = [$candidate];
        if ($candidate->role === content_mapping_service::ROLE_ASSESSES) {
            if ($restrictedids === null) {
                $pending = $DB->get_records(self::TABLE, [
                    'questionversionid' => $candidate->questionversionid,
                    'role' => content_mapping_service::ROLE_ASSESSES,
                    'status' => workflow::NEEDS_REVIEW,
                ], 'id ASC');
                $batch = array_values($pending);
            } else {
                $batch = [];
                foreach ($restrictedids as $restrictedid) {
                    $record = self::get_required(self::TABLE, (int) $restrictedid, 'question_mapping');
                    if (
                        $record->status !== workflow::NEEDS_REVIEW
                            || (int) $record->questionversionid !== (int) $candidate->questionversionid
                            || $record->role !== content_mapping_service::ROLE_ASSESSES
                    ) {
                        throw new validation_exception(
                            'invalidtransition',
                            'status',
                            $record->status . ':approved_batch'
                        );
                    }
                    $batch[] = $record;
                }
            }
        }
        foreach ($batch as $record) {
            workflow::require_approver_separation((int) $record->createdby, $actorid);
            self::validate_record($record);
            self::require_no_approved_overlap($record);
            self::require_no_duplicate_scope($record);
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $now = time();
            foreach ($batch as $record) {
                self::validate_record($record);
                self::require_no_approved_overlap($record);
                self::require_no_duplicate_scope($record);
                $after = clone $record;
                $after->status = workflow::APPROVED;
                $after->approvedby = $actorid;
                $after->approvedat = $now;
                $after->timemodified = $now;
                $DB->update_record(self::TABLE, $after);
                audit_writer::write(
                    'approve',
                    'question_mapping',
                    (int) $record->id,
                    $record->mappinguuid,
                    $record,
                    $after,
                    $reason,
                    $context,
                    $actorid
                );
            }
            if ($candidate->role === content_mapping_service::ROLE_ASSESSES) {
                self::require_valid_assessed_total((int) $candidate->questionversionid, $batch);
                // Existing nonfrozen results built from this question version
                // are now stale; reconciliation recalculates them.
                calculation_service::mark_stale_for_question_version((int) $candidate->questionversionid);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Report the assessed-weight state of a question version.
     *
     * @param int $questionversionid Question-version ID.
     * @param int|null $effectiveat Effective timestamp; defaults to now.
     * @return \stdClass Approved and pending totals with a validity flag.
     */
    public static function validate_assessed_weights(int $questionversionid, ?int $effectiveat = null): \stdClass {
        global $DB;
        $questionversion = self::require_question_version($questionversionid);
        $context = context_resolver::for_question_version($questionversionid);
        require_capability('local/outcomemap:viewdefinitions', $context);
        self::require_question_capability((int) $questionversion->questionid, 'view');
        $effectiveat = $effectiveat ?? time();
        $records = $DB->get_records_select(
            self::TABLE,
            'questionversionid = :questionversionid AND role = :role
                AND effectivefrom <= :at1 AND (effectiveto IS NULL OR effectiveto > :at2)',
            [
                'questionversionid' => $questionversion->id,
                'role' => content_mapping_service::ROLE_ASSESSES,
                'at1' => $effectiveat,
                'at2' => $effectiveat,
            ]
        );
        $approvedtotal = decimal::ZERO;
        $pendingtotal = decimal::ZERO;
        $missingweight = false;
        foreach ($records as $record) {
            if ($record->weight === null) {
                $missingweight = true;
                continue;
            }
            $weight = decimal::require_canonical($record->weight, 'weight');
            if ($record->status === workflow::APPROVED) {
                $approvedtotal = decimal::add($approvedtotal, $weight);
            } else if ($record->status === workflow::DRAFT || $record->status === workflow::NEEDS_REVIEW) {
                $pendingtotal = decimal::add($pendingtotal, $weight);
            }
        }
        return (object) [
            'questionversionid' => (int) $questionversion->id,
            'effectiveat' => $effectiveat,
            'approvedtotal' => $approvedtotal,
            'combinedtotal' => decimal::add($approvedtotal, $pendingtotal),
            'missingweight' => $missingweight,
            'approvedvalid' => $approvedtotal === decimal::ONE || $approvedtotal === decimal::ZERO,
            'combinedvalid' => !$missingweight
                && decimal::add($approvedtotal, $pendingtotal) === decimal::ONE,
        ];
    }

    /**
     * Preview eligible approved mappings from the immediately preceding version.
     *
     * @param int $targetquestionversionid Target question-version ID.
     * @param int|null $sourcequestionversionid Optional explicit source version.
     * @return \stdClass Companion-safe eligibility and provenance summary.
     */
    public static function preview_copy_to_version(
        int $targetquestionversionid,
        ?int $sourcequestionversionid = null
    ): \stdClass {
        $prepared = self::prepare_copy_to_version($targetquestionversionid, $sourcequestionversionid);
        unset($prepared->_mappings, $prepared->actorid);
        return $prepared;
    }

    /**
     * Copy currently effective approved mappings from one question version to another as drafts.
     *
     * Copies receive new mapping UUIDs, version one, and draft status. The copy
     * is idempotent: outcome-version/role pairs that already have any mapping on
     * the target version are skipped.
     *
     * @param int $targetquestionversionid Target question-version ID.
     * @param int|null $sourcequestionversionid Source question-version ID; defaults
     *     to the immediately preceding version of the same question bank entry.
     * @param string|null $reason Optional copy reason.
     * @return int[] New draft mapping record IDs.
     */
    public static function copy_to_version(
        int $targetquestionversionid,
        ?int $sourcequestionversionid = null,
        ?string $reason = null
    ): array {
        global $DB;
        $target = self::require_question_version($targetquestionversionid);
        $locks = self::acquire_bulk_locks([(int) $target->questionid]);
        try {
            $prepared = self::prepare_copy_to_version($targetquestionversionid, $sourcequestionversionid);
            if (!$prepared->_mappings) {
                return [];
            }
            $newids = [];
            $transaction = $DB->start_delegated_transaction();
            try {
                $context = context_resolver::for_question_version($targetquestionversionid);
                foreach ($prepared->_mappings as $mapping) {
                    $draft = self::build_record([
                        'questionversionid' => $targetquestionversionid,
                        'sourceqmapid' => (int) $mapping->id,
                        'sourcequestionversionid' => (int) $prepared->sourcequestionversionid,
                        'itemverid' => (int) $mapping->itemverid,
                        'role' => $mapping->role,
                        'weight' => $mapping->weight,
                        'notes' => $mapping->notes,
                        'effectivefrom' => (int) $mapping->effectivefrom,
                        'effectiveto' => $mapping->effectiveto === null ? null : (int) $mapping->effectiveto,
                    ], uuid::generate(), 1);
                    $draft->createdby = (int) $prepared->actorid;
                    $id = $DB->insert_record(self::TABLE, $draft);
                    $draft->id = $id;
                    audit_writer::write(
                        'copy_version',
                        'question_mapping',
                        $id,
                        $draft->mappinguuid,
                        $mapping,
                        $draft,
                        $reason ?? ('Copied from question version ' . $prepared->sourcequestionversionid
                            . ' mapping ' . $mapping->mappinguuid . ' v' . $mapping->version),
                        $context,
                        (int) $prepared->actorid
                    );
                    $newids[] = $id;
                }
                $transaction->allow_commit();
                return $newids;
            } catch (\Throwable $e) {
                self::rollback($transaction, $e);
            }
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * Resolve and validate the exact source and eligible copy set.
     *
     * @param int $targetquestionversionid Targetquestionversionid.
     * @param ?int $sourcequestionversionid Sourcequestionversionid.
     */
    private static function prepare_copy_to_version(
        int $targetquestionversionid,
        ?int $sourcequestionversionid
    ): \stdClass {
        global $DB;
        $target = self::require_question_version($targetquestionversionid);
        $actorid = self::require_mutation_capabilities((int) $target->id, (int) $target->questionid);
        $sources = $DB->get_records_select(
            'question_versions',
            'questionbankentryid = :entryid AND version < :version',
            ['entryid' => $target->questionbankentryid, 'version' => $target->version],
            'version DESC',
            '*',
            0,
            1
        );
        if (!$sources) {
            if ($sourcequestionversionid !== null) {
                throw new validation_exception(
                    'questionversionmismatch',
                    'sourcequestionversionid',
                    $sourcequestionversionid
                );
            }
            return (object) [
                'targetquestionversionid' => (int) $target->id,
                'targetversion' => (int) $target->version,
                'sourcequestionversionid' => null,
                'sourceversion' => null,
                'eligiblecount' => 0,
                'duplicatecount' => 0,
                'invalidcount' => 0,
                'mappings' => [],
                'actorid' => $actorid,
                '_mappings' => [],
            ];
        }
        $source = reset($sources);
        if (
            $sourcequestionversionid !== null
                && (int) $sourcequestionversionid !== (int) $source->id
        ) {
            throw new validation_exception(
                'questionversionmismatch',
                'sourcequestionversionid',
                $sourcequestionversionid
            );
        }
        $sourcecontext = context_resolver::for_question_version((int) $source->id);
        require_capability('local/outcomemap:viewdefinitions', $sourcecontext);
        self::require_question_capability((int) $source->questionid, 'view');

        $now = time();
        $mappings = $DB->get_records_sql(
            "SELECT m.*, i.code AS outcomecode, v.version AS outcomeversion,
                    v.status AS outcomeversionstatus, v.effectivefrom AS outcomeeffectivefrom,
                    v.effectiveto AS outcomeeffectiveto, f.code AS frameworkcode
               FROM {local_outcomemap_qmap} m
               JOIN {local_outcomemap_itemver} v ON v.id = m.itemverid
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE m.questionversionid = :questionversionid AND m.status = :status
                AND m.effectivefrom <= :at1 AND (m.effectiveto IS NULL OR m.effectiveto > :at2)
           ORDER BY m.id",
            [
                'questionversionid' => $source->id,
                'status' => workflow::APPROVED,
                'at1' => $now,
                'at2' => $now,
            ]
        );
        $existing = $DB->get_records(self::TABLE, ['questionversionid' => $target->id], '', 'id,itemverid,role');
        $covered = [];
        foreach ($existing as $record) {
            $covered[$record->itemverid . ':' . $record->role] = true;
        }

        $eligible = [];
        $summaries = [];
        $duplicatecount = 0;
        $invalidcount = 0;
        foreach ($mappings as $mapping) {
            if (isset($covered[$mapping->itemverid . ':' . $mapping->role])) {
                $duplicatecount++;
                continue;
            }
            try {
                self::bulk_validate_loaded_record($mapping);
            } catch (validation_exception $e) {
                $invalidcount++;
                continue;
            }
            $eligible[] = $mapping;
            $summaries[] = (object) [
                'sourcemappingid' => (int) $mapping->id,
                'sourcemappinguuid' => $mapping->mappinguuid,
                'sourcemappingversion' => (int) $mapping->version,
                'outcome' => $mapping->frameworkcode . '.' . $mapping->outcomecode
                    . ' v' . $mapping->outcomeversion,
                'role' => $mapping->role,
                'weight' => $mapping->weight,
            ];
        }
        return (object) [
            'targetquestionversionid' => (int) $target->id,
            'targetversion' => (int) $target->version,
            'sourcequestionversionid' => (int) $source->id,
            'sourceversion' => (int) $source->version,
            'eligiblecount' => count($eligible),
            'duplicatecount' => $duplicatecount,
            'invalidcount' => $invalidcount,
            'mappings' => $summaries,
            'actorid' => $actorid,
            '_mappings' => $eligible,
        ];
    }

    /**
     * Load one mapping record.
     *
     * @param int $id Mapping record ID.
     * @return \stdClass The mapping record.
     */
    public static function get(int $id): \stdClass {
        $record = self::get_required(self::TABLE, $id, 'question_mapping');
        $context = context_resolver::for_question_version((int) $record->questionversionid);
        require_capability('local/outcomemap:viewdefinitions', $context);
        self::require_question_capability((int) $record->questionid, 'view');
        return $record;
    }

    /**
     * Bulk-load mappings with outcome display data for a page of question versions.
     *
     * Question versions the caller may not view are omitted rather than failing
     * the whole page.
     *
     * @param int[] $questionversionids Question-version IDs.
     * @return array Mapping records grouped by question-version ID.
     */
    public static function get_for_question_versions(array $questionversionids): array {
        global $DB;
        $questionversionids = array_values(array_unique(array_map('intval', $questionversionids)));
        if (!$questionversionids) {
            return [];
        }
        if (count($questionversionids) > 1000) {
            throw new validation_exception('invalidfield', 'questionversionids', 'maximum of 1000 IDs per call');
        }
        [$insql, $params] = $DB->get_in_or_equal($questionversionids, SQL_PARAMS_NAMED, 'qv');
        $questions = $DB->get_records_sql(
            "SELECT qv.id, qv.questionid, q.createdby, qc.contextid
               FROM {question_versions} qv
               JOIN {question} q ON q.id = qv.questionid
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qv.id $insql",
            $params
        );
        $visible = [];
        $checkedcontexts = [];
        foreach ($questions as $question) {
            $contextid = (int) $question->contextid;
            if (!array_key_exists($contextid, $checkedcontexts)) {
                $context = \context::instance_by_id($contextid, IGNORE_MISSING);
                $checkedcontexts[$contextid] = $context
                    && has_capability('local/outcomemap:viewdefinitions', $context);
            }
            if (!$checkedcontexts[$contextid]) {
                continue;
            }
            $proxy = (object) [
                'id' => (int) $question->questionid,
                'contextid' => (int) $question->contextid,
                'createdby' => (int) $question->createdby,
            ];
            if (question_has_capability_on($proxy, 'view')) {
                $visible[(int) $question->id] = true;
            }
        }
        if (!$visible) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($visible), SQL_PARAMS_NAMED, 'vqv');
        $records = $DB->get_records_sql(
            "SELECT m.*, i.uuid AS outcomeuuid, i.code AS outcomecode, v.uuid AS outcomeversionuuid,
                    v.version AS outcomeversion, v.statement AS outcomestatement,
                    v.shortstatement AS outcomeshortstatement,
                    f.uuid AS frameworkuuid, f.code AS frameworkcode,
                    sm.mappinguuid AS sourcemappinguuid, sm.version AS sourcemappingversion,
                    sqv.version AS sourcequestionversion
               FROM {local_outcomemap_qmap} m
               JOIN {local_outcomemap_itemver} v ON v.id = m.itemverid
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
          LEFT JOIN {local_outcomemap_qmap} sm ON sm.id = m.sourceqmapid
          LEFT JOIN {question_versions} sqv ON sqv.id = m.sourcequestionversionid
              WHERE m.questionversionid $insql
           ORDER BY m.questionversionid, f.code, i.code, m.version DESC, m.id",
            $params
        );
        $grouped = [];
        foreach ($records as $record) {
            $grouped[(int) $record->questionversionid][] = $record;
        }
        return $grouped;
    }

    /**
     * Preview a governed bulk mapping operation for selected core question IDs.
     *
     * Selection, capabilities, draft state, and assessed totals are validated
     * without changing data. The returned token is bound to both the request
     * and the current mapping state and must be supplied to {@see commit_bulk()}.
     *
     * @param int[] $questionids Selected core question IDs.
     * @param array $operation Normalized operation data.
     * @return \stdClass Structured preview.
     */
    public static function preview_bulk(array $questionids, array $operation): \stdClass {
        return self::prepare_bulk($questionids, $operation);
    }

    /**
     * Atomically commit a previously previewed bulk operation.
     *
     * @param int[] $questionids Selected core question IDs.
     * @param array $operation Normalized operation data.
     * @param string $previewtoken Token returned by {@see preview_bulk()}.
     * @return \stdClass Commit summary.
     */
    public static function commit_bulk(array $questionids, array $operation, string $previewtoken): \stdClass {
        global $DB;
        $locks = self::acquire_bulk_locks($questionids);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $prepared = self::prepare_bulk($questionids, $operation);
                if (!$prepared->valid) {
                    throw new validation_exception(
                        'bulkpreviewinvalid',
                        'operation',
                        implode('; ', self::bulk_error_messages($prepared))
                    );
                }
                if ($previewtoken === '' || !hash_equals($prepared->previewtoken, $previewtoken)) {
                    throw new validation_exception('bulkpreviewstale', 'previewtoken');
                }

                $affected = 0;
                $now = time();
                foreach ($prepared->_changes as $change) {
                    $context = \context::instance_by_id((int) $change->contextid);
                    if ($prepared->action === 'add') {
                        $after = clone $change->after;
                        $after->mappinguuid = uuid::generate();
                        $after->createdby = (int) $prepared->actorid;
                        $id = $DB->insert_record(self::TABLE, $after);
                        $after->id = $id;
                        audit_writer::write(
                            'create',
                            'question_mapping',
                            $id,
                            $after->mappinguuid,
                            null,
                            $after,
                            $prepared->reason ?? $after->notes,
                            $context,
                            (int) $prepared->actorid
                        );
                    } else if ($prepared->action === 'change_role') {
                        $after = clone $change->after;
                        $after->timemodified = $now;
                        $DB->update_record(self::TABLE, $after);
                        audit_writer::write(
                            'update',
                            'question_mapping',
                            (int) $after->id,
                            $after->mappinguuid,
                            $change->before,
                            $after,
                            $prepared->reason,
                            $context,
                            (int) $prepared->actorid
                        );
                    } else if ($prepared->action === 'delete_drafts') {
                        $before = $change->before;
                        $DB->delete_records(self::TABLE, ['id' => $before->id]);
                        audit_writer::write(
                            'delete',
                            'question_mapping',
                            (int) $before->id,
                            $before->mappinguuid,
                            $before,
                            null,
                            $prepared->reason,
                            $context,
                            (int) $prepared->actorid
                        );
                    } else if ($prepared->action === 'submit_drafts') {
                        $before = $change->before;
                        $submitted = clone $before;
                        $submitted->status = workflow::NEEDS_REVIEW;
                        $submitted->timemodified = $now;
                        $DB->update_record(self::TABLE, $submitted);
                        audit_writer::write(
                            'submit_review',
                            'question_mapping',
                            (int) $before->id,
                            $before->mappinguuid,
                            $before,
                            $submitted,
                            $prepared->reason,
                            $context,
                            (int) $prepared->actorid
                        );
                        if (!workflow::requires_independent_approval()) {
                            $approved = clone $submitted;
                            $approved->status = workflow::APPROVED;
                            $approved->approvedby = (int) $prepared->actorid;
                            $approved->approvedat = $now;
                            $DB->update_record(self::TABLE, $approved);
                            audit_writer::write(
                                'approve',
                                'question_mapping',
                                (int) $before->id,
                                $before->mappinguuid,
                                $submitted,
                                $approved,
                                $prepared->reason,
                                $context,
                                (int) $prepared->actorid
                            );
                        }
                    }
                    $affected++;
                }

                if ($prepared->action === 'submit_drafts' && !workflow::requires_independent_approval()) {
                    foreach ($prepared->_assessedquestionversions as $questionversionid) {
                        calculation_service::mark_stale_for_question_version((int) $questionversionid);
                    }
                }
                $transaction->allow_commit();
                return (object) [
                'operation' => $prepared->action,
                'questioncount' => count($prepared->questions),
                'affected' => $affected,
                ];
            } catch (\Throwable $e) {
                self::rollback($transaction, $e);
            }
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * Acquire deterministic per-question locks for preview revalidation and commit.
     *
     * @param int[] $questionids Selected question IDs.
     * @return \core\lock\lock[] Acquired locks.
     */
    private static function acquire_bulk_locks(array $questionids): array {
        $questionids = array_values(array_unique(array_filter(array_map('intval', $questionids))));
        sort($questionids);
        if (!$questionids || count($questionids) > 1000) {
            throw new validation_exception('invalidfield', 'questionids', 'between 1 and 1000 question IDs');
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_outcomemap');
        $locks = [];
        foreach ($questionids as $questionid) {
            $lock = $factory->get_lock('question_mapping_' . $questionid, 10);
            if (!$lock) {
                foreach (array_reverse($locks) as $heldlock) {
                    $heldlock->release();
                }
                throw new validation_exception('bulklocktimeout', 'questionids', $questionid);
            }
            $locks[] = $lock;
        }
        return $locks;
    }

    /**
     * Resolve, authorize, bulk-load, and validate a bulk operation.
     *
     * @param int[] $questionids Selected core question IDs.
     * @param array $operation Operation data.
     * @return \stdClass Internal prepared preview with public fields.
     */
    private static function prepare_bulk(array $questionids, array $operation): \stdClass {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/questionlib.php');

        $questionids = array_values(array_unique(array_filter(array_map('intval', $questionids))));
        sort($questionids);
        if (!$questionids || count($questionids) > 1000) {
            throw new validation_exception('invalidfield', 'questionids', 'between 1 and 1000 question IDs');
        }
        $action = input::required_text($operation['action'] ?? '', 'operation', 30);
        if (!in_array($action, ['inspect', 'add', 'change_role', 'delete_drafts', 'submit_drafts'], true)) {
            throw new validation_exception('invalidfield', 'operation', $action);
        }
        $role = null;
        if ($action === 'add' || $action === 'change_role') {
            $role = input::required_text($operation['role'] ?? '', 'role', 20);
            if (!in_array($role, self::ROLES, true)) {
                throw new validation_exception('invalidmappingrole', 'role', $role);
            }
        }
        $mappingids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($operation['mappingids'] ?? [])
        ))));
        sort($mappingids);
        $weights = (array) ($operation['weights'] ?? []);
        $effectivefrom = isset($operation['effectivefrom'])
            ? input::positive_int($operation['effectivefrom'], 'effectivefrom')
            : time();
        $notes = input::optional_multiline($operation['notes'] ?? null);
        $reason = input::optional_multiline($operation['reason'] ?? null);

        [$insql, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'bq');
        $selected = $DB->get_records_sql(
            "SELECT q.id AS questionid, q.name, q.createdby, qv.id AS questionversionid,
                    qv.version AS questionversion, qc.contextid
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE q.id $insql
           ORDER BY q.id",
            $params
        );
        if (count($selected) !== count($questionids)) {
            throw new validation_exception('recordnotfound', 'question', 'bulk selection');
        }

        $questions = [];
        $versionids = [];
        foreach ($selected as $question) {
            $context = \context::instance_by_id((int) $question->contextid, IGNORE_MISSING);
            if (!$context) {
                throw new validation_exception('recordnotfound', 'question_context', $question->contextid);
            }
            require_capability('local/outcomemap:mapquestions', $context);
            $proxy = (object) [
                'id' => (int) $question->questionid,
                'contextid' => (int) $question->contextid,
                'createdby' => (int) $question->createdby,
            ];
            if (!question_has_capability_on($proxy, 'edit')) {
                throw new \required_capability_exception(
                    $context,
                    'moodle/question:editall',
                    'nopermissions',
                    ''
                );
            }
            $question->questionid = (int) $question->questionid;
            $question->questionversionid = (int) $question->questionversionid;
            $question->questionversion = (int) $question->questionversion;
            $question->contextid = (int) $question->contextid;
            $question->drafts = [];
            $question->actions = [];
            $question->errors = [];
            $questions[$question->questionid] = $question;
            $versionids[] = $question->questionversionid;
        }

        [$versionsql, $versionparams] = $DB->get_in_or_equal($versionids, SQL_PARAMS_NAMED, 'bv');
        $records = $DB->get_records_sql(
            "SELECT m.*, i.code AS outcomecode, v.version AS outcomeversion,
                    v.status AS outcomeversionstatus, v.effectivefrom AS outcomeeffectivefrom,
                    v.effectiveto AS outcomeeffectiveto, f.code AS frameworkcode
               FROM {local_outcomemap_qmap} m
               JOIN {local_outcomemap_itemver} v ON v.id = m.itemverid
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE m.questionversionid $versionsql
           ORDER BY m.questionversionid, m.id",
            $versionparams
        );
        $recordsbyquestion = [];
        $recordindex = [];
        $questionbyversion = [];
        foreach ($questions as $question) {
            $questionbyversion[$question->questionversionid] = $question->questionid;
            $recordsbyquestion[$question->questionid] = [];
        }
        foreach ($records as $record) {
            $record->id = (int) $record->id;
            $record->questionversionid = (int) $record->questionversionid;
            $record->questionid = (int) $record->questionid;
            $record->itemverid = (int) $record->itemverid;
            $recordindex[$record->id] = $record;
            $recordsbyquestion[$record->questionid][] = $record;
            if ($record->status === workflow::DRAFT) {
                $questions[$record->questionid]->drafts[] = self::bulk_mapping_summary($record);
            }
        }

        $preview = (object) [
            'valid' => true,
            'action' => $action,
            'questions' => array_values($questions),
            'errors' => [],
            'previewtoken' => '',
            'actorid' => (int) $USER->id,
            'reason' => $reason,
            '_changes' => [],
            '_assessedquestionversions' => [],
        ];
        if ($action === 'inspect') {
            $preview->previewtoken = self::bulk_preview_token($questionids, $operation, $records);
            return $preview;
        }

        $outcome = null;
        if ($action === 'add') {
            $itemverid = input::positive_int($operation['itemverid'] ?? 0, 'itemverid');
            $outcome = $DB->get_record_sql(
                "SELECT v.*, i.code AS outcomecode, f.code AS frameworkcode
                   FROM {local_outcomemap_itemver} v
                   JOIN {local_outcomemap_item} i ON i.id = v.itemid
                   JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
                  WHERE v.id = :id",
                ['id' => $itemverid]
            );
            if (!$outcome) {
                throw new validation_exception('recordnotfound', 'outcome_version', $itemverid);
            }
            if ($outcome->status !== workflow::APPROVED) {
                throw new validation_exception('outcomeversionnotapproved', 'itemverid', $itemverid);
            }
            $checkedcontexts = [];
            foreach ($questions as $question) {
                if (isset($checkedcontexts[$question->contextid])) {
                    continue;
                }
                outcome_search::require_visible_version(
                    \context::instance_by_id((int) $question->contextid),
                    (string) $outcome->uuid,
                    $effectivefrom
                );
                $checkedcontexts[$question->contextid] = true;
            }
        } else {
            if (!$mappingids) {
                $preview->errors[] = get_string('bulkmappingselectionrequired', 'local_outcomemap');
            }
            foreach ($mappingids as $mappingid) {
                if (!isset($recordindex[$mappingid])) {
                    $preview->errors[] = get_string('bulkmappingselectioninvalid', 'local_outcomemap', $mappingid);
                    continue;
                }
                $record = $recordindex[$mappingid];
                if ($record->status !== workflow::DRAFT) {
                    $questions[$record->questionid]->errors[] = get_string(
                        'bulkonlydrafts',
                        'local_outcomemap',
                        $mappingid
                    );
                }
            }
        }

        if ($action === 'add') {
            foreach ($questions as $questionid => $question) {
                $weight = $role === content_mapping_service::ROLE_ASSESSES
                    ? ($weights[$questionid] ?? null)
                    : null;
                try {
                    $candidate = self::bulk_new_record($question, $outcome, $role, $weight, $notes, $effectivefrom);
                    foreach ($recordsbyquestion[$questionid] as $existing) {
                        if ($existing->itemverid === (int) $outcome->id && $existing->role === $role) {
                            throw new validation_exception('duplicatemapping', 'itemverid', $outcome->id);
                        }
                    }
                    $preview->_changes[] = (object) [
                        'contextid' => $question->contextid,
                        'before' => null,
                        'after' => $candidate,
                    ];
                    $question->actions[] = (object) [
                        'operation' => 'add',
                        'mappingid' => null,
                        'outcome' => $outcome->frameworkcode . '.' . $outcome->outcomecode
                            . ' v' . $outcome->version,
                        'role' => $role,
                        'weight' => $candidate->weight,
                    ];
                } catch (validation_exception $e) {
                    $question->errors[] = $e->getMessage();
                }
            }
        } else {
            foreach ($mappingids as $mappingid) {
                if (!isset($recordindex[$mappingid]) || $recordindex[$mappingid]->status !== workflow::DRAFT) {
                    continue;
                }
                $before = $recordindex[$mappingid];
                $question = $questions[$before->questionid];
                try {
                    self::bulk_validate_loaded_record($before);
                } catch (validation_exception $e) {
                    $question->errors[] = $e->getMessage();
                    continue;
                }
                $after = clone $before;
                if ($action === 'change_role') {
                    try {
                        $weight = $role === content_mapping_service::ROLE_ASSESSES
                            ? ($weights[$mappingid] ?? null)
                            : null;
                        $after->role = $role;
                        $after->weight = self::validate_role_weight($role, $weight);
                    } catch (validation_exception $e) {
                        $question->errors[] = $e->getMessage();
                        continue;
                    }
                } else if ($action === 'submit_drafts') {
                    $after->status = workflow::requires_independent_approval()
                        ? workflow::NEEDS_REVIEW
                        : workflow::APPROVED;
                }
                $preview->_changes[] = (object) [
                    'contextid' => $question->contextid,
                    'before' => $before,
                    'after' => $after,
                ];
                $question->actions[] = (object) [
                    'operation' => $action,
                    'mappingid' => $mappingid,
                    'outcome' => $before->frameworkcode . '.' . $before->outcomecode
                        . ' v' . $before->outcomeversion,
                    'role' => $action === 'change_role' ? $role : $before->role,
                    'weight' => $after->weight,
                ];
            }
        }

        $changedbyquestion = [];
        foreach ($preview->_changes as $change) {
            $record = $change->before ?? $change->after;
            $changedbyquestion[(int) $record->questionid][] = $change;
        }
        foreach ($changedbyquestion as $questionid => $changes) {
            $needsweightcheck = false;
            foreach ($changes as $change) {
                if ($action === 'change_role' || $action === 'add') {
                    $needsweightcheck = $change->after
                        && $change->after->role === content_mapping_service::ROLE_ASSESSES;
                } else if ($action === 'submit_drafts') {
                    $needsweightcheck = $change->before
                        && $change->before->role === content_mapping_service::ROLE_ASSESSES;
                }
                if ($needsweightcheck) {
                    break;
                }
            }
            if ($needsweightcheck) {
                [$total, $missing] = self::bulk_hypothetical_assessed_total(
                    $recordsbyquestion[$questionid],
                    $changes,
                    $action
                );
                if ($missing || $total !== decimal::ONE) {
                    $questions[$questionid]->errors[] = get_string(
                        'assessedweighttotalinvalid',
                        'local_outcomemap',
                        (object) ['field' => 'weight', 'detail' => $total]
                    );
                } else {
                    $preview->_assessedquestionversions[] = $questions[$questionid]->questionversionid;
                }
            }
        }

        if ($action === 'change_role') {
            self::bulk_validate_changed_scope($records, $preview->_changes, $questions);
        }
        if ($action === 'submit_drafts' && !workflow::requires_independent_approval()) {
            self::bulk_validate_finalization($records, $preview->_changes, $questions);
        }

        $preview->questions = array_values($questions);
        $preview->valid = !$preview->errors;
        foreach ($preview->questions as $question) {
            if ($question->errors) {
                $preview->valid = false;
            }
        }
        $preview->previewtoken = self::bulk_preview_token($questionids, $operation, $records);
        return $preview;
    }

    /**
     * Build a validated add candidate using already bulk-loaded core data.
     *
     * @param \stdClass $question Question.
     * @param \stdClass $outcome Outcome.
     * @param string $role Role.
     * @param mixed $weight Weight.
     * @param ?string $notes Notes.
     * @param int $effectivefrom Effectivefrom.
     */
    private static function bulk_new_record(
        \stdClass $question,
        \stdClass $outcome,
        string $role,
        $weight,
        ?string $notes,
        int $effectivefrom
    ): \stdClass {
        $record = (object) [
            'mappinguuid' => null,
            'version' => 1,
            'questionversionid' => (int) $question->questionversionid,
            'questionid' => (int) $question->questionid,
            'sourceqmapid' => null,
            'sourcequestionversionid' => null,
            'itemverid' => (int) $outcome->id,
            'role' => $role,
            'weight' => self::validate_role_weight($role, $weight),
            'notes' => $notes,
            'status' => workflow::DRAFT,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'approvedat' => null,
        ];
        effective_dates::validate($record->effectivefrom, null);
        if (
            (int) $record->effectivefrom < (int) $outcome->effectivefrom
                || ($outcome->effectiveto !== null && (int) $record->effectivefrom >= (int) $outcome->effectiveto)
        ) {
            throw new validation_exception('mappingoutsideoutcomeversion', 'effectivefrom');
        }
        return $record;
    }

    /**
     * Validate a selected draft using its already bulk-loaded outcome fields.
     *
     * @param \stdClass $record Record.
     */
    private static function bulk_validate_loaded_record(\stdClass $record): void {
        if ($record->outcomeversionstatus !== workflow::APPROVED) {
            throw new validation_exception('outcomeversionnotapproved', 'itemverid', $record->itemverid);
        }
        if (
            (int) $record->effectivefrom < (int) $record->outcomeeffectivefrom
                || ($record->outcomeeffectiveto !== null
                    && ($record->effectiveto === null
                        || (int) $record->effectiveto > (int) $record->outcomeeffectiveto))
        ) {
            throw new validation_exception('mappingoutsideoutcomeversion', 'effectivefrom');
        }
        self::validate_role_weight($record->role, $record->weight);
    }

    /**
     * Return the public subset of one draft for bulk selection.
     *
     * @param \stdClass $record Record.
     */
    private static function bulk_mapping_summary(\stdClass $record): \stdClass {
        return (object) [
            'id' => (int) $record->id,
            'outcome' => $record->frameworkcode . '.' . $record->outcomecode . ' v' . $record->outcomeversion,
            'role' => $record->role,
            'weight' => $record->weight,
            'status' => $record->status,
        ];
    }

    /**
     * Calculate an exact assessed total after applying in-memory changes.
     *
     * @param array $records Records.
     * @param array $changes Changes.
     * @param string $action Action.
     */
    private static function bulk_hypothetical_assessed_total(
        array $records,
        array $changes,
        string $action
    ): array {
        $changed = [];
        $touchedranges = [];
        foreach ($changes as $change) {
            if ($change->before) {
                $changed[(int) $change->before->id] = $change->after;
            } else {
                $records[] = $change->after;
            }
            $touched = $action === 'submit_drafts' ? $change->before : $change->after;
            if ($touched && $touched->role === content_mapping_service::ROLE_ASSESSES) {
                $touchedranges[] = $touched;
            }
        }

        $assessed = [];
        $boundaries = [];
        foreach ($records as $record) {
            if (isset($record->id) && array_key_exists((int) $record->id, $changed)) {
                $record = $changed[(int) $record->id];
                if ($record === null) {
                    continue;
                }
            }
            if ($record->role !== content_mapping_service::ROLE_ASSESSES) {
                continue;
            }
            $included = in_array($record->status, [workflow::APPROVED, workflow::DRAFT, workflow::NEEDS_REVIEW], true);
            if ($action === 'submit_drafts' && workflow::requires_independent_approval()) {
                $included = in_array($record->status, [workflow::APPROVED, workflow::NEEDS_REVIEW], true);
            } else if ($action === 'submit_drafts') {
                $included = $record->status === workflow::APPROVED;
            }
            if (!$included) {
                continue;
            }
            $assessed[] = $record;
            $boundaries[] = (int) $record->effectivefrom;
            if ($record->effectiveto !== null) {
                $boundaries[] = (int) $record->effectiveto;
            }
        }
        foreach ($touchedranges as $record) {
            $boundaries[] = (int) $record->effectivefrom;
            if ($record->effectiveto !== null) {
                $boundaries[] = (int) $record->effectiveto;
            }
        }
        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);

        foreach ($boundaries as $point) {
            $touchespoint = false;
            foreach ($touchedranges as $record) {
                if (
                    (int) $record->effectivefrom <= $point
                        && ($record->effectiveto === null || (int) $record->effectiveto > $point)
                ) {
                    $touchespoint = true;
                    break;
                }
            }
            if (!$touchespoint) {
                continue;
            }
            $total = decimal::ZERO;
            $missing = false;
            foreach ($assessed as $record) {
                if (
                    (int) $record->effectivefrom <= $point
                        && ($record->effectiveto === null || (int) $record->effectiveto > $point)
                ) {
                    if ($record->weight === null) {
                        $missing = true;
                    } else {
                        $total = decimal::add(
                            $total,
                            decimal::require_canonical($record->weight, 'weight')
                        );
                    }
                }
            }
            if ($missing || $total !== decimal::ONE) {
                return [$total, $missing];
            }
        }
        return [decimal::ONE, false];
    }

    /**
     * Validate that role changes do not create a duplicate draft/current scope.
     *
     * @param array $records Records.
     * @param array $changes Changes.
     * @param array $questions Questions.
     */
    private static function bulk_validate_changed_scope(array $records, array $changes, array &$questions): void {
        $changed = [];
        foreach ($changes as $change) {
            $changed[(int) $change->before->id] = $change->after;
        }
        foreach ($changes as $change) {
            $candidate = $change->after;
            foreach ($records as $record) {
                if ((int) $record->id === (int) $candidate->id) {
                    continue;
                }
                $other = $changed[(int) $record->id] ?? $record;
                if (
                    (int) $other->questionversionid === (int) $candidate->questionversionid
                        && (int) $other->itemverid === (int) $candidate->itemverid
                        && $other->role === $candidate->role
                ) {
                    $questions[(int) $candidate->questionid]->errors[] = get_string(
                        'duplicatemapping',
                        'local_outcomemap'
                    );
                    break;
                }
            }
        }
    }

    /**
     * Validate duplicate scopes and immutable history before direct finalization.
     *
     * @param array $records Records.
     * @param array $changes Changes.
     * @param array $questions Questions.
     */
    private static function bulk_validate_finalization(array $records, array $changes, array &$questions): void {
        $final = [];
        foreach ($changes as $change) {
            $final[(int) $change->before->id] = $change->after;
        }
        foreach ($changes as $change) {
            $candidate = $change->after;
            foreach ($records as $record) {
                if ((int) $record->id === (int) $candidate->id) {
                    continue;
                }
                $other = $final[(int) $record->id] ?? $record;
                if (
                    $other->status !== workflow::APPROVED
                        || !self::bulk_ranges_overlap($candidate, $other)
                ) {
                    continue;
                }
                if (
                    $other->mappinguuid === $candidate->mappinguuid
                        || ((int) $other->questionversionid === (int) $candidate->questionversionid
                            && (int) $other->itemverid === (int) $candidate->itemverid
                            && $other->role === $candidate->role)
                ) {
                    $questions[(int) $candidate->questionid]->errors[] = get_string(
                        'duplicatemapping',
                        'local_outcomemap'
                    );
                    break;
                }
            }
        }
    }

    /**
     * Whether two effective ranges overlap.
     *
     * @param \stdClass $a A.
     * @param \stdClass $b B.
     */
    private static function bulk_ranges_overlap(\stdClass $a, \stdClass $b): bool {
        return ($a->effectiveto === null || (int) $b->effectivefrom < (int) $a->effectiveto)
            && ($b->effectiveto === null || (int) $a->effectivefrom < (int) $b->effectiveto);
    }

    /**
     * Bind a preview token to request and current mapping state.
     *
     * @param array $questionids Questionids.
     * @param array $operation Operation.
     * @param array $records Records.
     */
    private static function bulk_preview_token(array $questionids, array $operation, array $records): string {
        $state = [];
        foreach ($records as $record) {
            $state[] = [
                (int) $record->id,
                $record->mappinguuid,
                (int) $record->version,
                (int) $record->questionversionid,
                (int) $record->itemverid,
                $record->status,
                $record->role,
                $record->weight,
                $record->notes,
                (int) $record->effectivefrom,
                $record->effectiveto === null ? null : (int) $record->effectiveto,
                (int) $record->timemodified,
            ];
        }
        $payload = json_encode([$questionids, $operation, $state], JSON_UNESCAPED_SLASHES);
        return hash_hmac('sha256', $payload, get_site_identifier() . ':' . sesskey());
    }

    /**
     * Flatten structured preview errors for a commit exception.
     *
     * @param \stdClass $preview Preview.
     */
    private static function bulk_error_messages(\stdClass $preview): array {
        $messages = $preview->errors;
        foreach ($preview->questions as $question) {
            foreach ($question->errors as $error) {
                $messages[] = $question->name . ': ' . $error;
            }
        }
        return $messages ?: ['The bulk preview is invalid.'];
    }

    /**
     * Build and validate a draft mapping record.
     *
     * @param array $data Mapping data.
     * @param string $mappinguuid Stable mapping UUID.
     * @param int $version Mapping version number.
     * @return \stdClass The validated draft mapping record.
     */
    private static function build_record(array $data, string $mappinguuid, int $version): \stdClass {
        $questionversion = self::require_question_version(
            input::positive_int($data['questionversionid'] ?? 0, 'questionversionid')
        );
        $now = time();
        $record = (object) [
            'mappinguuid' => uuid::normalize($mappinguuid),
            'version' => $version,
            'questionversionid' => (int) $questionversion->id,
            'questionid' => (int) $questionversion->questionid,
            'sourceqmapid' => empty($data['sourceqmapid'])
                ? null
                : input::positive_int($data['sourceqmapid'], 'sourceqmapid'),
            'sourcequestionversionid' => empty($data['sourcequestionversionid'])
                ? null
                : input::positive_int($data['sourcequestionversionid'], 'sourcequestionversionid'),
            'itemverid' => input::positive_int($data['itemverid'] ?? 0, 'itemverid'),
            'role' => input::required_text($data['role'] ?? '', 'role', 20),
            'weight' => null,
            'notes' => input::optional_multiline($data['notes'] ?? null),
            'status' => workflow::DRAFT,
            'effectivefrom' => input::positive_int($data['effectivefrom'] ?? $now, 'effectivefrom'),
            'effectiveto' => input::optional_timestamp($data['effectiveto'] ?? null, 'effectiveto'),
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
        ];
        $record->weight = self::validate_role_weight($record->role, $data['weight'] ?? null);
        effective_dates::validate(
            (int) $record->effectivefrom,
            $record->effectiveto === null ? null : (int) $record->effectiveto
        );
        self::validate_record($record);
        return $record;
    }

    /**
     * Validate the references, dates, role, and weight of a mapping record.
     *
     * @param \stdClass $record Mapping record.
     * @return void
     */
    private static function validate_record(\stdClass $record): void {
        $questionversion = self::require_question_version((int) $record->questionversionid);
        if ((int) $questionversion->questionid !== (int) $record->questionid) {
            throw new validation_exception('questionversionmismatch', 'questionid', $record->questionid);
        }
        $itemversion = self::get_required('local_outcomemap_itemver', (int) $record->itemverid, 'outcome_version');
        if ($itemversion->status !== workflow::APPROVED) {
            throw new validation_exception('outcomeversionnotapproved', 'itemverid', $record->itemverid);
        }
        $context = context_resolver::for_question_version((int) $record->questionversionid);
        outcome_search::require_visible_version($context, $itemversion->uuid, (int) $record->effectivefrom);
        if (
            (int) $record->effectivefrom < (int) $itemversion->effectivefrom
                || ($itemversion->effectiveto !== null
                    && ($record->effectiveto === null || (int) $record->effectiveto > (int) $itemversion->effectiveto))
        ) {
            throw new validation_exception('mappingoutsideoutcomeversion', 'effectivefrom');
        }
        self::validate_role_weight($record->role, $record->weight);
    }

    /**
     * Validate a mapping role and its weight under question evidence rules.
     *
     * @param string $role Mapping role.
     * @param mixed $weight Mapping weight.
     * @return string|null The normalized weight, or null for non-assessment roles.
     */
    private static function validate_role_weight(string $role, $weight): ?string {
        if (!in_array($role, self::ROLES, true)) {
            throw new validation_exception('invalidmappingrole', 'role', $role);
        }
        $hasweight = $weight !== null && trim((string) $weight) !== '';
        if ($role === content_mapping_service::ROLE_ASSESSES) {
            if (!$hasweight) {
                throw new validation_exception('assessedweightrequired', 'weight');
            }
            return decimal::positive($weight, 'weight');
        }
        if ($hasweight) {
            throw new validation_exception('weightnotallowedforrole', 'weight', $role);
        }
        return null;
    }

    /**
     * Require that approved assessed weights total exactly one across every
     * effective segment touched by a newly approved batch.
     *
     * Runs inside the approval transaction after the batch rows are updated so
     * the check sees the exact state being committed.
     *
     * @param int $questionversionid Question-version ID.
     * @param \stdClass[] $batch Mapping records that were just approved.
     * @return void
     */
    private static function require_valid_assessed_total(int $questionversionid, array $batch): void {
        global $DB;
        $approved = $DB->get_records(self::TABLE, [
            'questionversionid' => $questionversionid,
            'role' => content_mapping_service::ROLE_ASSESSES,
            'status' => workflow::APPROVED,
        ]);
        $boundaries = [];
        foreach ($approved as $record) {
            $boundaries[] = (int) $record->effectivefrom;
            if ($record->effectiveto !== null) {
                $boundaries[] = (int) $record->effectiveto;
            }
        }
        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);
        foreach ($boundaries as $point) {
            $coversbatch = false;
            foreach ($batch as $record) {
                if (
                    (int) $record->effectivefrom <= $point
                        && ($record->effectiveto === null || (int) $record->effectiveto > $point)
                ) {
                    $coversbatch = true;
                    break;
                }
            }
            if (!$coversbatch) {
                continue;
            }
            $total = decimal::ZERO;
            foreach ($approved as $record) {
                if (
                    (int) $record->effectivefrom <= $point
                        && ($record->effectiveto === null || (int) $record->effectiveto > $point)
                ) {
                    if ($record->weight === null) {
                        throw new validation_exception('assessedweightrequired', 'weight');
                    }
                    $total = decimal::add($total, decimal::require_canonical($record->weight, 'weight'));
                }
            }
            if ($total !== decimal::ONE) {
                throw new validation_exception('assessedweighttotalinvalid', 'weight', $total);
            }
        }
    }

    /**
     * Require that a candidate does not overlap an approved version of the same mapping.
     *
     * @param \stdClass $candidate Candidate mapping record.
     * @return void
     */
    private static function require_no_approved_overlap(\stdClass $candidate): void {
        global $DB;
        [$overlapsql, $params] = effective_dates::overlap_sql(
            '',
            (int) $candidate->effectivefrom,
            $candidate->effectiveto === null ? null : (int) $candidate->effectiveto,
            'mapping'
        );
        $params += [
            'mappinguuid' => $candidate->mappinguuid,
            'status' => workflow::APPROVED,
            'id' => $candidate->id,
        ];
        if (
            $DB->record_exists_select(
                self::TABLE,
                'mappinguuid = :mappinguuid AND status = :status AND id <> :id AND ' . $overlapsql,
                $params
            )
        ) {
            throw new validation_exception('effectiverangeoverlap', 'effectivefrom');
        }
    }

    /**
     * Require that a candidate does not duplicate another approved mapping in the same scope.
     *
     * @param \stdClass $candidate Candidate mapping record.
     * @return void
     */
    private static function require_no_duplicate_scope(\stdClass $candidate): void {
        global $DB;
        [$overlapsql, $params] = effective_dates::overlap_sql(
            '',
            (int) $candidate->effectivefrom,
            $candidate->effectiveto === null ? null : (int) $candidate->effectiveto,
            'scope'
        );
        $params += [
            'questionversionid' => $candidate->questionversionid,
            'itemverid' => $candidate->itemverid,
            'role' => $candidate->role,
            'status' => workflow::APPROVED,
            'id' => $candidate->id,
            'mappinguuid' => $candidate->mappinguuid,
        ];
        $select = 'questionversionid = :questionversionid AND itemverid = :itemverid AND role = :role'
            . ' AND status = :status AND id <> :id AND mappinguuid <> :mappinguuid AND ' . $overlapsql;
        if ($DB->record_exists_select(self::TABLE, $select, $params)) {
            throw new validation_exception('duplicatemapping', 'itemverid');
        }
    }

    /**
     * Require an existing core question version.
     *
     * @param int $questionversionid Question-version ID.
     * @return \stdClass The core question-version record.
     */
    private static function require_question_version(int $questionversionid): \stdClass {
        global $DB;
        $record = $DB->get_record('question_versions', ['id' => $questionversionid]);
        if (!$record) {
            throw new validation_exception('recordnotfound', 'question_version', $questionversionid);
        }
        return $record;
    }

    /**
     * Require mapping and Moodle question capabilities for a mutation.
     *
     * @param int $questionversionid Question-version ID.
     * @param int $questionid Question ID.
     * @return int The acting user ID.
     */
    private static function require_mutation_capabilities(int $questionversionid, int $questionid): int {
        global $USER;
        $context = context_resolver::for_question_version($questionversionid);
        require_capability('local/outcomemap:mapquestions', $context);
        self::require_question_capability($questionid, 'edit');
        return (int) $USER->id;
    }

    /**
     * Require a Moodle question capability through the core ownership-aware check.
     *
     * @param int $questionid Question ID.
     * @param string $capability Question capability suffix such as edit or view.
     * @return void
     */
    private static function require_question_capability(int $questionid, string $capability): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        if (!question_has_capability_on($questionid, $capability)) {
            $questionversionid = (int) $DB->get_field(
                'question_versions',
                'id',
                ['questionid' => $questionid],
                MUST_EXIST
            );
            throw new \required_capability_exception(
                context_resolver::for_question_version($questionversionid),
                'moodle/question:' . $capability . 'all',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Prevent scalar mutations from racing an explicit prior-version copy.
     *
     * @param \stdClass $candidate Candidate.
     */
    private static function require_no_existing_copied_scope(\stdClass $candidate): void {
        global $DB;
        $params = [
            'questionversionid' => (int) $candidate->questionversionid,
            'itemverid' => (int) $candidate->itemverid,
            'role' => $candidate->role,
        ];
        $select = 'questionversionid = :questionversionid AND itemverid = :itemverid AND role = :role'
            . ' AND sourceqmapid IS NOT NULL';
        if (isset($candidate->id)) {
            $params['id'] = (int) $candidate->id;
            $select .= ' AND id <> :id';
        }
        if ($DB->record_exists_select(self::TABLE, $select, $params)) {
            throw new validation_exception('duplicatemapping', 'itemverid', $candidate->itemverid);
        }
    }

    /**
     * Insert a mapping and write its audit event.
     *
     * @param \stdClass $record Mapping record to insert.
     * @param string $action Audit action.
     * @param string|null $reason Optional reason.
     * @return int The new mapping record ID.
     */
    private static function insert(\stdClass $record, string $action, ?string $reason): int {
        global $DB;
        $actorid = self::require_mutation_capabilities((int) $record->questionversionid, (int) $record->questionid);
        $locks = self::acquire_bulk_locks([(int) $record->questionid]);
        try {
            if ($action === 'create') {
                self::require_no_existing_copied_scope($record);
            }
            $record->createdby = $actorid;
            $transaction = $DB->start_delegated_transaction();
            try {
                $id = $DB->insert_record(self::TABLE, $record);
                $record->id = $id;
                audit_writer::write(
                    $action,
                    'question_mapping',
                    $id,
                    $record->mappinguuid,
                    null,
                    $record,
                    $reason ?? $record->notes,
                    context_resolver::for_question_version((int) $record->questionversionid),
                    $actorid
                );
                $transaction->allow_commit();
                return $id;
            } catch (\Throwable $e) {
                self::rollback($transaction, $e);
            }
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }
}
