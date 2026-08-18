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

use local_outcomemap\api\question_mappings;
use local_outcomemap\local\decimal;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\question_mapping_service;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Tests for governed question-version mappings.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_outcomemap\local\service\question_mapping_service
 */
final class question_mapping_service_test extends \advanced_testcase {
    use \local_outcomemap\tests\moodle_compat_trait;

    /**
     * @var int Outcome effective start shared across fixtures.
     */
    private const EFFECTIVEFROM = 1704067200;

    /**
     * Creates a system manager who can independently approve question mappings.
     *
     * @return \stdClass Reviewer user record.
     */
    private function create_reviewer(): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $user->id, \context_system::instance()->id);
        return $user;
    }

    /**
     * Creates an approved framework with approved outcome versions.
     *
     * @param \stdClass $reviewer Reviewer user record.
     * @param string[] $codes Outcome codes.
     * @return int[] Approved outcome-version IDs keyed by outcome code.
     */
    private function create_outcomes(\stdClass $reviewer, array $codes): array {

        global $DB;
        $this->setAdminUser();
        $frameworkid = framework_service::create([
            'code' => 'QFW' . random_string(4),
            'name' => 'Question mapping outcomes',
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        if (workflow::requires_independent_approval()) {
            $this->setUser($reviewer);
            framework_service::approve($frameworkid);
        }
        $itemverids = [];
        foreach ($codes as $code) {
            $this->setAdminUser();
            $itemid = outcome_service::create([
                'frameworkid' => $frameworkid,
                'code' => $code,
                'statement' => 'Outcome ' . $code,
                'effectivefrom' => self::EFFECTIVEFROM,
            ]);
            $itemverid = (int) $DB->get_field('local_outcomemap_itemver', 'id', ['itemid' => $itemid], MUST_EXIST);
            outcome_service::submit_for_review($itemverid);
            if (workflow::requires_independent_approval()) {
                $this->setUser($reviewer);
                outcome_service::approve($itemverid);
            }
            $itemverids[$code] = $itemverid;
        }
        $this->setAdminUser();
        return $itemverids;
    }

    /**
     * Create an approved outcome owned by an unrelated catalog course.
     *
     * @param \stdClass $reviewer Reviewer.
     */
    private function create_unrelated_catalog_outcome(\stdClass $reviewer): \stdClass {

        global $DB;
        $this->setAdminUser();
        $catalogid = catalog_course_service::create([
            'code' => 'UNRELATED' . random_string(4), 'name' => 'Unrelated catalog course',
        ]);
        $frameworkid = framework_service::create([
            'code' => 'UNRELATEDFW' . random_string(4),
            'name' => 'Unrelated course outcomes',
            'ownertype' => framework_service::OWNER_COURSE,
            'ownerid' => $catalogid,
        ]);
        framework_service::submit_for_review($frameworkid);
        $this->setUser($reviewer);
        framework_service::approve($frameworkid);
        $this->setAdminUser();
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => 'PRIVATE',
            'statement' => 'Private catalog-course outcome.',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $itemverid = (int) $DB->get_field('local_outcomemap_itemver', 'id', ['itemid' => $itemid], MUST_EXIST);
        outcome_service::submit_for_review($itemverid);
        $this->setUser($reviewer);
        outcome_service::approve($itemverid);
        $this->setAdminUser();
        return $DB->get_record('local_outcomemap_itemver', ['id' => $itemverid], '*', MUST_EXIST);
    }
    /**
     * Creates a question in a course-context question bank category.
     *
     * @return \stdClass Question data including versionid.
     */
    private function create_question(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category([
            'contextid' => $this->question_bank_contextid($course),
        ]);
        return $generator->create_question('shortanswer', null, ['category' => $category->id]);
    }

    /**
     * * Tests draft rules: assessed weight required, no weight on other roles, question binding.
     */
    public function test_draft_weight_rules_and_provenance(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['CLO1']);
        $question = $this->create_question();

        try {
            question_mapping_service::create([
                'questionversionid' => $question->versionid,
                'itemverid' => $itemverids['CLO1'],
                'role' => 'assesses',
                'effectivefrom' => self::EFFECTIVEFROM,
            ]);
            $this->fail('An assesses mapping without a weight must be rejected.');
        } catch (validation_exception $e) {
            $this->assertSame('assessedweightrequired', $e->errorcode);
        }

        try {
            question_mapping_service::create([
                'questionversionid' => $question->versionid,
                'itemverid' => $itemverids['CLO1'],
                'role' => 'teaches',
                'weight' => '0.5',
                'effectivefrom' => self::EFFECTIVEFROM,
            ]);
            $this->fail('A non-assessment mapping with a weight must be rejected.');
        } catch (validation_exception $e) {
            $this->assertSame('weightnotallowedforrole', $e->errorcode);
        }

        $mappingid = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'assesses',
            'weight' => '1',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $record = $DB->get_record('local_outcomemap_qmap', ['id' => $mappingid], '*', MUST_EXIST);
        $this->assertSame(workflow::DRAFT, $record->status);
        $this->assertSame('1.0000000000', $record->weight);
        $this->assertSame((int) $question->id, (int) $record->questionid);
        $this->assertSame((int) $question->versionid, (int) $record->questionversionid);
        $this->assertTrue($DB->record_exists('local_outcomemap_audit', [
            'objecttype' => 'question_mapping',
            'objectid' => $mappingid,
            'action' => 'create',
        ]));

        question_mapping_service::update_draft($mappingid, ['weight' => '0.9999999999']);
        $this->assertSame('0.9999999999', $DB->get_field('local_outcomemap_qmap', 'weight', ['id' => $mappingid]));

        question_mapping_service::delete_draft($mappingid, 'test cleanup');
        $this->assertFalse($DB->record_exists('local_outcomemap_qmap', ['id' => $mappingid]));
        $this->assertTrue($DB->record_exists('local_outcomemap_audit', [
            'objecttype' => 'question_mapping',
            'objectid' => $mappingid,
            'action' => 'delete',
        ]));
    }

    /**
     * * Tests that assessed sets approve together and must total exactly one.
     */
    public function test_assessed_set_approval_requires_exact_total(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['CLO1', 'CLO2', 'CLO3']);
        $question = $this->create_question();

        $first = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'assesses',
            'weight' => '0.6',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $second = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO2'],
            'role' => 'assesses',
            'weight' => '0.3',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        question_mapping_service::submit_for_review($first);
        question_mapping_service::submit_for_review($second);

        $this->setUser($reviewer);
        try {
            question_mapping_service::approve($first);
            $this->fail('A 0.9 assessed total must not be approvable.');
        } catch (validation_exception $e) {
            $this->assertSame('assessedweighttotalinvalid', $e->errorcode);
        }
        $this->assertSame(workflow::NEEDS_REVIEW, $DB->get_field('local_outcomemap_qmap', 'status', ['id' => $first]));
        $this->assertSame(workflow::NEEDS_REVIEW, $DB->get_field('local_outcomemap_qmap', 'status', ['id' => $second]));

        $this->setAdminUser();
        $third = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO3'],
            'role' => 'assesses',
            'weight' => '0.1',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        question_mapping_service::submit_for_review($third);

        // The creator must not approve their own submissions.
        try {
            question_mapping_service::approve($first);
            $this->fail('Creators must not approve their own mappings.');
        } catch (validation_exception $e) {
            $this->assertSame('creatorcannotapprove', $e->errorcode);
        }

        $this->setUser($reviewer);
        question_mapping_service::approve($first, 'Blueprint approved');
        foreach ([$first, $second, $third] as $id) {
            $this->assertSame(
                workflow::APPROVED,
                $DB->get_field('local_outcomemap_qmap', 'status', ['id' => $id])
            );
        }
        $report = question_mapping_service::validate_assessed_weights(
            (int) $question->versionid,
            self::EFFECTIVEFROM
        );
        $this->assertSame('1.0000000000', $report->approvedtotal);
        $this->assertTrue($report->approvedvalid);
    }

    /**
     * * Tests alignment-only approval and duplicate-scope rejection.
     */
    public function test_alignment_only_approval_and_duplicate_scope(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['CLO1', 'ULO1']);
        $question = $this->create_question();

        $alignment = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['ULO1'],
            'role' => 'alignment_only',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        question_mapping_service::submit_for_review($alignment);
        $this->setUser($reviewer);
        question_mapping_service::approve($alignment);
        $this->assertSame(
            workflow::APPROVED,
            $DB->get_field('local_outcomemap_qmap', 'status', ['id' => $alignment])
        );

        // A second mapping of the same question version, outcome version, and
        // role under a different mapping UUID must be rejected at approval.
        $this->setAdminUser();
        $duplicate = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['ULO1'],
            'role' => 'alignment_only',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        question_mapping_service::submit_for_review($duplicate);
        $this->setUser($reviewer);
        try {
            question_mapping_service::approve($duplicate);
            $this->fail('Duplicate approved scope must be rejected.');
        } catch (validation_exception $e) {
            $this->assertSame('duplicatemapping', $e->errorcode);
        }
    }

    /**
     * * Tests explicit copy-to-version and the automatic observer copy.
     */
    public function test_copy_to_version_and_observer(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['CLO1', 'CLO2']);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');

        // Explicit copy with the automatic observer disabled.
        set_config('autocopyquestionmappings', 0, 'local_outcomemap');
        $question = $this->create_question();
        $assessed = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'assesses',
            'weight' => '1.0',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $teaches = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO2'],
            'role' => 'teaches',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        question_mapping_service::submit_for_review($assessed);
        question_mapping_service::submit_for_review($teaches);
        $this->setUser($reviewer);
        question_mapping_service::approve($assessed);
        question_mapping_service::approve($teaches);

        $this->setAdminUser();
        $newversion = $generator->update_question(clone $question, null, ['name' => 'Second version']);
        $this->assertSame(0, $DB->count_records('local_outcomemap_qmap', [
            'questionversionid' => $newversion->versionid,
        ]));

        $preview = question_mapping_service::preview_copy_to_version((int) $newversion->versionid);
        $this->assertSame((int) $question->versionid, $preview->sourcequestionversionid);
        $this->assertSame(1, $preview->sourceversion);
        $this->assertSame(2, $preview->eligiblecount);
        $this->assertCount(2, $preview->mappings);

        $newids = question_mapping_service::copy_to_version((int) $newversion->versionid);
        $this->assertCount(2, $newids);
        $copies = $DB->get_records('local_outcomemap_qmap', ['questionversionid' => $newversion->versionid]);
        foreach ($copies as $copy) {
            $this->assertSame(workflow::DRAFT, $copy->status);
            $this->assertSame(1, (int) $copy->version);
            $this->assertSame((int) $newversion->id, (int) $copy->questionid);
            $this->assertFalse($DB->record_exists_select(
                'local_outcomemap_qmap',
                'mappinguuid = :uuid AND id <> :id',
                ['uuid' => $copy->mappinguuid, 'id' => $copy->id]
            ));
        }
        $dtos = question_mappings::get_for_question_versions([(int) $newversion->versionid]);
        $copiedto = $dtos[(int) $newversion->versionid][0];
        $this->assertSame((int) $question->versionid, $copiedto->sourcequestionversionid);
        $this->assertSame(1, $copiedto->sourcequestionversion);
        $this->assertNotNull($copiedto->sourcemappinguuid);
        $this->assertSame(1, $copiedto->sourcemappingversion);

        $afterpreview = question_mappings::preview_copy_to_version((int) $newversion->versionid);
        $this->assertSame(0, $afterpreview->eligiblecount);
        $this->assertSame(2, $afterpreview->duplicatecount);

        // Source mappings stay approved on the old version for historical attempts.
        $this->assertSame(
            workflow::APPROVED,
            $DB->get_field('local_outcomemap_qmap', 'status', ['id' => $assessed])
        );

        // The copy is idempotent.
        $this->assertSame([], question_mapping_service::copy_to_version((int) $newversion->versionid));

        // An explicit source must still be the immediately preceding version.
        $thirdversion = $generator->update_question(clone $newversion, null, ['name' => 'Third version']);
        try {
            question_mapping_service::preview_copy_to_version(
                (int) $thirdversion->versionid,
                (int) $question->versionid
            );
            $this->fail('A non-immediate source version must be rejected.');
        } catch (validation_exception $e) {
            $this->assertSame('questionversionmismatch', $e->errorcode);
        }

        // Automatic copy through the question_created observer.
        set_config('autocopyquestionmappings', 1, 'local_outcomemap');
        $observed = $this->create_question();
        $observedmapping = question_mapping_service::create([
            'questionversionid' => $observed->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'assesses',
            'weight' => '1.0',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        question_mapping_service::submit_for_review($observedmapping);
        $this->setUser($reviewer);
        question_mapping_service::approve($observedmapping);

        $this->setAdminUser();
        $observednew = $generator->update_question(clone $observed, null, ['name' => 'Observed second version']);
        $autocopies = $DB->get_records('local_outcomemap_qmap', [
            'questionversionid' => $observednew->versionid,
        ]);
        $this->assertCount(1, $autocopies);
        $autocopy = reset($autocopies);
        $this->assertSame(workflow::DRAFT, $autocopy->status);
        $this->assertSame('1.0000000000', $autocopy->weight);
        $this->assertTrue($DB->record_exists('local_outcomemap_audit', [
            'objecttype' => 'question_mapping',
            'objectid' => $autocopy->id,
            'action' => 'copy_version',
        ]));
    }

    /**
     * * Tests bulk retrieval, DTO conversion, and capability filtering.
     */
    public function test_bulk_get_and_capability_filtering(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['CLO1', 'CLO2']);
        $question = $this->create_question();

        question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'assesses',
            'weight' => '0.7500000000',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        // The companion-facing facade addresses outcomes by stable UUID.
        $clo2uuid = $DB->get_field(
            'local_outcomemap_itemver',
            'uuid',
            ['id' => $itemverids['CLO2']],
            MUST_EXIST
        );
        question_mappings::create_draft(
            (int) $question->versionid,
            $clo2uuid,
            'alignment_only',
            null,
            null,
            self::EFFECTIVEFROM
        );

        $grouped = question_mappings::get_for_question_versions([
            (int) $question->versionid,
            (int) $question->versionid,
            999999999,
        ]);
        $this->assertCount(1, $grouped);
        $dtos = $grouped[(int) $question->versionid];
        $this->assertCount(2, $dtos);
        $byoutcome = [];
        foreach ($dtos as $dto) {
            $this->assertInstanceOf(local\dto\question_mapping::class, $dto);
            $this->assertSame((int) $question->versionid, $dto->questionversionid);
            $this->assertSame(workflow::DRAFT, $dto->status);
            $byoutcome[$dto->outcomecode] = $dto;
        }
        $this->assertSame('0.7500000000', $byoutcome['CLO1']->weight);
        $this->assertNull($byoutcome['CLO2']->weight);
        $encoded = json_decode(json_encode($byoutcome['CLO1']), true);
        $this->assertArrayNotHasKey('itemverid', $encoded);

        // A user without definitions/question capabilities receives nothing.
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $this->assertSame([], question_mappings::get_for_question_versions([(int) $question->versionid]));
    }

    /**
     * * Tests the assessed-weight validation report used by the qbank filter.
     */
    public function test_validate_assessed_weights_report(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['CLO1', 'CLO2']);
        $question = $this->create_question();

        $report = question_mapping_service::validate_assessed_weights((int) $question->versionid);
        $this->assertSame(decimal::ZERO, $report->approvedtotal);
        $this->assertTrue($report->approvedvalid);
        $this->assertFalse($report->combinedvalid);

        $first = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'assesses',
            'weight' => '0.6',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO2'],
            'role' => 'assesses',
            'weight' => '0.4',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $report = question_mapping_service::validate_assessed_weights((int) $question->versionid);
        $this->assertSame(decimal::ZERO, $report->approvedtotal);
        $this->assertSame('1.0000000000', $report->combinedtotal);
        $this->assertTrue($report->combinedvalid);

        question_mapping_service::submit_for_review($first);
        $report = question_mapping_service::validate_assessed_weights((int) $question->versionid);
        $this->assertSame('1.0000000000', $report->combinedtotal);
    }

    /**
     * * Tests bulk preview, explicit weights, stale protection, and every mutation.
     */
    public function test_bulk_preview_and_atomic_operations(): void {
        global $DB;
        $this->resetAfterTest(true);
        set_config('requireapproval', 1, 'local_outcomemap');
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['BULK1']);
        $outcomeuuid = (string) $DB->get_field('local_outcomemap_itemver', 'uuid', [
            'id' => $itemverids['BULK1'],
        ], MUST_EXIST);
        $firstquestion = $this->create_question();
        $secondquestion = $this->create_question();
        $questionids = [(int) $firstquestion->id, (int) $secondquestion->id];

        $invalid = question_mappings::preview_bulk($questionids, [
            'action' => question_mappings::BULK_ADD,
            'outcomeversionuuid' => $outcomeuuid,
            'role' => 'assesses',
            'weights' => [(int) $firstquestion->id => '1.0'],
        ]);
        $this->assertFalse($invalid->valid);
        $this->assertNotEmpty($invalid->questions[1]->errors);
        $this->assertSame(0, $DB->count_records('local_outcomemap_qmap'));

        $addoperation = [
            'action' => question_mappings::BULK_ADD,
            'outcomeversionuuid' => $outcomeuuid,
            'role' => 'assesses',
            'weights' => [
                (int) $firstquestion->id => '1.0000000000',
                (int) $secondquestion->id => '1',
            ],
        ];
        $addpreview = question_mappings::preview_bulk($questionids, $addoperation);
        $this->assertTrue($addpreview->valid);
        $addresult = question_mappings::commit_bulk(
            $questionids,
            $addpreview->operation,
            $addpreview->previewtoken
        );
        $this->assertSame(2, $addresult->affected);
        $mappings = array_values($DB->get_records('local_outcomemap_qmap', [], 'questionid ASC'));
        $this->assertCount(2, $mappings);
        $this->assertSame('1.0000000000', $mappings[0]->weight);
        $this->assertSame('1.0000000000', $mappings[1]->weight);

        $mappingids = array_map(static fn(\stdClass $mapping): int => (int) $mapping->id, $mappings);
        $roleoperation = [
            'action' => question_mappings::BULK_CHANGE_ROLE,
            'mappingids' => $mappingids,
            'role' => 'teaches',
        ];
        $stalepreview = question_mappings::preview_bulk($questionids, $roleoperation);
        $this->assertTrue($stalepreview->valid);
        question_mappings::update_draft($mappingids[0], ['notes' => 'Changed after preview']);
        try {
            question_mappings::commit_bulk(
                $questionids,
                $stalepreview->operation,
                $stalepreview->previewtoken
            );
            $this->fail('A stale bulk preview must not be committed.');
        } catch (validation_exception $e) {
            $this->assertSame('bulkpreviewstale', $e->errorcode);
        }
        $this->assertSame('assesses', $DB->get_field('local_outcomemap_qmap', 'role', [
            'id' => $mappingids[1],
        ]));

        $rolepreview = question_mappings::preview_bulk($questionids, $roleoperation);
        question_mappings::commit_bulk($questionids, $rolepreview->operation, $rolepreview->previewtoken);
        foreach ($mappingids as $mappingid) {
            $this->assertSame('teaches', $DB->get_field('local_outcomemap_qmap', 'role', ['id' => $mappingid]));
            $this->assertNull($DB->get_field('local_outcomemap_qmap', 'weight', ['id' => $mappingid]));
        }

        $deletepreview = question_mappings::preview_bulk($questionids, [
            'action' => question_mappings::BULK_DELETE_DRAFTS,
            'mappingids' => [$mappingids[0]],
        ]);
        $this->assertTrue($deletepreview->valid);
        question_mappings::commit_bulk($questionids, $deletepreview->operation, $deletepreview->previewtoken);
        $this->assertFalse($DB->record_exists('local_outcomemap_qmap', ['id' => $mappingids[0]]));

        $submitpreview = question_mappings::preview_bulk($questionids, [
            'action' => question_mappings::BULK_SUBMIT_DRAFTS,
            'mappingids' => [$mappingids[1]],
            'reason' => 'Bulk review submission.',
        ]);
        $this->assertTrue($submitpreview->valid);
        question_mappings::commit_bulk($questionids, $submitpreview->operation, $submitpreview->previewtoken);
        $this->assertSame(workflow::NEEDS_REVIEW, $DB->get_field('local_outcomemap_qmap', 'status', [
            'id' => $mappingids[1],
        ]));
    }

    /**
     * * Tests approval-disabled bulk finalization across future effective segments.
     */
    public function test_bulk_finalization_validates_future_effective_segment(): void {
        global $DB;
        $this->resetAfterTest(true);
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['BULKFUTURE']);
        $outcomeuuid = (string) $DB->get_field('local_outcomemap_itemver', 'uuid', [
            'id' => $itemverids['BULKFUTURE'],
        ], MUST_EXIST);
        $question = $this->create_question();
        $effectivefrom = time() + DAYSECS;

        $addpreview = question_mappings::preview_bulk([(int) $question->id], [
            'action' => question_mappings::BULK_ADD,
            'outcomeversionuuid' => $outcomeuuid,
            'role' => 'assesses',
            'weights' => [(int) $question->id => '1'],
            'effectivefrom' => $effectivefrom,
        ]);
        $this->assertTrue($addpreview->valid);
        question_mappings::commit_bulk(
            [(int) $question->id],
            $addpreview->operation,
            $addpreview->previewtoken
        );
        $mappingid = (int) $DB->get_field('local_outcomemap_qmap', 'id', [
            'questionversionid' => $question->versionid,
        ], MUST_EXIST);

        $submitpreview = question_mappings::preview_bulk([(int) $question->id], [
            'action' => question_mappings::BULK_SUBMIT_DRAFTS,
            'mappingids' => [$mappingid],
        ]);
        $this->assertTrue($submitpreview->valid);
        question_mappings::commit_bulk(
            [(int) $question->id],
            $submitpreview->operation,
            $submitpreview->previewtoken
        );
        $this->assertSame(workflow::APPROVED, $DB->get_field('local_outcomemap_qmap', 'status', [
            'id' => $mappingid,
        ]));
    }

    /**
     * * Tests mutation APIs repeat local and Moodle question capability checks.
     */
    public function test_public_mutations_denied_without_mapping_capabilities(): void {

        global $DB;
        $this->resetAfterTest(true);
        set_config('autocopyquestionmappings', 0, 'local_outcomemap');
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['DENIED']);
        $outcomeuuid = (string) $DB->get_field('local_outcomemap_itemver', 'uuid', [
            'id' => $itemverids['DENIED'],
        ], MUST_EXIST);
        $question = $this->create_question();
        $updatedraft = question_mappings::create_draft(
            (int) $question->versionid,
            $outcomeuuid,
            'alignment_only'
        );
        $deletedraft = question_mappings::create_draft(
            (int) $question->versionid,
            $outcomeuuid,
            'teaches'
        );
        $submitdraft = question_mappings::create_draft(
            (int) $question->versionid,
            $outcomeuuid,
            'practices'
        );
        $bulkoperation = [
            'action' => question_mappings::BULK_ADD,
            'outcomeversionuuid' => $outcomeuuid,
            'role' => 'assesses',
            'weights' => [(int) $question->id => '1.0000000000'],
        ];
        $bulkpreview = question_mappings::preview_bulk([(int) $question->id], $bulkoperation);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $target = $questiongenerator->update_question(clone $question, null, ['name' => 'Denied copy target']);
        $context = \local_outcomemap\api\context_resolver::for_question_version((int) $question->versionid);

        $coreonly = $this->getDataGenerator()->create_user();
        $coreonlyrole = create_role('Core question editor only', 'corequestiononly', '');
        assign_capability('moodle/question:editall', CAP_ALLOW, $coreonlyrole, $context->id, true);
        assign_capability('moodle/question:viewall', CAP_ALLOW, $coreonlyrole, $context->id, true);
        role_assign($coreonlyrole, $coreonly->id, $context->id);

        $localonly = $this->getDataGenerator()->create_user();
        $localonlyrole = create_role('Outcome mapper only', 'outcomemaponly', '');
        assign_capability('local/outcomemap:mapquestions', CAP_ALLOW, $localonlyrole, $context->id, true);
        assign_capability('local/outcomemap:viewdefinitions', CAP_ALLOW, $localonlyrole, $context->id, true);
        role_assign($localonlyrole, $localonly->id, $context->id);

        $this->setUser($coreonly);
        $this->assertTrue(has_capability('moodle/question:editall', $context));
        $this->assertFalse(has_capability('local/outcomemap:mapquestions', $context));
        $this->setUser($localonly);
        $this->assertTrue(has_capability('local/outcomemap:mapquestions', $context));
        $this->assertFalse(question_has_capability_on((int) $question->id, 'edit'));

        $operations = [
            'create' => static function () use ($question, $outcomeuuid): void {
                question_mappings::create_draft(
                    (int) $question->versionid,
                    $outcomeuuid,
                    'assesses',
                    '1.0000000000'
                );
            },
            'update' => static function () use ($updatedraft): void {
                question_mappings::update_draft($updatedraft, ['notes' => 'Unauthorized update']);
            },
            'delete' => static function () use ($deletedraft): void {
                question_mappings::delete_draft($deletedraft);
            },
            'submit' => static function () use ($submitdraft): void {
                question_mappings::submit_for_review($submitdraft);
            },
            'bulk preview' => static function () use ($question, $bulkoperation): void {
                question_mappings::preview_bulk([(int) $question->id], $bulkoperation);
            },
            'bulk commit' => static function () use ($question, $bulkpreview): void {
                question_mappings::commit_bulk(
                    [(int) $question->id],
                    $bulkpreview->operation,
                    $bulkpreview->previewtoken
                );
            },
            'copy preview' => static function () use ($target): void {
                question_mappings::preview_copy_to_version((int) $target->versionid);
            },
            'copy commit' => static function () use ($target): void {
                question_mappings::copy_to_version((int) $target->versionid);
            },
        ];
        $assertdenied = function (\stdClass $user, string $boundary) use ($operations): void {
            $this->setUser($user);
            foreach ($operations as $operationname => $operation) {
                try {
                    $operation();
                    $this->fail($boundary . ' did not deny operation: ' . $operationname);
                } catch (\required_capability_exception $e) {
                    $this->assertNotEmpty($e->getMessage());
                }
            }
        };

        $assertdenied($coreonly, 'Missing local/outcomemap:mapquestions');
        $assertdenied($localonly, 'Missing Moodle question edit capability');
        $this->assertSame(3, $DB->count_records('local_outcomemap_qmap'));
        $this->assertSame(0, $DB->count_records('local_outcomemap_qmap', [
            'questionversionid' => $target->versionid,
        ]));
    }

    /**
     * Stable UUID mutation APIs cannot bypass the question context's outcome scope.
     */
    public function test_public_create_rejects_outcome_outside_question_context(): void {

        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $outcomeversion = $this->create_unrelated_catalog_outcome($reviewer);
        $question = $this->create_question();
        try {
            question_mappings::create_draft(
                (int) $question->versionid,
                $outcomeversion->uuid,
                content_mapping_service::ROLE_ALIGNMENT_ONLY,
                null,
                null,
                self::EFFECTIVEFROM
            );
            $this->fail('A question was mapped to an outcome outside its context.');
        } catch (validation_exception $e) {
            $this->assertSame('recordnotfound', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('local_outcomemap_qmap', [
            'questionversionid' => $question->versionid,
        ]));
    }
    /**
     * * Tests exact decimal addition used for weight totals.
     */
    public function test_decimal_addition_is_exact(): void {
        $this->assertSame('1.0000000000', decimal::add('0.9999999999', '0.0000000001'));
        $this->assertSame('1.0000000000', decimal::add(
            decimal::add('0.3333333333', '0.3333333333'),
            '0.3333333334'
        ));
        $this->assertSame('0.9999999999', decimal::add('0.9999999998', '0.0000000001'));
        $this->assertSame('2.5000000000', decimal::add('1.2500000000', '1.2500000000'));
    }

    /**
     * * Autosubmit finalizes a sole assessed mapping without a manual submit step.
     */
    public function test_autosubmit_finalizes_a_single_assessed_mapping(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        set_config('autosubmitquestionmappings', 1, 'local_outcomemap');
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['CLO1']);
        $question = $this->create_question();

        $id = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'assesses',
            'weight' => '1',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $this->assertSame(workflow::APPROVED, question_mapping_service::get($id)->status);
    }

    /**
     * An approved mapping's effective start can be corrected backwards, which no
     * new version could express, and the correction is audited and reasoned.
     */
    public function test_correct_effectivefrom_moves_an_approved_mapping_backwards(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        set_config('autosubmitquestionmappings', 1, 'local_outcomemap');
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['CLO1']);
        $question = $this->create_question();

        $id = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'assesses',
            'weight' => '1',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $before = question_mapping_service::get($id);
        $this->assertSame(workflow::APPROVED, $before->status);

        $backdated = self::EFFECTIVEFROM - (86400 * 365);
        $this->assertSame(1, question_mapping_service::correct_effectivefrom(
            [$id],
            $backdated,
            'Authored for an existing course; the mapping described the exam all along.'
        ));

        $after = question_mapping_service::get($id);
        $this->assertSame(
            $backdated,
            (int) $after->effectivefrom,
            'The corrected start must be stored.'
        );
        $this->assertSame(
            (int) $before->version,
            (int) $after->version,
            'A correction is not a new decision, so the version does not move.'
        );
        $this->assertSame($before->mappinguuid, $after->mappinguuid);
        $this->assertSame(workflow::APPROVED, $after->status);

        $audit = $DB->get_records(
            'local_outcomemap_audit',
            ['objecttype' => 'question_mapping', 'action' => 'correct_effectivefrom']
        );
        $this->assertCount(1, $audit, 'The correction must be audited.');
        $event = reset($audit);
        $this->assertStringContainsString('existing course', (string) $event->reason);
        $this->assertSame(
            self::EFFECTIVEFROM,
            (int) json_decode($event->beforejson, true)['effectivefrom'],
            'The audit trail must retain the start that was replaced.'
        );

        // A reason is mandatory, and a draft cannot be corrected this way.
        try {
            question_mapping_service::correct_effectivefrom([$id], $backdated, '   ');
            $this->fail('A correction without a reason must be rejected.');
        } catch (validation_exception $e) {
            $this->assertSame('requiredfield', $e->errorcode);
        }
        $draftid = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'alignment_only',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $DB->set_field('local_outcomemap_qmap', 'status', workflow::DRAFT, ['id' => $draftid]);
        try {
            question_mapping_service::correct_effectivefrom([$draftid], $backdated, 'Not approved yet.');
            $this->fail('Only an approved mapping may be corrected.');
        } catch (validation_exception $e) {
            $this->assertSame('invalidtransition', $e->errorcode);
        }
    }

    /**
     * A four-way assessed set moves as one unit; correcting it row by row would
     * be rejected because each intermediate state totals less than 1.0.
     */
    public function test_correct_effectivefrom_moves_a_split_set_atomically(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        set_config('autosubmitquestionmappings', 1, 'local_outcomemap');
        $reviewer = $this->create_reviewer();
        $codes = ['CLO1', 'CLO2', 'CLO3', 'CLO4'];
        $itemverids = $this->create_outcomes($reviewer, $codes);
        $question = $this->create_question();

        $ids = [];
        foreach ($codes as $code) {
            $ids[] = question_mapping_service::create([
                'questionversionid' => $question->versionid,
                'itemverid' => $itemverids[$code],
                'role' => 'assesses',
                'weight' => '0.25',
                'effectivefrom' => self::EFFECTIVEFROM,
            ]);
        }
        foreach ($ids as $id) {
            $this->assertSame(workflow::APPROVED, question_mapping_service::get($id)->status);
        }

        $backdated = self::EFFECTIVEFROM - 86400;

        // One row alone cannot move: at the corrected start only that row is in
        // force, so the assessed total there is 0.25.
        try {
            question_mapping_service::correct_effectivefrom([$ids[0]], $backdated, 'Partial move.');
            $this->fail('Moving one row of a split set must be rejected.');
        } catch (validation_exception $e) {
            $this->assertSame('assessedweighttotalinvalid', $e->errorcode);
        }
        $this->assertSame(
            self::EFFECTIVEFROM,
            (int) question_mapping_service::get($ids[0])->effectivefrom,
            'The rejected correction must not have been committed.'
        );

        // The whole set moves together.
        $this->assertSame(4, question_mapping_service::correct_effectivefrom(
            $ids,
            $backdated,
            'The exam measured all four outcomes from the start.'
        ));
        foreach ($ids as $id) {
            $this->assertSame($backdated, (int) question_mapping_service::get($id)->effectivefrom);
        }
        $report = question_mapping_service::validate_assessed_weights($question->versionid, $backdated);
        $this->assertSame(
            decimal::ONE,
            $report->approvedtotal,
            'The four corrected rows must total 1.0 at the corrected start.'
        );
        $this->assertTrue($report->approvedvalid);
    }

    /**
     * A multi-outcome assessed set stays draft until its weights total 1.0, then
     * the final creation carries the whole set through together.
     */
    public function test_autosubmit_defers_until_a_multi_outcome_set_totals_one(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        set_config('autosubmitquestionmappings', 1, 'local_outcomemap');
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['CLO1', 'CLO2', 'CLO3', 'CLO4']);
        $question = $this->create_question();

        $ids = [];
        foreach (['CLO1', 'CLO2', 'CLO3'] as $code) {
            $ids[$code] = question_mapping_service::create([
                'questionversionid' => $question->versionid,
                'itemverid' => $itemverids[$code],
                'role' => 'assesses',
                'weight' => '0.25',
                'effectivefrom' => self::EFFECTIVEFROM,
            ]);
            $this->assertSame(
                workflow::DRAFT,
                question_mapping_service::get($ids[$code])->status,
                "{$code} must stay a draft while the assessed total is below 1.0"
            );
        }

        $ids['CLO4'] = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO4'],
            'role' => 'assesses',
            'weight' => '0.25',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        foreach ($ids as $code => $id) {
            $this->assertSame(
                workflow::APPROVED,
                question_mapping_service::get($id)->status,
                "{$code} must be carried through once the set totals 1.0"
            );
        }
    }

    /**
     * * Autosubmit stays off by default and never touches copied mappings.
     */
    public function test_autosubmit_is_opt_in_and_skips_copies(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        set_config('autosubmitquestionmappings', 0, 'local_outcomemap');
        $reviewer = $this->create_reviewer();
        $itemverids = $this->create_outcomes($reviewer, ['CLO1']);
        $question = $this->create_question();

        // Unset by default.
        $id = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'alignment_only',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $this->assertSame(workflow::DRAFT, question_mapping_service::get($id)->status);

        // Enabled, but a copy carries provenance and must remain a draft.
        set_config('autosubmitquestionmappings', 1, 'local_outcomemap');
        $copyid = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverids['CLO1'],
            'role' => 'teaches',
            'effectivefrom' => self::EFFECTIVEFROM,
            'sourceqmapid' => $id,
            'sourcequestionversionid' => $question->versionid,
        ]);
        $this->assertSame(workflow::DRAFT, question_mapping_service::get($copyid)->status);
    }
}
