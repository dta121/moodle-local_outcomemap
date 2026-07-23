<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local;

/**
 * Decimal-string validation without binary floating-point conversion.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class decimal {
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
        $value = trim((string) $value);
        if (!preg_match('/^(\d{1,10})(?:\.(\d{1,10}))?$/D', $value, $matches)) {
            throw new validation_exception('invaliddecimal', $field, $value);
        }
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches[2] ?? '', self::SCALE, '0');
        if ($whole === '0' && trim($fraction, '0') === '') {
            throw new validation_exception('invaliddecimal', $field, $value);
        }
        return $whole . '.' . $fraction;
    }

    /**
     * Add two canonical nonnegative scale-10 decimals without binary floating point.
     *
     * @param string $a Canonical decimal.
     * @param string $b Canonical decimal.
     * @return string Canonical decimal sum.
     */
    public static function add(string $a, string $b): string {
        $adigits = str_replace('.', '', self::require_canonical($a, 'a'));
        $bdigits = str_replace('.', '', self::require_canonical($b, 'b'));
        $length = max(strlen($adigits), strlen($bdigits));
        $adigits = str_pad($adigits, $length, '0', STR_PAD_LEFT);
        $bdigits = str_pad($bdigits, $length, '0', STR_PAD_LEFT);
        $carry = 0;
        $digits = '';
        for ($i = $length - 1; $i >= 0; $i--) {
            $sum = (int) $adigits[$i] + (int) $bdigits[$i] + $carry;
            $carry = intdiv($sum, 10);
            $digits = ($sum % 10) . $digits;
        }
        if ($carry > 0) {
            $digits = $carry . $digits;
        }
        $whole = ltrim(substr($digits, 0, -self::SCALE), '0');
        return ($whole === '' ? '0' : $whole) . '.' . substr($digits, -self::SCALE);
    }

    /**
     * Require a canonical nonnegative scale-10 decimal string.
     *
     * Database NUMBER(20,10) values are accepted at their stored precision and
     * padded to the canonical scale; scientific notation and signs are rejected.
     *
     * @param mixed $value Decimal input.
     * @param string $field Field name.
     * @return string Canonical decimal with ten fractional digits.
     */
    public static function require_canonical($value, string $field): string {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{1,10})(?:\.(\d{1,10}))?$/D', $value, $matches)) {
            throw new validation_exception('invaliddecimal', $field, $value);
        }
        $whole = ltrim($matches[1], '0');
        return ($whole === '' ? '0' : $whole) . '.' . str_pad($matches[2] ?? '', self::SCALE, '0');
    }
}
