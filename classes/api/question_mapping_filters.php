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

namespace local_outcomemap\api;

use core\output\datafilter;
use local_outcomemap\local\validation_exception;

/**
 * Capability-aware SQL predicates for companion question-bank filters.
 *
 * Returned fragments target Moodle's supported question-bank `qv` alias. The
 * companion never receives local table names or constructs local joins itself.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_mapping_filters {
    /**
     * Public API version.
     */
    public const API_VERSION = '1.0';
    /**
     * Value for outcome.
     */
    public const OUTCOME = 'outcome';
    /**
     * Value for role.
     */
    public const ROLE = 'role';
    /**
     * Value for status.
     */
    public const STATUS = 'status';
    /**
     * Value for mapped.
     */
    public const MAPPED = 'mapped';
    /**
     * Value for invalid weight.
     */
    public const INVALID_WEIGHT = 'invalid_weight';
    /**
     * Value for copied pending.
     */
    public const COPIED_PENDING = 'copied_pending';

    /**
     * @var string[] Supported mapping roles.
     */
    private const ROLES = ['teaches', 'practices', 'assesses', 'alignment_only', 'remediates'];
    /**
     * @var string[] Canonical workflow states.
     */
    private const STATUSES = ['draft', 'needs_review', 'approved', 'retired'];

    /**
     * Build one filter predicate after checking the caller's authoritative contexts.
     *
     * @param string $criterion One of this class's criterion constants.
     * @param array $filter Core question-bank filter data.
     * @param \context[] $contexts Question-bank contexts for the current view.
     * @return array{0:string,1:array} SQL predicate and named parameters.
     */
    public static function build(string $criterion, array $filter, array $contexts): array {
        [$scopewhere, $scopeparams] = self::authorized_question_versions($contexts);
        if ($scopewhere === '1 = 0') {
            return [$scopewhere, []];
        }
        switch ($criterion) {
            case self::OUTCOME:
                $predicate = self::outcome($filter);
                break;
            case self::ROLE:
                $predicate = self::field_values($filter, 'role', self::ROLES, 'qbomrole');
                break;
            case self::STATUS:
                $predicate = self::field_values($filter, 'status', self::STATUSES, 'qbomstatus');
                break;
            case self::MAPPED:
                $predicate = self::binary($filter, self::mapped_exists());
                break;
            case self::INVALID_WEIGHT:
                $predicate = self::invalid_weight($filter);
                break;
            case self::COPIED_PENDING:
                $predicate = self::binary($filter, self::copied_pending_exists());
                break;
            default:
                throw new validation_exception('invalidfield', 'criterion', $criterion);
        }
        if ($predicate[0] === '') {
            return $predicate;
        }
        if ($scopewhere === '') {
            return $predicate;
        }
        return [
            '(' . $scopewhere . ') AND (' . $predicate[0] . ')',
            array_merge($scopeparams, $predicate[1]),
        ];
    }

    /**
     * Restrict predicates to authoritative question contexts the caller may view.
     *
     * Moodle 4.5 supplies the current question context and its ancestors, while
     * Moodle 5.2 supplies the current bank context. Candidate rows belong to one
     * of those exact contexts. The system-only static compatibility path is
     * intentionally unrestricted only for a caller with the system capability.
     *
     * @param \context[] $contexts Question-bank contexts.
     * @return array{0:string,1:array} Scope predicate and parameters.
     */
    private static function authorized_question_versions(array $contexts): array {
        global $DB;
        $uniquecontexts = [];
        foreach ($contexts as $context) {
            if ($context instanceof \context) {
                $uniquecontexts[(int) $context->id] = $context;
            }
        }
        if (count($uniquecontexts) === 1) {
            $context = reset($uniquecontexts);
            if ($context->contextlevel === CONTEXT_SYSTEM) {
                return has_capability('local/outcomemap:viewdefinitions', $context)
                    ? ['', []]
                    : ['1 = 0', []];
            }
        }

        $allowedcontextids = [];
        foreach ($uniquecontexts as $context) {
            if (has_capability('local/outcomemap:viewdefinitions', $context)) {
                $allowedcontextids[] = (int) $context->id;
            }
        }
        if (!$allowedcontextids) {
            return ['1 = 0', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($allowedcontextids, SQL_PARAMS_NAMED, 'qbomctx');
        return [
            'EXISTS (SELECT 1
                       FROM {question_versions} qbomqv
                       JOIN {question_bank_entries} qbomqbe ON qbomqbe.id = qbomqv.questionbankentryid
                       JOIN {question_categories} qbomqc ON qbomqc.id = qbomqbe.questioncategoryid
                      WHERE qbomqv.id = qv.id AND qbomqc.contextid ' . $insql . ')',
            $params,
        ];
    }

    /**
     * Build the outcome/framework keyword predicate.
     *
     * @param array $filter Filter.
     */
    private static function outcome(array $filter): array {
        global $DB;
        $values = [];
        foreach ($filter['values'] ?? [] as $value) {
            $value = trim(clean_param((string) $value, PARAM_TEXT));
            if ($value !== '') {
                $values[] = $value;
            }
        }
        if (!$values) {
            return ['', []];
        }

        $jointype = self::jointype($filter);
        $fragments = [];
        $params = [];
        $fullcode = $DB->sql_concat('qbomf.code', "'.'", 'qbomi.code');
        foreach (array_values($values) as $index => $value) {
            $matches = [];
            foreach (['code', 'fullcode', 'statement', 'frameworkcode', 'frameworkname'] as $fieldindex => $field) {
                $param = 'qbomterm' . $index . 'field' . $fieldindex;
                $params[$param] = '%' . $DB->sql_like_escape($value) . '%';
                $placeholder = ':' . $param;
                if ($field === 'code') {
                    $matches[] = $DB->sql_like('qbomi.code', $placeholder, false);
                } else if ($field === 'fullcode') {
                    $matches[] = $DB->sql_like($fullcode, $placeholder, false);
                } else if ($field === 'statement') {
                    $matches[] = $DB->sql_like('qbomv.statement', $placeholder, false);
                } else if ($field === 'frameworkcode') {
                    $matches[] = $DB->sql_like('qbomf.code', $placeholder, false);
                } else {
                    $matches[] = $DB->sql_like('qbomf.name', $placeholder, false);
                }
            }
            $exists = 'EXISTS (SELECT 1
                                 FROM {local_outcomemap_qmap} qbomm
                                 JOIN {local_outcomemap_itemver} qbomv ON qbomv.id = qbomm.itemverid
                                 JOIN {local_outcomemap_item} qbomi ON qbomi.id = qbomv.itemid
                                 JOIN {local_outcomemap_fw} qbomf ON qbomf.id = qbomi.frameworkid
                                WHERE qbomm.questionversionid = qv.id
                                  AND (' . implode(' OR ', $matches) . '))';
            $fragments[] = $jointype === datafilter::JOINTYPE_NONE ? 'NOT ' . $exists : $exists;
        }
        $glue = $jointype === datafilter::JOINTYPE_ANY ? ' OR ' : ' AND ';
        return ['(' . implode($glue, $fragments) . ')', $params];
    }

    /**
     * Resolve a supported core join type, clamping unknown values to ANY.
     *
     * Without this an out-of-range value would fall through to the restrictive
     * AND glue rather than the documented permissive default.
     *
     * @param array $filter Core question-bank filter data.
     * @return int One of datafilter's supported join types.
     */
    private static function jointype(array $filter): int {
        $jointype = (int) ($filter['jointype'] ?? datafilter::JOINTYPE_ANY);
        $allowed = [
            datafilter::JOINTYPE_ANY,
            datafilter::JOINTYPE_ALL,
            datafilter::JOINTYPE_NONE,
        ];
        return in_array($jointype, $allowed, true) ? $jointype : datafilter::JOINTYPE_ANY;
    }

    /**
     * Build role/status predicates where separate mappings may satisfy each selected value.
     *
     * @param array $filter Filter.
     * @param string $field Field.
     * @param array $allowed Allowed.
     * @param string $prefix Prefix.
     */
    private static function field_values(array $filter, string $field, array $allowed, string $prefix): array {
        $values = [];
        foreach ($filter['values'] ?? [] as $value) {
            $value = clean_param((string) $value, PARAM_ALPHANUMEXT);
            if (in_array($value, $allowed, true)) {
                $values[] = $value;
            }
        }
        if (!$values) {
            return ['', []];
        }

        $jointype = self::jointype($filter);
        $fragments = [];
        $params = [];
        foreach (array_values(array_unique($values)) as $index => $value) {
            $param = $prefix . $index;
            $params[$param] = $value;
            $exists = 'EXISTS (SELECT 1 FROM {local_outcomemap_qmap} qbomm
                                WHERE qbomm.questionversionid = qv.id
                                  AND qbomm.' . $field . ' = :' . $param . ')';
            $fragments[] = $jointype === datafilter::JOINTYPE_NONE ? 'NOT ' . $exists : $exists;
        }
        $glue = $jointype === datafilter::JOINTYPE_ANY ? ' OR ' : ' AND ';
        return ['(' . implode($glue, $fragments) . ')', $params];
    }

    /**
     * Build a fixed yes/no condition around an EXISTS expression.
     *
     * @param array $filter Filter.
     * @param string $exists Exists.
     * @param array $params Params.
     */
    private static function binary(array $filter, string $exists, array $params = []): array {
        $values = $filter['values'] ?? [];
        if ($values === []) {
            return ['', []];
        }
        return [(int) reset($values) === 1 ? $exists : 'NOT ' . $exists, $params];
    }

    /**
     * Any non-retired mapping means the exact question version is mapped.
     */
    private static function mapped_exists(): string {
        return "EXISTS (SELECT 1 FROM {local_outcomemap_qmap} qbomm
                         WHERE qbomm.questionversionid = qv.id
                           AND qbomm.status <> 'retired')";
    }

    /**
     * A copied mapping remains pending until explicitly finalized/approved.
     */
    private static function copied_pending_exists(): string {
        return "EXISTS (SELECT 1 FROM {local_outcomemap_qmap} qbomm
                         WHERE qbomm.questionversionid = qv.id
                           AND qbomm.sourceqmapid IS NOT NULL
                           AND qbomm.status IN ('draft', 'needs_review'))";
    }

    /**
     * Build the current effective assessed-total validity predicate.
     *
     * @param array $filter Filter.
     */
    private static function invalid_weight(array $filter): array {
        $now = time();
        $active = [];
        $params = ['qbomweightone' => '1.0000000000'];
        for ($index = 0; $index < 3; $index++) {
            $fromparam = 'qbomweightfrom' . $index;
            $toparam = 'qbomweightto' . $index;
            $params[$fromparam] = $now;
            $params[$toparam] = $now;
            $active[$index] = "qbomm.questionversionid = qv.id
                   AND qbomm.role = 'assesses'
                   AND qbomm.status IN ('draft', 'needs_review', 'approved')
                   AND qbomm.effectivefrom <= :$fromparam
                   AND (qbomm.effectiveto IS NULL OR qbomm.effectiveto > :$toparam)";
        }
        $exists = "EXISTS (SELECT 1 FROM {local_outcomemap_qmap} qbomm WHERE {$active[0]})
                   AND (EXISTS (SELECT 1 FROM {local_outcomemap_qmap} qbomm WHERE {$active[1]}
                                AND qbomm.weight IS NULL)
                        OR (SELECT COALESCE(SUM(qbomm.weight), 0)
                              FROM {local_outcomemap_qmap} qbomm
                             WHERE {$active[2]}) <> :qbomweightone)";
        return self::binary($filter, '(' . $exists . ')', $params);
    }
}
