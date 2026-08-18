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

namespace local_outcomemap\local;

/**
 * Shared service-layer input validation.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class input {
    /**
     * Require and clean a single-line value.
     *
     * @param mixed $value Input value.
     * @param string $field Field name.
     * @param int $maxlength Maximum length.
     * @return string
     */
    public static function required_text($value, string $field, int $maxlength): string {
        $value = clean_param((string) $value, PARAM_TEXT);
        if ($value === '') {
            throw new validation_exception('requiredfield', $field);
        }
        if (\core_text::strlen($value) > $maxlength) {
            throw new validation_exception('invalidfield', $field, 'maximum length is ' . $maxlength);
        }
        return $value;
    }

    /**
     * Clean an optional single-line value.
     *
     * @param mixed $value Input value.
     * @param string $field Field name.
     * @param int $maxlength Maximum length.
     * @return string|null
     */
    public static function optional_text($value, string $field, int $maxlength): ?string {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return self::required_text($value, $field, $maxlength);
    }

    /**
     * Clean optional plain multiline text.
     *
     * @param mixed $value Input value.
     * @return string|null
     */
    public static function optional_multiline($value): ?string {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return clean_param((string) $value, PARAM_TEXT);
    }

    /**
     * Require a positive integer.
     *
     * @param mixed $value Input value.
     * @param string $field Field name.
     * @return int
     */
    public static function positive_int($value, string $field): int {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) {
            throw new validation_exception('invalidfield', $field, 'positive integer required');
        }
        return $value;
    }

    /**
     * Normalize an optional timestamp.
     *
     * @param mixed $value Input value.
     * @param string $field Field name.
     * @return int|null
     */
    public static function optional_timestamp($value, string $field): ?int {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }
        return self::positive_int($value, $field);
    }
}
