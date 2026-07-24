<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\reportbuilder\local\filters;

use core_reportbuilder\local\filters\cohort;
use core_reportbuilder\local\helpers\database;

/**
 * Cohort selector that preserves one row per learner result.
 *
 * The core cohort filter compares selected cohort IDs with one field. This
 * variant treats the configured field as a user ID and applies membership by
 * correlated EXISTS, avoiding the fan-out caused by joining cohort_members.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cohort_membership extends cohort {
    /**
     * Return a grain-preserving cohort-membership predicate.
     *
     * @param array $values Submitted filter values.
     * @return array{0:string,1:array} SQL and parameters.
     */
    public function get_sql_filter(array $values): array {
        global $DB;

        $cohortids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($values["{$this->name}_values"] ?? [])),
            static fn(int $cohortid): bool => $cohortid > 0
        )));
        if ($cohortids === []) {
            return ['', []];
        }

        $userfieldsql = $this->filter->get_field_sql();
        $fieldparams = $this->filter->get_field_params();
        [$cohortsql, $cohortparams] = $DB->get_in_or_equal(
            $cohortids,
            SQL_PARAMS_NAMED,
            database::generate_param_name('_')
        );
        $memberalias = database::generate_alias('cohortmember');
        $sql = "EXISTS (
                    SELECT 1
                      FROM {cohort_members} {$memberalias}
                     WHERE {$memberalias}.userid = {$userfieldsql}
                       AND {$memberalias}.cohortid {$cohortsql}
                )";

        return [$sql, array_merge($fieldparams, $cohortparams)];
    }
}
