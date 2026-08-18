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

use local_outcomemap\local\decimal;
use local_outcomemap\local\validation_exception;

/**
 * Boundary tests for deterministic decimal arithmetic.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_outcomemap\local\decimal
 */
final class decimal_test extends \basic_testcase {
    /**
     * * Tests canonical parsing and rejection rules.
     */
    public function test_canonical_parsing(): void {
        $this->assertSame('0.5000000000', decimal::canonical('0.5'));
        $this->assertSame('-1.2500000000', decimal::canonical('-1.25'));
        $this->assertSame('0.0000000000', decimal::canonical('-0'));
        $this->assertSame('12.0000000000', decimal::canonical('012'));
        foreach (['1e5', '1,5', 'NAN', 'INF', '1.', '.5', '--1', '1.12345678901'] as $bad) {
            try {
                decimal::canonical($bad);
                $this->fail('Value must be rejected: ' . $bad);
            } catch (validation_exception $e) {
                $this->assertSame('invaliddecimal', $e->errorcode);
            }
        }
        try {
            decimal::canonical('-1', 'value', false);
            $this->fail('Negatives must be rejected when disallowed.');
        } catch (validation_exception $e) {
            $this->assertSame('invaliddecimal', $e->errorcode);
        }
    }

    /**
     * * Tests signed addition and subtraction.
     */
    public function test_signed_addition(): void {
        $this->assertSame('1.0000000000', decimal::add('0.9999999999', '0.0000000001'));
        $this->assertSame('0.0000000000', decimal::add('1.5', '-1.5'));
        $this->assertSame('-0.2500000000', decimal::add('0.5', '-0.75'));
        $this->assertSame('-2.0000000000', decimal::add('-0.75', '-1.25'));
        $this->assertSame('0.2500000000', decimal::sub('0.5', '0.25'));
        $this->assertSame('-0.7500000000', decimal::sub('0.25', '1'));
    }

    /**
     * * Tests multiplication with exact intermediate precision.
     */
    public function test_multiplication(): void {
        $this->assertSame('0.1250000000', decimal::mul('0.5', '0.25'));
        $this->assertSame('4.2000000000', decimal::mul('6', '0.7'));
        $this->assertSame('-4.2000000000', decimal::mul('-6', '0.7'));
        $this->assertSame('4.2000000000', decimal::mul('-6', '-0.7'));
        // The scale-20 exact product rounds half away from zero at scale 10.
        $this->assertSame('0.0000000001', decimal::mul('0.0000000005', '0.1'));
        $this->assertSame('-0.0000000001', decimal::mul('-0.0000000005', '0.1'));
        $this->assertSame('0.0000000000', decimal::mul('0.0000000004', '0.1'));
        $this->assertSame('0.9999999998', decimal::mul('0.9999999999', '0.9999999999'));
    }

    /**
     * * Tests repeating division and the half-away-from-zero guard digit.
     */
    public function test_division(): void {
        $this->assertSame('0.3333333333', decimal::div('1', '3'));
        $this->assertSame('0.6666666667', decimal::div('2', '3'));
        $this->assertSame('-0.6666666667', decimal::div('-2', '3'));
        $this->assertSame('0.5000000000', decimal::div('1', '2'));
        $this->assertSame('81.4285714286', decimal::div('570', '7'));
        $this->assertSame('0.0000000001', decimal::div('0.0000000001', '1'));
        try {
            decimal::div('1', '0');
            $this->fail('Division by zero must be rejected.');
        } catch (validation_exception $e) {
            $this->assertSame('divisionbyzero', $e->errorcode);
        }
    }

    /**
     * * Tests comparison including sign handling.
     */
    public function test_comparison(): void {
        $this->assertSame(0, decimal::cmp('85', '85.0000000000'));
        $this->assertSame(-1, decimal::cmp('84.9999999999', '85'));
        $this->assertSame(1, decimal::cmp('85.0000000001', '85'));
        $this->assertSame(-1, decimal::cmp('-1', '0.5'));
        $this->assertSame(1, decimal::cmp('-0.5', '-1'));
        $this->assertTrue(decimal::is_zero('0.0000000000'));
        $this->assertFalse(decimal::is_zero('0.0000000001'));
    }

    /**
     * * Tests display quantization at policy boundaries.
     */
    public function test_quantize(): void {
        $this->assertSame('85.1000000000', decimal::quantize('85.0500000000', 1));
        $this->assertSame('85.0000000000', decimal::quantize('85.0499999999', 1));
        $this->assertSame('-85.1000000000', decimal::quantize('-85.0500000000', 1));
        $this->assertSame('81.4300000000', decimal::quantize('81.4285714286', 2));
        $this->assertSame('100.0000000000', decimal::quantize('99.9999999999', 0));
        $this->assertSame('85.0500000000', decimal::quantize('85.05', 10));
    }
}
