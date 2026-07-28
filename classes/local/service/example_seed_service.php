<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use core_reportbuilder\local\helpers\audience as audience_helper;
use core_reportbuilder\local\helpers\report as report_helper;
use core_reportbuilder\reportbuilder\audience\admins;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;
use local_outcomemap\reportbuilder\local\sources;

/**
 * Seeds example custom reports and one example accreditation snapshot.
 *
 * These examples exist so an evaluator can see every governed data source and a
 * complete frozen snapshot without hand-building them. Nothing here invents
 * outcome, mapping, or attainment data: the reports read whatever the site
 * already holds, and the snapshot captures authoritative existing results
 * through the ordinary snapshot service. Seeding is idempotent, so a repeated
 * run reports what already exists instead of creating duplicates.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class example_seed_service extends base_service {
    /**
     * Suppression threshold of the seeded example accreditation policy.
     *
     * A demonstration value only. It is not a recommendation, and it is never
     * applied to an institution's own approved policy.
     */
    public const EXAMPLE_MIN_COHORT_SIZE = 5;

    /** Achievement criterion of a seeded example accreditation policy. */
    public const EXAMPLE_ACHIEVEMENT_MIN_PERCENT = '70';

    /** Aggregate benchmark of a seeded example accreditation policy. */
    public const EXAMPLE_BENCHMARK_PERCENT = '70';

    /**
     * Create one example custom report per available governed data source.
     *
     * Each report is populated from its source's own default columns, filters,
     * and sorting, so the examples stay correct as a source evolves.
     *
     * @return array[] One row per source: key, name, reportid, and created.
     */
    public static function seed_reports(): array {
        global $DB;

        self::require_system('local/outcomemap:viewdefinitions');
        require_capability('moodle/reportbuilder:edit', \context_system::instance());

        $seeded = [];
        foreach (sources::all() as $key => $class) {
            $name = self::report_name($key);
            $existing = $DB->get_field('reportbuilder_report', 'id', [
                'source' => $class,
                'name' => $name,
            ], IGNORE_MULTIPLE);
            if ($existing) {
                $seeded[] = [
                    'key' => $key,
                    'name' => $name,
                    'reportid' => (int) $existing,
                    'created' => false,
                ];
                continue;
            }
            $report = report_helper::create_report((object) [
                'name' => $name,
                'source' => $class,
                'uniquerows' => 0,
            ], true);
            // A report with no audience is reachable only by report editors, so
            // give the example the narrowest audience that makes it a complete,
            // viewable report. Each source still enforces its own capabilities
            // when the report runs.
            admins::create((int) $report->get('id'), []);
            $seeded[] = [
                'key' => $key,
                'name' => $name,
                'reportid' => (int) $report->get('id'),
                'created' => true,
            ];
        }
        audience_helper::purge_caches();
        return $seeded;
    }

    /**
     * Capture and freeze one example accreditation snapshot.
     *
     * @param array $options programid and periodcode to capture, or null in each
     *     to auto-select; mincohortsize for a seeded policy; freeze to finalize;
     *     replace to withdraw an existing capture and take it again.
     * @return array programid, periodcode, policyid, policycreated, snapshotid,
     *     created, replaced, and frozen.
     */
    public static function seed_snapshot(array $options = []): array {
        global $DB;

        self::require_system('local/outcomemap:managesnapshots');
        [$programid, $periodcode] = self::resolve_snapshot_target(
            $options['programid'] ?? null,
            $options['periodcode'] ?? null
        );
        $policy = self::require_accreditation_policy(
            $programid,
            $options['mincohortsize'] ?? null
        );
        $freeze = $options['freeze'] ?? true;

        $existing = $DB->get_records('local_outcomemap_snapshot', [
            'programid' => $programid,
            'periodcode' => $periodcode,
        ], 'version DESC, id DESC');
        $replaced = 0;
        if ($existing && !empty($options['replace'])) {
            // Reseeding an example has to end at one capture of the current
            // data, not a correction lineage on top of a stale one, so every
            // existing version of this program and period goes. Deleting in
            // descending version order keeps each remaining chain intact while
            // the sweep runs.
            foreach ($existing as $snapshot) {
                snapshot_service::delete(
                    (int) $snapshot->id,
                    get_string('exampleseed_replacereason', 'local_outcomemap')
                );
                $replaced++;
            }
            $existing = [];
        }
        if ($existing) {
            $snapshot = reset($existing);
            return [
                'programid' => $programid,
                'periodcode' => $periodcode,
                'policyid' => (int) $policy['policyid'],
                'policycreated' => $policy['created'],
                'snapshotid' => (int) $snapshot->id,
                'created' => false,
                'replaced' => 0,
                'frozen' => $snapshot->status === snapshot_service::STATUS_FROZEN,
            ];
        }

        $snapshotid = snapshot_service::create_draft([
            'programid' => $programid,
            'periodcode' => $periodcode,
            'notes' => get_string('exampleseed_snapshotnotes', 'local_outcomemap'),
        ]);
        $frozen = false;
        if ($freeze) {
            snapshot_service::freeze($snapshotid);
            $frozen = true;
        }
        return [
            'programid' => $programid,
            'periodcode' => $periodcode,
            'policyid' => (int) $policy['policyid'],
            'policycreated' => $policy['created'],
            'snapshotid' => $snapshotid,
            'created' => true,
            'replaced' => $replaced,
            'frozen' => $frozen,
        ];
    }

    /**
     * Example report name for one source.
     *
     * @param string $key Short source name.
     * @return string
     */
    public static function report_name(string $key): string {
        return get_string('exampleseed_reportname', 'local_outcomemap', sources::name($key));
    }

    /**
     * Pick the program and period with the most captured attainment.
     *
     * An example snapshot is only worth seeding where authoritative course-scope
     * results already exist, so selection is driven by subject counts rather
     * than by record order.
     *
     * @param int|null $programid Requested program, or null to auto-select.
     * @param string|null $periodcode Requested period, or null to auto-select.
     * @return array{0:int,1:string}
     */
    private static function resolve_snapshot_target(?int $programid, ?string $periodcode): array {
        global $DB;

        $where = ['p.status = :pstatus', 'pc.status = :pcstatus', 'ci.status = :cistatus', 'ci.confirmed = 1'];
        $params = [
            'pstatus' => workflow::APPROVED,
            'pcstatus' => workflow::APPROVED,
            'cistatus' => workflow::APPROVED,
            'scope' => calculation_service::SCOPE_COURSE,
        ];
        if ($programid !== null) {
            $where[] = 'p.id = :programid';
            $params['programid'] = $programid;
        }
        if ($periodcode !== null && trim($periodcode) !== '') {
            $where[] = 'ci.periodcode = :periodcode';
            $params['periodcode'] = trim($periodcode);
        }
        $sql = "SELECT p.id AS programid, ci.periodcode, COUNT(DISTINCT r.userid) AS subjects
                  FROM {local_outcomemap_program} p
                  JOIN {local_outcomemap_progcourse} pc ON pc.programid = p.id
                  JOIN {local_outcomemap_cinst} ci ON ci.courseid = pc.courseid
                  JOIN {local_outcomemap_result} r ON r.cinstid = ci.id
                       AND r.scopetype = :scope AND r.supersededby IS NULL AND r.stale = 0
                 WHERE " . implode(' AND ', $where) . "
              GROUP BY p.id, ci.periodcode
              ORDER BY COUNT(DISTINCT r.userid) DESC, p.id ASC, ci.periodcode ASC";
        $candidates = $DB->get_records_sql($sql, $params, 0, 1);
        if (!$candidates) {
            throw new validation_exception('exampleseed_nosnapshotdata', 'periodcode',
                (string) $periodcode);
        }
        $candidate = reset($candidates);
        return [(int) $candidate->programid, (string) $candidate->periodcode];
    }

    /**
     * Resolve the program's accreditation policy, seeding one when absent.
     *
     * An institution's own approved policy is always preferred. The seeded
     * fallback carries explicit demonstration figures: the plugin supplies no
     * suppression, population, retention, achievement-criterion, or benchmark
     * default of its own, so an example capture has to state them to be
     * possible at all.
     *
     * @param int $programid Program ID.
     * @param int|null $mincohortsize Explicit suppression threshold for a seeded policy.
     * @return array{policyid:int,created:bool}
     */
    private static function require_accreditation_policy(int $programid, ?int $mincohortsize): array {
        $resolved = suppression_service::resolve($programid);
        if ($resolved !== null) {
            return ['policyid' => (int) $resolved->id, 'created' => false];
        }
        $policyid = policy_service::create([
            'policytype' => policy_service::TYPE_ACCREDITATION,
            'scopetype' => policy_service::SCOPE_PROGRAM,
            'scopeid' => $programid,
            'name' => get_string('exampleseed_policyname', 'local_outcomemap'),
            'config' => [
                'mincohortsize' => $mincohortsize ?? self::EXAMPLE_MIN_COHORT_SIZE,
                'populationsource' => suppression_service::POPULATION_ACTIVE_ENROLMENTS,
                'retentionbasis' => suppression_service::RETENTION_ANONYMISED,
                'achievementminpercent' => self::EXAMPLE_ACHIEVEMENT_MIN_PERCENT,
                'benchmarkpercent' => self::EXAMPLE_BENCHMARK_PERCENT,
            ],
            'effectivefrom' => time(),
            'reason' => get_string('exampleseed_policyreason', 'local_outcomemap'),
        ]);
        policy_service::submit_for_review(
            $policyid,
            get_string('exampleseed_policyreason', 'local_outcomemap')
        );
        $resolved = suppression_service::resolve($programid);
        if ($resolved === null || (int) $resolved->id !== $policyid) {
            // Independent approval is enabled, so a second governance actor must
            // approve the drafted policy before any snapshot may be captured.
            throw new validation_exception('exampleseed_policyunapproved', 'policyid', $policyid);
        }
        return ['policyid' => $policyid, 'created' => true];
    }
}
