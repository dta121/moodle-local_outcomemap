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
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use local_outcomemap\local\feature;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\question_mapping_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\task\backfill_prior_attempts;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Attempts finished before their outcomes were mapped.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_outcomemap\local\service\calculation_service
 * @covers \local_outcomemap\task\backfill_prior_attempts
 * @covers \local_outcomemap\task\reconcile_evidence
 */
final class prior_attempts_test extends \advanced_testcase {
    use \local_outcomemap\tests\moodle_compat_trait;

    /**
     * @var int Outcome and policy effective start.
     */
    private const EFFECTIVEFROM = 1704067200;

    /**
     * @var int One day in seconds.
     */
    private const DAY = 86400;

    /**
     * @var \stdClass Reviewer approving governed records.
     */
    private $reviewer;

    /**
     * Build a confirmed course instance with CLO1, CLO2, and PLO1, approved
     * policies, and a quiz holding one essay question worth ten marks.
     *
     * @return \stdClass Fixture identifiers.
     */
    private function fixture(): \stdClass {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->reviewer = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $this->reviewer->id, \context_system::instance()->id);

        $course = $this->getDataGenerator()->create_course(['shortname' => 'PRIOR101']);
        $catalogid = catalog_course_service::create(['code' => 'PRIOR101', 'name' => 'Prior attempts']);
        $cinstid = course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-T1',
        ]);
        course_instance_service::submit_for_review($cinstid);
        $this->setUser($this->reviewer);
        course_instance_service::confirm($cinstid);
        $this->setAdminUser();

        $frameworkid = framework_service::create([
            'code' => 'PRIOR-FW',
            'name' => 'Prior attempt outcomes',
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        $this->setUser($this->reviewer);
        framework_service::approve($frameworkid);
        $this->setAdminUser();
        $outcomes = [];
        foreach (['CLO1', 'CLO2', 'PLO1'] as $code) {
            $itemid = outcome_service::create([
                'frameworkid' => $frameworkid,
                'code' => $code,
                'statement' => 'Outcome ' . $code,
                'effectivefrom' => self::EFFECTIVEFROM,
            ]);
            $itemverid = (int) $DB->get_field('local_outcomemap_itemver', 'id', ['itemid' => $itemid], MUST_EXIST);
            outcome_service::submit_for_review($itemverid);
            $this->setUser($this->reviewer);
            outcome_service::approve($itemverid);
            $this->setAdminUser();
            $outcomes[$code] = (object) ['itemid' => $itemid, 'itemverid' => $itemverid];
        }

        $this->approve_policy([
            'policytype' => policy_service::TYPE_ATTEMPT_SELECTION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Latest completed attempt',
            'config' => ['method' => policy_service::METHOD_LATEST_COMPLETED],
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $this->approve_policy([
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Prior attempt calculation',
            'config' => [
                'minitems' => 1,
                'minweightedpossible' => '1',
                'requiremanualgrading' => true,
                'displayscale' => 1,
            ],
            'bands' => [
                ['code' => 'below', 'name' => 'Below', 'minpercent' => '0', 'maxpercent' => '70'],
                ['code' => 'meets', 'name' => 'Meets', 'minpercent' => '70', 'maxpercent' => '100',
                    'maxinclusive' => 1],
            ],
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category([
            'contextid' => $this->question_bank_contextid($course),
        ]);
        $question = $generator->create_question('essay', 'editor', [
            'category' => $category->id,
            'name' => 'Prior essay',
        ]);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'grade' => 10,
            'sumgrades' => 10,
        ]);
        quiz_add_quiz_question((int) $question->id, $quiz, 0, 10.0);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        return (object) [
            'course' => $course,
            'cinstid' => $cinstid,
            'quiz' => $quiz,
            'cmid' => (int) $cm->id,
            'questionversionid' => (int) $question->versionid,
            'outcomes' => $outcomes,
        ];
    }

    /**
     * Create, submit, and approve a policy.
     *
     * @param array $data Policy data.
     * @return void
     */
    private function approve_policy(array $data): void {
        $this->setAdminUser();
        $id = policy_service::create($data);
        policy_service::submit_for_review($id);
        $this->setUser($this->reviewer);
        policy_service::approve($id);
        $this->setAdminUser();
    }

    /**
     * Map the fixture question wholly to one outcome from a given moment.
     *
     * @param \stdClass $fixture Fixture.
     * @param string $code Outcome code.
     * @param int $from Effective start.
     * @return int Mapping record ID.
     */
    private function map(\stdClass $fixture, string $code, int $from): int {
        $this->setAdminUser();
        $id = question_mapping_service::create([
            'questionversionid' => $fixture->questionversionid,
            'itemverid' => $fixture->outcomes[$code]->itemverid,
            'role' => 'assesses',
            'weight' => '1',
            'effectivefrom' => $from,
        ]);
        question_mapping_service::submit_for_review($id);
        $this->setUser($this->reviewer);
        question_mapping_service::approve($id);
        $this->setAdminUser();
        return $id;
    }

    /**
     * Approve a contributes_to relation from a given moment.
     *
     * @param \stdClass $fixture Fixture.
     * @param string $source Source outcome code.
     * @param string $target Target outcome code.
     * @param int $from Effective start.
     * @return void
     */
    private function relate(\stdClass $fixture, string $source, string $target, int $from): void {
        $this->setAdminUser();
        $id = relation_service::create([
            'sourceitemid' => $fixture->outcomes[$source]->itemid,
            'targetitemid' => $fixture->outcomes[$target]->itemid,
            'type' => relation_service::CONTRIBUTES_TO,
            'weight' => '1',
            'effectivefrom' => $from,
        ]);
        relation_service::submit_for_review($id);
        $this->setUser($this->reviewer);
        relation_service::approve($id);
        $this->setAdminUser();
    }

    /**
     * Finish and manually grade one attempt at 8 out of 10.
     *
     * @param \stdClass $fixture Fixture.
     * @param \stdClass $student Learner.
     * @param int $finish Finish timestamp.
     * @return int Quiz attempt ID.
     */
    private function attempt(\stdClass $fixture, \stdClass $student, int $finish): int {
        $this->getDataGenerator()->enrol_user($student->id, $fixture->course->id, 'student');
        $start = $finish - 600;
        $quizobj = quiz_settings::create($fixture->quiz->id, $student->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($quizobj, 1, false, $start, false, $student->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $start);
        quiz_attempt_save_started($quizobj, $quba, $attempt);
        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($start, false, [
            1 => ['answer' => 'An earlier response', 'answerformat' => FORMAT_HTML],
        ]);
        $this->finish_quiz_attempt($attemptobj, $finish);
        $attemptobj = quiz_attempt::create($attempt->id);
        $usage = $attemptobj->get_question_usage();
        $usage->manual_grade(1, 'Graded', 8.0, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($usage);
        return (int) $attempt->id;
    }

    /**
     * Active evidence rows at one outcome for the fixture assessment.
     *
     * @param \stdClass $fixture Fixture.
     * @param string $code Outcome code.
     * @return \stdClass[]
     */
    private function evidence(\stdClass $fixture, string $code): array {
        global $DB;
        return array_values($DB->get_records_select(
            'local_outcomemap_evidence',
            'assessmentcmid = :cmid AND itemverid = :itemverid AND supersededby IS NULL',
            ['cmid' => $fixture->cmid, 'itemverid' => $fixture->outcomes[$code]->itemverid]
        ));
    }

    /**
     * Current course-scope result for one learner and outcome.
     *
     * @param \stdClass $fixture Fixture.
     * @param \stdClass $student Learner.
     * @param string $code Outcome code.
     * @return \stdClass|null
     */
    private function course_result(\stdClass $fixture, \stdClass $student, string $code): ?\stdClass {
        global $DB;
        $records = $DB->get_records_select(
            'local_outcomemap_result',
            'scopetype = :scope AND scopeid = :scopeid AND userid = :userid AND itemverid = :itemverid
                AND supersededby IS NULL',
            [
                'scope' => calculation_service::SCOPE_COURSE,
                'scopeid' => $fixture->cinstid,
                'userid' => $student->id,
                'itemverid' => $fixture->outcomes[$code]->itemverid,
            ]
        );
        return $records ? reset($records) : null;
    }

    /**
     * Queued catch-up tasks, decoded.
     *
     * @return \stdClass[] Custom data of each queued backfill task.
     */
    private function queued_backfills(): array {
        global $DB;
        return array_map(
            static fn(\stdClass $task): \stdClass => json_decode($task->customdata),
            array_values($DB->get_records('task_adhoc', [
                'classname' => '\\' . backfill_prior_attempts::class,
            ]))
        );
    }

    /**
     * Counting prior attempts is the default, including on an upgraded site
     * where the setting has never been saved.
     */
    public function test_counting_prior_attempts_is_the_default(): void {
        $this->resetAfterTest(true);
        unset_config('countpriorattempts', 'local_outcomemap');
        $this->assertTrue(feature::prior_attempts_count());
        set_config('countpriorattempts', 0, 'local_outcomemap');
        $this->assertFalse(feature::prior_attempts_count());
        set_config('countpriorattempts', 1, 'local_outcomemap');
        $this->assertTrue(feature::prior_attempts_count());
    }

    /**
     * An exam taken before the outcome was mapped counts towards it and
     * aggregates up to the program outcome once the mapping is approved.
     */
    public function test_attempt_before_mapping_counts_and_aggregates(): void {
        $fixture = $this->fixture();
        $now = time();
        $student = $this->getDataGenerator()->create_user();
        $this->relate($fixture, 'CLO1', 'PLO1', self::EFFECTIVEFROM);
        $attemptid = $this->attempt($fixture, $student, $now - 10 * self::DAY);
        // Nothing is mapped yet, so the attempt produces nothing.
        $this->assertSame(0, calculation_service::recalculate_attempt($attemptid)['results']);
        $this->assertSame([], $this->evidence($fixture, 'CLO1'));

        $mappingfrom = $now - self::DAY;
        $mappingid = $this->map($fixture, 'CLO1', $mappingfrom);

        // Approval queues a catch-up for the quiz rather than waiting for the
        // learner to sit it again.
        $queued = $this->queued_backfills();
        $this->assertCount(1, $queued);
        $this->assertSame($fixture->cmid, (int) $queued[0]->cmid);
        $this->expectOutputRegex('/prior-attempt catch-up: 1 recalculated, 0 errors/');
        $this->runAdhocTasks(backfill_prior_attempts::class);

        $direct = $this->evidence($fixture, 'CLO1');
        $this->assertCount(1, $direct);
        $this->assertSame(calculation_service::TYPE_DIRECT, $direct[0]->evidencetype);
        $this->assertSame($mappingid, (int) $direct[0]->mappingid);
        // Provenance keeps the real finish time, which predates the mapping.
        $this->assertSame($now - 10 * self::DAY, (int) $direct[0]->attempttime);
        $this->assertLessThan($mappingfrom, (int) $direct[0]->attempttime);

        $inherited = $this->evidence($fixture, 'PLO1');
        $this->assertCount(1, $inherited);
        $this->assertSame(calculation_service::TYPE_INHERITED, $inherited[0]->evidencetype);
        $this->assertSame((int) $direct[0]->id, (int) $inherited[0]->sourceevidenceid);

        foreach (['CLO1', 'PLO1'] as $code) {
            $result = $this->course_result($fixture, $student, $code);
            $this->assertNotNull($result, $code);
            $this->assertSame(calculation_service::STATE_CALCULATED, $result->state, $code);
            $this->assertSame('80.0000000000', $result->percentage, $code);
        }

        // Catching up again changes nothing.
        $summary = calculation_service::recalculate_attempt($attemptid);
        $this->assertSame(0, $summary['results']);
    }

    /**
     * With the setting off, governance is read only at the attempt's finish time.
     */
    public function test_attempt_before_mapping_ignored_when_disabled(): void {
        $fixture = $this->fixture();
        set_config('countpriorattempts', 0, 'local_outcomemap');
        $now = time();
        $student = $this->getDataGenerator()->create_user();
        $this->relate($fixture, 'CLO1', 'PLO1', self::EFFECTIVEFROM);
        $attemptid = $this->attempt($fixture, $student, $now - 10 * self::DAY);
        $this->map($fixture, 'CLO1', $now - self::DAY);

        $this->assertSame([], $this->queued_backfills());
        calculation_service::recalculate_attempt($attemptid);
        $this->assertSame([], $this->evidence($fixture, 'CLO1'));
        $this->assertSame([], $this->evidence($fixture, 'PLO1'));
        $this->assertNull($this->course_result($fixture, $student, 'CLO1'));
    }

    /**
     * A course-to-program relation approved after the exam still carries its
     * evidence up to the program outcome.
     */
    public function test_relation_added_after_attempt_carries_evidence_up(): void {
        $fixture = $this->fixture();
        $now = time();
        $student = $this->getDataGenerator()->create_user();
        $this->map($fixture, 'CLO1', self::EFFECTIVEFROM);
        $attemptid = $this->attempt($fixture, $student, $now - 10 * self::DAY);
        calculation_service::recalculate_attempt($attemptid);
        $this->assertCount(1, $this->evidence($fixture, 'CLO1'));
        $this->assertSame([], $this->evidence($fixture, 'PLO1'));
        $this->assertNull($this->course_result($fixture, $student, 'PLO1'));

        global $DB;
        $DB->delete_records('task_adhoc', ['classname' => '\\' . backfill_prior_attempts::class]);
        $this->relate($fixture, 'CLO1', 'PLO1', $now - self::DAY);
        $queued = $this->queued_backfills();
        $this->assertCount(1, $queued);
        $this->assertSame($fixture->cmid, (int) $queued[0]->cmid);
        $this->expectOutputRegex('/prior-attempt catch-up: 1 recalculated, 0 errors/');
        $this->runAdhocTasks(backfill_prior_attempts::class);

        $inherited = $this->evidence($fixture, 'PLO1');
        $this->assertCount(1, $inherited);
        $this->assertSame(calculation_service::TYPE_INHERITED, $inherited[0]->evidencetype);
        $result = $this->course_result($fixture, $student, 'PLO1');
        $this->assertNotNull($result);
        $this->assertSame('80.0000000000', $result->percentage);
    }

    /**
     * A later relation does not reach evidence when the setting is off.
     */
    public function test_relation_added_after_attempt_ignored_when_disabled(): void {
        $fixture = $this->fixture();
        set_config('countpriorattempts', 0, 'local_outcomemap');
        $now = time();
        $student = $this->getDataGenerator()->create_user();
        $this->map($fixture, 'CLO1', self::EFFECTIVEFROM);
        $attemptid = $this->attempt($fixture, $student, $now - 10 * self::DAY);
        $this->relate($fixture, 'CLO1', 'PLO1', $now - self::DAY);
        calculation_service::recalculate_attempt($attemptid);
        $this->assertCount(1, $this->evidence($fixture, 'CLO1'));
        $this->assertSame([], $this->evidence($fixture, 'PLO1'));
    }

    /**
     * A mapping in force when the attempt finished keeps governing it; the
     * later replacement set governs only later attempts. One observation is
     * never attributed to both sets.
     */
    public function test_mapping_in_force_at_attempt_still_governs(): void {
        $fixture = $this->fixture();
        $now = time();
        $early = $this->getDataGenerator()->create_user();
        $late = $this->getDataGenerator()->create_user();
        $first = $this->map($fixture, 'CLO1', self::EFFECTIVEFROM);
        $this->setAdminUser();
        question_mapping_service::end_mappings([$first], $now - 5 * self::DAY, 'Remapped to CLO2.');
        $this->map($fixture, 'CLO2', $now - 5 * self::DAY);

        calculation_service::recalculate_attempt($this->attempt($fixture, $early, $now - 10 * self::DAY));
        calculation_service::recalculate_attempt($this->attempt($fixture, $late, $now - 2 * self::DAY));

        $clo1 = $this->evidence($fixture, 'CLO1');
        $clo2 = $this->evidence($fixture, 'CLO2');
        $this->assertCount(1, $clo1);
        $this->assertCount(1, $clo2);
        $this->assertSame((int) $early->id, (int) $clo1[0]->userid);
        $this->assertSame((int) $late->id, (int) $clo2[0]->userid);
    }

    /**
     * Scheduled reconciliation picks up a learner whose only attempt
     * predates the mapping, and only while prior attempts count.
     */
    public function test_reconciliation_finds_prior_attempts(): void {
        global $DB;
        $fixture = $this->fixture();
        $now = time();
        $student = $this->getDataGenerator()->create_user();
        $this->attempt($fixture, $student, $now - 10 * self::DAY);
        $this->map($fixture, 'CLO1', $now - self::DAY);
        $DB->delete_records('task_adhoc', ['classname' => '\\' . backfill_prior_attempts::class]);

        set_config('countpriorattempts', 0, 'local_outcomemap');
        ob_start();
        (new \local_outcomemap\task\reconcile_evidence())->execute();
        ob_end_clean();
        $this->assertSame([], $this->evidence($fixture, 'CLO1'));

        set_config('countpriorattempts', 1, 'local_outcomemap');
        ob_start();
        (new \local_outcomemap\task\reconcile_evidence())->execute();
        ob_end_clean();
        $this->assertCount(1, $this->evidence($fixture, 'CLO1'));
    }

    /**
     * The catch-up walk is ordered and resumes strictly after its cursor.
     */
    public function test_backfill_walk_resumes_after_cursor(): void {
        $fixture = $this->fixture();
        $now = time();
        $students = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        foreach ($students as $student) {
            $this->attempt($fixture, $student, $now - 10 * self::DAY);
        }
        $this->map($fixture, 'CLO1', $now - self::DAY);

        $all = backfill_prior_attempts::next_tuples(null, null, 10);
        $this->assertCount(2, $all);
        $this->assertLessThan((int) $all[1]->userid, (int) $all[0]->userid);
        $rest = backfill_prior_attempts::next_tuples(null, [
            (int) $all[0]->courseid,
            (int) $all[0]->cmid,
            (int) $all[0]->userid,
        ], 10);
        $this->assertCount(1, $rest);
        $this->assertSame((int) $all[1]->userid, (int) $rest[0]->userid);
        $this->assertSame([], backfill_prior_attempts::next_tuples($fixture->cmid + 1000, null, 10));
    }
}
