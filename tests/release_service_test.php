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

use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\release_service;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Tests for learner-specific feedback-release decisions.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class release_service_test extends \advanced_testcase {
    use \local_outcomemap\tests\moodle_compat_trait;

    /**
     * Build a resolved release-policy record.
     *
     * @param string $mode Release mode.
     * @param array $config Additional normalized configuration.
     * @return \stdClass
     */
    private function policy(string $mode, array $config = []): \stdClass {
        return (object) [
            'config' => ['mode' => $mode] + $config,
            'effectivefrom' => 100,
            'approvedat' => 200,
        ];
    }

    /**
     * * Tests scheduled and governed manual release timestamps.
     */
    public function test_scheduled_and_manual_release_respect_time_and_access(): void {
        $scheduled = $this->policy(policy_service::RELEASE_SCHEDULED, ['releaseat' => 500]);
        $scope = ['accessible' => true];

        $before = release_service::evaluate($scheduled, $scope, 499);
        $this->assertFalse($before->released);
        $this->assertNull($before->releasedat);

        $released = release_service::evaluate($scheduled, $scope, 500);
        $this->assertTrue($released->released);
        $this->assertSame(500, $released->releasedat);

        $manualpolicy = $this->policy(policy_service::RELEASE_MANUAL);
        $this->assertFalse(release_service::evaluate($manualpolicy, $scope, 500)->released);
        $manual = release_service::evaluate($manualpolicy, $scope + ['manualreleaseat' => 250], 250);
        $this->assertTrue($manual->released);
        $this->assertSame(250, $manual->releasedat);

        $inaccessible = release_service::evaluate($scheduled, ['accessible' => false], 600);
        $this->assertFalse($inaccessible->released);
        $this->assertNull($inaccessible->releasedat);
        $this->assertFalse(release_service::evaluate($scheduled, [
            'accessible' => true,
            'lineagecomplete' => false,
        ], 600)->released);
        $this->assertFalse(release_service::evaluate(null, $scope, 600)->released);
    }

    /**
     * * Tests learner-specific grade visibility and quiz-close decisions.
     */
    public function test_grade_visible_and_quiz_closed_require_every_assessment(): void {
        $scope = [
            'accessible' => true,
            'assessmentcmids' => [11, 12, 11],
            'gradevisible' => [11 => true, 12 => true],
            'quizclosetimes' => [11 => 300, 12 => 400],
        ];

        $visible = release_service::evaluate(
            $this->policy(policy_service::RELEASE_GRADE_VISIBLE),
            $scope,
            450
        );
        $this->assertTrue($visible->released);
        $this->assertSame(450, $visible->releasedat);

        $scope['gradevisible'][12] = false;
        $this->assertFalse(release_service::evaluate(
            $this->policy(policy_service::RELEASE_GRADE_VISIBLE),
            $scope,
            450
        )->released);

        $closed = release_service::evaluate(
            $this->policy(policy_service::RELEASE_QUIZ_CLOSED),
            $scope,
            450
        );
        $this->assertTrue($closed->released);
        $this->assertSame(400, $closed->releasedat);

        $this->assertFalse(release_service::evaluate(
            $this->policy(policy_service::RELEASE_QUIZ_CLOSED),
            $scope,
            399
        )->released);
        $scope['quizclosetimes'][12] = 0;
        $this->assertFalse(release_service::evaluate(
            $this->policy(policy_service::RELEASE_QUIZ_CLOSED),
            $scope,
            450
        )->released);
    }

    /**
     * * Tests fully-graded release with real unfinished and manually graded essay usages.
     */
    public function test_fully_graded_requires_finished_loadable_graded_usages(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'grade' => 100,
            'sumgrades' => 10,
        ]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => $this->question_bank_contextid($course),
        ]);
        $question = $questiongenerator->create_question('essay', 'editor', [
            'category' => $category->id,
            'name' => 'Manual release grading check',
        ]);
        quiz_add_quiz_question((int) $question->id, $quiz, 0, 10.0);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $createattempt = function (int $attemptnumber, bool $grade) use ($DB, $quiz, $student): \stdClass {
            $timenow = time() + ($attemptnumber * 100);
            $quizobj = quiz_settings::create($quiz->id, $student->id);
            $usage = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
            $usage->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
            $attempt = quiz_create_attempt($quizobj, $attemptnumber, false, $timenow, false, $student->id);
            quiz_start_new_attempt($quizobj, $usage, $attempt, $attemptnumber, $timenow);
            quiz_attempt_save_started($quizobj, $usage, $attempt);
            $attemptobj = quiz_attempt::create($attempt->id);
            $attemptobj->process_submitted_actions($timenow, false, [
                1 => ['answer' => 'Essay response', 'answerformat' => FORMAT_HTML],
            ]);
            $this->finish_quiz_attempt($attemptobj, $timenow + 30);
            if ($grade) {
                $attemptobj = quiz_attempt::create($attempt->id);
                $usage = $attemptobj->get_question_usage();
                $usage->manual_grade(1, 'Graded', 7.0, FORMAT_HTML);
                \question_engine::save_questions_usage_by_activity($usage);
            }
            return $DB->get_record('quiz_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
        };

        $policy = $this->policy(policy_service::RELEASE_FULLY_GRADED);
        $needsgrading = $createattempt(1, false);
        $this->assertFalse(release_service::evaluate($policy, [
            'accessible' => true,
            'attempts' => [$needsgrading],
        ], 600)->released);

        $graded = $createattempt(2, true);
        $released = release_service::evaluate($policy, [
            'accessible' => true,
            'attempts' => [$graded],
        ], 600);
        $this->assertTrue($released->released);
        $this->assertSame(600, $released->releasedat);

        $graded->state = 'inprogress';
        $this->assertFalse(release_service::evaluate($policy, [
            'accessible' => true,
            'attempts' => [$graded],
        ], 600)->released);
        $this->assertFalse(release_service::evaluate($policy, [
            'accessible' => true,
            'attempts' => [],
        ], 600)->released);
        $this->assertFalse(release_service::evaluate($policy, [
            'accessible' => true,
            'attempts' => [(object) ['state' => 'finished', 'uniqueid' => 987654321]],
        ], 600)->released);
    }
}
