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
 * Learning Outcome Mapping plugin component.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local;

/**
 * Immutable result of validating a foundation CSV import.
 */
final class import_preview {
    /**
     * @var array<int,object>
     */
    public readonly array $rows;
    /**
     * Hash of the validated import.
     *
     * @var string
     */
    public readonly string $hash;
    /**
     * Whether every row is valid.
     *
     * @var bool
     */
    public readonly bool $valid;

    /**
     * Constructor.
     */
    public function __construct(array $rows, string $hash, bool $valid) {
        $this->rows = $rows;
        $this->hash = $hash;
        $this->valid = $valid;
    }

    /**
     * Number of data rows.
     */
    public function count(): int {
        return count($this->rows);
    }
}
