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

/**
 * Tests the catalog course summary counts the Curriculum page reads.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catalog_course_service_test extends \advanced_testcase {
    /**
     * Create an approved framework owned by a catalog course with its outcomes.
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
     * * Outcome counts split course level from unit level by the ULO convention.
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
        $this->assertSame(
            5,
            (int) $row->unitoutcomecount,
            'A framework whose code ends in ULO holds unit-level outcomes.'
        );
    }

    /**
     * * Associations are counted, with the confirmed ones reported separately.
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
}
