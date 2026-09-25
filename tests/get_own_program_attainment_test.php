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

        // All the way down, not just the top level. The fields a consumer reads
        // are three levels in: programs, then outcomes, then the state and the
        // percentage. Comparing only the envelope would let the two drift exactly
        // where drift matters and report agreement.
        $this->assertSame(
            self::shape_of($any),
            self::shape_of($own),
            'The two reports describe the same thing for different callers, so their shapes must '
                . 'not diverge at any depth.'
        );
    }

    /**
     * A comparable description of an external structure, recursively.
     *
     * Key names, nesting and each leaf's PARAM type and required flag. Not the
     * human-readable descriptions, which may legitimately differ between the two
     * functions because one of them is talking about the caller.
     *
     * @param \core_external\external_description $description Structure to describe.
     * @return array
     */
    private static function shape_of(\core_external\external_description $description): array {
        if ($description instanceof \core_external\external_value) {
            return ['type' => $description->type, 'required' => $description->required];
        }
        if ($description instanceof \core_external\external_multiple_structure) {
            return ['each' => self::shape_of($description->content)];
        }
        if ($description instanceof \core_external\external_single_structure) {
            $out = [];
            foreach ($description->keys as $key => $sub) {
                $out[$key] = self::shape_of($sub);
            }
            return $out;
        }
        return ['unknown' => get_class($description)];
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
     * A caller who is not logged in gets a permission error, not a database error.
     *
     * The identity check has to happen before the user context is built.
     * context_user::instance(0) raises dml_missing_record_exception, so a guard
     * placed after it never runs and the refusal surfaces as "can not find data
     * record in database table user", which tells the caller nothing and reads
     * like a broken site rather than a closed door.
     *
     * @return void
     */
    public function test_a_caller_who_is_not_logged_in_is_refused_cleanly(): void {
        $this->resetAfterTest();
        $this->setUser(null);

        try {
            get_own_program_attainment::execute('', 0);
            $this->fail('A caller with no identity must not receive a report.');
        } catch (\required_capability_exception $e) {
            $this->assertStringNotContainsString(
                'database',
                strtolower($e->getMessage()),
                'The refusal must be about permission, not about a missing user row.'
            );
        }
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

    /**
     * The whole point: a real learner, with real results, gets a real report.
     *
     * Every other test here checks a refusal or a shape. This one checks that the
     * function does the thing it was added for, as the person it was added for, and
     * it is the test that would have caught the reason the first version of this
     * branch did not work at all. That version routed around
     * local/outcomemap:exportattainment at the external-function boundary, while
     * the pooling underneath still required it, so a student got
     * required_capability_exception and every structural test still passed.
     *
     * @return void
     */
    public function test_a_learner_reads_their_own_attainment(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_outcomemap');
        $fixture = $generator->create_program_attainment(2);
        $this->setUser($fixture['learnerids'][0]);

        $this->assertFalse(
            has_capability('local/outcomemap:exportattainment', \context_system::instance()),
            'precondition: the caller must NOT hold the export capability, or this proves nothing'
        );

        $result = get_own_program_attainment::execute('', 0);

        $this->assertCount(1, $result['programs']);
        $this->assertSame($fixture['programcode'], $result['programs'][0]['code']);
        $this->assertNotEmpty($result['programs'][0]['outcomes']);

        // The state, not a number. See create_program_attainment(): the fixture
        // does not clear the release gate, and a consumer that only worked for
        // released results would be broken for most real ones anyway.
        $outcome = $result['programs'][0]['outcomes'][0];
        $this->assertArrayHasKey('state', $outcome);
        $this->assertArrayHasKey('percentage', $outcome);
    }

    /**
     * An unreleased result arrives without a number, and says so.
     *
     * The consumer-facing half of the release gate. A withheld figure that arrived
     * as 0 rather than as null with a state would tell a learner they had failed
     * something nobody has published.
     *
     * @return void
     */
    public function test_an_unreleased_result_carries_no_percentage(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_outcomemap');
        $fixture = $generator->create_program_attainment(1);
        $this->setUser($fixture['learnerids'][0]);

        $result = get_own_program_attainment::execute('', 0);
        $outcome = $result['programs'][0]['outcomes'][0];

        $this->assertSame('not_released', $outcome['state']);
        $this->assertNull($outcome['percentage']);
    }

    /**
     * Scoping to the seeded course keeps its program; scoping elsewhere drops it.
     *
     * @return void
     */
    public function test_the_course_filter_selects_the_right_programs(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_outcomemap');
        $fixture = $generator->create_program_attainment(1);
        $this->setUser($fixture['learnerids'][0]);

        $this->assertCount(1, get_own_program_attainment::execute('', $fixture['courseid'])['programs']);

        $unmapped = $this->getDataGenerator()->create_course();
        $this->assertSame([], get_own_program_attainment::execute('', (int) $unmapped->id)['programs']);
    }

    /**
     * One learner still cannot be shown another's, with real data on the table.
     *
     * @return void
     */
    public function test_a_second_learner_sees_only_their_own(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_outcomemap');
        $fixture = $generator->create_program_attainment(2);

        $this->setUser($fixture['learnerids'][1]);
        $second = get_own_program_attainment::execute('', 0);

        $this->assertCount(1, $second['programs']);
        $this->assertNotEmpty(
            $second['programs'][0]['outcomes'],
            'The second learner reads their own row, not the first learner\'s, and not nothing.'
        );
    }
}
