<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap;

use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\student_result_service;
use local_outcomemap\output\student_results;

/**
 * Tests the learner-facing progress page built from a released report.
 *
 * The report DTO is assembled directly here rather than through the service,
 * so each narrative branch can be driven deliberately: what the page says
 * about a gap, about a figure it cannot judge, and about a blank row.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class student_results_page_test extends \advanced_testcase {
    /** @var int Fixed generation time so the footnote is deterministic. */
    private const GENERATED_AT = 1785000000;

    /**
     * Build one report row with sensible released defaults.
     *
     * @param array $overrides Fields to replace.
     * @return array Report row.
     */
    private function row(array $overrides): array {
        return $overrides + [
            'code' => 'C1',
            'shortstatement' => 'Course skill one',
            'itemid' => 1,
            'tier' => student_result_service::TIER_COURSE,
            'frameworkcode' => 'FW-CLO',
            'parentitemids' => [],
            'expectedpercent' => '70.0000000000',
            'strongpercent' => '85.0000000000',
            'periodcode' => '2026-T1',
            'scopetype' => calculation_service::SCOPE_COURSE,
            'scopeid' => 1,
            'scopename' => null,
            'state' => calculation_service::STATE_CALCULATED,
            'percentage' => '90.0000000000',
            'displayscale' => 1,
            'bandname' => 'Exceeds expectations',
            'bandfeedback' => null,
            'bandid' => 3,
            'distinctitems' => 6,
            'weightedpossible' => '6.0000000000',
            'timecalculated' => self::GENERATED_AT,
            'releasedat' => self::GENERATED_AT,
            'remediation' => [],
        ];
    }

    /**
     * Wrap rows in the report envelope the service returns.
     *
     * @param array $rows Report rows.
     * @param string|null $expected Pass mark, or null when the ladder has none.
     * @param string|null $strong Top boundary, or null.
     * @return array Report data.
     */
    private function report(array $rows, ?string $expected = '70.0000000000',
            ?string $strong = '85.0000000000'): array {
        return [
            'courseid' => 1,
            'generatedat' => self::GENERATED_AT,
            'expectedpercent' => $expected,
            'strongpercent' => $strong,
            'rows' => $rows,
        ];
    }

    /**
     * Render the page for a report, returning the exported context and HTML.
     *
     * @param array $report Report data.
     * @return array{0:array,1:string} Context and rendered markup.
     */
    private function render(array $report): array {
        global $PAGE;
        $PAGE->set_url('/local/outcomemap/results.php', ['courseid' => 1]);
        // $OUTPUT is still the bootstrap renderer under PHPUnit, so ask the page
        // for the real core renderer the page itself would use.
        $output = $PAGE->get_renderer('core');
        $context = (new student_results($report))->export_for_template($output);
        return [$context, $output->render_from_template('local_outcomemap/student_results', $context)];
    }

    /**
     * A three-tier report renders every section and states the standing.
     */
    public function test_page_reports_standing_across_all_three_tiers(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context, $html] = $this->render($this->report([
            $this->row(['itemid' => 10, 'code' => 'C1', 'shortstatement' => 'Reading the market',
                'percentage' => '90.0000000000', 'parentitemids' => [30]]),
            $this->row(['itemid' => 11, 'code' => 'C2', 'shortstatement' => 'Pricing decisions',
                'percentage' => '75.0000000000', 'bandname' => 'Meets expectations', 'bandid' => 2,
                'parentitemids' => [30]]),
            $this->row(['itemid' => 12, 'code' => 'C3', 'shortstatement' => 'Ethical judgement',
                'percentage' => '50.0000000000', 'bandname' => 'Does not meet expectations',
                'bandid' => 1, 'distinctitems' => 3, 'parentitemids' => [31]]),
            $this->row(['itemid' => 20, 'code' => 'U1', 'shortstatement' => 'Explain fair dealing',
                'tier' => student_result_service::TIER_UNIT, 'frameworkcode' => 'FW-ULO',
                'percentage' => '40.0000000000', 'bandname' => 'Does not meet expectations',
                'bandid' => 1, 'distinctitems' => 4, 'parentitemids' => [12]]),
            $this->row(['itemid' => 21, 'code' => 'U2', 'shortstatement' => 'Apply a code of conduct',
                'tier' => student_result_service::TIER_UNIT, 'frameworkcode' => 'FW-ULO',
                'state' => student_result_service::STATE_NOT_RELEASED, 'percentage' => null,
                'bandname' => null, 'bandid' => null, 'distinctitems' => null,
                'parentitemids' => [12]]),
            $this->row(['itemid' => 22, 'code' => 'U3', 'shortstatement' => 'Weigh competing duties',
                'tier' => student_result_service::TIER_UNIT, 'frameworkcode' => 'FW-ULO',
                'state' => calculation_service::STATE_INSUFFICIENT, 'percentage' => null,
                'bandname' => null, 'bandid' => null, 'distinctitems' => 0,
                'parentitemids' => [12]]),
            $this->row(['itemid' => 30, 'code' => 'P1', 'shortstatement' => 'Setting direction',
                'tier' => student_result_service::TIER_PROGRAM, 'frameworkcode' => 'PROG-PLO',
                'percentage' => '88.0000000000']),
            $this->row(['itemid' => 31, 'code' => 'P2', 'shortstatement' => 'Ethical and legal judgement',
                'tier' => student_result_service::TIER_PROGRAM, 'frameworkcode' => 'PROG-PLO',
                'percentage' => '62.0000000000', 'bandname' => 'Does not meet expectations',
                'bandid' => 1]),
        ]));

        // Two of three course skills clear the mark, and only one exceeds it.
        $this->assertStringContainsString('on track in 2 of your 3 course skills', $context['hero']['title']);
        $this->assertSame([1, 1, 1], array_column($context['standing']['lines'], 'count'),
            'One skill should be strong, one comfortably past the mark, and one below it.');

        // Only the course tier is listed as a skill; units belong inside one.
        $this->assertSame(['C1', 'C2', 'C3'], array_column($context['skills']['items'], 'code'));
        $ethics = $context['skills']['items'][2];
        $this->assertCount(3, $ethics['units'], 'All three unit outcomes hang off C3.');
        $this->assertSame(['below', 'none', 'none'], array_column($ethics['units'], 'tone'));

        // The programme tier is reported separately, not as a course skill.
        $this->assertSame(['P1', 'P2'], array_column($context['degree']['items'], 'code'));

        $this->assertStringContainsString('Ethical judgement', $html);
        $this->assertStringContainsString('Explain fair dealing', $html);
        $this->assertStringContainsString('Setting direction', $html);
        $this->assertStringContainsString('62.0%', $html);
    }

    /**
     * The first action names the gap, its weakest unit, and the degree promise
     * it alone decides — the reason that gap cannot be offset elsewhere.
     */
    public function test_first_action_names_the_gap_and_its_sole_route_to_the_degree(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context] = $this->render($this->report([
            $this->row(['itemid' => 12, 'code' => 'C3', 'shortstatement' => 'Ethical judgement',
                'percentage' => '50.0000000000', 'bandname' => 'Does not meet expectations',
                'bandid' => 1, 'parentitemids' => [31], 'remediation' => [[
                    'title' => 'Unit 1 · Ethics in Sales',
                    'explanation' => 'Re-read before reassessment.',
                    'url' => 'https://example.com/review',
                    'required' => true,
                    'purpose' => 'review',
                ]]]),
            $this->row(['itemid' => 20, 'code' => 'U1', 'shortstatement' => 'Explain fair dealing',
                'tier' => student_result_service::TIER_UNIT, 'frameworkcode' => 'FW-ULO',
                'percentage' => '40.0000000000', 'parentitemids' => [12]]),
            $this->row(['itemid' => 31, 'code' => 'P2', 'shortstatement' => 'Ethical and legal judgement',
                'tier' => student_result_service::TIER_PROGRAM, 'frameworkcode' => 'PROG-PLO',
                'percentage' => '62.0000000000']),
        ]));

        $this->assertTrue($context['actions']['has']);
        $card = $context['actions']['cards'][0];
        $this->assertSame('Step 1', $card['step']);
        $this->assertStringContainsString('50.0%', $card['body']);
        $this->assertStringContainsString('Explain fair dealing', $card['body']);
        $this->assertStringContainsString('only route', $card['body'],
            'A gap that nothing else offsets should say so.');
        $this->assertStringContainsString('Ethical and legal judgement', $card['body']);
        $this->assertSame('Unit 1 · Ethics in Sales', $card['links']['items'][0]['title']);
        $this->assertSame('Required', $card['links']['items'][0]['designation']);

        // The same programme row explains itself in the degree section.
        $this->assertStringContainsString('one skill only', $context['degree']['items'][0]['reading']);
    }

    /**
     * A passing skill that hides a failing unit outcome is called out, because
     * the skill average is exactly what conceals it.
     */
    public function test_a_weak_unit_inside_a_passing_skill_becomes_an_action(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context] = $this->render($this->report([
            $this->row(['itemid' => 13, 'code' => 'C4', 'shortstatement' => 'Channels and promotion',
                'percentage' => '100.0000000000']),
            $this->row(['itemid' => 23, 'code' => 'U4', 'shortstatement' => 'Time to market and cost',
                'tier' => student_result_service::TIER_UNIT, 'frameworkcode' => 'FW-ULO',
                'percentage' => '50.0000000000', 'parentitemids' => [13]]),
        ]));

        $this->assertTrue($context['actions']['has']);
        $card = $context['actions']['cards'][0];
        $this->assertStringContainsString('Channels and promotion', $card['title']);
        $this->assertStringContainsString('Time to market and cost', $card['body']);
        $this->assertStringContainsString('average hides it', $card['body']);
    }

    /**
     * Blank rows are separated by cause, and neither cause is presented as a
     * score. A learner must be able to tell "not marked yet" from "not shown".
     */
    public function test_blank_rows_are_split_by_cause_and_never_read_as_zero(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context, $html] = $this->render($this->report([
            $this->row(['itemid' => 14, 'code' => 'C5', 'shortstatement' => 'Building a plan',
                'percentage' => '80.0000000000', 'bandname' => 'Meets expectations', 'bandid' => 2]),
            $this->row(['itemid' => 24, 'code' => 'U5', 'shortstatement' => 'Draft an action plan',
                'tier' => student_result_service::TIER_UNIT, 'frameworkcode' => 'FW-ULO',
                'state' => calculation_service::STATE_INSUFFICIENT, 'percentage' => null,
                'bandname' => null, 'bandid' => null, 'parentitemids' => [14]]),
            $this->row(['itemid' => 25, 'code' => 'U6', 'shortstatement' => 'Measure effectiveness',
                'tier' => student_result_service::TIER_UNIT, 'frameworkcode' => 'FW-ULO',
                'state' => student_result_service::STATE_NOT_RELEASED, 'percentage' => null,
                'bandname' => null, 'bandid' => null, 'parentitemids' => [14]]),
        ]));

        $this->assertSame([1, 1], array_column($context['blanks']['cards'], 'count'));
        $this->assertSame('No graded work yet', $context['blanks']['cards'][0]['title']);
        $this->assertSame('Waiting to be published', $context['blanks']['cards'][1]['title']);
        // Both blank groups name the skill they sit under, not a bare code.
        $this->assertSame('Building a plan', $context['blanks']['cards'][0]['groups']);
        $statuses = array_column($context['skills']['items'][0]['units'], 'status');
        $this->assertSame(['No graded work here yet', 'Result not published yet'], $statuses,
            'An unmeasured outcome states its cause and is never rendered as a zero score.');
    }

    /**
     * Without a band ladder the page still reports, but stops judging: no mark,
     * no on-track claim, no filter that would imply one.
     */
    public function test_a_report_without_thresholds_reports_without_judging(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context, $html] = $this->render($this->report([
            $this->row(['itemid' => 15, 'code' => 'C6', 'shortstatement' => 'Reading the market',
                'expectedpercent' => null, 'strongpercent' => null, 'bandname' => null,
                'bandid' => null, 'percentage' => '90.0000000000']),
        ], null, null));

        $this->assertFalse($context['hasthresholds']);
        $this->assertFalse($context['standing']['has']);
        $this->assertFalse($context['filters']['has']);
        $this->assertFalse($context['actions']['has']);
        $this->assertStringContainsString('no achievement bands set', $context['hero']['lede']);
        $this->assertNull($context['skills']['items'][0]['bar']['mark']);
        $this->assertStringNotContainsString('lom-sr-bar-mark', $html,
            'With no ladder there is no expected mark to draw on the bar.');
    }

    /**
     * A ladder whose top boundary is also its pass mark cannot tell strong work
     * from adequate work, so the page must not offer a "strong" reading.
     */
    public function test_a_single_boundary_ladder_claims_no_strong_tier(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context] = $this->render($this->report([
            $this->row(['itemid' => 16, 'code' => 'C7', 'shortstatement' => 'Reading the market',
                'expectedpercent' => '70.0000000000', 'strongpercent' => '70.0000000000',
                'percentage' => '95.0000000000', 'bandname' => 'Meets expectations', 'bandid' => 2]),
        ], '70.0000000000', '70.0000000000'));

        $this->assertNull($context['strong']);
        $this->assertSame('ontrack', $context['skills']['items'][0]['tone']);
        $this->assertSame(['ontrack', 'below'], array_column($context['standing']['lines'], 'tone'),
            'With no strong boundary the tally collapses to on-track and below.');
        $this->assertSame([1, 0], array_column($context['standing']['lines'], 'count'));
    }

    /**
     * A mixed-policy report has no single page-level mark, but each row still
     * knows its own. An actionable gap must not vanish because a different row
     * was judged against a different ladder.
     */
    public function test_a_gap_still_acts_when_the_report_has_no_single_ladder(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context] = $this->render($this->report([
            $this->row(['itemid' => 40, 'code' => 'C8', 'shortstatement' => 'Ethical judgement',
                'percentage' => '50.0000000000', 'expectedpercent' => '70.0000000000',
                'strongpercent' => '85.0000000000', 'bandname' => 'Does not meet expectations',
                'bandid' => 1]),
            $this->row(['itemid' => 41, 'code' => 'C9', 'shortstatement' => 'Pricing decisions',
                'percentage' => '65.0000000000', 'expectedpercent' => '60.0000000000',
                'strongpercent' => '80.0000000000', 'bandname' => 'Meets expectations',
                'bandid' => 2]),
        ], null, null));

        $this->assertFalse($context['hasthresholds'], 'No single ladder covers the whole report.');
        $this->assertSame(['below', 'ontrack'], array_column($context['skills']['items'], 'tone'),
            'Each row is judged against its own mark.');
        $this->assertTrue($context['actions']['has'],
            'The gap on C8 is still actionable even with no page-level ladder.');
        $this->assertStringContainsString('70% mark', $context['actions']['cards'][0]['body'],
            "The card names the row's own mark, not a page-level one.");
        $this->assertTrue($context['filters']['has'],
            'Tone-based filters stay meaningful under mixed policies.');
    }

    /**
     * Curated recommendations attached to a unit outcome reach the page. That
     * row is rendered nowhere else, so dropping them loses the most specific
     * help the report has.
     */
    public function test_recommendations_on_a_unit_outcome_are_not_dropped(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context, $html] = $this->render($this->report([
            $this->row(['itemid' => 50, 'code' => 'C10', 'shortstatement' => 'Channels and promotion',
                'percentage' => '100.0000000000']),
            $this->row(['itemid' => 51, 'code' => 'U10', 'shortstatement' => 'Time to market and cost',
                'tier' => student_result_service::TIER_UNIT, 'frameworkcode' => 'FW-ULO',
                'percentage' => '50.0000000000', 'parentitemids' => [50], 'remediation' => [[
                    'title' => 'Unit 6 · Logistics and delivery',
                    'explanation' => null,
                    'url' => 'https://example.com/logistics',
                    'required' => false,
                    'purpose' => 'practice',
                ]]]),
        ]));

        $unit = $context['skills']['items'][0]['units'][0];
        $this->assertTrue($unit['links']['has']);
        $this->assertSame('Unit 6 · Logistics and delivery', $unit['links']['items'][0]['title']);
        $this->assertStringContainsString('Unit 6 · Logistics and delivery', $html,
            'The unit recommendation must reach the rendered page.');
    }

    /**
     * The plugin stylesheet must parse.
     *
     * A plugin styles.css is concatenated into the theme, so one unterminated
     * rule does not fail loudly — it silently swallows every rule after it into
     * the block that was left open. That happened once already, when a merge
     * factored out a closing brace shared by both sides of a conflict, and the
     * result rendered as an unstyled page rather than as an error.
     */
    public function test_the_plugin_stylesheet_has_balanced_braces(): void {
        $css = file_get_contents(__DIR__ . '/../styles.css');
        $this->assertNotFalse($css, 'The plugin stylesheet should be readable.');
        $this->assertSame(
            substr_count($css, '{'),
            substr_count($css, '}'),
            'Unbalanced braces in styles.css swallow every rule after the unclosed one.'
        );
    }

    /**
     * With no rows at all the page says so instead of rendering empty furniture.
     */
    public function test_an_empty_report_renders_the_empty_notice(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$context, $html] = $this->render($this->report([]));

        $this->assertFalse($context['hasrows']);
        $this->assertStringContainsString('No approved course learning outcomes', $html);
    }
}
