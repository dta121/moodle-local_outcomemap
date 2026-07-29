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
use local_outcomemap\local\service\program_course_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;
use local_outcomemap\output\curriculum_page;

/**
 * Tests correcting a catalog course attached to the wrong program.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_outcomemap\local\service\program_course_service
 */
final class program_course_membership_test extends \advanced_testcase {
    /** @var int Fixed reference time. */
    private const NOW = 1785110400;

    /** @var int The program a course was meant to be in. */
    private int $mei;

    /** @var int The program it was attached to by mistake. */
    private int $mba;

    /** @var int The catalog course. */
    private int $courseid;

    /** @var int The mistaken membership. */
    private int $membershipid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->mba = program_service::create([
            'code' => 'MBA',
            'name' => 'Master of Business Administration',
            'programtype' => program_service::TYPE_GRADUATE,
        ]);
        $this->mei = program_service::create([
            'code' => 'MEI',
            'name' => "Master's in Entrepreneurship & Innovation",
            'programtype' => program_service::TYPE_GRADUATE,
        ]);
        $this->courseid = catalog_course_service::create([
            'code' => 'MEI603',
            'name' => 'Entrepreneurial Leadership',
        ]);
        $this->membershipid = program_course_service::create([
            'programid' => $this->mba,
            'courseid' => $this->courseid,
            'effectivefrom' => self::NOW - (30 * DAYSECS),
        ]);
    }

    /**
     * A draft membership governed nothing, so removing it deletes the row.
     */
    public function test_remove_deletes_a_draft_membership(): void {
        global $DB;

        program_course_service::remove($this->membershipid, 'Attached to the wrong program.');

        $this->assertFalse($DB->record_exists('local_outcomemap_progcourse', ['id' => $this->membershipid]),
            'A draft membership never took effect, so removing it must delete it.');
        $this->assertTrue($DB->record_exists('local_outcomemap_audit',
            ['objecttype' => 'program_course', 'action' => 'delete']),
            'The deletion must be recorded in the audit history.');
    }

    /**
     * An approved membership may already be captured in a frozen snapshot, so it is
     * retired instead of deleted.
     */
    public function test_remove_retires_an_approved_membership(): void {
        global $DB;
        program_course_service::submit_for_review($this->membershipid);
        $this->assertSame(workflow::APPROVED,
            $DB->get_field('local_outcomemap_progcourse', 'status', ['id' => $this->membershipid]));

        program_course_service::remove($this->membershipid, 'Wrong program.');

        $this->assertSame(workflow::RETIRED,
            $DB->get_field('local_outcomemap_progcourse', 'status', ['id' => $this->membershipid]),
            'An approved membership must survive as history rather than be deleted.');
        $this->assertTrue($DB->record_exists('local_outcomemap_audit',
            ['objecttype' => 'program_course', 'action' => 'retire']));
    }

    /**
     * Moving relocates the course and keeps the effective dates it already had.
     */
    public function test_move_relocates_the_course(): void {
        global $DB;
        $before = $DB->get_record('local_outcomemap_progcourse', ['id' => $this->membershipid]);

        $newid = program_course_service::move($this->membershipid, $this->mei, 'Belongs in MEI.');

        $this->assertFalse($DB->record_exists('local_outcomemap_progcourse', ['id' => $this->membershipid]),
            'The mistaken draft membership must be gone.');
        $new = $DB->get_record('local_outcomemap_progcourse', ['id' => $newid], '*', MUST_EXIST);
        $this->assertSame($this->mei, (int) $new->programid);
        $this->assertSame($this->courseid, (int) $new->courseid);
        $this->assertSame(workflow::DRAFT, $new->status,
            'Which program teaches a course is governed, so the new membership starts as a draft.');
        $this->assertSame((int) $before->effectivefrom, (int) $new->effectivefrom,
            'The effective dates the reader set must carry across the move.');
    }

    /**
     * Moving an approved membership retires it rather than losing the record.
     */
    public function test_move_retires_an_approved_membership(): void {
        global $DB;
        program_course_service::submit_for_review($this->membershipid);

        $newid = program_course_service::move($this->membershipid, $this->mei);

        $this->assertSame(workflow::RETIRED,
            $DB->get_field('local_outcomemap_progcourse', 'status', ['id' => $this->membershipid]));
        $this->assertSame($this->mei,
            (int) $DB->get_field('local_outcomemap_progcourse', 'programid', ['id' => $newid]));
    }

    /**
     * Moving a course into the program it is already in is rejected.
     */
    public function test_move_rejects_the_same_program(): void {
        $this->expectException(validation_exception::class);
        program_course_service::move($this->membershipid, $this->mba);
    }

    /**
     * Moving a course into a program that already contains it is rejected rather
     * than producing two live memberships the roll-up could not tell apart.
     */
    public function test_move_rejects_a_program_that_already_has_it(): void {
        program_course_service::create([
            'programid' => $this->mei,
            'courseid' => $this->courseid,
            'effectivefrom' => self::NOW,
        ]);

        $this->expectException(validation_exception::class);
        program_course_service::move($this->membershipid, $this->mei);
    }

    /**
     * A failed move leaves the original membership exactly as it was.
     */
    public function test_failed_move_leaves_the_original_alone(): void {
        global $DB;
        $before = $DB->get_record('local_outcomemap_progcourse', ['id' => $this->membershipid]);

        try {
            // A program id that does not exist fails after the membership is loaded.
            program_course_service::move($this->membershipid, $this->mba + $this->mei + 1000);
            $this->fail('Moving into a program that does not exist must be rejected.');
        } catch (validation_exception $e) {
            $after = $DB->get_record('local_outcomemap_progcourse', ['id' => $this->membershipid]);
            $this->assertEquals($before, $after,
                'A rejected move must not have touched the membership.');
        }
    }

    /**
     * After a move the curriculum page reads the course under its new program only.
     */
    public function test_curriculum_page_follows_the_move(): void {
        global $PAGE;
        program_course_service::move($this->membershipid, $this->mei);
        $renderer = $PAGE->get_renderer('core');

        $mba = (new curriculum_page($this->mba, self::NOW))->export_for_template($renderer);
        $mei = (new curriculum_page($this->mei, self::NOW))->export_for_template($renderer);

        $this->assertSame([], array_column($mba['courses'], 'code'),
            'The course must no longer be listed under the program it left.');
        $this->assertSame(['MEI603'], array_column($mei['courses'], 'code'),
            'The course must be listed under the program it moved into.');
    }

    /**
     * Every non-retired membership offers the two corrections to a manager.
     */
    public function test_curriculum_page_offers_move_and_remove(): void {
        global $PAGE;
        $context = (new curriculum_page($this->mba, self::NOW))
            ->export_for_template($PAGE->get_renderer('core'));

        $card = $context['courses'][0];
        $this->assertTrue($card['canmovemembership']);
        $this->assertStringContainsString('action=movemembership', $card['membershipmoveurl']);
        $this->assertStringContainsString('action=removemembership', $card['membershipremoveurl']);
        $this->assertSame(get_string('membershipremoveaction', 'local_outcomemap'),
            $card['membershipremovelabel'],
            'A draft membership is removed outright, so the action says so.');
    }
}
