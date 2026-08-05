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

use local_outcomemap\local\service\attainment_report_service;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\workflow;

/**
 * Tests the page model behind the course outcome attainment report.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attainment_report_service_test extends \advanced_testcase {
    /** @var \stdClass Moodle course under report. */
    private $course;

    /** @var int Course instance ID. */
    private $cinstid;

    /** @var int Catalog course ID. */
    private $catalogid;

    /** @var int Program ID. */
    private $programid;

    /** @var int Calculation policy ID. */
    private $policyid;

    /** @var array<string,int> Band IDs keyed by code. */
    private $bands = [];

    /** @var \stdClass[] Learners. */
    private $learners = [];

    /**
     * Build a program, a catalog course, one Moodle course, and a banded policy.
     *
     * @return void
     */
    private function build_course(): void {
        global $DB;
        $now = time();
        $this->course = $this->getDataGenerator()->create_course();
        $this->programid = (int) $DB->insert_record('local_outcomemap_program', (object) [
            'uuid' => uuid::generate(), 'code' => 'PRG', 'name' => 'Test program',
            'description' => null, 'externalid' => null, 'programtype' => 'graduate',
            'credential' => 'degree', 'status' => workflow::APPROVED, 'createdby' => null,
            'modifiedby' => null, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $this->catalogid = $this->create_catalog_course('CAT1', 'Catalog course one');
        $this->cinstid = $this->create_instance($this->catalogid, (int) $this->course->id);

        $this->policyid = (int) $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => uuid::generate(), 'version' => 1,
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_COURSE_INSTANCE, 'scopeid' => $this->cinstid,
            'name' => 'Test calculation', 'configjson' => '{"minitems":1}',
            'confighash' => hash('sha256', 'calc'), 'status' => workflow::APPROVED,
            'effectivefrom' => $now - 86400, 'effectiveto' => null, 'createdby' => null,
            'approvedby' => null, 'timecreated' => $now, 'timemodified' => $now,
            'approvedat' => $now,
        ]);
        foreach ([['NOTMET', 'Not met', 0], ['MET', 'Met', 1], ['EXCEEDS', 'Exceeds', 2]] as $index => $band) {
            [$code, $name, $sortorder] = $band;
            $this->bands[$code] = (int) $DB->insert_record('local_outcomemap_band', (object) [
                'policyid' => $this->policyid, 'code' => $code, 'name' => $name,
                'description' => null, 'minpercent' => null, 'mininclusive' => 1,
                'maxpercent' => null, 'maxinclusive' => 1, 'sortorder' => $sortorder,
            ]);
            unset($index);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->learners[] = $this->getDataGenerator()->create_user();
        }
    }

    /**
     * Create an approved catalog course inside the test program.
     *
     * @param string $code Catalog code.
     * @param string $name Catalog name.
     * @return int Catalog course ID.
     */
    private function create_catalog_course(string $code, string $name): int {
        global $DB;
        $now = time();
        $catalogid = (int) $DB->insert_record('local_outcomemap_course', (object) [
            'uuid' => uuid::generate(), 'code' => $code, 'name' => $name, 'description' => null,
            'siskey' => null, 'status' => workflow::APPROVED, 'createdby' => null,
            'modifiedby' => null, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_outcomemap_progcourse', (object) [
            'uuid' => uuid::generate(), 'programid' => $this->programid, 'courseid' => $catalogid,
            'status' => workflow::APPROVED, 'effectivefrom' => $now - 86400, 'effectiveto' => null,
            'createdby' => null, 'approvedby' => null, 'timecreated' => $now,
            'timemodified' => $now, 'approvedat' => $now,
        ]);
        return $catalogid;
    }

    /**
     * Associate a Moodle course with a catalog course for the test period.
     *
     * @param int $catalogid Catalog course ID.
     * @param int $moodlecourseid Moodle course ID.
     * @return int Course instance ID.
     */
    private function create_instance(int $catalogid, int $moodlecourseid): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('local_outcomemap_cinst', (object) [
            'uuid' => uuid::generate(), 'courseid' => $catalogid,
            'moodlecourseid' => $moodlecourseid, 'periodcode' => '2026', 'externalid' => null,
            'status' => workflow::APPROVED, 'confirmed' => 1, 'createdby' => null,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Create an approved outcome and return its item and version IDs.
     *
     * @param string $fwcode Framework code.
     * @param string $ownertype Framework owner type.
     * @param int|null $ownerid Framework owner ID.
     * @param string $code Outcome code.
     * @return array{0:int,1:int} Item ID and outcome-version ID.
     */
    private function create_outcome(string $fwcode, string $ownertype, ?int $ownerid,
            string $code): array {
        global $DB;
        $now = time();
        $fwid = (int) ($DB->get_field('local_outcomemap_fw', 'id', ['code' => $fwcode]) ?: 0);
        if (!$fwid) {
            $fwid = (int) $DB->insert_record('local_outcomemap_fw', (object) [
                'uuid' => uuid::generate(), 'code' => $fwcode, 'name' => $fwcode . ' framework',
                'description' => null, 'ownertype' => $ownertype, 'ownerid' => $ownerid,
                'status' => workflow::APPROVED, 'createdby' => null, 'modifiedby' => null,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $itemid = (int) $DB->insert_record('local_outcomemap_item', (object) [
            'uuid' => uuid::generate(), 'frameworkid' => $fwid, 'code' => $code,
            'status' => workflow::APPROVED, 'createdby' => null, 'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $verid = (int) $DB->insert_record('local_outcomemap_itemver', (object) [
            'uuid' => uuid::generate(), 'itemid' => $itemid, 'version' => 1,
            'statement' => 'Statement for ' . $code, 'shortstatement' => null, 'bloomlevel' => null,
            'status' => workflow::APPROVED, 'effectivefrom' => $now - 86400, 'effectiveto' => null,
            'changereason' => null, 'createdby' => null, 'approvedby' => null,
            'timecreated' => $now, 'timemodified' => $now, 'approvedat' => $now,
        ]);
        return [$itemid, $verid];
    }

    /**
     * Link one outcome to the outcome above it.
     *
     * @param int $sourceitemid Source outcome item ID.
     * @param int $targetitemid Target outcome item ID.
     * @return void
     */
    private function align(int $sourceitemid, int $targetitemid): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_outcomemap_rel', (object) [
            'relationuuid' => uuid::generate(), 'version' => 1, 'sourceitemid' => $sourceitemid,
            'targetitemid' => $targetitemid, 'type' => relation_service::CONTRIBUTES_TO,
            'weight' => null, 'status' => workflow::APPROVED, 'effectivefrom' => $now - 3600,
            'effectiveto' => null, 'notes' => null, 'createdby' => null, 'approvedby' => null,
            'timecreated' => $now, 'timemodified' => $now, 'approvedat' => $now,
        ]);
    }

    /**
     * Store one course-scope result for a learner.
     *
     * @param int $userid Learner ID.
     * @param int $itemverid Outcome-version ID.
     * @param string|null $percentage Stored percentage, or null when uncalculated.
     * @param string|null $bandcode Band code, or null.
     * @param int|null $cinstid Course instance; defaults to the course under report.
     * @return void
     */
    private function store_result(int $userid, int $itemverid, ?string $percentage,
            ?string $bandcode, ?int $cinstid = null): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_outcomemap_result', (object) [
            'uuid' => uuid::generate(), 'resultkey' => hash('sha256', uuid::generate()),
            'version' => 1, 'cinstid' => $cinstid ?? $this->cinstid, 'userid' => $userid,
            'scopetype' => calculation_service::SCOPE_COURSE, 'scopeid' => $cinstid ?? $this->cinstid,
            'periodcode' => '2026', 'itemverid' => $itemverid, 'policyid' => $this->policyid,
            'numerator' => '1.0000000000', 'denominator' => '1.0000000000',
            'percentage' => $percentage, 'distinctitems' => 1,
            'bandid' => $bandcode === null ? null : $this->bands[$bandcode],
            'state' => $percentage === null ? 'insufficient_evidence' : 'calculated', 'stale' => 0,
            'algoversion' => 'outcomemap-v1', 'inputhash' => hash('sha256', uuid::generate()),
            'lineagejson' => '[]', 'lineagehash' => hash('sha256', '[]'), 'supersededby' => null,
            'timecalculated' => $now, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Approve an accreditation policy for the test program.
     *
     * @param int $floor Minimum cohort size.
     * @param string $criterion Achievement criterion percentage.
     * @param string $benchmark Aggregate benchmark percentage.
     * @return void
     */
    private function approve_accreditation_policy(int $floor, string $criterion,
            string $benchmark): void {
        global $DB;
        $now = time();
        $config = [
            'mincohortsize' => $floor,
            'populationsource' => 'active_enrolments_at_freeze',
            'retentionbasis' => 'institutional_record_anonymised',
            'achievementminpercent' => $criterion,
            'benchmarkpercent' => $benchmark,
        ];
        $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => uuid::generate(), 'version' => 3,
            'policytype' => policy_service::TYPE_ACCREDITATION,
            'scopetype' => policy_service::SCOPE_PROGRAM, 'scopeid' => $this->programid,
            'name' => 'Programme reporting', 'configjson' => json_encode($config),
            'confighash' => hash('sha256', 'accred'), 'status' => workflow::APPROVED,
            'effectivefrom' => $now - 86400, 'effectiveto' => null, 'createdby' => null,
            'approvedby' => null, 'timecreated' => $now, 'timemodified' => $now,
            'approvedat' => $now,
        ]);
    }

    /**
     * Build a three-level curriculum: two unit outcomes, one course, one program.
     *
     * @return array<string,int> Outcome-version IDs keyed by code.
     */
    private function build_hierarchy(): array {
        [$ploitem, $plover] = $this->create_outcome('PRG-PLO', framework_service::OWNER_PROGRAM,
            $this->programid, 'PLO1');
        [$cloitem, $clover] = $this->create_outcome('CAT1-CLO', framework_service::OWNER_COURSE,
            $this->catalogid, '0a');
        [$ulo1item, $ulo1ver] = $this->create_outcome('CAT1-ULO', framework_service::OWNER_COURSE,
            $this->catalogid, '1a');
        [$ulo2item, $ulo2ver] = $this->create_outcome('CAT1-ULO', framework_service::OWNER_COURSE,
            $this->catalogid, '1b');
        $this->align($cloitem, $ploitem);
        $this->align($ulo1item, $cloitem);
        $this->align($ulo2item, $cloitem);
        return ['PLO1' => $plover, '0a' => $clover, '1a' => $ulo1ver, '1b' => $ulo2ver];
    }

    /**
     * Find one node in the report by outcome code.
     *
     * @param \stdClass $report Report model.
     * @param string $code Outcome code.
     * @return \stdClass
     */
    private function node(\stdClass $report, string $code): \stdClass {
        foreach ($report->nodes as $node) {
            if ($node->code === $code) {
                return $node;
            }
        }
        $this->fail('No node for outcome ' . $code);
    }

    /**
     * Levels come from the alignment graph, deepest chain last, top level first.
     */
    public function test_levels_are_derived_from_the_alignment_graph(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        $this->store_result((int) $this->learners[0]->id, $versions['PLO1'], '90.0000000000', 'MET');

        $report = attainment_report_service::report((int) $this->course->id);

        $this->assertCount(3, $report->tiers, 'Three authored levels must produce three tiers.');
        $this->assertSame(['PLO1'], array_map(
            static fn(\stdClass $n): string => $n->code, $report->tiers[0]->nodes));
        $this->assertSame(['0a'], array_map(
            static fn(\stdClass $n): string => $n->code, $report->tiers[1]->nodes));
        $this->assertSame(['1a', '1b'], array_map(
            static fn(\stdClass $n): string => $n->code, $report->tiers[2]->nodes));
        $this->assertSame($report->tiers[0], $report->toptier);
    }

    /**
     * An outcome nothing has evidenced is still carried, so its level is not empty.
     */
    public function test_alignment_targets_appear_without_any_result(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        // Only the deepest level has a result; the two above it are inferred.
        $this->store_result((int) $this->learners[0]->id, $versions['1a'], '90.0000000000', 'MET');

        $report = attainment_report_service::report((int) $this->course->id);

        $plo = $this->node($report, 'PLO1');
        $this->assertSame(0, $plo->stats[attainment_report_service::COHORT_ALL]->graded);
        $this->assertNull($plo->stats[attainment_report_service::COHORT_ALL]->metpct,
            'An outcome with no stored result must report no rate rather than zero.');
        $this->assertCount(3, $report->tiers);
    }

    /**
     * The level rate is a rate over learners, not an average of outcome averages.
     */
    public function test_level_rate_is_weighted_by_learners(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        // 1a: four learners, one reached the standard. 1b: one learner, who did.
        foreach ([0, 1, 2] as $index) {
            $this->store_result((int) $this->learners[$index]->id, $versions['1a'],
                '40.0000000000', 'NOTMET');
        }
        $this->store_result((int) $this->learners[3]->id, $versions['1a'], '95.0000000000', 'EXCEEDS');
        $this->store_result((int) $this->learners[4]->id, $versions['1b'], '95.0000000000', 'EXCEEDS');

        $report = attainment_report_service::report((int) $this->course->id);
        $base = $report->tiers[2]->stats[attainment_report_service::COHORT_ALL];

        // Two of five learner-results reached the standard: 40%. An unweighted
        // mean of the two outcome rates (25% and 100%) would say 62.5%.
        $this->assertSame(5, $base->judged);
        $this->assertSame(2, $base->met);
        $this->assertEqualsWithDelta(40.0, $base->metpct, 0.001);
    }

    /**
     * With an approved policy the criterion decides; without one, the band does.
     */
    public function test_achievement_criterion_comes_from_the_accreditation_policy(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        // Banded as "Met", but only 65% — below a 70% criterion.
        $this->store_result((int) $this->learners[0]->id, $versions['1a'], '65.0000000000', 'MET');

        $without = attainment_report_service::report((int) $this->course->id);
        $this->assertFalse($without->policy->available);
        $this->assertEqualsWithDelta(100.0,
            $this->node($without, '1a')->stats[attainment_report_service::COHORT_ALL]->metpct, 0.001,
            'With no approved criterion, clearing the lowest band is the standard.');

        $this->approve_accreditation_policy(3, '70.0000000000', '80.0000000000');
        $with = attainment_report_service::report((int) $this->course->id);

        $this->assertTrue($with->policy->available);
        $this->assertEqualsWithDelta(70.0, $with->policy->criterion, 0.001);
        $this->assertEqualsWithDelta(80.0, $with->policy->target, 0.001);
        $this->assertEqualsWithDelta(0.0,
            $this->node($with, '1a')->stats[attainment_report_service::COHORT_ALL]->metpct, 0.001,
            'The approved criterion must override the band the calculation policy assigned.');
    }

    /**
     * The accreditation lens withholds thin results everywhere they appear.
     */
    public function test_accreditation_lens_withholds_results_under_the_floor(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        $this->approve_accreditation_policy(4, '70.0000000000', '80.0000000000');
        // Two learners: measured, but under a floor of four.
        $this->store_result((int) $this->learners[0]->id, $versions['1a'], '95.0000000000', 'EXCEEDS');
        $this->store_result((int) $this->learners[1]->id, $versions['1a'], '95.0000000000', 'EXCEEDS');

        $educator = attainment_report_service::report((int) $this->course->id,
            attainment_report_service::COHORT_ALL, attainment_report_service::LENS_EDUCATOR);
        $stats = $this->node($educator, '1a')->stats[attainment_report_service::COHORT_ALL];
        $this->assertFalse(attainment_report_service::is_withheld($educator, $stats),
            'The educator lens shows a thin result with its sample size.');

        $reviewer = attainment_report_service::report((int) $this->course->id,
            attainment_report_service::COHORT_ALL, attainment_report_service::LENS_ACCREDITATION);
        $stats = $this->node($reviewer, '1a')->stats[attainment_report_service::COHORT_ALL];
        $this->assertTrue(attainment_report_service::is_withheld($reviewer, $stats));
        $this->assertCount(1, $reviewer->gaps->thin);
    }

    /**
     * With no approved policy there is no floor to enforce, so no reviewer lens.
     */
    public function test_accreditation_lens_is_not_offered_without_a_policy(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        $this->store_result((int) $this->learners[0]->id, $versions['1a'], '95.0000000000', 'EXCEEDS');

        $report = attainment_report_service::report((int) $this->course->id,
            attainment_report_service::COHORT_ALL, attainment_report_service::LENS_ACCREDITATION);

        $this->assertNotContains(attainment_report_service::LENS_ACCREDITATION, $report->lenses);
        $this->assertSame(attainment_report_service::LENS_EDUCATOR, $report->lens,
            'A lens the data cannot support must fall back rather than pretend.');
        $this->assertNull($report->policy->floor);
    }

    /**
     * Learners split on the pass mark the teacher set on the course grade item.
     */
    public function test_cohort_split_uses_the_course_grade_pass_mark(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();

        // The course grade item is created lazily, so ask for it rather than
        // assuming the generator left one behind.
        $courseitem = \grade_item::fetch_course_item((int) $this->course->id);
        $item = $DB->get_record('grade_items', ['id' => $courseitem->id], '*', MUST_EXIST);
        $item->gradepass = 80;
        $item->grademax = 100;
        $DB->update_record('grade_items', $item);
        foreach ([[0, 90.0], [1, 85.0], [2, 40.0]] as [$index, $grade]) {
            $DB->insert_record('grade_grades', (object) [
                'itemid' => $item->id, 'userid' => $this->learners[$index]->id,
                'rawgrademax' => 100, 'rawgrademin' => 0, 'finalgrade' => $grade,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }
        // Completers do well, the non-completer does not.
        $this->store_result((int) $this->learners[0]->id, $versions['1a'], '95.0000000000', 'EXCEEDS');
        $this->store_result((int) $this->learners[1]->id, $versions['1a'], '95.0000000000', 'EXCEEDS');
        $this->store_result((int) $this->learners[2]->id, $versions['1a'], '30.0000000000', 'NOTMET');

        $report = attainment_report_service::report((int) $this->course->id);

        $this->assertSame('gradepass', $report->cohortrule);
        $this->assertEqualsWithDelta(80.0, $report->cohortrulevalue, 0.001);
        $this->assertSame(2, $report->cohortcounts[attainment_report_service::COHORT_COMPLETED]);
        $this->assertSame(1, $report->cohortcounts[attainment_report_service::COHORT_NOTCOMPLETED]);

        $node = $this->node($report, '1a');
        $this->assertEqualsWithDelta(100.0,
            $node->stats[attainment_report_service::COHORT_COMPLETED]->metpct, 0.001);
        $this->assertEqualsWithDelta(0.0,
            $node->stats[attainment_report_service::COHORT_NOTCOMPLETED]->metpct, 0.001);
    }

    /**
     * Without completion or a pass mark the split is unavailable, not invented.
     */
    public function test_cohort_split_is_absent_when_the_course_defines_no_standard(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        $this->store_result((int) $this->learners[0]->id, $versions['1a'], '95.0000000000', 'EXCEEDS');

        $report = attainment_report_service::report((int) $this->course->id,
            attainment_report_service::COHORT_COMPLETED);

        $this->assertNull($report->cohortrule);
        $this->assertSame(attainment_report_service::COHORT_ALL, $report->cohort);
    }

    /**
     * A claim carried by outcomes that produced nothing is reported as hollow.
     */
    public function test_gaps_report_a_claim_with_no_evidence_underneath_it(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        // The course outcome has a result; neither unit outcome underneath does.
        $this->store_result((int) $this->learners[0]->id, $versions['0a'], '90.0000000000', 'MET');

        $report = attainment_report_service::report((int) $this->course->id);

        $this->assertCount(1, $report->gaps->hollow);
        $this->assertSame('0a', $report->gaps->hollow[0]->node->code);
        $this->assertCount(2, $report->gaps->hollow[0]->missing);
        $keys = array_map(static fn(\stdClass $f): string => $f->key, $report->priorities);
        $this->assertContains('hollowclaim', $keys,
            'A hollow claim is a finding, not merely a row with a blank cell.');
    }

    /**
     * The rollup shows every course in the programme that claims the top level.
     */
    public function test_rollup_covers_sibling_courses_in_the_same_programme(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        $this->store_result((int) $this->learners[0]->id, $versions['PLO1'], '90.0000000000', 'MET');

        $sibling = $this->getDataGenerator()->create_course();
        $siblingcatalog = $this->create_catalog_course('CAT2', 'Catalog course two');
        $siblingcinst = $this->create_instance($siblingcatalog, (int) $sibling->id);
        $this->store_result((int) $this->learners[1]->id, $versions['PLO1'], '30.0000000000',
            'NOTMET', $siblingcinst);

        $report = attainment_report_service::report((int) $this->course->id);

        $this->assertTrue($report->rollup->available);
        $this->assertCount(1, $report->rollup->outcomes);
        $codes = array_map(static fn(\stdClass $c): string => $c->code, $report->rollup->courses);
        sort($codes);
        $this->assertSame(['CAT1', 'CAT2'], $codes);
        $rows = [];
        foreach ($report->rollup->courses as $rollupcourse) {
            $rows[$rollupcourse->code] = $rollupcourse;
        }
        $this->assertTrue($rows['CAT1']->current);
        $this->assertFalse($rows['CAT2']->current);
        $itemid = $report->rollup->outcomes[0]->itemid;
        $this->assertEqualsWithDelta(100.0, $rows['CAT1']->cells[$itemid]->metpct, 0.001);
        $this->assertEqualsWithDelta(0.0, $rows['CAT2']->cells[$itemid]->metpct, 0.001);
    }

    /**
     * A learner row with no percentage is awaiting calculation, not a failure.
     */
    public function test_uncalculated_results_are_not_counted_as_missed(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        $this->store_result((int) $this->learners[0]->id, $versions['1a'], '95.0000000000', 'EXCEEDS');
        $this->store_result((int) $this->learners[1]->id, $versions['1a'], null, null);

        $report = attainment_report_service::report((int) $this->course->id);
        $stats = $this->node($report, '1a')->stats[attainment_report_service::COHORT_ALL];

        $this->assertSame(2, $stats->learners);
        $this->assertSame(1, $stats->graded);
        $this->assertSame(1, $stats->judged);
        $this->assertEqualsWithDelta(100.0, $stats->metpct, 0.001);
    }

    /**
     * A legacy policy that no longer normalises degrades the page, not kills it.
     *
     * The achievement and benchmark percentages became required after the policy
     * type shipped, and an approved policy cannot be edited in place, so sites
     * hold approved accreditation policies whose stored configuration omits
     * them. Reading one must not take the whole report down with it.
     */
    public function test_unreadable_legacy_policy_degrades_instead_of_failing(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->build_course();
        $versions = $this->build_hierarchy();
        $this->store_result((int) $this->learners[0]->id, $versions['1a'], '95.0000000000', 'EXCEEDS');

        $now = time();
        $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => uuid::generate(), 'version' => 1,
            'policytype' => policy_service::TYPE_ACCREDITATION,
            'scopetype' => policy_service::SCOPE_PROGRAM, 'scopeid' => $this->programid,
            'name' => 'Legacy accreditation policy',
            // Exactly the shape an older release wrote: no achievement criterion
            // and no benchmark, both of which normalisation now demands.
            'configjson' => json_encode([
                'mincohortsize' => 5,
                'populationsource' => 'active_enrolments_at_freeze',
                'retentionbasis' => 'institutional_record_anonymised',
                'aggregationmethod' => 'sum_numerators_denominators',
                'correctionmethod' => 'new_snapshot_version',
            ]),
            'confighash' => hash('sha256', 'legacy'), 'status' => workflow::APPROVED,
            'effectivefrom' => $now - 86400, 'effectiveto' => null, 'createdby' => null,
            'approvedby' => null, 'timecreated' => $now, 'timemodified' => $now,
            'approvedat' => $now,
        ]);

        $report = attainment_report_service::report((int) $this->course->id);

        $this->assertTrue($report->hasinstance, 'The report must still be produced.');
        $this->assertFalse($report->policy->available);
        $this->assertTrue($report->policy->unreadable,
            'A policy that exists but cannot be applied is not the same as no policy.');
        $this->assertSame('achievementminpercent', $report->policy->problemfield);
        $this->assertNull($report->policy->target);
        $this->assertNull($report->policy->floor);
        $this->assertNotContains(attainment_report_service::LENS_ACCREDITATION, $report->lenses,
            'No floor can be enforced, so no submission lens may be offered.');
        // The attainment figures do not depend on the policy, so they stand.
        $this->assertEqualsWithDelta(100.0,
            $this->node($report, '1a')->stats[attainment_report_service::COHORT_ALL]->metpct, 0.001);
    }

    /**
     * A course with no approved confirmed instance reports that, and nothing else.
     */
    public function test_course_without_an_instance_reports_no_instance(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $report = attainment_report_service::report((int) $course->id);

        $this->assertFalse($report->hasinstance);
    }
}
