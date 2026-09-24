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

use local_outcomemap\external\get_own_program_attainment;
use local_outcomemap\external\get_user_program_attainment;

/**
 * The own-attainment external function answers about the caller and nobody else.
 *
 * The security property under test is structural rather than procedural: the
 * function takes no user id, so there is no request it can be made to answer
 * about another person. These pin that the signature stays that way, that the
 * capability still governs whether a learner sees anything at all, and that the
 * return shape does not drift from the any-user export a consumer may also read.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_outcomemap\external\get_own_program_attainment
 */
final class get_own_program_attainment_test extends \advanced_testcase {
    /**
     * There is no user id parameter, and there must never be one.
     *
     * If a later change adds one, every argument for exposing this over AJAX to
     * a logged-in learner stops holding, because the function could then be
     * asked about somebody else. This test is the guard on that, not a
     * restatement of the signature.
     *
     * @return void
     */
    public function test_the_signature_cannot_name_another_user(): void {
        $keys = array_keys(get_own_program_attainment::execute_parameters()->keys);

        $this->assertNotContains(
            'userid',
            $keys,
            'A user id parameter here would need the system capability the any-user export '
                . 'requires, and would undo the reason this exists.'
        );
        $this->assertSame(
            ['programcode', 'courseid'],
            $keys,
            'This function is safe to expose to learners precisely because it cannot be asked '
                . 'about another user. A user id parameter here would need the system capability '
                . 'the any-user export requires, and would undo the reason this exists.'
        );
    }

    /**
     * The return shape is the any-user export's, by construction.
     *
     * A consumer that can read one should be able to read the other without
     * touching its parser, and two hand-maintained copies would drift.
     *
     * @return void
     */
    public function test_the_return_shape_matches_the_any_user_export(): void {
        $own = get_own_program_attainment::execute_returns();
        $any = get_user_program_attainment::execute_returns();

        $this->assertSame(
            array_keys($any->keys),
            array_keys($own->keys),
            'The two reports describe the same thing for different callers, so their shapes must '
                . 'not diverge.'
        );
    }

    /**
     * A learner holding the capability nowhere receives an empty report.
     *
     * Empty rather than an exception: to a learner, "this site does not show you
     * your own results" and "you have no results yet" are the same experience,
     * and the course report page already handles the first by not being offered.
     *
     * @return void
     */
    public function test_a_learner_with_no_enrolment_sees_no_programs(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = get_own_program_attainment::execute('', 0);

        $this->assertSame([], $result['programs']);
        $this->assertArrayHasKey(
            'algoversion',
            $result,
            'The envelope is still well formed when the list is empty, so a consumer does not have '
                . 'to special-case the refusal.'
        );
    }

    /**
     * A guest is refused rather than answered.
     *
     * @return void
     */
    public function test_a_guest_is_refused(): void {
        $this->resetAfterTest();
        $this->setGuestUser();

        $this->expectException(\required_capability_exception::class);
        get_own_program_attainment::execute('', 0);
    }

    /**
     * A course that takes part in no program narrows the report to nothing.
     *
     * This is what makes a course-scoped consumer possible without reading the
     * outcome definitions. A learner-facing page cannot ask "does this course use
     * outcomes" any other way: every definitions API requires
     * local/outcomemap:viewdefinitions, which editing teachers and managers hold
     * and students do not, so a page that asked would render for staff and for
     * nobody else.
     *
     * @return void
     */
    public function test_scoping_to_an_unmapped_course_returns_no_programs(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($user);

        $result = get_own_program_attainment::execute('', (int) $course->id);

        $this->assertSame(
            [],
            $result['programs'],
            'A plain course belongs to no program, so there is nothing about it to report and '
                . 'the consumer should render nothing rather than an empty panel.'
        );
    }
}
