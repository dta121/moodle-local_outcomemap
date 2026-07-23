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
}
