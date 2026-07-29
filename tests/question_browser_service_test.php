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

use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\question_browser_service;
use local_outcomemap\local\service\question_mapping_service;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Tests for the course quiz and question browser behind the question mapping page.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_browser_service_test extends \advanced_testcase {
    use \local_outcomemap\tests\moodle_compat_trait;

    /** @var int Outcome effective start shared across fixtures. */
    private const EFFECTIVEFROM = 1704067200;

    /**
     * Create a system manager who can independently approve governed records.
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
     * Create one approved institution outcome version.
     *
     * @param string $code Outcome code.
     * @return int Approved outcome-version ID.
     */
    private function create_outcome(string $code): int {
        global $DB;
        $reviewer = $this->create_reviewer();
        $this->setAdminUser();
        $frameworkid = framework_service::create([
            'code' => 'QBFW' . random_string(4),
            'name' => 'Question browser outcomes',
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        if (workflow::requires_independent_approval()) {
            $this->setUser($reviewer);
            framework_service::approve($frameworkid);
            $this->setAdminUser();
        }
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
        $this->setAdminUser();
        return $itemverid;
    }

    /**
     * Build a course with a quiz holding the requested number of questions.
     *
     * @param int $questioncount Number of specific-question slots to add.
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass[]} Course, quiz, category, questions.
     */
    private function create_quiz_with_questions(int $questioncount): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => $this->question_bank_contextid($course),
        ]);
        $questions = [];
        for ($i = 0; $i < $questioncount; $i++) {
            $question = $questiongenerator->create_question('shortanswer', null, ['category' => $category->id]);
            quiz_add_quiz_question($question->id, $quiz, 0, 1);
            $questions[] = $question;
        }
        return [$course, $quiz, $category, $questions];
    }

    /**
     * The quiz list reports slot counts and mapping coverage per quiz.
     */
    public function test_quizzes_reports_mapping_coverage(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $quiz, , $questions] = $this->create_quiz_with_questions(3);
        $itemverid = $this->create_outcome('CLO1');

        $rows = question_browser_service::quizzes((int) $course->id);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame((int) $quiz->cmid, $row->cmid);
        $this->assertSame(3, $row->slotcount);
        $this->assertSame(3, $row->questioncount);
        $this->assertSame(0, $row->randomslots);
        $this->assertSame(0, $row->mappedcount);
        $this->assertSame(0, $row->assessedcount);

        question_mapping_service::create([
            'questionversionid' => $questions[0]->versionid,
            'itemverid' => $itemverid,
            'role' => 'assesses',
            'weight' => '1',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        question_mapping_service::create([
            'questionversionid' => $questions[1]->versionid,
            'itemverid' => $itemverid,
            'role' => 'teaches',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);

        $rows = question_browser_service::quizzes((int) $course->id);
        $row = reset($rows);
        $this->assertSame(2, $row->mappedcount, 'Both mapped questions must be counted.');
        $this->assertSame(1, $row->assessedcount, 'Only the assessed mapping counts as assessed.');
    }

    /**
     * Assessment coverage groups approved question mappings by quiz and outcome.
     */
    public function test_assessment_coverage_groups_mapped_questions(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        [$course, $quiz, , $questions] = $this->create_quiz_with_questions(3);
        $itemverid = $this->create_outcome('CLO-COVERAGE');

        foreach ([0, 1] as $index) {
            $mappingid = question_mapping_service::create([
                'questionversionid' => $questions[$index]->versionid,
                'itemverid' => $itemverid,
                'role' => 'assesses',
                'weight' => '1',
                'effectivefrom' => self::EFFECTIVEFROM,
            ]);
            question_mapping_service::submit_for_review($mappingid);
        }
        question_mapping_service::create([
            'questionversionid' => $questions[2]->versionid,
            'itemverid' => $itemverid,
            'role' => 'teaches',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);

        $coverage = question_browser_service::assessment_coverage((int) $course->id);

        $this->assertArrayHasKey($itemverid, $coverage);
        $this->assertCount(1, $coverage[$itemverid]);
        $mapping = $coverage[$itemverid][0];
        $this->assertSame((int) $quiz->cmid, $mapping->cmid);
        $this->assertSame(2, $mapping->questioncount);
        $this->assertSame('assesses', $mapping->role);
        $this->assertSame('CLO-COVERAGE', $mapping->outcomecode);
    }

    /**
     * The quiz detail resolves each slot to its exact question version and mappings.
     */
    public function test_quiz_detail_resolves_versions_and_mappings(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $quiz, , $questions] = $this->create_quiz_with_questions(2);
        $itemverid = $this->create_outcome('CLO2');
        question_mapping_service::create([
            'questionversionid' => $questions[0]->versionid,
            'itemverid' => $itemverid,
            'role' => 'assesses',
            'weight' => '1',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);

        $detail = question_browser_service::quiz_detail((int) $course->id, (int) $quiz->cmid);
        $this->assertSame((int) $quiz->cmid, $detail->cmid);
        $this->assertCount(2, $detail->slots);
        $this->assertNotEmpty($detail->banks, 'The bank holding the questions must be reported.');

        $first = $detail->slots[0];
        $this->assertFalse($first->random);
        $this->assertCount(1, $first->questions);
        $question = $first->questions[0];
        $this->assertSame((int) $questions[0]->versionid, $question->questionversionid);
        $this->assertTrue($question->canedit, 'An admin may map questions in this bank.');
        $this->assertCount(1, $question->mappings);
        $this->assertSame('assesses', $question->mappings[0]->role);
        $this->assertSame('CLO2', $question->mappings[0]->outcomecode);

        $second = $detail->slots[1];
        $this->assertCount(1, $second->questions);
        $this->assertSame([], $second->questions[0]->mappings);
    }

    /**
     * A random slot lists the pool a draw could select from, so it can be mapped.
     */
    public function test_quiz_detail_expands_random_slot_pool(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $quiz, $category, $questions] = $this->create_quiz_with_questions(1);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        // Two further questions live only in the pool, never in a specific slot.
        $pooled = [
            $questiongenerator->create_question('shortanswer', null, ['category' => $category->id]),
            $questiongenerator->create_question('shortanswer', null, ['category' => $category->id]),
        ];
        $this->add_random_slot($quiz, $category);

        $detail = question_browser_service::quiz_detail((int) $course->id, (int) $quiz->cmid);
        $randomslots = array_values(array_filter($detail->slots, static fn($slot): bool => $slot->random));
        $this->assertCount(1, $randomslots, 'The random slot must be present.');
        $random = $randomslots[0];
        $this->assertSame($category->name, $random->poolname);

        $poolversions = array_map(
            static fn(\stdClass $question): int => $question->questionversionid,
            $random->questions
        );
        // The pool is the whole category: the slot question plus both pooled ones.
        $this->assertContains((int) $questions[0]->versionid, $poolversions);
        $this->assertContains((int) $pooled[0]->versionid, $poolversions);
        $this->assertContains((int) $pooled[1]->versionid, $poolversions);
        $this->assertFalse($random->pooltruncated);
    }

    /**
     * The quiz list counts random-slot pools, not just fixed slots.
     *
     * A randomly drawn final exam has few fixed slots or none, so counting only
     * fixed slots reported a fully mapped exam as having nothing mapped.
     */
    public function test_quizzes_counts_random_slot_pool(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $quiz, $category, ] = $this->create_quiz_with_questions(1);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $pooled = $questiongenerator->create_question('shortanswer', null, ['category' => $category->id]);
        $this->add_random_slot($quiz, $category);
        $itemverid = $this->create_outcome('CLO1');

        $rows = question_browser_service::quizzes((int) $course->id);
        $row = reset($rows);
        $this->assertSame(2, $row->slotcount);
        $this->assertSame(1, $row->randomslots);
        // The fixed slot's question and the pooled one, counted once each even
        // though the fixed question is also a member of the drawn category.
        $this->assertSame(2, $row->questioncount, 'The pool must be counted and deduplicated.');
        $this->assertSame(0, $row->mappedcount);

        question_mapping_service::create([
            'questionversionid' => $pooled->versionid,
            'itemverid' => $itemverid,
            'role' => 'assesses',
            'weight' => '1',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);

        $rows = question_browser_service::quizzes((int) $course->id);
        $row = reset($rows);
        $this->assertSame(
            1,
            $row->mappedcount,
            'A mapping on a pool-only question must count even with no fixed slot for it.'
        );
        $this->assertSame(1, $row->assessedcount);
    }

    /**
     * A teacher without the mapping capability sees questions but cannot edit them.
     */
    public function test_quiz_detail_reports_read_only_for_unprivileged_user(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $quiz, , ] = $this->create_quiz_with_questions(1);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(
            'local/outcomemap:mapquestions',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($user);

        $detail = question_browser_service::quiz_detail((int) $course->id, (int) $quiz->cmid);
        $this->assertCount(1, $detail->slots);
        $this->assertFalse(
            $detail->slots[0]->questions[0]->canedit,
            'A prohibited mapping capability must not offer editing.'
        );
    }

    /**
     * A course module from another course is rejected rather than silently browsed.
     */
    public function test_quiz_detail_rejects_foreign_module(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, , , ] = $this->create_quiz_with_questions(1);
        [, $otherquiz, , ] = $this->create_quiz_with_questions(1);

        $this->expectException(validation_exception::class);
        question_browser_service::quiz_detail((int) $course->id, (int) $otherquiz->cmid);
    }

    /**
     * Add a random question slot to a quiz across supported Moodle versions.
     *
     * @param \stdClass $quiz Quiz record.
     * @param \stdClass $category Question category to draw from.
     * @return void
     */
    private function add_random_slot(\stdClass $quiz, \stdClass $category): void {
        $structure = \mod_quiz\structure::create_for_quiz(
            \mod_quiz\quiz_settings::create((int) $quiz->id)
        );
        $structure->add_random_questions(0, 1, [
            'filter' => [
                'category' => [
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => [(int) $category->id],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
        ]);
    }
}
