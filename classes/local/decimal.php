<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local;

/**
 * Deterministic decimal arithmetic on canonical strings.
 *
 * Implements ADR 0003: authoritative values are scale-10 decimal strings and
 * every operation uses integer digit-string arithmetic — no binary floating
 * point and no optional extensions such as BCMath. Rounding is half away
 * from zero and applies only where a value is quantized back to scale 10.
 * Negative values are supported because Moodle question behaviours permit
 * negative marks.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class decimal {
    /** Authoritative fractional scale. */
    public const SCALE = 10;

    /** Canonical zero at scale 10. */
    public const ZERO = '0.0000000000';

    /** Canonical one at scale 10. */
    public const ONE = '1.0000000000';

    /**
     * Normalize a positive NUMBER(20,10) value.
     *
     * @param mixed $value Decimal input.
     * @param string $field Field name.
     * @return string Canonical decimal with ten fractional digits.
     */
    public static function positive($value, string $field = 'weight'): string {
        $canonical = self::canonical($value, $field, false);
        if ($canonical === self::ZERO) {
            throw new validation_exception('invaliddecimal', $field, trim((string) $value));
        }
        return $canonical;
    }

    /**
     * Require a canonical nonnegative scale-10 decimal string.
     *
     * @param mixed $value Decimal input.
     * @param string $field Field name.
     * @return string Canonical decimal with ten fractional digits.
     */
    public static function require_canonical($value, string $field): string {
        return self::canonical($value, $field, false);
    }

    /**
     * Normalize a decimal to its canonical signed scale-10 string.
     *
     * Database decimal values are accepted at their stored precision and
     * padded to scale 10. Scientific notation, locale separators, NaN,
     * infinity, and fractions beyond scale 10 are rejected.
     *
     * @param mixed $value Decimal input.
     * @param string $field Field name used in validation errors.
     * @param bool $allownegative Whether a leading minus sign is accepted.
     * @return string Canonical decimal such as -1.2500000000.
     */
    public static function canonical($value, string $field = 'value', bool $allownegative = true): string {
        $value = trim((string) $value);
        if (!preg_match('/^(-)?(\d{1,10})(?:\.(\d{1,10}))?$/D', $value, $matches)) {
            throw new validation_exception('invaliddecimal', $field, $value);
        }
        if ($matches[1] === '-' && !$allownegative) {
            throw new validation_exception('invaliddecimal', $field, $value);
        }
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches[3] ?? '', self::SCALE, '0');
        $sign = ($matches[1] === '-' && !($whole === '0' && trim($fraction, '0') === '')) ? '-' : '';
        return $sign . $whole . '.' . $fraction;
    }

    /**
     * Compare two canonical decimals.
     *
     * @param string $a Canonical decimal.
     * @param string $b Canonical decimal.
     * @return int Negative, zero, or positive like the spaceship operator.
     */
    public static function cmp(string $a, string $b): int {
        [$asign, $aunits] = self::units(self::canonical($a, 'a'));
        [$bsign, $bunits] = self::units(self::canonical($b, 'b'));
        if ($asign !== $bsign) {
            return $asign === '-' ? -1 : 1;
        }
        $unsigned = self::ucmp($aunits, $bunits);
        return $asign === '-' ? -$unsigned : $unsigned;
    }

    /**
     * Add two canonical decimals.
     *
     * @param string $a Canonical decimal.
     * @param string $b Canonical decimal.
     * @return string Canonical decimal sum.
     */
    public static function add(string $a, string $b): string {
        [$asign, $aunits] = self::units(self::canonical($a, 'a'));
        [$bsign, $bunits] = self::units(self::canonical($b, 'b'));
        if ($asign === $bsign) {
            return self::from_units($asign, self::uadd($aunits, $bunits));
        }
        $order = self::ucmp($aunits, $bunits);
        if ($order === 0) {
            return self::ZERO;
        }
        if ($order > 0) {
            return self::from_units($asign, self::usub($aunits, $bunits));
        }
        return self::from_units($bsign, self::usub($bunits, $aunits));
    }

    /**
     * Subtract one canonical decimal from another.
     *
     * @param string $a Canonical decimal.
     * @param string $b Canonical decimal to subtract.
     * @return string Canonical decimal difference.
     */
    public static function sub(string $a, string $b): string {
        return self::add($a, self::neg($b));
    }

    /**
     * Multiply two canonical decimals, quantizing the exact scale-20 product.
     *
     * @param string $a Canonical decimal.
     * @param string $b Canonical decimal.
     * @return string Canonical decimal product at scale 10.
     */
    public static function mul(string $a, string $b): string {
        [$asign, $aunits] = self::units(self::canonical($a, 'a'));
        [$bsign, $bunits] = self::units(self::canonical($b, 'b'));
        $product = self::umul($aunits, $bunits);
        // The exact product carries 2×SCALE fractional digits; drop SCALE of
        // them with half-away-from-zero rounding.
        $units = self::udroplast($product, self::SCALE);
        $sign = ($asign === $bsign) ? '' : '-';
        return self::from_units($sign, $units);
    }

    /**
     * Divide two canonical decimals at scale 10, rounding half away from zero.
     *
     * @param string $a Canonical dividend.
     * @param string $b Canonical divisor.
     * @return string Canonical decimal quotient at scale 10.
     */
    public static function div(string $a, string $b): string {
        [$asign, $aunits] = self::units(self::canonical($a, 'a'));
        [$bsign, $bunits] = self::units(self::canonical($b, 'b'));
        if (trim($bunits, '0') === '') {
            throw new validation_exception('divisionbyzero', 'denominator');
        }
        // Both operands share scale 10, so a_units/b_units is the true value.
        // One guard digit decides the half-away-from-zero rounding exactly.
        $scaled = $aunits . str_repeat('0', self::SCALE + 1);
        $quotient = self::udiv($scaled, $bunits);
        $units = self::udroplast($quotient, 1);
        $sign = ($asign === $bsign) ? '' : '-';
        return self::from_units($sign, $units);
    }

    /**
     * Return the negation of a canonical decimal.
     *
     * @param string $value Canonical decimal.
     * @return string Canonical decimal.
     */
    public static function neg(string $value): string {
        $value = self::canonical($value, 'value');
        if ($value === self::ZERO) {
            return self::ZERO;
        }
        return $value[0] === '-' ? substr($value, 1) : '-' . $value;
    }

    /**
     * Whether a canonical decimal equals zero.
     *
     * @param string $value Canonical decimal.
     * @return bool
     */
    public static function is_zero(string $value): bool {
        return self::canonical($value, 'value') === self::ZERO;
    }

    /**
     * Quantize a canonical decimal to a coarser display scale.
     *
     * The result remains a canonical scale-10 string whose digits beyond the
     * requested scale are zero, so authoritative storage never changes shape.
     *
     * @param string $value Canonical decimal.
     * @param int $displayscale Target fractional digits from 0 to 10.
     * @return string Canonical decimal rounded half away from zero.
     */
    public static function quantize(string $value, int $displayscale): string {
        if ($displayscale < 0 || $displayscale > self::SCALE) {
            throw new validation_exception('invalidfield', 'displayscale', $displayscale);
        }
        if ($displayscale === self::SCALE) {
            return self::canonical($value, 'value');
        }
        [$sign, $units] = self::units(self::canonical($value, 'value'));
        $units = self::udroplast($units, self::SCALE - $displayscale);
        $units .= str_repeat('0', self::SCALE - $displayscale);
        return self::from_units($sign, $units);
    }

    /**
     * Split a canonical decimal into sign and integer digit units at scale 10.
     *
     * @param string $canonical Canonical decimal.
     * @return array{0:string,1:string} Sign ('' or '-') and digit string.
     */
    private static function units(string $canonical): array {
        $sign = '';
        if ($canonical[0] === '-') {
            $sign = '-';
            $canonical = substr($canonical, 1);
        }
        $units = ltrim(str_replace('.', '', $canonical), '0');
        return [$sign, $units === '' ? '0' : $units];
    }

    /**
     * Rebuild a canonical decimal from sign and scale-10 integer units.
     *
     * @param string $sign Sign ('' or '-').
     * @param string $units Digit string at scale 10.
     * @return string Canonical decimal.
     */
    private static function from_units(string $sign, string $units): string {
        $units = ltrim($units, '0');
        $units = $units === '' ? '0' : $units;
        $units = str_pad($units, self::SCALE + 1, '0', STR_PAD_LEFT);
        $whole = substr($units, 0, -self::SCALE);
        $fraction = substr($units, -self::SCALE);
        if ($whole === '0' && trim($fraction, '0') === '') {
            return self::ZERO;
        }
        return $sign . $whole . '.' . $fraction;
    }

    /**
     * Compare unsigned digit strings.
     *
     * @param string $a Digit string.
     * @param string $b Digit string.
     * @return int Negative, zero, or positive.
     */
    private static function ucmp(string $a, string $b): int {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }
        return strcmp($a, $b) <=> 0;
    }

    /**
     * Add unsigned digit strings.
     *
     * @param string $a Digit string.
     * @param string $b Digit string.
     * @return string Digit string sum.
     */
    private static function uadd(string $a, string $b): string {
        $length = max(strlen($a), strlen($b));
        $a = str_pad($a, $length, '0', STR_PAD_LEFT);
        $b = str_pad($b, $length, '0', STR_PAD_LEFT);
        $carry = 0;
        $digits = '';
        for ($i = $length - 1; $i >= 0; $i--) {
            $sum = (int) $a[$i] + (int) $b[$i] + $carry;
            $carry = intdiv($sum, 10);
            $digits = ($sum % 10) . $digits;
        }
        return $carry > 0 ? $carry . $digits : $digits;
    }

    /**
     * Subtract unsigned digit strings where the first is not smaller.
     *
     * @param string $a Digit string minuend.
     * @param string $b Digit string subtrahend, not larger than the minuend.
     * @return string Digit string difference.
     */
    private static function usub(string $a, string $b): string {
        $length = max(strlen($a), strlen($b));
        $a = str_pad($a, $length, '0', STR_PAD_LEFT);
        $b = str_pad($b, $length, '0', STR_PAD_LEFT);
        $borrow = 0;
        $digits = '';
        for ($i = $length - 1; $i >= 0; $i--) {
            $diff = (int) $a[$i] - (int) $b[$i] - $borrow;
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $digits = $diff . $digits;
        }
        $digits = ltrim($digits, '0');
        return $digits === '' ? '0' : $digits;
    }

    /**
     * Multiply unsigned digit strings with long multiplication.
     *
     * @param string $a Digit string.
     * @param string $b Digit string.
     * @return string Digit string product.
     */
    private static function umul(string $a, string $b): string {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if ($a === '' || $b === '') {
            return '0';
        }
        $alen = strlen($a);
        $blen = strlen($b);
        $result = array_fill(0, $alen + $blen, 0);
        for ($i = $alen - 1; $i >= 0; $i--) {
            $carry = 0;
            for ($j = $blen - 1; $j >= 0; $j--) {
                $sum = $result[$i + $j + 1] + ((int) $a[$i]) * ((int) $b[$j]) + $carry;
                $result[$i + $j + 1] = $sum % 10;
                $carry = intdiv($sum, 10);
            }
            $result[$i] += $carry;
        }
        $digits = ltrim(implode('', $result), '0');
        return $digits === '' ? '0' : $digits;
    }

    /**
     * Integer-divide unsigned digit strings.
     *
     * @param string $a Digit string dividend.
     * @param string $b Digit string divisor, not zero.
     * @return string Digit string quotient.
     */
    private static function udiv(string $a, string $b): string {
        $a = ltrim($a, '0');
        if ($a === '') {
            return '0';
        }
        $quotient = '';
        $remainder = '';
        $length = strlen($a);
        for ($i = 0; $i < $length; $i++) {
            $remainder = ltrim($remainder . $a[$i], '0');
            $remainder = $remainder === '' ? '0' : $remainder;
            $digit = 0;
            while (self::ucmp($remainder, $b) >= 0) {
                $remainder = self::usub($remainder, $b);
                $digit++;
            }
            $quotient .= $digit;
        }
        $quotient = ltrim($quotient, '0');
        return $quotient === '' ? '0' : $quotient;
    }

    /**
     * Drop trailing digits with half-away-from-zero rounding.
     *
     * The first dropped digit alone decides the rounding: five or more rounds
     * the retained magnitude up, which is away from zero for either sign.
     *
     * @param string $units Digit string.
     * @param int $count Digits to drop.
     * @return string Digit string.
     */
    private static function udroplast(string $units, int $count): string {
        if ($count <= 0) {
            return $units;
        }
        $units = str_pad($units, $count + 1, '0', STR_PAD_LEFT);
        $kept = substr($units, 0, -$count);
        $firstdropped = (int) $units[strlen($units) - $count];
        if ($firstdropped >= 5) {
            $kept = self::uadd($kept, '1');
        }
        $kept = ltrim($kept, '0');
        return $kept === '' ? '0' : $kept;
    }
}
