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

use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\course_attainment_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\workflow;

/**
 * Tests for cohort outcome attainment on a course.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_outcomemap\local\service\course_attainment_service
 */
final class course_attainment_service_test extends \advanced_testcase {
    /**
     * Build a course with an approved instance and one banded calculation policy.
     *
     * @return array{0:\stdClass,1:int,2:int,3:int} Course, cinstid, policyid, low band id.
     */
    private function create_fixture(): array {
        global $DB;
        $now = time();
        $course = $this->getDataGenerator()->create_course();
        $catalogid = $DB->insert_record('local_outcomemap_course', (object) [
            'uuid' => uuid::generate(), 'code' => 'CAT' . random_string(4), 'name' => 'Catalog course',
            'description' => null, 'siskey' => null, 'status' => workflow::APPROVED,
            'createdby' => null, 'modifiedby' => null, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $cinstid = $DB->insert_record('local_outcomemap_cinst', (object) [
            'uuid' => uuid::generate(), 'courseid' => $catalogid, 'moodlecourseid' => $course->id,
            'periodcode' => '2026-T1', 'externalid' => null, 'status' => workflow::APPROVED,
            'confirmed' => 1, 'createdby' => null, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $policyid = $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => uuid::generate(), 'version' => 1, 'policytype' => 'calculation',
            'scopetype' => 'course_instance', 'scopeid' => $cinstid, 'name' => 'Test calculation',
            'configjson' => '{"minitems":1}', 'confighash' => hash('sha256', 'x'),
            'status' => workflow::APPROVED, 'effectivefrom' => $now - 86400, 'effectiveto' => null,
            'createdby' => null, 'approvedby' => null, 'timecreated' => $now, 'timemodified' => $now,
            'approvedat' => $now,
        ]);
        $lowband = $DB->insert_record('local_outcomemap_band', (object) [
            'policyid' => $policyid, 'code' => 'NOTMET', 'name' => 'Not met', 'description' => null,
            'minpercent' => null, 'mininclusive' => 1, 'maxpercent' => '80.0000000000',
            'maxinclusive' => 0, 'sortorder' => 0,
        ]);
        $DB->insert_record('local_outcomemap_band', (object) [
            'policyid' => $policyid, 'code' => 'MET', 'name' => 'Met', 'description' => null,
            'minpercent' => '80.0000000000', 'mininclusive' => 1, 'maxpercent' => null,
            'maxinclusive' => 1, 'sortorder' => 1,
        ]);
        return [$course, $cinstid, $policyid, $lowband];
    }

    /**
     * Create one approved outcome version and return its ID.
     *
     * @param string $fwcode Framework code.
     * @param string $code Outcome code.
     * @return int Outcome-version ID.
     */
    private function create_outcome(string $fwcode, string $code): int {
        global $DB;
        $now = time();
        $fw = $DB->get_record('local_outcomemap_fw', ['code' => $fwcode]);
        if (!$fw) {
            $fwid = $DB->insert_record('local_outcomemap_fw', (object) [
                'uuid' => uuid::generate(), 'code' => $fwcode, 'name' => $fwcode, 'description' => null,
                'ownertype' => 'institution', 'ownerid' => null, 'status' => workflow::APPROVED,
                'createdby' => null, 'modifiedby' => null, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        } else {
            $fwid = (int) $fw->id;
        }
        $itemid = $DB->insert_record('local_outcomemap_item', (object) [
            'uuid' => uuid::generate(), 'frameworkid' => $fwid, 'code' => $code,
            'status' => workflow::APPROVED, 'createdby' => null, 'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return (int) $DB->insert_record('local_outcomemap_itemver', (object) [
            'uuid' => uuid::generate(), 'itemid' => $itemid, 'version' => 1,
            'statement' => 'Outcome ' . $code, 'shortstatement' => 'Short ' . $code,
            'bloomlevel' => null, 'status' => workflow::APPROVED, 'effectivefrom' => $now - 86400,
            'effectiveto' => null, 'changereason' => null, 'createdby' => null, 'approvedby' => null,
            'timecreated' => $now, 'timemodified' => $now, 'approvedat' => $now,
        ]);
    }

    /**
     * Create an approved outcome in a framework the catalog course owns.
     *
     * Only these outcomes are in the course's own scope, so only these appear on
     * the report when nothing has been calculated for them yet.
     *
     * @param int $catalogid Catalog course ID owning the framework.
     * @param string $fwcode Framework code.
     * @param string $code Outcome code.
     * @return int Outcome-version ID.
     */
    private function create_course_outcome(int $catalogid, string $fwcode, string $code): int {
        global $DB;
        $now = time();
        $fwid = (int) ($DB->get_field(
            'local_outcomemap_fw',
            'id',
            ['code' => $fwcode, 'ownertype' => 'catalog_course', 'ownerid' => $catalogid]
        ) ?: 0);
        if (!$fwid) {
            $fwid = (int) $DB->insert_record('local_outcomemap_fw', (object) [
                'uuid' => uuid::generate(), 'code' => $fwcode, 'name' => $fwcode, 'description' => null,
                'ownertype' => 'catalog_course', 'ownerid' => $catalogid, 'status' => workflow::APPROVED,
                'createdby' => null, 'modifiedby' => null, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $itemid = $DB->insert_record('local_outcomemap_item', (object) [
            'uuid' => uuid::generate(), 'frameworkid' => $fwid, 'code' => $code,
            'status' => workflow::APPROVED, 'createdby' => null, 'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return (int) $DB->insert_record('local_outcomemap_itemver', (object) [
            'uuid' => uuid::generate(), 'itemid' => $itemid, 'version' => 1,
            'statement' => 'Course outcome ' . $code, 'shortstatement' => null,
            'bloomlevel' => null, 'status' => workflow::APPROVED, 'effectivefrom' => $now - 86400,
            'effectiveto' => null, 'changereason' => null, 'createdby' => null, 'approvedby' => null,
            'timecreated' => $now, 'timemodified' => $now, 'approvedat' => $now,
        ]);
    }

    /**
     * Store one course-scope result for a learner.
     *
     * @param int $cinstid Course instance ID.
     * @param int $userid Learner ID.
     * @param int $itemverid Outcome-version ID.
     * @param int $policyid Policy ID.
     * @param string|null $percentage Percentage, or null for no calculation.
     * @param int|null $bandid Band ID.
     * @param string $state Result state.
     * @param string $scopetype Result scope.
     * @return int The new result ID.
     */
    private function store_result(
        int $cinstid,
        int $userid,
        int $itemverid,
        int $policyid,
        ?string $percentage,
        ?int $bandid,
        string $state = 'calculated',
        string $scopetype = calculation_service::SCOPE_COURSE
    ): int {
        global $DB;
        $now = time();
        // The resultkey is unique with version, so each fixture row needs its own.
        return (int) $DB->insert_record('local_outcomemap_result', (object) [
            'uuid' => uuid::generate(), 'resultkey' => hash('sha256', uuid::generate()),
            'version' => 1, 'cinstid' => $cinstid, 'userid' => $userid,
            'scopetype' => $scopetype, 'scopeid' => $cinstid,
            'periodcode' => '2026-T1', 'itemverid' => $itemverid, 'policyid' => $policyid,
            'numerator' => '1.0000000000', 'denominator' => '1.0000000000',
            'percentage' => $percentage, 'distinctitems' => 1, 'bandid' => $bandid,
            'state' => $state, 'stale' => 0, 'algoversion' => 'outcomemap-v1',
            'inputhash' => hash('sha256', uuid::generate()), 'lineagejson' => '[]',
            'lineagehash' => hash('sha256', '[]'), 'supersededby' => null,
            'timecalculated' => $now, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Store one approved effective outcome relationship.
     *
     * @param int $sourceverid Source outcome-version ID.
     * @param int $targetverid Target outcome-version ID.
     * @param string $type Relationship type.
     * @return void
     */
    private function store_relation(int $sourceverid, int $targetverid, string $type): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_outcomemap_rel', (object) [
            'relationuuid' => uuid::generate(),
            'version' => 1,
            'sourceitemid' => $DB->get_field(
                'local_outcomemap_itemver',
                'itemid',
                ['id' => $sourceverid],
                MUST_EXIST
            ),
            'targetitemid' => $DB->get_field(
                'local_outcomemap_itemver',
                'itemid',
                ['id' => $targetverid],
                MUST_EXIST
            ),
            'type' => $type,
            'weight' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $now - 3600,
            'effectiveto' => null,
            'notes' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
    }

    /**
     * * Attainment counts learners per band and averages only calculated results.
     */
    public function test_summary_counts_bands_and_averages(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $cinstid, $policyid, $lowband] = $this->create_fixture();
        $metband = (int) $DB->get_field(
            'local_outcomemap_band',
            'id',
            ['policyid' => $policyid, 'code' => 'MET']
        );
        $itemverid = $this->create_outcome('TESTFW', 'U1');

        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $u3 = $this->getDataGenerator()->create_user();
        $this->store_result($cinstid, $u1->id, $itemverid, $policyid, '90.0000000000', $metband);
        $this->store_result($cinstid, $u2->id, $itemverid, $policyid, '70.0000000000', $lowband);
        // A learner with no calculation must count towards the cohort but not the average.
        $this->store_result($cinstid, $u3->id, $itemverid, $policyid, null, null, 'not_assessed');

        $summary = course_attainment_service::summary((int) $course->id);
        $this->assertTrue($summary->hasinstance);
        $this->assertSame(3, $summary->learners);
        $this->assertCount(1, $summary->rows);

        $row = $summary->rows[0];
        $this->assertSame('U1', $row->code);
        $this->assertSame(3, $row->learners);
        $this->assertSame(2, $row->calculated);
        $this->assertSame(1, $row->unassessed);
        $this->assertEqualsWithDelta(80.0, $row->average, 0.001, 'Average must ignore uncalculated rows.');
        $this->assertSame('NOTMET', $row->lowestband->code, 'The lowest band must sort first.');
        $this->assertSame(1, $row->lowestband->count);
    }

    /**
     * * Superseded results and other scopes never reach the cohort view.
     */
    public function test_summary_ignores_superseded_and_other_scopes(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $cinstid, $policyid, $lowband] = $this->create_fixture();
        $itemverid = $this->create_outcome('TESTFW2', 'U2');
        $user = $this->getDataGenerator()->create_user();

        // A quiz-attempt row, which is out of scope for a course figure, and an
        // older course-scope row superseded by it. supersededby is a foreign key,
        // so it has to point at a row that exists.
        $attemptrow = $this->store_result(
            $cinstid,
            $user->id,
            $itemverid,
            $policyid,
            '10.0000000000',
            $lowband,
            'calculated',
            'quiz_attempt'
        );
        $old = $this->store_result($cinstid, $user->id, $itemverid, $policyid, '50.0000000000', $lowband);
        $DB->set_field('local_outcomemap_result', 'supersededby', $attemptrow, ['id' => $old]);

        $summary = course_attainment_service::summary((int) $course->id);
        $this->assertSame([], $summary->rows);
        $this->assertSame(0, $summary->learners);
    }

    /**
     * The report exposes terminal ULO-to-CLO-to-PLO paths without claiming an
     * aligns_to path propagated attainment.
     */
    public function test_summary_includes_higher_level_alignment_paths(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $cinstid, $policyid, $lowband] = $this->create_fixture();
        $uloverid = $this->create_outcome('TEST-ULO', '1a');
        $cloverid = $this->create_outcome('TEST-CLO', '0a');
        $ploverid = $this->create_outcome('TEST-PLO', 'PLO1');
        $this->store_relation($uloverid, $cloverid, 'aligns_to');
        $this->store_relation($cloverid, $ploverid, 'aligns_to');
        $user = $this->getDataGenerator()->create_user();
        $this->store_result($cinstid, $user->id, $uloverid, $policyid, '75.0000000000', $lowband);

        $summary = course_attainment_service::summary((int) $course->id);

        $this->assertTrue($summary->hasalignmentpaths);
        $this->assertCount(1, $summary->rows);
        $this->assertCount(1, $summary->rows[0]->alignmentpaths);
        $path = $summary->rows[0]->alignmentpaths[0];
        $this->assertFalse($path->propagates);
        $this->assertSame(['0a', 'PLO1'], array_column($path->targets, 'code'));
        $this->assertSame(['TEST-CLO', 'TEST-PLO'], array_column($path->targets, 'frameworkcode'));
    }

    /**
     * * Measured rows are classified by the lowest band's share of the cohort.
     */
    public function test_summary_states_split_attention_from_attained(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $cinstid, $policyid, $lowband] = $this->create_fixture();
        $metband = (int) $DB->get_field(
            'local_outcomemap_band',
            'id',
            ['policyid' => $policyid, 'code' => 'MET']
        );
        $strong = $this->create_outcome('TESTFW3', 'S1');
        $weak = $this->create_outcome('TESTFW3', 'W1');

        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $this->store_result($cinstid, $u1->id, $strong, $policyid, '90.0000000000', $metband);
        $this->store_result($cinstid, $u2->id, $strong, $policyid, '95.0000000000', $metband);
        $this->store_result($cinstid, $u1->id, $weak, $policyid, '90.0000000000', $metband);
        $this->store_result($cinstid, $u2->id, $weak, $policyid, '40.0000000000', $lowband);

        $summary = course_attainment_service::summary((int) $course->id);
        $states = array_combine(
            array_column($summary->rows, 'code'),
            array_column($summary->rows, 'state')
        );
        $this->assertSame(course_attainment_service::STATE_ATTAINED, $states['S1']);
        $this->assertSame(
            course_attainment_service::STATE_ATTENTION,
            $states['W1'],
            'Half the assessed learners in the lowest band must flag the outcome.'
        );
        $this->assertSame(2, $summary->measured);
        $this->assertSame(1, $summary->counts[course_attainment_service::STATE_ATTENTION]);
        $this->assertEqualsWithDelta(
            78.75,
            $summary->average,
            0.001,
            'The headline average is the mean of the per-outcome cohort averages.'
        );

        // Nobody sits in the bottom band of the strong outcome, so there is no
        // lowest-band row to report and its share is zero rather than everything.
        $rows = array_combine(array_column($summary->rows, 'code'), $summary->rows);
        $this->assertNull(
            $rows['S1']->lowestband,
            'An outcome every learner passed has no result in the policy\'s bottom band.'
        );
        $this->assertSame(0.0, $rows['S1']->lowshare);
        $this->assertSame('NOTMET', $rows['W1']->lowestband->code);
        $this->assertEqualsWithDelta(0.5, $rows['W1']->lowshare, 0.001);
    }

    /**
     * An outcome the course owns but holds no result for still gets a row, and is
     * reported as never assessed when no assessing content maps to it.
     */
    public function test_summary_reports_outcomes_with_no_result(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $cinstid, $policyid, $lowband] = $this->create_fixture();
        $catalogid = (int) $DB->get_field('local_outcomemap_cinst', 'courseid', ['id' => $cinstid]);
        $measured = $this->create_course_outcome($catalogid, 'CAT-ULO', '1a');
        $this->create_course_outcome($catalogid, 'CAT-ULO', '1b');
        $user = $this->getDataGenerator()->create_user();
        $this->store_result($cinstid, $user->id, $measured, $policyid, '75.0000000000', $lowband);

        $summary = course_attainment_service::summary((int) $course->id);

        $this->assertSame(2, $summary->outcomes, 'An unmeasured course outcome is still a row.');
        $this->assertSame(1, $summary->measured);
        $this->assertTrue($summary->coverageknown);
        $rows = array_combine(array_column($summary->rows, 'code'), $summary->rows);
        $this->assertSame(course_attainment_service::STATE_ATTENTION, $rows['1a']->state);
        $this->assertSame(course_attainment_service::STATE_UNASSESSED, $rows['1b']->state);
        $this->assertSame(0, $rows['1b']->calculated);
        $this->assertNull($rows['1b']->average);
        $this->assertSame(1, $summary->counts[course_attainment_service::STATE_UNASSESSED]);
    }

    /**
     * An outcome with an approved assessing mapping is awaiting calculation
     * rather than never assessed, which is a different thing to fix.
     */
    public function test_summary_separates_pending_from_never_assessed(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $cinstid, , ] = $this->create_fixture();
        $catalogid = (int) $DB->get_field('local_outcomemap_cinst', 'courseid', ['id' => $cinstid]);
        $pending = $this->create_course_outcome($catalogid, 'CAT-ULO', '2a');
        $this->create_course_outcome($catalogid, 'CAT-ULO', '2b');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $now = time();
        $DB->insert_record('local_outcomemap_cmmap', (object) [
            'mappinguuid' => uuid::generate(), 'version' => 1, 'cinstid' => $cinstid,
            'cmid' => $page->cmid, 'itemverid' => $pending, 'role' => 'assesses',
            'weight' => null, 'priority' => 0, 'notes' => null, 'status' => workflow::APPROVED,
            'effectivefrom' => $now - 3600, 'effectiveto' => null, 'createdby' => null,
            'approvedby' => null, 'timecreated' => $now, 'timemodified' => $now, 'approvedat' => $now,
        ]);

        $summary = course_attainment_service::summary((int) $course->id);

        $rows = array_combine(array_column($summary->rows, 'code'), $summary->rows);
        $this->assertSame(course_attainment_service::STATE_PENDING, $rows['2a']->state);
        $this->assertTrue($rows['2a']->assessedcontent);
        $this->assertSame(course_attainment_service::STATE_UNASSESSED, $rows['2b']->state);
        $this->assertFalse($rows['2b']->assessedcontent);
    }

    /**
     * * A course with no approved confirmed instance reports that, not an empty table.
     */
    public function test_summary_without_a_course_instance(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $summary = course_attainment_service::summary((int) $course->id);
        $this->assertFalse($summary->hasinstance);
        $this->assertSame([], $summary->rows);
    }

    /**
     * * Viewing other learners' attainment requires the all-results capability.
     */
    public function test_summary_requires_viewallresults(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, , , ] = $this->create_fixture();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        course_attainment_service::summary((int) $course->id);
    }
}
