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
    /** Mapping table name. */
    private const TABLE = 'local_outcomemap_qmap';

    /** Supported mapping roles. */
    public const ROLES = content_mapping_service::ROLES;

    /**
     * Create a version-one draft question-version mapping.
     *
     * @param array $data Mapping data including questionversionid, itemverid, and role.
     * @return int The new mapping record ID.
     */
    public static function create(array $data): int {
        $record = self::build_record($data, uuid::normalize_or_generate($data['mappinguuid'] ?? null), 1);
        return self::insert($record, 'create', $data['reason'] ?? null);
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
        $before = self::get_required(self::TABLE, $id, 'question_mapping');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'question_mapping', $id);
        }
        $data['questionversionid'] = $before->questionversionid;
        $after = self::build_record(array_merge((array) $before, $data), $before->mappinguuid, (int) $before->version);
        $actorid = self::require_mutation_capabilities((int) $after->questionversionid, (int) $after->questionid);
        $after->id = $id;
        $after->createdby = $before->createdby;
        $after->timecreated = $before->timecreated;
        $after->timemodified = time();
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
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'question_mapping', $id);
        }
        $actorid = self::require_mutation_capabilities((int) $before->questionversionid, (int) $before->questionid);
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
     * Create the next draft version of an approved mapping.
     *
     * @param int $id Approved mapping record ID.
     * @param array $data New version data.
     * @return int The new mapping record ID.
     */
    public static function create_version(int $id, array $data): int {
        global $DB;
        $previous = self::get_required(self::TABLE, $id, 'question_mapping');
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
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':needs_review');
        }
        $actorid = self::require_mutation_capabilities((int) $before->questionversionid, (int) $before->questionid);
        self::validate_record($before);
        $after = clone $before;
        $after->status = workflow::NEEDS_REVIEW;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                'submit_review',
                'question_mapping',
                $id,
                $after->mappinguuid,
                $before,
                $after,
                $reason,
                context_resolver::for_question_version((int) $after->questionversionid),
                $actorid
            );
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
        global $DB, $USER;
        $candidate = self::get_required(self::TABLE, $id, 'question_mapping');
        if ($candidate->status !== workflow::NEEDS_REVIEW) {
            throw new validation_exception('invalidtransition', 'status', $candidate->status . ':approved');
        }
        $context = context_resolver::for_question_version((int) $candidate->questionversionid);
        require_capability('local/outcomemap:approve', $context);
        self::require_question_capability((int) $candidate->questionid, 'view');
        $actorid = (int) $USER->id;
        $batch = [$candidate];
        if ($candidate->role === content_mapping_service::ROLE_ASSESSES) {
            $pending = $DB->get_records(self::TABLE, [
                'questionversionid' => $candidate->questionversionid,
                'role' => content_mapping_service::ROLE_ASSESSES,
                'status' => workflow::NEEDS_REVIEW,
            ], 'id ASC');
            $batch = array_values($pending);
        }
        foreach ($batch as $record) {
            if ((int) $record->createdby === $actorid) {
                throw new validation_exception('creatorcannotapprove', 'createdby', $actorid);
            }
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
        if ($sourcequestionversionid === null) {
            $source = $DB->get_records_select(
                'question_versions',
                'questionbankentryid = :entryid AND version < :version',
                ['entryid' => $target->questionbankentryid, 'version' => $target->version],
                'version DESC',
                '*',
                0,
                1
            );
            if (!$source) {
                return [];
            }
            $source = reset($source);
        } else {
            $source = self::require_question_version($sourcequestionversionid);
            if ((int) $source->questionbankentryid !== (int) $target->questionbankentryid) {
                throw new validation_exception('questionversionmismatch', 'sourcequestionversionid',
                    $sourcequestionversionid);
            }
        }
        if ((int) $source->id === (int) $target->id) {
            throw new validation_exception('questionversionmismatch', 'sourcequestionversionid', $source->id);
        }
        $actorid = self::require_mutation_capabilities((int) $target->id, (int) $target->questionid);
        $now = time();
        $mappings = $DB->get_records_select(
            self::TABLE,
            'questionversionid = :questionversionid AND status = :status
                AND effectivefrom <= :at1 AND (effectiveto IS NULL OR effectiveto > :at2)',
            [
                'questionversionid' => $source->id,
                'status' => workflow::APPROVED,
                'at1' => $now,
                'at2' => $now,
            ],
            'id ASC'
        );
        if (!$mappings) {
            return [];
        }
        $existing = $DB->get_records(self::TABLE, ['questionversionid' => $target->id], '', 'id,itemverid,role');
        $covered = [];
        foreach ($existing as $record) {
            $covered[$record->itemverid . ':' . $record->role] = true;
        }
        $newids = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            $context = context_resolver::for_question_version((int) $target->id);
            foreach ($mappings as $mapping) {
                if (isset($covered[$mapping->itemverid . ':' . $mapping->role])) {
                    continue;
                }
                try {
                    $draft = self::build_record([
                        'questionversionid' => (int) $target->id,
                        'itemverid' => (int) $mapping->itemverid,
                        'role' => $mapping->role,
                        'weight' => $mapping->weight,
                        'notes' => $mapping->notes,
                        'effectivefrom' => (int) $mapping->effectivefrom,
                        'effectiveto' => $mapping->effectiveto === null ? null : (int) $mapping->effectiveto,
                    ], uuid::generate(), 1);
                } catch (validation_exception $e) {
                    // The source mapping no longer satisfies draft rules, for
                    // example a retired outcome version; skip it rather than
                    // losing every other copied mapping.
                    continue;
                }
                $draft->createdby = $actorid;
                $id = $DB->insert_record(self::TABLE, $draft);
                $draft->id = $id;
                audit_writer::write(
                    'copy_version',
                    'question_mapping',
                    $id,
                    $draft->mappinguuid,
                    $mapping,
                    $draft,
                    $reason ?? ('Copied from question version ' . $source->id
                        . ' mapping ' . $mapping->mappinguuid . ' v' . $mapping->version),
                    $context,
                    $actorid
                );
                $newids[] = $id;
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
        return $newids;
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
                    f.uuid AS frameworkuuid, f.code AS frameworkcode
               FROM {local_outcomemap_qmap} m
               JOIN {local_outcomemap_itemver} v ON v.id = m.itemverid
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
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
            $questionversionid = (int) $DB->get_field('question_versions', 'id',
                ['questionid' => $questionid], MUST_EXIST);
            throw new \required_capability_exception(
                context_resolver::for_question_version($questionversionid),
                'moodle/question:' . $capability . 'all',
                'nopermissions',
                ''
            );
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
    }
}
