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
 * Versioned calculation and attempt-selection policy service.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\canonical_json;
use local_outcomemap\local\decimal;
use local_outcomemap\local\effective_dates;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Governed policies for attempt selection, sufficiency, and banding.
 *
 * No institutional default is ever seeded: until an approved policy of each
 * required type is resolvable for a scope, no official result is calculated
 * there.
 */
final class policy_service extends base_service {
    /** Attempt-selection policy type. */
    public const TYPE_ATTEMPT_SELECTION = 'attempt_selection';

    /** Calculation (sufficiency, precision, and bands) policy type. */
    public const TYPE_CALCULATION = 'calculation';

    /** Supported policy types. */
    public const TYPES = [self::TYPE_ATTEMPT_SELECTION, self::TYPE_CALCULATION];

    /** Institution scope. */
    public const SCOPE_INSTITUTION = 'institution';

    /** Catalog-course scope. */
    public const SCOPE_CATALOG_COURSE = 'catalog_course';

    /** Course-instance scope. */
    public const SCOPE_COURSE_INSTANCE = 'course_instance';

    /** Assessment (course-module) scope. */
    public const SCOPE_ASSESSMENT = 'assessment';

    /** Scope precedence from most to least specific. */
    public const SCOPE_PRECEDENCE = [
        self::SCOPE_ASSESSMENT,
        self::SCOPE_COURSE_INSTANCE,
        self::SCOPE_CATALOG_COURSE,
        self::SCOPE_INSTITUTION,
    ];

    /** First completed attempt. */
    public const METHOD_FIRST_COMPLETED = 'first_completed';

    /** Latest completed attempt. */
    public const METHOD_LATEST_COMPLETED = 'latest_completed';

    /** Highest graded attempt. */
    public const METHOD_HIGHEST_GRADED = 'highest_graded';

    /** Attempt selected by the quiz grade method. */
    public const METHOD_QUIZ_GRADE = 'quiz_grade';

    /** All completed attempts. */
    public const METHOD_ALL_COMPLETED = 'all_completed';

    /** Supported attempt-selection methods. */
    public const METHODS = [
        self::METHOD_FIRST_COMPLETED,
        self::METHOD_LATEST_COMPLETED,
        self::METHOD_HIGHEST_GRADED,
        self::METHOD_QUIZ_GRADE,
        self::METHOD_ALL_COMPLETED,
    ];

    /**
     * Create a version-one draft policy.
     *
     * @param array $data Policy data: policytype, scopetype, scopeid, name,
     *     config array, optional bands array, effective dates.
     * @return int The new policy record ID.
     */
    public static function create(array $data): int {
        return self::insert(self::build_record(
            $data,
            uuid::normalize_or_generate($data['policyuuid'] ?? null),
            1
        ), $data['bands'] ?? [], 'create', $data['reason'] ?? null);
    }

    /**
     * Update an unsubmitted draft policy and replace its draft bands.
     *
     * @param int $id Policy record ID.
     * @param array $data Updated policy data.
     * @return void
     */
    public static function update_draft(int $id, array $data): void {
        global $DB;
        $before = self::get_required('local_outcomemap_policy', $id, 'policy');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'policy', $id);
        }
        // Moving a draft between scopes requires authority in both contexts.
        self::require_policy_capability($before);
        $beforebands = self::get_bands($id);
        $merged = array_merge((array) $before, $data);
        if (!array_key_exists('config', $data)) {
            $merged['config'] = json_decode($before->configjson, true) ?? [];
        }
        $after = self::build_record(
            $merged,
            $before->policyuuid,
            (int) $before->version
        );
        $actorid = self::require_policy_capability($after);
        $afterbands = self::build_bands(
            $after->policytype,
            array_key_exists('bands', $data) ? $data['bands'] : $beforebands
        );
        $after->id = $id;
        $after->createdby = $before->createdby;
        $after->timecreated = $before->timecreated;
        $after->timemodified = time();

        $beforeaudit = clone $before;
        $beforeaudit->bands = $beforebands;
        $afteraudit = clone $after;
        $afteraudit->bands = $afterbands;
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record('local_outcomemap_policy', $after);
            $DB->delete_records('local_outcomemap_band', ['policyid' => $id]);
            foreach ($afterbands as $band) {
                $band->policyid = $id;
                $DB->insert_record('local_outcomemap_band', $band);
            }
            audit_writer::write('update', 'policy', $id, $after->policyuuid, $beforeaudit, $afteraudit,
                $data['reason'] ?? null, self::policy_context($after), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Delete an unsubmitted, unreferenced draft policy.
     *
     * @param int $id Policy record ID.
     * @param string|null $reason Optional deletion reason.
     * @return void
     */
    public static function delete_draft(int $id, ?string $reason = null): void {
        global $DB;
        $before = self::get_required('local_outcomemap_policy', $id, 'policy');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'policy', $id);
        }
        $actorid = self::require_policy_capability($before);
        if ($DB->record_exists('local_outcomemap_evidence', ['policyid' => $id])
                || $DB->record_exists('local_outcomemap_result', ['policyid' => $id])) {
            throw new validation_exception('policyinuse', 'policy', $id);
        }
        $before->bands = self::get_bands($id);
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records('local_outcomemap_band', ['policyid' => $id]);
            $DB->delete_records('local_outcomemap_policy', ['id' => $id]);
            audit_writer::write('delete', 'policy', $id, $before->policyuuid, $before, null,
                $reason, self::policy_context($before), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Create the next draft version of an approved policy.
     *
     * @param int $id Approved policy record ID.
     * @param array $data New version data.
     * @return int The new policy record ID.
     */
    public static function create_version(int $id, array $data): int {
        global $DB;
        $previous = self::get_required('local_outcomemap_policy', $id, 'policy');
        if ($previous->status !== workflow::APPROVED) {
            throw new validation_exception('invalidtransition', 'status', $previous->status . ':new_version');
        }
        $data['policytype'] = $previous->policytype;
        $data['scopetype'] = $previous->scopetype;
        $data['scopeid'] = $previous->scopeid;
        $maxversion = (int) $DB->get_field_sql(
            'SELECT MAX(version) FROM {local_outcomemap_policy} WHERE policyuuid = :policyuuid',
            ['policyuuid' => $previous->policyuuid]
        );
        $record = self::build_record(
            array_merge(['name' => $previous->name], $data),
            $previous->policyuuid,
            $maxversion + 1
        );
        return self::insert($record, $data['bands'] ?? [], 'create_version', $data['reason'] ?? null);
    }

    /**
     * Submit a draft policy for review.
     *
     * @param int $id Policy record ID.
     * @param string|null $reason Optional reason.
     * @return void
     */
    public static function submit_for_review(int $id, ?string $reason = null): void {
        global $DB;
        $before = self::get_required('local_outcomemap_policy', $id, 'policy');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':needs_review');
        }
        $actorid = self::require_policy_capability($before);
        $after = clone $before;
        $after->status = workflow::NEEDS_REVIEW;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record('local_outcomemap_policy', $after);
            audit_writer::write('submit_review', 'policy', $id, $after->policyuuid, $before, $after,
                $reason, self::policy_context($after), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Approve a reviewed policy and mark superseded results stale.
     *
     * @param int $id Policy record ID.
     * @param string|null $reason Optional reason.
     * @return void
     */
    public static function approve(int $id, ?string $reason = null): void {
        global $DB, $USER;
        $before = self::get_required('local_outcomemap_policy', $id, 'policy');
        if ($before->status !== workflow::NEEDS_REVIEW) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':approved');
        }
        $context = self::policy_context($before);
        require_capability('local/outcomemap:approve', $context);
        $actorid = (int) $USER->id;
        if ((int) $before->createdby === $actorid) {
            throw new validation_exception('creatorcannotapprove', 'createdby', $actorid);
        }
        self::validate_config($before->policytype, json_decode($before->configjson, true) ?? []);
        self::validate_bands(self::get_bands($id));
        $after = clone $before;
        $after->status = workflow::APPROVED;
        $after->approvedby = $actorid;
        $after->approvedat = time();
        $after->timemodified = $after->approvedat;
        $transaction = $DB->start_delegated_transaction();
        try {
            self::require_no_approved_overlap($before);
            $DB->update_record('local_outcomemap_policy', $after);
            // Nonfrozen results calculated under earlier versions of this
            // policy are now stale and queue for recalculation.
            $previousids = $DB->get_fieldset_select('local_outcomemap_policy', 'id',
                'policyuuid = :policyuuid AND id <> :id',
                ['policyuuid' => $before->policyuuid, 'id' => $id]);
            if ($previousids) {
                [$insql, $params] = $DB->get_in_or_equal($previousids, SQL_PARAMS_NAMED, 'pol');
                $params['frozen'] = 'frozen';
                $DB->set_field_select('local_outcomemap_result', 'stale', 1,
                    "policyid $insql AND state <> :frozen", $params);
            }
            audit_writer::write('approve', 'policy', $id, $after->policyuuid, $before, $after,
                $reason, $context, $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Load one policy with decoded config and bands.
     *
     * @param int $id Policy record ID.
     * @return \stdClass Policy record with config array and band rows.
     */
    public static function get(int $id): \stdClass {
        $record = self::get_required('local_outcomemap_policy', $id, 'policy');
        $record->config = json_decode($record->configjson, true) ?? [];
        $record->bands = self::get_bands($id);
        return $record;
    }

    /**
     * Return the bands of a policy in canonical sort order.
     *
     * @param int $policyid Policy record ID.
     * @return \stdClass[]
     */
    public static function get_bands(int $policyid): array {
        global $DB;
        return array_values($DB->get_records('local_outcomemap_band',
            ['policyid' => $policyid], 'sortorder ASC'));
    }

    /**
     * Return every policy version with decoded configuration and bands.
     *
     * This site-administration listing bulk-loads all bands to avoid an N+1
     * query as policy histories grow.
     *
     * @return \stdClass[] Policy records keyed by record ID.
     */
    public static function list_all(): array {
        global $DB;
        require_capability('local/outcomemap:managepolicies', \context_system::instance());
        $records = $DB->get_records('local_outcomemap_policy', null,
            'policytype, name, policyuuid, version DESC, id DESC');
        foreach ($records as $record) {
            $record->config = json_decode($record->configjson, true) ?? [];
            $record->bands = [];
        }
        if (!$records) {
            return [];
        }
        $bands = $DB->get_records('local_outcomemap_band', null, 'policyid, sortorder');
        foreach ($bands as $band) {
            $records[(int) $band->policyid]->bands[] = $band;
        }
        return $records;
    }

    /**
     * Resolve the effective approved policy for a calculation scope.
     *
     * Precedence: assessment, course instance, catalog course, institution.
     * Returns null when no approved policy governs the scope; officials
     * calculations must not proceed in that case.
     *
     * @param string $policytype Policy type.
     * @param int|null $cinstid Course-instance ID.
     * @param int|null $cmid Assessment course-module ID.
     * @param int|null $at Effective timestamp; defaults to now.
     * @return \stdClass|null Policy with decoded config and bands.
     */
    public static function resolve(string $policytype, ?int $cinstid, ?int $cmid, ?int $at = null): ?\stdClass {
        global $DB;
        if (!in_array($policytype, self::TYPES, true)) {
            throw new validation_exception('invalidfield', 'policytype', $policytype);
        }
        $at = $at ?? time();
        $courseid = null;
        if ($cinstid !== null) {
            $courseid = $DB->get_field('local_outcomemap_cinst', 'courseid', ['id' => $cinstid]);
        }
        $scopes = [
            [self::SCOPE_ASSESSMENT, $cmid],
            [self::SCOPE_COURSE_INSTANCE, $cinstid],
            [self::SCOPE_CATALOG_COURSE, $courseid === false ? null : $courseid],
            [self::SCOPE_INSTITUTION, null],
        ];
        foreach ($scopes as [$scopetype, $scopeid]) {
            if ($scopetype !== self::SCOPE_INSTITUTION && !$scopeid) {
                continue;
            }
            $select = 'policytype = :policytype AND scopetype = :scopetype AND status = :status
                AND effectivefrom <= :at1 AND (effectiveto IS NULL OR effectiveto > :at2)';
            $params = [
                'policytype' => $policytype,
                'scopetype' => $scopetype,
                'status' => workflow::APPROVED,
                'at1' => $at,
                'at2' => $at,
            ];
            if ($scopetype === self::SCOPE_INSTITUTION) {
                $select .= ' AND scopeid IS NULL';
            } else {
                $select .= ' AND scopeid = :scopeid';
                $params['scopeid'] = (int) $scopeid;
            }
            $records = $DB->get_records_select('local_outcomemap_policy', $select, $params, 'version DESC, id DESC');
            if ($records) {
                $record = reset($records);
                $record->config = json_decode($record->configjson, true) ?? [];
                $record->bands = self::get_bands((int) $record->id);
                return $record;
            }
        }
        return null;
    }

    /**
     * Build and validate a draft policy record.
     *
     * @param array $data Policy data.
     * @param string $policyuuid Stable policy UUID.
     * @param int $version Version number.
     * @return \stdClass Draft policy record.
     */
    private static function build_record(array $data, string $policyuuid, int $version): \stdClass {
        $policytype = input::required_text($data['policytype'] ?? '', 'policytype', 50);
        if (!in_array($policytype, self::TYPES, true)) {
            throw new validation_exception('invalidfield', 'policytype', $policytype);
        }
        $scopetype = input::required_text($data['scopetype'] ?? '', 'scopetype', 30);
        if (!in_array($scopetype, self::SCOPE_PRECEDENCE, true)) {
            throw new validation_exception('invalidfield', 'scopetype', $scopetype);
        }
        $scopeid = null;
        if ($scopetype !== self::SCOPE_INSTITUTION) {
            $scopeid = input::positive_int($data['scopeid'] ?? 0, 'scopeid');
            self::require_scope_target($scopetype, $scopeid);
        }
        $config = $data['config'] ?? [];
        if (!is_array($config)) {
            throw new validation_exception('invalidfield', 'config', 'array required');
        }
        $config = self::validate_config($policytype, $config);
        $now = time();
        $record = (object) [
            'policyuuid' => uuid::normalize($policyuuid),
            'version' => $version,
            'policytype' => $policytype,
            'scopetype' => $scopetype,
            'scopeid' => $scopeid,
            'name' => input::required_text($data['name'] ?? '', 'name', 255),
            'configjson' => canonical_json::encode($config),
            'confighash' => hash('sha256', canonical_json::encode($config)),
            'status' => workflow::DRAFT,
            'effectivefrom' => input::positive_int($data['effectivefrom'] ?? $now, 'effectivefrom'),
            'effectiveto' => input::optional_timestamp($data['effectiveto'] ?? null, 'effectiveto'),
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
        ];
        effective_dates::validate(
            (int) $record->effectivefrom,
            $record->effectiveto === null ? null : (int) $record->effectiveto
        );
        return $record;
    }

    /**
     * Insert a draft policy with its bands and audit event.
     *
     * @param \stdClass $record Draft policy record.
     * @param array $bands Band definitions in display order.
     * @param string $action Audit action.
     * @param string|null $reason Optional reason.
     * @return int The new policy record ID.
     */
    private static function insert(\stdClass $record, array $bands, string $action, ?string $reason): int {
        global $DB;
        $actorid = self::require_policy_capability($record);
        $record->createdby = $actorid;
        $bandrecords = self::build_bands($record->policytype, $bands);
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record('local_outcomemap_policy', $record);
            $record->id = $id;
            foreach ($bandrecords as $band) {
                $band->policyid = $id;
                $DB->insert_record('local_outcomemap_band', $band);
            }
            audit_writer::write($action, 'policy', $id, $record->policyuuid, null, $record,
                $reason, self::policy_context($record), $actorid);
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Validate a typed policy configuration.
     *
     * @param string $policytype Policy type.
     * @param array $config Configuration array.
     * @return array Normalized configuration.
     */
    private static function validate_config(string $policytype, array $config): array {
        if ($policytype === self::TYPE_ATTEMPT_SELECTION) {
            $method = $config['method'] ?? '';
            if (!in_array($method, self::METHODS, true)) {
                throw new validation_exception('invalidpolicyconfig', 'method', (string) $method);
            }
            return ['method' => $method];
        }
        $normalized = [];
        $minitems = $config['minitems'] ?? 1;
        if (filter_var($minitems, FILTER_VALIDATE_INT) === false || (int) $minitems < 1) {
            throw new validation_exception('invalidpolicyconfig', 'minitems', $minitems);
        }
        $normalized['minitems'] = (int) $minitems;
        if (isset($config['minweightedpossible']) && trim((string) $config['minweightedpossible']) !== '') {
            $normalized['minweightedpossible'] = decimal::require_canonical(
                $config['minweightedpossible'], 'minweightedpossible');
        }
        $normalized['requiremanualgrading'] = !empty($config['requiremanualgrading']);
        $displayscale = $config['displayscale'] ?? 1;
        if (filter_var($displayscale, FILTER_VALIDATE_INT) === false
                || (int) $displayscale < 0 || (int) $displayscale > decimal::SCALE) {
            throw new validation_exception('invalidpolicyconfig', 'displayscale', $displayscale);
        }
        $normalized['displayscale'] = (int) $displayscale;
        return $normalized;
    }

    /**
     * Build validated band rows for a draft calculation policy.
     *
     * @param string $policytype Policy type.
     * @param array $bands Band definitions in display order.
     * @return \stdClass[] Band records without policy IDs.
     */
    private static function build_bands(string $policytype, array $bands): array {
        if (!$bands) {
            return [];
        }
        if ($policytype !== self::TYPE_CALCULATION) {
            throw new validation_exception('invalidpolicyconfig', 'bands', 'bands require a calculation policy');
        }
        $records = [];
        foreach (array_values($bands) as $index => $band) {
            $band = (array) $band;
            $records[] = (object) [
                'policyid' => 0,
                'code' => input::required_text($band['code'] ?? '', 'code', 50),
                'name' => input::required_text($band['name'] ?? '', 'name', 255),
                'description' => input::optional_multiline($band['description'] ?? null),
                'minpercent' => isset($band['minpercent']) && trim((string) $band['minpercent']) !== ''
                    ? decimal::require_canonical($band['minpercent'], 'minpercent') : null,
                'mininclusive' => empty($band['mininclusive']) && isset($band['mininclusive']) ? 0 : 1,
                'maxpercent' => isset($band['maxpercent']) && trim((string) $band['maxpercent']) !== ''
                    ? decimal::require_canonical($band['maxpercent'], 'maxpercent') : null,
                'maxinclusive' => empty($band['maxinclusive']) ? 0 : 1,
                'sortorder' => $index,
            ];
        }
        self::validate_bands($records);
        return $records;
    }

    /**
     * Validate that band ranges are ordered and non-overlapping.
     *
     * @param \stdClass[] $bands Band records in sort order.
     * @return void
     */
    private static function validate_bands(array $bands): void {
        $previousmax = null;
        $previousmaxinclusive = false;
        $seencodes = [];
        foreach ($bands as $band) {
            if (isset($seencodes[$band->code])) {
                throw new validation_exception('duplicatebandcode', 'bands', $band->code);
            }
            $seencodes[$band->code] = true;
            if (
                $band->minpercent !== null && $band->maxpercent !== null
                    && decimal::cmp((string) $band->minpercent, (string) $band->maxpercent) > 0
            ) {
                throw new validation_exception('invalidpolicyconfig', 'bands', $band->code);
            }
            if ($previousmax === null && count($seencodes) > 1) {
                throw new validation_exception('bandsoverlap', 'bands', $band->code);
            }
            if (count($seencodes) > 1 && $band->minpercent === null) {
                throw new validation_exception('bandsoverlap', 'bands', $band->code);
            }
            if ($previousmax !== null && $band->minpercent !== null) {
                $comparison = decimal::cmp((string) $band->minpercent, $previousmax);
                if ($comparison < 0 || ($comparison === 0 && $previousmaxinclusive && (int) $band->mininclusive)) {
                    throw new validation_exception('bandsoverlap', 'bands', $band->code);
                }
            }
            $previousmax = $band->maxpercent === null ? null : (string) $band->maxpercent;
            $previousmaxinclusive = (bool) $band->maxinclusive;
        }
    }

    /**
     * Match the band containing an unrounded percentage.
     *
     * @param \stdClass[] $bands Band records in sort order.
     * @param string $percentage Canonical percentage.
     * @return \stdClass|null Matching band or null.
     */
    public static function match_band(array $bands, string $percentage): ?\stdClass {
        foreach ($bands as $band) {
            if ($band->minpercent !== null) {
                $cmp = decimal::cmp($percentage, (string) $band->minpercent);
                if ($cmp < 0 || ($cmp === 0 && !(int) $band->mininclusive)) {
                    continue;
                }
            }
            if ($band->maxpercent !== null) {
                $cmp = decimal::cmp($percentage, (string) $band->maxpercent);
                if ($cmp > 0 || ($cmp === 0 && !(int) $band->maxinclusive)) {
                    continue;
                }
            }
            return $band;
        }
        return null;
    }

    /**
     * Require that a scope target exists.
     *
     * @param string $scopetype Scope type.
     * @param int $scopeid Scope target ID.
     * @return void
     */
    private static function require_scope_target(string $scopetype, int $scopeid): void {
        global $DB;
        $exists = match ($scopetype) {
            self::SCOPE_CATALOG_COURSE => $DB->record_exists('local_outcomemap_course', ['id' => $scopeid]),
            self::SCOPE_COURSE_INSTANCE => $DB->record_exists('local_outcomemap_cinst', ['id' => $scopeid]),
            self::SCOPE_ASSESSMENT => $DB->record_exists('course_modules', ['id' => $scopeid]),
            default => false,
        };
        if (!$exists) {
            throw new validation_exception('recordnotfound', 'scopeid', $scopeid);
        }
    }

    /**
     * Resolve the authoritative context of a policy scope.
     *
     * @param \stdClass $record Policy record.
     * @return \context
     */
    private static function policy_context(\stdClass $record): \context {
        global $DB;
        if ($record->scopetype === self::SCOPE_ASSESSMENT) {
            return \context_module::instance((int) $record->scopeid, MUST_EXIST);
        }
        if ($record->scopetype === self::SCOPE_COURSE_INSTANCE) {
            $moodlecourseid = $DB->get_field('local_outcomemap_cinst', 'moodlecourseid',
                ['id' => $record->scopeid], MUST_EXIST);
            return \context_course::instance((int) $moodlecourseid, MUST_EXIST);
        }
        return \context_system::instance();
    }

    /**
     * Require the policy management capability in the policy's context.
     *
     * @param \stdClass $record Policy record.
     * @return int Acting user ID.
     */
    private static function require_policy_capability(\stdClass $record): int {
        global $USER;
        require_capability('local/outcomemap:managepolicies', self::policy_context($record));
        return (int) $USER->id;
    }

    /**
     * Require no approved overlapping policy at the same type and scope.
     *
     * @param \stdClass $candidate Candidate policy record.
     * @return void
     */
    private static function require_no_approved_overlap(\stdClass $candidate): void {
        global $DB;
        [$overlapsql, $params] = effective_dates::overlap_sql(
            '',
            (int) $candidate->effectivefrom,
            $candidate->effectiveto === null ? null : (int) $candidate->effectiveto,
            'policy'
        );
        $select = 'policytype = :policytype AND scopetype = :scopetype AND status = :status AND id <> :id AND '
            . $overlapsql;
        $params += [
            'policytype' => $candidate->policytype,
            'scopetype' => $candidate->scopetype,
            'status' => workflow::APPROVED,
            'id' => $candidate->id,
        ];
        if ($candidate->scopeid === null) {
            $select .= ' AND scopeid IS NULL';
        } else {
            $select .= ' AND scopeid = :scopeid';
            $params['scopeid'] = (int) $candidate->scopeid;
        }
        if ($DB->record_exists_select('local_outcomemap_policy', $select, $params)) {
            throw new validation_exception('effectiverangeoverlap', 'effectivefrom');
        }
    }
}
