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
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\local\service\snapshot_service;
use local_outcomemap\local\workflow;
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
    /** Group the attainment table by each outcome's own framework. */
    public const GROUP_FRAMEWORK = 'framework';

    /** Group by the course-level outcome each row rolls up into. */
    public const GROUP_COURSE = 'course';

    /** Group by the top-level outcome each row rolls up into. */
    public const GROUP_PROGRAM = 'program';

    /** Every subject in the captured population. */
    public const SUBJECTS_ALL = 'all';

    /** Only subjects who met the criterion in every course they were judged in. */
    public const SUBJECTS_PASSEDALL = 'passedall';

    /** Only subjects who missed the criterion in at least one course. */
    public const SUBJECTS_FAILEDANY = 'failedany';

    /** Selectable attainment groupings. */
    public const GROUPINGS = [self::GROUP_FRAMEWORK, self::GROUP_COURSE, self::GROUP_PROGRAM];

    /** Selectable subject filters. */
    public const SUBJECT_FILTERS = [self::SUBJECTS_ALL, self::SUBJECTS_PASSEDALL, self::SUBJECTS_FAILEDANY];

    /** Item types small enough to keep decoded for display. */
    private const DISPLAY_TYPES = [
        snapshot_service::ITEM_PROGRAM,
        snapshot_service::ITEM_COHORT,
        snapshot_service::ITEM_COURSE_INSTANCE,
        snapshot_service::ITEM_OUTCOME_VERSION,
        snapshot_service::ITEM_POLICY_VERSION,
        snapshot_service::ITEM_RELATION_VERSION,
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

    /** @var string Selected attainment grouping. */
    private string $groupby;

    /** @var string Selected subject filter. */
    private string $subjectfilter;

    /** @var array<string,array<int,array>> Pooled points per subject per course instance. */
    private array $coursepool = [];

    /** @var array<int,array<string,array>> Pooled points per outcome per subject. */
    private array $subjectpool = [];

    /** @var string|null Canonical achievement criterion recorded on the frozen rows. */
    private ?string $criterion = null;

    /** @var array<string,string> Course-progress verdict keyed by subject reference. */
    private array $verdicts = [];

    /** @var array<string,bool> Subject references the active filter selects. */
    private array $selected = [];

    /** @var bool Whether the grouping fell back to today's alignment edges. */
    private bool $liverollup = false;

    /**
     * Load and verify one snapshot.
     *
     * Verification recomputes the payload hash over every frozen row, so a
     * tampered snapshot still cannot render as though it were sound. It streams
     * those rows rather than holding them: a programme-wide capture runs to
     * hundreds of thousands of rows, four fifths of them evidence payloads that
     * exist only to be hashed, and loading them cost around a gigabyte — enough
     * that a perfectly sound record could not be viewed at all. What the page
     * displays is loaded separately, and is a few thousand rows.
     *
     * @param int $snapshotid Snapshot ID.
     * @param string $groupby One of this class's GROUP_* values.
     * @param string $subjectfilter One of this class's SUBJECTS_* values.
     */
    public function __construct(int $snapshotid, string $groupby = self::GROUP_FRAMEWORK,
            string $subjectfilter = self::SUBJECTS_ALL) {
        $this->groupby = in_array($groupby, self::GROUPINGS, true) ? $groupby : self::GROUP_FRAMEWORK;
        $this->subjectfilter = in_array($subjectfilter, self::SUBJECT_FILTERS, true)
            ? $subjectfilter
            : self::SUBJECTS_ALL;
        $this->snapshot = snapshot_service::get($snapshotid);
        raise_memory_limit(MEMORY_EXTRA);
        // Verifying a programme-wide capture is hundreds of thousands of hash and
        // canonical-JSON operations, which outlasts the default request budget
        // even though it now fits in a fraction of the memory. The alternative
        // would be to verify less of the record than the page reports on.
        \core_php_time_limit::raise(300);
        // Verification streams the capture rather than loading it: the payloads
        // exist only to be hashed, and a programme-wide capture is far larger
        // than any request could hold.
        snapshot_service::verify_streamed($this->snapshot);
        // Counts describe the whole capture, so they are aggregated in the
        // database instead of tallied over rows nothing else here reads.
        $this->counts = snapshot_service::item_counts($snapshotid);
        $filtering = $this->subjectfilter !== self::SUBJECTS_ALL;
        // Only the small governance types are loaded with their payloads.
        foreach (snapshot_service::items_of_types($snapshotid, self::DISPLAY_TYPES) as $item) {
            // Each captured row is an envelope of type, identity, indexed
            // columns, and the canonical payload; only the payload is read.
            $decoded = json_decode((string) $item->payloadjson, true);
            $item->payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
            $this->grouped[(string) $item->itemtype][] = $item;
        }
        // Learner results are pooled from their indexed columns; their payloads
        // are the second largest thing in a capture and are never read here.
        $rs = snapshot_service::result_index_rows($snapshotid);
        try {
            foreach ($rs as $item) {
                $subjectref = (string) $item->subjectref;
                $cinstid = (int) $item->cinstid;
                $this->learnersbycourse[$cinstid][$subjectref] = true;
                // Only calculated rows carry points, and every captured learner
                // result is course-scope, so pooling by course instance is the
                // same judgement the engine makes for a course.
                if ((string) $item->state !== calculation_service::STATE_CALCULATED
                        || $item->percentage === null) {
                    continue;
                }
                $numerator = decimal::canonical($item->numerator, 'numerator');
                $denominator = decimal::canonical($item->denominator, 'denominator');
                self::pool($this->coursepool[$subjectref][$cinstid], $numerator, $denominator);
                if ($filtering) {
                    self::pool(
                        $this->subjectpool[(int) $item->itemverid][$subjectref],
                        $numerator,
                        $denominator
                    );
                }
            }
        } finally {
            $rs->close();
        }
        $this->criterion = $this->criterion();
        $this->verdicts = $this->verdicts();
        $this->selected = $this->selected();
    }

    /**
     * Add one learner result's points into a pooled bucket.
     *
     * Percentages are never averaged anywhere in this plugin: canonical points
     * are summed and divided once, so a pooled figure derived here matches the
     * one the engine would have produced over the same rows.
     *
     * @param array|null $bucket Bucket to accumulate into, created on first use.
     * @param string $numerator Canonical achieved points.
     * @param string $denominator Canonical possible points.
     * @return void
     */
    private static function pool(?array &$bucket, string $numerator, string $denominator): void {
        $bucket ??= ['numerator' => decimal::ZERO, 'denominator' => decimal::ZERO, 'count' => 0];
        $bucket['numerator'] = decimal::add($bucket['numerator'], $numerator);
        $bucket['denominator'] = decimal::add($bucket['denominator'], $denominator);
        $bucket['count']++;
    }

    /**
     * Divide one pooled bucket once, or return null when nothing was assessed.
     *
     * @param array $bucket Pooled bucket.
     * @return string|null Canonical percentage.
     */
    private static function percentage(array $bucket): ?string {
        if ($bucket['count'] === 0 || decimal::is_zero($bucket['denominator'])) {
            return null;
        }
        return decimal::div(decimal::mul($bucket['numerator'], '100'), $bucket['denominator']);
    }

    /**
     * Read the achievement criterion the frozen aggregates were judged against.
     *
     * Taken off the captured rows rather than re-resolved from the live policy:
     * the standard a snapshot was judged against is part of the record.
     *
     * @return string|null Canonical criterion percentage.
     */
    private function criterion(): ?string {
        foreach ($this->grouped[snapshot_service::ITEM_PROGRAM_AGGREGATE] ?? [] as $item) {
            if ($item->criterionpercent !== null) {
                return decimal::canonical($item->criterionpercent, 'criterionpercent');
            }
        }
        return null;
    }

    /**
     * Judge each subject's progress across the courses captured for them.
     *
     * A subject passed a course when their pooled course-scope points meet the
     * criterion, which is the same test the engine applies per learner when it
     * builds an aggregate's met count. Courses the subject holds no calculable
     * result in are not counted against them: not yet judged is not a failure.
     *
     * @return array<string,string> One of passed, failed, or unjudged per subject.
     */
    private function verdicts(): array {
        if ($this->criterion === null) {
            return [];
        }
        $verdicts = [];
        foreach ($this->coursepool as $subjectref => $courses) {
            $judged = 0;
            $failed = 0;
            foreach ($courses as $bucket) {
                $percentage = self::percentage($bucket);
                if ($percentage === null) {
                    continue;
                }
                $judged++;
                $failed += decimal::cmp($percentage, $this->criterion) < 0 ? 1 : 0;
            }
            $verdicts[(string) $subjectref] = $judged === 0
                ? 'unjudged'
                : ($failed > 0 ? 'failed' : 'passed');
        }
        return $verdicts;
    }

    /**
     * Resolve the subject references the active filter selects.
     *
     * @return array<string,bool> Selected subject references, keyed for lookup.
     */
    private function selected(): array {
        if ($this->subjectfilter === self::SUBJECTS_ALL) {
            return [];
        }
        $want = $this->subjectfilter === self::SUBJECTS_PASSEDALL ? 'passed' : 'failed';
        $selected = [];
        foreach ($this->verdicts as $subjectref => $verdict) {
            if ($verdict === $want) {
                $selected[$subjectref] = true;
            }
        }
        return $selected;
    }

    /**
     * Recompute the attainment cells over the filtered subject set.
     *
     * Nothing here re-reads the live database: the figures are pooled from the
     * same frozen learner rows the snapshot's own aggregates were built from,
     * restricted to the selected subjects. They are therefore reproducible from
     * the capture, but they are not the snapshot's governed aggregate, and the
     * page says so wherever they are shown. Small-cell suppression still
     * applies, using the threshold the snapshot recorded.
     *
     * @return array<int,array> Recomputed cells keyed by outcome-version ID.
     */
    private function overrides(): array {
        if ($this->subjectfilter === self::SUBJECTS_ALL) {
            return [];
        }
        $threshold = (int) $this->snapshot->suppressionthreshold;
        $overrides = [];
        foreach ($this->subjectpool as $itemverid => $subjects) {
            $pooled = ['numerator' => decimal::ZERO, 'denominator' => decimal::ZERO, 'count' => 0];
            $assessed = 0;
            $met = 0;
            $subjectcount = 0;
            // Subjects are judged in reference order so a filtered figure does
            // not depend on the order the frozen rows happened to be read in.
            ksort($subjects, SORT_STRING);
            foreach ($subjects as $subjectref => $bucket) {
                if (!isset($this->selected[(string) $subjectref])) {
                    continue;
                }
                $subjectcount++;
                self::pool($pooled, $bucket['numerator'], $bucket['denominator']);
                $percentage = self::percentage($bucket);
                if ($percentage === null) {
                    continue;
                }
                $assessed++;
                $met += $this->criterion !== null
                    && decimal::cmp($percentage, $this->criterion) >= 0 ? 1 : 0;
            }
            $rate = $assessed === 0 ? null : decimal::div(
                decimal::mul(decimal::canonical((string) $met, 'metcount'), '100'),
                decimal::canonical((string) $assessed, 'assessedcount')
            );
            $overrides[(int) $itemverid] = [
                'percentage' => self::percentage($pooled),
                'calculatedcount' => $pooled['count'],
                'subjectcount' => $subjectcount,
                'assessedcount' => $assessed,
                'metcount' => $met,
                'attainmentpercent' => $rate,
                'numerator' => $pooled['numerator'],
                'denominator' => $pooled['denominator'],
                'suppressed' => $subjectcount < $threshold,
            ];
        }
        return $overrides;
    }

    /** Export the template context. */
    public function export_for_template(renderer_base $output): array {
        $context = \context_system::instance();
        $baseurl = new moodle_url('/local/outcomemap/snapshots.php');
        $frozen = $this->snapshot->status === snapshot_service::STATUS_FROZEN;
        $program = $this->first(snapshot_service::ITEM_PROGRAM);
        $users = $this->users();
        $progress = $this->progress();
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
            'progress' => $progress,
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
            'controls' => $this->controls($baseurl, $progress['counts']),
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
        $overrides = $this->overrides();
        $rollup = $this->groupby === self::GROUP_FRAMEWORK ? [] : $this->rollup_labels();
        $groups = [];
        foreach ($this->grouped[snapshot_service::ITEM_PROGRAM_AGGREGATE] ?? [] as $item) {
            $itemverid = (int) $item->itemverid;
            $outcome = $statements[$itemverid] ?? [];
            $cells = $this->cells($item, $overrides);
            $suppressed = (bool) $cells['suppressed'];
            $codes = array_keys($evidence[$itemverid] ?? []);
            sort($codes, SORT_NATURAL);
            $row = [
                'itemverid' => $itemverid,
                'code' => $outcome['code'] ?? ($item->payload['outcomecode'] ?? ''),
                'statement' => format_string($outcome['statement'] ?? ''),
                'evidence' => $codes,
                'hasevidence' => $codes !== [],
                'suppressed' => $suppressed,
                'learners' => number_format((int) $cells['subjectcount']),
                'results' => number_format((int) $cells['calculatedcount']),
                'percent' => $cells['percentage'] === null
                    ? get_string('calculationnotavailable', 'local_outcomemap')
                    : number_format((float) $cells['percentage'], 1) . '%',
                'barwidth' => $cells['percentage'] === null
                    ? 0
                    : round(min(100, max(0, (float) $cells['percentage'])), 2),
                'hasbar' => !$suppressed && $cells['percentage'] !== null,
                'numerator' => $cells['numerator'],
                'denominator' => $cells['denominator'],
                'calculated' => (int) $cells['calculatedcount'],
                'assessed' => (int) $cells['assessedcount'],
                'met' => (int) $cells['metcount'],
            ] + self::attainment_cells([
                'attainmentpercent' => $cells['attainmentpercent'],
                'benchmarkpercent' => $item->payload['benchmarkpercent'] ?? null,
                // A recomputed rate was never judged against the benchmark by the
                // engine, so the filtered view reports no verdict rather than
                // reusing the unfiltered one.
                'benchmarkmet' => $this->subjectfilter === self::SUBJECTS_ALL
                    ? ($item->payload['benchmarkmet'] ?? null)
                    : null,
                'metcount' => $cells['metcount'],
                'assessedcount' => $cells['assessedcount'],
            ]);

            $framework = (string) ($outcome['frameworkcode'] ?? ($item->payload['frameworkcode'] ?? ''));
            if ($this->groupby === self::GROUP_FRAMEWORK) {
                $groups[$framework]['label'] = $framework;
                $groups[$framework]['rows'][] = $row;
                continue;
            }
            // An outcome supporting two higher-level outcomes answers both, so it
            // is reported under each of them rather than being assigned to one.
            $targets = $rollup[$itemverid] ?? [];
            if ($targets === []) {
                $key = "\xff";
                $groups[$key]['label'] = get_string('snapreport_groupunaligned', 'local_outcomemap');
                $groups[$key]['rows'][] = $row;
                continue;
            }
            foreach ($targets as $key => $label) {
                $groups[$key]['label'] = $label;
                $groups[$key]['rows'][] = $row;
            }
        }
        uksort($groups, static fn($a, $b) => strnatcasecmp($a, $b));
        $result = [];
        foreach ($groups as $group) {
            usort($group['rows'], static fn($a, $b) => strnatcasecmp($a['code'], $b['code']));
            $count = count($group['rows']);
            $result[] = [
                'framework' => $group['label'],
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
     * Resolve the figures one attainment row reports.
     *
     * Without a subject filter these are the snapshot's own frozen aggregate
     * columns. With one they come from the recomputed set, and an outcome no
     * selected subject holds a calculable result for reports nothing rather than
     * falling back to the unfiltered figure.
     *
     * @param \stdClass $item Program aggregate row.
     * @param array<int,array> $overrides Recomputed cells keyed by outcome-version ID.
     * @return array Cell values for one row.
     */
    private function cells(\stdClass $item, array $overrides): array {
        $itemverid = (int) $item->itemverid;
        if (isset($overrides[$itemverid])) {
            return $overrides[$itemverid];
        }
        if ($this->subjectfilter !== self::SUBJECTS_ALL) {
            return [
                'percentage' => null,
                'calculatedcount' => 0,
                'subjectcount' => 0,
                'assessedcount' => 0,
                'metcount' => 0,
                'attainmentpercent' => null,
                'numerator' => decimal::ZERO,
                'denominator' => decimal::ZERO,
                'suppressed' => (int) $this->snapshot->suppressionthreshold > 0,
            ];
        }
        return [
            'percentage' => $item->percentage,
            'calculatedcount' => (int) ($item->payload['calculatedcount'] ?? 0),
            'subjectcount' => (int) $item->subjectcount,
            'assessedcount' => (int) ($item->payload['assessedcount'] ?? 0),
            'metcount' => (int) ($item->payload['metcount'] ?? 0),
            'attainmentpercent' => $item->payload['attainmentpercent'] ?? null,
            'numerator' => decimal::canonical($item->numerator, 'numerator'),
            'denominator' => decimal::canonical($item->denominator, 'denominator'),
            'suppressed' => (int) $item->suppressed === 1,
        ];
    }

    /**
     * Resolve the higher-level outcome each captured outcome rolls up into.
     *
     * The alignment edges come from the snapshot's own captured relation rows, so
     * the grouping reflects the curriculum as it stood at the data freeze rather
     * than as it stands today. Only the identity of a target outcome that was not
     * itself captured is read live, and code and framework code are immutable.
     *
     * @return array<int,array<string,string>> Group key to label, keyed by outcome-version ID.
     */
    private function rollup_labels(): array {
        global $DB;
        $itemids = [];
        foreach ($this->grouped[snapshot_service::ITEM_OUTCOME_VERSION] ?? [] as $item) {
            $itemids[(int) $item->itemverid] = null;
        }
        if ($itemids === []) {
            return [];
        }
        // Outcome versions are immutable once approved, so the version-to-outcome
        // link is a stable fact rather than a figure that could have drifted.
        [$insql, $params] = $DB->get_in_or_equal(array_keys($itemids), SQL_PARAMS_NAMED, 'iv');
        foreach ($DB->get_records_select('local_outcomemap_itemver', "id $insql", $params,
                '', 'id, itemid') as $version) {
            $itemids[(int) $version->id] = (int) $version->itemid;
        }

        $edges = [];
        foreach ($this->grouped[snapshot_service::ITEM_RELATION_VERSION] ?? [] as $item) {
            $payload = $item->payload;
            $type = (string) ($payload['type'] ?? '');
            if (!in_array($type, [relation_service::ALIGNS_TO, relation_service::CONTRIBUTES_TO], true)
                    || ($payload['status'] ?? '') !== workflow::APPROVED) {
                continue;
            }
            $edges[(int) $payload['sourceitemid']][(int) $payload['targetitemid']] = true;
        }
        if ($edges === []) {
            // A capture only holds the relations its evidence travelled, so a
            // snapshot built from direct question mappings records none at all.
            // Grouping then falls back to the curriculum as it stands today and
            // says so, because a single unaligned bucket answers nothing. Only
            // the grouping moves: every figure still comes from the frozen rows.
            $edges = $this->live_edges();
            $this->liverollup = $edges !== [];
        }
        if ($edges === []) {
            return [];
        }

        $labels = $this->outcome_identities($itemids, $edges);
        $rollup = [];
        foreach ($itemids as $itemverid => $itemid) {
            if ($itemid === null) {
                continue;
            }
            $targets = $this->groupby === self::GROUP_COURSE
                ? array_keys($edges[$itemid] ?? [])
                : self::terminals($itemid, $edges);
            foreach ($targets as $targetid) {
                if ($targetid === $itemid) {
                    continue;
                }
                $rollup[(int) $itemverid]['t:' . $labels[$targetid]] = $labels[$targetid];
            }
        }
        return $rollup;
    }

    /**
     * Count how many subjects met and missed the criterion in each course.
     *
     * @return array<int,array{passed:int,failed:int}> Counts keyed by course-instance ID.
     */
    private function course_passes(): array {
        $passes = [];
        if ($this->criterion === null) {
            return $passes;
        }
        foreach ($this->coursepool as $courses) {
            foreach ($courses as $cinstid => $bucket) {
                $percentage = self::percentage($bucket);
                if ($percentage === null) {
                    continue;
                }
                $cinstid = (int) $cinstid;
                $passes[$cinstid] ??= ['passed' => 0, 'failed' => 0];
                $met = decimal::cmp($percentage, $this->criterion) >= 0;
                $passes[$cinstid][$met ? 'passed' : 'failed']++;
            }
        }
        return $passes;
    }

    /**
     * Summarise how the population is progressing through its courses.
     *
     * The three states are mutually exclusive and add up to the snapshot's own
     * population count, so the tiles cannot imply a larger or smaller cohort than
     * the record does. A subject with no calculable result anywhere is reported as
     * not yet judged rather than folded into either of the other two.
     *
     * @return array Tiles, notes, and the criterion the verdicts used.
     */
    private function progress(): array {
        $population = (int) $this->snapshot->populationcount;
        $passed = 0;
        $failed = 0;
        foreach ($this->verdicts as $verdict) {
            if ($verdict === 'passed') {
                $passed++;
            } else if ($verdict === 'failed') {
                $failed++;
            }
        }
        // Subjects with no calculable course result are the remainder of the
        // governed population, including any who hold no captured result at all.
        $unjudged = max(0, $population - $passed - $failed);
        $share = static fn(int $count): string => $population === 0
            ? '—'
            : number_format($count / $population * 100, 1) . '%';
        return [
            'known' => $this->criterion !== null,
            'criterion' => $this->criterion === null
                ? ''
                : get_string('snapreport_progresscriterion', 'local_outcomemap',
                    number_format((float) $this->criterion, 1)),
            'populationline' => get_string('snapreport_progresspopulation', 'local_outcomemap', (object) [
                'count' => number_format($population),
                'source' => get_string('population_' . $this->snapshot->populationsource,
                    'local_outcomemap'),
            ]),
            'tiles' => [
                [
                    'label' => get_string('snapreport_progress_passedall', 'local_outcomemap'),
                    'value' => number_format($passed),
                    'note' => get_string('snapreport_progress_passedallnote', 'local_outcomemap',
                        $share($passed)),
                    'variant' => 'lom-snap-progress-good',
                ],
                [
                    'label' => get_string('snapreport_progress_failedany', 'local_outcomemap'),
                    'value' => number_format($failed),
                    'note' => get_string('snapreport_progress_failedanynote', 'local_outcomemap',
                        $share($failed)),
                    'variant' => $failed > 0 ? 'lom-snap-progress-bad' : '',
                ],
                [
                    'label' => get_string('snapreport_progress_unjudged', 'local_outcomemap'),
                    'value' => number_format($unjudged),
                    'note' => get_string('snapreport_progress_unjudgednote', 'local_outcomemap',
                        $share($unjudged)),
                    'variant' => $unjudged > 0 ? 'lom-snap-progress-warn' : '',
                ],
            ],
            'counts' => ['passed' => $passed, 'failed' => $failed, 'unjudged' => $unjudged],
        ];
    }

    /**
     * The subject count the attainment table currently reports over.
     *
     * @return int
     */
    private function population(): int {
        return $this->subjectfilter === self::SUBJECTS_ALL
            ? (int) $this->snapshot->populationcount
            : count($this->selected);
    }

    /**
     * Build the grouping and subject-filter controls.
     *
     * @param moodle_url $baseurl Snapshot list URL.
     * @param array $counts Progress counts used for the filter labels.
     * @return array Template context for both control rows.
     */
    private function controls(moodle_url $baseurl, array $counts): array {
        $link = fn(array $params): string => (new moodle_url($baseurl, $params + [
            'action' => 'view',
            'id' => (int) $this->snapshot->id,
        ]))->out(false);
        $groupings = [];
        foreach (self::GROUPINGS as $grouping) {
            $groupings[] = [
                'label' => get_string('snapreport_group_' . $grouping, 'local_outcomemap'),
                'url' => $link(['group' => $grouping, 'subjects' => $this->subjectfilter]),
                'active' => $this->groupby === $grouping,
            ];
        }
        $labels = [
            self::SUBJECTS_ALL => (int) $this->snapshot->populationcount,
            self::SUBJECTS_PASSEDALL => $counts['passed'],
            self::SUBJECTS_FAILEDANY => $counts['failed'],
        ];
        $filters = [];
        foreach (self::SUBJECT_FILTERS as $subjects) {
            $filters[] = [
                'label' => get_string('snapreport_subjects_' . $subjects, 'local_outcomemap'),
                'count' => number_format($labels[$subjects]),
                'url' => $link(['group' => $this->groupby, 'subjects' => $subjects]),
                'active' => $this->subjectfilter === $subjects,
            ];
        }
        return [
            'groupings' => $groupings,
            'groupsub' => get_string('snapreport_groupsub_' . $this->groupby, 'local_outcomemap'),
            'liverollup' => $this->liverollup && $this->groupby !== self::GROUP_FRAMEWORK,
            'liverollupnote' => get_string('snapreport_liverollup', 'local_outcomemap',
                userdate((int) $this->snapshot->populationat)),
            'filters' => $filters,
            'filtered' => $this->subjectfilter !== self::SUBJECTS_ALL,
            'filternote' => get_string('snapreport_filternote', 'local_outcomemap', (object) [
                'selected' => number_format(count($this->selected)),
                'population' => number_format((int) $this->snapshot->populationcount),
                'filter' => get_string('snapreport_subjects_' . $this->subjectfilter, 'local_outcomemap'),
            ]),
        ];
    }

    /**
     * Load the approved alignment edges that were in force at the data freeze.
     *
     * Read from the live relation table, which is why it is used only to group
     * rows a capture holds no relations for. Effective dates are evaluated at the
     * snapshot's own population timestamp rather than now, so the grouping is as
     * close to the freeze as an uncaptured structure can be — but an edge
     * authored later with a backdated start would still appear, and the reader is
     * told the grouping did not come out of the capture.
     *
     * @return array<int,array<int,bool>> Edges keyed by source outcome ID.
     */
    private function live_edges(): array {
        global $DB;
        $now = (int) $this->snapshot->populationat;
        [$typesql, $params] = $DB->get_in_or_equal(
            [relation_service::ALIGNS_TO, relation_service::CONTRIBUTES_TO],
            SQL_PARAMS_NAMED,
            'reltype'
        );
        $params += ['status' => workflow::APPROVED, 'at1' => $now, 'at2' => $now];
        $edges = [];
        $relations = $DB->get_records_select(
            'local_outcomemap_rel',
            "type $typesql AND status = :status AND effectivefrom <= :at1
                AND (effectiveto IS NULL OR effectiveto > :at2)",
            $params,
            '',
            'id, sourceitemid, targetitemid'
        );
        foreach ($relations as $relation) {
            $edges[(int) $relation->sourceitemid][(int) $relation->targetitemid] = true;
        }
        return $edges;
    }

    /**
     * Walk the alignment edges to the outcomes at the end of every chain.
     *
     * @param int $itemid Starting outcome ID.
     * @param array<int,array<int,bool>> $edges Alignment edges by source outcome ID.
     * @return int[] Terminal outcome IDs.
     */
    private static function terminals(int $itemid, array $edges): array {
        $terminals = [];
        $seen = [$itemid => true];
        $queue = array_keys($edges[$itemid] ?? []);
        // Bounded by the visited set, so a relation cycle cannot spin here even
        // though only the acyclic relation types are validated as acyclic.
        while ($queue !== []) {
            $current = (int) array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $next = array_keys($edges[$current] ?? []);
            if ($next === []) {
                $terminals[$current] = true;
                continue;
            }
            foreach ($next as $target) {
                $queue[] = $target;
            }
        }
        return array_keys($terminals);
    }

    /**
     * Label every outcome the grouping can land on, by immutable identity.
     *
     * @param array<int,int|null> $itemids Outcome IDs keyed by captured version ID.
     * @param array<int,array<int,bool>> $edges Alignment edges by source outcome ID.
     * @return array<int,string> Framework-qualified code keyed by outcome ID.
     */
    private function outcome_identities(array $itemids, array $edges): array {
        global $DB;
        $wanted = [];
        foreach ($itemids as $itemid) {
            if ($itemid !== null) {
                $wanted[$itemid] = true;
            }
        }
        foreach ($edges as $source => $targets) {
            $wanted[(int) $source] = true;
            foreach (array_keys($targets) as $target) {
                $wanted[(int) $target] = true;
            }
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($wanted), SQL_PARAMS_NAMED, 'it');
        $labels = [];
        $records = $DB->get_records_sql(
            "SELECT i.id, i.code, f.code AS frameworkcode
               FROM {local_outcomemap_item} i
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE i.id $insql",
            $params
        );
        foreach ($records as $record) {
            $labels[(int) $record->id] = $record->frameworkcode . '.' . $record->code;
        }
        foreach (array_keys($wanted) as $itemid) {
            $labels[(int) $itemid] ??= get_string('snapreport_groupunknown', 'local_outcomemap');
        }
        return $labels;
    }

    /**
     * Build the attainment-rate cells of one program outcome row.
     *
     * The rate is the share of assessed learners who met the policy's
     * achievement criterion, which is the figure accreditation reporting is
     * compared against. It is deliberately separate from the pooled score in
     * the neighbouring column.
     *
     * @param array $payload Program aggregate payload.
     * @return array Template values.
     */
    private static function attainment_cells(array $payload): array {
        $rate = $payload['attainmentpercent'] ?? null;
        $benchmark = $payload['benchmarkpercent'] ?? null;
        $met = $payload['benchmarkmet'] ?? null;
        return [
            'hasrate' => $rate !== null,
            'rate' => $rate === null
                ? get_string('calculationnotavailable', 'local_outcomemap')
                : number_format((float) $rate, 1) . '%',
            'ratedetail' => get_string('attainmentrate_value', 'local_outcomemap', (object) [
                'met' => number_format((int) ($payload['metcount'] ?? 0)),
                'assessed' => number_format((int) ($payload['assessedcount'] ?? 0)),
                'rate' => $rate === null ? '—' : number_format((float) $rate, 1),
            ]),
            'benchmark' => $benchmark === null ? '' : get_string('snapreport_vsbenchmark',
                'local_outcomemap', number_format((float) $benchmark, 1)),
            'hasbenchmark' => $benchmark !== null,
            'benchmarkmet' => $met === true,
            'benchmarkmissed' => $met === false,
            'benchmarklabel' => get_string(
                $met === null ? 'benchmarkmet_unknown' : ($met ? 'benchmarkmet_yes' : 'benchmarkmet_no'),
                'local_outcomemap'
            ),
        ];
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
        $judged = 0;
        $benchmarksmet = 0;
        $seen = [];
        foreach ($outcomes as $group) {
            foreach ($group['rows'] as $row) {
                // The alignment groupings report a shared outcome under every
                // higher-level outcome it answers, so the aggregate line counts
                // each captured outcome once regardless of the view.
                $key = $row['itemverid'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $numerator = decimal::add($numerator, $row['numerator']);
                $denominator = decimal::add($denominator, $row['denominator']);
                $results += $row['calculated'];
                // Attainment rates are shares of different learner sets, so they
                // cannot be pooled. The defensible program summary is how many
                // outcomes cleared their own benchmark.
                if ($row['benchmarkmet'] || $row['benchmarkmissed']) {
                    $judged++;
                    $benchmarksmet += $row['benchmarkmet'] ? 1 : 0;
                }
            }
        }
        $percent = decimal::is_zero($denominator)
            ? null
            : decimal::div(decimal::mul($numerator, '100'), $denominator);
        return [
            'learners' => number_format($this->population()),
            'results' => number_format($results),
            'percent' => $percent === null
                ? get_string('calculationnotavailable', 'local_outcomemap')
                : number_format((float) $percent, 1) . '%',
            'frameworkcount' => count($outcomes),
            'hasbenchmarks' => $judged > 0,
            'benchmarksummary' => get_string('snapreport_benchmarksmet', 'local_outcomemap', (object) [
                'met' => number_format($benchmarksmet),
                'judged' => number_format($judged),
            ]),
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
        $passes = $this->course_passes();
        $rows = [];
        foreach ($this->grouped[snapshot_service::ITEM_COURSE_INSTANCE] ?? [] as $item) {
            $cinstid = (int) $item->cinstid;
            $payload = $item->payload;
            $count = $outcomes[$cinstid] ?? 0;
            $learners = count($this->learnersbycourse[$cinstid] ?? []);
            $pass = $passes[$cinstid] ?? ['passed' => 0, 'failed' => 0];
            $graded = $pass['passed'] + $pass['failed'];
            $rows[] = [
                'haspass' => $this->criterion !== null && $graded > 0,
                'passed' => number_format($pass['passed']),
                'failed' => number_format($pass['failed']),
                'passrate' => $graded === 0
                    ? get_string('calculationnotavailable', 'local_outcomemap')
                    : number_format($pass['passed'] / $graded * 100, 1) . '%',
                'passline' => get_string('snapreport_coursepassline', 'local_outcomemap', (object) [
                    'passed' => number_format($pass['passed']),
                    'graded' => number_format($graded),
                ]),
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
