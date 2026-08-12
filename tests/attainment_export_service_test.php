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

use local_outcomemap\local\service\attainment_export_service;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\student_result_service;

/**
 * Pooling rules for the SIS program-attainment export.
 *
 * These exercise the arithmetic and state decisions in isolation: the rows
 * fed in are the learner-safe report rows student_result_service already
 * produces (and already tests, release gates included), so what is proved
 * here is what the export ADDS — fraction pooling instead of percentage
 * averaging, state precedence when nothing pooled, threshold single-voice.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attainment_export_service_test extends \basic_testcase {
    /** @var array Outcome descriptor shared by every pooled row. */
    private const OUTCOME = [
        'itemid' => 41,
        'code' => 'PLO1',
        'statement' => 'Identify and analyze problems in a business environment.',
        'shortstatement' => 'Analyze business problems.',
    ];

    /**
     * Build one learner-safe report row.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    private function row(array $overrides = []): array {
        return array_merge([
            'state' => calculation_service::STATE_CALCULATED,
            'percentage' => '50.0000000000',
            'weightedearned' => '10.0000000000',
            'weightedpossible' => '20.0000000000',
            'distinctitems' => 4,
            'timecalculated' => 1000,
            'expectedpercent' => '70.0000000000',
            'strongpercent' => '85.0000000000',
            'cinstid' => 11,
        ], $overrides);
    }

    /**
     * Tests that pooling sums canonical fractions and never averages percentages.
     */
    public function test_pooling_sums_fractions_rather_than_averaging_percentages(): void {
        $pooled = attainment_export_service::pool_outcome(self::OUTCOME, [
            // 10/20 = 50% ...
            $this->row(),
            // ... and 30/30 = 100%. Averaging says 75%; the pooled fraction
            // 40/50 says 80%, and 80 is the honest weight-bearing answer.
            $this->row([
                'percentage' => '100.0000000000',
                'weightedearned' => '30.0000000000',
                'weightedpossible' => '30.0000000000',
                'distinctitems' => 6,
                'timecalculated' => 2000,
                'cinstid' => 12,
            ]),
        ], 9);

        $this->assertSame(calculation_service::STATE_CALCULATED, $pooled['state']);
        $this->assertSame('80.0000000000', $pooled['percentage']);
        $this->assertSame(2, $pooled['coursesassessed']);
        $this->assertSame(9, $pooled['coursestotal']);
        $this->assertSame(10, $pooled['gradeditems']);
        $this->assertSame(2000, $pooled['timecalculated']);
        $this->assertSame('70.0000000000', $pooled['expectedpercent']);
        $this->assertSame('85.0000000000', $pooled['strongpercent']);
        $this->assertSame('PLO1', $pooled['code']);
    }

    /**
     * Tests that non-calculated rows contribute no figures but decide the fallback state.
     */
    public function test_placeholder_rows_carry_no_figures_and_rank_by_precedence(): void {
        // A withheld row alone: the pooled outcome says withheld, not zero.
        $withheld = attainment_export_service::pool_outcome(self::OUTCOME, [
            $this->row(['state' => student_result_service::STATE_NOT_RELEASED, 'percentage' => null]),
        ], 9);
        $this->assertSame(student_result_service::STATE_NOT_RELEASED, $withheld['state']);
        $this->assertNull($withheld['percentage']);
        $this->assertSame(0, $withheld['coursesassessed']);
        $this->assertSame(0, $withheld['gradeditems']);
        $this->assertNull($withheld['timecalculated']);

        // Insufficient evidence outranks a withheld figure: it is the stronger
        // claim about the learner's record.
        $mixed = attainment_export_service::pool_outcome(self::OUTCOME, [
            $this->row(['state' => student_result_service::STATE_NOT_RELEASED, 'percentage' => null]),
            $this->row(['state' => calculation_service::STATE_INSUFFICIENT, 'percentage' => null, 'cinstid' => 12]),
        ], 9);
        $this->assertSame(calculation_service::STATE_INSUFFICIENT, $mixed['state']);
        $this->assertNull($mixed['percentage']);

        // One calculated course among placeholders pools alone.
        $partial = attainment_export_service::pool_outcome(self::OUTCOME, [
            $this->row(['state' => student_result_service::STATE_NOT_RELEASED, 'percentage' => null]),
            $this->row(['cinstid' => 12]),
        ], 9);
        $this->assertSame(calculation_service::STATE_CALCULATED, $partial['state']);
        $this->assertSame('50.0000000000', $partial['percentage']);
        $this->assertSame(1, $partial['coursesassessed']);
    }

    /**
     * Tests that an outcome no course has fed yet reads not assessed, never zero.
     */
    public function test_no_rows_is_not_assessed(): void {
        $pooled = attainment_export_service::pool_outcome(self::OUTCOME, [], 9);
        $this->assertSame(calculation_service::STATE_NOT_ASSESSED, $pooled['state']);
        $this->assertNull($pooled['percentage']);
        $this->assertSame(0, $pooled['coursesassessed']);
        $this->assertSame(9, $pooled['coursestotal']);
        $this->assertNull($pooled['expectedpercent']);
    }

    /**
     * Tests that disagreeing band ladders withhold the threshold instead of inventing one.
     */
    public function test_mixed_ladders_withhold_thresholds(): void {
        $pooled = attainment_export_service::pool_outcome(self::OUTCOME, [
            $this->row(),
            $this->row(['expectedpercent' => '60.0000000000', 'cinstid' => 12]),
        ], 9);
        $this->assertSame(calculation_service::STATE_CALCULATED, $pooled['state']);
        $this->assertNull($pooled['expectedpercent']);
        $this->assertNull($pooled['strongpercent']);
    }

    /**
     * Tests the per-course contribution choice: released figures first, then recency.
     */
    public function test_prefer_takes_released_calculated_then_most_recent(): void {
        $calculated = $this->row();
        $newerwithheld = $this->row([
            'state' => student_result_service::STATE_NOT_RELEASED,
            'percentage' => null,
            'timecalculated' => 9999,
        ]);
        // A released figure beats a newer withheld one in either argument order.
        $this->assertSame($calculated, attainment_export_service::prefer($calculated, $newerwithheld));
        $this->assertSame($calculated, attainment_export_service::prefer($newerwithheld, $calculated));

        // Between two released figures the retake (more recent) wins.
        $retake = $this->row(['percentage' => '90.0000000000', 'timecalculated' => 2000, 'cinstid' => 12]);
        $this->assertSame($retake, attainment_export_service::prefer($calculated, $retake));
        $this->assertSame($retake, attainment_export_service::prefer($retake, $calculated));
    }
}
