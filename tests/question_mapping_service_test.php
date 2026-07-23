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
 */
final class question_mapping_service_test extends \advanced_testcase {
    /** @var int Outcome effective start shared across fixtures. */
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
        $this->setUser($reviewer);
        framework_service::approve($frameworkid);
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
            $this->setUser($reviewer);
            outcome_service::approve($itemverid);
            $itemverids[$code] = $itemverid;
        }
        $this->setAdminUser();
        return $itemverids;
    }

    /**
     * Creates a question inside a question-bank module context.
     *
     * @return \stdClass Question data including versionid.
     */
    private function create_question(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category([
            'contextid' => \context_module::instance($qbank->cmid)->id,
        ]);
        return $generator->create_question('shortanswer', null, ['category' => $category->id]);
    }

    /**
     * Tests draft rules: assessed weight required, no weight on other roles, question binding.
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
     * Tests that assessed sets approve together and must total exactly one.
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
            $this->assertSame(workflow::APPROVED,
                $DB->get_field('local_outcomemap_qmap', 'status', ['id' => $id]));
        }
        $report = question_mapping_service::validate_assessed_weights((int) $question->versionid,
            self::EFFECTIVEFROM);
        $this->assertSame('1.0000000000', $report->approvedtotal);
        $this->assertTrue($report->approvedvalid);
    }

    /**
     * Tests alignment-only approval and duplicate-scope rejection.
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
        $this->assertSame(workflow::APPROVED,
            $DB->get_field('local_outcomemap_qmap', 'status', ['id' => $alignment]));

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
     * Tests explicit copy-to-version and the automatic observer copy.
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

        $newids = question_mapping_service::copy_to_version((int) $newversion->versionid);
        $this->assertCount(2, $newids);
        $copies = $DB->get_records('local_outcomemap_qmap', ['questionversionid' => $newversion->versionid]);
        foreach ($copies as $copy) {
            $this->assertSame(workflow::DRAFT, $copy->status);
            $this->assertSame(1, (int) $copy->version);
            $this->assertSame((int) $newversion->id, (int) $copy->questionid);
            $this->assertFalse($DB->record_exists_select('local_outcomemap_qmap',
                'mappinguuid = :uuid AND id <> :id', ['uuid' => $copy->mappinguuid, 'id' => $copy->id]));
        }
        // Source mappings stay approved on the old version for historical attempts.
        $this->assertSame(workflow::APPROVED,
            $DB->get_field('local_outcomemap_qmap', 'status', ['id' => $assessed]));

        // The copy is idempotent.
        $this->assertSame([], question_mapping_service::copy_to_version((int) $newversion->versionid));

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
     * Tests bulk retrieval, DTO conversion, and capability filtering.
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
        $clo2uuid = $DB->get_field('local_outcomemap_itemver', 'uuid',
            ['id' => $itemverids['CLO2']], MUST_EXIST);
        question_mappings::create_draft((int) $question->versionid, $clo2uuid, 'alignment_only',
            null, null, self::EFFECTIVEFROM);

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
     * Tests the assessed-weight validation report used by the qbank filter.
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
     * Tests exact decimal addition used for weight totals.
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
}
