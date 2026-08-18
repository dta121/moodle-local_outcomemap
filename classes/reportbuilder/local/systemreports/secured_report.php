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

namespace local_outcomemap\reportbuilder\local\systemreports;

use context;
use core_reportbuilder\system_report;
use local_outcomemap\reportbuilder\local\access;

/**
 * Reusable access base for embedded outcome-mapping system reports.
 *
 * Concrete reports still validate cleaned parameters in
 * can_view_parameters(), which is called for AJAX and downloads as part of
 * the final can_view implementation.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class secured_report extends system_report {
    /**
     * Capabilities that are all required in the report access context.
     *
     * @return string[]
     */
    abstract protected function get_required_capabilities(): array;

    /**
     * Optional capabilities of which at least one is required.
     *
     * @return string[]
     */
    protected function get_any_capabilities(): array {
        return [];
    }

    /**
     * Context used for access checks.
     *
     * @return context
     */
    protected function get_access_context(): context {
        return $this->get_context();
    }

    /**
     * Validate any report parameters after get_parameter() cleaning.
     *
     * @return bool
     */
    protected function can_view_parameters(): bool {
        return true;
    }

    /**
     * Repeat access checks independently of the embedding page.
     *
     * @return bool
     */
    final protected function can_view(): bool {
        return access::can_view(
            $this->get_access_context(),
            $this->get_required_capabilities(),
            $this->get_any_capabilities()
        ) && $this->can_view_parameters();
    }
}
