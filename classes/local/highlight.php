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
 * Search-term highlighting shared by the course report pages.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local;

/**
 * * Marks the searched term inside an outcome statement.
 */
final class highlight {
    /**
     * Escape a statement and mark every occurrence of the searched term within it.
     *
     * The needle arrives already lower-cased because callers match on it too, and
     * a second fold here would let the two disagree about what counts as a hit.
     *
     * @param string $text Raw statement.
     * @param string $needle Lower-cased search term; empty for no highlighting.
     * @return string Safe HTML.
     */
    public static function mark(string $text, string $needle): string {
        if ($needle === '') {
            return s($text);
        }
        $out = '';
        $remaining = $text;
        while (($pos = \core_text::strpos(\core_text::strtolower($remaining), $needle)) !== false) {
            $out .= s(\core_text::substr($remaining, 0, $pos));
            $out .= \html_writer::tag(
                'mark',
                s(\core_text::substr($remaining, $pos, \core_text::strlen($needle))),
                ['class' => 'lom-cov-hit']
            );
            $remaining = \core_text::substr($remaining, $pos + \core_text::strlen($needle));
        }
        return $out . s($remaining);
    }
}
