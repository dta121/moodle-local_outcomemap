<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Accreditation snapshot report page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\decimal;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\snapshot_service;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * Present one accreditation snapshot as a readable evidence report.
 *
 * An accreditor asks three questions of a snapshot: what does it claim, where did
 * the evidence come from, and can the figures be trusted. The page answers them in
 * that order, and every figure on it is read out of the frozen rows rather than
 * recomputed, so the report says the same thing for as long as the snapshot exists.
 */
final class snapshot_report implements renderable, templatable {
    /** Item types small enough to keep decoded for display. */
    private const DISPLAY_TYPES = [
        snapshot_service::ITEM_PROGRAM,
        snapshot_service::ITEM_COHORT,
        snapshot_service::ITEM_COURSE_INSTANCE,
        snapshot_service::ITEM_OUTCOME_VERSION,
        snapshot_service::ITEM_POLICY_VERSION,
        snapshot_service::ITEM_COURSE_AGGREGATE,
        snapshot_service::ITEM_PROGRAM_AGGREGATE,
    ];

    /** @var \stdClass The snapshot record. */
    private \stdClass $snapshot;

    /** @var array[] Decoded display items grouped by item type. */
    private array $grouped = [];

    /** @var array[] Row counts and suppression counts keyed by item type. */
    private array $counts = [];

    /** @var int[][] Distinct learner references keyed by course-instance ID. */
    private array $learnersbycourse = [];

    /**
     * Load and verify one snapshot.
     *
     * Verification recomputes the payload hash over every frozen row, so it needs
     * the whole capture in memory. That is the same guarantee the page it replaces
     * offered: a tampered snapshot must not render as though it were sound.
     *
     * @param int $snapshotid Snapshot ID.
     */
    public function __construct(int $snapshotid) {
        $this->snapshot = snapshot_service::get($snapshotid);
        raise_memory_limit(MEMORY_EXTRA);
        $items = snapshot_service::items($snapshotid);
        snapshot_service::verify($this->snapshot, $items);
        $displaytypes = array_fill_keys(self::DISPLAY_TYPES, true);
        foreach ($items as $item) {
            $type = (string) $item->itemtype;
            if (!isset($this->counts[$type])) {
                $this->counts[$type] = ['total' => 0, 'suppressed' => 0];
            }
            $this->counts[$type]['total']++;
            $this->counts[$type]['suppressed'] += (int) $item->suppressed;
            // Only the small governance types are decoded. A real reporting period
            // holds tens of thousands of evidence rows, and none of them is read
            // individually here.
            if (isset($displaytypes[$type])) {
                // Each captured row is an envelope of type, identity, indexed
                // columns, and the canonical payload; only the payload is read.
                $decoded = json_decode((string) $item->payloadjson, true);
                $item->payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
                $this->grouped[$type][] = $item;
            } else if ($type === snapshot_service::ITEM_RESULT && $item->cinstid !== null) {
                $this->learnersbycourse[(int) $item->cinstid][(string) $item->subjectref] = true;
            }
        }
        ksort($this->counts, SORT_STRING);
    }

    /** Export the template context. */
    public function export_for_template(renderer_base $output): array {
        $context = \context_system::instance();
        $baseurl = new moodle_url('/local/outcomemap/snapshots.php');
        $frozen = $this->snapshot->status === snapshot_service::STATUS_FROZEN;
        $program = $this->first(snapshot_service::ITEM_PROGRAM);
        $users = $this->users();
        $outcomes = $this->outcomes();
        $rowtotal = 0;
        $suppressedtotal = 0;
        $rowtypes = [];
        foreach ($this->counts as $type => $count) {
            $rowtotal += $count['total'];
            $suppressedtotal += $count['suppressed'];
            $rowtypes[] = [
                'type' => $type,
                'count' => number_format($count['total']),
                'suppressed' => $count['suppressed'],
            ];
        }

        return [
            'programcode' => $program['code'] ?? '',
            'programname' => format_string($program['name'] ?? ''),
            'versionline' => get_string('snapreport_versionline', 'local_outcomemap', (object) [
                'version' => (int) $this->snapshot->version,
                'time' => userdate((int) $this->snapshot->populationat),
                'period' => s($this->snapshot->periodcode),
            ]),
            'isfrozen' => $frozen,
            'statuslabel' => get_string(
                $frozen ? 'snapreport_verified' : 'snapreport_draftstate',
                'local_outcomemap'
            ),
            'facts' => $this->facts($users),
            'outcomes' => $outcomes,
            'hasoutcomes' => $outcomes !== [],
            'totals' => $this->totals($outcomes),
            'courses' => $this->courses(),
            'methods' => $this->methods(),
            'provenance' => $this->provenance($users),
            'rowtypes' => $rowtypes,
            'rowsline' => get_string('snapreport_rowsline', 'local_outcomemap', (object) [
                'total' => number_format($rowtotal),
                'suppressed' => number_format($suppressedtotal),
                'threshold' => (int) $this->snapshot->suppressionthreshold,
            ]),
            'exports' => $this->exports($context, $frozen),
            'backurl' => $baseurl->out(false),
            'canfreeze' => !$frozen,
            'freezeurl' => (new moodle_url($baseurl, [
                'action' => 'freeze',
                'id' => (int) $this->snapshot->id,
            ]))->out(false),
            'cancorrect' => $frozen,
            'correcturl' => (new moodle_url($baseurl, [
                'action' => 'correct',
                'id' => (int) $this->snapshot->id,
            ]))->out(false),
            // A version a correction was built on stays; withdrawing it would
            // leave the chain claiming to correct something that is gone.
            'candelete' => !$this->is_superseded(),
            'deleteurl' => (new moodle_url($baseurl, [
                'action' => 'delete',
                'id' => (int) $this->snapshot->id,
            ]))->out(false),
        ];
    }

    /**
     * Whether a correction version builds on this snapshot version.
     *
     * @return bool
     */
    private function is_superseded(): bool {
        global $DB;
        return $DB->record_exists('local_outcomemap_snapshot', [
            'previousid' => (int) $this->snapshot->id,
        ]);
    }

    /**
     * Build the four headline facts about the capture.
     *
     * @param \stdClass[] $users Users keyed by id.
     * @return array[] Fact tiles.
     */
    private function facts(array $users): array {
        $cohort = $this->first(snapshot_service::ITEM_COHORT);
        $courses = count($this->grouped[snapshot_service::ITEM_COURSE_INSTANCE] ?? []);
        $approver = $users[(int) ($this->snapshot->approvedby ?? 0)] ?? null;
        return [
            [
                'label' => get_string('populationcount', 'local_outcomemap'),
                'value' => get_string('snapreport_learners', 'local_outcomemap',
                    number_format((int) $this->snapshot->populationcount)),
                'note' => $cohort === null
                    ? get_string('population_' . $this->snapshot->populationsource, 'local_outcomemap')
                    : get_string('snapreport_cohortnote', 'local_outcomemap',
                        format_string($cohort['name'] ?? '')),
            ],
            [
                'label' => get_string('snapshots_periodlabel', 'local_outcomemap'),
                'value' => s($this->snapshot->periodcode),
                'note' => get_string(
                    $courses === 1 ? 'snapreport_scope_one' : 'snapreport_scope',
                    'local_outcomemap',
                    $courses
                ),
            ],
            [
                'label' => get_string('approvedby', 'local_outcomemap'),
                'value' => $approver === null
                    ? get_string('snapreport_notfrozen', 'local_outcomemap')
                    : fullname($approver),
                'note' => $this->snapshot->approvedat === null
                    ? get_string('snapreport_awaitingfreeze', 'local_outcomemap')
                    : userdate((int) $this->snapshot->approvedat),
            ],
            [
                'label' => get_string('snapreport_reference', 'local_outcomemap'),
                'value' => get_string('snapreport_shortref', 'local_outcomemap', (object) [
                    'uuid' => substr((string) $this->snapshot->snapshotuuid, 0, 8),
                    'version' => (int) $this->snapshot->version,
                ]),
                'note' => get_string('snapreport_payloadshort', 'local_outcomemap',
                    substr((string) $this->snapshot->payloadhash, 0, 12)),
            ],
        ];
    }

    /**
     * Group the captured attainment rows by the framework each outcome belongs to.
     *
     * Every captured outcome gets a program-scope aggregate, not only the ones in
     * the program's own framework, so a single flat table would label course and
     * unit outcomes as program outcomes. Grouping by the framework code recorded in
     * the snapshot keeps each level readable without hiding any captured figure.
     *
     * The attainment figure is the snapshot's own weighted percentage, not a share
     * of learners passing a threshold: the calculation model produces achieved
     * points over possible points, and reporting anything else here would state a
     * number the frozen rows do not contain.
     *
     * @return array[] Framework groups, each with its outcome rows.
     */
    private function outcomes(): array {
        $statements = [];
        foreach ($this->grouped[snapshot_service::ITEM_OUTCOME_VERSION] ?? [] as $item) {
            $statements[(int) $item->itemverid] = $item->payload;
        }
        $evidence = [];
        foreach ($this->grouped[snapshot_service::ITEM_COURSE_AGGREGATE] ?? [] as $item) {
            $code = $item->payload['coursecode'] ?? '';
            if ($code !== '') {
                $evidence[(int) $item->itemverid][$code] = true;
            }
        }
        $groups = [];
        foreach ($this->grouped[snapshot_service::ITEM_PROGRAM_AGGREGATE] ?? [] as $item) {
            $itemverid = (int) $item->itemverid;
            $outcome = $statements[$itemverid] ?? [];
            $suppressed = (int) $item->suppressed === 1;
            $codes = array_keys($evidence[$itemverid] ?? []);
            sort($codes, SORT_NATURAL);
            $framework = (string) ($outcome['frameworkcode'] ?? ($item->payload['frameworkcode'] ?? ''));
            $groups[$framework]['rows'][] = [
                'code' => $outcome['code'] ?? ($item->payload['outcomecode'] ?? ''),
                'statement' => format_string($outcome['statement'] ?? ''),
                'evidence' => $codes,
                'hasevidence' => $codes !== [],
                'suppressed' => $suppressed,
                'learners' => number_format((int) $item->subjectcount),
                'results' => number_format((int) ($item->payload['calculatedcount'] ?? 0)),
                'percent' => $item->percentage === null
                    ? get_string('calculationnotavailable', 'local_outcomemap')
                    : number_format((float) $item->percentage, 1) . '%',
                'barwidth' => $item->percentage === null
                    ? 0
                    : round(min(100, max(0, (float) $item->percentage)), 2),
                'hasbar' => !$suppressed && $item->percentage !== null,
            ];
        }
        uksort($groups, static fn($a, $b) => strnatcasecmp($a, $b));
        $result = [];
        foreach ($groups as $framework => $group) {
            usort($group['rows'], static fn($a, $b) => strnatcasecmp($a['code'], $b['code']));
            $count = count($group['rows']);
            $result[] = [
                'framework' => $framework,
                'countline' => get_string(
                    $count === 1 ? 'snapreport_outcomes_one' : 'snapreport_outcomes',
                    'local_outcomemap',
                    $count
                ),
                'rows' => $group['rows'],
            ];
        }
        return $result;
    }

    /**
     * Sum the program outcome rows into one governed aggregate line.
     *
     * Learner counts cannot be added across outcomes because the same learner
     * contributes to several, so the population figure is the snapshot's own
     * population. Weighted points do add, and give the overall percentage.
     *
     * @param array[] $outcomes Framework groups, counted for the aggregate line.
     * @return array Aggregate line.
     */
    private function totals(array $outcomes): array {
        $numerator = decimal::ZERO;
        $denominator = decimal::ZERO;
        $results = 0;
        foreach ($this->grouped[snapshot_service::ITEM_PROGRAM_AGGREGATE] ?? [] as $item) {
            $numerator = decimal::add($numerator, decimal::canonical($item->numerator, 'numerator'));
            $denominator = decimal::add($denominator, decimal::canonical($item->denominator, 'denominator'));
            $results += (int) ($item->payload['calculatedcount'] ?? 0);
        }
        $percent = decimal::is_zero($denominator)
            ? null
            : decimal::div(decimal::mul($numerator, '100'), $denominator);
        return [
            'learners' => number_format((int) $this->snapshot->populationcount),
            'results' => number_format($results),
            'percent' => $percent === null
                ? get_string('calculationnotavailable', 'local_outcomemap')
                : number_format((float) $percent, 1) . '%',
            'frameworkcount' => count($outcomes),
        ];
    }

    /**
     * Build one row per course instance whose results were captured.
     *
     * @return array[] Course evidence rows.
     */
    private function courses(): array {
        $outcomes = [];
        $results = [];
        foreach ($this->grouped[snapshot_service::ITEM_COURSE_AGGREGATE] ?? [] as $item) {
            $cinstid = (int) $item->cinstid;
            $outcomes[$cinstid] = ($outcomes[$cinstid] ?? 0) + 1;
            $results[$cinstid] = ($results[$cinstid] ?? 0) + (int) ($item->payload['calculatedcount'] ?? 0);
        }
        $rows = [];
        foreach ($this->grouped[snapshot_service::ITEM_COURSE_INSTANCE] ?? [] as $item) {
            $cinstid = (int) $item->cinstid;
            $payload = $item->payload;
            $count = $outcomes[$cinstid] ?? 0;
            $learners = count($this->learnersbycourse[$cinstid] ?? []);
            $rows[] = [
                'code' => $payload['coursecode'] ?? '',
                'name' => format_string($payload['coursename'] ?? ''),
                'shell' => get_string('snapreport_shell', 'local_outcomemap', (object) [
                    'shell' => format_string($payload['moodlecoursename'] ?? ''),
                    'period' => s($payload['periodcode'] ?? ''),
                ]),
                'outcomes' => get_string(
                    $count === 1 ? 'snapreport_outcomes_one' : 'snapreport_outcomes',
                    'local_outcomemap',
                    $count
                ),
                'learners' => get_string(
                    $learners === 1 ? 'snapreport_learners_one' : 'snapreport_learners',
                    'local_outcomemap',
                    number_format($learners)
                ),
                'results' => get_string(
                    ($results[$cinstid] ?? 0) === 1 ? 'snapreport_results_one' : 'snapreport_results',
                    'local_outcomemap',
                    number_format($results[$cinstid] ?? 0)
                ),
            ];
        }
        usort($rows, static fn($a, $b) => strnatcasecmp($a['code'], $b['code']));
        return $rows;
    }

    /**
     * Describe the governing rules that were in force at the data freeze.
     *
     * @return array[] Method cards.
     */
    private function methods(): array {
        $cards = [];
        $policies = [];
        foreach ($this->grouped[snapshot_service::ITEM_POLICY_VERSION] ?? [] as $item) {
            $policies[(string) ($item->payload['policytype'] ?? '')][] = $item->payload;
        }
        $accreditation = $policies[policy_service::TYPE_ACCREDITATION][0] ?? null;
        if ($accreditation !== null) {
            $cards[] = [
                'label' => get_string('snapreport_method_accreditation', 'local_outcomemap'),
                'value' => get_string('snapreport_method_policyvalue', 'local_outcomemap', (object) [
                    'name' => format_string($accreditation['name'] ?? ''),
                    'version' => (int) ($accreditation['version'] ?? 0),
                    'from' => userdate((int) ($accreditation['effectivefrom'] ?? 0)),
                ]),
            ];
        }
        $calculation = $policies[policy_service::TYPE_CALCULATION] ?? [];
        $cards[] = [
            'label' => get_string('snapreport_method_attainment', 'local_outcomemap'),
            'value' => $calculation === []
                ? get_string('snapreport_method_attainmentvalue_nopolicy', 'local_outcomemap')
                : get_string('snapreport_method_attainmentvalue', 'local_outcomemap', (object) [
                    'count' => count($calculation),
                ]),
        ];
        $cards[] = [
            'label' => get_string('populationsource', 'local_outcomemap'),
            'value' => get_string('snapreport_method_populationvalue', 'local_outcomemap', (object) [
                'source' => get_string('population_' . $this->snapshot->populationsource, 'local_outcomemap'),
                'time' => userdate((int) $this->snapshot->populationat),
                'count' => number_format((int) $this->snapshot->populationcount),
            ]),
        ];
        $suppressed = 0;
        foreach ($this->counts as $count) {
            $suppressed += $count['suppressed'];
        }
        $cards[] = [
            'label' => get_string('snapreport_method_suppression', 'local_outcomemap'),
            'value' => get_string(
                $suppressed === 0
                    ? 'snapreport_method_suppressionvalue_none'
                    : 'snapreport_method_suppressionvalue',
                'local_outcomemap',
                (object) [
                    'threshold' => (int) $this->snapshot->suppressionthreshold,
                    'count' => number_format($suppressed),
                ]
            ),
        ];
        $cards[] = [
            'label' => get_string('snapreport_method_algorithm', 'local_outcomemap'),
            'value' => get_string('snapreport_method_algorithmvalue', 'local_outcomemap', (object) [
                'algo' => s($this->snapshot->algoversion),
                'plugin' => s($this->snapshot->pluginversion),
            ]),
        ];
        return $cards;
    }

    /**
     * Build the technical provenance and integrity record.
     *
     * @param \stdClass[] $users Users keyed by id.
     * @return array[] Label and value rows.
     */
    private function provenance(array $users): array {
        $creator = $users[(int) ($this->snapshot->createdby ?? 0)] ?? null;
        $approver = $users[(int) ($this->snapshot->approvedby ?? 0)] ?? null;
        $rows = [
            ['label' => get_string('snapshotuuid', 'local_outcomemap'),
                'value' => s($this->snapshot->snapshotuuid), 'mono' => true],
            ['label' => get_string('version', 'local_outcomemap'),
                'value' => $this->versiondetail(), 'mono' => false],
            ['label' => get_string('status', 'local_outcomemap'),
                'value' => get_string('snapshotstatus_' . $this->snapshot->status, 'local_outcomemap'),
                'mono' => false],
            ['label' => get_string('populationat', 'local_outcomemap'),
                'value' => userdate((int) $this->snapshot->populationat), 'mono' => false],
            ['label' => get_string('payloadhash', 'local_outcomemap'),
                'value' => s($this->snapshot->payloadhash), 'mono' => true],
            ['label' => get_string('manifesthash', 'local_outcomemap'),
                'value' => $this->snapshot->manifesthash === null
                    ? get_string('snapreport_nomanifest', 'local_outcomemap')
                    : s($this->snapshot->manifesthash),
                'mono' => $this->snapshot->manifesthash !== null],
            ['label' => get_string('createdby', 'local_outcomemap'),
                'value' => get_string('snapreport_actor', 'local_outcomemap', (object) [
                    'name' => $creator === null
                        ? get_string('snapreport_unknownuser', 'local_outcomemap')
                        : fullname($creator),
                    'time' => userdate((int) $this->snapshot->timecreated),
                ]),
                'mono' => false],
            ['label' => get_string('approvedby', 'local_outcomemap'),
                'value' => $approver === null
                    ? get_string('snapreport_awaitingfreeze', 'local_outcomemap')
                    : get_string('snapreport_actor', 'local_outcomemap', (object) [
                        'name' => fullname($approver),
                        'time' => userdate((int) $this->snapshot->approvedat),
                    ]),
                'mono' => false],
            ['label' => get_string('suppressionthreshold', 'local_outcomemap'),
                'value' => (string) (int) $this->snapshot->suppressionthreshold, 'mono' => false],
            ['label' => get_string('retentionbasis', 'local_outcomemap'),
                'value' => get_string('retention_' . $this->snapshot->retentionbasis, 'local_outcomemap'),
                'mono' => false],
            ['label' => get_string('snapreport_subjectmethod', 'local_outcomemap'),
                'value' => s($this->snapshot->subjecthashmethod), 'mono' => true],
        ];
        $notes = trim((string) ($this->snapshot->notes ?? ''));
        if ($notes !== '') {
            $rows[] = ['label' => get_string('snapshotnotes', 'local_outcomemap'),
                'value' => format_text($notes, FORMAT_PLAIN), 'mono' => false];
        }
        return $rows;
    }

    /**
     * Describe the version, naming its correction lineage where there is one.
     *
     * @return string
     */
    private function versiondetail(): string {
        $version = (int) $this->snapshot->version;
        $reason = trim((string) ($this->snapshot->correctionreason ?? ''));
        if ($this->snapshot->previousid === null) {
            return get_string('snapreport_originalversion', 'local_outcomemap', $version);
        }
        return get_string('snapreport_correctionversion', 'local_outcomemap', (object) [
            'version' => $version,
            'reason' => $reason === '' ? get_string('none', 'local_outcomemap') : $reason,
        ]);
    }

    /**
     * Resolve which export formats the reader may take away.
     *
     * @param \context $context System context.
     * @param bool $frozen Whether the snapshot is frozen.
     * @return array Export button context.
     */
    private function exports(\context $context, bool $frozen): array {
        $canexport = $frozen && has_capability('local/outcomemap:exportaccreditation', $context);
        $exporturl = static fn(array $params): string =>
            (new moodle_url('/local/outcomemap/export.php', $params))->out(false);
        return [
            'canexport' => $canexport,
            'csvurl' => $exporturl(['id' => (int) $this->snapshot->id, 'format' => 'csv']),
            'jsonurl' => $exporturl(['id' => (int) $this->snapshot->id, 'format' => 'json']),
            'canevidence' => $canexport && has_capability('local/outcomemap:viewallresults', $context),
            'evidenceurl' => $exporturl([
                'id' => (int) $this->snapshot->id,
                'format' => 'json',
                'evidence' => 1,
            ]),
            'notfrozen' => !$frozen,
        ];
    }

    /**
     * Load the governance actors named on the snapshot in one query.
     *
     * @return \stdClass[] Users keyed by id.
     */
    private function users(): array {
        global $DB;
        $ids = array_filter([
            (int) ($this->snapshot->createdby ?? 0),
            (int) ($this->snapshot->approvedby ?? 0),
        ]);
        if ($ids === []) {
            return [];
        }
        return $DB->get_records_list('user', 'id', array_unique($ids), '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename');
    }

    /**
     * Return the payload of the first item of one type.
     *
     * @param string $type Item type.
     * @return array|null Decoded payload, or null when the type was not captured.
     */
    private function first(string $type): ?array {
        $items = $this->grouped[$type] ?? [];
        return $items === [] ? null : $items[0]->payload;
    }
}
