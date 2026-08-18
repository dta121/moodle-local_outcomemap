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

use local_outcomemap\local\csv_safety;

/**
 * Formula-neutralization tests for CSV export cells.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class csv_safety_test extends \basic_testcase {
    /**
     * * Tests that formula-shaped cells are neutralized.
     */
    public function test_formula_cells_are_neutralized(): void {
        $this->assertSame("'=HYPERLINK(\"http://evil\")", csv_safety::cell('=HYPERLINK("http://evil")'));
        $this->assertSame("'=1+1", csv_safety::cell('=1+1'));
        $this->assertSame("'@SUM(A1)", csv_safety::cell('@SUM(A1)'));
        $this->assertSame("'+cmd|/c calc", csv_safety::cell('+cmd|/c calc'));
        $this->assertSame("'-2+3+cmd", csv_safety::cell('-2+3+cmd'));
        // Leading whitespace must not defeat the guard.
        $this->assertSame("'  =1+1", csv_safety::cell('  =1+1'));
        $this->assertSame("'\t=1+1", csv_safety::cell("\t=1+1"));
    }

    /**
     * * Tests that ordinary values pass through byte-identical.
     */
    public function test_ordinary_cells_are_unchanged(): void {
        $this->assertSame('', csv_safety::cell(''));
        $this->assertSame('', csv_safety::cell(null));
        $this->assertSame('2026-T1', csv_safety::cell('2026-T1'));
        $this->assertSame('Explain double-entry bookkeeping.', csv_safety::cell('Explain double-entry bookkeeping.'));
        $this->assertSame('MBA605.CLO1 v2', csv_safety::cell('MBA605.CLO1 v2'));
        // Negative and signed numbers are numbers, not formulas.
        $this->assertSame('-1.2500000000', csv_safety::cell('-1.2500000000'));
        $this->assertSame('+15', csv_safety::cell('+15'));
        $this->assertSame('75.00', csv_safety::cell('75.00'));
    }

    /**
     * * Tests that row guarding touches string cells only.
     */
    public function test_row_guards_strings_and_leaves_numbers(): void {
        $row = ['=evil', 12, 0.5, null, 'safe', '-3'];
        $this->assertSame(["'=evil", 12, 0.5, null, 'safe', '-3'], csv_safety::row($row));
    }
}
