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

use core\context_helper;
use local_outcomemap\local\canonical_json;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\example_seed_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\service\snapshot_service;
use local_outcomemap\local\service\suppression_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\workflow;
use local_outcomemap\reportbuilder\local\access;
use local_outcomemap\reportbuilder\local\sources;

/**
 * Tests seeding of the plugin's example reports and example snapshot.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_outcomemap\local\service\example_seed_service
 */
final class example_seed_service_test extends \advanced_testcase {
    /**
     * Build a program with one period of authoritative course-scope results.
     *
     * The learners are enrolled rather than pooled into a cohort because the
     * seeded accreditation policy captures active enrolments at freeze.
     *
     * @return array programid, periodcode, courseid, and outcomeversionid.
     */
    private function create_attainment_fixture(): array {
        global $CFG, $DB;

        if (empty($CFG->passwordsaltmain)) {
            $CFG->passwordsaltmain = 'local_outcomemap_phpunit_example_secret';
        }
        $now = time();
        $effectivefrom = $now - DAYSECS;
        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'SEED-COURSE',
            'fullname' => 'Seeded example course',
        ]);

        $programid = (int) $DB->insert_record('local_outcomemap_program', (object) [
            'uuid' => uuid::generate(),
            'code' => 'SEED-PROGRAM',
            'name' => 'Seeded example program',
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
            'code' => 'SEED-CATALOG',
            'name' => 'Seeded catalog course',
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
            'periodcode' => 'SEED-T1',
            'externalid' => null,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
            'confirmedby' => null,
            'confirmedat' => $now,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_outcomemap_progcourse', (object) [
            'uuid' => uuid::generate(),
            'programid' => $programid,
            'courseid' => $catalogcourseid,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
        $frameworkid = (int) $DB->insert_record('local_outcomemap_fw', (object) [
            'uuid' => uuid::generate(),
            'code' => 'SEED-PLO',
            'name' => 'Seeded program outcomes',
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
            'statement' => 'Demonstrate the seeded program outcome.',
            'shortstatement' => 'Demonstrate the seeded outcome.',
            'bloomlevel' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'changereason' => null,
            'createdby' => null,
            'approvedby' => null,
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
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        foreach ([0, 1] as $index) {
            $learner = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($learner->id, $course->id);
            $evidenceuuid = uuid::generate();
            $DB->insert_record('local_outcomemap_evidence', (object) [
                'uuid' => $evidenceuuid,
                'lineageuuid' => uuid::generate(),
                'dedupekey' => hash('sha256', 'seed-evidence-' . $index),
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
            $DB->insert_record('local_outcomemap_result', (object) [
                'uuid' => uuid::generate(),
                'resultkey' => hash('sha256', 'seed-result-' . $index),
                'version' => 1,
                'cinstid' => $courseinstanceid,
                'userid' => $learner->id,
                'scopetype' => calculation_service::SCOPE_COURSE,
                'scopeid' => $courseinstanceid,
                'periodcode' => 'SEED-T1',
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
                'inputhash' => hash('sha256', 'seed-input-' . $index),
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
            'periodcode' => 'SEED-T1',
            'courseid' => (int) $course->id,
            'outcomeversionid' => $outcomeversionid,
        ];
    }

    /**
     * Insert an approved institution-scope policy version.
     *
     * @param string $policytype Policy type.
     * @param array $config Typed policy configuration.
     * @param int $effectivefrom Effective start.
     * @return int Policy version ID.
     */
    private function insert_policy(string $policytype, array $config, int $effectivefrom): int {
        global $DB;

        $configjson = canonical_json::encode($config);
        $now = time();
        return (int) $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => uuid::generate(),
            'version' => 1,
            'policytype' => $policytype,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'scopeid' => null,
            'name' => 'Seed ' . $policytype,
            'configjson' => $configjson,
            'confighash' => hash('sha256', $configjson),
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
    }

    /**
     * * Test one example report per source, populated from the source defaults.
     */
    public function test_seed_reports_covers_every_source_and_repeats_safely(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $expected = sources::all();
        $seeded = example_seed_service::seed_reports();
        $this->assertCount(count($expected), $seeded);

        foreach ($seeded as $index => $report) {
            $key = array_keys($expected)[$index];
            $this->assertSame($key, $report['key']);
            $this->assertTrue($report['created']);
            $record = $DB->get_record('reportbuilder_report', ['id' => $report['reportid']], '*', MUST_EXIST);
            $this->assertSame($expected[$key], $record->source);
            $this->assertSame(example_seed_service::report_name($key), $record->name);
            // The example must carry the source's own default layout, not an
            // empty report a reviewer would still have to configure.
            $this->assertGreaterThan(0, $DB->count_records('reportbuilder_column', [
                'reportid' => $report['reportid'],
            ]));
            $this->assertGreaterThan(0, $DB->count_records('reportbuilder_filter', [
                'reportid' => $report['reportid'],
            ]));
            $this->assertTrue($DB->record_exists('reportbuilder_audience', [
                'reportid' => $report['reportid'],
            ]));
        }

        $repeated = example_seed_service::seed_reports();
        $this->assertSame(
            array_column($seeded, 'reportid'),
            array_column($repeated, 'reportid')
        );
        foreach ($repeated as $report) {
            $this->assertFalse($report['created']);
        }
        $this->assertCount(count($expected), $DB->get_records('reportbuilder_report', [
            'type' => \core_reportbuilder\datasource::TYPE_CUSTOM_REPORT,
        ]));
    }

    /**
     * * Test the example snapshot is captured, frozen, and never duplicated.
     */
    public function test_seed_snapshot_freezes_one_verifiable_capture(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $fixture = $this->create_attainment_fixture();

        $this->assertNull(suppression_service::resolve($fixture['programid']));
        $result = example_seed_service::seed_snapshot();

        $this->assertSame($fixture['programid'], $result['programid']);
        $this->assertSame($fixture['periodcode'], $result['periodcode']);
        $this->assertTrue($result['policycreated']);
        $this->assertTrue($result['created']);
        $this->assertTrue($result['frozen']);

        $policy = $DB->get_record('local_outcomemap_policy', ['id' => $result['policyid']], '*', MUST_EXIST);
        $this->assertSame(policy_service::TYPE_ACCREDITATION, $policy->policytype);
        $this->assertSame(workflow::APPROVED, $policy->status);
        $this->assertSame(
            example_seed_service::EXAMPLE_MIN_COHORT_SIZE,
            (int) json_decode($policy->configjson, true)['mincohortsize']
        );

        $snapshot = $DB->get_record('local_outcomemap_snapshot', ['id' => $result['snapshotid']], '*', MUST_EXIST);
        $this->assertSame(snapshot_service::STATUS_FROZEN, $snapshot->status);
        $this->assertSame(2, (int) $snapshot->populationcount);
        $items = snapshot_service::items((int) $snapshot->id);
        // A frozen snapshot must reverify from its own stored rows.
        snapshot_service::verify($snapshot, $items);
        $types = array_unique(array_map(static fn($item): string => $item->itemtype, $items));
        $this->assertContains(snapshot_service::ITEM_PROGRAM_AGGREGATE, $types);
        $this->assertContains(snapshot_service::ITEM_COURSE_AGGREGATE, $types);
        $this->assertContains(snapshot_service::ITEM_RESULT, $types);

        $repeated = example_seed_service::seed_snapshot();
        $this->assertSame($result['snapshotid'], $repeated['snapshotid']);
        $this->assertFalse($repeated['created']);
        $this->assertFalse($repeated['policycreated']);
        $this->assertSame(0, $repeated['replaced']);
        $this->assertSame(1, $DB->count_records('local_outcomemap_snapshot'));
    }

    /**
     * * Test reseeding withdraws the previous example and recaptures current data.
     */
    public function test_seed_snapshot_replaces_an_existing_example_capture(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $fixture = $this->create_attainment_fixture();

        $first = example_seed_service::seed_snapshot();
        $correction = snapshot_service::create_draft([
            'programid' => $fixture['programid'],
            'periodcode' => $fixture['periodcode'],
            'previousid' => $first['snapshotid'],
            'correctionreason' => 'A correction the reseed must also withdraw.',
        ]);
        snapshot_service::freeze($correction);
        $this->assertSame(2, $DB->count_records('local_outcomemap_snapshot'));

        $replacement = example_seed_service::seed_snapshot(['replace' => true]);

        $this->assertTrue($replacement['created']);
        $this->assertTrue($replacement['frozen']);
        $this->assertSame(2, $replacement['replaced']);
        $this->assertFalse($replacement['policycreated'], 'The seeded policy must be reused, not duplicated.');
        // A reseed ends at one capture of current data rather than a further
        // version of the lineage it withdrew.
        $this->assertSame(1, $DB->count_records('local_outcomemap_snapshot'));
        $snapshot = $DB->get_record(
            'local_outcomemap_snapshot',
            ['id' => $replacement['snapshotid']],
            '*',
            MUST_EXIST
        );
        $this->assertSame(1, (int) $snapshot->version);
        $this->assertNull($snapshot->previousid);
        snapshot_service::verify($snapshot, snapshot_service::items((int) $snapshot->id));
        $this->assertSame(
            $DB->count_records('local_outcomemap_snapitem'),
            $DB->count_records('local_outcomemap_snapitem', ['snapshotid' => (int) $snapshot->id]),
            'The withdrawn versions must leave no captured rows behind.'
        );
        $this->assertSame(2, $DB->count_records('local_outcomemap_audit', [
            'action' => 'delete_snapshot',
            'objecttype' => 'snapshot',
        ]));
    }

    /**
     * * Test seeding refuses to invent a snapshot with no captured attainment.
     */
    public function test_seed_snapshot_requires_existing_results(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->expectException(\local_outcomemap\local\validation_exception::class);
        example_seed_service::seed_snapshot();
    }

    /**
     * Test context preloading leaves the caller's scope columns readable.
     *
     * Priming the context cache consumes the ctx* columns of the record it is
     * handed, so a source that reads ctxinstance after the check would silently
     * resolve no scope at all.
     */
    public function test_allowed_context_ids_preserves_caller_records(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $preload = context_helper::get_preload_record_columns_sql('ctx');
        $records = $DB->get_records_sql(
            "SELECT {$preload} FROM {context} ctx WHERE ctx.contextlevel = :level AND ctx.instanceid = :courseid",
            ['level' => CONTEXT_COURSE, 'courseid' => $course->id]
        );
        $this->assertCount(1, $records);
        $contextid = (int) \context_course::instance($course->id)->id;

        $allowed = access::allowed_context_ids($records, ['moodle/course:view']);
        $this->assertSame([$contextid => $contextid], $allowed);
        foreach ($records as $record) {
            $this->assertSame($contextid, (int) $record->ctxid);
            $this->assertSame((int) $course->id, (int) $record->ctxinstance);
        }
    }
}
