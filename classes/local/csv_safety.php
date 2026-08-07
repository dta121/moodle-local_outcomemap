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

/**
 * Spreadsheet formula neutralization for CSV exports.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local;

/**
 * Guards free-text CSV cells against spreadsheet formula execution.
 *
 * Spreadsheet applications treat a cell beginning with =, +, -, or @ (or a
 * control character) as a formula, so text that staff typed into an outcome
 * statement, band name, or period code could execute when a downloaded export
 * is opened. Numeric cells are produced separately by the exporters and never
 * pass through here, so plain negative numbers are unaffected.
 */
final class csv_safety {
    /**
     * Neutralize one free-text cell for CSV output.
     *
     * A cell whose first character would start a spreadsheet formula is
     * prefixed with an apostrophe, the spreadsheet convention for "this is
     * text". Cells that cannot be mistaken for formulas are returned unchanged,
     * so ordinary exports are byte-identical with or without the guard. A
     * plain negative number is not a formula and passes through untouched.
     *
     * @param string|null $value Raw cell text.
     * @return string Safe cell text.
     */
    public static function cell(?string $value): string {
        $value = (string) $value;
        if ($value === '') {
            return $value;
        }
        // Leading whitespace does not stop a spreadsheet parsing a formula.
        $trimmed = ltrim($value, " \t\r\n");
        $first = $trimmed[0] ?? '';
        if (!in_array($first, ['=', '+', '-', '@'], true)) {
            return $value;
        }
        if (($first === '-' || $first === '+') && is_numeric($trimmed)) {
            return $value;
        }
        return "'" . $value;
    }

    /**
     * Neutralize every cell of a row, leaving non-strings untouched.
     *
     * @param array $row Mixed row of string and numeric cells.
     * @return array Row with every string cell guarded.
     */
    public static function row(array $row): array {
        return array_map(
            static fn($cell) => is_string($cell) ? self::cell($cell) : $cell,
            $row
        );
    }
}
