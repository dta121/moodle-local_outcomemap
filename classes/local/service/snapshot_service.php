<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\canonical_json;
use local_outcomemap\local\decimal;
use local_outcomemap\local\input;
use local_outcomemap\local\privacy\subject_key_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Creates, verifies, freezes, and versions accreditation snapshots.
 *
 * A draft is a complete immutable capture. Freezing only verifies its row
 * hashes and adds approval or finalization metadata. Corrections always
 * recapture authoritative records into a new version under the same snapshot
 * UUID.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class snapshot_service extends base_service {
    /** Snapshot states. */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FROZEN = 'frozen';

    /** Snapshot payload algorithm. */
    public const ALGO_VERSION = 'outcomemap-accreditation-v1';

    /** Erasable protected subject-reference method for new snapshots. */
    public const SUBJECT_HASH_METHOD = 'hmac-sha256-subject-key-v2';

    /** Legacy site-secret method retained for immutable historical snapshots. */
    public const LEGACY_SUBJECT_HASH_METHOD = 'hmac-sha256-site-secret-v1';

    /** Snapshot item types. */
    public const ITEM_PROGRAM = 'program';
    public const ITEM_COHORT = 'cohort';
    public const ITEM_POPULATION = 'population_subject';
    public const ITEM_PROGRAM_COURSE = 'program_course';
    public const ITEM_COURSE_INSTANCE = 'course_instance';
    public const ITEM_OUTCOME_VERSION = 'outcome_version';
    public const ITEM_POLICY_VERSION = 'policy_version';
    public const ITEM_MAPPING_VERSION = 'mapping_version';
    public const ITEM_RELATION_VERSION = 'relation_version';
    public const ITEM_EVIDENCE = 'evidence';
    public const ITEM_RESULT = 'learner_result';
    public const ITEM_COURSE_AGGREGATE = 'course_aggregate';
    public const ITEM_PROGRAM_AGGREGATE = 'program_aggregate';

    /**
     * Create a complete draft snapshot or a correction version.
     *
     * @param array $data programid, periodcode, optional cohortid, notes,
     *     previousid, and correctionreason.
     * @return int Snapshot ID.
     */
    public static function create_draft(array $data): int {
        global $DB;

        $actorid = self::require_system('local/outcomemap:managesnapshots');
        $programid = input::positive_int($data['programid'] ?? 0, 'programid');
        $periodcode = input::required_text($data['periodcode'] ?? '', 'periodcode', 100);
        $previousid = empty($data['previousid']) ? null
            : input::positive_int($data['previousid'], 'previousid');
        $notes = input::optional_multiline($data['notes'] ?? null);
        $correctionreason = input::optional_multiline($data['correctionreason'] ?? null);
        $populationat = time();

        $transaction = $DB->start_delegated_transaction();
        try {
            $program = $DB->get_record('local_outcomemap_program', ['id' => $programid], '*', MUST_EXIST);
            if ($program->status !== workflow::APPROVED) {
                throw new validation_exception('invalidstatus', 'program', $program->status);
            }

            [$snapshotuuid, $version, $previous] = self::version_identity(
                $previousid,
                $programid,
                $periodcode,
                $correctionreason
            );
            $policy = suppression_service::resolve($programid, $populationat);
            if ($policy === null) {
                throw new validation_exception('snapshotpolicyrequired', 'programid', $programid);
            }
            $config = suppression_service::normalize_config((array) $policy->config);
            $cohortid = self::validate_cohort($config['populationsource'], $data['cohortid'] ?? null);
            $courses = aggregate_service::course_instances($programid, $periodcode, $populationat);
            if (!$courses) {
                throw new validation_exception('snapshotcoursesempty', 'periodcode', $periodcode);
            }
            $userids = self::population_userids($config['populationsource'], $courses, $cohortid);
            if (!$userids) {
                throw new validation_exception('snapshotpopulationempty', 'programid', $programid);
            }

            $subjectrefs = [];
            foreach ($userids as $userid) {
                $subjectrefs[$userid] = self::subject_reference($snapshotuuid, $userid);
            }
            $results = aggregate_service::load_results(array_keys($courses), $userids);
            $aggregates = aggregate_service::aggregate($results, $policy);
            $lineage = self::load_lineage_context($results, $policy);
            $items = self::build_items(
                $snapshotuuid,
                $program,
                $periodcode,
                $cohortid,
                $populationat,
                $courses,
                $userids,
                $subjectrefs,
                $results,
                $aggregates,
                $lineage
            );

            $pluginversion = get_config('local_outcomemap', 'version');
            if ($pluginversion === false || (string) $pluginversion === '') {
                throw new validation_exception('snapshotintegrityfailure', 'pluginversion', 'missing');
            }
            $now = time();
            $record = (object) [
                'snapshotuuid' => $snapshotuuid,
                'version' => $version,
                'previousid' => $previous === null ? null : (int) $previous->id,
                'programid' => $programid,
                'periodcode' => $periodcode,
                'cohortid' => $cohortid,
                'policyid' => (int) $policy->id,
                'status' => self::STATUS_DRAFT,
                'notes' => $notes,
                'correctionreason' => $correctionreason,
                'populationsource' => $config['populationsource'],
                'retentionbasis' => $config['retentionbasis'],
                'populationat' => $populationat,
                'populationcount' => count($userids),
                'suppressionthreshold' => $config['mincohortsize'],
                'subjecthashmethod' => self::SUBJECT_HASH_METHOD,
                'pluginversion' => (string) $pluginversion,
                'algoversion' => self::ALGO_VERSION,
                'payloadhash' => str_repeat('0', 64),
                'manifesthash' => null,
                'createdby' => $actorid,
                'approvedby' => null,
                'timecreated' => $now,
                'timemodified' => $now,
                'approvedat' => null,
            ];
            $id = $DB->insert_record('local_outcomemap_snapshot', $record);
            $record->id = $id;
            foreach ($items as $item) {
                $item->snapshotid = $id;
                $item->id = $DB->insert_record('local_outcomemap_snapitem', $item);
            }
            $record->payloadhash = self::payload_hash($items);
            $DB->update_record('local_outcomemap_snapshot', (object) [
                'id' => $id,
                'payloadhash' => $record->payloadhash,
            ]);
            audit_writer::write(
                $previous === null ? 'create_snapshot' : 'correct_snapshot',
                'snapshot',
                $id,
                $snapshotuuid,
                $previous,
                $record,
                $correctionreason,
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
     * Verify and irreversibly freeze a draft as an authorized finalizer.
     *
     * Independent approval requires the approval capability and a different
     * actor. When disabled, snapshot managers may freeze their own captures.
     *
     * @param int $snapshotid Snapshot ID.
     */
    public static function freeze(int $snapshotid): void {
        global $DB;

        $actorid = self::require_approval_system('local/outcomemap:managesnapshots');
        $transaction = $DB->start_delegated_transaction();
        try {
            $before = $DB->get_record('local_outcomemap_snapshot', ['id' => $snapshotid], '*', MUST_EXIST);
            if ($before->status !== self::STATUS_DRAFT) {
                throw new validation_exception('snapshotimmutable', 'status', $before->status);
            }
            workflow::require_approver_separation((int) $before->createdby, $actorid);
            $items = self::load_items($snapshotid);
            audit_lineage_service::verify_snapshot_payload($before, $items);

            $after = clone $before;
            $after->status = self::STATUS_FROZEN;
            $after->approvedby = $actorid;
            $after->approvedat = time();
            $after->timemodified = $after->approvedat;
            $after->manifesthash = hash('sha256', canonical_json::encode(
                audit_lineage_service::manifest($after, count($items))
            ));
            $DB->update_record('local_outcomemap_snapshot', $after);
            audit_lineage_service::verify_manifest($after, count($items));
            audit_writer::write(
                'freeze_snapshot',
                'snapshot',
                $snapshotid,
                $after->snapshotuuid,
                $before,
                $after,
                null,
                \context_system::instance(),
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Withdraw one snapshot version and every row it captured.
     *
     * Freezing makes a version unchangeable, not undeletable. A capture taken
     * against the wrong period or population, or taken only to demonstrate the
     * workflow, has to be withdrawable, and correcting such a capture would
     * assert that it once reported something the institution stands behind.
     * Withdrawal therefore removes the whole version instead of editing it, and
     * only from the end of a lineage, so every correction chain that remains
     * still verifies from its own rows. The audit event keeps the withdrawn
     * version's identity and hashes.
     *
     * @param int $snapshotid Snapshot ID.
     * @param string|null $reason Optional audit reason.
     * @return void
     */
    public static function delete(int $snapshotid, ?string $reason = null): void {
        global $DB;

        $actorid = self::require_system('local/outcomemap:managesnapshots');
        $transaction = $DB->start_delegated_transaction();
        try {
            $before = self::get_required('local_outcomemap_snapshot', $snapshotid, 'snapshot');
            if ($DB->record_exists('local_outcomemap_snapshot', ['previousid' => $snapshotid])) {
                throw new validation_exception('snapshotdeletesuperseded', 'id', $snapshotid);
            }
            $DB->delete_records('local_outcomemap_snapitem', ['snapshotid' => $snapshotid]);
            $DB->delete_records('local_outcomemap_snapshot', ['id' => $snapshotid]);
            audit_writer::write(
                'delete_snapshot',
                'snapshot',
                $snapshotid,
                $before->snapshotuuid,
                $before,
                null,
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
     * Load one snapshot for its management page.
     *
     * @param int $snapshotid Snapshot ID.
     * @return \stdClass
     */
    public static function get(int $snapshotid): \stdClass {
        self::require_system('local/outcomemap:managesnapshots');
        return self::get_required('local_outcomemap_snapshot', $snapshotid, 'snapshot');
    }

    /**
     * Load one snapshot with the program metadata and row count a listing shows.
     *
     * @param int $snapshotid Snapshot ID.
     * @return \stdClass
     */
    public static function summary(int $snapshotid): \stdClass {
        self::require_system('local/outcomemap:managesnapshots');
        $records = self::list_records($snapshotid);
        if (!$records) {
            throw new validation_exception('recordnotfound', 'snapshot', $snapshotid);
        }
        return reset($records);
    }

    /**
     * List all snapshot versions with program metadata and item counts.
     *
     * @return \stdClass[]
     */
    public static function list_all(): array {
        self::require_system('local/outcomemap:managesnapshots');
        return array_values(self::list_records(null));
    }

    /**
     * Query snapshot versions with the metadata a listing or confirmation needs.
     *
     * @param int|null $snapshotid One snapshot ID, or null for every version.
     * @return \stdClass[] Records keyed by snapshot ID.
     */
    private static function list_records(?int $snapshotid): array {
        global $DB;
        $params = [];
        $where = '';
        if ($snapshotid !== null) {
            $where = ' WHERE s.id = :snapshotid';
            $params['snapshotid'] = $snapshotid;
        }
        $sql = "SELECT s.*, p.code AS programcode, p.name AS programname,
                       (SELECT COUNT(si.id)
                          FROM {local_outcomemap_snapitem} si
                         WHERE si.snapshotid = s.id) AS itemcount
                  FROM {local_outcomemap_snapshot} s
                  JOIN {local_outcomemap_program} p ON p.id = s.programid
               {$where}
              ORDER BY s.timecreated DESC, s.snapshotuuid, s.version DESC";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Load snapshot items in their immutable hash order.
     *
     * @param int $snapshotid Snapshot ID.
     * @return \stdClass[]
     */
    public static function items(int $snapshotid): array {
        self::require_system('local/outcomemap:managesnapshots');
        self::get_required('local_outcomemap_snapshot', $snapshotid, 'snapshot');
        return self::load_items($snapshotid);
    }

    /**
     * Verify stored payload and, when frozen, final manifest hashes.
     *
     * The caller must enforce the capability appropriate to its operation.
     *
     * @param \stdClass $snapshot Snapshot record.
     * @param \stdClass[] $items Ordered snapshot items.
     */
    public static function verify(\stdClass $snapshot, array $items): void {
        audit_lineage_service::verify_snapshot_payload($snapshot, $items);
        if ($snapshot->status === self::STATUS_FROZEN) {
            audit_lineage_service::verify_manifest($snapshot, count($items));
        }
    }

    /**
     * Produce an erasable snapshot-specific reference for a Moodle user.
     *
     * New snapshots use random per-user key material. Privacy erasure forgets
     * that key without changing immutable frozen rows or their hashes.
     *
     * @param string $snapshotuuid Snapshot UUID.
     * @param int $userid Moodle user ID.
     * @return string
     */
    public static function subject_reference(string $snapshotuuid, int $userid): string {
        $reference = subject_key_service::reference($snapshotuuid, $userid, true);
        if ($reference === null) {
            throw new validation_exception('invalidfield', 'userid', $userid);
        }
        return $reference;
    }

    /**
     * Resolve an existing subject reference without issuing new key material.
     *
     * @param string $snapshotuuid Snapshot UUID.
     * @param int $userid Moodle user ID.
     * @param string $method Subject hash method frozen with the snapshot.
     * @return string|null Existing reference, or null after privacy erasure.
     */
    public static function subject_reference_for_lookup(
        string $snapshotuuid,
        int $userid,
        string $method
    ): ?string {
        if ($method === self::SUBJECT_HASH_METHOD) {
            return subject_key_service::reference($snapshotuuid, $userid, false);
        }
        if ($method === self::LEGACY_SUBJECT_HASH_METHOD) {
            return subject_key_service::legacy_reference($snapshotuuid, $userid);
        }
        return null;
    }

    /**
     * Resolve references for many snapshots with one key/marker lookup.
     *
     * @param \stdClass[] $snapshots Records containing id, snapshotuuid, and subjecthashmethod.
     * @param int $userid Moodle user ID.
     * @return array<int,string> Resolvable subject references keyed by snapshot ID.
     */
    public static function subject_references_for_lookup(array $snapshots, int $userid): array {
        $active = [];
        $legacy = [];
        foreach ($snapshots as $snapshot) {
            if ((string) $snapshot->subjecthashmethod === self::SUBJECT_HASH_METHOD) {
                $active[(int) $snapshot->id] = (string) $snapshot->snapshotuuid;
            } else if ((string) $snapshot->subjecthashmethod === self::LEGACY_SUBJECT_HASH_METHOD) {
                $legacy[(int) $snapshot->id] = (string) $snapshot->snapshotuuid;
            }
        }
        return subject_key_service::references_for_lookup($active, $legacy, $userid);
    }

    /**
     * Resolve snapshot UUID/version and validate correction linkage.
     *
     * @param int|null $previousid Previous frozen snapshot ID.
     * @param int $programid Program ID.
     * @param string $periodcode Reporting period.
     * @param string|null $reason Correction reason.
     * @return array{0:string,1:int,2:?\stdClass}
     */
    private static function version_identity(
        ?int $previousid,
        int $programid,
        string $periodcode,
        ?string $reason
    ): array {
        global $DB;
        if ($previousid === null) {
            return [uuid::generate(), 1, null];
        }
        $previous = $DB->get_record('local_outcomemap_snapshot', ['id' => $previousid], '*', MUST_EXIST);
        if ($previous->status !== self::STATUS_FROZEN
                || (int) $previous->programid !== $programid
                || (string) $previous->periodcode !== $periodcode) {
            throw new validation_exception('snapshotpreviousinvalid', 'previousid', $previousid);
        }
        if ($reason === null || trim($reason) === '') {
            throw new validation_exception('snapshotcorrectionrequired', 'correctionreason', '');
        }
        $maxversion = (int) $DB->get_field_sql(
            'SELECT MAX(version) FROM {local_outcomemap_snapshot} WHERE snapshotuuid = :uuid',
            ['uuid' => $previous->snapshotuuid]
        );
        if ($maxversion !== (int) $previous->version) {
            throw new validation_exception('snapshotversionconflict', 'previousid', $previousid);
        }
        return [(string) $previous->snapshotuuid, $maxversion + 1, $previous];
    }

    /**
     * Validate population-source/cohort consistency.
     *
     * @param string $source Governed population source.
     * @param mixed $submittedcohortid Submitted cohort ID.
     * @return int|null
     */
    private static function validate_cohort(string $source, $submittedcohortid): ?int {
        global $DB;
        $cohortid = empty($submittedcohortid) ? null
            : input::positive_int($submittedcohortid, 'cohortid');
        if ($source === suppression_service::POPULATION_MOODLE_COHORT) {
            if ($cohortid === null) {
                throw new validation_exception('snapshotcohortrequired', 'cohortid', '');
            }
            if (!$DB->record_exists('cohort', ['id' => $cohortid])) {
                throw new validation_exception('recordnotfound', 'cohortid', $cohortid);
            }
            return $cohortid;
        }
        if ($cohortid !== null) {
            throw new validation_exception('snapshotcohortnotallowed', 'cohortid', $cohortid);
        }
        return null;
    }

    /**
     * Capture the governed population at the data-freeze timestamp.
     *
     * @param string $source Population source.
     * @param \stdClass[] $courses Included course instances.
     * @param int|null $cohortid Cohort ID.
     * @return int[] Sorted distinct user IDs, used only during capture.
     */
    private static function population_userids(string $source, array $courses, ?int $cohortid): array {
        global $DB;
        $userids = [];
        if ($source === suppression_service::POPULATION_MOODLE_COHORT) {
            $sql = "SELECT cm.userid
                      FROM {cohort_members} cm
                      JOIN {user} u ON u.id = cm.userid
                     WHERE cm.cohortid = :cohortid AND u.deleted = 0
                  ORDER BY cm.userid";
            foreach ($DB->get_fieldset_sql($sql, ['cohortid' => $cohortid]) as $userid) {
                $userids[(int) $userid] = (int) $userid;
            }
        } else {
            $courseids = [];
            foreach ($courses as $course) {
                $courseids[(int) $course->moodlecourseid] = true;
            }
            foreach (array_keys($courseids) as $courseid) {
                $context = \context_course::instance($courseid, MUST_EXIST);
                [$enrolledsql, $params] = get_enrolled_sql($context, '', 0, true);
                foreach ($DB->get_fieldset_sql($enrolledsql, $params) as $userid) {
                    $userids[(int) $userid] = (int) $userid;
                }
            }
        }
        ksort($userids, SORT_NUMERIC);
        return array_values($userids);
    }

    /**
     * Bulk-load and verify evidence, mapping, relation, and policy lineage.
     *
     * @param \stdClass[] $results Captured result records.
     * @param \stdClass $accreditationpolicy Accreditation policy.
     * @return array
     */
    private static function load_lineage_context(array $results, \stdClass $accreditationpolicy): array {
        $evidenceuuids = [];
        $decodedlineage = [];
        foreach ($results as $result) {
            if (!hash_equals((string) $result->lineagehash, hash('sha256', (string) $result->lineagejson))) {
                throw new validation_exception('resultintegrityfailure', 'result', $result->id);
            }
            $entries = json_decode($result->lineagejson, true);
            if (!is_array($entries)) {
                throw new validation_exception('resultintegrityfailure', 'lineage', $result->id);
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || empty($entry['uuid'])) {
                    throw new validation_exception('resultintegrityfailure', 'lineage', $result->id);
                }
                $evidenceuuids[(string) $entry['uuid']] = (string) $entry['uuid'];
            }
            $decodedlineage[(int) $result->id] = $entries;
        }
        $evidence = self::records_by_field('local_outcomemap_evidence', 'uuid', array_values($evidenceuuids));
        if (count($evidence) !== count($evidenceuuids)) {
            throw new validation_exception('resultintegrityfailure', 'evidence', 'missing');
        }
        $evidencebyuuid = [];
        $mappingids = [];
        $policyids = [(int) $accreditationpolicy->id => (int) $accreditationpolicy->id];
        $relationids = [];
        foreach ($evidence as $row) {
            $evidencebyuuid[(string) $row->uuid] = $row;
            $mappingids[(int) $row->mappingid] = (int) $row->mappingid;
            $policyids[(int) $row->policyid] = (int) $row->policyid;
            if ($row->relationpathjson !== null) {
                $path = json_decode($row->relationpathjson, true);
                if (!is_array($path)) {
                    throw new validation_exception('resultintegrityfailure', 'relationpath', $row->id);
                }
                foreach ($path as $relationid) {
                    $relationids[(int) $relationid] = (int) $relationid;
                }
            }
        }
        foreach ($results as $result) {
            $policyids[(int) $result->policyid] = (int) $result->policyid;
        }
        return [
            'decodedlineage' => $decodedlineage,
            'evidence' => $evidencebyuuid,
            'mappings' => self::records_by_field('local_outcomemap_qmap', 'id', array_values($mappingids)),
            'policies' => self::records_by_field('local_outcomemap_policy', 'id', array_values($policyids)),
            'relations' => self::records_by_field('local_outcomemap_rel', 'id', array_values($relationids)),
        ];
    }

    /**
     * Build every canonical snapshot item in deterministic type/key order.
     *
     * @param string $snapshotuuid Snapshot UUID.
     * @param \stdClass $program Program record.
     * @param string $periodcode Reporting period.
     * @param int|null $cohortid Cohort ID.
     * @param int $populationat Data-freeze timestamp.
     * @param \stdClass[] $courses Course instances.
     * @param int[] $userids Population IDs, never written to payloads.
     * @param array<int,string> $subjectrefs Subject references keyed by user ID.
     * @param \stdClass[] $results Results.
     * @param array $aggregates Course and program aggregate rows.
     * @param array $lineage Bulk-loaded lineage context.
     * @return \stdClass[]
     */
    private static function build_items(
        string $snapshotuuid,
        \stdClass $program,
        string $periodcode,
        ?int $cohortid,
        int $populationat,
        array $courses,
        array $userids,
        array $subjectrefs,
        array $results,
        array $aggregates,
        array $lineage
    ): array {
        global $DB;
        $groups = [];
        $groups[self::ITEM_PROGRAM]['program:' . $program->uuid] = self::make_item(
            self::ITEM_PROGRAM,
            ['programuuid' => (string) $program->uuid],
            [
                'id' => (int) $program->id,
                'uuid' => (string) $program->uuid,
                'code' => (string) $program->code,
                'name' => (string) $program->name,
                'programtype' => (string) $program->programtype,
                'credential' => (string) $program->credential,
                'status' => (string) $program->status,
                'periodcode' => $periodcode,
            ],
            ['sourceuuid' => (string) $program->uuid, 'sourceid' => (int) $program->id]
        );
        if ($cohortid !== null) {
            $cohort = $DB->get_record('cohort', ['id' => $cohortid], '*', MUST_EXIST);
            $groups[self::ITEM_COHORT]['cohort:' . $cohortid] = self::make_item(
                self::ITEM_COHORT,
                ['cohortid' => $cohortid],
                [
                    'id' => $cohortid,
                    'idnumber' => (string) $cohort->idnumber,
                    'name' => (string) $cohort->name,
                    'contextid' => (int) $cohort->contextid,
                ],
                ['sourceid' => $cohortid]
            );
        }
        foreach ($userids as $userid) {
            $subjectref = $subjectrefs[$userid];
            $groups[self::ITEM_POPULATION][$subjectref] = self::make_item(
                self::ITEM_POPULATION,
                ['subjectref' => $subjectref],
                [
                    'subjectref' => $subjectref,
                    'populationat' => $populationat,
                ],
                ['subjectref' => $subjectref, 'subjectcount' => 1]
            );
        }
        foreach ($courses as $course) {
            $groups[self::ITEM_PROGRAM_COURSE][(string) $course->membershipuuid] = self::make_item(
                self::ITEM_PROGRAM_COURSE,
                ['membershipuuid' => (string) $course->membershipuuid],
                [
                    'membershipuuid' => (string) $course->membershipuuid,
                    'programid' => (int) $program->id,
                    'catalogcourseid' => (int) $course->courseid,
                    'courseuuid' => (string) $course->courseuuid,
                    'coursecode' => (string) $course->coursecode,
                    'periodcode' => (string) $course->periodcode,
                ],
                ['sourceuuid' => (string) $course->membershipuuid]
            );
            $groups[self::ITEM_COURSE_INSTANCE][(string) $course->uuid] = self::make_item(
                self::ITEM_COURSE_INSTANCE,
                ['courseinstanceuuid' => (string) $course->uuid],
                [
                    'id' => (int) $course->id,
                    'uuid' => (string) $course->uuid,
                    'catalogcourseid' => (int) $course->courseid,
                    'courseuuid' => (string) $course->courseuuid,
                    'coursecode' => (string) $course->coursecode,
                    'coursename' => (string) $course->coursename,
                    'moodlecourseid' => (int) $course->moodlecourseid,
                    'moodlecoursename' => (string) $course->moodlecoursename,
                    'periodcode' => (string) $course->periodcode,
                    'status' => (string) $course->status,
                    'confirmed' => (int) $course->confirmed,
                ],
                [
                    'sourceuuid' => (string) $course->uuid,
                    'sourceid' => (int) $course->id,
                    'cinstid' => (int) $course->id,
                ]
            );
        }

        $programsuppression = [];
        foreach ($aggregates['program'] as $aggregate) {
            $programsuppression[(int) $aggregate['itemverid']] = !empty($aggregate['suppressed']);
        }
        foreach ($results as $result) {
            $groups[self::ITEM_OUTCOME_VERSION][(string) $result->outcomeversionuuid] = self::make_item(
                self::ITEM_OUTCOME_VERSION,
                ['outcomeversionuuid' => (string) $result->outcomeversionuuid],
                [
                    'id' => (int) $result->itemverid,
                    'uuid' => (string) $result->outcomeversionuuid,
                    'version' => (int) $result->outcomeversion,
                    'outcomeuuid' => (string) $result->outcomeuuid,
                    'code' => (string) $result->outcomecode,
                    'statement' => (string) $result->outcomestatement,
                    'frameworkuuid' => (string) $result->frameworkuuid,
                    'frameworkcode' => (string) $result->frameworkcode,
                ],
                [
                    'sourceuuid' => (string) $result->outcomeversionuuid,
                    'sourceid' => (int) $result->itemverid,
                    'itemverid' => (int) $result->itemverid,
                ]
            );
        }
        foreach ($lineage['policies'] as $policy) {
            $groups[self::ITEM_POLICY_VERSION][$policy->policyuuid . ':' . $policy->version] = self::make_item(
                self::ITEM_POLICY_VERSION,
                ['policyuuid' => (string) $policy->policyuuid, 'version' => (int) $policy->version],
                [
                    'id' => (int) $policy->id,
                    'policyuuid' => (string) $policy->policyuuid,
                    'version' => (int) $policy->version,
                    'policytype' => (string) $policy->policytype,
                    'scopetype' => (string) $policy->scopetype,
                    'scopeid' => $policy->scopeid === null ? null : (int) $policy->scopeid,
                    'name' => (string) $policy->name,
                    'config' => json_decode($policy->configjson, true),
                    'confighash' => (string) $policy->confighash,
                    'status' => (string) $policy->status,
                    'effectivefrom' => (int) $policy->effectivefrom,
                    'effectiveto' => $policy->effectiveto === null ? null : (int) $policy->effectiveto,
                ],
                ['sourceuuid' => (string) $policy->policyuuid, 'sourceid' => (int) $policy->id]
            );
        }
        foreach ($lineage['mappings'] as $mapping) {
            $groups[self::ITEM_MAPPING_VERSION][$mapping->mappinguuid . ':' . $mapping->version] = self::make_item(
                self::ITEM_MAPPING_VERSION,
                ['mappinguuid' => (string) $mapping->mappinguuid, 'version' => (int) $mapping->version],
                [
                    'id' => (int) $mapping->id,
                    'mappinguuid' => (string) $mapping->mappinguuid,
                    'version' => (int) $mapping->version,
                    'questionversionid' => (int) $mapping->questionversionid,
                    'questionid' => (int) $mapping->questionid,
                    'itemverid' => (int) $mapping->itemverid,
                    'role' => (string) $mapping->role,
                    'weight' => $mapping->weight === null ? null : decimal::canonical($mapping->weight, 'weight'),
                    'status' => (string) $mapping->status,
                    'effectivefrom' => (int) $mapping->effectivefrom,
                    'effectiveto' => $mapping->effectiveto === null ? null : (int) $mapping->effectiveto,
                ],
                [
                    'sourceuuid' => (string) $mapping->mappinguuid,
                    'sourceid' => (int) $mapping->id,
                    'itemverid' => (int) $mapping->itemverid,
                ]
            );
        }
        foreach ($lineage['relations'] as $relation) {
            $groups[self::ITEM_RELATION_VERSION][$relation->relationuuid . ':' . $relation->version] = self::make_item(
                self::ITEM_RELATION_VERSION,
                ['relationuuid' => (string) $relation->relationuuid, 'version' => (int) $relation->version],
                [
                    'id' => (int) $relation->id,
                    'relationuuid' => (string) $relation->relationuuid,
                    'version' => (int) $relation->version,
                    'sourceitemid' => (int) $relation->sourceitemid,
                    'targetitemid' => (int) $relation->targetitemid,
                    'type' => (string) $relation->type,
                    'weight' => $relation->weight === null ? null
                        : decimal::canonical($relation->weight, 'weight'),
                    'status' => (string) $relation->status,
                    'effectivefrom' => (int) $relation->effectivefrom,
                    'effectiveto' => $relation->effectiveto === null ? null : (int) $relation->effectiveto,
                ],
                ['sourceuuid' => (string) $relation->relationuuid, 'sourceid' => (int) $relation->id]
            );
        }

        foreach ($lineage['evidence'] as $evidence) {
            $subjectref = $subjectrefs[(int) $evidence->userid] ?? null;
            if ($subjectref === null) {
                throw new validation_exception('resultintegrityfailure', 'population', $evidence->id);
            }
            $mapping = $lineage['mappings'][(int) $evidence->mappingid] ?? null;
            $selection = $lineage['policies'][(int) $evidence->policyid] ?? null;
            if ($mapping === null || $selection === null) {
                throw new validation_exception('resultintegrityfailure', 'versions', $evidence->id);
            }
            $relationpath = [];
            foreach ((array) json_decode($evidence->relationpathjson ?? '[]', true) as $relationid) {
                $relation = $lineage['relations'][(int) $relationid] ?? null;
                if ($relation === null) {
                    throw new validation_exception('resultintegrityfailure', 'relation', $relationid);
                }
                $relationpath[] = [
                    'id' => (int) $relation->id,
                    'uuid' => (string) $relation->relationuuid,
                    'version' => (int) $relation->version,
                ];
            }
            $suppressed = !empty($programsuppression[(int) $evidence->itemverid]);
            $payload = [
                'id' => (int) $evidence->id,
                'uuid' => (string) $evidence->uuid,
                'lineageuuid' => (string) $evidence->lineageuuid,
                'sourceevidenceid' => $evidence->sourceevidenceid === null
                    ? null : (int) $evidence->sourceevidenceid,
                'subjectref' => $subjectref,
                'cinstid' => (int) $evidence->cinstid,
                'assessmentcmid' => (int) $evidence->assessmentcmid,
                'quizattemptid' => (int) $evidence->quizattemptid,
                'questionusageid' => (int) $evidence->questionusageid,
                'slot' => (int) $evidence->slot,
                'questionattemptid' => (int) $evidence->questionattemptid,
                'questionversionid' => (int) $evidence->questionversionid,
                'questionid' => (int) $evidence->questionid,
                'itemverid' => (int) $evidence->itemverid,
                'mapping' => [
                    'id' => (int) $mapping->id,
                    'uuid' => (string) $mapping->mappinguuid,
                    'version' => (int) $mapping->version,
                ],
                'selectionpolicy' => [
                    'id' => (int) $selection->id,
                    'uuid' => (string) $selection->policyuuid,
                    'version' => (int) $selection->version,
                    'confighash' => (string) $selection->confighash,
                ],
                'evidencetype' => (string) $evidence->evidencetype,
                'weightedearned' => $evidence->weightedearned === null ? null
                    : decimal::canonical($evidence->weightedearned, 'weightedearned'),
                'weightedpossible' => decimal::canonical($evidence->weightedpossible, 'weightedpossible'),
                'mappingweight' => decimal::canonical($evidence->mappingweight, 'mappingweight'),
                'relationweight' => decimal::canonical($evidence->relationweight, 'relationweight'),
                'relationpath' => $relationpath,
                'gradingstate' => (string) $evidence->gradingstate,
                'attempttime' => (int) $evidence->attempttime,
                'gradingtime' => $evidence->gradingtime === null ? null : (int) $evidence->gradingtime,
                'suppressed' => $suppressed,
            ];
            $groups[self::ITEM_EVIDENCE][(string) $evidence->uuid] = self::make_item(
                self::ITEM_EVIDENCE,
                ['evidenceuuid' => (string) $evidence->uuid],
                $payload,
                [
                    'subjectref' => $subjectref,
                    'sourceuuid' => (string) $evidence->uuid,
                    'sourceid' => (int) $evidence->id,
                    'cinstid' => (int) $evidence->cinstid,
                    'itemverid' => (int) $evidence->itemverid,
                    'state' => (string) $evidence->gradingstate,
                    'numerator' => $evidence->weightedearned ?? decimal::ZERO,
                    'denominator' => $evidence->weightedpossible,
                    'subjectcount' => 1,
                    'suppressed' => $suppressed,
                ]
            );
        }
        foreach ($results as $result) {
            $subjectref = $subjectrefs[(int) $result->userid] ?? null;
            if ($subjectref === null) {
                throw new validation_exception('resultintegrityfailure', 'population', $result->id);
            }
            $entries = [];
            foreach ($lineage['decodedlineage'][(int) $result->id] as $entry) {
                $evidence = $lineage['evidence'][(string) $entry['uuid']] ?? null;
                if ($evidence === null) {
                    throw new validation_exception('resultintegrityfailure', 'evidence', $entry['uuid']);
                }
                $entries[] = [
                    'evidenceuuid' => (string) $evidence->uuid,
                    'lineageuuid' => (string) $evidence->lineageuuid,
                    'type' => (string) $evidence->evidencetype,
                    'earned' => $evidence->weightedearned === null ? null
                        : decimal::canonical($evidence->weightedearned, 'weightedearned'),
                    'possible' => decimal::canonical($evidence->weightedpossible, 'weightedpossible'),
                ];
            }
            $suppressed = !empty($programsuppression[(int) $result->itemverid]);
            $payload = [
                'id' => (int) $result->id,
                'uuid' => (string) $result->uuid,
                'version' => (int) $result->version,
                'subjectref' => $subjectref,
                'cinstid' => (int) $result->cinstid,
                'cinstuuid' => (string) $result->cinstuuid,
                'courseuuid' => (string) $result->courseuuid,
                'coursecode' => (string) $result->coursecode,
                'periodcode' => (string) $result->periodcode,
                'scopetype' => (string) $result->scopetype,
                'scopeid' => $result->scopeid === null ? null : (int) $result->scopeid,
                'itemverid' => (int) $result->itemverid,
                'outcomeversionuuid' => (string) $result->outcomeversionuuid,
                'calculationpolicy' => [
                    'id' => (int) $result->policyid,
                    'uuid' => (string) $result->policyuuid,
                    'version' => (int) $result->policyversion,
                    'confighash' => (string) $result->policyconfighash,
                ],
                'numerator' => decimal::canonical($result->numerator, 'numerator'),
                'denominator' => decimal::canonical($result->denominator, 'denominator'),
                'percentage' => $result->percentage === null ? null
                    : decimal::canonical($result->percentage, 'percentage'),
                'state' => (string) $result->state,
                'bandcode' => $result->bandcode === null ? null : (string) $result->bandcode,
                'algoversion' => (string) $result->algoversion,
                'inputhash' => (string) $result->inputhash,
                'lineagehash' => (string) $result->lineagehash,
                'evidence' => $entries,
                'suppressed' => $suppressed,
            ];
            $groups[self::ITEM_RESULT][(string) $result->uuid] = self::make_item(
                self::ITEM_RESULT,
                ['resultuuid' => (string) $result->uuid],
                $payload,
                [
                    'subjectref' => $subjectref,
                    'sourceuuid' => (string) $result->uuid,
                    'sourceid' => (int) $result->id,
                    'cinstid' => (int) $result->cinstid,
                    'itemverid' => (int) $result->itemverid,
                    'state' => (string) $result->state,
                    'bandcode' => $result->bandcode,
                    'numerator' => $result->numerator,
                    'denominator' => $result->denominator,
                    'percentage' => $result->percentage,
                    'subjectcount' => 1,
                    'suppressed' => $suppressed,
                ]
            );
        }
        foreach ($aggregates['course'] as $aggregate) {
            $identity = $aggregate['cinstuuid'] . ':' . $aggregate['outcomeversionuuid'];
            $groups[self::ITEM_COURSE_AGGREGATE][$identity] = self::aggregate_item(
                self::ITEM_COURSE_AGGREGATE,
                $identity,
                $aggregate,
                (int) $aggregate['cinstid']
            );
        }
        foreach ($aggregates['program'] as $aggregate) {
            $identity = $program->uuid . ':' . $periodcode . ':' . $aggregate['outcomeversionuuid'];
            $aggregate['programid'] = (int) $program->id;
            $aggregate['programuuid'] = (string) $program->uuid;
            $groups[self::ITEM_PROGRAM_AGGREGATE][$identity] = self::aggregate_item(
                self::ITEM_PROGRAM_AGGREGATE,
                $identity,
                $aggregate,
                null
            );
        }

        $items = [];
        $sortorder = 0;
        foreach ($groups as $group) {
            ksort($group, SORT_STRING);
            foreach ($group as $item) {
                $item->sortorder = $sortorder++;
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * Build one aggregate snapshot item.
     *
     * @param string $type Item type.
     * @param string $identity Stable aggregate identity.
     * @param array $aggregate Aggregate payload.
     * @param int|null $cinstid Course-instance ID.
     * @return \stdClass
     */
    private static function aggregate_item(
        string $type,
        string $identity,
        array $aggregate,
        ?int $cinstid
    ): \stdClass {
        return self::make_item(
            $type,
            ['aggregate' => $identity],
            $aggregate,
            [
                'cinstid' => $cinstid,
                'itemverid' => (int) $aggregate['itemverid'],
                'state' => $aggregate['percentage'] === null ? 'not_calculated' : calculation_service::STATE_CALCULATED,
                'numerator' => $aggregate['numerator'],
                'denominator' => $aggregate['denominator'],
                'percentage' => $aggregate['percentage'],
                'subjectcount' => (int) $aggregate['subjectcount'],
                'suppressed' => !empty($aggregate['suppressed']),
            ]
        );
    }

    /**
     * Build a canonical item record without snapshot ID and sort order.
     *
     * @param string $type Item type.
     * @param array $identity Stable identity used to derive the key.
     * @param array $payload Canonical payload.
     * @param array $columns Indexed reporting columns.
     * @return \stdClass
     */
    private static function make_item(string $type, array $identity, array $payload, array $columns = []): \stdClass {
        $index = [
            'subjectref' => $columns['subjectref'] ?? null,
            'sourceuuid' => $columns['sourceuuid'] ?? null,
            'sourceid' => isset($columns['sourceid']) ? (int) $columns['sourceid'] : null,
            'cinstid' => isset($columns['cinstid']) ? (int) $columns['cinstid'] : null,
            'itemverid' => isset($columns['itemverid']) ? (int) $columns['itemverid'] : null,
            'state' => $columns['state'] ?? null,
            'bandcode' => $columns['bandcode'] ?? null,
            'numerator' => decimal::canonical($columns['numerator'] ?? decimal::ZERO, 'numerator'),
            'denominator' => decimal::canonical($columns['denominator'] ?? decimal::ZERO, 'denominator'),
            'percentage' => !array_key_exists('percentage', $columns) || $columns['percentage'] === null
                ? null : decimal::canonical($columns['percentage'], 'percentage'),
            'subjectcount' => (int) ($columns['subjectcount'] ?? 0),
            'suppressed' => empty($columns['suppressed']) ? 0 : 1,
        ];
        $payloadjson = canonical_json::encode([
            'type' => $type,
            'identity' => $identity,
            'index' => $index,
            'payload' => $payload,
        ]);
        return (object) [
            'snapshotid' => 0,
            'itemtype' => $type,
            'stablekey' => hash('sha256', canonical_json::encode(['type' => $type, 'identity' => $identity])),
            'subjectref' => $index['subjectref'],
            'sourceuuid' => $index['sourceuuid'],
            'sourceid' => $index['sourceid'],
            'cinstid' => $index['cinstid'],
            'itemverid' => $index['itemverid'],
            'state' => $index['state'],
            'bandcode' => $index['bandcode'],
            'numerator' => $index['numerator'],
            'denominator' => $index['denominator'],
            'percentage' => $index['percentage'],
            'subjectcount' => $index['subjectcount'],
            'suppressed' => $index['suppressed'],
            'payloadjson' => $payloadjson,
            'payloadhash' => hash('sha256', $payloadjson),
            'sortorder' => 0,
        ];
    }

    /**
     * Calculate the ordered snapshot payload hash.
     *
     * @param \stdClass[] $items Ordered items.
     * @return string
     */
    private static function payload_hash(array $items): string {
        $hashes = [];
        foreach ($items as $item) {
            $hashes[] = ['key' => (string) $item->stablekey, 'hash' => (string) $item->payloadhash];
        }
        return hash('sha256', canonical_json::encode($hashes));
    }

    /**
     * Load records in bounded chunks and key them by the requested field.
     *
     * @param string $table Moodle table name.
     * @param string $field Lookup/key field.
     * @param array $values Values.
     * @return array
     */
    private static function records_by_field(string $table, string $field, array $values): array {
        global $DB;
        $records = [];
        foreach (array_chunk(array_values(array_unique($values, SORT_REGULAR)), 500) as $chunk) {
            foreach ($DB->get_records_list($table, $field, $chunk) as $record) {
                $key = is_numeric($record->{$field}) ? (int) $record->{$field} : (string) $record->{$field};
                $records[$key] = $record;
            }
        }
        return $records;
    }

    /**
     * Load ordered items without a capability check for internal verification.
     *
     * @param int $snapshotid Snapshot ID.
     * @return \stdClass[]
     */
    private static function load_items(int $snapshotid): array {
        global $DB;
        return array_values($DB->get_records('local_outcomemap_snapitem',
            ['snapshotid' => $snapshotid], 'sortorder ASC, id ASC'));
    }
}
