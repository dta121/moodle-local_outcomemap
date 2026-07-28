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
use local_outcomemap\local\workflow;
use local_outcomemap\output\curriculum_page;

/**
 * Tests the combined curriculum page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class curriculum_page_test extends \advanced_testcase {
    /** @var int Fixed reference time for delivery-window assertions. */
    private const NOW = 1785110400;

    /**
     * Attach a catalog course to a program.
     *
     * @param int $programid Program ID.
     * @param string $code Catalog course code.
     * @param string $name Catalog course name.
     * @return int Catalog course ID.
     */
    private function attach(int $programid, string $code, string $name): int {
        $courseid = catalog_course_service::create(['code' => $code, 'name' => $name]);
        program_course_service::create([
            'programid' => $programid,
            'courseid' => $courseid,
            'effectivefrom' => self::NOW - (30 * DAYSECS),
        ]);
        return $courseid;
    }

    /**
     * Give a catalog course an approved framework holding one outcome.
     *
     * @param int $courseid Catalog course ID.
     * @param string $code Framework code.
     * @return void
     */
    private function add_framework(int $courseid, string $code): void {
        $frameworkid = framework_service::create([
            'code' => $code,
            'name' => 'Framework ' . $code,
            'ownertype' => framework_service::OWNER_COURSE,
            'ownerid' => $courseid,
        ]);
        framework_service::submit_for_review($frameworkid);
        outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => $code . '.1',
            'statement' => 'Outcome for ' . $code,
            'effectivefrom' => self::NOW - (30 * DAYSECS),
        ]);
    }

    /**
     * Associate a Moodle course with a catalog course.
     *
     * @param int $courseid Catalog course ID.
     * @param string $periodcode Reporting period code.
     * @param bool $confirm Whether to finalize the association.
     * @return void
     */
    private function deliver(int $courseid, string $periodcode, bool $confirm): void {
        $course = $this->getDataGenerator()->create_course([
            'startdate' => self::NOW - DAYSECS,
            'enddate' => self::NOW + DAYSECS,
        ]);
        $data = [
            'courseid' => $courseid,
            'moodlecourseid' => $course->id,
            'periodcode' => $periodcode,
        ];
        $confirm ? course_instance_service::create_confirmed($data) : course_instance_service::create($data);
    }

    /**
     * Export the page context for one program.
     *
     * @param int $programid Program to open, or 0 for the first.
     * @return array Template context.
     */
    private function export(int $programid = 0): array {
        global $PAGE;
        return (new curriculum_page($programid, self::NOW))
            ->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * The page joins a program to its courses and each course to its delivery.
     */
    public function test_export_joins_programs_courses_and_delivery(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $programid = program_service::create([
            'code' => 'MBA',
            'name' => 'Master of Business Administration',
            'programtype' => program_service::TYPE_GRADUATE,
        ]);
        $delivered = $this->attach($programid, 'MBA601', 'Financial Management');
        $this->add_framework($delivered, 'MBA601-CLO');
        $this->deliver($delivered, '2026-SP', true);
        $this->attach($programid, 'MBA699', 'Not delivered yet');

        $context = $this->export($programid);

        $this->assertTrue($context['hasprograms']);
        $this->assertTrue($context['hasselection']);
        $this->assertSame('MBA', $context['code']);
        $this->assertCount(1, $context['sidebar'], 'One program type is represented.');
        $this->assertSame('graduate', $context['sidebar'][0]['typeclass']);
        $this->assertTrue($context['sidebar'][0]['rows'][0]['selected']);
        $this->assertSame(2, $context['sidebar'][0]['rows'][0]['coursecount']);

        $courses = array_column($context['courses'], null, 'code');
        $this->assertSame(['MBA601', 'MBA699'], array_keys($courses),
            'Courses are listed in catalog code order.');

        $this->assertTrue($courses['MBA601']['hasoutcomes']);
        $this->assertTrue($courses['MBA601']['hasinstances']);
        $this->assertCount(1, $courses['MBA601']['instances']);
        $this->assertSame('2026-SP', $courses['MBA601']['instances'][0]['periodcode']);
        $this->assertSame('active', $courses['MBA601']['instances'][0]['stateclass']);
        $this->assertArrayHasKey('coverageurl', $courses['MBA601']['instances'][0]);

        $this->assertFalse($courses['MBA699']['hasoutcomes']);
        $this->assertFalse($courses['MBA699']['hasinstances'],
            'A course with no Moodle association captures no evidence.');
        $this->assertSame('ended', $courses['MBA699']['stateclass'],
            'A course nothing delivers must not read as in delivery.');
    }

    /**
     * The attention fact names the two things that stop a program reporting.
     */
    public function test_export_flags_unconfirmed_delivery_and_missing_outcomes(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $programid = program_service::create([
            'code' => 'BSBA',
            'name' => 'Bachelor of Science in Business Administration',
            'programtype' => program_service::TYPE_UNDERGRADUATE,
        ]);
        $courseid = $this->attach($programid, 'BUS101', 'Introduction to Business');
        // Left as a draft, so it cannot govern mappings or results yet.
        $this->deliver($courseid, '2026-FA', false);

        $context = $this->export($programid);
        $facts = array_column($context['facts'], null, 'label');
        $attention = $facts[get_string('curriculum_fact_attention', 'local_outcomemap')];

        $this->assertTrue($attention['warn']);
        $this->assertStringContainsString('1 unconfirmed instance', $attention['value']);
        $this->assertStringContainsString('1 course without outcomes', $attention['value']);

        $outcomes = $facts[get_string('curriculum_fact_outcomes', 'local_outcomemap')];
        $this->assertTrue($outcomes['warn'], 'A program with no outcomes rolls nothing up.');
    }

    /**
     * A course shared with another program says so, and is offered to programs
     * that do not have it.
     */
    public function test_export_reports_shared_and_attachable_courses(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $mba = program_service::create([
            'code' => 'MBA',
            'name' => 'Master of Business Administration',
            'programtype' => program_service::TYPE_GRADUATE,
        ]);
        $spec = program_service::create([
            'code' => 'SP-MKT',
            'name' => 'Digital Marketing Specialization',
            'programtype' => program_service::TYPE_SPECIALIZATION,
        ]);
        $shared = $this->attach($mba, 'MBA602', 'Marketing Management');
        program_course_service::create([
            'programid' => $spec,
            'courseid' => $shared,
            'effectivefrom' => self::NOW - (10 * DAYSECS),
        ]);
        $this->attach($mba, 'MBA601', 'Financial Management');

        $mbacontext = $this->export($mba);
        $courses = array_column($mbacontext['courses'], null, 'code');
        $this->assertTrue($courses['MBA602']['shared']);
        $this->assertStringContainsString('SP-MKT', $courses['MBA602']['sharedlabel']);
        $this->assertFalse($courses['MBA601']['shared']);

        // The specialization holds only the shared course, so the other one is
        // offered for attachment rather than being invisible.
        $speccontext = $this->export($spec);
        $this->assertCount(1, $speccontext['courses']);
        $attachable = array_column($speccontext['attachable'], null, 'code');
        $this->assertArrayHasKey('MBA601', $attachable);
        $this->assertStringContainsString('MBA', $attachable['MBA601']['inline']);
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
        $courseid = $this->attach($programid, 'BUS101', 'Introduction to Business');
        $DB->set_field('local_outcomemap_progcourse', 'status', workflow::RETIRED,
            ['programid' => $programid, 'courseid' => $courseid]);

        $context = $this->export($programid);
        $this->assertSame([], $context['courses'],
            'A retired membership must not keep the course in the program.');
        $this->assertSame(0, $context['sidebar'][0]['rows'][0]['coursecount']);
        // The course is not lost: it is offered back for attachment.
        $this->assertSame(['BUS101'], array_column($context['attachable'], 'code'));
    }

    /**
     * With no programs the page says so instead of rendering an empty column.
     */
    public function test_export_reports_an_empty_curriculum(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $context = $this->export();
        $this->assertFalse($context['hasprograms']);
        $this->assertFalse($context['hasselection']);
        $this->assertSame([], $context['sidebar']);
        // The create actions live in the page toolbar, so a site with nothing in
        // it can still make its first program and catalog course.
        $this->assertNotEmpty($context['addprogramurl']);
        $this->assertNotEmpty($context['addcourseurl']);
        $this->assertNotEmpty($context['addinstanceurl']);
    }

    /**
     * An unknown program falls back to the first rather than showing nothing.
     */
    public function test_export_falls_back_to_the_first_program(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        program_service::create([
            'code' => 'MBA',
            'name' => 'Master of Business Administration',
            'programtype' => program_service::TYPE_GRADUATE,
        ]);
        $context = $this->export(987654);
        $this->assertTrue($context['hasselection']);
        $this->assertSame('MBA', $context['code']);
    }
}
