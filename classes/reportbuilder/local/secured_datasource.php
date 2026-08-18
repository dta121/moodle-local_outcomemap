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

namespace local_outcomemap\reportbuilder\local;

use context_system;
use core_reportbuilder\datasource;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\report_access_exception;
use local_outcomemap\reportbuilder\local\entities\report_record;

/**
 * Capability-enforcing base for custom Report Builder data sources.
 *
 * Moodle custom data sources do not have the system-report can_view hook.
 * This base therefore checks access while every source instance is built,
 * including AJAX, download, and scheduled execution paths. Sources containing
 * course/question data can additionally declare an exact scoped access check
 * and must apply the corresponding allowed-ID SQL condition to their rows.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class secured_datasource extends datasource {
    /**
     * Capabilities that are all required in the applicable access context.
     *
     * @return string[]
     */
    abstract protected static function get_required_capabilities(): array;

    /**
     * Optional capabilities of which at least one is required.
     *
     * @return string[]
     */
    protected static function get_any_capabilities(): array {
        return [];
    }

    /**
     * Whether at least one source-specific lower context is accessible.
     *
     * Sources overriding this method must independently constrain their SQL to
     * the same allowed contexts. Globally governed sources intentionally keep
     * the default false rather than exposing rows with no authoritative lower
     * context.
     *
     * @return bool
     */
    protected static function can_view_scoped(): bool {
        return false;
    }

    /**
     * Whether all source capabilities are granted at system context.
     *
     * @return bool
     */
    final protected static function has_global_access(): bool {
        return access::can_view(
            context_system::instance(),
            static::get_required_capabilities(),
            static::get_any_capabilities()
        );
    }

    /**
     * * Perform the source-specific setup after access has been checked.
     */
    abstract protected function initialise_source(): void;

    /**
     * Whether the current user may execute this source.
     *
     * @return bool
     */
    final public static function can_view(): bool {
        return static::has_global_access() || static::can_view_scoped();
    }

    /**
     * Hide inaccessible sources from source selection as defence in depth.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return parent::is_available() && static::can_view();
    }

    /**
     * * Enforce access before any query definition is exposed.
     */
    final protected function initialise(): void {
        if (!static::can_view()) {
            throw new report_access_exception();
        }

        $this->initialise_source();
    }

    /**
     * Add a parameterised allowed-ID condition to the report base query.
     *
     * @param string $fieldsql SQL field containing the authoritative scope ID.
     * @param int[] $allowedids Exact IDs allowed after Moodle capability checks.
     * @param bool $allowmissing Whether globally authorised rows with no lower scope are allowed.
     */
    final protected function add_allowed_id_condition(
        string $fieldsql,
        array $allowedids,
        bool $allowmissing = false
    ): void {
        global $DB;

        $allowedids = array_values(array_unique(array_filter(array_map('intval', $allowedids))));
        if ($allowedids === []) {
            $this->add_base_condition_sql($allowmissing ? "{$fieldsql} IS NULL" : '1 = 0');
            return;
        }

        $prefix = database::generate_param_name() . 'scope';
        [$insql, $params] = $DB->get_in_or_equal($allowedids, SQL_PARAMS_NAMED, $prefix);
        $condition = "{$fieldsql} {$insql}";
        if ($allowmissing) {
            $condition = "({$fieldsql} IS NULL OR {$condition})";
        }
        $this->add_base_condition_sql($condition, $params);
    }

    /**
     * Register the configured local entity and all of its elements.
     *
     * @param report_record $entity Entity instance.
     * @param string $maintable Main database table.
     */
    final protected function register_entity(report_record $entity, string $maintable): void {
        $this->set_main_table($maintable, $entity->get_table_alias($maintable));
        $this->add_entity($entity);
        $this->add_all_from_entity($entity->get_entity_name());
    }

    /**
     * Sources do not impose report conditions by default.
     *
     * @return string[]
     */
    final public function get_default_conditions(): array {
        return [];
    }
}
