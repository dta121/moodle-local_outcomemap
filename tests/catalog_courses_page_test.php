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

use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\program_course_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\output\catalog_courses_page;

/**
 * Tests the catalog courses page model and its summary counts.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catalog_courses_page_test extends \advanced_testcase {
    /**
     * Create an approved framework owned by a catalog course with one outcome.
     *
     * @param int $catalogid Catalog course ID.
     * @param string $code Framework code; a ULO suffix marks unit level.
     * @param int $outcomes How many outcomes to place in the framework.
     * @return void
     */
    private function add_framework(int $catalogid, string $code, int $outcomes): void {
        $frameworkid = framework_service::create([
            'code' => $code,
            'name' => 'Framework ' . $code,
            'ownertype' => framework_service::OWNER_COURSE,
            'ownerid' => $catalogid,
        ]);
        framework_service::submit_for_review($frameworkid);
        for ($index = 1; $index <= $outcomes; $index++) {
            outcome_service::create([
                'frameworkid' => $frameworkid,
                'code' => $code . '.' . $index,
                'statement' => 'Outcome ' . $code . '.' . $index,
                'effectivefrom' => 1704067200,
            ]);
        }
    }

    /**
     * Export the page context with its rows keyed by catalog code.
     *
     * @return array{0:array,1:array} Full context and rows keyed by course code.
     */
    private function export(): array {
        global $PAGE;
        $context = (new catalog_courses_page())->export_for_template($PAGE->get_renderer('core'));
        return [$context, array_column($context['rows'], null, 'code')];
    }

    /**
     * Outcome counts split course level from unit level by the ULO convention.
     */
    public function test_summary_splits_course_and_unit_outcome_counts(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $catalogid = catalog_course_service::create(['code' => 'MBA601', 'name' => 'Financial Management']);
        $this->add_framework($catalogid, 'MBA601-CLO', 3);
        $this->add_framework($catalogid, 'MBA601-ULO', 5);

        $summary = catalog_course_service::list_with_summary();
        $this->assertArrayHasKey($catalogid, $summary);
        $row = $summary[$catalogid];
        $this->assertSame(2, (int) $row->frameworkcount);
        $this->assertSame(3, (int) $row->courseoutcomecount);
        $this->assertSame(5, (int) $row->unitoutcomecount,
            'A framework whose code ends in ULO holds unit-level outcomes.');
    }

    /**
     * Associations are counted, with the confirmed ones reported separately.
     */
    public function test_summary_counts_course_instances_and_confirmations(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $catalogid = catalog_course_service::create(['code' => 'MBA603', 'name' => 'Managing People']);
        $confirmed = $this->getDataGenerator()->create_course();
        $unconfirmed = $this->getDataGenerator()->create_course();
        course_instance_service::create_confirmed([
            'courseid' => $catalogid,
            'moodlecourseid' => $confirmed->id,
            'periodcode' => '2026-SP',
        ]);
        course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $unconfirmed->id,
            'periodcode' => '2026-FA',
        ]);

        $row = catalog_course_service::list_with_summary()[$catalogid];
        $this->assertSame(2, (int) $row->instancecount);
        $this->assertSame(1, (int) $row->confirmedinstancecount);
    }

    /**
     * The two states worth acting on are surfaced and counted for filtering.
     */
    public function test_export_flags_courses_with_no_program_and_no_outcomes(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $programid = program_service::create([
            'code' => 'MBA',
            'name' => 'Master of Business Administration',
            'programtype' => program_service::TYPE_GRADUATE,
        ]);
        $attached = catalog_course_service::create(['code' => 'MBA601', 'name' => 'Financial Management']);
        $this->add_framework($attached, 'MBA601-CLO', 2);
        program_course_service::create([
            'programid' => $programid,
            'courseid' => $attached,
            'effectivefrom' => 1767139200,
        ]);
        catalog_course_service::create(['code' => 'MBA699', 'name' => 'Orphan course']);

        [$context, $rows] = $this->export();

        $this->assertTrue($rows['MBA601']['hasprograms']);
        $this->assertTrue($rows['MBA601']['hasoutcomes']);
        $this->assertCount(1, $rows['MBA601']['memberships']);
        $this->assertSame('MBA', $rows['MBA601']['memberships'][0]['code']);
        $this->assertSame('graduate', $rows['MBA601']['memberships'][0]['typeclass'],
            'The membership badge takes its colour from the program type.');

        $this->assertFalse($rows['MBA699']['hasprograms'],
            'A course in no program must be visible as such: its outcomes roll up nowhere.');
        $this->assertFalse($rows['MBA699']['hasoutcomes']);

        $filters = array_column($context['filters'], 'count', 'id');
        $this->assertSame(2, $filters['all']);
        $this->assertSame(1, $filters['noprogram']);
        $this->assertSame(1, $filters['nooutcomes']);
    }

    /**
     * A retired membership no longer claims the course for its program.
     */
    public function test_export_ignores_retired_memberships(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $programid = program_service::create([
            'code' => 'BSBA',
            'name' => 'Bachelor of Science in Business Administration',
            'programtype' => program_service::TYPE_UNDERGRADUATE,
        ]);
        $catalogid = catalog_course_service::create(['code' => 'BUS101', 'name' => 'Introduction to Business']);
        $membershipid = program_course_service::create([
            'programid' => $programid,
            'courseid' => $catalogid,
            'effectivefrom' => 1767139200,
        ]);
        $DB->set_field('local_outcomemap_progcourse', 'status', \local_outcomemap\local\workflow::RETIRED,
            ['id' => $membershipid]);

        [, $rows] = $this->export();
        $this->assertFalse($rows['BUS101']['hasprograms']);
        $this->assertSame([], $rows['BUS101']['memberships']);
    }
}
