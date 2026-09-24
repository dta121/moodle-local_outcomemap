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

namespace local_outcomemap\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_outcomemap\local\service\attainment_export_service;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\workflow;

/**
 * External function: the CALLING learner's own released program-outcome attainment.
 *
 * The same report get_user_program_attainment returns, for the caller and only
 * ever for the caller. It exists because that function cannot serve a learner:
 * it takes an arbitrary user id and therefore has to require
 * local/outcomemap:exportattainment at system context, which no student holds
 * and which must not be granted to one, since holding it means reading anybody's
 * attainment.
 *
 * The distinction is in the signature rather than in a permission check. There
 * is no user id parameter, so there is no request this function could be made to
 * answer about another person. That is what makes it safe to expose over AJAX to
 * a logged-in learner, where the any-user export deliberately is not.
 *
 * Authorization is therefore about whether this site lets learners see their own
 * results at all, which is what local/outcomemap:viewownresults already means.
 * That capability is defined at course level, and this report is program-wide, so
 * it is required in at least one course contributing to the report rather than in
 * some single context that does not exist. A learner who holds it nowhere gets an
 * empty programs list, which is the same answer they would get from the
 * course-level report page.
 *
 * Everything else is unchanged and deliberately so: the service evaluates every
 * release gate against the learner, percentages stay canonical scale-10 decimal
 * strings, and a state accompanies every row so no consumer can mistake
 * not_assessed or insufficient_evidence for zero.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_own_program_attainment extends external_api {
    /**
     * Parameter definition.
     *
     * Deliberately carries no user id. See the class docblock: the absence is
     * the security property, not an omission. Both parameters narrow the caller's
     * own report and neither can widen it.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'programcode' => new external_value(
                PARAM_TEXT,
                'Restrict to one program code; empty for every program the caller has results in',
                VALUE_DEFAULT,
                ''
            ),
            'courseid' => new external_value(
                PARAM_INT,
                'Restrict to the programs this Moodle course contributes to; 0 for all of them',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Execute the read for the calling learner.
     *
     * @param string $programcode Optional program-code filter.
     * @param int $courseid Optional Moodle course filter; see program_codes_for_course().
     * @return array
     */
    public static function execute(string $programcode = '', int $courseid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'programcode' => $programcode,
            'courseid' => $courseid,
        ]);

        // The caller's own user context. Nothing here reads another user, so a
        // system context check would claim an authority this function does not
        // use and does not need.
        $usercontext = \context_user::instance($USER->id);
        self::validate_context($usercontext);

        // A guest has no results and no identity worth reporting against, and
        // answering an empty report would imply the question was meaningful.
        if (isguestuser() || !isloggedin()) {
            throw new \required_capability_exception(
                $usercontext,
                'local/outcomemap:viewownresults',
                'nopermissions',
                ''
            );
        }

        // Whether this site lets this learner see their own results at all.
        // viewownresults is defined at course level and this report is
        // program-wide, so it is required in at least one course the learner is
        // enrolled in rather than in a single context that does not exist. A site
        // that has withheld the capability from a cohort withholds this too.
        //
        // An empty report rather than an exception when they hold it nowhere:
        // "you may not see your own results here" and "you have no results" look
        // the same to a learner, and the course report page already answers the
        // first case by simply not offering the page.
        if (!self::may_see_own_results()) {
            return [
                'generatedat' => time(),
                'algoversion' => calculation_service::ALGO_VERSION,
                'programs' => [],
            ];
        }

        $report = attainment_export_service::get_user_program_attainment(
            (int) $USER->id,
            $params['programcode'] === '' ? null : $params['programcode']
        );

        if ((int) $params['courseid'] > 0) {
            $codes = self::program_codes_for_course((int) $params['courseid']);
            $report['programs'] = array_values(array_filter(
                $report['programs'],
                static fn(array $program): bool => in_array($program['code'], $codes, true)
            ));
        }

        return $report;
    }

    /**
     * The programs a Moodle course currently contributes to.
     *
     * Exists so that a course-scoped consumer can ask "does this course take part
     * in outcomes, and where does the caller stand in its programs" as one
     * question. Asking it any other way means reading the outcome definitions,
     * which needs local/outcomemap:viewdefinitions, and that is an author's
     * capability held by editing teachers and managers rather than by learners. A
     * learner-facing page that had to check it would be able to render for staff
     * and for nobody else, which is the failure this whole function exists to
     * avoid one layer down.
     *
     * Nothing here is personal: it is the curriculum shape of a course. What the
     * caller then sees is still only their own attainment, narrowed.
     *
     * Effective-dated, because a course joins and leaves a program over time and
     * the report is about now. An approved membership with no end date, or one
     * whose end date has not passed, counts.
     *
     * @param int $moodlecourseid Moodle course id.
     * @return string[] Program codes, possibly empty.
     */
    private static function program_codes_for_course(int $moodlecourseid): array {
        global $DB;

        $now = time();

        return array_values($DB->get_fieldset_sql(
            "SELECT DISTINCT p.code
               FROM {local_outcomemap_cinst} ci
               JOIN {local_outcomemap_progcourse} pc ON pc.courseid = ci.courseid
               JOIN {local_outcomemap_program} p ON p.id = pc.programid
              WHERE ci.moodlecourseid = :courseid
                AND ci.status = :cistatus
                AND ci.confirmed = 1
                AND pc.status = :pcstatus
                AND pc.effectivefrom <= :now1
                AND (pc.effectiveto IS NULL OR pc.effectiveto > :now2)",
            [
                'courseid' => $moodlecourseid,
                'cistatus' => workflow::APPROVED,
                'pcstatus' => workflow::APPROVED,
                'now1' => $now,
                'now2' => $now,
            ]
        ));
    }

    /**
     * Does the caller hold viewownresults anywhere it could apply?
     *
     * Checked against the courses they are enrolled in, which is the population
     * the capability is defined over. Stops at the first grant rather than
     * evaluating every enrolment, because one is enough to answer the question.
     *
     * @return bool
     */
    private static function may_see_own_results(): bool {
        foreach (enrol_get_my_courses('id', 'id ASC') as $course) {
            $context = \context_course::instance((int) $course->id, IGNORE_MISSING);
            if ($context && has_capability('local/outcomemap:viewownresults', $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return definition.
     *
     * Identical to get_user_program_attainment by construction rather than by
     * copy, so the two cannot drift apart and a consumer can switch between them
     * without touching its parser.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return get_user_program_attainment::execute_returns();
    }
}
