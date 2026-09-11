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
use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\coverage_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\local\workflow;

/**
 * Tests for the course coverage projection.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_outcomemap\local\service\coverage_service
 */
final class coverage_service_test extends \advanced_testcase {
    /**
     * Create an approved outcome in an approved framework owned by a catalog course.
     *
     * @param int $catalogid Catalog course id.
     * @param string $fwcode Framework code.
     * @param string $code Outcome code.
     * @return array{0:int,1:int} Item id and version id.
     */
    private function outcome(int $catalogid, string $fwcode, string $code): array {
        global $DB;
        $frameworkid = (int) $DB->get_field('local_outcomemap_fw', 'id', ['code' => $fwcode]);
        if (!$frameworkid) {
            $frameworkid = framework_service::create([
                'code' => $fwcode,
                'name' => 'Framework ' . $fwcode,
                'ownertype' => framework_service::OWNER_COURSE,
                'ownerid' => $catalogid,
            ]);
            framework_service::submit_for_review($frameworkid);
        }
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => $code,
            'statement' => 'Outcome ' . $fwcode . '.' . $code,
            'effectivefrom' => 1704067200,
        ]);
        $versionid = (int) $DB->get_field('local_outcomemap_itemver', 'id', ['itemid' => $itemid], MUST_EXIST);
        outcome_service::submit_for_review($versionid);
        return [$itemid, $versionid];
    }

    /**
     * * A course outcome nothing maps to is covered through the unit outcomes aligned to it.
     */
    public function test_course_outcome_inherits_coverage_from_aligned_unit_outcomes(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $catalogid = catalog_course_service::create(['code' => 'COV601', 'name' => 'Coverage course']);
        catalog_course_service::submit_for_review($catalogid);
        $cinstid = course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => 'COV601',
        ]);
        course_instance_service::submit_for_review($cinstid);
        // Without independent approval, submission already finalizes and confirms.
        if (!$DB->get_field('local_outcomemap_cinst', 'confirmed', ['id' => $cinstid])) {
            course_instance_service::confirm($cinstid);
        }

        [$cloid, $cloversion] = $this->outcome($catalogid, 'COV601-CLO', '0a');
        [$clo2id, $clo2version] = $this->outcome($catalogid, 'COV601-CLO', '0b');
        [$uloid, $uloversion] = $this->outcome($catalogid, 'COV601-ULO', '1a');
        [$ulo2id, $ulo2version] = $this->outcome($catalogid, 'COV601-ULO', '1b');
        foreach ([[$uloid, $cloid, 1706745600], [$ulo2id, $cloid, 1704067200]] as [$source, $target, $from]) {
            $relationid = relation_service::create([
                'sourceitemid' => $source,
                'targetitemid' => $target,
                'type' => relation_service::ALIGNS_TO,
                'effectivefrom' => $from,
            ]);
            relation_service::submit_for_review($relationid);
        }
        $this->assertSame(workflow::APPROVED, $DB->get_field('local_outcomemap_item', 'status', ['id' => $cloid]));

        // Only the first unit outcome is taught by anything.
        $mappingid = content_mapping_service::create_course_module([
            'cinstid' => $cinstid,
            'cmid' => $cm->id,
            'itemverid' => $uloversion,
            'role' => content_mapping_service::ROLE_TEACHES,
            'effectivefrom' => 1704067200,
        ]);
        content_mapping_service::submit_for_review(content_mapping_service::TARGET_MODULE, $mappingid);

        $beforealignment = coverage_service::matrix($course->id, 1705276800);
        $this->assertSame(
            coverage_service::STATUS_NONE,
            coverage_service::row_status($beforealignment[$cloversion]),
            'Coverage must not inherit through a future alignment.'
        );

        $matrix = coverage_service::matrix($course->id);
        $this->assertArrayHasKey($cloversion, $matrix);

        // The unit outcome is covered directly, and only by teaching content.
        $this->assertSame(coverage_service::STATUS_TAUGHT, coverage_service::row_status($matrix[$uloversion]));
        $this->assertSame([], $matrix[$uloversion]->inheritedfrom);

        // Its course outcome has no mapping of its own but is covered through it.
        $clo = $matrix[$cloversion];
        $this->assertSame([], $clo->modules);
        $this->assertSame(coverage_service::STATUS_INHERITED, coverage_service::row_status($clo));
        $this->assertSame(['COV601-ULO.1a'], array_map(static fn($e) => $e->label, $clo->inheritedfrom));
        $this->assertFalse($clo->inheritedassessed, 'Teaching-only unit coverage must not read as assessed.');

        // A course outcome whose aligned unit outcomes are all unmapped stays uncovered,
        // and an unmapped unit outcome inherits nothing.
        $this->assertSame(coverage_service::STATUS_NONE, coverage_service::row_status($matrix[$clo2version]));
        $this->assertSame(coverage_service::STATUS_NONE, coverage_service::row_status($matrix[$ulo2version]));

        // Assessing content on a second unit outcome makes the inherited coverage assessed.
        $assessid = content_mapping_service::create_course_module([
            'cinstid' => $cinstid,
            'cmid' => $cm->id,
            'itemverid' => $ulo2version,
            'role' => content_mapping_service::ROLE_ASSESSES,
            'weight' => '1.0000000000',
            'effectivefrom' => 1704067200,
        ]);
        content_mapping_service::submit_for_review(content_mapping_service::TARGET_MODULE, $assessid);
        $clo = coverage_service::matrix($course->id)[$cloversion];
        $this->assertSame(coverage_service::STATUS_INHERITED, coverage_service::row_status($clo));
        $this->assertTrue($clo->inheritedassessed);
        $this->assertSame(
            ['COV601-ULO.1a', 'COV601-ULO.1b'],
            array_map(static fn($e) => $e->label, $clo->inheritedfrom)
        );
    }
}
