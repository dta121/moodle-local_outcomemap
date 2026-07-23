<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local;

/**
 * Effective-date validation helpers.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class effective_dates {
    /**
     * Validate an effective range.
     *
     * Ranges use an inclusive start and exclusive end.
     *
     * @param int $from Start timestamp.
     * @param int|null $to End timestamp, or null for open ended.
     * @return void
     */
    public static function validate(int $from, ?int $to): void {
        if ($from < 1 || ($to !== null && $to <= $from)) {
            throw new validation_exception('effectiverangeinvalid', 'effectiveto');
        }
    }

    /**
     * Build portable SQL and parameters selecting ranges overlapping a candidate.
     *
     * @param string $alias SQL table alias including trailing dot when needed.
     * @param int $from Candidate start.
     * @param int|null $to Candidate end.
     * @param string $prefix Parameter prefix.
     * @return array{0:string,1:array}
     */
    public static function overlap_sql(string $alias, int $from, ?int $to, string $prefix): array {
        $sql = '(' . $alias . 'effectiveto IS NULL OR ' . $alias . 'effectiveto > :' . $prefix . 'from)';
        $params = [$prefix . 'from' => $from];
        if ($to !== null) {
            $sql .= ' AND ' . $alias . 'effectivefrom < :' . $prefix . 'to';
            $params[$prefix . 'to'] = $to;
        }
        return [$sql, $params];
    }
}
