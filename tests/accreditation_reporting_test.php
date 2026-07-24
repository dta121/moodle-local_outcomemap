<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');

use local_outcomemap\local\canonical_json;
use local_outcomemap\local\decimal;
use local_outcomemap\local\service\accreditation_export_service;
use local_outcomemap\local\service\aggregate_service;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\service\snapshot_service;
use local_outcomemap\local\service\suppression_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Tests deterministic Milestone 6 accreditation reporting and exports.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class accreditation_reporting_test extends \advanced_testcase {
    /** @var \stdClass Independent manager reviewer. */
    private $reviewer;

    /**
     * Create a system manager who can independently freeze snapshots.
     *
     * @return \stdClass
     */
    private function create_reviewer(): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $user->id, \context_system::instance()->id);
        return $user;
    }

    /**
     * Insert an approved exact policy version for a production snapshot fixture.
     *
     * @param string $policytype Policy type.
     * @param array $config Typed policy configuration.
     * @param int $effectivefrom Effective start.
     * @param string|null $policyuuid Existing stable policy UUID for a new version.
     * @param int $version Exact version.
     * @return int Policy version ID.
     */
    private function insert_policy(
        string $policytype,
        array $config,
        int $effectivefrom,
        ?string $policyuuid = null,
        int $version = 1
    ): int {
        global $DB;

        $configjson = canonical_json::encode($config);
        $now = time();
        return (int) $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => $policyuuid ?? uuid::generate(),
            'version' => $version,
            'policytype' => $policytype,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'scopeid' => null,
            'name' => 'M6 ' . $policytype . ' v' . $version,
            'configjson' => $configjson,
            'confighash' => hash('sha256', $configjson),
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => $this->reviewer->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
    }

    /**
     * Build a complete two-learner, exact-version snapshot fixture.
     *
     * The two learner results each contribute 12.75 / 15, so the exported
     * aggregate must be reconstructable as 25.5 / 30 = 85 percent.
     *
     * @param int $threshold Minimum cohort size.
     * @return array Fixture identifiers.
     */
    private function create_snapshot_fixture(int $threshold = 2): array {
        global $CFG, $DB;

        if (empty($CFG->passwordsaltmain)) {
            $CFG->passwordsaltmain = 'local_outcomemap_phpunit_snapshot_secret';
        }
        set_config('version', 2026072603, 'local_outcomemap');
        $now = time();
        $effectivefrom = $now - DAYSECS;
        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'M6-ACCREDITATION',
            'fullname' => 'M6 accreditation reporting',
        ]);
        $learners = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        $cohort = $this->getDataGenerator()->create_cohort([
            'name' => 'M6 accreditation cohort',
            'idnumber' => 'M6-ACCREDITATION',
        ]);
        foreach ($learners as $learner) {
            cohort_add_member($cohort->id, $learner->id);
        }

        $programid = (int) $DB->insert_record('local_outcomemap_program', (object) [
            'uuid' => uuid::generate(),
            'code' => 'M6-PROGRAM',
            'name' => 'M6 reporting program',
            'description' => null,
            'externalid' => null,
            'programtype' => program_service::TYPE_SPECIALIZATION,
            'credential' => program_service::CREDENTIAL_CERTIFICATE,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $catalogcourseid = (int) $DB->insert_record('local_outcomemap_course', (object) [
            'uuid' => uuid::generate(),
            'code' => 'M6-COURSE',
            'name' => 'M6 reporting course',
            'description' => null,
            'siskey' => null,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $courseinstanceid = (int) $DB->insert_record('local_outcomemap_cinst', (object) [
            'uuid' => uuid::generate(),
            'courseid' => $catalogcourseid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-T1',
            'externalid' => null,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
            'confirmedby' => $this->reviewer->id,
            'confirmedat' => $now,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $membershipuuid = uuid::generate();
        $DB->insert_record('local_outcomemap_progcourse', (object) [
            'uuid' => $membershipuuid,
            'programid' => $programid,
            'courseid' => $catalogcourseid,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => $this->reviewer->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
        $frameworkid = (int) $DB->insert_record('local_outcomemap_fw', (object) [
            'uuid' => uuid::generate(),
            'code' => 'M6-PLO',
            'name' => 'M6 program outcomes',
            'description' => null,
            'ownertype' => framework_service::OWNER_PROGRAM,
            'ownerid' => $programid,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $outcomeid = (int) $DB->insert_record('local_outcomemap_item', (object) [
            'uuid' => uuid::generate(),
            'frameworkid' => $frameworkid,
            'code' => 'PLO1',
            'status' => workflow::APPROVED,
            'createdby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $outcomeversionid = (int) $DB->insert_record('local_outcomemap_itemver', (object) [
            'uuid' => uuid::generate(),
            'itemid' => $outcomeid,
            'version' => 1,
            'statement' => 'Demonstrate the accredited program outcome.',
            'shortstatement' => 'Demonstrate the program outcome.',
            'bloomlevel' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'changereason' => null,
            'createdby' => null,
            'approvedby' => $this->reviewer->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        $selectionpolicyid = $this->insert_policy(
            policy_service::TYPE_ATTEMPT_SELECTION,
            ['method' => policy_service::METHOD_LATEST_COMPLETED],
            $effectivefrom
        );
        $calculationpolicyid = $this->insert_policy(
            policy_service::TYPE_CALCULATION,
            [
                'minitems' => 1,
                'minweightedpossible' => '0.0000000000',
                'requiremanualgrading' => true,
                'displayscale' => 1,
            ],
            $effectivefrom
        );
        $accreditationpolicyid = $this->insert_policy(
            policy_service::TYPE_ACCREDITATION,
            [
                'mincohortsize' => $threshold,
                'populationsource' => suppression_service::POPULATION_MOODLE_COHORT,
                'retentionbasis' => suppression_service::RETENTION_ANONYMISED,
                'aggregationmethod' => suppression_service::AGGREGATION_METHOD,
                'correctionmethod' => suppression_service::CORRECTION_METHOD,
            ],
            $effectivefrom
        );
        $accreditationpolicy = $DB->get_record(
            'local_outcomemap_policy',
            ['id' => $accreditationpolicyid],
            '*',
            MUST_EXIST
        );

        $mappingid = (int) $DB->insert_record('local_outcomemap_qmap', (object) [
            'mappinguuid' => uuid::generate(),
            'version' => 1,
            'questionversionid' => 910001,
            'questionid' => 900001,
            'itemverid' => $outcomeversionid,
            'role' => 'assesses',
            'weight' => '1.0000000000',
            'notes' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => $this->reviewer->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        $resultids = [];
        foreach ($learners as $index => $learner) {
            $evidenceuuid = uuid::generate();
            $DB->insert_record('local_outcomemap_evidence', (object) [
                'uuid' => $evidenceuuid,
                'lineageuuid' => uuid::generate(),
                'dedupekey' => hash('sha256', 'm6-evidence-' . $index),
                'sourceevidenceid' => null,
                'relationpathjson' => canonical_json::encode([]),
                'cinstid' => $courseinstanceid,
                'userid' => $learner->id,
                'assessmentcmid' => 810001,
                'quizattemptid' => 820001 + $index,
                'questionusageid' => 830001 + $index,
                'slot' => 1,
                'questionattemptid' => 840001 + $index,
                'questionversionid' => 910001,
                'questionid' => 900001,
                'itemverid' => $outcomeversionid,
                'mappingid' => $mappingid,
                'policyid' => $selectionpolicyid,
                'evidencetype' => calculation_service::TYPE_DIRECT,
                'rawfraction' => '0.8500000000',
                'rawmark' => '12.7500000000',
                'maxmark' => '15.0000000000',
                'mappingweight' => '1.0000000000',
                'relationweight' => '1.0000000000',
                'weightedearned' => '12.7500000000',
                'weightedpossible' => '15.0000000000',
                'gradingstate' => calculation_service::GRADING_GRADED,
                'attempttime' => $now - 100,
                'gradingtime' => $now - 50,
                'supersededby' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $lineagejson = canonical_json::encode([['uuid' => $evidenceuuid]]);
            $resultids[] = (int) $DB->insert_record('local_outcomemap_result', (object) [
                'uuid' => uuid::generate(),
                'resultkey' => hash('sha256', 'm6-result-' . $index),
                'version' => 1,
                'cinstid' => $courseinstanceid,
                'userid' => $learner->id,
                'scopetype' => calculation_service::SCOPE_COURSE,
                'scopeid' => $courseinstanceid,
                'periodcode' => '2026-T1',
                'itemverid' => $outcomeversionid,
                'policyid' => $calculationpolicyid,
                'numerator' => '12.7500000000',
                'denominator' => '15.0000000000',
                'percentage' => '85.0000000000',
                'distinctitems' => 1,
                'bandid' => null,
                'state' => calculation_service::STATE_CALCULATED,
                'stale' => 0,
                'algoversion' => calculation_service::ALGO_VERSION,
                'inputhash' => hash('sha256', 'm6-input-' . $index),
                'lineagejson' => $lineagejson,
                'lineagehash' => hash('sha256', $lineagejson),
                'supersededby' => null,
                'timecalculated' => $now,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        return [
            'programid' => $programid,
            'courseinstanceid' => $courseinstanceid,
            'cohortid' => (int) $cohort->id,
            'outcomeversionid' => $outcomeversionid,
            'resultids' => $resultids,
            'accreditationpolicyuuid' => (string) $accreditationpolicy->policyuuid,
            'effectivefrom' => $effectivefrom,
        ];
    }

    /**
     * Test denominator-weighted aggregation and explicit suppression.
     */
    public function test_aggregate_sums_components_once_and_applies_suppression(): void {
        $this->resetAfterTest(true);
        $base = [
            'cinstid' => 7,
            'cinstuuid' => uuid::generate(),
            'courseuuid' => uuid::generate(),
            'coursecode' => 'M6-COURSE',
            'cinstperiod' => '2026-T1',
            'itemverid' => 11,
            'outcomeuuid' => uuid::generate(),
            'outcomeversionuuid' => uuid::generate(),
            'outcomeversion' => 1,
            'outcomecode' => 'PLO1',
            'frameworkuuid' => uuid::generate(),
            'frameworkcode' => 'M6-PLO',
            'state' => calculation_service::STATE_CALCULATED,
            'percentage' => '85.0000000000',
            'numerator' => '12.7500000000',
            'denominator' => '15.0000000000',
        ];
        $results = [
            (object) ($base + ['userid' => 101, 'uuid' => uuid::generate()]),
            (object) ($base + ['userid' => 102, 'uuid' => uuid::generate()]),
        ];
        $policy = (object) ['config' => [
            'mincohortsize' => 2,
            'populationsource' => suppression_service::POPULATION_MOODLE_COHORT,
            'retentionbasis' => suppression_service::RETENTION_ANONYMISED,
        ]];

        $aggregates = aggregate_service::aggregate($results, $policy);
        $this->assertCount(1, $aggregates['course']);
        $this->assertCount(1, $aggregates['program']);
        foreach ([$aggregates['course'][0], $aggregates['program'][0]] as $aggregate) {
            $this->assertSame('25.5000000000', $aggregate['numerator']);
            $this->assertSame('30.0000000000', $aggregate['denominator']);
            $this->assertSame('85.0000000000', $aggregate['percentage']);
            $this->assertSame(2, $aggregate['subjectcount']);
            $this->assertFalse($aggregate['suppressed']);
        }

        $policy->config['mincohortsize'] = 3;
        $suppressed = aggregate_service::aggregate($results, $policy);
        $this->assertTrue($suppressed['course'][0]['suppressed']);
        $this->assertTrue($suppressed['program'][0]['suppressed']);
    }

    /**
     * Test an authorized snapshot creator may finalize when independent approval is disabled.
     */
    public function test_snapshot_creator_can_freeze_when_independent_approval_disabled(): void {
        global $USER;

        $this->resetAfterTest(true);
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->assertFalse(workflow::requires_independent_approval());
        $this->setAdminUser();
        $creatorid = (int) $USER->id;
        $this->reviewer = $this->create_reviewer();
        $fixture = $this->create_snapshot_fixture();

        $snapshotid = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => '2026-T1',
            'cohortid' => $fixture['cohortid'],
            'notes' => 'Creator-finalized accreditation baseline',
        ]);
        snapshot_service::freeze($snapshotid);

        $snapshot = snapshot_service::get($snapshotid);
        $this->assertSame(snapshot_service::STATUS_FROZEN, $snapshot->status);
        $this->assertSame($creatorid, (int) $snapshot->createdby);
        $this->assertSame($creatorid, (int) $snapshot->approvedby);
        $this->assertNotNull($snapshot->approvedat);
        $this->assertNotEmpty($snapshot->manifesthash);
    }

    /**
     * Test independent freeze, immutable corrections, redaction, hashes, and export reconstruction.
     */
    public function test_snapshot_versions_are_immutable_and_exports_are_reconstructable(): void {
        global $DB;

        $this->resetAfterTest(true);
        unset_config('requireapproval', 'local_outcomemap');
        $this->assertTrue(workflow::requires_independent_approval());
        $this->setAdminUser();
        $this->reviewer = $this->create_reviewer();
        $fixture = $this->create_snapshot_fixture();

        $snapshotid = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => '2026-T1',
            'cohortid' => $fixture['cohortid'],
            'notes' => 'M6 accreditation baseline',
        ]);
        try {
            snapshot_service::freeze($snapshotid);
            $this->fail('A snapshot creator must not freeze their own capture.');
        } catch (validation_exception $exception) {
            $this->assertSame('creatorcannotapprove', $exception->errorcode);
        }

        $this->setUser($this->reviewer);
        snapshot_service::freeze($snapshotid);
        $snapshotv1 = snapshot_service::get($snapshotid);
        $itemsv1 = snapshot_service::items($snapshotid);
        snapshot_service::verify($snapshotv1, $itemsv1);
        $this->assertSame(snapshot_service::STATUS_FROZEN, $snapshotv1->status);
        $this->assertSame(1, (int) $snapshotv1->version);
        $this->assertSame(2, (int) $snapshotv1->populationcount);
        $this->assertNotEmpty($snapshotv1->payloadhash);
        $this->assertNotEmpty($snapshotv1->manifesthash);

        $packagev1 = accreditation_export_service::package($snapshotid);
        $programitems = array_values(array_filter(
            $packagev1['items'],
            static fn(array $item): bool => $item['itemtype'] === snapshot_service::ITEM_PROGRAM
        ));
        $this->assertCount(1, $programitems);
        $programpayload = $programitems[0]['payload']['payload'];
        $this->assertSame(program_service::TYPE_SPECIALIZATION, $programpayload['programtype']);
        $this->assertSame(program_service::CREDENTIAL_CERTIFICATE, $programpayload['credential']);
        $this->assertSame('local_outcomemap-accreditation-export-v1', $packagev1['schema']);
        $this->assertSame('standard', $packagev1['mode']);
        foreach (['snapshotuuid', 'version', 'policyid', 'pluginversion', 'algoversion',
            'payloadhash', 'manifesthash', 'itemcount'] as $manifestfield) {
            $this->assertArrayHasKey($manifestfield, $packagev1['manifest']);
        }
        $this->assertSame((string) $snapshotv1->manifesthash, $packagev1['manifest']['manifesthash']);

        $numerator = decimal::ZERO;
        $denominator = decimal::ZERO;
        $types = [];
        foreach ($packagev1['items'] as $item) {
            $types[] = $item['itemtype'];
            if ($item['itemtype'] !== snapshot_service::ITEM_RESULT) {
                continue;
            }
            $numerator = decimal::add($numerator, $item['payload']['payload']['numerator']);
            $denominator = decimal::add($denominator, $item['payload']['payload']['denominator']);
            $this->assertArrayHasKey('subjectref', $item['payload']['payload']);
            $this->assertArrayNotHasKey('userid', $item['payload']['payload']);
        }
        $reconstructed = decimal::div(decimal::mul($numerator, '100'), $denominator);
        $this->assertSame('25.5000000000', $numerator);
        $this->assertSame('30.0000000000', $denominator);
        $this->assertSame('85.0000000000', $reconstructed);
        foreach ([
            snapshot_service::ITEM_OUTCOME_VERSION,
            snapshot_service::ITEM_POLICY_VERSION,
            snapshot_service::ITEM_MAPPING_VERSION,
            snapshot_service::ITEM_RESULT,
            snapshot_service::ITEM_COURSE_AGGREGATE,
            snapshot_service::ITEM_PROGRAM_AGGREGATE,
        ] as $requiredtype) {
            $this->assertContains($requiredtype, $types);
        }
        $this->assertNotContains(snapshot_service::ITEM_POPULATION, $types);
        $this->assertNotContains(snapshot_service::ITEM_EVIDENCE, $types);

        $csvrows = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim(
            accreditation_export_service::summary_csv($snapshotid)
        )));
        $csvheader = array_shift($csvrows);
        $programrow = null;
        foreach ($csvrows as $csvrow) {
            $row = array_combine($csvheader, $csvrow);
            if ($row['item_type'] === snapshot_service::ITEM_PROGRAM_AGGREGATE) {
                $programrow = $row;
                break;
            }
        }
        $this->assertNotNull($programrow);
        $this->assertSame('25.5000000000', $programrow['numerator']);
        $this->assertSame('30.0000000000', $programrow['denominator']);
        $this->assertSame('85.0000000000', $programrow['percentage']);
        $this->assertSame('0', $programrow['suppressed']);

        $jsonv1 = accreditation_export_service::json($snapshotid);
        $DB->set_field('local_outcomemap_result', 'numerator', '0.0000000000', [
            'id' => $fixture['resultids'][0],
        ]);
        $DB->set_field('local_outcomemap_result', 'percentage', '0.0000000000', [
            'id' => $fixture['resultids'][0],
        ]);
        $this->assertSame($jsonv1, accreditation_export_service::json($snapshotid),
            'A frozen export must not change when live results change.');

        $this->insert_policy(
            policy_service::TYPE_ACCREDITATION,
            [
                'mincohortsize' => 3,
                'populationsource' => suppression_service::POPULATION_MOODLE_COHORT,
                'retentionbasis' => suppression_service::RETENTION_ANONYMISED,
                'aggregationmethod' => suppression_service::AGGREGATION_METHOD,
                'correctionmethod' => suppression_service::CORRECTION_METHOD,
            ],
            $fixture['effectivefrom'],
            $fixture['accreditationpolicyuuid'],
            2
        );
        $this->setUser($this->reviewer);
        $snapshotv2id = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => '2026-T1',
            'cohortid' => $fixture['cohortid'],
            'previousid' => $snapshotid,
            'correctionreason' => 'Apply the approved higher disclosure threshold.',
        ]);
        $snapshotv2draft = snapshot_service::get($snapshotv2id);
        $this->assertSame($snapshotv1->snapshotuuid, $snapshotv2draft->snapshotuuid);
        $this->assertSame(2, (int) $snapshotv2draft->version);
        $this->assertSame($snapshotid, (int) $snapshotv2draft->previousid);
        $this->assertSame(snapshot_service::STATUS_FROZEN, snapshot_service::get($snapshotid)->status);
        $this->assertSame($jsonv1, accreditation_export_service::json($snapshotid));

        $this->setAdminUser();
        snapshot_service::freeze($snapshotv2id);
        $packagev2 = accreditation_export_service::package($snapshotv2id);
        $programaggregate = null;
        foreach ($packagev2['items'] as $item) {
            if ($item['itemtype'] === snapshot_service::ITEM_PROGRAM_AGGREGATE) {
                $programaggregate = $item;
                break;
            }
        }
        $this->assertNotNull($programaggregate);
        $this->assertTrue($programaggregate['redacted']);
        $this->assertSame(2, $programaggregate['payload']['index']['subjectcount']);
        $this->assertSame(1, $programaggregate['payload']['index']['suppressed']);
        $this->assertNull($programaggregate['payload']['index']['numerator']);
        $this->assertNull($programaggregate['payload']['index']['denominator']);
        $this->assertNull($programaggregate['payload']['index']['percentage']);
        $this->assertNotContains(
            snapshot_service::ITEM_RESULT,
            array_column($packagev2['items'], 'itemtype'),
            'Suppressed subject rows must not appear in a standard export.'
        );

        $exporter = $this->getDataGenerator()->create_user();
        $exportroleid = create_role('M6 accreditation exporter', 'm6accreditationexporter', '');
        assign_capability(
            'local/outcomemap:exportaccreditation',
            CAP_ALLOW,
            $exportroleid,
            \context_system::instance()->id
        );
        role_assign($exportroleid, $exporter->id, \context_system::instance()->id);
        $this->setUser($exporter);
        $this->assertSame('standard', accreditation_export_service::package($snapshotv2id)['mode']);
        try {
            accreditation_export_service::package($snapshotv2id, true);
            $this->fail('Evidence-detail export must require the stronger all-results capability.');
        } catch (\required_capability_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->setAdminUser();
        $evidencepackage = accreditation_export_service::package($snapshotv2id, true);
        $this->assertSame('evidence_detail', $evidencepackage['mode']);
        $evidencetypes = array_column($evidencepackage['items'], 'itemtype');
        $this->assertContains(snapshot_service::ITEM_POPULATION, $evidencetypes);
        $this->assertContains(snapshot_service::ITEM_EVIDENCE, $evidencetypes);
        $this->assertContains(snapshot_service::ITEM_RESULT, $evidencetypes);

        accreditation_export_service::record_export($snapshotid, 'json');
        $exportaudit = $DB->get_record('local_outcomemap_audit', [
            'action' => 'export_snapshot',
            'objecttype' => 'snapshot',
            'objectid' => $snapshotid,
        ], '*', MUST_EXIST);
        $exportdetails = json_decode($exportaudit->afterjson, true);
        $this->assertSame('json', $exportdetails['format']);
        $this->assertSame('standard', $exportdetails['mode']);
        $this->assertSame(1, $exportdetails['snapshotversion']);
        $this->assertSame($snapshotv1->payloadhash, $exportdetails['payloadhash']);
        $this->assertSame($snapshotv1->manifesthash, $exportdetails['manifesthash']);

        $DB->set_field('local_outcomemap_snapshot', 'manifesthash', str_repeat('0', 64), [
            'id' => $snapshotid,
        ]);
        try {
            accreditation_export_service::package($snapshotid);
            $this->fail('A snapshot with a tampered manifest must never be exported.');
        } catch (validation_exception $exception) {
            $this->assertSame('snapshotintegrityfailure', $exception->errorcode);
        }
        $DB->set_field('local_outcomemap_snapshot', 'manifesthash', $snapshotv1->manifesthash, [
            'id' => $snapshotid,
        ]);

        $tampereditem = reset($itemsv1);
        $DB->set_field('local_outcomemap_snapitem', 'payloadjson', '{}', ['id' => $tampereditem->id]);
        try {
            accreditation_export_service::package($snapshotid);
            $this->fail('Tampered snapshot items must never be exported.');
        } catch (validation_exception $exception) {
            $this->assertSame('snapshotintegrityfailure', $exception->errorcode);
        }
    }
}
