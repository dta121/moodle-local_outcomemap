<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\remediation_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\workflow;

/**
 * Behat fixture steps for learner-facing outcome feedback.
 *
 * These steps create the governed definitions and a real mapped, completed,
 * manually graded quiz attempt. The production calculation path creates the
 * authoritative evidence and result consumed by the learner report.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_outcomemap extends behat_base {
    /**
     * Creates a completed mapped CLO4 quiz result and curated visible/hidden/draft review items.
     *
     * @Given /^the M5 learner "([^"]+)" has completed the mapped quiz in "([^"]+)" with feedback "(released|withheld|manual)"$/
     * @param string $username Learner username.
     * @param string $courseshortname Moodle course shortname.
     * @param string $releasestate Whether the governed schedule has passed.
     */
    public function the_m5_learner_feedback_fixture_exists(
        string $username,
        string $courseshortname,
        string $releasestate
    ): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['shortname' => $courseshortname], '*', MUST_EXIST);
        $quizcm = $DB->get_record('course_modules', [
            'course' => $course->id,
            'idnumber' => 'm5quiz',
        ], '*', MUST_EXIST);
        $reviewcm = $DB->get_record('course_modules', [
            'course' => $course->id,
            'idnumber' => 'm5review',
        ], '*', MUST_EXIST);
        $restrictedcm = $DB->get_record('course_modules', [
            'course' => $course->id,
            'idnumber' => 'm5restricted',
        ], '*', MUST_EXIST);
        $DB->set_field('course_modules', 'visible', 0, ['id' => $restrictedcm->id]);
        rebuild_course_cache($course->id, true);

        $now = time();
        $effectivefrom = $now - HOURSECS;
        $catalogid = $DB->insert_record('local_outcomemap_course', (object) [
            'uuid' => uuid::generate(),
            'code' => 'M5-' . $course->id,
            'name' => 'M5 learner feedback course',
            'description' => null,
            'siskey' => null,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $cinstid = $DB->insert_record('local_outcomemap_cinst', (object) [
            'uuid' => uuid::generate(),
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-T1',
            'externalid' => null,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
            'confirmedby' => null,
            'confirmedat' => $now,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $frameworkid = $DB->insert_record('local_outcomemap_fw', (object) [
            'uuid' => uuid::generate(),
            'code' => 'M5-CLO',
            'name' => 'M5 course learning outcomes',
            'description' => null,
            'ownertype' => framework_service::OWNER_COURSE,
            'ownerid' => $catalogid,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $itemid = $DB->insert_record('local_outcomemap_item', (object) [
            'uuid' => uuid::generate(),
            'frameworkid' => $frameworkid,
            'code' => 'CLO4',
            'status' => workflow::APPROVED,
            'createdby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $itemverid = $DB->insert_record('local_outcomemap_itemver', (object) [
            'uuid' => uuid::generate(),
            'itemid' => $itemid,
            'version' => 1,
            'statement' => 'Integrate evidence into an ethical strategic recommendation.',
            'shortstatement' => 'Integrate evidence into a recommendation.',
            'bloomlevel' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'changereason' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        $selectionconfig = json_encode([
            'method' => policy_service::METHOD_LATEST_COMPLETED,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => uuid::generate(),
            'version' => 1,
            'policytype' => policy_service::TYPE_ATTEMPT_SELECTION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'scopeid' => null,
            'name' => 'M5 latest completed attempt',
            'configjson' => $selectionconfig,
            'confighash' => hash('sha256', $selectionconfig),
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        $calculationconfig = json_encode([
            'minitems' => 1,
            'requiremanualgrading' => true,
            'displayscale' => 1,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $calculationpolicyid = $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => uuid::generate(),
            'version' => 1,
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'scopeid' => null,
            'name' => 'M5 calculation and bands',
            'configjson' => $calculationconfig,
            'confighash' => hash('sha256', $calculationconfig),
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
        $belowbandid = $DB->insert_record('local_outcomemap_band', (object) [
            'policyid' => $calculationpolicyid,
            'code' => 'below',
            'name' => 'Below expectations',
            'description' => 'Review the curated items before reassessment.',
            'minpercent' => '0.0000000000',
            'mininclusive' => 1,
            'maxpercent' => '70.0000000000',
            'maxinclusive' => 0,
            'sortorder' => 0,
        ]);

        if ($releasestate === 'manual') {
            $releaseconfig = json_encode([
                'mode' => policy_service::RELEASE_MANUAL,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $releasename = 'M5 manual feedback release';
        } else {
            $releaseat = $releasestate === 'released' ? $now - MINSECS : $now + HOURSECS;
            $releaseconfig = json_encode([
                'mode' => policy_service::RELEASE_SCHEDULED,
                'releaseat' => $releaseat,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $releasename = 'M5 scheduled feedback release';
        }
        $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => uuid::generate(),
            'version' => 1,
            'policytype' => policy_service::TYPE_RELEASE,
            'scopetype' => policy_service::SCOPE_ASSESSMENT,
            'scopeid' => $quizcm->id,
            'name' => $releasename,
            'configjson' => $releaseconfig,
            'confighash' => hash('sha256', $releaseconfig),
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        $insertremediation = static function(
            int $targetid,
            string $title,
            string $status,
            int $priority
        ) use ($DB, $belowbandid, $cinstid, $effectivefrom, $itemverid, $now): void {
            $DB->insert_record('local_outcomemap_remed', (object) [
                'mappinguuid' => uuid::generate(),
                'version' => 1,
                'cinstid' => $cinstid,
                'itemverid' => $itemverid,
                'bandid' => $belowbandid,
                'targettype' => remediation_service::TARGET_MODULE,
                'purpose' => remediation_service::PURPOSE_REVIEW,
                'targetid' => $targetid,
                'externalurl' => null,
                'title' => $title,
                'explanation' => 'Review this item before attempting CLO4 again.',
                'priority' => $priority,
                'sortorder' => 0,
                'required' => 1,
                'minpercent' => '0.0000000000',
                'maxpercent' => '69.9999999999',
                'status' => $status,
                'effectivefrom' => $effectivefrom,
                'effectiveto' => null,
                'createdby' => null,
                'approvedby' => null,
                'timecreated' => $now,
                'timemodified' => $now,
                'approvedat' => $status === workflow::APPROVED ? $now : null,
            ]);
        };
        $insertremediation((int) $reviewcm->id, 'Unit 2.3', workflow::APPROVED, 10);
        $insertremediation((int) $restrictedcm->id, 'Restricted Unit 4.4', workflow::APPROVED, 20);
        $insertremediation((int) $reviewcm->id, 'Unapproved review item', workflow::DRAFT, 30);

        require_once($CFG->libdir . '/testing/generator/lib.php');
        $datagenerator = new testing_data_generator();
        $qbank = $datagenerator->create_module('qbank', ['course' => $course->id]);
        $questiongenerator = $datagenerator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => context_module::instance($qbank->cmid)->id,
        ]);
        $question = $questiongenerator->create_question('essay', 'editor', [
            'category' => $category->id,
            'name' => 'M5_PROTECTED_QUESTION_7f1',
            'questiontext' => 'M5_PROTECTED_QUESTION_7f1',
            'generalfeedback' => 'M5_PROTECTED_ANSWER_KEY_7f1',
        ]);
        $DB->insert_record('local_outcomemap_qmap', (object) [
            'mappinguuid' => uuid::generate(),
            'version' => 1,
            'questionversionid' => $question->versionid,
            'questionid' => $question->id,
            'itemverid' => $itemverid,
            'role' => 'assesses',
            'weight' => '1.0000000000',
            'notes' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        $quiz = $DB->get_record('quiz', ['id' => $quizcm->instance], '*', MUST_EXIST);
        $quiz->grade = 100;
        $quiz->sumgrades = 10;
        $quiz->reviewattempt = 0;
        $quiz->reviewcorrectness = 0;
        $quiz->reviewmarks = 0;
        $quiz->reviewspecificfeedback = 0;
        $quiz->reviewgeneralfeedback = 0;
        $quiz->reviewrightanswer = 0;
        $quiz->reviewoverallfeedback = 0;
        $DB->update_record('quiz', $quiz);
        quiz_add_quiz_question((int) $question->id, $quiz, 0, 10.0);

        $quizobj = \mod_quiz\quiz_settings::create($quiz->id, $user->id);
        $usage = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $usage->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($quizobj, 1, false, $now, false, $user->id);
        quiz_start_new_attempt($quizobj, $usage, $attempt, 1, $now);
        quiz_attempt_save_started($quizobj, $usage, $attempt);
        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($now, false, [
            1 => [
                'answer' => 'M5_PROTECTED_RESPONSE_7f1',
                'answerformat' => FORMAT_HTML,
            ],
        ]);
        $attemptobj->process_submit($now + 30, false);
        $attemptobj->process_grade_submission($now + 30);
        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        $usage = $attemptobj->get_question_usage();
        $usage->manual_grade(1, 'M5_PROTECTED_CORRECTNESS_7f1', 5.0, FORMAT_HTML);
        question_engine::save_questions_usage_by_activity($usage);
        calculation_service::recalculate_attempt((int) $attempt->id);
    }

    /**
     * Creates a complete two-learner accreditation snapshot source fixture.
     *
     * @Given /^the M6 accreditation reporting fixture for "([^"]+)" contains learners "([^"]+)" and "([^"]+)"$/
     * @param string $courseshortname Moodle course shortname.
     * @param string $firstusername First learner username.
     * @param string $secondusername Second learner username.
     */
    public function the_m6_accreditation_reporting_fixture_exists(
        string $courseshortname,
        string $firstusername,
        string $secondusername
    ): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->libdir . '/testing/generator/lib.php');
        $course = $DB->get_record('course', ['shortname' => $courseshortname], '*', MUST_EXIST);
        $learners = [
            $DB->get_record('user', ['username' => $firstusername], '*', MUST_EXIST),
            $DB->get_record('user', ['username' => $secondusername], '*', MUST_EXIST),
        ];
        $generator = new testing_data_generator();
        $cohort = $generator->create_cohort([
            'name' => 'M6 accreditation cohort',
            'idnumber' => 'M6-ACCREDITATION',
        ]);
        foreach ($learners as $learner) {
            cohort_add_member($cohort->id, $learner->id);
        }

        $now = time();
        $effectivefrom = $now - DAYSECS;
        $programid = (int) $DB->insert_record('local_outcomemap_program', (object) [
            'uuid' => uuid::generate(),
            'code' => 'M6-PROGRAM',
            'name' => 'M6 reporting program',
            'description' => null,
            'externalid' => null,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $catalogcourseid = (int) $DB->insert_record('local_outcomemap_course', (object) [
            'uuid' => uuid::generate(),
            'code' => 'M6-COURSE',
            'name' => 'M6 reporting course',
            'description' => null,
            'siskey' => null,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $courseinstanceid = (int) $DB->insert_record('local_outcomemap_cinst', (object) [
            'uuid' => uuid::generate(),
            'courseid' => $catalogcourseid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-T1',
            'externalid' => null,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
            'confirmedby' => null,
            'confirmedat' => $now,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_outcomemap_progcourse', (object) [
            'uuid' => uuid::generate(),
            'programid' => $programid,
            'courseid' => $catalogcourseid,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
        $frameworkid = (int) $DB->insert_record('local_outcomemap_fw', (object) [
            'uuid' => uuid::generate(),
            'code' => 'M6-PLO',
            'name' => 'M6 program outcomes',
            'description' => null,
            'ownertype' => framework_service::OWNER_PROGRAM,
            'ownerid' => $programid,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $outcomeid = (int) $DB->insert_record('local_outcomemap_item', (object) [
            'uuid' => uuid::generate(),
            'frameworkid' => $frameworkid,
            'code' => 'PLO1',
            'status' => workflow::APPROVED,
            'createdby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $outcomeversionid = (int) $DB->insert_record('local_outcomemap_itemver', (object) [
            'uuid' => uuid::generate(),
            'itemid' => $outcomeid,
            'version' => 1,
            'statement' => 'Demonstrate the accredited program outcome.',
            'shortstatement' => 'Demonstrate the program outcome.',
            'bloomlevel' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'changereason' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        $insertpolicy = static function(string $type, array $config) use ($DB, $effectivefrom, $now): int {
            $configjson = \local_outcomemap\local\canonical_json::encode($config);
            return (int) $DB->insert_record('local_outcomemap_policy', (object) [
                'policyuuid' => uuid::generate(),
                'version' => 1,
                'policytype' => $type,
                'scopetype' => policy_service::SCOPE_INSTITUTION,
                'scopeid' => null,
                'name' => 'M6 ' . $type,
                'configjson' => $configjson,
                'confighash' => hash('sha256', $configjson),
                'status' => workflow::APPROVED,
                'effectivefrom' => $effectivefrom,
                'effectiveto' => null,
                'createdby' => null,
                'approvedby' => null,
                'timecreated' => $now,
                'timemodified' => $now,
                'approvedat' => $now,
            ]);
        };
        $selectionpolicyid = $insertpolicy(policy_service::TYPE_ATTEMPT_SELECTION, [
            'method' => policy_service::METHOD_LATEST_COMPLETED,
        ]);
        $calculationpolicyid = $insertpolicy(policy_service::TYPE_CALCULATION, [
            'minitems' => 1,
            'minweightedpossible' => '0.0000000000',
            'requiremanualgrading' => true,
            'displayscale' => 1,
        ]);
        $insertpolicy(policy_service::TYPE_ACCREDITATION, [
            'mincohortsize' => 2,
            'populationsource' => \local_outcomemap\local\service\suppression_service::POPULATION_MOODLE_COHORT,
            'retentionbasis' => \local_outcomemap\local\service\suppression_service::RETENTION_ANONYMISED,
            'aggregationmethod' => \local_outcomemap\local\service\suppression_service::AGGREGATION_METHOD,
            'correctionmethod' => \local_outcomemap\local\service\suppression_service::CORRECTION_METHOD,
        ]);
        $mappingid = (int) $DB->insert_record('local_outcomemap_qmap', (object) [
            'mappinguuid' => uuid::generate(),
            'version' => 1,
            'questionversionid' => 910001,
            'questionid' => 900001,
            'itemverid' => $outcomeversionid,
            'role' => 'assesses',
            'weight' => '1.0000000000',
            'notes' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        foreach ($learners as $index => $learner) {
            $evidenceuuid = uuid::generate();
            $DB->insert_record('local_outcomemap_evidence', (object) [
                'uuid' => $evidenceuuid,
                'lineageuuid' => uuid::generate(),
                'dedupekey' => hash('sha256', 'm6-behat-evidence-' . $index),
                'sourceevidenceid' => null,
                'relationpathjson' => '[]',
                'cinstid' => $courseinstanceid,
                'userid' => $learner->id,
                'assessmentcmid' => 810001,
                'quizattemptid' => 820001 + $index,
                'questionusageid' => 830001 + $index,
                'slot' => 1,
                'questionattemptid' => 840001 + $index,
                'questionversionid' => 910001,
                'questionid' => 900001,
                'itemverid' => $outcomeversionid,
                'mappingid' => $mappingid,
                'policyid' => $selectionpolicyid,
                'evidencetype' => calculation_service::TYPE_DIRECT,
                'rawfraction' => '0.8500000000',
                'rawmark' => '12.7500000000',
                'maxmark' => '15.0000000000',
                'mappingweight' => '1.0000000000',
                'relationweight' => '1.0000000000',
                'weightedearned' => '12.7500000000',
                'weightedpossible' => '15.0000000000',
                'gradingstate' => calculation_service::GRADING_GRADED,
                'attempttime' => $now - 100,
                'gradingtime' => $now - 50,
                'supersededby' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $lineagejson = \local_outcomemap\local\canonical_json::encode([['uuid' => $evidenceuuid]]);
            $DB->insert_record('local_outcomemap_result', (object) [
                'uuid' => uuid::generate(),
                'resultkey' => hash('sha256', 'm6-behat-result-' . $index),
                'version' => 1,
                'cinstid' => $courseinstanceid,
                'userid' => $learner->id,
                'scopetype' => calculation_service::SCOPE_COURSE,
                'scopeid' => $courseinstanceid,
                'periodcode' => '2026-T1',
                'itemverid' => $outcomeversionid,
                'policyid' => $calculationpolicyid,
                'numerator' => '12.7500000000',
                'denominator' => '15.0000000000',
                'percentage' => '85.0000000000',
                'distinctitems' => 1,
                'bandid' => null,
                'state' => calculation_service::STATE_CALCULATED,
                'stale' => 0,
                'algoversion' => calculation_service::ALGO_VERSION,
                'inputhash' => hash('sha256', 'm6-behat-input-' . $index),
                'lineagejson' => $lineagejson,
                'lineagehash' => hash('sha256', $lineagejson),
                'supersededby' => null,
                'timecalculated' => $now,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Verifies the latest frozen package and reconstructs its aggregate from exported learner rows.
     *
     * @Then /^the latest frozen accreditation export for "([^"]+)" reconstructs "([^"]+)" percent$/
     * @param string $username Authorized exporter username.
     * @param string $expected Expected canonical percentage.
     */
    public function the_latest_frozen_export_reconstructs(string $username, string $expected): void {
        global $DB, $USER;

        $exporter = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $snapshots = $DB->get_records(
            'local_outcomemap_snapshot',
            ['status' => \local_outcomemap\local\service\snapshot_service::STATUS_FROZEN],
            'id DESC',
            '*',
            0,
            1
        );
        if (!$snapshots) {
            throw new \RuntimeException('No frozen accreditation snapshot was found.');
        }
        $snapshot = reset($snapshots);
        $originaluser = $USER;
        \core\session\manager::set_user($exporter);
        try {
            $package = \local_outcomemap\local\service\accreditation_export_service::package(
                (int) $snapshot->id
            );
            \local_outcomemap\local\service\accreditation_export_service::record_export(
                (int) $snapshot->id,
                'json'
            );
        } finally {
            \core\session\manager::set_user($originaluser);
        }
        $DB->get_record('local_outcomemap_audit', [
            'action' => 'export_snapshot',
            'objecttype' => 'snapshot',
            'objectid' => $snapshot->id,
            'actorid' => $exporter->id,
        ], 'id', MUST_EXIST);

        $numerator = \local_outcomemap\local\decimal::ZERO;
        $denominator = \local_outcomemap\local\decimal::ZERO;
        $resultcount = 0;
        foreach ($package['items'] as $item) {
            if ($item['itemtype'] !== \local_outcomemap\local\service\snapshot_service::ITEM_RESULT) {
                continue;
            }
            $numerator = \local_outcomemap\local\decimal::add(
                $numerator,
                $item['payload']['payload']['numerator']
            );
            $denominator = \local_outcomemap\local\decimal::add(
                $denominator,
                $item['payload']['payload']['denominator']
            );
            $resultcount++;
        }
        $actual = \local_outcomemap\local\decimal::div(
            \local_outcomemap\local\decimal::mul($numerator, '100'),
            $denominator
        );
        if ($resultcount !== 2 || $actual !== $expected) {
            throw new \RuntimeException(
                "Expected two exported learner rows reconstructing {$expected}; got {$resultcount} rows and {$actual}."
            );
        }
    }
}
