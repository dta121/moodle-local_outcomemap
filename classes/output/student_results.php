<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student outcome-results template context.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\decimal;
use local_outcomemap\local\service\calculation_service;

/**
 * Converts the learner-safe report DTO into an accessible template context.
 */
final class student_results implements \renderable, \templatable {
    /** @var array Learner-safe report data. */
    private array $report;

    /**
     * @param array $report Learner-safe report data.
     */
    public function __construct(array $report) {
        $this->report = $report;
    }

    /**
     * Export the Mustache context.
     *
     * @param \renderer_base $output Renderer.
     * @return array Template context.
     */
    public function export_for_template(\renderer_base $output): array {
        $rows = [];
        foreach ($this->report['rows'] as $row) {
            $state = (string) $row['state'];
            $percentage = $row['percentage'] === null
                ? null : self::format_decimal($row['percentage'], (int) $row['displayscale']);
            $scope = $row['scopetype'] === calculation_service::SCOPE_ASSESSMENT
                ? get_string('resultscope_assessment_named', 'local_outcomemap', $row['scopename'] ?? '')
                : get_string('resultscope_course', 'local_outcomemap');
            if ($row['periodcode'] !== '') {
                $scope .= ' — ' . $row['periodcode'];
            }
            $recommendations = [];
            foreach ($row['remediation'] as $recommendation) {
                $recommendations[] = [
                    'title' => $recommendation['title'],
                    'explanation' => $recommendation['explanation'],
                    'url' => $recommendation['url'],
                    'designation' => get_string($recommendation['required']
                        ? 'remediation_required' : 'remediation_recommended', 'local_outcomemap'),
                    'purpose' => get_string('remediationpurpose_' . $recommendation['purpose'],
                        'local_outcomemap'),
                ];
            }
            $hascalculation = $row['distinctitems'] !== null;
            $rows[] = [
                'code' => $row['code'],
                'shortstatement' => $row['shortstatement'],
                'percentage' => $percentage === null
                    ? get_string('calculationnotavailable', 'local_outcomemap')
                    : get_string('resultpercentage', 'local_outcomemap', $percentage),
                'bandstate' => $row['bandname'] ?? get_string('resultstate_' . $state, 'local_outcomemap'),
                'bandfeedback' => $row['bandfeedback'],
                'scope' => $scope,
                'hascalculation' => $hascalculation,
                'distinctitems' => $hascalculation ? $row['distinctitems'] : null,
                'weightedpossible' => $hascalculation
                    ? self::format_decimal($row['weightedpossible'], decimal::SCALE, true) : null,
                'timecalculated' => $row['timecalculated'] === null
                    ? get_string('calculationnotavailable', 'local_outcomemap')
                    : userdate($row['timecalculated']),
                'hasremediation' => (bool) $recommendations,
                'remediation' => $recommendations,
            ];
        }
        return [
            'hasrows' => (bool) $rows,
            'rows' => $rows,
            'caption' => get_string('outcomeresults_caption', 'local_outcomemap'),
        ];
    }

    /**
     * Format a canonical decimal without converting it to float.
     *
     * @param string $value Canonical decimal.
     * @param int $scale Number of displayed fractional digits.
     * @param bool $trimzeroes Trim trailing fractional zeroes.
     * @return string Localized decimal text.
     */
    private static function format_decimal(string $value, int $scale, bool $trimzeroes = false): string {
        $quantized = decimal::quantize($value, $scale);
        [$whole, $fraction] = explode('.', $quantized);
        $fraction = substr($fraction, 0, $scale);
        if ($trimzeroes) {
            $fraction = rtrim($fraction, '0');
        }
        if ($fraction === '') {
            return $whole;
        }
        return $whole . get_string('decsep', 'langconfig') . $fraction;
    }
}
