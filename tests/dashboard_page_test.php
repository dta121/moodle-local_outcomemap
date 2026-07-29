<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap;

use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\coverage_service;
use local_outcomemap\local\service\dashboard_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\program_course_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\service\question_mapping_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\output\dashboard_page;

/**
 * Tests the dashboard readiness signals and page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dashboard_page_test extends \advanced_testcase {
    use \local_outcomemap\tests\moodle_compat_trait;

    /** @var int Effective start shared by every governed record in a fixture. */
    private const EFFECTIVE_FROM = 1704067200;

    /**
     * Create an approved framework with outcomes owned by a catalog course.
     *
     * @param int $catalogid Catalog course ID.
     * @param string $code Framework code.
     * @param int $outcomes How many outcomes to create.
     * @return int[] Outcome-version IDs in creation order.
     */
    private function add_course_outcomes(int $catalogid, string $code, int $outcomes): array {
        global $DB;
        $frameworkid = framework_service::create([
            'code' => $code,
            'name' => 'Framework ' . $code,
            'ownertype' => framework_service::OWNER_COURSE,
            'ownerid' => $catalogid,
        ]);
        framework_service::submit_for_review($frameworkid);
        $versionids = [];
        for ($index = 1; $index <= $outcomes; $index++) {
            $outcomeid = outcome_service::create([
                'frameworkid' => $frameworkid,
                'code' => $code . '.' . $index,
                'statement' => 'Outcome ' . $code . '.' . $index,
                'effectivefrom' => self::EFFECTIVE_FROM,
            ]);
            $versionid = (int) $DB->get_field('local_outcomemap_itemver', 'id',
                ['itemid' => $outcomeid], IGNORE_MULTIPLE);
            // A mapping may only bind to an approved version, and the dashboard
            // only reports approved outcomes, so the fixture takes each version
            // through the submission boundary.
            outcome_service::submit_for_review($versionid);
            $versionids[] = $versionid;
        }
        return $versionids;
    }

    /**
     * Create an approved program-owned framework with one outcome.
     *
     * @param int $programid Program ID.
     * @param string $code Framework code.
     * @return int Outcome ID, which relations target.
     */
    private function add_program_outcome(int $programid, string $code): int {
        global $DB;
        $frameworkid = framework_service::create([
            'code' => $code,
            'name' => 'Framework ' . $code,
            'ownertype' => framework_service::OWNER_PROGRAM,
            'ownerid' => $programid,
        ]);
        framework_service::submit_for_review($frameworkid);
        $outcomeid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => $code . '.1',
            'statement' => 'Program outcome ' . $code,
            'effectivefrom' => self::EFFECTIVE_FROM,
        ]);
        outcome_service::submit_for_review((int) $DB->get_field('local_outcomemap_itemver', 'id',
            ['itemid' => $outcomeid], IGNORE_MULTIPLE));
        return $outcomeid;
    }

    /**
     * Map one course module to an outcome version and approve it.
     *
     * @param int $cinstid Course-instance ID.
     * @param int $cmid Course-module ID.
     * @param int $itemverid Outcome-version ID.
     * @param string $role Mapping role.
     * @return void
     */
    private function map_module(int $cinstid, int $cmid, int $itemverid, string $role): void {
        $data = [
            'cinstid' => $cinstid,
            'cmid' => $cmid,
            'itemverid' => $itemverid,
            'role' => $role,
            'effectivefrom' => self::EFFECTIVE_FROM,
        ];
        if ($role === content_mapping_service::ROLE_ASSESSES) {
            $data['weight'] = '1.0000000000';
        }
        $mappingid = content_mapping_service::create_course_module($data);
        content_mapping_service::submit_for_review(content_mapping_service::TARGET_MODULE, $mappingid);
    }

    /**
     * Build a program whose single delivery covers its outcomes unevenly.
     *
     * Three course outcomes are taught and assessed, one is taught only, and one
     * has nothing mapped, so every coverage status the dashboard reports on is
     * represented by exactly one outcome.
     *
     * @return array Fixture identifiers.
     */
    private function create_uneven_coverage_fixture(): array {
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $programid = program_service::create([
            'code' => 'MBA',
            'name' => 'Master of Business Administration',
            'programtype' => program_service::TYPE_GRADUATE,
        ]);
        $catalogid = catalog_course_service::create(['code' => 'MBA601', 'name' => 'Financial Management']);
        program_course_service::create([
            'programid' => $programid,
            'courseid' => $catalogid,
            'effectivefrom' => self::EFFECTIVE_FROM,
        ]);
        $course = $this->getDataGenerator()->create_course();
        $cinstid = course_instance_service::create_confirmed([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-SP',
        ]);
        $versionids = $this->add_course_outcomes($catalogid, 'MBA601-CLO', 5);
        $teaching = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $assessing = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        // Three outcomes complete, one taught only, one untouched.
        foreach ([0, 1, 2] as $index) {
            $this->map_module($cinstid, $teaching->cmid, $versionids[$index],
                content_mapping_service::ROLE_TEACHES);
            $this->map_module($cinstid, $assessing->cmid, $versionids[$index],
                content_mapping_service::ROLE_ASSESSES);
        }
        $this->map_module($cinstid, $teaching->cmid, $versionids[3],
            content_mapping_service::ROLE_TEACHES);

        return [
            'programid' => $programid,
            'catalogid' => $catalogid,
            'cinstid' => $cinstid,
            'courseid' => (int) $course->id,
            'assessingquizid' => (int) $assessing->id,
            'versionids' => $versionids,
        ];
    }

    /**
     * Export the page context.
     *
     * @return array
     */
    private function export(): array {
        global $PAGE;
        return (new dashboard_page())->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Coverage gaps are counted with the same rule the coverage page applies.
     */
    public function test_coverage_gaps_split_no_content_from_never_assessed(): void {
        $this->resetAfterTest(true);
        $this->create_uneven_coverage_fixture();

        $summary = dashboard_service::summary();
        $this->assertSame(1, $summary['nocontent'],
            'One outcome has no mapping of any role.');
        $this->assertSame(1, $summary['taughtnotassessed'],
            'One outcome has teaching content but no assessing mapping.');
    }

    /**
     * Readiness is the share of in-scope outcomes that are taught and assessed.
     */
    public function test_program_readiness_reports_completed_share(): void {
        $this->resetAfterTest(true);
        $fixture = $this->create_uneven_coverage_fixture();
        $this->add_program_outcome($fixture['programid'], 'MBA-PLO');

        $summary = dashboard_service::summary();
        $this->assertCount(1, $summary['programs']);
        $program = $summary['programs'][0];
        $this->assertSame(5, $program['inscope']);
        $this->assertSame(3, $program['complete']);
        $this->assertSame(60, $program['percent']);
        $this->assertSame('gaps', $program['state']);
    }

    /**
     * An approved assessed question mapping counts as assessment coverage.
     */
    public function test_question_mapping_counts_towards_readiness(): void {
        global $DB;
        $this->resetAfterTest(true);
        $fixture = $this->create_uneven_coverage_fixture();
        $this->add_program_outcome($fixture['programid'], 'MBA-PLO');

        // Replace one activity-level assessment mapping with the more precise
        // question-level mapping used by proctored finals.
        $DB->delete_records('local_outcomemap_cmmap', [
            'cinstid' => $fixture['cinstid'],
            'itemverid' => $fixture['versionids'][0],
            'role' => content_mapping_service::ROLE_ASSESSES,
        ]);
        $quiz = $DB->get_record('quiz', ['id' => $fixture['assessingquizid']], '*', MUST_EXIST);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => $this->question_bank_contextid(get_course($fixture['courseid'])),
        ]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz, 0, 1);
        $questionmappingid = question_mapping_service::create([
            'questionversionid' => $question->versionid,
            'itemverid' => $fixture['versionids'][0],
            'role' => content_mapping_service::ROLE_ASSESSES,
            'weight' => '1',
            'effectivefrom' => self::EFFECTIVE_FROM,
        ]);
        question_mapping_service::submit_for_review($questionmappingid);

        $matrix = coverage_service::matrix($fixture['courseid']);
        $this->assertSame(coverage_service::STATUS_FULL,
            coverage_service::row_status($matrix[$fixture['versionids'][0]]));
        $this->assertCount(1, $matrix[$fixture['versionids'][0]]->questions);

        $program = dashboard_service::summary()['programs'][0];
        $this->assertSame(3, $program['complete'],
            'Replacing an activity assessment with a question assessment must not reduce readiness.');
        $this->assertSame(60, $program['percent']);
    }

    /**
     * A program with no outcome framework has not started rather than scored zero.
     */
    public function test_program_without_outcomes_reports_not_started(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        program_service::create([
            'code' => 'BSBA',
            'name' => 'Bachelor of Science in Business Administration',
            'programtype' => program_service::TYPE_UNDERGRADUATE,
        ]);

        $program = dashboard_service::summary()['programs'][0];
        $this->assertSame('none', $program['state']);
        $this->assertSame(0, $program['percent']);
    }

    /**
     * A course outcome with no approved relation rolls up nowhere.
     */
    public function test_unaligned_outcomes_ignore_related_ones(): void {
        $this->resetAfterTest(true);
        $fixture = $this->create_uneven_coverage_fixture();
        $programoutcomeid = $this->add_program_outcome($fixture['programid'], 'MBA-PLO');

        $this->assertSame(5, dashboard_service::summary()['unaligned'],
            'No course outcome is related to a program outcome yet.');

        global $DB;
        $sourceitemid = (int) $DB->get_field('local_outcomemap_itemver', 'itemid',
            ['id' => $fixture['versionids'][0]], MUST_EXIST);
        $relationid = relation_service::create([
            'sourceitemid' => $sourceitemid,
            'targetitemid' => $programoutcomeid,
            'type' => relation_service::CONTRIBUTES_TO,
            // A contributing relationship carries explicit influence; nothing
            // in this plugin infers a weight.
            'weight' => '1.0000000000',
            'effectivefrom' => self::EFFECTIVE_FROM,
        ]);
        relation_service::submit_for_review($relationid);

        $this->assertSame(4, dashboard_service::summary()['unaligned'],
            'The related course outcome no longer counts as unaligned.');
    }

    /**
     * A catalog course owning no approved outcome is reported by code.
     */
    public function test_catalog_course_without_outcomes_is_reported(): void {
        $this->resetAfterTest(true);
        $fixture = $this->create_uneven_coverage_fixture();
        catalog_course_service::create(['code' => 'MBA603', 'name' => 'Managing People']);

        $unframed = dashboard_service::summary()['unframedcourses'];
        $this->assertCount(1, $unframed);
        $this->assertSame('MBA603', $unframed[0]->code);
        $this->assertNotEquals($fixture['catalogid'], (int) $unframed[0]->id);
    }

    /**
     * The work queue leads with what blocks reporting and links to the fix.
     */
    public function test_tasks_are_ordered_by_what_blocks_reporting(): void {
        $this->resetAfterTest(true);
        $fixture = $this->create_uneven_coverage_fixture();
        catalog_course_service::create(['code' => 'MBA603', 'name' => 'Managing People']);

        $context = $this->export();
        $this->assertFalse($context['allclear']);
        $tones = array_column($context['tasks'], 'tone');
        $this->assertSame(['danger', 'danger', 'warn', 'warn'], $tones,
            'Blocking findings precede findings that only limit measurement.');

        $severities = array_column($context['tasks'], 'severity');
        $this->assertSame(get_string('dash_severity_blocks', 'local_outcomemap'), $severities[0]);

        // The coverage findings name a delivery, so they deep-link to it.
        $this->assertStringContainsString('courseid=' . $fixture['courseid'], $context['tasks'][2]['url']);
        $this->assertStringContainsString('courseid=' . $fixture['courseid'], $context['tasks'][3]['url']);
    }

    /**
     * A resolved site reports an all-clear rather than an empty list.
     */
    public function test_all_clear_when_nothing_is_outstanding(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $context = $this->export();
        $this->assertTrue($context['allclear']);
        $this->assertSame([], $context['tasks']);
        foreach ($context['tiles'] as $tile) {
            $this->assertSame('clear', $tile['tone'],
                'Every tile reads as resolved when there is no gap to report.');
            $this->assertSame(0, $tile['value']);
        }
    }

    /**
     * Tiles carry a tone that separates blocking gaps from lesser ones.
     */
    public function test_tiles_tone_by_severity_of_the_gap(): void {
        $this->resetAfterTest(true);
        $this->create_uneven_coverage_fixture();

        $tiles = array_column($this->export()['tiles'], null, 'label');
        $this->assertSame('danger',
            $tiles[get_string('dash_tile_unaligned', 'local_outcomemap')]['tone']);
        $this->assertSame('warn',
            $tiles[get_string('dash_tile_coverage', 'local_outcomemap')]['tone']);
        $this->assertSame(2,
            $tiles[get_string('dash_tile_coverage', 'local_outcomemap')]['value'],
            'The coverage tile totals both kinds of gap.');
    }

    /**
     * Repeated governance events of one kind collapse into a single line.
     */
    public function test_activity_groups_repeated_changes_of_one_kind(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $catalogid = catalog_course_service::create(['code' => 'MBA601', 'name' => 'Financial Management']);
        $this->add_course_outcomes($catalogid, 'MBA601-CLO', 4);

        $activity = dashboard_service::summary()['activity'];
        $this->assertNotSame([], $activity);
        // Approving the fourth version is the newest event, so this group leads
        // the feed regardless of how many other kinds of change precede it.
        $approvals = array_values(array_filter($activity, static fn(array $row): bool =>
            $row['action'] === 'approve' && $row['objecttype'] === 'outcome_version'));
        $this->assertCount(1, $approvals, 'Four approvals report as one grouped change.');
        $this->assertSame(4, $approvals[0]['count']);
    }

    /**
     * Reading the dashboard needs no management capability.
     */
    public function test_definition_reader_can_load_the_dashboard(): void {
        $this->resetAfterTest(true);
        $this->create_uneven_coverage_fixture();

        $reader = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/outcomemap:viewdefinitions', CAP_ALLOW, $roleid,
            \context_system::instance()->id);
        role_assign($roleid, $reader->id, \context_system::instance()->id);
        $this->setUser($reader);

        $context = $this->export();
        $this->assertNotSame([], $context['tiles']);
        $this->assertNotSame([], $context['inventory']);
    }
}
