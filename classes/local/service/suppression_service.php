<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\decimal;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Resolves and applies governed accreditation suppression policies.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class suppression_service {
    /** Accreditation reporting policy type. */
    public const POLICY_TYPE = policy_service::TYPE_ACCREDITATION;

    /** Supported population sources. */
    public const POPULATION_ACTIVE_ENROLMENTS = 'active_enrolments_at_freeze';
    public const POPULATION_MOODLE_COHORT = 'moodle_cohort_at_freeze';
    public const POPULATION_SOURCES = [
        self::POPULATION_ACTIVE_ENROLMENTS,
        self::POPULATION_MOODLE_COHORT,
    ];

    /** Supported privacy/retention decisions. */
    public const RETENTION_ANONYMISED = 'institutional_record_anonymised';
    public const RETENTION_PRIVACY_DELETION = 'privacy_deletion';
    public const RETENTION_BASES = [
        self::RETENTION_ANONYMISED,
        self::RETENTION_PRIVACY_DELETION,
    ];

    /** Fixed arithmetic and correction semantics. */
    public const AGGREGATION_METHOD = 'sum_numerators_denominators';
    public const CORRECTION_METHOD = 'new_snapshot_version';

    /**
     * Normalize and validate an accreditation policy configuration.
     *
     * No threshold, population, achievement criterion, or benchmark default is
     * supplied. Accreditation reporting states two separate faculty decisions:
     * the achievement criterion each learner is judged against, and the
     * aggregate benchmark the resulting attainment rate is compared to. Both
     * are required so no snapshot can report a rate with nothing to report it
     * against.
     *
     * @param array $config Submitted policy configuration.
     * @return array Canonical configuration.
     */
    public static function normalize_config(array $config): array {
        $threshold = filter_var($config['mincohortsize'] ?? null, FILTER_VALIDATE_INT);
        if ($threshold === false || (int) $threshold < 1) {
            throw new validation_exception('invalidpolicyconfig', 'mincohortsize',
                $config['mincohortsize'] ?? '');
        }
        $achievement = self::require_percent($config['achievementminpercent'] ?? null, 'achievementminpercent');
        $benchmark = self::require_percent($config['benchmarkpercent'] ?? null, 'benchmarkpercent');
        $population = trim((string) ($config['populationsource'] ?? ''));
        if (!in_array($population, self::POPULATION_SOURCES, true)) {
            throw new validation_exception('invalidpolicyconfig', 'populationsource', $population);
        }
        $retention = trim((string) ($config['retentionbasis'] ?? ''));
        if (!in_array($retention, self::RETENTION_BASES, true)) {
            throw new validation_exception('invalidpolicyconfig', 'retentionbasis', $retention);
        }
        if (isset($config['aggregationmethod'])
                && $config['aggregationmethod'] !== self::AGGREGATION_METHOD) {
            throw new validation_exception('invalidpolicyconfig', 'aggregationmethod',
                $config['aggregationmethod']);
        }
        if (isset($config['correctionmethod'])
                && $config['correctionmethod'] !== self::CORRECTION_METHOD) {
            throw new validation_exception('invalidpolicyconfig', 'correctionmethod',
                $config['correctionmethod']);
        }
        return [
            'mincohortsize' => (int) $threshold,
            'populationsource' => $population,
            'retentionbasis' => $retention,
            'achievementminpercent' => $achievement,
            'benchmarkpercent' => $benchmark,
            'aggregationmethod' => self::AGGREGATION_METHOD,
            'correctionmethod' => self::CORRECTION_METHOD,
        ];
    }

    /**
     * Require a canonical percentage between zero and one hundred inclusive.
     *
     * @param mixed $value Submitted percentage.
     * @param string $field Configuration field name.
     * @return string Canonical percentage.
     */
    private static function require_percent($value, string $field): string {
        if ($value === null || trim((string) $value) === '') {
            throw new validation_exception('invalidpolicyconfig', $field, '');
        }
        $canonical = decimal::require_canonical($value, $field);
        if (decimal::cmp($canonical, decimal::ZERO) < 0
                || decimal::cmp($canonical, decimal::canonical('100', $field)) > 0) {
            throw new validation_exception('invalidpolicyconfig', $field, (string) $value);
        }
        return $canonical;
    }

    /**
     * Resolve the effective approved policy for a program.
     *
     * Program scope takes precedence over institution scope. Missing policy
     * returns null so official snapshot creation can fail closed.
     *
     * @param int $programid Program ID.
     * @param int|null $at Effective timestamp.
     * @return \stdClass|null Resolved policy with decoded config.
     */
    public static function resolve(int $programid, ?int $at = null): ?\stdClass {
        global $DB;
        $at = $at ?? time();
        foreach ([
            [policy_service::SCOPE_PROGRAM, $programid],
            [policy_service::SCOPE_INSTITUTION, null],
        ] as [$scopetype, $scopeid]) {
            $select = 'policytype = :policytype AND scopetype = :scopetype AND status = :status
                AND effectivefrom <= :at1 AND (effectiveto IS NULL OR effectiveto > :at2)';
            $params = [
                'policytype' => self::POLICY_TYPE,
                'scopetype' => $scopetype,
                'status' => workflow::APPROVED,
                'at1' => $at,
                'at2' => $at,
            ];
            if ($scopeid === null) {
                $select .= ' AND scopeid IS NULL';
            } else {
                $select .= ' AND scopeid = :scopeid';
                $params['scopeid'] = $scopeid;
            }
            $records = $DB->get_records_select('local_outcomemap_policy', $select, $params,
                'version DESC, id DESC', '*', 0, 1);
            if ($records) {
                $policy = reset($records);
                $policy->config = self::normalize_config(json_decode($policy->configjson, true) ?? []);
                return $policy;
            }
        }
        return null;
    }

    /**
     * Whether an aggregate row is suppressed.
     *
     * @param int $subjectcount Distinct subjects contributing to the row.
     * @param \stdClass $policy Approved accreditation policy.
     * @return bool
     */
    public static function is_suppressed(int $subjectcount, \stdClass $policy): bool {
        return $subjectcount < self::config_of($policy)['mincohortsize'];
    }

    /**
     * Return the canonical configuration of an accreditation policy.
     *
     * @param \stdClass $policy Approved accreditation policy.
     * @return array Canonical configuration.
     */
    public static function config_of(\stdClass $policy): array {
        return isset($policy->config)
            ? self::normalize_config((array) $policy->config)
            : self::normalize_config(json_decode($policy->configjson, true) ?? []);
    }

    /**
     * Whether one learner's outcome percentage meets the achievement criterion.
     *
     * @param string $percentage Canonical learner percentage.
     * @param array $config Canonical accreditation configuration.
     * @return bool
     */
    public static function meets_criterion(string $percentage, array $config): bool {
        return decimal::cmp($percentage, $config['achievementminpercent']) >= 0;
    }

    /**
     * Whether an attainment rate meets the aggregate benchmark.
     *
     * @param string|null $attainmentpercent Canonical attainment rate, or null.
     * @param array $config Canonical accreditation configuration.
     * @return bool|null Null when no rate was calculable.
     */
    public static function meets_benchmark(?string $attainmentpercent, array $config): ?bool {
        if ($attainmentpercent === null) {
            return null;
        }
        return decimal::cmp($attainmentpercent, $config['benchmarkpercent']) >= 0;
    }
}
