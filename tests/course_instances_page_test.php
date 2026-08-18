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
use local_outcomemap\output\course_instances_page;

/**
 * Tests the course instances page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_instances_page_test extends \advanced_testcase {
    /**
     * @var int Fixed reference time used for every delivery-window assertion.
     */
    private const NOW = 1785110400;

    /**
     * Associate a Moodle course with the given delivery window.
     *
     * @param int $catalogid Catalog course ID.
     * @param string $periodcode Reporting period code.
     * @param int $startdate Moodle course start date.
     * @param int $enddate Moodle course end date, or 0 for none.
     * @param bool $confirm Whether to finalize the association.
     * @return int Association ID.
     */
    private function associate(
        int $catalogid,
        string $periodcode,
        int $startdate,
        int $enddate,
        bool $confirm = true
    ): int {
        $course = $this->getDataGenerator()->create_course([
            'startdate' => $startdate,
            'enddate' => $enddate,
        ]);
        $data = [
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => $periodcode,
        ];
        return $confirm
            ? course_instance_service::create_confirmed($data)
            : course_instance_service::create($data);
    }

    /**
     * Export the page context keyed by reporting period for easy assertions.
     *
     * @param string $catalogcode Catalog code to open the page filtered to.
     * @return array{0:array,1:array} Full context and rows keyed by period code.
     */
    private function export(string $catalogcode = ''): array {
        global $PAGE;
        $context = (new course_instances_page($catalogcode, self::NOW))
            ->export_for_template($PAGE->get_renderer('core'));
        $rows = [];
        foreach ($context['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $rows[$row['periodcode']] = $row;
            }
        }
        return [$context, $rows];
    }

    /**
     * * Each association reports its governance state and its delivery phase.
     */
    public function test_export_classifies_the_delivery_phase_of_each_association(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $catalogid = catalog_course_service::create(['code' => 'MBA601', 'name' => 'Financial Management']);
        // A window that is open now, one that closed, one that has not opened,
        // and one that is not confirmed at all.
        $this->associate($catalogid, '2026-SP', self::NOW - DAYSECS, self::NOW + DAYSECS);
        $this->associate($catalogid, '2025-FA', self::NOW - (400 * DAYSECS), self::NOW - (30 * DAYSECS));
        $this->associate($catalogid, '2027-SP', self::NOW + (30 * DAYSECS), self::NOW + (120 * DAYSECS));
        $this->associate($catalogid, '2027-FA', self::NOW + (200 * DAYSECS), 0, false);

        [$context, $rows] = $this->export();

        $this->assertSame('active', $rows['2026-SP']['phase']);
        $this->assertSame('active', $rows['2026-SP']['stateclass']);
        $this->assertSame('ended', $rows['2025-FA']['phase']);
        $this->assertSame('upcoming', $rows['2027-SP']['phase']);
        $this->assertSame(
            'ended',
            $rows['2027-SP']['stateclass'],
            'A finalized association outside its window must not read as active.'
        );
        $this->assertSame('draft', $rows['2027-FA']['phase']);

        $this->assertTrue($context['hasdrafts']);
        $this->assertSame(1, $context['draftcount']);
        $this->assertCount(1, $context['groups'], 'All four associations share one catalog course.');
        $this->assertSame(4, count($context['groups'][0]['rows']));

        $filters = array_column($context['filters'], 'count', 'id');
        $this->assertSame(4, $filters['all']);
        $this->assertSame(1, $filters['active']);
        $this->assertSame(1, $filters['draft']);
    }

    /**
     * * A confirmed association offers the course pages; an unconfirmed one does not.
     */
    public function test_export_offers_course_pages_only_once_confirmed(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $catalogid = catalog_course_service::create(['code' => 'MBA602', 'name' => 'Marketing Management']);
        $this->associate($catalogid, '2026-SP', self::NOW - DAYSECS, self::NOW + DAYSECS);
        $this->associate($catalogid, '2027-FA', self::NOW + (200 * DAYSECS), 0, false);

        [, $rows] = $this->export();

        $this->assertArrayHasKey('coverageurl', $rows['2026-SP']);
        $this->assertArrayHasKey('mappingurl', $rows['2026-SP']);
        $this->assertFalse(
            $rows['2026-SP']['cansubmit'],
            'A finalized association has nothing left to submit.'
        );

        $this->assertArrayNotHasKey(
            'coverageurl',
            $rows['2027-FA'],
            'An unconfirmed association cannot govern mappings, so it must not link to them.'
        );
        $this->assertTrue($rows['2027-FA']['cansubmit']);
    }

    /**
     * * The catalog code a reader arrived with prefills the search box.
     */
    public function test_export_prefills_the_search_with_the_requested_catalog_code(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context] = $this->export('MBA601');
        $this->assertSame('MBA601', $context['searchprefill']);
    }

    /**
     * * With nothing associated the page says so rather than showing empty groups.
     */
    public function test_export_reports_an_empty_catalog(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context] = $this->export();
        $this->assertFalse($context['hasinstances']);
        $this->assertFalse($context['hasdrafts']);
        $this->assertSame([], $context['groups']);
    }
}
