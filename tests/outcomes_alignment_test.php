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

use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\output\outcomes_hierarchy;

/**
 * Tests the combined Outcomes and alignment page.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class outcomes_alignment_test extends \advanced_testcase {
    /**
     * Create an approved framework and return its id.
     *
     * @param string $code Framework code.
     * @param string $ownertype Framework owner type.
     * @param int|null $ownerid Framework owner id.
     * @return int
     */
    private function framework(string $code, string $ownertype, ?int $ownerid = null): int {
        $id = framework_service::create([
            'code' => $code,
            'name' => 'Framework ' . $code,
            'ownertype' => $ownertype,
            'ownerid' => $ownerid,
        ]);
        framework_service::submit_for_review($id);
        return $id;
    }

    /**
     * Create an approved outcome and return its item and version ids.
     *
     * @param int $frameworkid Framework id.
     * @param string $code Outcome code.
     * @return array{0:int,1:int} Item id and item version id.
     */
    private function outcome(int $frameworkid, string $code): array {
        global $DB;
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => $code,
            'statement' => 'Statement for ' . $code,
            'effectivefrom' => 1704067200,
        ]);
        $versionid = (int) $DB->get_field('local_outcomemap_itemver', 'id',
            ['itemid' => $itemid], MUST_EXIST);
        // A relation may only join approved outcomes, so the version is carried
        // through the submission boundary before it can be aligned.
        outcome_service::submit_for_review($versionid);
        return [$itemid, $versionid];
    }

    /**
     * Export the page context for one view.
     *
     * @param string $view View to render.
     * @return array Template context.
     */
    private function export(string $view): array {
        global $PAGE;
        return (new outcomes_hierarchy($view))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Build a program outcome aligned to by a course outcome.
     *
     * @return void
     */
    private function build_alignment(): void {
        $programid = program_service::create([
            'code' => 'MBA',
            'name' => 'Master of Business Administration',
            'programtype' => program_service::TYPE_GRADUATE,
        ]);
        $plofw = $this->framework('MBA-PLO', framework_service::OWNER_PROGRAM, $programid);
        $clofw = $this->framework('MBA601-CLO', framework_service::OWNER_INSTITUTION);
        [$plo] = $this->outcome($plofw, 'PLO1');
        [$clo] = $this->outcome($clofw, 'CLO1');
        $relationid = relation_service::create([
            'sourceitemid' => $clo,
            'targetitemid' => $plo,
            'type' => relation_service::ALIGNS_TO,
            'effectivefrom' => 1704067200,
        ]);
        relation_service::submit_for_review($relationid);
    }

    /**
     * The three views are offered, and only the matrix is reached by link.
     */
    public function test_view_tabs_offer_the_matrix_as_a_separate_render(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $context = $this->export('program');
        $tabs = array_column($context['viewtabs'], null, 'id');
        $this->assertSame(['program', 'course', 'matrix'], array_keys($tabs));
        $this->assertTrue($tabs['program']['active']);
        $this->assertTrue($tabs['program']['isbutton'],
            'The two hierarchy views are both in the page and switch in the browser.');
        $this->assertTrue($tabs['course']['isbutton']);
        $this->assertTrue($tabs['matrix']['islink'],
            'The matrix is its own render, so it is reached by link.');
        $this->assertFalse($context['ismatrix']);
    }

    /**
     * Asking for the course view opens on it rather than defaulting to program.
     */
    public function test_requested_view_is_the_active_one(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $context = $this->export('course');
        $tabs = array_column($context['viewtabs'], null, 'id');
        $this->assertTrue($tabs['course']['active']);
        $this->assertFalse($tabs['program']['active']);
        $this->assertSame('course', $context['initialview']);
    }

    /**
     * An unknown view falls back to the hierarchy rather than erroring.
     */
    public function test_unknown_view_falls_back_to_program(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $context = $this->export('nonsense');
        $this->assertSame('program', $context['initialview']);
        $this->assertFalse($context['ismatrix']);
    }

    /**
     * The matrix view carries the alignment grid and drops the hierarchy cards.
     *
     * The grid is an outcome-by-outcome table, so building it for the hierarchy
     * views would be paid for on every page load and thrown away.
     */
    public function test_matrix_view_builds_the_grid_and_only_the_grid(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->build_alignment();

        $hierarchy = $this->export('program');
        $this->assertNull($hierarchy['alignment'],
            'The hierarchy views must not pay for the alignment grid.');
        $this->assertNotSame([], $hierarchy['programcards']);

        $matrix = $this->export(outcomes_hierarchy::VIEW_MATRIX);
        $this->assertTrue($matrix['ismatrix']);
        $this->assertIsArray($matrix['alignment']);
        $this->assertTrue($matrix['alignment']['hasrelations']);
        $this->assertNotSame([], $matrix['alignment']['groups']);
        $this->assertSame([], $matrix['programcards'],
            'The grid stands alone; the hierarchy cards are not rendered under it.');
        $this->assertSame([], $matrix['coursecards']);
        $this->assertSame([], $matrix['pickers']);
    }

    /**
     * Both exports are offered from the one toolbar.
     */
    public function test_both_csv_exports_are_offered(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $context = $this->export('program');
        $this->assertStringContainsString('frameworks.php', $context['exporturl']);
        $this->assertStringContainsString('action=exportcsv', $context['exporturl']);
        $this->assertStringContainsString('relations.php', $context['alignmentexporturl']);
        $this->assertStringContainsString('action=exportcsv', $context['alignmentexporturl']);
    }

    /**
     * The stats line counts alignments alongside the outcomes.
     */
    public function test_stats_line_counts_alignments(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->build_alignment();

        $context = $this->export('program');
        $this->assertStringContainsString('1 alignments', $context['hierarchyline']);
    }
}
