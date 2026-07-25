<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_outcomemap\local\service\snapshot_service;

/**
 * Privacy export, deletion, and governed snapshot de-linking.
 *
 * Mutable learner evidence, results, and engagement are deleted. Mutable
 * governance attribution is cleared, while append-only audit actor/reason data
 * and immutable snapshot creator/approver metadata remain institutional
 * records. Snapshot rows are never rewritten; erasure destroys the per-user
 * key that made their pseudonymous subject references linkable.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_data_service {
    /** Component name used by the Privacy API. */
    public const COMPONENT = 'local_outcomemap';

    /**
     * Governed tables and nullable user-attribution columns.
     *
     * @return array<string,string[]>
     */
    public static function governance_tables(): array {
        return [
            'local_outcomemap_program' => ['createdby', 'modifiedby'],
            'local_outcomemap_course' => ['createdby', 'modifiedby'],
            'local_outcomemap_cinst' => ['createdby', 'modifiedby', 'confirmedby'],
            'local_outcomemap_progcourse' => ['createdby', 'approvedby'],
            'local_outcomemap_fw' => ['createdby', 'modifiedby'],
            'local_outcomemap_item' => ['createdby'],
            'local_outcomemap_itemver' => ['createdby', 'approvedby'],
            'local_outcomemap_rel' => ['createdby', 'approvedby'],
            'local_outcomemap_cmmap' => ['createdby', 'approvedby'],
            'local_outcomemap_secmap' => ['createdby', 'approvedby'],
            'local_outcomemap_qmap' => ['createdby', 'approvedby'],
            'local_outcomemap_policy' => ['createdby', 'approvedby'],
            'local_outcomemap_remed' => ['createdby', 'approvedby'],
            'local_outcomemap_snapshot' => ['createdby', 'approvedby'],
        ];
    }

    /**
     * Add every context containing data for one user.
     *
     * @param contextlist $contextlist Context collector.
     * @param int $userid Moodle user ID.
     * @return void
     */
    public static function add_contexts_for_user(contextlist $contextlist, int $userid): void {
        global $DB;

        $params = ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid];
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_outcomemap_cinst} ci
                 ON ci.moodlecourseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
               JOIN {local_outcomemap_evidence} e ON e.cinstid = ci.id
              WHERE e.userid = :userid",
            $params
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_outcomemap_cinst} ci
                 ON ci.moodlecourseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
               JOIN {local_outcomemap_result} r ON r.cinstid = ci.id
              WHERE r.userid = :userid",
            $params
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_outcomemap_cinst} ci
                 ON ci.moodlecourseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
               JOIN {local_outcomemap_result} r ON r.cinstid = ci.id
               JOIN {local_outcomemap_remed_event} re ON re.resultid = r.id
              WHERE re.userid = :userid",
            $params
        );

        $contextlist->add_from_sql(
            'SELECT contextid AS id
               FROM {local_outcomemap_audit}
              WHERE actorid = :userid',
            ['userid' => $userid]
        );
        $contextlist->add_from_sql(
            "SELECT DISTINCT a.contextid AS id
               FROM {local_outcomemap_audit} a
               JOIN {local_outcomemap_evidence} e ON e.id = a.objectid
              WHERE a.objecttype = :objecttype AND e.userid = :userid",
            ['objecttype' => 'evidence', 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            "SELECT DISTINCT a.contextid AS id
               FROM {local_outcomemap_audit} a
               JOIN {local_outcomemap_result} r ON r.id = a.objectid
              WHERE a.objecttype = :objecttype AND r.userid = :userid",
            ['objecttype' => 'result', 'userid' => $userid]
        );

        if (self::has_system_data($userid)) {
            $contextlist->add_system_context();
        }
    }

    /**
     * Add users represented in one approved context.
     *
     * @param userlist $userlist User collector.
     * @return void
     */
    public static function add_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $userlist->add_from_sql(
            'actorid',
            'SELECT actorid
               FROM {local_outcomemap_audit}
              WHERE contextid = :contextid AND actorid IS NOT NULL',
            ['contextid' => $context->id]
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT e.userid
               FROM {local_outcomemap_audit} a
               JOIN {local_outcomemap_evidence} e ON e.id = a.objectid
              WHERE a.contextid = :contextid AND a.objecttype = :objecttype",
            ['contextid' => $context->id, 'objecttype' => 'evidence']
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT r.userid
               FROM {local_outcomemap_audit} a
               JOIN {local_outcomemap_result} r ON r.id = a.objectid
              WHERE a.contextid = :contextid AND a.objecttype = :objecttype
                    AND r.userid IS NOT NULL",
            ['contextid' => $context->id, 'objecttype' => 'result']
        );

        if ($context instanceof \context_course) {
            self::add_course_users($userlist, (int) $context->instanceid);
        } else if ($context instanceof \context_system) {
            self::add_governance_users($userlist);
            subject_key_service::add_users($userlist);
            $userlist->add_from_sql(
                'userid',
                'SELECT userid
                   FROM {local_outcomemap_result}
                  WHERE cinstid IS NULL AND userid IS NOT NULL',
                []
            );
        }
    }

    /**
     * Export all approved user data.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        if (!count($contextlist)) {
            return;
        }

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if ($context instanceof \context_course) {
                self::export_course_data($context, $userid);
            } else if ($context instanceof \context_system) {
                self::export_system_data($context, $userid);
            }
            self::export_audit_data($context, $userid);
        }
    }

    /**
     * Delete all personal data in one context.
     *
     * @param \context $context Approved context.
     * @return void
     */
    public static function delete_all_in_context(\context $context): void {
        self::delete_in_context($context, null);
    }

    /**
     * Delete one user's data in all approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts and user.
     * @return void
     */
    public static function delete_for_user(approved_contextlist $contextlist): void {
        if (!count($contextlist)) {
            return;
        }
        $userids = [(int) $contextlist->get_user()->id];
        foreach ($contextlist as $context) {
            self::delete_in_context($context, $userids);
        }
    }

    /**
     * Delete several users' data in one approved context.
     *
     * @param approved_userlist $userlist Approved context and users.
     * @return void
     */
    public static function delete_for_users(approved_userlist $userlist): void {
        $userids = array_values(array_unique(array_map('intval', $userlist->get_userids())));
        if (!$userids) {
            return;
        }
        self::delete_in_context($userlist->get_context(), $userids);
    }

    /**
     * Whether a user has system-scoped governance, snapshot, or result data.
     *
     * @param int $userid Moodle user ID.
     * @return bool
     */
    private static function has_system_data(int $userid): bool {
        global $DB;

        foreach (self::governance_tables() as $table => $fields) {
            $conditions = [];
            $params = [];
            foreach ($fields as $index => $field) {
                $name = 'userid' . $index;
                $conditions[] = "{$field} = :{$name}";
                $params[$name] = $userid;
            }
            if ($DB->record_exists_select($table, implode(' OR ', $conditions), $params)) {
                return true;
            }
        }
        if ($DB->record_exists_select(
            'local_outcomemap_result',
            'userid = :userid AND cinstid IS NULL',
            ['userid' => $userid]
        )) {
            return true;
        }
        return subject_key_service::has_record($userid) || (bool) self::snapshot_subjects($userid);
    }

    /**
     * Add course-scoped learner and governance users.
     *
     * @param userlist $userlist User collector.
     * @param int $courseid Moodle course ID.
     * @return void
     */
    private static function add_course_users(userlist $userlist, int $courseid): void {
        $params = ['courseid' => $courseid];
        $userlist->add_from_sql(
            'userid',
            'SELECT e.userid
               FROM {local_outcomemap_evidence} e
               JOIN {local_outcomemap_cinst} ci ON ci.id = e.cinstid
              WHERE ci.moodlecourseid = :courseid',
            $params
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT r.userid
               FROM {local_outcomemap_result} r
               JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
              WHERE ci.moodlecourseid = :courseid AND r.userid IS NOT NULL',
            $params
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT re.userid
               FROM {local_outcomemap_remed_event} re
               JOIN {local_outcomemap_result} r ON r.id = re.resultid
               JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
              WHERE ci.moodlecourseid = :courseid',
            $params
        );

        foreach (['createdby', 'modifiedby', 'confirmedby'] as $field) {
            $userlist->add_from_sql(
                $field,
                "SELECT {$field}
                   FROM {local_outcomemap_cinst}
                  WHERE moodlecourseid = :courseid AND {$field} IS NOT NULL",
                $params
            );
        }
        foreach (['local_outcomemap_cmmap', 'local_outcomemap_secmap', 'local_outcomemap_remed'] as $table) {
            foreach (['createdby', 'approvedby'] as $field) {
                $userlist->add_from_sql(
                    $field,
                    "SELECT m.{$field}
                       FROM {{$table}} m
                       JOIN {local_outcomemap_cinst} ci ON ci.id = m.cinstid
                      WHERE ci.moodlecourseid = :courseid AND m.{$field} IS NOT NULL",
                    $params
                );
            }
        }
    }

    /**
     * Add all directly attributed governance users to a system user list.
     *
     * @param userlist $userlist User collector.
     * @return void
     */
    private static function add_governance_users(userlist $userlist): void {
        foreach (self::governance_tables() as $table => $fields) {
            foreach ($fields as $field) {
                $userlist->add_from_sql(
                    $field,
                    "SELECT {$field} FROM {{$table}} WHERE {$field} IS NOT NULL",
                    []
                );
            }
        }
    }

    /**
     * Export learner records belonging to one course.
     *
     * @param \context_course $context Course context.
     * @param int $userid Moodle user ID.
     * @return void
     */
    private static function export_course_data(\context_course $context, int $userid): void {
        global $DB;

        $cinstids = self::course_instance_ids((int) $context->instanceid);
        if (!$cinstids) {
            return;
        }
        [$cinstsql, $cinstparams] = $DB->get_in_or_equal($cinstids, SQL_PARAMS_NAMED, 'cinst');
        $params = ['userid' => $userid] + $cinstparams;
        $evidence = array_values($DB->get_records_select(
            'local_outcomemap_evidence',
            "userid = :userid AND cinstid {$cinstsql}",
            $params,
            'attempttime ASC, id ASC'
        ));
        $results = array_values($DB->get_records_select(
            'local_outcomemap_result',
            "userid = :userid AND cinstid {$cinstsql}",
            $params,
            'timecalculated ASC, id ASC'
        ));
        $engagement = array_values($DB->get_records_sql(
            "SELECT re.*
               FROM {local_outcomemap_remed_event} re
               JOIN {local_outcomemap_result} r ON r.id = re.resultid
              WHERE re.userid = :userid AND r.cinstid {$cinstsql}
           ORDER BY re.occurredat ASC, re.id ASC",
            $params
        ));
        $governance = self::governance_records($userid, $cinstids);
        if (!$evidence && !$results && !$engagement && !$governance) {
            return;
        }
        writer::with_context($context)->export_data(
            [get_string('privacy:path:course', self::COMPONENT)],
            (object) [
                'evidence' => $evidence,
                'results' => $results,
                'remediationengagement' => $engagement,
                'governance' => $governance,
            ]
        );
    }

    /**
     * Export system-scoped governance and frozen snapshot data.
     *
     * @param \context_system $context System context.
     * @param int $userid Moodle user ID.
     * @return void
     */
    private static function export_system_data(\context_system $context, int $userid): void {
        global $DB;

        $governance = self::governance_records($userid, null);
        $unscopedresults = array_values($DB->get_records_select(
            'local_outcomemap_result',
            'userid = :userid AND cinstid IS NULL',
            ['userid' => $userid],
            'timecalculated ASC, id ASC'
        ));
        $snapshots = self::snapshot_export_rows($userid);
        $subjectlinkage = subject_key_service::export_status($userid);
        if (!$governance && !$unscopedresults && !$snapshots && $subjectlinkage === null) {
            return;
        }
        writer::with_context($context)->export_data(
            [get_string('privacy:path:system', self::COMPONENT)],
            (object) [
                'governance' => $governance,
                'unscopedresults' => $unscopedresults,
                'frozensnapshots' => $snapshots,
                'subjectlinkage' => $subjectlinkage,
            ]
        );
    }

    /**
     * Export audit events attributed to or describing the user in one context.
     *
     * Evidence/result ownership is resolved through the live mutable row. Once
     * privacy deletion removes that row, retained append-only audit summaries
     * no longer identify the learner.
     *
     * @param \context $context Context.
     * @param int $userid Moodle user ID.
     * @return void
     */
    private static function export_audit_data(\context $context, int $userid): void {
        global $DB;

        $sql = "SELECT a.id, a.eventuuid, a.actorid, a.action, a.objecttype,
                       a.objectid, a.objectuuid, a.beforejson, a.afterjson,
                       a.reason, a.correlationid, a.timecreated
                  FROM {local_outcomemap_audit} a
                 WHERE a.contextid = :contextid
                   AND (a.actorid = :actorid
                        OR (a.objecttype = :evidencetype AND EXISTS (
                            SELECT 1
                              FROM {local_outcomemap_evidence} e
                             WHERE e.id = a.objectid AND e.userid = :evidenceuserid
                        ))
                        OR (a.objecttype = :resulttype AND EXISTS (
                            SELECT 1
                              FROM {local_outcomemap_result} r
                             WHERE r.id = a.objectid AND r.userid = :resultuserid
                        )))
              ORDER BY a.timecreated ASC, a.id ASC";
        $records = $DB->get_records_sql($sql, [
            'contextid' => $context->id,
            'actorid' => $userid,
            'evidencetype' => 'evidence',
            'evidenceuserid' => $userid,
            'resulttype' => 'result',
            'resultuserid' => $userid,
        ]);
        if (!$records) {
            return;
        }
        writer::with_context($context)->export_data(
            [get_string('privacy:path:audit', self::COMPONENT)],
            (object) ['events' => array_values($records)]
        );
    }

    /**
     * Return governed rows directly attributed to one user.
     *
     * @param int $userid Moodle user ID.
     * @param int[]|null $cinstids Course-instance scope, or null for all rows.
     * @return array<string,\stdClass[]>
     */
    private static function governance_records(int $userid, ?array $cinstids): array {
        global $DB;

        $records = [];
        foreach (self::governance_tables() as $table => $fields) {
            if ($table === 'local_outcomemap_snapshot' && $cinstids !== null) {
                continue;
            }
            if ($cinstids !== null && !in_array($table, [
                'local_outcomemap_cinst',
                'local_outcomemap_cmmap',
                'local_outcomemap_secmap',
                'local_outcomemap_remed',
            ], true)) {
                continue;
            }
            $conditions = [];
            $params = [];
            foreach ($fields as $index => $field) {
                $name = 'actor' . $index;
                $conditions[] = "{$field} = :{$name}";
                $params[$name] = $userid;
            }
            $select = '(' . implode(' OR ', $conditions) . ')';
            if ($cinstids !== null) {
                [$cinstsql, $cinstparams] = $DB->get_in_or_equal($cinstids, SQL_PARAMS_NAMED, 'scope');
                $scopefield = $table === 'local_outcomemap_cinst' ? 'id' : 'cinstid';
                $select .= " AND {$scopefield} {$cinstsql}";
                $params += $cinstparams;
            }
            $matches = $DB->get_records_select($table, $select, $params, 'id ASC');
            if ($matches) {
                $records[$table] = array_values($matches);
            }
        }
        return $records;
    }

    /**
     * Return exact frozen rows belonging to one user.
     *
     * Snapshot headers and matching subject rows are bulk-loaded in bounded
     * batches; the query count does not grow once per snapshot.
     *
     * @param int $userid Moodle user ID.
     * @return array
     */
    private static function snapshot_export_rows(int $userid): array {
        global $DB;

        $matches = self::snapshot_subjects($userid);
        if (!$matches) {
            return [];
        }

        $snapshots = [];
        foreach (array_chunk(array_keys($matches), 500) as $snapshotids) {
            $snapshots += $DB->get_records_list(
                'local_outcomemap_snapshot',
                'id',
                $snapshotids,
                '',
                'id,snapshotuuid,version,periodcode,retentionbasis,status'
            );
        }

        $itemsbysnapshot = [];
        foreach (array_chunk($matches, 500, true) as $chunk) {
            [$snapshotsql, $snapshotparams] = $DB->get_in_or_equal(
                array_keys($chunk),
                SQL_PARAMS_NAMED,
                'exportsnapshot'
            );
            [$subjectsql, $subjectparams] = $DB->get_in_or_equal(
                array_values($chunk),
                SQL_PARAMS_NAMED,
                'exportsubject'
            );
            $sql = "SELECT id, snapshotid, subjectref, itemtype, stablekey,
                           payloadjson, payloadhash, sortorder
                      FROM {local_outcomemap_snapitem}
                     WHERE snapshotid {$snapshotsql} AND subjectref {$subjectsql}
                  ORDER BY snapshotid, sortorder, id";
            foreach ($DB->get_records_sql($sql, $snapshotparams + $subjectparams) as $item) {
                $snapshotid = (int) $item->snapshotid;
                if (isset($chunk[$snapshotid]) && (string) $item->subjectref === $chunk[$snapshotid]) {
                    $itemsbysnapshot[$snapshotid][] = $item;
                }
            }
        }

        $export = [];
        foreach ($matches as $snapshotid => $subjectref) {
            $snapshot = $snapshots[$snapshotid];
            $payloads = [];
            foreach ($itemsbysnapshot[$snapshotid] ?? [] as $item) {
                $payloads[] = [
                    'itemtype' => (string) $item->itemtype,
                    'stablekey' => (string) $item->stablekey,
                    'payloadhash' => (string) $item->payloadhash,
                    'payload' => json_decode($item->payloadjson, true),
                ];
            }
            $export[] = [
                'snapshotuuid' => (string) $snapshot->snapshotuuid,
                'version' => (int) $snapshot->version,
                'periodcode' => (string) $snapshot->periodcode,
                'retentionbasis' => (string) $snapshot->retentionbasis,
                'status' => (string) $snapshot->status,
                'items' => $payloads,
            ];
        }
        return $export;
    }

    /**
     * Delete or anonymise all relevant data in one context transactionally.
     *
     * Null user IDs mean every user in the context.
     *
     * @param \context $context Approved context.
     * @param int[]|null $userids Target users, or null for all users.
     * @return void
     */
    private static function delete_in_context(\context $context, ?array $userids): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($context instanceof \context_course) {
                $cinstids = self::course_instance_ids((int) $context->instanceid);
                self::delete_learner_records($userids, $cinstids, false);
                self::anonymise_governance($userids, $cinstids);
            } else if ($context instanceof \context_system) {
                self::delete_learner_records($userids, [], true);
                self::anonymise_governance($userids, null);
                self::forget_snapshot_linkage($userids);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Delete mutable learner records in course or unscoped result storage.
     *
     * @param int[]|null $userids Target users, or null for all users.
     * @param int[] $cinstids Course-instance IDs.
     * @param bool $unscoped Whether to select only results without a course instance.
     * @return void
     */
    private static function delete_learner_records(?array $userids, array $cinstids, bool $unscoped): void {
        global $DB;

        if (!$unscoped && !$cinstids) {
            return;
        }
        $params = [];
        if ($unscoped) {
            $resultselect = 'cinstid IS NULL';
        } else {
            [$cinstsql, $cinstparams] = $DB->get_in_or_equal($cinstids, SQL_PARAMS_NAMED, 'cinst');
            $resultselect = "cinstid {$cinstsql}";
            $params += $cinstparams;
        }
        if ($userids !== null) {
            [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
            $resultselect .= " AND userid {$usersql}";
            $params += $userparams;
        }
        $resultids = array_keys($DB->get_records_select(
            'local_outcomemap_result',
            $resultselect,
            $params,
            '',
            'id'
        ));
        if ($resultids) {
            self::delete_records_by_ids('local_outcomemap_remed_event', 'resultid', $resultids);
            self::clear_references('local_outcomemap_result', 'supersededby', $resultids);
            self::delete_records_by_ids('local_outcomemap_result', 'id', $resultids);
        }
        if ($unscoped) {
            return;
        }

        $evidenceparams = [];
        [$cinstsql, $cinstparams] = $DB->get_in_or_equal($cinstids, SQL_PARAMS_NAMED, 'evidencecinst');
        $evidenceselect = "cinstid {$cinstsql}";
        $evidenceparams += $cinstparams;
        if ($userids !== null) {
            [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'evidenceuser');
            $evidenceselect .= " AND userid {$usersql}";
            $evidenceparams += $userparams;
        }
        $evidenceids = array_keys($DB->get_records_select(
            'local_outcomemap_evidence',
            $evidenceselect,
            $evidenceparams,
            '',
            'id'
        ));
        if ($evidenceids) {
            self::clear_references('local_outcomemap_evidence', 'sourceevidenceid', $evidenceids);
            self::clear_references('local_outcomemap_evidence', 'supersededby', $evidenceids);
            self::delete_records_by_ids('local_outcomemap_evidence', 'id', $evidenceids);
        }
    }

    /**
     * Remove user attribution from governed institutional records.
     *
     * @param int[]|null $userids Target users, or null for all users.
     * @param int[]|null $cinstids Course-instance scope, or null for all governance.
     * @return void
     */
    private static function anonymise_governance(?array $userids, ?array $cinstids): void {
        global $DB;

        foreach (self::governance_tables() as $table => $fields) {
            if ($table === 'local_outcomemap_snapshot') {
                continue;
            }
            if ($cinstids !== null && !in_array($table, [
                'local_outcomemap_cinst',
                'local_outcomemap_cmmap',
                'local_outcomemap_secmap',
                'local_outcomemap_remed',
            ], true)) {
                continue;
            }
            foreach ($fields as $field) {
                $select = "{$field} IS NOT NULL";
                $params = [];
                if ($userids !== null) {
                    [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'governanceuser');
                    $select = "{$field} {$usersql}";
                    $params += $userparams;
                }
                if ($cinstids !== null) {
                    if (!$cinstids) {
                        continue;
                    }
                    [$cinstsql, $cinstparams] = $DB->get_in_or_equal($cinstids, SQL_PARAMS_NAMED, 'governancecinst');
                    $scopefield = $table === 'local_outcomemap_cinst' ? 'id' : 'cinstid';
                    $select .= " AND {$scopefield} {$cinstsql}";
                    $params += $cinstparams;
                }
                $DB->set_field_select($table, $field, null, $select, $params);
            }
        }
    }

    /**
     * Forget snapshot linkage without mutating any frozen snapshot row.
     *
     * @param int[]|null $userids Target users, or null for all issued keys.
     * @return void
     */
    private static function forget_snapshot_linkage(?array $userids): void {
        if ($userids === null) {
            subject_key_service::forget_all();
            return;
        }
        foreach ($userids as $userid) {
            subject_key_service::forget($userid);
        }
    }

    /**
     * Find snapshots containing the deterministic reference for one user.
     *
     * @param int $userid Moodle user ID.
     * @return array<int,string> Subject reference keyed by snapshot ID.
     */
    private static function snapshot_subjects(int $userid): array {
        global $DB;

        $snapshots = $DB->get_records(
            'local_outcomemap_snapshot',
            null,
            'id ASC',
            'id,snapshotuuid,subjecthashmethod'
        );
        $references = snapshot_service::subject_references_for_lookup($snapshots, $userid);
        if (!$references) {
            return [];
        }

        $matches = [];
        foreach (array_chunk($references, 500, true) as $chunk) {
            [$snapshotsql, $snapshotparams] = $DB->get_in_or_equal(
                array_keys($chunk),
                SQL_PARAMS_NAMED,
                'lookupsnapshot'
            );
            [$subjectsql, $subjectparams] = $DB->get_in_or_equal(
                array_values($chunk),
                SQL_PARAMS_NAMED,
                'lookupsubject'
            );
            $sql = "SELECT DISTINCT snapshotid, subjectref
                      FROM {local_outcomemap_snapitem}
                     WHERE snapshotid {$snapshotsql} AND subjectref {$subjectsql}";
            $items = $DB->get_records_sql($sql, $snapshotparams + $subjectparams);
            foreach ($items as $item) {
                $snapshotid = (int) $item->snapshotid;
                if (isset($chunk[$snapshotid]) && (string) $item->subjectref === $chunk[$snapshotid]) {
                    $matches[$snapshotid] = (string) $item->subjectref;
                }
            }
        }
        ksort($matches);
        return $matches;
    }

    /**
     * Return course-instance IDs for a Moodle course.
     *
     * @param int $courseid Moodle course ID.
     * @return int[]
     */
    private static function course_instance_ids(int $courseid): array {
        global $DB;

        return array_map('intval', array_keys($DB->get_records(
            'local_outcomemap_cinst',
            ['moodlecourseid' => $courseid],
            '',
            'id'
        )));
    }

    /**
     * Clear foreign/self references to records that are about to be deleted.
     *
     * @param string $table Table name.
     * @param string $field Reference field.
     * @param int[] $ids Referenced IDs.
     * @return void
     */
    private static function clear_references(string $table, string $field, array $ids): void {
        global $DB;

        foreach (array_chunk($ids, 500) as $chunk) {
            [$idsql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'reference');
            $DB->set_field_select($table, $field, null, "{$field} {$idsql}", $params);
        }
    }

    /**
     * Delete records by a field in bounded batches.
     *
     * @param string $table Table name.
     * @param string $field Field name.
     * @param int[] $ids Values.
     * @return void
     */
    private static function delete_records_by_ids(string $table, string $field, array $ids): void {
        global $DB;

        foreach (array_chunk($ids, 500) as $chunk) {
            [$idsql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'delete');
            $DB->delete_records_select($table, "{$field} {$idsql}", $params);
        }
    }
}
