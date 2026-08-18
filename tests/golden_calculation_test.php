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

use local_outcomemap\local\decimal;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\question_mapping_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\local\workflow;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Golden MBA614-like calculation fixtures.
 *
 * The expected numerators, denominators, percentages, and bands live in this
 * fixture so the tests prove the calculation itself, not merely a display.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_outcomemap\local\service\calculation_service
 */
final class golden_calculation_test extends \advanced_testcase {
    use \local_outcomemap\tests\moodle_compat_trait;

    /**
     * @var int Governance effective start.
     */
    private const EFFECTIVEFROM = 1704067200;

    /**
     * @var \stdClass Reviewer approving governed records.
     */
    private $reviewer;

    /**
     * Creates a system manager reviewer.
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
     * Creates an approved outcome and returns its item and version IDs.
     *
     * @param int $frameworkid Approved framework ID.
     * @param string $code Outcome code.
     * @return array{0:int,1:int} Item ID and outcome-version ID.
     */
    private function create_outcome(int $frameworkid, string $code): array {
        global $DB;
        $this->setAdminUser();
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
        return [$itemid, $itemverid];
    }

    /**
     * Creates an approved contributes_to relation.
     *
     * @param int $sourceitemid Source item ID.
     * @param int $targetitemid Target item ID.
     * @param string|null $weight Optional contribution weight.
     * @return int Relation record ID.
     */
    private function create_relation(int $sourceitemid, int $targetitemid, ?string $weight): int {
        $this->setAdminUser();
        $id = relation_service::create([
            'sourceitemid' => $sourceitemid,
            'targetitemid' => $targetitemid,
            'type' => relation_service::CONTRIBUTES_TO,
            'weight' => $weight,
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        relation_service::submit_for_review($id);
        $this->setUser($this->reviewer);
        relation_service::approve($id);
        $this->setAdminUser();
        return $id;
    }

    /**
     * Maps a question version to outcomes with approved assessed weights.
     *
     * @param int $questionversionid Question-version ID.
     * @param array $weights Weight strings keyed by outcome-version ID.
     * @return void
     */
    private function map_question(int $questionversionid, array $weights): void {
        $this->setAdminUser();
        $ids = [];
        foreach ($weights as $itemverid => $weight) {
            $ids[] = question_mapping_service::create([
                'questionversionid' => $questionversionid,
                'itemverid' => $itemverid,
                'role' => 'assesses',
                'weight' => $weight,
                'effectivefrom' => self::EFFECTIVEFROM,
            ]);
        }
        foreach ($ids as $id) {
            question_mapping_service::submit_for_review($id);
        }
        $this->setUser($this->reviewer);
        question_mapping_service::approve(reset($ids));
        $this->setAdminUser();
    }

    /**
     * Creates an approved policy.
     *
     * @param array $data Policy data.
     * @return int Policy record ID.
     */
    private function create_policy(array $data): int {
        $this->setAdminUser();
        $id = policy_service::create($data);
        policy_service::submit_for_review($id);
        $this->setUser($this->reviewer);
        policy_service::approve($id);
        $this->setAdminUser();
        return $id;
    }

    /**
     * Loads the current assessment-scope result for one outcome version.
     *
     * @param int $cmid Quiz course-module ID.
     * @param int $userid Student ID.
     * @param int $itemverid Outcome-version ID.
     * @return \stdClass|null Current result row.
     */
    private function assessment_result(int $cmid, int $userid, int $itemverid): ?\stdClass {
        global $DB;
        $records = $DB->get_records_select(
            'local_outcomemap_result',
            'scopetype = :scope AND scopeid = :scopeid AND userid = :userid AND itemverid = :itemverid
                AND supersededby IS NULL',
            ['scope' => 'assessment', 'scopeid' => $cmid, 'userid' => $userid, 'itemverid' => $itemverid]
        );
        return $records ? reset($records) : null;
    }

    /**
     * The golden fixture: six CLOs, weighted multi-outcome question,
     * cross-outcome propagation with path dedupe, sufficiency, pending
     * grading, banding on unrounded percentages, idempotency, and regrade.
     */
    public function test_golden_mba614_fixture(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->reviewer = $this->create_reviewer();

        // Governed foundation: catalog course, confirmed instance, outcomes.
        $course = $this->getDataGenerator()->create_course(['shortname' => 'MBA614G']);
        $catalogid = catalog_course_service::create(['code' => 'MBA614G', 'name' => 'Strategic Leadership']);
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
            'code' => 'MBA614G-FW',
            'name' => 'Golden outcomes',
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        $this->setUser($this->reviewer);
        framework_service::approve($frameworkid);
        $this->setAdminUser();

        $outcomes = [];
        foreach (['CLO1', 'CLO2', 'CLO3', 'CLO4', 'CLO5', 'CLO6', 'CLOINS', 'MID', 'PLOA', 'PLOB'] as $code) {
            $outcomes[$code] = $this->create_outcome($frameworkid, $code);
        }

        // Propagation graph: CLO1 reaches PLOA directly (weight 1) and through
        // MID (cumulative 0.5); only the strongest path may count.
        $this->create_relation($outcomes['CLO1'][0], $outcomes['PLOA'][0], '1');
        $this->create_relation($outcomes['CLO1'][0], $outcomes['MID'][0], '1');
        $this->create_relation($outcomes['MID'][0], $outcomes['PLOA'][0], '0.5');
        $this->create_relation($outcomes['CLO1'][0], $outcomes['PLOB'][0], '1');

        // Governed policies: no defaults are seeded, so calculation only
        // happens once both approved policies resolve.
        $this->create_policy([
            'policytype' => policy_service::TYPE_ATTEMPT_SELECTION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Latest completed attempt',
            'config' => ['method' => policy_service::METHOD_LATEST_COMPLETED],
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $calculationpolicyid = $this->create_policy([
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Golden calculation',
            'config' => [
                'minitems' => 1,
                'minweightedpossible' => '2',
                'requiremanualgrading' => true,
                'displayscale' => 1,
            ],
            'bands' => [
                ['code' => 'below', 'name' => 'Below expectations', 'minpercent' => '0', 'maxpercent' => '70'],
                ['code' => 'meets', 'name' => 'Meets expectations', 'minpercent' => '70', 'maxpercent' => '90'],
                ['code' => 'exceeds', 'name' => 'Exceeds expectations', 'minpercent' => '90',
                    'maxpercent' => '100', 'maxinclusive' => 1],
            ],
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $belowband = policy_service::get_bands($calculationpolicyid)[0];

        // The golden learner fixture stores the expected CLO4 recommendations,
        // not merely strings later asserted from rendered output.
        $expectedrecommendations = [
            ['title' => 'Unit 2.3', 'purpose' => 'review', 'required' => true, 'sortorder' => 0],
            ['title' => 'Unit 2.5', 'purpose' => 'review', 'required' => false, 'sortorder' => 1],
            ['title' => 'Unit 4.1', 'purpose' => 'review', 'required' => false, 'sortorder' => 2],
            ['title' => 'Unit 4.4', 'purpose' => 'review', 'required' => false, 'sortorder' => 3],
        ];
        $remediationids = [];
        foreach ($expectedrecommendations as $expectedrecommendation) {
            $page = $this->getDataGenerator()->create_module('page', [
                'course' => $course->id,
                'name' => $expectedrecommendation['title'],
            ]);
            $pagecm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
            $remediationids[] = \local_outcomemap\local\service\remediation_service::create([
                'cinstid' => $cinstid,
                'itemverid' => $outcomes['CLO4'][1],
                'bandid' => $belowband->id,
                'targettype' => \local_outcomemap\local\service\remediation_service::TARGET_MODULE,
                'targetid' => $pagecm->id,
                'purpose' => \local_outcomemap\local\service\remediation_service::PURPOSE_REVIEW,
                'title' => $expectedrecommendation['title'],
                'priority' => 10,
                'sortorder' => $expectedrecommendation['sortorder'],
                'required' => $expectedrecommendation['required'],
                'minpercent' => '0',
                'maxpercent' => '69.9999999999',
                'effectivefrom' => self::EFFECTIVEFROM,
            ]);
        }
        foreach ($remediationids as $remediationid) {
            \local_outcomemap\local\service\remediation_service::submit_for_review($remediationid);
        }
        $this->setUser($this->reviewer);
        foreach ($remediationids as $remediationid) {
            \local_outcomemap\local\service\remediation_service::approve($remediationid);
        }
        $this->setAdminUser();

        // Questions: exact marks come from manual grading of essay questions.
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category([
            'contextid' => $this->question_bank_contextid($course),
        ]);
        $plan = [
            // Question key => [maxmark, mark, mappings [code => weight]].
            'Q1' => ['10', '9', ['CLO1' => '1']],
            'Q2' => ['10', '8', ['CLO1' => '1']],
            'Q3' => ['4', '3', ['CLO2' => '1']],
            'Q4' => ['3', '2.7', ['CLO2' => '1']],
            'Q5' => ['10', '9', ['CLO3' => '1']],
            'Q6' => ['10', '5', ['CLO4' => '1']],
            'Q7' => ['10', '10', ['CLO5' => '0.6', 'CLO6' => '0.4']],
            'Q8' => ['1', '1', ['CLOINS' => '1']],
        ];
        $questions = [];
        foreach ($plan as $key => [$maxmark, $mark, $weights]) {
            $question = $generator->create_question('essay', 'editor', [
                'category' => $category->id,
                'name' => 'Golden ' . $key,
            ]);
            $questions[$key] = $question;
            $this->map_question((int) $question->versionid, array_combine(
                array_map(static fn(string $code): int => $outcomes[$code][1], array_keys($weights)),
                array_values($weights)
            ));
        }

        // Quiz with the mapped questions at their fixture maxmarks.
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'grade' => 100,
            'sumgrades' => 48,
        ]);
        $slotbykey = [];
        $slot = 1;
        foreach ($plan as $key => [$maxmark]) {
            quiz_add_quiz_question((int) $questions[$key]->id, $quiz, 0, (float) $maxmark);
            $slotbykey[$key] = $slot++;
        }
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // One completed attempt with responses to every essay.
        $timenow = time();
        $quizobj = quiz_settings::create($quiz->id, $student->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $student->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);
        $attemptobj = quiz_attempt::create($attempt->id);
        $responses = [];
        foreach ($slotbykey as $key => $slotnumber) {
            $responses[$slotnumber] = ['answer' => 'Response for ' . $key, 'answerformat' => FORMAT_HTML];
        }
        $attemptobj->process_submitted_actions($timenow, false, $responses);
        $this->finish_quiz_attempt($attemptobj, $timenow + 60);

        // Before manual grading every result must be calculation_pending.
        $summary = calculation_service::recalculate_attempt((int) $attempt->id);
        $this->assertGreaterThan(0, $summary['results']);
        $pendingresult = $this->assessment_result((int) $cm->id, (int) $student->id, $outcomes['CLO1'][1]);
        $this->assertSame(calculation_service::STATE_PENDING, $pendingresult->state);
        $this->assertNull($pendingresult->percentage);

        // Manual grading with the exact fixture marks.
        $attemptobj = quiz_attempt::create($attempt->id);
        $usage = $attemptobj->get_question_usage();
        foreach ($plan as $key => [$maxmark, $mark]) {
            $usage->manual_grade($slotbykey[$key], 'Graded ' . $key, (float) $mark, FORMAT_HTML);
        }
        \question_engine::save_questions_usage_by_activity($usage);

        $summary = calculation_service::recalculate_attempt((int) $attempt->id);
        $this->assertGreaterThan(0, $summary['results']);

        $expected = [
            'CLO1' => ['17.0000000000', '20.0000000000', '85.0000000000', 'meets', 2],
            'CLO2' => ['5.7000000000', '7.0000000000', '81.4285714286', 'meets', 2],
            'CLO3' => ['9.0000000000', '10.0000000000', '90.0000000000', 'exceeds', 1],
            'CLO4' => ['5.0000000000', '10.0000000000', '50.0000000000', 'below', 1],
            'CLO5' => ['6.0000000000', '6.0000000000', '100.0000000000', 'exceeds', 1],
            'CLO6' => ['4.0000000000', '4.0000000000', '100.0000000000', 'exceeds', 1],
            'PLOA' => ['17.0000000000', '20.0000000000', '85.0000000000', 'meets', 2],
            'PLOB' => ['17.0000000000', '20.0000000000', '85.0000000000', 'meets', 2],
        ];
        foreach ($expected as $code => [$numerator, $denominator, $percentage, $bandcode, $items]) {
            $result = $this->assessment_result((int) $cm->id, (int) $student->id, $outcomes[$code][1]);
            $this->assertNotNull($result, $code . ' result missing');
            $this->assertSame(calculation_service::STATE_CALCULATED, $result->state, $code);
            $this->assertSame($numerator, $result->numerator, $code . ' numerator');
            $this->assertSame($denominator, $result->denominator, $code . ' denominator');
            $this->assertSame($percentage, $result->percentage, $code . ' percentage');
            $this->assertSame($items, (int) $result->distinctitems, $code . ' items');
            $band = $DB->get_record('local_outcomemap_band', ['id' => $result->bandid], '*', MUST_EXIST);
            $this->assertSame($bandcode, $band->code, $code . ' band');
            $this->assertSame(calculation_service::ALGO_VERSION, $result->algoversion);
            $this->assertSame(hash('sha256', $result->lineagejson), $result->lineagehash, $code . ' lineage hash');
        }
        // Display rounding never changes the authoritative band or value.
        $clo2 = $this->assessment_result((int) $cm->id, (int) $student->id, $outcomes['CLO2'][1]);
        $this->assertSame('81.4000000000', decimal::quantize($clo2->percentage, 1));

        // CLO4's exact below band selects all four approved and accessible
        // review targets in the fixture's governed display order.
        $clo4 = $this->assessment_result((int) $cm->id, (int) $student->id, $outcomes['CLO4'][1]);
        $this->setUser($student);
        $modinfo = get_fast_modinfo($course->id, $student->id);
        $selector = new \ReflectionMethod(
            \local_outcomemap\local\service\student_result_service::class,
            'select_accessible_remediation'
        );
        $selectedrecommendations = $selector->invoke(
            null,
            $course->id,
            ['CLO4' => [
                'cinstid' => $cinstid,
                'itemverid' => $outcomes['CLO4'][1],
                'bandid' => $clo4->bandid,
                'percentage' => $clo4->percentage,
            ]],
            time(),
            $modinfo,
            $modinfo->get_cms()
        )['CLO4'];
        $this->setAdminUser();
        $actualrecommendations = array_map(static function (array $recommendation): array {
            return [
                'title' => $recommendation['title'],
                'purpose' => $recommendation['purpose'],
                'required' => $recommendation['required'],
                'sortorder' => $recommendation['sortorder'],
            ];
        }, $selectedrecommendations);
        $this->assertSame($expectedrecommendations, $actualrecommendations);
        foreach ($selectedrecommendations as $recommendation) {
            $this->assertNotSame('', $recommendation['url']);
            $this->assertArrayNotHasKey('targetid', $recommendation);
        }

        // Too little weighted evidence is insufficient, never a failure.
        $insufficient = $this->assessment_result((int) $cm->id, (int) $student->id, $outcomes['CLOINS'][1]);
        $this->assertSame(calculation_service::STATE_INSUFFICIENT, $insufficient->state);
        $this->assertNull($insufficient->percentage);
        $this->assertNull($insufficient->bandid);

        // Multi-path propagation counted each lineage exactly once at PLOA.
        $ploaevidence = $DB->get_records_select(
            'local_outcomemap_evidence',
            'itemverid = :itemverid AND supersededby IS NULL',
            ['itemverid' => $outcomes['PLOA'][1]]
        );
        $this->assertCount(2, $ploaevidence);
        foreach ($ploaevidence as $row) {
            $this->assertSame(calculation_service::TYPE_INHERITED, $row->evidencetype);
            $this->assertSame('1.0000000000', $row->relationweight);
        }

        // Repeat calculation from identical inputs is a pure no-op.
        $clo1before = $this->assessment_result((int) $cm->id, (int) $student->id, $outcomes['CLO1'][1]);
        $summary = calculation_service::recalculate_attempt((int) $attempt->id);
        $this->assertSame(0, $summary['results']);
        $clo1after = $this->assessment_result((int) $cm->id, (int) $student->id, $outcomes['CLO1'][1]);
        $this->assertSame($clo1before->id, $clo1after->id);
        $this->assertSame($clo1before->inputhash, $clo1after->inputhash);

        // A regrade supersedes evidence and result versions without deleting
        // history: Q1 goes from 9 to 10, so CLO1 becomes 18/20 = 90.0.
        $attemptobj = quiz_attempt::create($attempt->id);
        $usage = $attemptobj->get_question_usage();
        $usage->manual_grade($slotbykey['Q1'], 'Regraded Q1', 10.0, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($usage);
        $summary = calculation_service::recalculate_attempt((int) $attempt->id);
        $this->assertGreaterThan(0, $summary['results']);

        $regraded = $this->assessment_result((int) $cm->id, (int) $student->id, $outcomes['CLO1'][1]);
        $this->assertSame('18.0000000000', $regraded->numerator);
        $this->assertSame('90.0000000000', $regraded->percentage);
        $this->assertSame((int) $clo1before->version + 1, (int) $regraded->version);
        $band = $DB->get_record('local_outcomemap_band', ['id' => $regraded->bandid], '*', MUST_EXIST);
        $this->assertSame('exceeds', $band->code);
        $old = $DB->get_record('local_outcomemap_result', ['id' => $clo1before->id], '*', MUST_EXIST);
        $this->assertSame(calculation_service::STATE_SUPERSEDED, $old->state);
        $this->assertSame((int) $regraded->id, (int) $old->supersededby);
        $this->assertSame('85.0000000000', $old->percentage);
        $this->assertTrue($DB->record_exists_select(
            'local_outcomemap_evidence',
            'supersededby IS NOT NULL AND itemverid = :itemverid',
            ['itemverid' => $outcomes['CLO1'][1]]
        ));
        // The propagated PLO results follow the regrade through the lineage.
        $ploa = $this->assessment_result((int) $cm->id, (int) $student->id, $outcomes['PLOA'][1]);
        $this->assertSame('90.0000000000', $ploa->percentage);
    }

    /**
     * * Tests governed policy resolution precedence and the unconfigured guard.
     */
    public function test_policy_scope_precedence(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->reviewer = $this->create_reviewer();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $catalogid = catalog_course_service::create(['code' => 'POLPREC', 'name' => 'Policy precedence']);
        $cinstid = course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-T1',
        ]);
        course_instance_service::submit_for_review($cinstid);
        $this->setUser($this->reviewer);
        course_instance_service::confirm($cinstid);
        $this->setAdminUser();

        // No approved policy resolves: official calculation must not proceed.
        $this->assertNull(policy_service::resolve(
            policy_service::TYPE_ATTEMPT_SELECTION,
            $cinstid,
            (int) $cm->id
        ));

        $institution = $this->create_policy([
            'policytype' => policy_service::TYPE_ATTEMPT_SELECTION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Institution latest',
            'config' => ['method' => policy_service::METHOD_LATEST_COMPLETED],
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $resolved = policy_service::resolve(policy_service::TYPE_ATTEMPT_SELECTION, $cinstid, (int) $cm->id);
        $this->assertSame($institution, (int) $resolved->id);

        $assessment = $this->create_policy([
            'policytype' => policy_service::TYPE_ATTEMPT_SELECTION,
            'scopetype' => policy_service::SCOPE_ASSESSMENT,
            'scopeid' => (int) $cm->id,
            'name' => 'Final exam highest',
            'config' => ['method' => policy_service::METHOD_HIGHEST_GRADED],
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $resolved = policy_service::resolve(policy_service::TYPE_ATTEMPT_SELECTION, $cinstid, (int) $cm->id);
        $this->assertSame($assessment, (int) $resolved->id);
        $this->assertSame(policy_service::METHOD_HIGHEST_GRADED, $resolved->config['method']);

        // Without the assessment scope the institution policy still governs.
        $resolved = policy_service::resolve(policy_service::TYPE_ATTEMPT_SELECTION, $cinstid, null);
        $this->assertSame($institution, (int) $resolved->id);
    }
}
