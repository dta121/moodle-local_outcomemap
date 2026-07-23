<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student feedback-release evaluator.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

/**
 * Evaluates governed release policies against learner-specific Moodle state.
 *
 * The evaluator returns only an aggregate release decision. Question content,
 * responses, correctness, and review data never cross this service boundary.
 */
final class release_service {
    /**
     * Evaluate a resolved release policy for one result scope.
     *
     * Scope data contains: accessible, assessmentcmids, attempts,
     * gradevisible, and quizclosetimes.
     *
     * @param \stdClass|null $policy Resolved approved release policy.
     * @param array $scope Learner-specific scope state.
     * @param int|null $at Evaluation timestamp; defaults to now.
     * @return \stdClass Decision with released, mode, and releasedat fields.
     */
    public static function evaluate(?\stdClass $policy, array $scope, ?int $at = null): \stdClass {
        $at = $at ?? time();
        $mode = $policy->config['mode'] ?? null;
        $decision = (object) [
            'released' => false,
            'mode' => $mode,
            'releasedat' => null,
        ];
        if ($policy === null || !in_array($mode, policy_service::RELEASE_MODES, true)) {
            return $decision;
        }
        if (empty($scope['accessible']) || (array_key_exists('lineagecomplete', $scope)
                && empty($scope['lineagecomplete']))) {
            return $decision;
        }

        $assessmentcmids = array_values(array_unique(array_map('intval', $scope['assessmentcmids'] ?? [])));
        if ($mode === policy_service::RELEASE_SCHEDULED) {
            $releaseat = (int) ($policy->config['releaseat'] ?? 0);
            $decision->released = $releaseat > 0 && $at >= $releaseat;
            $decision->releasedat = $decision->released ? $releaseat : null;
            return $decision;
        }
        if ($mode === policy_service::RELEASE_MANUAL) {
            $manualreleasedat = (int) ($scope['manualreleaseat'] ?? 0);
            $releaseat = max((int) $policy->effectivefrom, $manualreleasedat);
            $decision->released = $manualreleasedat > 0 && $at >= $releaseat;
            $decision->releasedat = $decision->released ? $releaseat : null;
            return $decision;
        }
        if ($mode === policy_service::RELEASE_FULLY_GRADED) {
            $decision->released = self::attempts_fully_graded($scope['attempts'] ?? []);
            $decision->releasedat = $decision->released ? $at : null;
            return $decision;
        }
        if (!$assessmentcmids) {
            return $decision;
        }
        if ($mode === policy_service::RELEASE_GRADE_VISIBLE) {
            $gradevisible = $scope['gradevisible'] ?? [];
            foreach ($assessmentcmids as $cmid) {
                if (empty($gradevisible[$cmid])) {
                    return $decision;
                }
            }
            $decision->released = true;
            $decision->releasedat = $at;
            return $decision;
        }
        if ($mode === policy_service::RELEASE_QUIZ_CLOSED) {
            $closetimes = $scope['quizclosetimes'] ?? [];
            foreach ($assessmentcmids as $cmid) {
                $close = (int) ($closetimes[$cmid] ?? 0);
                if ($close < 1 || $at < $close) {
                    return $decision;
                }
            }
            $decision->released = true;
            $decision->releasedat = max(array_map(
                static fn(int $cmid): int => (int) $closetimes[$cmid],
                $assessmentcmids
            ));
        }
        return $decision;
    }

    /**
     * Require every contributing finished attempt to have no needs-grading item.
     *
     * @param \stdClass[] $attempts Distinct quiz-attempt records.
     * @return bool Whether every attempt is fully graded.
     */
    private static function attempts_fully_graded(array $attempts): bool {
        static $gradedcache = [];
        if (!$attempts) {
            return false;
        }
        foreach ($attempts as $attempt) {
            if ($attempt->state !== 'finished' || empty($attempt->uniqueid)) {
                return false;
            }
            $usageid = (int) $attempt->uniqueid;
            if (!array_key_exists($usageid, $gradedcache)) {
                try {
                    $usage = \question_engine::load_questions_usage_by_activity($usageid);
                    $gradedcache[$usageid] = $usage->get_total_mark() !== null;
                } catch (\Throwable $e) {
                    $gradedcache[$usageid] = false;
                }
            }
            if (!$gradedcache[$usageid]) {
                return false;
            }
        }
        return true;
    }
}
