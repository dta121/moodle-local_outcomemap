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
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\question_mapping_service;
use local_outcomemap\local\service\remediation_service;
use local_outcomemap\local\workflow;

/**
 * Tests for draft-safe course backup and restore.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_restore_test extends \advanced_testcase {
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
            'code' => 'BACKUP' . $course->id,
            'name' => 'Backup catalog course',
        ]);
        $cinstid = course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-BACKUP',
        ]);
        course_instance_service::submit_for_review($cinstid);
        $this->setUser($reviewer);
        course_instance_service::confirm($cinstid);
        $this->setAdminUser();
        $frameworkid = framework_service::create([
            'code' => 'BACKUPFW' . $course->id,
            'name' => 'Backup outcomes',
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        $this->setUser($reviewer);
        framework_service::approve($frameworkid);
        $this->setAdminUser();
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => 'CLO1',
            'statement' => 'Demonstrate backup-safe outcome alignment.',
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
     * Tests that course restore creates new draft mappings.
     */
    public function test_course_restore_creates_new_draft_mappings(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'OMBACKUP',
            'fullname' => 'Outcome mapping backup source',
            'numsections' => 1,
        ]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'section' => 1,
            'name' => 'Mapped resource',
        ]);
        $sourcecm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $sourcesectionid = (int) $DB->get_field('course_sections', 'id', [
            'course' => $course->id,
            'section' => 1,
        ], MUST_EXIST);
        $reviewer = $this->create_reviewer();
        [$sourcecinstid, $itemverid] = $this->create_scope($course, $reviewer);
        $calculationpolicyid = \local_outcomemap\local\service\policy_service::create([
            'policytype' => \local_outcomemap\local\service\policy_service::TYPE_CALCULATION,
            'scopetype' => \local_outcomemap\local\service\policy_service::SCOPE_INSTITUTION,
            'name' => 'Backup remediation bands',
            'config' => ['minitems' => 1, 'displayscale' => 1],
            'bands' => [[
                'code' => 'below',
                'name' => 'Below expectations',
                'minpercent' => '0',
                'maxpercent' => '70',
            ]],
            'effectivefrom' => 1704067200,
        ]);
        \local_outcomemap\local\service\policy_service::submit_for_review($calculationpolicyid);
        $this->setUser($reviewer);
        \local_outcomemap\local\service\policy_service::approve($calculationpolicyid);
        $this->setAdminUser();
        $band = \local_outcomemap\local\service\policy_service::get_bands($calculationpolicyid)[0];
        $nextpolicyid = \local_outcomemap\local\service\policy_service::create_version($calculationpolicyid, [
            'name' => 'Backup remediation bands v2',
            'config' => ['minitems' => 1, 'displayscale' => 1],
            'bands' => [[
                'code' => 'below',
                'name' => 'Below expectations revised',
                'minpercent' => '0',
                'maxpercent' => '75',
            ]],
            'effectivefrom' => 1800000000,
        ]);
        $nextband = \local_outcomemap\local\service\policy_service::get_bands($nextpolicyid)[0];
        $this->assertNotSame((int) $band->id, (int) $nextband->id);

        $modulemappingid = content_mapping_service::create_course_module([
            'cinstid' => $sourcecinstid,
            'cmid' => $sourcecm->id,
            'itemverid' => $itemverid,
            'role' => content_mapping_service::ROLE_PRACTICES,
            'effectivefrom' => 1704067200,
        ]);
        $sectionmappingid = content_mapping_service::create_section([
            'cinstid' => $sourcecinstid,
            'sectionid' => $sourcesectionid,
            'itemverid' => $itemverid,
            'role' => content_mapping_service::ROLE_TEACHES,
            'effectivefrom' => 1704067200,
        ]);
        $remediationid = remediation_service::create([
            'cinstid' => $sourcecinstid,
            'itemverid' => $itemverid,
            'bandid' => $band->id,
            'targettype' => remediation_service::TARGET_EXTERNAL,
            'purpose' => remediation_service::PURPOSE_PRACTICE,
            'externalurl' => 'https://example.test/restore-safe',
            'title' => 'Restore-safe recommendation',
            'sortorder' => 17,
            'effectivefrom' => 1704067200,
        ]);
        content_mapping_service::submit_for_review(content_mapping_service::TARGET_MODULE, $modulemappingid);
        content_mapping_service::submit_for_review(content_mapping_service::TARGET_SECTION, $sectionmappingid);
        remediation_service::submit_for_review($remediationid);
        $this->setUser($reviewer);
        content_mapping_service::approve(content_mapping_service::TARGET_MODULE, $modulemappingid);
        content_mapping_service::approve(content_mapping_service::TARGET_SECTION, $sectionmappingid);
        remediation_service::approve($remediationid);
        $this->setAdminUser();

        $sourcecinst = $DB->get_record('local_outcomemap_cinst', ['id' => $sourcecinstid], '*', MUST_EXIST);
        $sourcemodulemapping = $DB->get_record('local_outcomemap_cmmap', ['id' => $modulemappingid], '*', MUST_EXIST);
        $sourcesectionmapping = $DB->get_record('local_outcomemap_secmap', ['id' => $sectionmappingid], '*', MUST_EXIST);
        $sourceremediation = $DB->get_record('local_outcomemap_remed', ['id' => $remediationid], '*', MUST_EXIST);

        $backupcontroller = null;
        try {
            $backupcontroller = new \backup_controller(
                \backup::TYPE_1COURSE,
                $course->id,
                \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO,
                \backup::MODE_IMPORT,
                $USER->id,
            );
            $backupid = $backupcontroller->get_backupid();
            $backupcontroller->execute_plan();
        } finally {
            if ($backupcontroller) {
                $backupcontroller->destroy();
            }
        }

        $newcourseid = \restore_dbops::create_new_course(
            'Outcome mapping backup restored',
            'OMBACKUP-RESTORED',
            $course->category,
        );
        $restorecontroller = null;
        try {
            $restorecontroller = new \restore_controller(
                $backupid,
                $newcourseid,
                \backup::INTERACTIVE_NO,
                \backup::MODE_GENERAL,
                $USER->id,
                \backup::TARGET_NEW_COURSE,
            );
            $this->assertTrue($restorecontroller->execute_precheck());
            $restorecontroller->execute_plan();
        } finally {
            if ($restorecontroller) {
                $restorecontroller->destroy();
            }
        }

        $restoredcinst = $DB->get_record('local_outcomemap_cinst', [
            'moodlecourseid' => $newcourseid,
            'periodcode' => '2026-BACKUP',
        ], '*', MUST_EXIST);
        $this->assertSame(workflow::DRAFT, $restoredcinst->status);
        $this->assertEquals(0, $restoredcinst->confirmed);
        $this->assertNotSame($sourcecinst->uuid, $restoredcinst->uuid);

        $restoredmodinfo = get_fast_modinfo($newcourseid);
        $restoredpages = $restoredmodinfo->get_instances_of('page');
        $this->assertCount(1, $restoredpages);
        $restoredcm = reset($restoredpages);
        $restoredsection = $restoredmodinfo->get_section_info(1, MUST_EXIST);
        $restoredmodulemapping = $DB->get_record('local_outcomemap_cmmap', [
            'cinstid' => $restoredcinst->id,
            'cmid' => $restoredcm->id,
        ], '*', MUST_EXIST);
        $restoredsectionmapping = $DB->get_record('local_outcomemap_secmap', [
            'cinstid' => $restoredcinst->id,
            'sectionid' => $restoredsection->id,
        ], '*', MUST_EXIST);
        $restoredremediation = $DB->get_record('local_outcomemap_remed', [
            'cinstid' => $restoredcinst->id,
            'targettype' => remediation_service::TARGET_EXTERNAL,
        ], '*', MUST_EXIST);
        $this->assertSame(workflow::DRAFT, $restoredmodulemapping->status);
        $this->assertSame(workflow::DRAFT, $restoredsectionmapping->status);
        $this->assertSame(workflow::DRAFT, $restoredremediation->status);
        $this->assertEquals($itemverid, $restoredmodulemapping->itemverid);
        $this->assertEquals($itemverid, $restoredsectionmapping->itemverid);
        $this->assertEquals(1, $restoredmodulemapping->version);
        $this->assertNotSame($sourcemodulemapping->mappinguuid, $restoredmodulemapping->mappinguuid);
        $this->assertNotSame($sourcesectionmapping->mappinguuid, $restoredsectionmapping->mappinguuid);
        $this->assertNotSame($sourceremediation->mappinguuid, $restoredremediation->mappinguuid);
        $this->assertSame(remediation_service::PURPOSE_PRACTICE, $restoredremediation->purpose);
        $this->assertSame(17, (int) $restoredremediation->sortorder);
        $this->assertSame((int) $band->id, (int) $restoredremediation->bandid);

        $itemversion = $DB->get_record('local_outcomemap_itemver', ['id' => $itemverid], '*', MUST_EXIST);
        $itemuuid = $DB->get_field('local_outcomemap_item', 'uuid', ['id' => $itemversion->itemid], MUST_EXIST);
        $beforecount = $DB->count_records('local_outcomemap_remed');
        $this->assertNull(\local_outcomemap\local\backup\mapping_restorer::restore_remediation(
            remediation_service::TARGET_EXTERNAL,
            (object) [
                'outcomeuuid' => $itemuuid,
                'outcomeversionuuid' => $itemversion->uuid,
                'bandpolicyuuid' => \local_outcomemap\local\uuid::generate(),
                'bandpolicyversion' => 1,
                'bandcode' => 'below',
                'externalurl' => 'https://example.test/unresolved-band',
                'title' => 'Must not broaden on restore',
                'purpose' => remediation_service::PURPOSE_REVIEW,
                'effectivefrom' => 1704067200,
            ],
            (int) $restoredcinst->id
        ));
        $this->assertSame($beforecount, $DB->count_records('local_outcomemap_remed'));
    }

    /**
     * Tests that course restore recreates question mappings as drafts on the restored version.
     */
    public function test_course_restore_creates_question_mapping_drafts(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'OMQBACKUP',
            'fullname' => 'Question mapping backup source',
        ]);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_module::instance($qbank->cmid)->id,
        ]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $category->id]);
        $reviewer = $this->create_reviewer();
        [, $itemverid] = $this->create_scope($course, $reviewer);

        $mappingid = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverid,
            'role' => 'assesses',
            'weight' => '1.0',
            'effectivefrom' => 1704067200,
        ]);
        question_mapping_service::submit_for_review($mappingid);
        $this->setUser($reviewer);
        question_mapping_service::approve($mappingid);
        $this->setAdminUser();
        $sourcemapping = $DB->get_record('local_outcomemap_qmap', ['id' => $mappingid], '*', MUST_EXIST);

        $backupcontroller = null;
        try {
            $backupcontroller = new \backup_controller(
                \backup::TYPE_1COURSE,
                $course->id,
                \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO,
                \backup::MODE_IMPORT,
                $USER->id,
            );
            $backupid = $backupcontroller->get_backupid();
            $backupcontroller->execute_plan();
        } finally {
            if ($backupcontroller) {
                $backupcontroller->destroy();
            }
        }

        $newcourseid = \restore_dbops::create_new_course(
            'Question mapping backup restored',
            'OMQBACKUP-RESTORED',
            $course->category,
        );
        $restorecontroller = null;
        try {
            $restorecontroller = new \restore_controller(
                $backupid,
                $newcourseid,
                \backup::INTERACTIVE_NO,
                \backup::MODE_GENERAL,
                $USER->id,
                \backup::TARGET_NEW_COURSE,
            );
            $this->assertTrue($restorecontroller->execute_precheck());
            $restorecontroller->execute_plan();
        } finally {
            if ($restorecontroller) {
                $restorecontroller->destroy();
            }
        }

        $restoredmapping = $DB->get_record_select(
            'local_outcomemap_qmap',
            'questionversionid <> :sourceversion',
            ['sourceversion' => (int) $question->versionid],
            '*',
            MUST_EXIST
        );
        $this->assertSame(workflow::DRAFT, $restoredmapping->status);
        $this->assertSame('1.0000000000', $restoredmapping->weight);
        $this->assertSame('assesses', $restoredmapping->role);
        $this->assertEquals($itemverid, $restoredmapping->itemverid);
        $this->assertEquals(1, $restoredmapping->version);
        $this->assertNotSame($sourcemapping->mappinguuid, $restoredmapping->mappinguuid);
        $restoredversion = $DB->get_record('question_versions', [
            'id' => $restoredmapping->questionversionid,
        ], '*', MUST_EXIST);
        $this->assertEquals($restoredversion->questionid, $restoredmapping->questionid);
        // The source mapping remains approved on the original version.
        $this->assertSame(workflow::APPROVED,
            $DB->get_field('local_outcomemap_qmap', 'status', ['id' => $mappingid]));
    }
}
