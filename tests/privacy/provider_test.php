<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_outcomemap\local\audit_writer;
use local_outcomemap\local\canonical_json;
use local_outcomemap\local\decimal;
use local_outcomemap\local\service\audit_lineage_service;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\snapshot_service;
use local_outcomemap\local\service\suppression_service;
use local_outcomemap\local\privacy\subject_key_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\workflow;

/**
 * Privacy API coverage for learner, governance, audit, and snapshot records.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_outcomemap\privacy\provider
 * @covers     \local_outcomemap\local\privacy\user_data_service
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Guarantee the legacy site secret used by pre-v2 snapshot references.
     *
     * Active subject markers derive from a durable plugin secret and no longer
     * need this. Legacy references still reproduce hashes frozen under
     * $CFG->passwordsaltmain, which a PHPUnit site does not set by default, so
     * the legacy-resolution coverage in this class supplies one.
     *
     * @see subject_key_service_test for coverage without any legacy secret.
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        if (empty($CFG->passwordsaltmain)) {
            $CFG->passwordsaltmain = 'local_outcomemap_privacy_test_secret';
        }
    }

    /**
     * The provider declares all direct and pseudonymous personal-data stores.
     */
    public function test_metadata_declares_personal_data_tables(): void {
        $collection = provider::get_metadata(new collection('local_outcomemap'));
        $names = [];
        foreach ($collection->get_collection() as $type) {
            $names[] = $type->get_name();
        }
        foreach ([
            'local_outcomemap_evidence',
            'local_outcomemap_result',
            'local_outcomemap_remed_event',
            'local_outcomemap_snapitem',
            'local_outcomemap_privkey',
            'local_outcomemap_audit',
            'local_outcomemap_snapshot',
        ] as $tablename) {
            $this->assertContains($tablename, $names);
        }
    }

    /**
     * Export finds course/system data and deletion applies every retention rule.
     */
    public function test_export_and_delete_user_data(): void {
        global $CFG, $DB;

        $this->resetAfterTest(true);
        writer::reset();
        if (empty($CFG->passwordsaltmain)) {
            $CFG->passwordsaltmain = 'local_outcomemap_privacy_test_secret';
        }
        $fixture = $this->create_fixture();
        $userid = (int) $fixture['user']->id;

        $contextlist = provider::get_contexts_for_userid($userid);
        $this->assertEqualsCanonicalizing(
            [$fixture['coursecontext']->id, \context_system::instance()->id],
            $contextlist->get_contextids()
        );

        $courseusers = new userlist($fixture['coursecontext'], 'local_outcomemap');
        provider::get_users_in_context($courseusers);
        $this->assertEqualsCanonicalizing(
            [$fixture['user']->id, $fixture['otheruser']->id],
            $courseusers->get_userids()
        );
        $systemusers = new userlist(\context_system::instance(), 'local_outcomemap');
        provider::get_users_in_context($systemusers);
        $this->assertEqualsCanonicalizing(
            [$fixture['user']->id, $fixture['otheruser']->id],
            $systemusers->get_userids()
        );

        $approved = new \core_privacy\tests\request\approved_contextlist(
            \core_user::get_user($userid),
            'local_outcomemap',
            $contextlist->get_contextids()
        );
        provider::export_user_data($approved);
        $coursedata = writer::with_context($fixture['coursecontext'])->get_data([
            get_string('privacy:path:course', 'local_outcomemap'),
        ]);
        $this->assertCount(1, $coursedata->evidence);
        $this->assertCount(1, $coursedata->results);
        $this->assertCount(1, $coursedata->remediationengagement);
        $systemdata = writer::with_context(\context_system::instance())->get_data([
            get_string('privacy:path:system', 'local_outcomemap'),
        ]);
        $this->assertCount(2, $systemdata->frozensnapshots);
        $auditdata = writer::with_context($fixture['coursecontext'])->get_data([
            get_string('privacy:path:audit', 'local_outcomemap'),
        ]);
        $this->assertCount(3, $auditdata->events);
        $eventsbytype = [];
        foreach ($auditdata->events as $event) {
            $eventsbytype[$event->objecttype] = $event;
        }
        $this->assertArrayHasKey('evidence', $eventsbytype);
        $this->assertArrayHasKey('result', $eventsbytype);
        $evidencepayload = json_decode($eventsbytype['evidence']->afterjson, true);
        $this->assertArrayNotHasKey('userid', $evidencepayload);
        $this->assertArrayNotHasKey('quizattemptid', $evidencepayload);
        $this->assertArrayNotHasKey('rawfraction', $evidencepayload);
        $resultpayload = json_decode($eventsbytype['result']->afterjson, true);
        $this->assertArrayNotHasKey('userid', $resultpayload);
        $this->assertArrayNotHasKey('resultkey', $resultpayload);
        $this->assertArrayNotHasKey('numerator', $resultpayload);
        $this->assertArrayNotHasKey('inputhash', $resultpayload);
        $this->assertArrayNotHasKey('lineagejson', $resultpayload);
        $anonymousfingerprint = $this->snapshot_fingerprint($fixture['anonymoussnapshotid']);
        $deletionfingerprint = $this->snapshot_fingerprint($fixture['deletionsnapshotid']);
        $auditfingerprint = $this->audit_fingerprint($fixture['coursecontext']->id);

        provider::delete_data_for_user($approved);

        $this->assertFalse($DB->record_exists('local_outcomemap_evidence', ['userid' => $userid]));
        $this->assertFalse($DB->record_exists('local_outcomemap_result', ['userid' => $userid]));
        $this->assertFalse($DB->record_exists('local_outcomemap_remed_event', ['userid' => $userid]));
        $this->assertTrue($DB->record_exists('local_outcomemap_evidence', [
            'userid' => $fixture['otheruser']->id,
        ]));
        $this->assertTrue($DB->record_exists('local_outcomemap_result', [
            'userid' => $fixture['otheruser']->id,
        ]));
        $this->assertTrue($DB->record_exists('local_outcomemap_remed_event', [
            'userid' => $fixture['otheruser']->id,
        ]));

        $program = $DB->get_record('local_outcomemap_program', ['id' => $fixture['programid']], '*', MUST_EXIST);
        $this->assertNull($program->createdby);
        $courseinstance = $DB->get_record(
            'local_outcomemap_cinst',
            ['id' => $fixture['cinstid']],
            '*',
            MUST_EXIST
        );
        $this->assertNull($courseinstance->createdby);
        $audit = $DB->get_record(
            'local_outcomemap_audit',
            ['id' => $fixture['auditid']],
            '*',
            MUST_EXIST
        );
        // Append-only audit actor and reason are deliberately retained as an
        // institutional record, as declared in the privacy metadata. Only the
        // payload user references are minimised, which happens at write time.
        $this->assertSame($userid, (int) $audit->actorid);
        $this->assertNotNull($audit->reason);
        $this->assertArrayNotHasKey('userid', json_decode($audit->beforejson, true));
        $this->assertArrayNotHasKey('createdby', json_decode($audit->afterjson, true));
        $this->assertSame($auditfingerprint, $this->audit_fingerprint($fixture['coursecontext']->id));

        $anonymoussnapshot = $this->verified_snapshot($fixture['anonymoussnapshotid']);
        $oldanonymousref = $fixture['subjectrefs'][$fixture['anonymoussnapshotid']][$userid];
        $otheranonymousref = $fixture['subjectrefs'][$fixture['anonymoussnapshotid']][$fixture['otheruser']->id];
        $this->assertSame(2, (int) $anonymoussnapshot->populationcount);
        $this->assertTrue($DB->record_exists('local_outcomemap_snapitem', [
            'snapshotid' => $anonymoussnapshot->id,
            'subjectref' => $oldanonymousref,
        ]));
        $this->assertTrue($DB->record_exists('local_outcomemap_snapitem', [
            'snapshotid' => $anonymoussnapshot->id,
            'subjectref' => $otheranonymousref,
        ]));
        $this->assertSame($anonymousfingerprint, $this->snapshot_fingerprint($anonymoussnapshot->id));

        $deletionsnapshot = $this->verified_snapshot($fixture['deletionsnapshotid']);
        $olddeletionref = $fixture['subjectrefs'][$fixture['deletionsnapshotid']][$userid];
        $otherdeletionref = $fixture['subjectrefs'][$fixture['deletionsnapshotid']][$fixture['otheruser']->id];
        $this->assertSame(2, (int) $deletionsnapshot->populationcount);
        $this->assertTrue($DB->record_exists('local_outcomemap_snapitem', [
            'snapshotid' => $deletionsnapshot->id,
            'subjectref' => $olddeletionref,
        ]));
        $this->assertTrue($DB->record_exists('local_outcomemap_snapitem', [
            'snapshotid' => $deletionsnapshot->id,
            'subjectref' => $otherdeletionref,
        ]));
        $this->assertSame($deletionfingerprint, $this->snapshot_fingerprint($deletionsnapshot->id));
        $this->assertNull(snapshot_service::subject_reference_for_lookup(
            (string) $anonymoussnapshot->snapshotuuid,
            $userid,
            (string) $anonymoussnapshot->subjecthashmethod
        ));
        $linkagestatus = subject_key_service::export_status($userid);
        $this->assertNotNull($linkagestatus);
        $this->assertFalse($linkagestatus['activekey']);
        $this->assertTrue($linkagestatus['legacyresolutionblocked']);
    }

    /**
     * Bulk context deletion removes only approved users and supports all users.
     */
    public function test_bulk_and_context_deletion(): void {
        global $CFG, $DB;

        $this->resetAfterTest(true);
        if (empty($CFG->passwordsaltmain)) {
            $CFG->passwordsaltmain = 'local_outcomemap_privacy_test_secret';
        }
        $fixture = $this->create_fixture();
        $approvedusers = new approved_userlist(
            $fixture['coursecontext'],
            'local_outcomemap',
            [(int) $fixture['user']->id]
        );
        provider::delete_data_for_users($approvedusers);
        $this->assertFalse($DB->record_exists('local_outcomemap_evidence', [
            'userid' => $fixture['user']->id,
        ]));
        $this->assertTrue($DB->record_exists('local_outcomemap_evidence', [
            'userid' => $fixture['otheruser']->id,
        ]));

        provider::delete_data_for_all_users_in_context($fixture['coursecontext']);
        $this->assertFalse($DB->record_exists('local_outcomemap_evidence', [
            'userid' => $fixture['otheruser']->id,
        ]));
        $this->assertFalse($DB->record_exists('local_outcomemap_result', [
            'userid' => $fixture['otheruser']->id,
        ]));
        $this->assertFalse($DB->record_exists('local_outcomemap_remed_event', [
            'userid' => $fixture['otheruser']->id,
        ]));
    }

    /**
     * System-context deletion blocks legacy references even without user rows.
     */
    public function test_system_context_deletion_blocks_all_legacy_resolution(): void {
        global $CFG;

        $this->resetAfterTest(true);
        if (empty($CFG->passwordsaltmain)) {
            $CFG->passwordsaltmain = 'local_outcomemap_privacy_test_secret';
        }
        $user = $this->getDataGenerator()->create_user();
        $snapshotuuid = uuid::generate();

        $this->assertFalse(subject_key_service::has_record((int) $user->id));
        $this->assertNotNull(subject_key_service::legacy_reference($snapshotuuid, (int) $user->id));

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertFalse(subject_key_service::has_record((int) $user->id));
        $this->assertNull(subject_key_service::legacy_reference($snapshotuuid, (int) $user->id));
        $status = subject_key_service::export_status((int) $user->id);
        $this->assertNotNull($status);
        $this->assertFalse($status['activekey']);
        $this->assertTrue($status['legacyresolutionblocked']);
    }

    /**
     * Snapshot subject discovery uses a fixed query budget for one batch.
     */
    public function test_snapshot_subject_discovery_query_count_is_bounded(): void {
        global $DB;

        $this->resetAfterTest(true);
        $fixture = $this->create_fixture();
        $snapshot = $DB->get_record(
            'local_outcomemap_snapshot',
            ['id' => $fixture['anonymoussnapshotid']],
            'programid,policyid',
            MUST_EXIST
        );
        for ($index = 2; $index < 25; $index++) {
            $this->create_snapshot(
                (int) $snapshot->programid,
                (int) $snapshot->policyid,
                [$fixture['user'], $fixture['otheruser']],
                suppression_service::RETENTION_ANONYMISED,
                time() + $index
            );
        }

        $method = new \ReflectionMethod(
            \local_outcomemap\local\privacy\user_data_service::class,
            'snapshot_subjects'
        );
        $method->setAccessible(true);
        $before = $DB->perf_get_queries();
        $matches = $method->invoke(null, (int) $fixture['user']->id);
        $queries = $DB->perf_get_queries() - $before;

        $this->assertCount(25, $matches);
        $this->assertLessThanOrEqual(3, $queries,
            'Snapshot discovery must use one snapshot query, one key query, and one bounded item query.');
    }

    /**
     * Create two learners, mutable records, audit history, and both retention modes.
     *
     * @return array Fixture identifiers and records.
     */
    private function create_fixture(): array {
        global $DB;

        $now = time();
        $user = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $programid = (int) $DB->insert_record('local_outcomemap_program', (object) [
            'uuid' => uuid::generate(),
            'code' => 'PRIVACY-PROGRAM',
            'name' => 'Privacy test program',
            'description' => null,
            'externalid' => null,
            'programtype' => 'graduate',
            'credential' => 'degree',
            'status' => workflow::APPROVED,
            'createdby' => $user->id,
            'modifiedby' => $user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $catalogcourseid = (int) $DB->insert_record('local_outcomemap_course', (object) [
            'uuid' => uuid::generate(),
            'code' => 'PRIVACY-COURSE',
            'name' => 'Privacy test course',
            'description' => null,
            'siskey' => null,
            'status' => workflow::APPROVED,
            'createdby' => $user->id,
            'modifiedby' => $user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $cinstid = (int) $DB->insert_record('local_outcomemap_cinst', (object) [
            'uuid' => uuid::generate(),
            'courseid' => $catalogcourseid,
            'moodlecourseid' => $course->id,
            'periodcode' => 'PRIVACY-2026',
            'externalid' => null,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
            'confirmedby' => $user->id,
            'confirmedat' => $now,
            'createdby' => $user->id,
            'modifiedby' => $user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $frameworkid = (int) $DB->insert_record('local_outcomemap_fw', (object) [
            'uuid' => uuid::generate(),
            'code' => 'PRIVACY-FW',
            'name' => 'Privacy test outcomes',
            'description' => null,
            'ownertype' => 'program',
            'ownerid' => $programid,
            'status' => workflow::APPROVED,
            'createdby' => $user->id,
            'modifiedby' => $user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $itemid = (int) $DB->insert_record('local_outcomemap_item', (object) [
            'uuid' => uuid::generate(),
            'frameworkid' => $frameworkid,
            'code' => 'PRIVACY-1',
            'status' => workflow::APPROVED,
            'createdby' => $user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $itemverid = (int) $DB->insert_record('local_outcomemap_itemver', (object) [
            'uuid' => uuid::generate(),
            'itemid' => $itemid,
            'version' => 1,
            'statement' => 'Privacy test outcome.',
            'shortstatement' => null,
            'bloomlevel' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $now - DAYSECS,
            'effectiveto' => null,
            'changereason' => null,
            'createdby' => $user->id,
            'approvedby' => $otheruser->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
        $configjson = canonical_json::encode([
            'minitems' => 1,
            'minweightedpossible' => decimal::ZERO,
            'requiremanualgrading' => true,
            'displayscale' => 1,
        ]);
        $policyid = (int) $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => uuid::generate(),
            'version' => 1,
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'scopeid' => null,
            'name' => 'Privacy calculation policy',
            'configjson' => $configjson,
            'confighash' => hash('sha256', $configjson),
            'status' => workflow::APPROVED,
            'effectivefrom' => $now - DAYSECS,
            'effectiveto' => null,
            'createdby' => $user->id,
            'approvedby' => $otheruser->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
        $mappingid = (int) $DB->insert_record('local_outcomemap_qmap', (object) [
            'mappinguuid' => uuid::generate(),
            'version' => 1,
            'questionversionid' => 700001,
            'questionid' => 700000,
            'itemverid' => $itemverid,
            'role' => 'assesses',
            'weight' => '1.0000000000',
            'notes' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $now - DAYSECS,
            'effectiveto' => null,
            'createdby' => $user->id,
            'approvedby' => $otheruser->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
        $remediationid = (int) $DB->insert_record('local_outcomemap_remed', (object) [
            'mappinguuid' => uuid::generate(),
            'version' => 1,
            'cinstid' => $cinstid,
            'itemverid' => $itemverid,
            'bandid' => null,
            'targettype' => 'external_url',
            'purpose' => 'review',
            'targetid' => null,
            'externalurl' => 'https://example.invalid/privacy-review',
            'title' => 'Privacy review',
            'explanation' => null,
            'priority' => 1,
            'sortorder' => 1,
            'required' => 0,
            'minpercent' => null,
            'maxpercent' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $now - DAYSECS,
            'effectiveto' => null,
            'createdby' => $user->id,
            'approvedby' => $otheruser->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        foreach ([$user, $otheruser] as $index => $learner) {
            $evidenceuuid = uuid::generate();
            $evidencerecord = [
                'uuid' => $evidenceuuid,
                'lineageuuid' => uuid::generate(),
                'dedupekey' => hash('sha256', 'privacy-evidence-' . $index),
                'sourceevidenceid' => null,
                'relationpathjson' => canonical_json::encode([]),
                'cinstid' => $cinstid,
                'userid' => $learner->id,
                'assessmentcmid' => 710000,
                'quizattemptid' => 720000 + $index,
                'questionusageid' => 730000 + $index,
                'slot' => 1,
                'questionattemptid' => 740000 + $index,
                'questionversionid' => 700001,
                'questionid' => 700000,
                'itemverid' => $itemverid,
                'mappingid' => $mappingid,
                'policyid' => $policyid,
                'evidencetype' => calculation_service::TYPE_DIRECT,
                'rawfraction' => '0.8000000000',
                'rawmark' => '8.0000000000',
                'maxmark' => '10.0000000000',
                'mappingweight' => '1.0000000000',
                'relationweight' => '1.0000000000',
                'weightedearned' => '8.0000000000',
                'weightedpossible' => '10.0000000000',
                'gradingstate' => calculation_service::GRADING_GRADED,
                'attempttime' => $now,
                'gradingtime' => $now,
                'supersededby' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $evidenceid = (int) $DB->insert_record('local_outcomemap_evidence', (object) $evidencerecord);
            $lineagejson = canonical_json::encode([['uuid' => $evidenceuuid]]);
            $resultrecord = [
                'uuid' => uuid::generate(),
                'resultkey' => hash('sha256', 'privacy-result-' . $index),
                'version' => 1,
                'cinstid' => $cinstid,
                'userid' => $learner->id,
                'scopetype' => calculation_service::SCOPE_COURSE,
                'scopeid' => $cinstid,
                'periodcode' => 'PRIVACY-2026',
                'itemverid' => $itemverid,
                'policyid' => $policyid,
                'numerator' => '8.0000000000',
                'denominator' => '10.0000000000',
                'percentage' => '80.0000000000',
                'distinctitems' => 1,
                'bandid' => null,
                'state' => calculation_service::STATE_CALCULATED,
                'stale' => 0,
                'algoversion' => calculation_service::ALGO_VERSION,
                'inputhash' => hash('sha256', 'privacy-input-' . $index),
                'lineagejson' => $lineagejson,
                'lineagehash' => hash('sha256', $lineagejson),
                'supersededby' => null,
                'timecalculated' => $now,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $resultid = (int) $DB->insert_record('local_outcomemap_result', (object) $resultrecord);
            $DB->insert_record('local_outcomemap_remed_event', (object) [
                'eventuuid' => uuid::generate(),
                'remediationid' => $remediationid,
                'resultid' => $resultid,
                'userid' => $learner->id,
                'eventtype' => 'opened',
                'occurredat' => $now,
                'timecreated' => $now,
            ]);

            if ($index === 0) {
                // Audit history describing the primary learner's own evidence and
                // result. Export resolves learner ownership through these live rows
                // rather than through actorid, so both rows are written by the
                // system (null actor) to keep that branch honestly covered.
                audit_writer::write(
                    'evidence_ingested',
                    'evidence',
                    $evidenceid,
                    $evidenceuuid,
                    null,
                    $evidencerecord,
                    null,
                    $coursecontext,
                    null
                );
                audit_writer::write(
                    'result_calculated',
                    'result',
                    $resultid,
                    $resultrecord['uuid'],
                    null,
                    $resultrecord,
                    null,
                    $coursecontext,
                    null
                );
            }
        }

        $auditid = audit_writer::write(
            'privacy_fixture',
            'course_instance',
            $cinstid,
            $DB->get_field('local_outcomemap_cinst', 'uuid', ['id' => $cinstid], MUST_EXIST),
            ['userid' => $user->id],
            ['createdby' => $user->id],
            'Created by the privacy test user.',
            $coursecontext,
            $user->id
        );
        $anonymoussnapshotid = $this->create_snapshot(
            $programid,
            $policyid,
            [$user, $otheruser],
            suppression_service::RETENTION_ANONYMISED,
            $now
        );
        $deletionsnapshotid = $this->create_snapshot(
            $programid,
            $policyid,
            [$user, $otheruser],
            suppression_service::RETENTION_PRIVACY_DELETION,
            $now + 1
        );

        $subjectrefs = [];
        foreach ([$anonymoussnapshotid, $deletionsnapshotid] as $snapshotid) {
            $snapshotuuid = $DB->get_field(
                'local_outcomemap_snapshot',
                'snapshotuuid',
                ['id' => $snapshotid],
                MUST_EXIST
            );
            foreach ([$user, $otheruser] as $learner) {
                $subjectrefs[$snapshotid][$learner->id] = snapshot_service::subject_reference(
                    $snapshotuuid,
                    (int) $learner->id
                );
            }
        }
        return [
            'user' => $user,
            'otheruser' => $otheruser,
            'coursecontext' => $coursecontext,
            'programid' => $programid,
            'cinstid' => $cinstid,
            'auditid' => $auditid,
            'anonymoussnapshotid' => $anonymoussnapshotid,
            'deletionsnapshotid' => $deletionsnapshotid,
            'subjectrefs' => $subjectrefs,
        ];
    }

    /**
     * Create a valid frozen snapshot with one population row per learner.
     *
     * @param int $programid Program ID.
     * @param int $policyid Policy ID.
     * @param \stdClass[] $users Learners.
     * @param string $retentionbasis Retention basis.
     * @param int $now Timestamp.
     * @return int Snapshot ID.
     */
    private function create_snapshot(
        int $programid,
        int $policyid,
        array $users,
        string $retentionbasis,
        int $now
    ): int {
        global $DB;

        $snapshotuuid = uuid::generate();
        $record = (object) [
            'snapshotuuid' => $snapshotuuid,
            'version' => 1,
            'previousid' => null,
            'programid' => $programid,
            'periodcode' => 'PRIVACY-2026',
            'cohortid' => null,
            'policyid' => $policyid,
            'status' => snapshot_service::STATUS_FROZEN,
            'notes' => null,
            'correctionreason' => null,
            'populationsource' => suppression_service::POPULATION_ACTIVE_ENROLMENTS,
            'retentionbasis' => $retentionbasis,
            'populationat' => $now,
            'populationcount' => count($users),
            'suppressionthreshold' => 1,
            'subjecthashmethod' => snapshot_service::SUBJECT_HASH_METHOD,
            'pluginversion' => '2026072701',
            'algoversion' => snapshot_service::ALGO_VERSION,
            'payloadhash' => str_repeat('0', 64),
            'manifesthash' => null,
            'createdby' => $users[0]->id,
            'approvedby' => $users[1]->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ];
        $snapshotid = (int) $DB->insert_record('local_outcomemap_snapshot', $record);
        $record->id = $snapshotid;
        $items = [];
        foreach ($users as $sortorder => $user) {
            $subjectref = snapshot_service::subject_reference($snapshotuuid, (int) $user->id);
            $identity = ['subjectref' => $subjectref];
            $index = [
                'subjectref' => $subjectref,
                'sourceuuid' => null,
                'sourceid' => null,
                'cinstid' => null,
                'itemverid' => null,
                'state' => null,
                'bandcode' => null,
                'numerator' => decimal::ZERO,
                'denominator' => decimal::ZERO,
                'percentage' => null,
                'subjectcount' => 1,
                'suppressed' => 0,
            ];
            $payloadjson = canonical_json::encode([
                'type' => snapshot_service::ITEM_POPULATION,
                'identity' => $identity,
                'index' => $index,
                'payload' => ['subjectref' => $subjectref, 'populationat' => $now],
            ]);
            $item = (object) [
                'snapshotid' => $snapshotid,
                'itemtype' => snapshot_service::ITEM_POPULATION,
                'stablekey' => hash('sha256', canonical_json::encode([
                    'type' => snapshot_service::ITEM_POPULATION,
                    'identity' => $identity,
                ])),
                'subjectref' => $subjectref,
                'sourceuuid' => null,
                'sourceid' => null,
                'cinstid' => null,
                'itemverid' => null,
                'state' => null,
                'bandcode' => null,
                'numerator' => decimal::ZERO,
                'denominator' => decimal::ZERO,
                'percentage' => null,
                'subjectcount' => 1,
                'suppressed' => 0,
                'payloadjson' => $payloadjson,
                'payloadhash' => hash('sha256', $payloadjson),
                'sortorder' => $sortorder,
            ];
            $item->id = $DB->insert_record('local_outcomemap_snapitem', $item);
            $items[] = $item;
        }
        $hashes = array_map(static function(\stdClass $item): array {
            return ['key' => (string) $item->stablekey, 'hash' => (string) $item->payloadhash];
        }, $items);
        $record->payloadhash = hash('sha256', canonical_json::encode($hashes));
        $record->manifesthash = hash('sha256', canonical_json::encode(
            audit_lineage_service::manifest($record, count($items))
        ));
        $DB->update_record('local_outcomemap_snapshot', $record);
        audit_lineage_service::verify_snapshot_payload($record, $items);
        audit_lineage_service::verify_manifest($record, count($items));
        return $snapshotid;
    }

    /**
     * Build a byte-stable fingerprint proving privacy erasure did not mutate a snapshot.
     *
     * @param int $snapshotid Snapshot ID.
     * @return string Canonical fingerprint.
     */
    private function snapshot_fingerprint(int $snapshotid): string {
        global $DB;

        $snapshot = $DB->get_record('local_outcomemap_snapshot', ['id' => $snapshotid], '*', MUST_EXIST);
        $items = array_values($DB->get_records(
            'local_outcomemap_snapitem',
            ['snapshotid' => $snapshotid],
            'sortorder ASC, id ASC'
        ));
        return canonical_json::encode([
            'snapshot' => (array) $snapshot,
            'items' => array_map(static fn(\stdClass $item): array => (array) $item, $items),
        ]);
    }

    /**
     * Build a fingerprint over the append-only audit columns for one context.
     *
     * Privacy deletion may redact actor and reason, but it must never remove,
     * reorder, or re-time retained audit history. Those mutable columns are
     * therefore excluded here and asserted separately.
     *
     * @param int $contextid Context ID.
     * @return string Canonical fingerprint.
     */
    private function audit_fingerprint(int $contextid): string {
        global $DB;

        $records = $DB->get_records(
            'local_outcomemap_audit',
            ['contextid' => $contextid],
            'id ASC',
            'id,eventuuid,action,objecttype,objectid,objectuuid,correlationid,timecreated'
        );
        return canonical_json::encode(
            array_map(static fn(\stdClass $record): array => (array) $record, array_values($records))
        );
    }

    /**
     * Load and verify one immutable frozen snapshot.
     *
     * @param int $snapshotid Snapshot ID.
     * @return \stdClass Snapshot.
     */
    private function verified_snapshot(int $snapshotid): \stdClass {
        global $DB;

        $snapshot = $DB->get_record('local_outcomemap_snapshot', ['id' => $snapshotid], '*', MUST_EXIST);
        $items = array_values($DB->get_records(
            'local_outcomemap_snapitem',
            ['snapshotid' => $snapshotid],
            'sortorder ASC, id ASC'
        ));
        audit_lineage_service::verify_snapshot_payload($snapshot, $items);
        audit_lineage_service::verify_manifest($snapshot, count($items));
        return $snapshot;
    }
}
