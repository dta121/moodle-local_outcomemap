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
 * Optional feature switches for local_outcomemap.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local;

/**
 * Reports which optional parts of the plugin an administrator has turned on.
 *
 * A switch here removes a feature from every entry point that offers it. It
 * never deletes stored records: an institution that turns a feature off still
 * holds the governed history it created, and subject-access export and deletion
 * must keep working on that history regardless of the switch.
 */
final class feature {
    /**
     * Whether the remediation feature set is available.
     *
     * Treat an unset setting as enabled so an upgrade does not silently withdraw
     * recommendations an institution is already relying on.
     *
     * @return bool
     */
    public static function remediation_enabled(): bool {
        $configured = get_config('local_outcomemap', 'enableremediation');
        return $configured === false ? true : (bool) $configured;
    }

    /**
     * Stop a request that reached a disabled feature by direct URL.
     *
     * @param bool $enabled Result of the matching switch check.
     * @param string $identifier Language string naming the disabled feature.
     * @return void
     */
    public static function require_enabled(bool $enabled, string $identifier): void {
        if (!$enabled) {
            throw new \moodle_exception($identifier, 'local_outcomemap');
        }
    }
}
