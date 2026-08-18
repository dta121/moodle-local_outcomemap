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
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Tests for creating, finalizing, and removing course-instance associations.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_outcomemap\local\service\course_instance_service
 */
final class course_instance_service_test extends \advanced_testcase {
    /**
     * Create a catalog course and Moodle course, then an association for them.
     *
     * @param string $periodcode Reporting period code.
     * @return array{0:int,1:\stdClass} Association ID and the Moodle course.
     */
    private function create_association(string $periodcode = '2026-T1'): array {
        $course = $this->getDataGenerator()->create_course();
        $catalogid = catalog_course_service::create([
            'code' => 'CINST' . strtoupper(random_string(4)),
            'name' => 'Course instance test',
        ]);
        $id = course_instance_service::create_confirmed([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => $periodcode,
        ]);
        return [$id, $course];
    }

    /**
     * * With independent approval off, saving an association finalizes it outright.
     */
    public function test_create_confirmed_finalizes_without_a_second_step(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        [$id] = $this->create_association();
        $record = course_instance_service::get($id);
        $this->assertSame(workflow::APPROVED, $record->status);
        $this->assertSame(
            1,
            (int) $record->confirmed,
            'Saving should leave nothing further to click before the association governs data.'
        );
    }

    /**
     * * With independent approval on, the association still waits for a reviewer.
     */
    public function test_create_confirmed_respects_independent_approval(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 1, 'local_outcomemap');

        [$id] = $this->create_association();
        $record = course_instance_service::get($id);
        $this->assertSame(
            workflow::DRAFT,
            $record->status,
            'A site requiring independent approval must not self-confirm.'
        );
        $this->assertSame(0, (int) $record->confirmed);
    }

    /**
     * * A mistake with nothing depending on it can be removed outright.
     */
    public function test_delete_removes_an_unused_association(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        [$id] = $this->create_association();
        $this->assertSame([], course_instance_service::deletion_blockers($id));
        course_instance_service::delete($id);
        $this->assertFalse(
            $DB->record_exists('local_outcomemap_cinst', ['id' => $id]),
            'An unused association must be removable even once confirmed.'
        );
    }

    /**
     * * Anything built on the association blocks removal, and says what.
     */
    public function test_delete_refuses_when_records_depend_on_it(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        [$id, $course] = $this->create_association();
        // A section mapping is enough to make the association load-bearing.
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0], 'id', MUST_EXIST);
        $DB->insert_record('local_outcomemap_secmap', (object) [
            'mappinguuid' => \local_outcomemap\local\uuid::generate(), 'version' => 1,
            'cinstid' => $id, 'sectionid' => $section->id, 'itemverid' => 1,
            'role' => 'teaches', 'weight' => null, 'priority' => 0, 'notes' => null,
            'status' => workflow::DRAFT, 'effectivefrom' => time(), 'effectiveto' => null,
            'createdby' => null, 'approvedby' => null, 'timecreated' => time(),
            'timemodified' => time(), 'approvedat' => null,
        ]);

        $blockers = course_instance_service::deletion_blockers($id);
        $this->assertNotEmpty($blockers, 'A dependent record must block removal.');

        try {
            course_instance_service::delete($id);
            $this->fail('Deleting a load-bearing association must be refused.');
        } catch (validation_exception $e) {
            $this->assertSame('courseinstanceinuse', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('local_outcomemap_cinst', ['id' => $id]));
    }

    /**
     * * The summary listing carries the Moodle course facts a reader needs.
     */
    public function test_list_with_summary_carries_moodle_course_facts(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $start = 1767571200;
        $end = 1777680000;
        $course = $this->getDataGenerator()->create_course([
            'startdate' => $start,
            'enddate' => $end,
            'fullname' => 'Financial Management Spring',
        ]);
        $catalogid = catalog_course_service::create(['code' => 'CINSTSUM', 'name' => 'Financial Management']);
        $id = course_instance_service::create_confirmed([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-SP',
        ]);
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $rows = course_instance_service::list_with_summary();
        $this->assertArrayHasKey($id, $rows);
        $row = $rows[$id];
        $this->assertSame('CINSTSUM', $row->catalogcode);
        $this->assertSame('Financial Management', $row->catalogname);
        $this->assertSame('Financial Management Spring', $row->moodlename);
        $this->assertSame($start, (int) $row->moodlestartdate);
        $this->assertSame($end, (int) $row->moodleenddate);
        $this->assertSame(
            1,
            (int) $row->enrolledcount,
            'Only active enrolments in the associated shell should be counted.'
        );
    }

    /**
     * Associations seeded under separate periods can be gathered onto one.
     *
     * The period code decides which associations a capture covers, so
     * associations each carrying their own course code can never be captured
     * together. An association has no version history, so the only way to say
     * "these belong to one reporting period" is an audited correction.
     */
    public function test_correct_periodcode_gathers_associations_onto_one_period(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        [$first] = $this->create_association('COURSE-A');
        [$second] = $this->create_association('COURSE-B');

        $moved = course_instance_service::correct_periodcode(
            [$first, $second],
            '2026',
            'Gathered onto one reporting period so the programme can be captured at once.'
        );

        $this->assertSame(2, $moved);
        $this->assertSame('2026', $DB->get_field('local_outcomemap_cinst', 'periodcode', ['id' => $first]));
        $this->assertSame('2026', $DB->get_field('local_outcomemap_cinst', 'periodcode', ['id' => $second]));
        $this->assertTrue(
            $DB->record_exists(
                'local_outcomemap_audit',
                ['action' => 'correct_periodcode', 'objecttype' => 'course_instance', 'objectid' => $first]
            ),
            'A correction to an approved record must leave an audit trail.'
        );
    }

    /**
     * * A correction has to say why, like every other change to an approved record.
     */
    public function test_correct_periodcode_requires_a_reason(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        [$id] = $this->create_association('COURSE-A');

        $this->expectException(validation_exception::class);
        course_instance_service::correct_periodcode([$id], '2026', '   ');
    }

    /**
     * * One Moodle course cannot hold two associations for the same period.
     */
    public function test_correct_periodcode_refuses_a_collision(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $course = $this->getDataGenerator()->create_course();
        $ids = [];
        foreach (['COURSE-A', '2026'] as $period) {
            $catalogid = catalog_course_service::create([
                'code' => 'CINST' . strtoupper(random_string(4)),
                'name' => 'Collision test',
            ]);
            $ids[$period] = course_instance_service::create_confirmed([
                'courseid' => $catalogid,
                'moodlecourseid' => $course->id,
                'periodcode' => $period,
            ]);
        }

        try {
            course_instance_service::correct_periodcode([$ids['COURSE-A']], '2026', 'Should not land.');
            $this->fail('Moving onto a period the same course already holds must be refused.');
        } catch (validation_exception $e) {
            $this->assertSame('courseinstanceexists', $e->errorcode);
        }
        $this->assertSame(
            'COURSE-A',
            $DB->get_field('local_outcomemap_cinst', 'periodcode', ['id' => $ids['COURSE-A']]),
            'A refused correction must leave the association untouched.'
        );
    }
}
