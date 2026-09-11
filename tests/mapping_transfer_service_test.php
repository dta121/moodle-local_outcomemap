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
use local_outcomemap\local\service\foundation_import_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\mapping_transfer_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\question_mapping_service;
use local_outcomemap\local\workflow;

/**
 * Tests for moving question and content mappings between courses as CSV.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_outcomemap\local\service\mapping_transfer_service
 */
final class mapping_transfer_service_test extends \advanced_testcase {
    use \local_outcomemap\tests\moodle_compat_trait;

    /**
     * A course with a quiz of two named questions, an activity with an ID number,
     * and an approved, confirmed course instance.
     *
     * @param string $shortname Course shortname.
     * @return \stdClass course, quizcm, questions (name => record), pagecm, cinstid.
     */
    private function build_course(string $shortname): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $course = $this->getDataGenerator()->create_course(['shortname' => $shortname, 'numsections' => 1]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Final exam']);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $qgen->create_question_category(['contextid' => $this->question_bank_contextid($course)]);
        $questions = [];
        foreach (['Question 1', 'Question 2'] as $name) {
            $question = $qgen->create_question('shortanswer', null, ['category' => $category->id, 'name' => $name]);
            quiz_add_quiz_question($question->id, $quiz, 0, 1);
            $questions[$name] = $question;
        }
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'section' => 1,
            'name' => 'Evidence workshop',
            'idnumber' => 'evidence-' . $shortname,
        ]);
        $pagecm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $catalogid = catalog_course_service::create(['code' => 'CAT-' . $shortname, 'name' => 'Catalog ' . $shortname]);
        catalog_course_service::submit_for_review($catalogid);
        $cinstid = course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => $shortname,
        ]);
        course_instance_service::submit_for_review($cinstid);
        if (!$DB->get_field('local_outcomemap_cinst', 'confirmed', ['id' => $cinstid])) {
            course_instance_service::confirm($cinstid);
        }
        return (object) [
            'course' => $course,
            'quizcmid' => (int) $quiz->cmid,
            'questions' => $questions,
            'pagecm' => $pagecm,
            'cinstid' => $cinstid,
        ];
    }

    /**
     * Approved institution-scope outcomes, visible from any course.
     *
     * @param string[] $codes Outcome codes.
     * @return array<string,int> Version ids by code.
     */
    private function outcomes(array $codes): array {
        global $DB;
        $frameworkid = framework_service::create([
            'code' => 'XFER',
            'name' => 'Transfer outcomes',
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        $versions = [];
        foreach ($codes as $code) {
            $itemid = outcome_service::create([
                'frameworkid' => $frameworkid,
                'code' => $code,
                'statement' => 'Outcome ' . $code,
                'effectivefrom' => 1704067200,
            ]);
            $versionid = (int) $DB->get_field('local_outcomemap_itemver', 'id', ['itemid' => $itemid], MUST_EXIST);
            outcome_service::submit_for_review($versionid);
            $versions[$code] = $versionid;
        }
        return $versions;
    }

    /**
     * Load, preview and commit one CSV, asserting the preview is valid.
     *
     * @param string $entity Import entity.
     * @param array $rows Rows including the header.
     * @return array Preview rows.
     */
    private function import(string $entity, array $rows): array {
        $csv = implode("\n", array_map(static function (array $row): string {
            return implode(',', array_map(static fn($cell) => '"' . str_replace('"', '""', (string) $cell) . '"', $row));
        }, $rows)) . "\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, $entity);
        $this->assertTrue($preview->valid, json_encode(array_map(static fn($r) => $r->errors, $preview->rows)));
        foundation_import_service::commit($importid, $entity, $preview->hash);
        foundation_import_service::cleanup($importid);
        return $preview->rows;
    }

    /**
     * * Mappings exported from one course import into a structurally identical course, once.
     */
    public function test_mappings_round_trip_between_courses_by_name(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        set_config('autosubmitquestionmappings', 1, 'local_outcomemap');

        $versions = $this->outcomes(['CLO1', 'CLO2']);
        $source = $this->build_course('SRC101');

        // Source: Question 1 assesses CLO1 and CLO2 half each; the page teaches CLO1.
        $q1version = (int) $DB->get_field('question_versions', 'id', [
            'questionid' => $source->questions['Question 1']->id,
        ], MUST_EXIST);
        foreach (['CLO1', 'CLO2'] as $code) {
            question_mapping_service::create([
                'questionversionid' => $q1version,
                'itemverid' => $versions[$code],
                'role' => 'assesses',
                'weight' => '0.5',
                'notes' => 'From the ' . $code . ' blueprint',
                'effectivefrom' => 1704067200,
            ]);
        }
        $this->assertSame(2, $DB->count_records('local_outcomemap_qmap', ['status' => workflow::APPROVED]));
        $contentid = content_mapping_service::create_course_module([
            'cinstid' => $source->cinstid,
            'cmid' => $source->pagecm->id,
            'itemverid' => $versions['CLO1'],
            'role' => content_mapping_service::ROLE_TEACHES,
            'priority' => 2,
            'effectivefrom' => 1704067200,
        ]);
        content_mapping_service::submit_for_review(content_mapping_service::TARGET_MODULE, $contentid);

        // Export names things, not ids.
        $qrows = mapping_transfer_service::export_question_mappings($source->course->id);
        $this->assertSame(mapping_transfer_service::QUESTION_HEADERS, $qrows[0]);
        $this->assertCount(3, $qrows);
        $this->assertSame(
            ['SRC101', 'Final exam', 'Question 1', '1', 'XFER.CLO1', 'assesses', '0.5000000000'],
            array_slice($qrows[1], 0, 7)
        );
        $this->assertSame('1704067200', $qrows[1][7]);
        $this->assertSame('From the CLO1 blueprint', $qrows[1][9]);

        $crows = mapping_transfer_service::export_content_mappings($source->course->id);
        $this->assertCount(2, $crows);
        $this->assertSame(['SRC101', 'module', 'evidence-SRC101', 'XFER.CLO1', 'teaches', '', '2'], array_slice($crows[1], 0, 7));

        // Target: same names, different ids. Re-point the course column only.
        $target = $this->build_course('TGT101');
        $qrows[1][0] = $qrows[2][0] = 'TGT101';
        $crows[1][0] = 'TGT101';
        $crows[1][2] = 'evidence-TGT101';

        $this->import(foundation_import_service::QUESTION_MAPPINGS, $qrows);
        $tq1version = (int) $DB->get_field('question_versions', 'id', [
            'questionid' => $target->questions['Question 1']->id,
        ], MUST_EXIST);
        $imported = $DB->get_records('local_outcomemap_qmap', ['questionversionid' => $tq1version], 'itemverid');
        $this->assertCount(2, $imported);
        foreach ($imported as $mapping) {
            $this->assertSame(workflow::APPROVED, $mapping->status, 'The complete set finalizes as it would from the page.');
            $this->assertSame('0.5000000000', $mapping->weight);
            $this->assertSame(1704067200, (int) $mapping->effectivefrom);
        }

        $this->import(foundation_import_service::CONTENT_MAPPINGS, $crows);
        $content = $DB->get_records('local_outcomemap_cmmap', ['cmid' => $target->pagecm->id]);
        $this->assertCount(1, $content);
        $content = reset($content);
        $this->assertSame($target->cinstid, (int) $content->cinstid);
        $this->assertSame(2, (int) $content->priority);
        $this->assertSame(workflow::APPROVED, $content->status);

        // A second import skips every row, and says so.
        $preview = $this->import(foundation_import_service::QUESTION_MAPPINGS, $qrows);
        $this->assertSame('Already mapped; row skipped.', $preview[0]->note);
        $this->assertCount(2, $DB->get_records('local_outcomemap_qmap', ['questionversionid' => $tq1version]));
        $preview = $this->import(foundation_import_service::CONTENT_MAPPINGS, $crows);
        $this->assertSame('Already mapped; row skipped.', $preview[0]->note);
        $this->assertCount(1, $DB->get_records('local_outcomemap_cmmap', ['cmid' => $target->pagecm->id]));

        // Unknown names are reported by name, before anything is written.
        $qrows[1][2] = 'Question 9';
        $csv = implode("\n", array_map(static fn($r) => implode(',', $r), $qrows)) . "\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, foundation_import_service::QUESTION_MAPPINGS);
        foundation_import_service::cleanup($importid);
        $this->assertFalse($preview->valid);
        $this->assertStringContainsString('Question 9', implode(' ', $preview->rows[0]->errors));
    }

    /**
     * A historical mapping is rebound to the outcome version covering its dates.
     */
    public function test_question_import_resolves_the_historical_outcome_version(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        set_config('autosubmitquestionmappings', 1, 'local_outcomemap');

        $frameworkid = framework_service::create([
            'code' => 'XFER',
            'name' => 'Transfer outcomes',
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => 'CLO1',
            'statement' => 'Original outcome',
            'effectivefrom' => 1704067200,
            'effectiveto' => 1735689600,
        ]);
        $version1id = (int) $DB->get_field(
            'local_outcomemap_itemver',
            'id',
            ['itemid' => $itemid],
            MUST_EXIST
        );
        outcome_service::submit_for_review($version1id);
        $version2id = outcome_service::create_version($itemid, [
            'statement' => 'Revised outcome',
            'effectivefrom' => 1735689600,
        ]);
        outcome_service::submit_for_review($version2id);

        $source = $this->build_course('HISTSRC');
        $sourcequestionversion = (int) $DB->get_field('question_versions', 'id', [
            'questionid' => $source->questions['Question 1']->id,
        ], MUST_EXIST);
        question_mapping_service::create([
            'questionversionid' => $sourcequestionversion,
            'itemverid' => $version1id,
            'role' => content_mapping_service::ROLE_TEACHES,
            'effectivefrom' => 1704067200,
            'effectiveto' => 1735689600,
        ]);

        $rows = mapping_transfer_service::export_question_mappings($source->course->id);
        $target = $this->build_course('HISTTGT');
        $rows[1][0] = 'HISTTGT';
        $this->import(foundation_import_service::QUESTION_MAPPINGS, $rows);

        $targetquestionversion = (int) $DB->get_field('question_versions', 'id', [
            'questionid' => $target->questions['Question 1']->id,
        ], MUST_EXIST);
        $imported = $DB->get_record(
            'local_outcomemap_qmap',
            ['questionversionid' => $targetquestionversion],
            '*',
            MUST_EXIST
        );
        $this->assertSame($version1id, (int) $imported->itemverid);
        $this->assertNotSame($version2id, (int) $imported->itemverid);
    }
}
