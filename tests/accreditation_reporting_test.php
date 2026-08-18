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

namespace local_outcomemap;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');

use local_outcomemap\local\canonical_json;
use local_outcomemap\local\decimal;
use local_outcomemap\local\service\accreditation_export_service;
use local_outcomemap\local\service\aggregate_service;
use local_outcomemap\local\service\audit_lineage_service;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\local\service\snapshot_service;
use local_outcomemap\local\service\suppression_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;
use local_outcomemap\output\snapshot_report;
use local_outcomemap\output\snapshots_page;

/**
 * Tests deterministic Milestone 6 accreditation reporting and exports.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_outcomemap\local\service\snapshot_service
 */
final class accreditation_reporting_test extends \advanced_testcase {
    /**
     * @var \stdClass Independent manager reviewer.
     */
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

        if (!isset($this->reviewer)) {
            $this->reviewer = $this->create_reviewer();
        }
        if (empty($CFG->passwordsaltmain)) {
            $CFG->passwordsaltmain = 'local_outcomemap_phpunit_snapshot_secret';
        }
        set_config('version', 2026072701, 'local_outcomemap');
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
                'achievementminpercent' => '70',
                'benchmarkpercent' => '70',
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
     * * Test denominator-weighted aggregation and explicit suppression.
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
            'achievementminpercent' => '70',
            'benchmarkpercent' => '70',
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
            // Both learners scored 85%, so the attainment rate is 100% and the
            // 70% benchmark is met. The pooled score and the rate are distinct
            // statistics that happen to agree here only because both learners
            // sit on the same side of the criterion.
            $this->assertSame(2, $aggregate['assessedcount']);
            $this->assertSame(2, $aggregate['metcount']);
            $this->assertSame(0, $aggregate['notmetcount']);
            $this->assertSame('100.0000000000', $aggregate['attainmentpercent']);
            $this->assertSame('70.0000000000', $aggregate['achievementminpercent']);
            $this->assertSame('70.0000000000', $aggregate['benchmarkpercent']);
            $this->assertTrue($aggregate['benchmarkmet']);
        }

        $policy->config['mincohortsize'] = 3;
        $suppressed = aggregate_service::aggregate($results, $policy);
        $this->assertTrue($suppressed['course'][0]['suppressed']);
        $this->assertTrue($suppressed['program'][0]['suppressed']);
    }

    /**
     * * Test the attainment rate is a separate statistic from the pooled score.
     */
    public function test_attainment_rate_counts_learners_not_marks(): void {
        $this->resetAfterTest(true);
        $base = $this->aggregate_result_base();
        $results = [];
        // Two learners well above the criterion and two well below it. The
        // pooled score clears the benchmark while only half the learners do.
        foreach ([201 => '95', 202 => '95', 203 => '40', 204 => '40'] as $userid => $score) {
            $results[] = (object) array_merge($base, [
                'userid' => $userid,
                'uuid' => uuid::generate(),
                'numerator' => $score . '.0000000000',
                'denominator' => '100.0000000000',
                'percentage' => $score . '.0000000000',
            ]);
        }
        $policy = $this->aggregate_policy('70', '70');

        $aggregates = aggregate_service::aggregate($results, $policy);
        $aggregate = $aggregates['program'][0];
        $this->assertSame(
            '67.5000000000',
            $aggregate['percentage'],
            'The pooled score divides summed marks, not learners.'
        );
        $this->assertSame(4, $aggregate['assessedcount']);
        $this->assertSame(2, $aggregate['metcount']);
        $this->assertSame(2, $aggregate['notmetcount']);
        $this->assertSame('50.0000000000', $aggregate['attainmentpercent']);
        $this->assertFalse(
            $aggregate['benchmarkmet'],
            'Half the learners met the criterion, short of the 70% benchmark.'
        );
    }

    /**
     * * Test a program row pools each learner's own evidence before judging them.
     */
    public function test_program_attainment_judges_each_learner_once(): void {
        $this->resetAfterTest(true);
        $base = $this->aggregate_result_base();
        // One learner assessed on the same outcome in two course instances:
        // 60% in one and 80% in the other, pooling to exactly the criterion.
        $results = [
            (object) array_merge($base, [
                'userid' => 301,
                'uuid' => uuid::generate(),
                'cinstid' => 7,
                'numerator' => '6.0000000000',
                'denominator' => '10.0000000000',
                'percentage' => '60.0000000000',
            ]),
            (object) array_merge($base, [
                'userid' => 301,
                'uuid' => uuid::generate(),
                'cinstid' => 8,
                'numerator' => '8.0000000000',
                'denominator' => '10.0000000000',
                'percentage' => '80.0000000000',
            ]),
        ];
        $policy = $this->aggregate_policy('70', '70');

        $aggregates = aggregate_service::aggregate($results, $policy);
        $this->assertCount(
            2,
            $aggregates['course'],
            'Each course instance keeps its own row.'
        );
        $this->assertCount(1, $aggregates['program']);
        $program = $aggregates['program'][0];
        $this->assertSame(1, $program['subjectcount']);
        $this->assertSame(
            1,
            $program['assessedcount'],
            'One learner contributing two results counts once.'
        );
        $this->assertSame(
            1,
            $program['metcount'],
            'Pooled 14/20 is exactly 70%, and the criterion is inclusive.'
        );
        $this->assertSame('100.0000000000', $program['attainmentpercent']);
        $this->assertTrue($program['benchmarkmet']);

        $bycinst = [];
        foreach ($aggregates['course'] as $row) {
            $bycinst[(int) $row['cinstid']] = $row;
        }
        $this->assertSame(
            0,
            $bycinst[7]['metcount'],
            'Judged on that course instance alone the learner falls short.'
        );
        $this->assertSame(1, $bycinst[8]['metcount']);
    }

    /**
     * * Test a rate is not calculable when no learner has a calculated result.
     */
    public function test_attainment_rate_absent_without_calculated_results(): void {
        $this->resetAfterTest(true);
        $base = $this->aggregate_result_base();
        $results = [
            (object) array_merge($base, [
                'userid' => 401,
                'uuid' => uuid::generate(),
                'state' => calculation_service::STATE_INSUFFICIENT,
                'percentage' => null,
            ]),
        ];

        $aggregate = aggregate_service::aggregate($results, $this->aggregate_policy('70', '70'))['program'][0];
        $this->assertSame(0, $aggregate['assessedcount']);
        $this->assertSame(0, $aggregate['metcount']);
        $this->assertNull($aggregate['attainmentpercent']);
        $this->assertNull(
            $aggregate['benchmarkmet'],
            'An uncalculable rate must not read as a failed benchmark.'
        );
    }

    /**
     * * Test an accreditation policy without a stated criterion is rejected.
     */
    public function test_accreditation_policy_requires_criterion_and_benchmark(): void {
        $this->resetAfterTest(true);
        $config = [
            'mincohortsize' => 2,
            'populationsource' => suppression_service::POPULATION_MOODLE_COHORT,
            'retentionbasis' => suppression_service::RETENTION_ANONYMISED,
            'achievementminpercent' => '70',
            'benchmarkpercent' => '70',
        ];
        foreach (['achievementminpercent', 'benchmarkpercent'] as $field) {
            $missing = $config;
            unset($missing[$field]);
            try {
                suppression_service::normalize_config($missing);
                $this->fail('A missing ' . $field . ' must be rejected.');
            } catch (validation_exception $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
        foreach (['-1', '100.5', 'abc', ''] as $invalid) {
            try {
                suppression_service::normalize_config(
                    ['benchmarkpercent' => $invalid] + $config
                );
                $this->fail('Benchmark "' . $invalid . '" must be rejected.');
            } catch (validation_exception $e) {
                $this->assertInstanceOf(validation_exception::class, $e);
            }
        }
        $normalized = suppression_service::normalize_config($config);
        $this->assertSame('70.0000000000', $normalized['achievementminpercent']);
        $this->assertSame('70.0000000000', $normalized['benchmarkpercent']);
    }

    /**
     * * Test a snapshot frozen before the attainment columns still verifies.
     */
    public function test_verification_accepts_index_without_attainment_columns(): void {
        global $DB;
        $this->resetAfterTest(true);
        // One actor is enough here: the subject is payload verification, not the
        // approval separation exercised elsewhere.
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->setAdminUser();
        $this->reviewer = $this->create_reviewer();
        $fixture = $this->create_snapshot_fixture();
        $snapshotid = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => '2026-T1',
            'cohortid' => $fixture['cohortid'],
        ]);
        snapshot_service::freeze($snapshotid);

        // Rewrite every item's stored index to the pre-upgrade key set, exactly
        // as a snapshot frozen under an earlier release holds it. The columns
        // keep their values: only the hashed payload loses the newer keys.
        $legacykeys = ['assessedcount', 'metcount', 'attainmentpercent', 'criterionpercent',
            'benchmarkpercent', 'benchmarkmet'];
        $hashes = [];
        foreach (snapshot_service::items($snapshotid) as $item) {
            $decoded = json_decode($item->payloadjson, true);
            foreach ($legacykeys as $key) {
                unset($decoded['index'][$key]);
            }
            $payloadjson = canonical_json::encode($decoded);
            $DB->update_record('local_outcomemap_snapitem', (object) [
                'id' => $item->id,
                'payloadjson' => $payloadjson,
                'payloadhash' => hash('sha256', $payloadjson),
            ]);
            $hashes[] = ['key' => (string) $item->stablekey, 'hash' => hash('sha256', $payloadjson)];
        }
        $DB->set_field(
            'local_outcomemap_snapshot',
            'payloadhash',
            hash('sha256', canonical_json::encode($hashes)),
            ['id' => $snapshotid]
        );

        $snapshot = snapshot_service::get($snapshotid);
        $items = snapshot_service::items($snapshotid);
        $this->assertSame(
            $snapshot->payloadhash,
            audit_lineage_service::verify_snapshot_payload($snapshot, $items),
            'A snapshot frozen before the attainment columns existed must stay verifiable.'
        );

        // An index key the normalizer never produces is still rejected, so the
        // relaxation cannot be used to smuggle content past verification.
        $first = reset($items);
        $decoded = json_decode($first->payloadjson, true);
        $decoded['index']['injected'] = 'x';
        $payloadjson = canonical_json::encode($decoded);
        $DB->update_record('local_outcomemap_snapitem', (object) [
            'id' => $first->id,
            'payloadjson' => $payloadjson,
            'payloadhash' => hash('sha256', $payloadjson),
        ]);
        try {
            audit_lineage_service::verify_snapshot_payload(
                snapshot_service::get($snapshotid),
                snapshot_service::items($snapshotid)
            );
            $this->fail('An unknown index key must fail verification.');
        } catch (validation_exception $e) {
            $this->assertSame('snapshotintegrityfailure', $e->errorcode);
        }
    }

    /**
     * Build the shared aggregate result fixture.
     *
     * @return array Result fields without learner-specific values.
     */
    private function aggregate_result_base(): array {
        return [
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
    }

    /**
     * Build an in-memory accreditation policy for aggregation tests.
     *
     * @param string $criterion Achievement criterion percentage.
     * @param string $benchmark Aggregate benchmark percentage.
     * @return \stdClass
     */
    private function aggregate_policy(string $criterion, string $benchmark): \stdClass {
        return (object) ['config' => [
            'mincohortsize' => 1,
            'populationsource' => suppression_service::POPULATION_MOODLE_COHORT,
            'retentionbasis' => suppression_service::RETENTION_ANONYMISED,
            'achievementminpercent' => $criterion,
            'benchmarkpercent' => $benchmark,
        ]];
    }

    /**
     * * Test an authorized snapshot creator may finalize when independent approval is disabled.
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
     * * Test a snapshot version is withdrawable only from the end of its lineage.
     */
    public function test_snapshot_deletion_removes_captured_rows_from_the_newest_version_only(): void {
        global $DB, $PAGE;

        $this->resetAfterTest(true);
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->setAdminUser();
        $fixture = $this->create_snapshot_fixture();

        $v1id = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => '2026-T1',
            'cohortid' => $fixture['cohortid'],
            'notes' => 'Withdrawal fixture',
        ]);
        snapshot_service::freeze($v1id);
        $v2id = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => '2026-T1',
            'cohortid' => $fixture['cohortid'],
            'previousid' => $v1id,
            'correctionreason' => 'Recapture for the withdrawal fixture.',
        ]);
        $v1uuid = snapshot_service::get($v1id)->snapshotuuid;
        $v2rows = $DB->count_records('local_outcomemap_snapitem', ['snapshotid' => $v2id]);
        $this->assertGreaterThan(0, $v2rows);

        // The list offers withdrawal at the end of the lineage only, so the
        // version a correction was built on stays out of reach.
        $context = (new snapshots_page())->export_for_template($PAGE->get_renderer('core'));
        $rows = $context['groups'][0]['rows'];
        $this->assertSame([2, 1], array_column($rows, 'version'));
        $this->assertSame([true, false], array_column($rows, 'candelete'));
        $this->assertFalse(
            (new snapshot_report($v1id))->export_for_template($PAGE->get_renderer('core'))['candelete'],
            'The report of a corrected version must not offer withdrawal either.'
        );

        try {
            snapshot_service::delete($v1id);
            $this->fail('A superseded snapshot version must not be deleted.');
        } catch (validation_exception $exception) {
            $this->assertSame('snapshotdeletesuperseded', $exception->errorcode);
        }
        $this->assertTrue($DB->record_exists('local_outcomemap_snapshot', ['id' => $v1id]));

        snapshot_service::delete($v2id, 'Captured against the wrong reporting period.');
        $this->assertFalse($DB->record_exists('local_outcomemap_snapshot', ['id' => $v2id]));
        $this->assertSame(0, $DB->count_records('local_outcomemap_snapitem', ['snapshotid' => $v2id]));
        $audit = $DB->get_record('local_outcomemap_audit', [
            'action' => 'delete_snapshot',
            'objecttype' => 'snapshot',
            'objectid' => $v2id,
        ], '*', MUST_EXIST);
        $this->assertSame($v1uuid, $audit->objectuuid);
        $this->assertSame('Captured against the wrong reporting period.', $audit->reason);
        $this->assertNull($audit->afterjson);

        // With the correction gone the original is the newest version again, so
        // the version that could not be withdrawn a moment ago now can be.
        $this->assertTrue(
            (new snapshot_report($v1id))->export_for_template($PAGE->get_renderer('core'))['candelete']
        );
        snapshot_service::delete($v1id);
        $this->assertSame(0, $DB->count_records('local_outcomemap_snapshot'));
        $this->assertSame(0, $DB->count_records('local_outcomemap_snapitem'));
    }

    /**
     * * Test independent freeze, immutable corrections, redaction, hashes, and export reconstruction.
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
        foreach (
            ['snapshotuuid', 'version', 'policyid', 'pluginversion', 'algoversion',
            'payloadhash', 'manifesthash', 'itemcount'] as $manifestfield
        ) {
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
        foreach (
            [
            snapshot_service::ITEM_OUTCOME_VERSION,
            snapshot_service::ITEM_POLICY_VERSION,
            snapshot_service::ITEM_MAPPING_VERSION,
            snapshot_service::ITEM_RESULT,
            snapshot_service::ITEM_COURSE_AGGREGATE,
            snapshot_service::ITEM_PROGRAM_AGGREGATE,
            ] as $requiredtype
        ) {
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
        $this->assertSame(
            $jsonv1,
            accreditation_export_service::json($snapshotid),
            'A frozen export must not change when live results change.'
        );

        $this->insert_policy(
            policy_service::TYPE_ACCREDITATION,
            [
                'mincohortsize' => 3,
                'populationsource' => suppression_service::POPULATION_MOODLE_COHORT,
                'retentionbasis' => suppression_service::RETENTION_ANONYMISED,
                'achievementminpercent' => '70',
                'benchmarkpercent' => '70',
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

    /**
     * Freeze a snapshot over the standard fixture and export its report context.
     *
     * @param int $threshold Minimum cohort size for the accreditation policy.
     * @return array{0:array,1:int} Report context and snapshot ID.
     */
    private function frozen_report(int $threshold = 2): array {
        global $PAGE;

        set_config('requireapproval', 0, 'local_outcomemap');
        $this->setAdminUser();
        $this->reviewer = $this->create_reviewer();
        $fixture = $this->create_snapshot_fixture($threshold);
        $snapshotid = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => '2026-T1',
            'cohortid' => $fixture['cohortid'],
            'notes' => 'Report model fixture',
        ]);
        snapshot_service::freeze($snapshotid);
        $report = new snapshot_report($snapshotid);
        return [$report->export_for_template($PAGE->get_renderer('core')), $snapshotid];
    }

    /**
     * The report reads its figures out of the frozen rows, grouped by framework.
     *
     * The captured payload is an envelope of type, identity, indexed columns, and
     * the canonical payload, so a statement and a framework code appearing here is
     * also the assertion that the envelope was unwrapped rather than read flat.
     */
    public function test_snapshot_report_reads_figures_from_the_frozen_capture(): void {
        $this->resetAfterTest(true);
        [$context] = $this->frozen_report();

        $this->assertSame('M6-PROGRAM', $context['programcode']);
        $this->assertSame('M6 reporting program', $context['programname']);
        $this->assertTrue($context['isfrozen']);

        $this->assertTrue($context['hasoutcomes']);
        $this->assertCount(
            1,
            $context['outcomes'],
            'The fixture captures outcomes from exactly one framework.'
        );
        $group = $context['outcomes'][0];
        $this->assertSame('M6-PLO', $group['framework']);
        $this->assertCount(1, $group['rows']);
        $row = $group['rows'][0];
        $this->assertSame('PLO1', $row['code']);
        $this->assertSame('Demonstrate the accredited program outcome.', $row['statement']);
        $this->assertFalse($row['suppressed']);
        $this->assertSame('2', $row['learners']);
        $this->assertSame('2', $row['results']);
        $this->assertSame('85.0%', $row['percent']);
        $this->assertSame(
            ['M6-COURSE'],
            $row['evidence'],
            'The evidence chips name the catalog courses whose aggregates fed the outcome.'
        );

        // Learner counts cannot be summed across outcomes, so the aggregate line
        // reports the snapshot's own population and the weighted percentage.
        $this->assertSame('2', $context['totals']['learners']);
        $this->assertSame('2', $context['totals']['results']);
        $this->assertSame('85.0%', $context['totals']['percent']);

        $this->assertCount(1, $context['courses']);
        $this->assertSame('M6-COURSE', $context['courses'][0]['code']);
        $this->assertSame('M6 reporting course', $context['courses'][0]['name']);

        $types = array_column($context['rowtypes'], 'count', 'type');
        $this->assertSame('1', $types[snapshot_service::ITEM_PROGRAM_AGGREGATE]);
        $this->assertSame('2', $types[snapshot_service::ITEM_RESULT]);
    }

    /**
     * * The report judges each subject once per course against the frozen criterion.
     */
    public function test_snapshot_report_reports_course_progress(): void {
        $this->resetAfterTest(true);
        [$context] = $this->frozen_report();

        // Both fixture learners score 85% against a 70% criterion.
        $this->assertTrue($context['progress']['known']);
        $this->assertSame(
            ['passed' => 2, 'failed' => 0, 'unjudged' => 0],
            $context['progress']['counts']
        );
        $values = array_column($context['progress']['tiles'], 'value', 'label');
        $this->assertSame('2', $values[get_string('snapreport_progress_passedall', 'local_outcomemap')]);
        $this->assertSame('0', $values[get_string('snapreport_progress_failedany', 'local_outcomemap')]);
        $this->assertStringContainsString('70.0', $context['progress']['criterion']);

        $course = $context['courses'][0];
        $this->assertTrue($course['haspass']);
        $this->assertSame('100.0%', $course['passrate']);
        $this->assertSame('2', $course['passed']);
        $this->assertSame('0', $course['failed']);
    }

    /**
     * Filtering to the subjects who failed a course recomputes the table over
     * that set and refuses to restate the snapshot's benchmark verdict for it.
     */
    public function test_snapshot_report_filters_the_table_by_course_progress(): void {
        global $PAGE;

        $this->resetAfterTest(true);
        [, $snapshotid] = $this->frozen_report();

        $failing = (new snapshot_report(
            $snapshotid,
            snapshot_report::GROUP_FRAMEWORK,
            snapshot_report::SUBJECTS_FAILEDANY
        ))->export_for_template($PAGE->get_renderer('core'));
        $this->assertTrue($failing['controls']['filtered']);
        $row = $failing['outcomes'][0]['rows'][0];
        $this->assertSame(
            '0',
            $row['learners'],
            'No fixture subject failed a course, so the filtered table reports none.'
        );
        $this->assertFalse($row['benchmarkmet']);
        $this->assertFalse(
            $row['benchmarkmissed'],
            'A recomputed rate was never judged against the benchmark, so no verdict is claimed.'
        );

        // The unfiltered view is untouched and still carries the governed figures.
        $all = (new snapshot_report(
            $snapshotid,
            snapshot_report::GROUP_FRAMEWORK,
            snapshot_report::SUBJECTS_PASSEDALL
        ))->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame('2', $all['outcomes'][0]['rows'][0]['learners']);
        $this->assertSame('85.0%', $all['outcomes'][0]['rows'][0]['percent']);
    }

    /**
     * The attainment table groups by the outcome each row rolls up into, using
     * the alignment edges the snapshot itself captured.
     */
    public function test_snapshot_report_groups_by_higher_level_outcome(): void {
        global $PAGE;

        $this->resetAfterTest(true);
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->setAdminUser();
        $this->reviewer = $this->create_reviewer();
        $fixture = $this->create_snapshot_fixture();
        $this->align_fixture_outcome($fixture['outcomeversionid'], $fixture['courseinstanceid']);
        $snapshotid = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => '2026-T1',
            'cohortid' => $fixture['cohortid'],
        ]);
        snapshot_service::freeze($snapshotid);

        $render = fn(string $group): array => (new snapshot_report($snapshotid, $group))
            ->export_for_template($PAGE->get_renderer('core'));

        $framework = $render(snapshot_report::GROUP_FRAMEWORK);
        $this->assertSame(['M6-PLO'], array_column($framework['outcomes'], 'framework'));

        foreach ([snapshot_report::GROUP_COURSE, snapshot_report::GROUP_PROGRAM] as $group) {
            $context = $render($group);
            $this->assertSame(
                ['M6-TOP.TOP1'],
                array_column($context['outcomes'], 'framework'),
                'The row must be reported under the outcome it is approved to support.'
            );
            $this->assertSame('PLO1', $context['outcomes'][0]['rows'][0]['code']);
            // Regrouping only moves rows, so the aggregate line must not change.
            $this->assertSame($framework['totals']['percent'], $context['totals']['percent']);
        }
    }

    /**
     * A capture holding no alignment groups from the live curriculum instead, and
     * tells the reader the grouping did not come out of the frozen rows.
     */
    public function test_snapshot_report_falls_back_to_live_alignment(): void {
        global $PAGE;

        $this->resetAfterTest(true);
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->setAdminUser();
        $this->reviewer = $this->create_reviewer();
        $fixture = $this->create_snapshot_fixture();
        $snapshotid = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => '2026-T1',
            'cohortid' => $fixture['cohortid'],
        ]);
        snapshot_service::freeze($snapshotid);

        // Nothing is aligned yet, so the rollup views have nowhere to put the row.
        $before = (new snapshot_report($snapshotid, snapshot_report::GROUP_PROGRAM))
            ->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame(
            [get_string('snapreport_groupunaligned', 'local_outcomemap')],
            array_column($before['outcomes'], 'framework')
        );
        $this->assertFalse($before['controls']['liverollup']);

        // The alignment is authored after the freeze, so it is not in the capture.
        $this->align_fixture_outcome($fixture['outcomeversionid'], $fixture['courseinstanceid']);
        $after = (new snapshot_report($snapshotid, snapshot_report::GROUP_PROGRAM))
            ->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame(['M6-TOP.TOP1'], array_column($after['outcomes'], 'framework'));
        $this->assertTrue(
            $after['controls']['liverollup'],
            'A grouping read from outside the capture must be declared as such.'
        );
        $this->assertSame(
            $before['totals']['percent'],
            $after['totals']['percent'],
            'Only the grouping comes from outside the frozen rows; the figures do not.'
        );

        // The framework view never leaves the capture, so it makes no such claim.
        $framework = (new snapshot_report($snapshotid, snapshot_report::GROUP_FRAMEWORK))
            ->export_for_template($PAGE->get_renderer('core'));
        $this->assertFalse($framework['controls']['liverollup']);
        $this->assertSame(['M6-PLO'], array_column($framework['outcomes'], 'framework'));
    }

    /**
     * Align the fixture outcome to a higher-level outcome and record the edge on
     * the evidence, which is what makes the capture include the relation.
     *
     * @param int $outcomeversionid Fixture outcome-version ID.
     * @param int $courseinstanceid Fixture course-instance ID.
     * @return void
     */
    private function align_fixture_outcome(int $outcomeversionid, int $courseinstanceid): void {
        global $DB;
        $now = time();
        $sourceitemid = (int) $DB->get_field(
            'local_outcomemap_itemver',
            'itemid',
            ['id' => $outcomeversionid],
            MUST_EXIST
        );
        $frameworkid = (int) $DB->insert_record('local_outcomemap_fw', (object) [
            'uuid' => uuid::generate(), 'code' => 'M6-TOP', 'name' => 'M6 top level',
            'description' => null, 'ownertype' => framework_service::OWNER_INSTITUTION,
            'ownerid' => null, 'status' => workflow::APPROVED, 'createdby' => null,
            'modifiedby' => null, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $targetitemid = (int) $DB->insert_record('local_outcomemap_item', (object) [
            'uuid' => uuid::generate(), 'frameworkid' => $frameworkid, 'code' => 'TOP1',
            'status' => workflow::APPROVED, 'createdby' => null, 'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $relationid = (int) $DB->insert_record('local_outcomemap_rel', (object) [
            'relationuuid' => uuid::generate(), 'version' => 1,
            'sourceitemid' => $sourceitemid, 'targetitemid' => $targetitemid,
            'type' => relation_service::CONTRIBUTES_TO, 'weight' => null,
            'status' => workflow::APPROVED, 'effectivefrom' => $now - DAYSECS,
            'effectiveto' => null, 'notes' => null, 'createdby' => null,
            'approvedby' => $this->reviewer->id, 'timecreated' => $now,
            'timemodified' => $now, 'approvedat' => $now,
        ]);
        $DB->set_field(
            'local_outcomemap_evidence',
            'relationpathjson',
            canonical_json::encode([$relationid]),
            ['cinstid' => $courseinstanceid]
        );
    }

    /**
     * * A suppressed aggregate withholds its figures rather than printing them.
     */
    public function test_snapshot_report_withholds_suppressed_figures(): void {
        $this->resetAfterTest(true);
        // Three is above the two learners the fixture enrols, so every aggregate
        // derived from that population must be suppressed.
        [$context] = $this->frozen_report(3);

        $row = $context['outcomes'][0]['rows'][0];
        $this->assertTrue($row['suppressed']);
        $this->assertFalse(
            $row['hasbar'],
            'A suppressed row must not draw an attainment bar.'
        );
        $suppressionline = implode(' ', array_column($context['methods'], 'value'));
        $this->assertStringContainsString('suppressed', $suppressionline);
    }

    /**
     * * Exports stay closed until the snapshot is frozen, and freezing is offered.
     */
    public function test_snapshot_report_gates_exports_until_frozen(): void {
        global $PAGE;

        $this->resetAfterTest(true);
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->setAdminUser();
        $this->reviewer = $this->create_reviewer();
        $fixture = $this->create_snapshot_fixture();
        $snapshotid = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => '2026-T1',
            'cohortid' => $fixture['cohortid'],
        ]);

        $report = new snapshot_report($snapshotid);
        $context = $report->export_for_template($PAGE->get_renderer('core'));

        $this->assertFalse($context['isfrozen']);
        $this->assertFalse(
            $context['exports']['canexport'],
            'An unfrozen capture must not offer an accreditation export.'
        );
        $this->assertTrue($context['exports']['notfrozen']);
        $this->assertTrue($context['canfreeze']);
        $this->assertFalse($context['cancorrect']);

        snapshot_service::freeze($snapshotid);
        $frozen = (new snapshot_report($snapshotid))->export_for_template($PAGE->get_renderer('core'));
        $this->assertTrue($frozen['exports']['canexport']);
        $this->assertFalse($frozen['canfreeze']);
        $this->assertTrue($frozen['cancorrect']);
    }
}
