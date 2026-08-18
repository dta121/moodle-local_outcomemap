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
 * UUID generation and validation.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class uuid {
    /**
     * Generate a lowercase RFC 4122 version 4 UUID.
     *
     * @return string
     */
    public static function generate(): string {
        return strtolower(\core\uuid::generate());
    }

    /**
     * Validate and normalize a UUID.
     *
     * @param string $value UUID value.
     * @return string Lowercase UUID.
     */
    public static function normalize(string $value): string {
        $value = strtolower(trim($value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new validation_exception('invaliduuid', 'uuid', $value);
        }
        return $value;
    }

    /**
     * Return an imported UUID or generate one when omitted.
     *
     * @param string|null $value Imported value.
     * @return string
     */
    public static function normalize_or_generate(?string $value): string {
        return $value === null || trim($value) === '' ? self::generate() : self::normalize($value);
    }
}
