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

use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\coverage_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\remediation_service;
use local_outcomemap\local\service\student_result_service;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Tests for governed course-content mappings and remediation.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class content_mapping_service_test extends \advanced_testcase {
    /**
     * Creates a system manager who can independently approve course mappings.
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
     * Creates an approved course instance and exact outcome version.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $reviewer Reviewer user record.
     * @return array{0:int,1:int} Course-instance ID and outcome-version ID.
     */
    private function create_scope(\stdClass $course, \stdClass $reviewer): array {
        global $DB;
        $this->setAdminUser();
        $catalogid = catalog_course_service::create([
            'code' => 'CAT' . $course->id,
            'name' => 'Catalog course ' . $course->id,
        ]);
        $cinstid = course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-T1',
        ]);
        course_instance_service::submit_for_review($cinstid);
        $this->setUser($reviewer);
        course_instance_service::confirm($cinstid);

        $this->setAdminUser();
        $frameworkid = framework_service::create([
            'code' => 'FW' . $course->id,
            'name' => 'Course outcomes ' . $course->id,
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        $this->setUser($reviewer);
        framework_service::approve($frameworkid);

        $this->setAdminUser();
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => 'CLO1',
            'statement' => 'Evaluate evidence for a strategic decision.',
            'effectivefrom' => 1704067200,
        ]);
        $itemverid = (int) $DB->get_field('local_outcomemap_itemver', 'id', ['itemid' => $itemid], MUST_EXIST);
        outcome_service::submit_for_review($itemverid);
        $this->setUser($reviewer);
        outcome_service::approve($itemverid);
        $this->setAdminUser();
        return [$cinstid, $itemverid];
    }

    /**
     * Tests governed module and section mappings.
     */
    public function test_governed_module_and_section_mappings(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'section' => 1,
            'name' => 'Evidence workshop',
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $sectionid = (int) $DB->get_field('course_sections', 'id', [
            'course' => $course->id,
            'section' => 1,
        ], MUST_EXIST);
        $reviewer = $this->create_reviewer();
        [$cinstid, $itemverid] = $this->create_scope($course, $reviewer);

        $modulemappingid = content_mapping_service::create_course_module([
            'cinstid' => $cinstid,
            'cmid' => $cm->id,
            'itemverid' => $itemverid,
            'role' => content_mapping_service::ROLE_ASSESSES,
            'weight' => '0.5',
            'priority' => 2,
            'effectivefrom' => 1704067200,
        ]);
        $sectionmappingid = content_mapping_service::create_section([
            'cinstid' => $cinstid,
            'sectionid' => $sectionid,
            'itemverid' => $itemverid,
            'role' => content_mapping_service::ROLE_TEACHES,
            'effectivefrom' => 1704067200,
        ]);
        content_mapping_service::submit_for_review(content_mapping_service::TARGET_MODULE, $modulemappingid);
        content_mapping_service::submit_for_review(content_mapping_service::TARGET_SECTION, $sectionmappingid);

        $this->setUser($reviewer);
        content_mapping_service::approve(content_mapping_service::TARGET_MODULE, $modulemappingid);
        content_mapping_service::approve(content_mapping_service::TARGET_SECTION, $sectionmappingid);
        $this->assertSame(workflow::APPROVED, $DB->get_field('local_outcomemap_cmmap', 'status', [
            'id' => $modulemappingid,
        ]));
        $this->assertSame('0.5000000000', $DB->get_field('local_outcomemap_cmmap', 'weight', [
            'id' => $modulemappingid,
        ]));

        $this->setAdminUser();
        try {
            content_mapping_service::update_draft(content_mapping_service::TARGET_MODULE, $modulemappingid, [
                'notes' => 'Attempt to rewrite approved history.',
            ]);
            $this->fail('An approved mapping was updated in place.');
        } catch (validation_exception $e) {
            $this->assertSame('approvedimmutable', $e->errorcode);
        }

        $othercourse = $this->getDataGenerator()->create_course();
        $otherpage = $this->getDataGenerator()->create_module('page', ['course' => $othercourse->id]);
        $othercm = get_coursemodule_from_instance('page', $otherpage->id, $othercourse->id, false, MUST_EXIST);
        try {
            content_mapping_service::create_course_module([
                'cinstid' => $cinstid,
                'cmid' => $othercm->id,
                'itemverid' => $itemverid,
                'role' => content_mapping_service::ROLE_PRACTICES,
                'effectivefrom' => 1704067200,
            ]);
            $this->fail('A module from another course was mapped.');
        } catch (validation_exception $e) {
            $this->assertSame('targetcoursemismatch', $e->errorcode);
        }

        $matrix = coverage_service::matrix($course->id);
        $this->assertArrayHasKey($itemverid, $matrix);
        $this->assertCount(1, $matrix[$itemverid]->modules);
        $this->assertCount(1, $matrix[$itemverid]->sections);
    }

    /**
     * Tests the external remediation workflow.
     */
    public function test_external_remediation_workflow(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $reviewer = $this->create_reviewer();
        [$cinstid, $itemverid] = $this->create_scope($course, $reviewer);

        $id = remediation_service::create([
            'cinstid' => $cinstid,
            'itemverid' => $itemverid,
            'targettype' => remediation_service::TARGET_EXTERNAL,
            'externalurl' => 'https://example.test/remediation',
            'title' => 'Review evidence evaluation',
            'minpercent' => '0',
            'maxpercent' => '69.999',
            'required' => 1,
            'effectivefrom' => 1704067200,
        ]);
        remediation_service::submit_for_review($id);
        $this->setUser($reviewer);
        remediation_service::approve($id);

        $record = $DB->get_record('local_outcomemap_remed', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(workflow::APPROVED, $record->status);
        $this->assertNull($record->targetid);
        $this->assertSame('0.0000000000', $record->minpercent);
        $this->assertSame('69.9990000000', $record->maxpercent);
        $this->assertEquals(1, $record->required);
    }

    /**
     * Tests governed band/range selection, ordering, access filtering, and safe output fields.
     */
    public function test_accessible_remediation_selection_is_exact_ordered_and_learner_safe(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $at = 1800000000;
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $visiblepage = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'section' => 1,
            'name' => 'Visible review',
        ]);
        $hiddenpage = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'section' => 1,
            'name' => 'Hidden review',
            'visible' => 0,
        ]);
        $visiblecm = get_coursemodule_from_instance('page', $visiblepage->id, $course->id, false, MUST_EXIST);
        $hiddencm = get_coursemodule_from_instance('page', $hiddenpage->id, $course->id, false, MUST_EXIST);
        $hiddensectionid = (int) $DB->get_field('course_sections', 'id', [
            'course' => $course->id,
            'section' => 2,
        ], MUST_EXIST);
        $DB->set_field('course_sections', 'visible', 0, ['id' => $hiddensectionid]);
        rebuild_course_cache($course->id, true);

        $reviewer = $this->create_reviewer();
        [$cinstid, $itemverid] = $this->create_scope($course, $reviewer);
        $policyid = policy_service::create([
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Remediation bands',
            'config' => ['minitems' => 1, 'displayscale' => 1],
            'bands' => [
                ['code' => 'below', 'name' => 'Below', 'minpercent' => '0', 'maxpercent' => '70'],
                ['code' => 'met', 'name' => 'Met', 'minpercent' => '70', 'maxpercent' => '100',
                    'maxinclusive' => true],
            ],
            'effectivefrom' => 1704067200,
        ]);
        policy_service::submit_for_review($policyid);
        $this->setUser($reviewer);
        policy_service::approve($policyid);
        $this->setAdminUser();
        [$belowband, $metband] = policy_service::get_bands($policyid);

        $base = [
            'cinstid' => $cinstid,
            'itemverid' => $itemverid,
            'purpose' => remediation_service::PURPOSE_REVIEW,
            'effectivefrom' => 1704067200,
        ];
        $create = function(array $data, bool $approve = true) use ($base, $reviewer): int {
            $this->setAdminUser();
            $id = remediation_service::create(array_merge($base, $data));
            if ($approve) {
                remediation_service::submit_for_review($id);
                $this->setUser($reviewer);
                remediation_service::approve($id);
                $this->setAdminUser();
            }
            return $id;
        };
        $create([
            'targettype' => remediation_service::TARGET_EXTERNAL,
            'externalurl' => 'https://example.test/high-priority',
            'title' => 'External first',
            'minpercent' => '0',
            'maxpercent' => '50',
            'priority' => 10,
            'sortorder' => 9,
        ]);
        $create([
            'bandid' => $belowband->id,
            'targettype' => remediation_service::TARGET_MODULE,
            'targetid' => $visiblecm->id,
            'title' => 'Module second',
            'minpercent' => '50',
            'maxpercent' => '50',
            'priority' => 5,
            'sortorder' => 2,
        ]);
        $create([
            'bandid' => $belowband->id,
            'targettype' => remediation_service::TARGET_MODULE,
            'targetid' => $visiblecm->id,
            'title' => 'Module first',
            'minpercent' => '50',
            'maxpercent' => '50',
            'priority' => 5,
            'sortorder' => 1,
        ]);
        $create([
            'bandid' => $metband->id,
            'targettype' => remediation_service::TARGET_MODULE,
            'targetid' => $visiblecm->id,
            'title' => 'Wrong exact band',
            'minpercent' => '0',
            'maxpercent' => '100',
            'priority' => 50,
        ]);
        $create([
            'targettype' => remediation_service::TARGET_MODULE,
            'targetid' => $visiblecm->id,
            'title' => 'Outside percentage',
            'minpercent' => '51',
            'maxpercent' => '100',
            'priority' => 50,
        ]);
        $create([
            'targettype' => remediation_service::TARGET_MODULE,
            'targetid' => $hiddencm->id,
            'title' => 'Hidden module',
            'minpercent' => '0',
            'maxpercent' => '100',
            'priority' => 50,
        ]);
        $create([
            'targettype' => remediation_service::TARGET_SECTION,
            'targetid' => $hiddensectionid,
            'title' => 'Hidden section',
            'minpercent' => '0',
            'maxpercent' => '100',
            'priority' => 50,
        ]);
        $create([
            'targettype' => remediation_service::TARGET_EXTERNAL,
            'externalurl' => 'https://example.test/future',
            'title' => 'Future recommendation',
            'effectivefrom' => $at + 1,
            'priority' => 50,
        ]);
        $create([
            'targettype' => remediation_service::TARGET_EXTERNAL,
            'externalurl' => 'https://example.test/expired',
            'title' => 'Expired recommendation',
            'effectiveto' => $at,
            'priority' => 50,
        ]);
        $create([
            'targettype' => remediation_service::TARGET_EXTERNAL,
            'externalurl' => 'https://example.test/draft',
            'title' => 'Draft recommendation',
            'priority' => 50,
        ], false);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $modinfo = get_fast_modinfo($course->id, $student->id);
        $selector = new \ReflectionMethod(student_result_service::class, 'select_accessible_remediation');
        $selected = $selector->invoke(null, $course->id, [
            'clo' => [
                'cinstid' => $cinstid,
                'itemverid' => $itemverid,
                'bandid' => $belowband->id,
                'percentage' => '50',
            ],
            'notcalculated' => [
                'cinstid' => $cinstid,
                'itemverid' => $itemverid,
                'bandid' => $belowband->id,
                'percentage' => null,
            ],
        ], $at, $modinfo, $modinfo->get_cms());

        $this->assertSame(['External first', 'Module first', 'Module second'],
            array_column($selected['clo'], 'title'));
        $this->assertSame([], $selected['notcalculated']);
        $this->assertSame([
            'title', 'explanation', 'url', 'required', 'purpose', 'priority', 'sortorder',
        ], array_keys($selected['clo'][0]));
        $this->assertSame(remediation_service::PURPOSE_REVIEW, $selected['clo'][0]['purpose']);
        $this->assertStringStartsWith('https://', $selected['clo'][0]['url']);
        foreach ($selected['clo'] as $recommendation) {
            $this->assertArrayNotHasKey('targetid', $recommendation);
            $this->assertArrayNotHasKey('externalurl', $recommendation);
            $this->assertArrayNotHasKey('bandid', $recommendation);
        }
    }
}
