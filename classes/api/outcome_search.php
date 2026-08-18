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

namespace local_outcomemap\api;

use local_outcomemap\local\dto\outcome;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Public context-scoped search service for approved effective outcomes.
 */
final class outcome_search {
    /**
     * 1.1 adds {@see count()} and the optional search offset.
     */
    public const API_VERSION = '1.1';

    /**
     * Count approved outcome versions visible in a Moodle context.
     *
     * Callers that page or cap {@see search()} use this to report how much of
     * the visible set they are showing, rather than implying the list is whole.
     *
     * @param \context $context Authoritative caller context.
     * @param string $query Code or statement fragment.
     * @param int|null $effectiveat Effective timestamp; defaults to now.
     * @return int Total matching outcome versions.
     */
    public static function count(\context $context, string $query = '', ?int $effectiveat = null): int {
        global $DB;
        require_capability('local/outcomemap:viewdefinitions', $context);
        $effectiveat = $effectiveat ?? time();
        [$where, $params] = self::build_filter($context, $query, $effectiveat);
        return (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {local_outcomemap_itemver} v
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE " . implode(' AND ', $where),
            $params
        );
    }

    /**
     * Search approved outcome versions visible in a Moodle context.
     *
     * @param \context $context Authoritative caller context.
     * @param string $query Code or statement fragment.
     * @param int|null $effectiveat Effective timestamp; defaults to now.
     * @param int $limit Maximum 1-200 records.
     * @param int $offset Records to skip, for callers paging a larger set.
     * @return outcome[]
     */
    public static function search(
        \context $context,
        string $query = '',
        ?int $effectiveat = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        global $DB;
        require_capability('local/outcomemap:viewdefinitions', $context);
        $effectiveat = $effectiveat ?? time();
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        [$where, $params] = self::build_filter($context, $query, $effectiveat);
        $sql = "SELECT v.id, i.uuid, i.code, f.uuid AS frameworkuuid, f.code AS frameworkcode,
                       v.uuid AS versionuuid, v.version, v.statement, v.shortstatement,
                       v.bloomlevel, v.effectivefrom, v.effectiveto
                  FROM {local_outcomemap_itemver} v
                  JOIN {local_outcomemap_item} i ON i.id = v.itemid
                  JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
                 WHERE " . implode(' AND ', $where) . '
              ORDER BY f.code, i.code, v.version DESC';
        $records = $DB->get_records_sql($sql, $params, $offset, $limit);
        $results = [];
        foreach ($records as $record) {
            $results[] = new outcome(
                $record->uuid,
                $record->code,
                $record->frameworkuuid,
                $record->frameworkcode,
                $record->versionuuid,
                (int) $record->version,
                $record->statement,
                $record->shortstatement,
                $record->bloomlevel,
                (int) $record->effectivefrom,
                $record->effectiveto === null ? null : (int) $record->effectiveto,
            );
        }
        return $results;
    }

    /**
     * Require one exact outcome version to be visible and effective in a context.
     *
     * This repeats the same owner scoping as {@see search()} for mutation APIs
     * that receive a posted stable UUID rather than trusting form options.
     *
     * @param \context $context Authoritative question context.
     * @param string $versionuuid Exact outcome-version UUID.
     * @param int $effectiveat Proposed mapping effective timestamp.
     * @return void
     */
    public static function require_visible_version(
        \context $context,
        string $versionuuid,
        int $effectiveat
    ): void {
        global $DB;
        require_capability('local/outcomemap:viewdefinitions', $context);
        $params = [
            'versionuuid' => $versionuuid,
            'approvedfw' => workflow::APPROVED,
            'approveditem' => workflow::APPROVED,
            'approvedversion' => workflow::APPROVED,
            'effectiveat1' => $effectiveat,
            'effectiveat2' => $effectiveat,
        ];
        $where = [
            'v.uuid = :versionuuid',
            'f.status = :approvedfw',
            'i.status = :approveditem',
            'v.status = :approvedversion',
            'v.effectivefrom <= :effectiveat1',
            '(v.effectiveto IS NULL OR v.effectiveto > :effectiveat2)',
        ];
        self::add_context_scope($context, $effectiveat, $where, $params);
        $sql = "SELECT v.id
                  FROM {local_outcomemap_itemver} v
                  JOIN {local_outcomemap_item} i ON i.id = v.itemid
                  JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
                 WHERE " . implode(' AND ', $where);
        if (!$DB->record_exists_sql($sql, $params)) {
            throw new validation_exception('recordnotfound', 'outcome_version', $versionuuid);
        }
    }

    /**
     * Build the shared approved-and-effective filter with owner scoping.
     *
     * Extracted so {@see search()} and {@see count()} can never disagree about
     * which outcome versions a context may see.
     *
     * @param \context $context Authoritative caller context.
     * @param string $query Code or statement fragment.
     * @param int $effectiveat Effective timestamp.
     * @return array{0:string[],1:array} WHERE fragments and named parameters.
     */
    private static function build_filter(\context $context, string $query, int $effectiveat): array {
        global $DB;
        $params = [
            'approvedfw' => workflow::APPROVED,
            'approveditem' => workflow::APPROVED,
            'approvedversion' => workflow::APPROVED,
            'effectiveat1' => $effectiveat,
            'effectiveat2' => $effectiveat,
        ];
        $where = [
            'f.status = :approvedfw',
            'i.status = :approveditem',
            'v.status = :approvedversion',
            'v.effectivefrom <= :effectiveat1',
            '(v.effectiveto IS NULL OR v.effectiveto > :effectiveat2)',
        ];
        $query = trim(clean_param($query, PARAM_TEXT));
        if ($query !== '') {
            $params['querycode'] = '%' . $DB->sql_like_escape($query) . '%';
            $params['querystatement'] = '%' . $DB->sql_like_escape($query) . '%';
            $where[] = '(' . $DB->sql_like('i.code', ':querycode', false)
                . ' OR ' . $DB->sql_like('v.statement', ':querystatement', false) . ')';
        }
        self::add_context_scope($context, $effectiveat, $where, $params);
        return [$where, $params];
    }

    /**
     * Add owner scoping for non-system contexts.
     *
     * @param \context $context Context.
     * @param int $effectiveat Effectiveat.
     * @param array $where Where.
     * @param array $params Params.
     */
    private static function add_context_scope(\context $context, int $effectiveat, array &$where, array &$params): void {
        global $DB;
        if ($context->contextlevel === CONTEXT_SYSTEM) {
            return;
        }
        if ($context->contextlevel === CONTEXT_COURSE) {
            $moodlecourseid = (int) $context->instanceid;
        } else if ($context->contextlevel === CONTEXT_MODULE) {
            $moodlecourseid = (int) $DB->get_field('course_modules', 'course', ['id' => $context->instanceid], MUST_EXIST);
        } else {
            throw new validation_exception('invalidfield', 'context', 'course or system context required');
        }
        $catalogids = $DB->get_fieldset_select(
            'local_outcomemap_cinst',
            'courseid',
            'moodlecourseid = :moodlecourseid AND status = :status AND confirmed = :confirmed',
            [
                'moodlecourseid' => $moodlecourseid,
                'status' => workflow::APPROVED,
                'confirmed' => 1,
            ]
        );
        $catalogids = array_values(array_unique(array_map('intval', $catalogids)));
        $scope = ['f.ownertype = :institution'];
        $params['institution'] = 'institution';
        if ($catalogids) {
            $params['catalogtype'] = 'catalog_course';
            [$courseinsql, $courseparams] = $DB->get_in_or_equal($catalogids, SQL_PARAMS_NAMED, 'catalogcourse');
            $params += $courseparams;
            $scope[] = '(f.ownertype = :catalogtype AND f.ownerid ' . $courseinsql . ')';
            $sql = 'SELECT programid FROM {local_outcomemap_progcourse}
                     WHERE courseid ' . $courseinsql . ' AND status = :membershipstatus
                       AND effectivefrom <= :at1 AND (effectiveto IS NULL OR effectiveto > :at2)';
            $programids = $DB->get_fieldset_sql($sql, $courseparams + [
                'membershipstatus' => workflow::APPROVED, 'at1' => $effectiveat, 'at2' => $effectiveat,
            ]);
            $programids = array_values(array_unique(array_map('intval', $programids)));
            if ($programids) {
                [$insql, $inparams] = $DB->get_in_or_equal($programids, SQL_PARAMS_NAMED, 'program');
                $params['programtype'] = 'program';
                            $params += $inparams;
                            $scope[] = '(f.ownertype = :programtype AND f.ownerid ' . $insql . ')';
            }
        }
        $where[] = '(' . implode(' OR ', $scope) . ')';
    }
}
