<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use local_outcomemap\local\canonical_json;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\service\question_mapping_service;
use local_outcomemap\local\service\remediation_engagement_service;
use local_outcomemap\local\service\remediation_service;
use local_outcomemap\local\service\student_result_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Tests for the learner-safe outcome-result report.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class student_result_service_test extends \advanced_testcase {
    use \local_outcomemap\tests\moodle_compat_trait;

    /** Governance effective start. */
    private const EFFECTIVEFROM = 1704067200;

    /** Governed scheduled release time. */
    private const RELEASEAT = 1800000000;

    /** @var \stdClass Independent reviewer. */
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
     * Creates and independently approves a policy.
     *
     * @param array $data Policy data.
     * @return int Policy ID.
     */
    private function approve_policy(array $data): int {
        $this->setAdminUser();
        $id = policy_service::create($data);
        policy_service::submit_for_review($id);
        $this->setUser($this->reviewer);
        policy_service::approve($id);
        $this->setAdminUser();
        return $id;
    }

    /**
     * Creates a recommendation and optionally approves it.
     *
     * @param array $data Recommendation data.
     * @param bool $approve Whether to approve the draft.
     * @return int Recommendation ID.
     */
    private function create_remediation(array $data, bool $approve = true): int {
        $this->setAdminUser();
        $id = remediation_service::create($data);
        if ($approve) {
            remediation_service::submit_for_review($id);
            $this->setUser($this->reviewer);
            remediation_service::approve($id);
            $this->setAdminUser();
        }
        return $id;
    }

    /**
     * Tests real quiz calculation, aggregate release, historical versions, access filtering, and DTO safety.
     */
    public function test_report_releases_only_safe_current_clo_data_and_accessible_remediation(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->reviewer = $this->create_reviewer();

        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'M5RESULTS',
            'numsections' => 1,
        ]);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'CLO4 final quiz',
            'grade' => 100,
            'sumgrades' => 10,
            'reviewattempt' => 0,
            'reviewcorrectness' => 0,
            'reviewmarks' => 0,
            'reviewspecificfeedback' => 0,
            'reviewgeneralfeedback' => 0,
            'reviewrightanswer' => 0,
            'reviewoverallfeedback' => 0,
        ]);
        $quizcm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $visiblepage = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'section' => 1,
            'name' => 'Unit 2.3',
        ]);
        $hiddenpage = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'section' => 1,
            'name' => 'Restricted Unit 4.4',
            'visible' => 0,
        ]);
        $visiblecm = get_coursemodule_from_instance('page', $visiblepage->id, $course->id, false, MUST_EXIST);
        $hiddencm = get_coursemodule_from_instance('page', $hiddenpage->id, $course->id, false, MUST_EXIST);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $catalogid = catalog_course_service::create([
            'code' => 'M5RESULTS',
            'name' => 'M5 learner results',
        ]);
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
            'code' => 'M5-CLO',
            'name' => 'M5 course outcomes',
            'ownertype' => framework_service::OWNER_COURSE,
            'ownerid' => $catalogid,
        ]);
        framework_service::submit_for_review($frameworkid);
        $this->setUser($this->reviewer);
        framework_service::approve($frameworkid);
        $this->setAdminUser();
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => 'CLO4',
            'statement' => 'Integrate evidence into an ethical strategic recommendation.',
            'shortstatement' => 'Integrate evidence into a recommendation.',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $itemverid = (int) $DB->get_field('local_outcomemap_itemver', 'id', [
            'itemid' => $itemid,
        ], MUST_EXIST);
        outcome_service::submit_for_review($itemverid);
        $this->setUser($this->reviewer);
        outcome_service::approve($itemverid);
        $this->setAdminUser();

        $this->approve_policy([
            'policytype' => policy_service::TYPE_ATTEMPT_SELECTION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'M5 latest completed attempt',
            'config' => ['method' => policy_service::METHOD_LATEST_COMPLETED],
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $calculationpolicyid = $this->approve_policy([
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'M5 calculation and feedback bands',
            'config' => [
                'minitems' => 1,
                'requiremanualgrading' => true,
                'displayscale' => 1,
            ],
            'bands' => [
                [
                    'code' => 'below',
                    'name' => 'Below expectations',
                    'description' => 'Review the curated items before reassessment.',
                    'minpercent' => '0',
                    'maxpercent' => '70',
                ],
                [
                    'code' => 'meets',
                    'name' => 'Meets expectations',
                    'minpercent' => '70',
                    'maxpercent' => '100',
                    'maxinclusive' => true,
                ],
            ],
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        $belowband = policy_service::get_bands($calculationpolicyid)[0];
        $releasepolicyid = $this->approve_policy([
            'policytype' => policy_service::TYPE_RELEASE,
            'scopetype' => policy_service::SCOPE_ASSESSMENT,
            'scopeid' => $quizcm->id,
            'name' => 'M5 scheduled learner release',
            'config' => [
                'mode' => policy_service::RELEASE_SCHEDULED,
                'releaseat' => self::RELEASEAT,
            ],
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);

        $base = [
            'cinstid' => $cinstid,
            'itemverid' => $itemverid,
            'bandid' => $belowband->id,
            'purpose' => remediation_service::PURPOSE_REVIEW,
            'minpercent' => '0',
            'maxpercent' => '69.9999999999',
            'effectivefrom' => self::EFFECTIVEFROM,
        ];
        $visibleremediationid = $this->create_remediation($base + [
            'targettype' => remediation_service::TARGET_MODULE,
            'targetid' => $visiblecm->id,
            'title' => 'Unit 2.3',
            'explanation' => 'Review the evidence integration worked example.',
            'required' => 1,
            'priority' => 10,
            'sortorder' => 1,
        ]);
        $hiddenremediationid = $this->create_remediation($base + [
            'targettype' => remediation_service::TARGET_MODULE,
            'targetid' => $hiddencm->id,
            'title' => 'Restricted Unit 4.4',
            'priority' => 20,
            'sortorder' => 0,
        ]);
        $this->create_remediation($base + [
            'targettype' => remediation_service::TARGET_EXTERNAL,
            'externalurl' => 'https://example.test/unapproved',
            'title' => 'Unapproved review item',
            'priority' => 30,
            'sortorder' => 0,
        ], false);

        // Create a real essay question, exact approved mapping, completed
        // learner attempt, and manual grade so report lineage comes entirely
        // from the production calculation path.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => $this->question_bank_contextid($course),
        ]);
        $question = $questiongenerator->create_question('essay', 'editor', [
            'category' => $category->id,
            'name' => 'M5_PROTECTED_QUESTION_7f1',
            'questiontext' => 'M5_PROTECTED_QUESTION_7f1',
            'generalfeedback' => 'M5_PROTECTED_ANSWER_KEY_7f1',
        ]);
        $mappingid = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $itemverid,
            'role' => 'assesses',
            'weight' => '1',
            'effectivefrom' => self::EFFECTIVEFROM,
        ]);
        question_mapping_service::submit_for_review($mappingid);
        $this->setUser($this->reviewer);
        question_mapping_service::approve($mappingid);
        $this->setAdminUser();
        quiz_add_quiz_question((int) $question->id, $quiz, 0, 10.0);

        $timenow = time();
        $quizobj = quiz_settings::create($quiz->id, $student->id);
        $usage = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $usage->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $student->id);
        quiz_start_new_attempt($quizobj, $usage, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $usage, $attempt);
        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($timenow, false, [
            1 => [
                'answer' => 'M5_PROTECTED_RESPONSE_7f1',
                'answerformat' => FORMAT_HTML,
            ],
        ]);
        $this->finish_quiz_attempt($attemptobj, $timenow + 60);
        $attemptobj = quiz_attempt::create($attempt->id);
        $usage = $attemptobj->get_question_usage();
        $usage->manual_grade(1, 'M5_PROTECTED_CORRECTNESS_7f1', 5.0, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($usage);
        calculation_service::recalculate_attempt((int) $attempt->id);

        $this->setUser($student);
        $withheld = student_result_service::get_own_report($course->id, self::RELEASEAT - 1);
        $this->assertCount(1, $withheld['rows']);
        $this->assertSame(student_result_service::STATE_NOT_RELEASED, $withheld['rows'][0]['state']);
        $this->assertNull($withheld['rows'][0]['percentage']);
        $this->assertSame([], $withheld['rows'][0]['remediation']);

        $released = student_result_service::get_own_report($course->id, self::RELEASEAT);
        $this->assertCount(1, $released['rows']);
        $row = $released['rows'][0];
        $this->assertSame([
            'code', 'shortstatement', 'periodcode', 'scopetype', 'scopeid', 'scopename',
            'state', 'percentage', 'displayscale', 'bandname', 'bandfeedback', 'bandid',
            'distinctitems', 'weightedpossible', 'timecalculated', 'releasedat', 'remediation',
        ], array_keys($row));
        $this->assertSame('CLO4', $row['code']);
        $this->assertSame(calculation_service::SCOPE_COURSE, $row['scopetype']);
        $this->assertSame(calculation_service::STATE_CALCULATED, $row['state']);
        $this->assertSame('50.0000000000', $row['percentage']);
        $this->assertSame('Below expectations', $row['bandname']);
        $this->assertSame(self::RELEASEAT, $row['releasedat']);
        $this->assertCount(1, $row['remediation']);
        $this->assertSame('Unit 2.3', $row['remediation'][0]['title']);
        $this->assertArrayNotHasKey('lineagejson', $row);
        $this->assertArrayNotHasKey('questiontext', $row);
        $encodedreport = json_encode($released);
        foreach ([
            'M5_PROTECTED_QUESTION_7f1',
            'M5_PROTECTED_RESPONSE_7f1',
            'M5_PROTECTED_CORRECTNESS_7f1',
            'M5_PROTECTED_ANSWER_KEY_7f1',
            'Restricted Unit 4.4',
            'Unapproved review item',
        ] as $protected) {
            $this->assertStringNotContainsString($protected, $encodedreport);
        }

        $courseresult = $DB->get_record('local_outcomemap_result', [
            'userid' => $student->id,
            'itemverid' => $itemverid,
            'scopetype' => calculation_service::SCOPE_COURSE,
            'supersededby' => null,
        ], '*', MUST_EXIST);

        // A successful open is an append-only analytics event tied to the
        // exact recommendation and result versions. It must not alter mastery
        // evidence or calculated results, and forged/inaccessible IDs fail closed.
        $releaseconfig = canonical_json::encode([
            'mode' => policy_service::RELEASE_SCHEDULED,
            'releaseat' => time() - 1,
        ]);
        $DB->update_record('local_outcomemap_policy', (object) [
            'id' => $releasepolicyid,
            'configjson' => $releaseconfig,
            'confighash' => hash('sha256', $releaseconfig),
            'timemodified' => time(),
        ]);
        $recommendation = $row['remediation'][0];
        $this->assertSame($visibleremediationid, $recommendation['recommendationid']);
        $this->assertSame((int) $courseresult->id, $recommendation['resultid']);
        $evidencecount = $DB->count_records('local_outcomemap_evidence');
        $resultcount = $DB->count_records('local_outcomemap_result');
        $destination = remediation_engagement_service::record_open(
            $visibleremediationid,
            (int) $courseresult->id
        );
        $this->assertSame($recommendation['targeturl'], $destination);
        $event = $DB->get_record('local_outcomemap_remed_event', [], '*', MUST_EXIST);
        $this->assertSame($visibleremediationid, (int) $event->remediationid);
        $this->assertSame((int) $courseresult->id, (int) $event->resultid);
        $this->assertSame((int) $student->id, (int) $event->userid);
        $this->assertSame(remediation_engagement_service::EVENT_OPENED, $event->eventtype);
        $this->assertSame($evidencecount, $DB->count_records('local_outcomemap_evidence'));
        $this->assertSame($resultcount, $DB->count_records('local_outcomemap_result'));

        foreach ([
            [$visibleremediationid, (int) $courseresult->id + 999999],
            [$hiddenremediationid, (int) $courseresult->id],
            [$visibleremediationid + 999999, (int) $courseresult->id],
        ] as [$recommendationid, $resultid]) {
            try {
                remediation_engagement_service::record_open($recommendationid, $resultid);
                $this->fail('Forged or inaccessible remediation engagement must fail closed.');
            } catch (validation_exception $exception) {
                $this->assertSame('remediationnotavailable', $exception->errorcode);
            }
        }
        $this->assertSame(1, $DB->count_records('local_outcomemap_remed_event'));
        $this->assertSame($evidencecount, $DB->count_records('local_outcomemap_evidence'));
        $this->assertSame($resultcount, $DB->count_records('local_outcomemap_result'));

        $DB->set_field('local_outcomemap_result', 'lineagehash', str_repeat('0', 64), [
            'id' => $courseresult->id,
        ]);
        $corruptlineage = student_result_service::get_own_report($course->id, self::RELEASEAT + 1);
        $this->assertSame(student_result_service::STATE_NOT_RELEASED, $corruptlineage['rows'][0]['state']);
        $DB->set_field('local_outcomemap_result', 'lineagehash', $courseresult->lineagehash, [
            'id' => $courseresult->id,
        ]);

        $DB->set_field('quiz_attempts', 'preview', 1, ['id' => $attempt->id]);
        $previewattempt = student_result_service::get_own_report($course->id, self::RELEASEAT + 1);
        $this->assertSame(student_result_service::STATE_NOT_RELEASED, $previewattempt['rows'][0]['state']);
        $DB->set_field('quiz_attempts', 'preview', 0, ['id' => $attempt->id]);

        $evidenceid = (int) $DB->get_field('local_outcomemap_evidence', 'id', [
            'userid' => $student->id,
            'itemverid' => $itemverid,
        ], MUST_EXIST);
        $DB->set_field('local_outcomemap_evidence', 'supersededby', $evidenceid, ['id' => $evidenceid]);
        $incomplete = student_result_service::get_own_report($course->id, self::RELEASEAT + 1);
        $this->assertSame(student_result_service::STATE_NOT_RELEASED, $incomplete['rows'][0]['state']);
        $this->assertNull($incomplete['rows'][0]['percentage']);
        $DB->set_field('local_outcomemap_evidence', 'supersededby', null, ['id' => $evidenceid]);

        $DB->set_field('local_outcomemap_result', 'stale', 1, [
            'userid' => $student->id,
            'itemverid' => $itemverid,
        ]);
        $stale = student_result_service::get_own_report($course->id, self::RELEASEAT + 1);
        $this->assertSame(student_result_service::STATE_STALE, $stale['rows'][0]['state']);
        $this->assertNull($stale['rows'][0]['percentage']);
        $this->assertNull($stale['rows'][0]['bandname']);
        $this->assertSame([], $stale['rows'][0]['remediation']);

        // Expiring v1 and its calculation policy must not make the stored v1
        // result disappear or silently substitute current v2 wording.
        $DB->set_field('local_outcomemap_result', 'stale', 0, [
            'userid' => $student->id,
            'itemverid' => $itemverid,
        ]);
        $DB->set_field('local_outcomemap_itemver', 'effectiveto', self::RELEASEAT + 1, ['id' => $itemverid]);
        $DB->set_field('local_outcomemap_policy', 'effectiveto', self::RELEASEAT + 1,
            ['id' => $calculationpolicyid]);
        $now = time();
        $DB->insert_record('local_outcomemap_itemver', (object) [
            'uuid' => uuid::generate(),
            'itemid' => $itemid,
            'version' => 2,
            'statement' => 'Replacement CLO4 wording.',
            'shortstatement' => 'Replacement wording.',
            'bloomlevel' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => self::RELEASEAT + 1,
            'effectiveto' => null,
            'changereason' => 'Historical result resolution test',
            'createdby' => null,
            'approvedby' => $this->reviewer->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
        $historical = student_result_service::get_own_report($course->id, self::RELEASEAT + 2);
        $this->assertCount(1, $historical['rows']);
        $this->assertSame('Integrate evidence into a recommendation.', $historical['rows'][0]['shortstatement']);
        $this->assertSame('50.0000000000', $historical['rows'][0]['percentage']);
    }

    /**
     * Build a course whose catalog course optionally belongs to a programme, with
     * one course outcome and one programme outcome defined.
     *
     * @param bool $joinprogramme Whether to record programme membership.
     * @return array{0:\stdClass,1:\stdClass} Course and enrolled learner.
     */
    private function create_programme_fixture(bool $joinprogramme): array {
        global $DB;
        $this->setAdminUser();
        $this->reviewer = $this->create_reviewer();
        $suffix = strtoupper(random_string(4));
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $catalogid = catalog_course_service::create([
            'code' => 'PLOSCOPE' . $suffix,
            'name' => 'Programme scoping course',
        ]);
        $cinstid = course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-T1',
        ]);
        course_instance_service::submit_for_review($cinstid);
        $this->setUser($this->reviewer);
        course_instance_service::confirm($cinstid);
        $this->setAdminUser();

        // Course-owned outcome: visible before and after the change.
        $this->create_outcome_in(framework_service::OWNER_COURSE, $catalogid, 'CLOFW' . $suffix, 'C1');

        // Through the service so programme type and credential normalise correctly.
        $programid = program_service::create([
            'code' => 'PROG' . $suffix,
            'name' => 'Test programme',
            'programtype' => program_service::TYPE_GRADUATE,
        ]);
        program_service::submit_for_review($programid);
        if (workflow::requires_independent_approval()) {
            $this->setUser($this->reviewer);
            program_service::approve($programid);
            $this->setAdminUser();
        }
        if ($joinprogramme) {
            $DB->insert_record('local_outcomemap_progcourse', (object) [
                'uuid' => uuid::generate(), 'programid' => $programid, 'courseid' => $catalogid,
                'effectivefrom' => self::EFFECTIVEFROM, 'effectiveto' => null,
                'status' => workflow::APPROVED, 'createdby' => null, 'approvedby' => null,
                'timecreated' => time(), 'timemodified' => time(), 'approvedat' => time(),
            ]);
        }
        $this->create_outcome_in(framework_service::OWNER_PROGRAM, $programid, 'PLOFW' . $suffix, 'P1');

        return [$course, $student];
    }

    /**
     * Create one approved outcome in a framework owned by the given object.
     *
     * @param string $ownertype Framework owner type.
     * @param int $ownerid Owner record ID.
     * @param string $fwcode Framework code.
     * @param string $code Outcome code.
     * @return void
     */
    private function create_outcome_in(string $ownertype, int $ownerid, string $fwcode, string $code): void {
        global $DB;
        $frameworkid = framework_service::create([
            'code' => $fwcode,
            'name' => $fwcode,
            'ownertype' => $ownertype,
            'ownerid' => $ownerid,
        ]);
        framework_service::submit_for_review($frameworkid);
        if (workflow::requires_independent_approval()) {
            $this->setUser($this->reviewer);
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
            $this->setUser($this->reviewer);
            outcome_service::approve($itemverid);
            $this->setAdminUser();
        }
    }

    /**
     * A learner sees the outcomes of every programme their catalog course joins,
     * so attainment can be read as unit, course, and programme levels together.
     */
    public function test_report_includes_programme_outcomes_when_the_course_joins_one(): void {
        $this->resetAfterTest(true);
        [$course, $student] = $this->create_programme_fixture(true);

        $this->setUser($student);
        $report = student_result_service::get_own_report((int) $course->id);
        $codes = array_column($report['rows'], 'code');
        $this->assertContains('C1', $codes, 'The catalog course outcome must still be reported.');
        $this->assertContains('P1', $codes, 'The programme outcome must be reported.');
    }

    /**
     * Without programme membership the report stays limited to the catalog course.
     */
    public function test_report_excludes_programme_outcomes_without_membership(): void {
        $this->resetAfterTest(true);
        [$course, $student] = $this->create_programme_fixture(false);

        $this->setUser($student);
        $report = student_result_service::get_own_report((int) $course->id);
        $codes = array_column($report['rows'], 'code');
        $this->assertContains('C1', $codes);
        $this->assertNotContains('P1', $codes,
            'A programme the course does not belong to must not leak into the learner report.');
    }
}
