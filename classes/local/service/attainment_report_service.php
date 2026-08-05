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
 * Page model for the course outcome attainment report.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Builds the whole attainment report as one plain data model.
 *
 * The report answers one question — are learners reaching the outcomes — and
 * refuses three ways of answering it dishonestly.
 *
 * It reports an attainment *rate*, the share of graded learners who reached the
 * achievement criterion, rather than a mean of means: an outcome graded for
 * eight learners must not move a headline as hard as one graded for sixty.
 *
 * It reports per level. Unit, course and program outcomes describe the same
 * coursework at three grains, so pooling them counts the same evidence three
 * times. Levels are derived from the approved alignment graph rather than
 * declared, so a curriculum with two levels or five is reported as it is.
 *
 * And it never hides thin evidence. An outcome with no graded result is a
 * coverage finding and is carried as a row of its own; an outcome under the
 * governing suppression floor is marked, and under the accreditation lens is
 * withheld exactly as it would be in a submission.
 *
 * Nothing here is recomputed from evidence. Every percentage is the figure the
 * calculation engine stored, so this page and a learner's own page can never
 * disagree.
 */
final class attainment_report_service extends base_service {
    /** Every learner holding a stored result. */
    public const COHORT_ALL = 'all';

    /** Learners who completed the Moodle course. */
    public const COHORT_COMPLETED = 'completed';

    /** Learners who did not complete the Moodle course. */
    public const COHORT_NOTCOMPLETED = 'notcompleted';

    /** Selectable cohorts, in display order. */
    public const COHORTS = [self::COHORT_ALL, self::COHORT_COMPLETED, self::COHORT_NOTCOMPLETED];

    /** Teaching view: diagnostic flags on, thin results shown with their sample size. */
    public const LENS_EDUCATOR = 'educator';

    /** Program view: findings framed as risk to a program-level claim. */
    public const LENS_PROGRAM = 'program';

    /** Submission view: the governing suppression floor is enforced, not annotated. */
    public const LENS_ACCREDITATION = 'accreditation';

    /** Selectable lenses, in display order. */
    public const LENSES = [self::LENS_EDUCATOR, self::LENS_PROGRAM, self::LENS_ACCREDITATION];

    /** Headline cards and the level rollup. */
    public const VIEW_SUMMARY = 'summary';

    /** Every outcome, top down, one level inside another. */
    public const VIEW_LEDGER = 'ledger';

    /** One column per level, with an alignment trace. */
    public const VIEW_MAP = 'map';

    /** The top level across every course in the program that claims it. */
    public const VIEW_ROLLUP = 'rollup';

    /** Selectable views, in display order. */
    public const VIEWS = [self::VIEW_SUMMARY, self::VIEW_LEDGER, self::VIEW_MAP, self::VIEW_ROLLUP];

    /**
     * Percentage-point spread below which two cohorts are reported as alike.
     *
     * A display heuristic for surfacing outcomes that do not separate learners
     * who finished from learners who did not — never a pass decision, which
     * lives in the bands the calculation policy defined.
     */
    public const ALIKE_SPREAD = 8.0;

    /** Longest alignment chain walked when deriving levels. */
    private const MAX_DEPTH = 10;

    /** Priority findings carried onto the page. */
    private const MAX_PRIORITIES = 5;

    /** Places any one kind of finding may take before another kind is offered one. */
    private const MAX_PER_FINDING = 2;

    /**
     * Build the complete report model for one course.
     *
     * @param int $courseid Moodle course ID.
     * @param string $cohort One of this class's COHORT_* values.
     * @param string $lens One of this class's LENS_* values.
     * @param int|null $at Evaluation timestamp; defaults to now.
     * @return \stdClass The whole page model.
     */
    public static function report(int $courseid, string $cohort = self::COHORT_ALL,
            string $lens = self::LENS_EDUCATOR, ?int $at = null): \stdClass {
        global $DB;
        $context = \context_course::instance($courseid, MUST_EXIST);
        // Cohort attainment is other people's results, so this is the all-results
        // capability rather than the definitions one the mapping pages use.
        require_capability('local/outcomemap:viewallresults', $context);
        $at = $at ?? time();

        $instances = $DB->get_records('local_outcomemap_cinst', [
            'moodlecourseid' => $courseid,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
        ], 'periodcode, id', 'id, courseid, periodcode');
        if (!$instances) {
            return (object) ['hasinstance' => false, 'courseid' => $courseid];
        }

        $program = self::program_of($instances, $at);
        $policy = self::reporting_policy($program, $at);
        $split = self::completion_split($courseid);
        $records = self::load_results($instances);
        $nodes = self::build_nodes($courseid, $records, $instances, $at);
        self::score_nodes($nodes, $records, $policy, $split);
        $tiers = self::build_tiers($nodes);
        // Coverage runs last so an outcome's state can distinguish "no result
        // yet" from "no result ever", which is a mapping question rather than a
        // results one.
        self::apply_coverage($courseid, $context, $nodes);
        foreach ($nodes as $node) {
            $node->state = self::state_of($node, $policy);
        }

        $lenses = self::available_lenses($policy);
        if (!in_array($lens, $lenses, true)) {
            $lens = self::LENS_EDUCATOR;
        }
        if (!in_array($cohort, self::COHORTS, true) || ($split->rule === null && $cohort !== self::COHORT_ALL)) {
            $cohort = self::COHORT_ALL;
        }

        $report = (object) [
            'hasinstance' => true,
            'courseid' => $courseid,
            'periodcodes' => array_values(array_unique(array_map(
                static fn(\stdClass $i): string => (string) $i->periodcode, $instances))),
            'program' => $program,
            'policy' => $policy,
            'cohort' => $cohort,
            'cohortrule' => $split->rule,
            'cohortrulevalue' => $split->value,
            'cohortcounts' => self::cohort_counts($records, $split),
            'lens' => $lens,
            'lenses' => $lenses,
            'nodes' => $nodes,
            'tiers' => $tiers,
            'learners' => count(self::learner_ids($records)),
            'coverageknown' => has_capability('local/outcomemap:viewdefinitions', $context),
        ];
        $report->toptier = $tiers ? $tiers[0] : null;
        $report->basetier = $tiers ? $tiers[count($tiers) - 1] : null;
        $report->headline = $report->toptier ? $report->toptier->stats[$cohort] : null;
        $report->unweightedmean = self::unweighted_mean($nodes, $cohort);
        $report->gaps = self::build_gaps($nodes, $tiers, $policy, $cohort);
        $report->priorities = self::build_priorities($report);
        $report->rollup = self::build_rollup($report, $at);
        return $report;
    }

    /**
     * Resolve the program the course's catalog course belongs to.
     *
     * A course can sit in more than one program over time; only the membership
     * effective now is reported, because the accreditation policy that governs
     * this page is resolved from it.
     *
     * @param array<int,\stdClass> $instances Approved confirmed course instances.
     * @param int $at Effective timestamp.
     * @return \stdClass|null Program record, or null when the course sits in none.
     */
    private static function program_of(array $instances, int $at): ?\stdClass {
        global $DB;
        $catalogids = array_values(array_unique(array_map(
            static fn(\stdClass $i): int => (int) $i->courseid, $instances)));
        [$insql, $params] = $DB->get_in_or_equal($catalogids, SQL_PARAMS_NAMED, 'cc');
        $params += ['pcstatus' => workflow::APPROVED, 'pstatus' => workflow::APPROVED,
            'at1' => $at, 'at2' => $at];
        $records = $DB->get_records_sql(
            "SELECT p.id, p.code, p.name
               FROM {local_outcomemap_progcourse} pc
               JOIN {local_outcomemap_program} p ON p.id = pc.programid
              WHERE pc.courseid $insql
                AND pc.status = :pcstatus
                AND p.status = :pstatus
                AND pc.effectivefrom <= :at1
                AND (pc.effectiveto IS NULL OR pc.effectiveto > :at2)
           ORDER BY p.code",
            $params
        );
        return $records ? reset($records) : null;
    }

    /**
     * Resolve the accreditation policy that governs how this page may report.
     *
     * The policy carries the three numbers the report is meaningless without: the
     * criterion one learner is judged against, the benchmark the resulting rate is
     * compared to, and the cohort size below which a rate may not be published.
     * None of them has a default — a guessed benchmark would be a faculty decision
     * invented by a report — so a course whose program has no approved policy is
     * reported without a target and without suppression, and says so.
     *
     * A policy that exists but cannot be read is a third case, and a real one:
     * these fields became required after the policy type shipped, approved
     * records are immutable, and so a site can hold an approved policy that no
     * longer normalises. Reporting without a benchmark is the honest outcome
     * there. Failing the whole page is not — the attainment figures do not
     * depend on the policy — and neither is reporting it as "no policy", which
     * would hide a governance record somebody needs to replace.
     *
     * @param \stdClass|null $program Owning program, if any.
     * @param int $at Effective timestamp.
     * @return \stdClass Resolved reporting numbers, with an availability flag.
     */
    private static function reporting_policy(?\stdClass $program, int $at): \stdClass {
        $absent = (object) [
            'available' => false,
            'unreadable' => false,
            'problemfield' => null,
            'name' => null,
            'version' => null,
            'target' => null,
            'criterion' => null,
            'criterionraw' => null,
            'floor' => null,
        ];
        if ($program === null) {
            return $absent;
        }
        try {
            $policy = suppression_service::resolve((int) $program->id, $at);
            if ($policy === null) {
                return $absent;
            }
            $config = suppression_service::config_of($policy);
        } catch (validation_exception $e) {
            $absent->unreadable = true;
            $absent->problemfield = isset($e->a->field) && $e->a->field !== ''
                ? (string) $e->a->field
                : null;
            return $absent;
        }
        return (object) [
            'available' => true,
            'unreadable' => false,
            'problemfield' => null,
            'name' => (string) $policy->name,
            'version' => (int) $policy->version,
            'target' => (float) $config['benchmarkpercent'],
            'criterion' => (float) $config['achievementminpercent'],
            'criterionraw' => $config['achievementminpercent'],
            'floor' => (int) $config['mincohortsize'],
        ];
    }

    /**
     * Lenses this course can honestly offer.
     *
     * The accreditation lens enforces a suppression floor, so it is offered only
     * where an approved policy defines one. Offering it otherwise would show a
     * submission view that suppresses nothing.
     *
     * @param \stdClass $policy Resolved reporting policy.
     * @return string[] Selectable lens keys.
     */
    private static function available_lenses(\stdClass $policy): array {
        return $policy->available
            ? self::LENSES
            : [self::LENS_EDUCATOR, self::LENS_PROGRAM];
    }

    /**
     * Decide which learners completed the course, and by what rule.
     *
     * Course completion is preferred: it is the institution's own recorded
     * statement that a learner finished, and it is what a credential is issued
     * against. Where completion is not configured, the course grade item's pass
     * mark is the next most defensible standard, because a teacher set it. Where
     * neither exists the split is simply unavailable — inventing a threshold
     * would make the cohort comparison a property of this report rather than of
     * the course.
     *
     * @param int $courseid Moodle course ID.
     * @return \stdClass Rule name, its threshold, and the completed learner set.
     */
    private static function completion_split(int $courseid): \stdClass {
        global $DB, $CFG;
        $none = (object) ['rule' => null, 'value' => null, 'completed' => []];

        $course = get_course($courseid);
        if (!empty($CFG->enablecompletion) && !empty($course->enablecompletion)
                && $DB->record_exists('course_completion_criteria', ['course' => $courseid])) {
            $userids = $DB->get_fieldset_select(
                'course_completions',
                'userid',
                'course = :courseid AND timecompleted IS NOT NULL',
                ['courseid' => $courseid]
            );
            return (object) [
                'rule' => 'completion',
                'value' => null,
                'completed' => array_fill_keys(array_map('intval', $userids), true),
            ];
        }

        $item = $DB->get_record('grade_items', ['courseid' => $courseid, 'itemtype' => 'course']);
        if (!$item || (float) $item->gradepass <= 0 || (float) $item->grademax <= 0) {
            return $none;
        }
        $pass = (float) $item->gradepass;
        $grades = $DB->get_records_select(
            'grade_grades',
            'itemid = :itemid AND finalgrade IS NOT NULL',
            ['itemid' => (int) $item->id],
            '',
            'id, userid, finalgrade'
        );
        $completed = [];
        foreach ($grades as $grade) {
            if ((float) $grade->finalgrade >= $pass) {
                $completed[(int) $grade->userid] = true;
            }
        }
        return (object) [
            'rule' => 'gradepass',
            'value' => $pass / (float) $item->grademax * 100,
            'completed' => $completed,
        ];
    }

    /**
     * Load every authoritative course-scope result, one row per learner per outcome.
     *
     * Kept per learner rather than pre-aggregated: the cohort split and the
     * attainment rate are both counts over learners, and an aggregate cannot be
     * split back apart.
     *
     * @param array<int,\stdClass> $instances Approved confirmed course instances.
     * @return \stdClass[] Result rows carrying their outcome and band.
     */
    private static function load_results(array $instances): array {
        global $DB;
        [$cinstsql, $params] = $DB->get_in_or_equal(array_keys($instances), SQL_PARAMS_NAMED, 'ci');
        $params['coursescope'] = calculation_service::SCOPE_COURSE;
        return $DB->get_records_sql(
            "SELECT r.id, r.userid, r.itemverid, r.percentage, r.state, r.policyid,
                    v.itemid, b.code AS bandcode, b.name AS bandname, b.sortorder AS bandorder
               FROM {local_outcomemap_result} r
               JOIN {local_outcomemap_itemver} v ON v.id = r.itemverid
          LEFT JOIN {local_outcomemap_band} b ON b.id = r.bandid
              WHERE r.cinstid $cinstsql
                AND r.supersededby IS NULL
                AND r.scopetype = :coursescope",
            $params
        );
    }

    /**
     * Distinct learners holding any stored result.
     *
     * @param \stdClass[] $records Result rows.
     * @return array<int,bool> Learner IDs, keyed for lookup.
     */
    private static function learner_ids(array $records): array {
        $ids = [];
        foreach ($records as $record) {
            $ids[(int) $record->userid] = true;
        }
        return $ids;
    }

    /**
     * Count the learners in each cohort.
     *
     * @param \stdClass[] $records Result rows.
     * @param \stdClass $split Completion split.
     * @return array<string,int> Learner counts keyed by cohort.
     */
    private static function cohort_counts(array $records, \stdClass $split): array {
        $counts = [self::COHORT_ALL => 0, self::COHORT_COMPLETED => 0, self::COHORT_NOTCOMPLETED => 0];
        foreach (array_keys(self::learner_ids($records)) as $userid) {
            $counts[self::COHORT_ALL]++;
            $counts[isset($split->completed[$userid])
                ? self::COHORT_COMPLETED
                : self::COHORT_NOTCOMPLETED]++;
        }
        return $counts;
    }

    /**
     * Assemble every outcome the report is about, measured or not.
     *
     * Three sources, in widening circles: outcomes learners hold results for, the
     * outcomes the course is responsible for whether or not anything was stored
     * for them, and every outcome those two reach through the approved alignment
     * graph. The third is what gives the report its levels — a course outcome
     * that no learner reached is still the thing a program outcome rests on, and
     * a report that omitted it would show a program level made of nothing.
     *
     * @param int $courseid Moodle course ID.
     * @param \stdClass[] $records Result rows.
     * @param array<int,\stdClass> $instances Approved confirmed course instances.
     * @param int $at Effective timestamp.
     * @return array<int,\stdClass> Nodes keyed by stable outcome item ID.
     */
    private static function build_nodes(int $courseid, array $records, array $instances,
            int $at): array {
        global $DB;
        $itemids = [];
        foreach ($records as $record) {
            $itemids[(int) $record->itemid] = true;
        }
        foreach (self::course_scope_items($instances, $at) as $itemid) {
            $itemids[$itemid] = true;
        }

        $edges = self::alignment_edges($at);
        // Walk upward so the levels above the course appear even when no evidence
        // has propagated into them yet.
        $frontier = array_keys($itemids);
        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier; $depth++) {
            $next = [];
            foreach ($frontier as $itemid) {
                foreach ($edges[$itemid] ?? [] as $edge) {
                    if (!isset($itemids[$edge->targetitemid])) {
                        $itemids[$edge->targetitemid] = true;
                        $next[] = $edge->targetitemid;
                    }
                }
            }
            $frontier = $next;
        }
        if (!$itemids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($itemids), SQL_PARAMS_NAMED, 'it');
        $params += ['vstatus' => workflow::APPROVED, 'at1' => $at, 'at2' => $at];
        $records2 = $DB->get_records_sql(
            "SELECT v.id AS itemverid, i.id AS itemid, i.code AS outcomecode, v.version,
                    v.statement, v.shortstatement, f.id AS frameworkid, f.code AS frameworkcode,
                    f.name AS frameworkname, f.ownertype
               FROM {local_outcomemap_item} i
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
               JOIN {local_outcomemap_itemver} v ON v.itemid = i.id
              WHERE i.id $insql
                AND v.status = :vstatus
                AND v.effectivefrom <= :at1
                AND (v.effectiveto IS NULL OR v.effectiveto > :at2)
           ORDER BY i.id, v.version DESC",
            $params
        );

        $nodes = [];
        foreach ($records2 as $record) {
            $itemid = (int) $record->itemid;
            if (isset($nodes[$itemid])) {
                // Ordered version-descending, so the first row wins if an item
                // somehow has two versions effective at once.
                continue;
            }
            $nodes[$itemid] = (object) [
                'itemid' => $itemid,
                'itemverid' => (int) $record->itemverid,
                'code' => (string) $record->outcomecode,
                'version' => (int) $record->version,
                'statement' => (string) $record->statement,
                'shortstatement' => $record->shortstatement,
                'frameworkid' => (int) $record->frameworkid,
                'frameworkcode' => (string) $record->frameworkcode,
                'frameworkname' => (string) $record->frameworkname,
                'ownertype' => (string) $record->ownertype,
                'parents' => [],
                'children' => [],
                'propagates' => false,
                'assessedcontent' => null,
                'sources' => [],
                'height' => 0,
                'tier' => 0,
                'stats' => [],
                'state' => course_attainment_service::STATE_UNASSESSED,
            ];
        }

        foreach ($nodes as $itemid => $node) {
            foreach ($edges[$itemid] ?? [] as $edge) {
                if (!isset($nodes[$edge->targetitemid])) {
                    continue;
                }
                $node->parents[$edge->targetitemid] = $edge->targetitemid;
                $nodes[$edge->targetitemid]->children[$itemid] = $itemid;
                // Only a contributes_to edge carries evidence upward, which is the
                // calculation engine's rule; an aligns_to edge is curriculum
                // context and never becomes an attainment claim.
                $node->propagates = $node->propagates
                    || $edge->type === relation_service::CONTRIBUTES_TO;
            }
        }
        self::assign_heights($nodes);
        return $nodes;
    }

    /**
     * Outcome items the course is responsible for, whether measured or not.
     *
     * Scope is the currently effective approved outcomes of the frameworks owned
     * by the catalog courses this Moodle course is linked to — the same
     * association that makes a mapping valid.
     *
     * @param array<int,\stdClass> $instances Approved confirmed course instances.
     * @param int $at Effective timestamp.
     * @return int[] Stable outcome item IDs.
     */
    private static function course_scope_items(array $instances, int $at): array {
        global $DB;
        $catalogids = array_values(array_unique(array_map(
            static fn(\stdClass $i): int => (int) $i->courseid, $instances)));
        [$insql, $params] = $DB->get_in_or_equal($catalogids, SQL_PARAMS_NAMED, 'cc');
        $params += [
            'ownertype' => framework_service::OWNER_COURSE,
            'fstatus' => workflow::APPROVED,
            'istatus' => workflow::APPROVED,
            'vstatus' => workflow::APPROVED,
            'at1' => $at,
            'at2' => $at,
        ];
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT i.id
               FROM {local_outcomemap_fw} f
               JOIN {local_outcomemap_item} i ON i.frameworkid = f.id
               JOIN {local_outcomemap_itemver} v ON v.itemid = i.id
              WHERE f.ownertype = :ownertype
                AND f.ownerid $insql
                AND f.status = :fstatus
                AND i.status = :istatus
                AND v.status = :vstatus
                AND v.effectivefrom <= :at1
                AND (v.effectiveto IS NULL OR v.effectiveto > :at2)",
            $params
        ));
    }

    /**
     * Load every approved, currently effective alignment edge.
     *
     * Loaded whole rather than per outcome: the walk that derives levels visits
     * the graph repeatedly, and one query is cheaper than a query per step.
     *
     * @param int $at Effective timestamp.
     * @return array<int,\stdClass[]> Outgoing edges keyed by source item ID.
     */
    private static function alignment_edges(int $at): array {
        global $DB;
        [$typesql, $params] = $DB->get_in_or_equal([
            relation_service::ALIGNS_TO,
            relation_service::CONTRIBUTES_TO,
        ], SQL_PARAMS_NAMED, 'reltype');
        $params += ['status' => workflow::APPROVED, 'at1' => $at, 'at2' => $at];
        $relations = $DB->get_records_select(
            'local_outcomemap_rel',
            "type $typesql AND status = :status AND effectivefrom <= :at1
                AND (effectiveto IS NULL OR effectiveto > :at2)",
            $params,
            'sourceitemid, id',
            'id, sourceitemid, targetitemid, type'
        );
        $edges = [];
        foreach ($relations as $relation) {
            $source = (int) $relation->sourceitemid;
            $edges[$source][] = (object) [
                'targetitemid' => (int) $relation->targetitemid,
                'type' => (string) $relation->type,
            ];
        }
        return $edges;
    }

    /**
     * Derive each outcome's level from how far it still has to climb.
     *
     * Height is the longest approved alignment chain leading out of an outcome, so
     * height zero is a level nothing sits above and the largest height is the
     * grain the course actually teaches at. Deriving it beats declaring it: a
     * curriculum with unit, course and program outcomes falls out as three levels
     * without the plugin having to hold an opinion about how many levels a
     * curriculum has.
     *
     * @param array<int,\stdClass> $nodes Nodes with parents already linked.
     * @return void
     */
    private static function assign_heights(array $nodes): void {
        $resolved = [];
        $height = static function (int $itemid, array $visiting) use (&$height, $nodes, &$resolved): int {
            if (isset($resolved[$itemid])) {
                return $resolved[$itemid];
            }
            // A cycle is a curriculum-authoring error, not a reason to hang.
            if (isset($visiting[$itemid])) {
                return 0;
            }
            $visiting[$itemid] = true;
            $best = 0;
            foreach ($nodes[$itemid]->parents ?? [] as $parentid) {
                if (isset($nodes[$parentid])) {
                    $best = max($best, 1 + $height($parentid, $visiting));
                }
            }
            return $resolved[$itemid] = $best;
        };
        foreach ($nodes as $itemid => $node) {
            $node->height = $height($itemid, []);
        }
    }

    /**
     * Attach per-cohort statistics to every node.
     *
     * @param array<int,\stdClass> $nodes Nodes keyed by item ID.
     * @param \stdClass[] $records Result rows.
     * @param \stdClass $policy Resolved reporting policy.
     * @param \stdClass $split Completion split.
     * @return void
     */
    private static function score_nodes(array $nodes, array $records, \stdClass $policy,
            \stdClass $split): void {
        $bounds = self::band_bounds($records);
        $buckets = [];
        foreach ($records as $record) {
            $itemid = (int) $record->itemid;
            if (!isset($nodes[$itemid])) {
                continue;
            }
            $cohort = isset($split->completed[(int) $record->userid])
                ? self::COHORT_COMPLETED
                : self::COHORT_NOTCOMPLETED;
            foreach ([self::COHORT_ALL, $cohort] as $key) {
                $buckets[$itemid][$key][] = $record;
            }
        }
        foreach ($nodes as $itemid => $node) {
            foreach (self::COHORTS as $cohort) {
                $node->stats[$cohort] = self::tally(
                    $buckets[$itemid][$cohort] ?? [],
                    $policy,
                    $bounds
                );
            }
        }
    }

    /**
     * Resolve the band range each governing policy defines.
     *
     * Read from the band definitions rather than from the bands learners landed
     * in, because the two differ in exactly the case that matters: an outcome
     * every assessed learner passed has nobody in its bottom band at all, and
     * ranking the bands that did occur would paint its weakest observed band as
     * a failure.
     *
     * @param \stdClass[] $records Result rows carrying their policy ID.
     * @return array<int,array{lowest:int,highest:int}> Band range keyed by policy ID.
     */
    private static function band_bounds(array $records): array {
        global $DB;
        $policyids = [];
        foreach ($records as $record) {
            $policyids[(int) $record->policyid] = true;
        }
        if (!$policyids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($policyids), SQL_PARAMS_NAMED, 'pol');
        $bounds = [];
        foreach ($DB->get_records_sql(
            "SELECT policyid, MIN(sortorder) AS lowestorder, MAX(sortorder) AS highestorder
               FROM {local_outcomemap_band}
              WHERE policyid $insql
           GROUP BY policyid",
            $params
        ) as $record) {
            $bounds[(int) $record->policyid] = [
                'lowest' => (int) $record->lowestorder,
                'highest' => (int) $record->highestorder,
            ];
        }
        return $bounds;
    }

    /**
     * Reduce one outcome's result rows to a reportable figure.
     *
     * Two things are counted separately and must stay separate. `graded` is how
     * many learners the engine produced a percentage for. `judged` is how many of
     * those could be placed against the achievement criterion — which is the
     * policy's criterion where one is approved, and otherwise clearing the lowest
     * band the calculation policy defined. A result the report cannot place is
     * never quietly counted as a failure.
     *
     * @param \stdClass[] $rows Result rows for one outcome and cohort.
     * @param \stdClass $policy Resolved reporting policy.
     * @param array<int,array{lowest:int,highest:int}> $bounds Band range by policy ID.
     * @return \stdClass Learner counts, attainment rate, mean, and band spread.
     */
    private static function tally(array $rows, \stdClass $policy, array $bounds): \stdClass {
        $graded = 0;
        $judged = 0;
        $met = 0;
        $sum = 0.0;
        $bands = [];
        foreach ($rows as $row) {
            if ($row->percentage === null) {
                continue;
            }
            $graded++;
            $sum += (float) $row->percentage;
            if ($row->bandcode !== null) {
                $key = (string) $row->bandcode;
                $range = $bounds[(int) $row->policyid] ?? null;
                $order = (int) $row->bandorder;
                $bands[$key] ??= (object) [
                    'code' => $key,
                    'name' => (string) $row->bandname,
                    'sortorder' => $order,
                    // Ranked against the bands the policy defines, so a band is
                    // only painted as the bottom one when it really is.
                    'rank' => $range === null ? 'mid'
                        : ($order <= $range['lowest'] ? 'low'
                            : ($order >= $range['highest'] ? 'high' : 'mid')),
                    'count' => 0,
                ];
                $bands[$key]->count++;
            }
            $reached = self::reached_standard($row, $policy, $bounds);
            if ($reached === null) {
                continue;
            }
            $judged++;
            $met += $reached ? 1 : 0;
        }
        usort($bands, static fn(\stdClass $a, \stdClass $b): int => $a->sortorder <=> $b->sortorder);
        return (object) [
            'learners' => count($rows),
            'graded' => $graded,
            'judged' => $judged,
            'met' => $met,
            'metpct' => $judged ? $met / $judged * 100 : null,
            'mean' => $graded ? $sum / $graded : null,
            'bands' => array_values($bands),
        ];
    }

    /**
     * Whether one learner's stored result reached the achievement standard.
     *
     * @param \stdClass $row Result row.
     * @param \stdClass $policy Resolved reporting policy.
     * @param array<int,array{lowest:int,highest:int}> $bounds Band range by policy ID.
     * @return bool|null Null when the result cannot be placed against any standard.
     */
    private static function reached_standard(\stdClass $row, \stdClass $policy, array $bounds): ?bool {
        if ($policy->available) {
            return suppression_service::meets_criterion(
                (string) $row->percentage,
                ['achievementminpercent' => $policy->criterionraw]
            );
        }
        if ($row->bandcode === null || !isset($bounds[(int) $row->policyid])) {
            return null;
        }
        return (int) $row->bandorder > $bounds[(int) $row->policyid]['lowest'];
    }

    /**
     * Classify one outcome for filtering and colour.
     *
     * @param \stdClass $node Scored node carrying its coverage.
     * @param \stdClass $policy Resolved reporting policy.
     * @return string One of course_attainment_service's STATE_* values.
     */
    private static function state_of(\stdClass $node, \stdClass $policy): string {
        $stats = $node->stats[self::COHORT_ALL];
        if (!$stats->graded) {
            // Without the mapping side of the pipeline the two collapse into
            // pending rather than misreporting either.
            return $node->assessedcontent === false
                ? course_attainment_service::STATE_UNASSESSED
                : course_attainment_service::STATE_PENDING;
        }
        if ($stats->metpct === null) {
            return course_attainment_service::STATE_PENDING;
        }
        $bar = $policy->available
            ? $policy->target
            : (1 - course_attainment_service::ATTENTION_SHARE) * 100;
        return $stats->metpct + 0.05 >= $bar
            ? course_attainment_service::STATE_ATTAINED
            : course_attainment_service::STATE_ATTENTION;
    }

    /**
     * Group nodes into levels and roll each level up.
     *
     * The rollup is a rate over learner-outcome results, not a mean of outcome
     * means, so an outcome graded for eight learners cannot move a level as hard
     * as one graded for sixty.
     *
     * @param array<int,\stdClass> $nodes Scored nodes.
     * @return \stdClass[] Levels, top first.
     */
    private static function build_tiers(array $nodes): array {
        $byheight = [];
        foreach ($nodes as $node) {
            $byheight[$node->height][] = $node;
        }
        ksort($byheight);
        $tiers = [];
        $index = 0;
        foreach ($byheight as $height => $members) {
            usort($members, static fn(\stdClass $a, \stdClass $b): int
                => [$a->frameworkcode, $a->code] <=> [$b->frameworkcode, $b->code]);
            $frameworks = [];
            $ownertypes = [];
            foreach ($members as $node) {
                $node->tier = $index;
                $frameworks[$node->frameworkcode] = $node->frameworkname;
                $ownertypes[$node->ownertype] = true;
            }
            $stats = [];
            foreach (self::COHORTS as $cohort) {
                $stats[$cohort] = self::roll_up($members, $cohort);
            }
            $tiers[] = (object) [
                'index' => $index,
                'height' => (int) $height,
                'frameworks' => $frameworks,
                'ownertypes' => array_keys($ownertypes),
                'nodes' => $members,
                'stats' => $stats,
                'measurable' => count(array_filter(
                    $members,
                    static fn(\stdClass $n): bool => (bool) $n->stats[self::COHORT_ALL]->graded
                )),
            ];
            $index++;
        }
        return $tiers;
    }

    /**
     * Pool a set of outcomes into one attainment rate.
     *
     * @param \stdClass[] $members Nodes to pool.
     * @param string $cohort Cohort key.
     * @return \stdClass Pooled counts and rate.
     */
    private static function roll_up(array $members, string $cohort): \stdClass {
        $graded = 0;
        $judged = 0;
        $met = 0;
        $weighted = 0.0;
        foreach ($members as $node) {
            $stats = $node->stats[$cohort];
            $graded += $stats->graded;
            $judged += $stats->judged;
            $met += $stats->met;
            $weighted += ($stats->mean ?? 0) * $stats->graded;
        }
        return (object) [
            'outcomes' => count($members),
            'measured' => count(array_filter(
                $members,
                static fn(\stdClass $n): bool => (bool) $n->stats[$cohort]->graded
            )),
            'graded' => $graded,
            'judged' => $judged,
            'met' => $met,
            'metpct' => $judged ? $met / $judged * 100 : null,
            'mean' => $graded ? $weighted / $graded : null,
        ];
    }

    /**
     * The unweighted mean of outcome means, kept only so the page can disown it.
     *
     * This is the figure the report used to headline. It is retained because the
     * honest thing to do with a number people have been quoting is to show what
     * it was and why it moved, not to change it silently.
     *
     * @param array<int,\stdClass> $nodes Scored nodes.
     * @param string $cohort Cohort key.
     * @return float|null Null when nothing is measured.
     */
    private static function unweighted_mean(array $nodes, string $cohort): ?float {
        $means = [];
        foreach ($nodes as $node) {
            if ($node->stats[$cohort]->mean !== null) {
                $means[] = $node->stats[$cohort]->mean;
            }
        }
        return $means ? array_sum($means) / count($means) : null;
    }

    /**
     * Record which outcomes have assessing content mapped, and what it is.
     *
     * Delegates to the coverage projection rather than re-deriving it: a report
     * that disagreed with the coverage page about what is assessed would send
     * readers to fix a gap that page says does not exist.
     *
     * @param int $courseid Moodle course ID.
     * @param \context_course $context Course context.
     * @param array<int,\stdClass> $nodes Nodes keyed by item ID.
     * @return void
     */
    private static function apply_coverage(int $courseid, \context_course $context, array $nodes): void {
        global $DB;
        // Splitting "no result yet" from "no result ever" needs the mapping side
        // of the pipeline, which is a different capability. Without it the two
        // stay collapsed rather than misreporting either.
        if (!has_capability('local/outcomemap:viewdefinitions', $context)) {
            return;
        }
        $matrix = coverage_service::matrix($courseid);
        if (!$matrix) {
            return;
        }
        $itemids = $DB->get_records_list(
            'local_outcomemap_itemver',
            'id',
            array_keys($matrix),
            '',
            'id, itemid'
        );
        $direct = [];
        $sources = [];
        $cms = get_fast_modinfo($courseid)->get_cms();
        $courseformat = course_get_format($courseid);
        foreach ($matrix as $itemverid => $row) {
            $itemid = isset($itemids[$itemverid]) ? (int) $itemids[$itemverid]->itemid : 0;
            if (!$itemid) {
                continue;
            }
            $status = coverage_service::row_status($row);
            $direct[$itemid] = ($direct[$itemid] ?? false)
                || $status === coverage_service::STATUS_FULL
                || $status === coverage_service::STATUS_ASSESSED_ONLY;
            foreach ($row->questions ?? [] as $mapping) {
                $sources[$itemid][] = (object) [
                    'name' => (string) $mapping->label,
                    'detail' => (int) $mapping->questioncount,
                ];
            }
            foreach ($row->modules as $mapping) {
                if ($mapping->role !== content_mapping_service::ROLE_ASSESSES) {
                    continue;
                }
                // The mapping carries the module type; a reader needs the name of
                // the activity they would actually open.
                $cmid = (int) $mapping->cmid;
                $sources[$itemid][] = (object) [
                    'name' => isset($cms[$cmid])
                        ? $cms[$cmid]->get_formatted_name()
                        : (string) $mapping->modulename,
                    'detail' => 0,
                ];
            }
            foreach ($row->sections as $mapping) {
                if ($mapping->role !== content_mapping_service::ROLE_ASSESSES) {
                    continue;
                }
                $sources[$itemid][] = (object) [
                    'name' => (string) $courseformat->get_section_name((int) $mapping->sectionnumber),
                    'detail' => 0,
                ];
            }
        }

        // Assessment is inherited upward. An outcome that nothing maps to
        // directly is not a coverage gap when the outcomes underneath it are
        // assessed — that is exactly how a program outcome is supposed to be
        // evidenced, and flagging it would send readers to fix a mapping that
        // should not exist.
        $resolved = [];
        $covered = static function (int $itemid, array $visiting)
                use (&$covered, $nodes, $direct, &$resolved): bool {
            if (isset($resolved[$itemid])) {
                return $resolved[$itemid];
            }
            if (isset($visiting[$itemid])) {
                return false;
            }
            $visiting[$itemid] = true;
            $found = $direct[$itemid] ?? false;
            foreach ($nodes[$itemid]->children ?? [] as $childid) {
                if ($found) {
                    break;
                }
                $found = isset($nodes[$childid]) && $covered($childid, $visiting);
            }
            return $resolved[$itemid] = $found;
        };
        foreach ($nodes as $itemid => $node) {
            $node->assessedcontent = $covered($itemid, []);
            $node->sources = $sources[$itemid] ?? [];
        }
    }

    /**
     * Collect the places where the report has nothing to say, and why.
     *
     * Absence is the finding this report exists to surface. An outcome nothing
     * assesses can never produce a figure, and an outcome resting on a handful of
     * learners is one score away from a different answer. Neither is a low score;
     * both are silence, and silence is what a reviewer asks about.
     *
     * @param array<int,\stdClass> $nodes Scored nodes.
     * @param \stdClass[] $tiers Levels, top first.
     * @param \stdClass $policy Resolved reporting policy.
     * @param string $cohort Cohort key.
     * @return \stdClass Unassessed outcomes, thin outcomes, and whole empty levels.
     */
    private static function build_gaps(array $nodes, array $tiers, \stdClass $policy,
            string $cohort): \stdClass {
        $unassessed = [];
        $thin = [];
        $leaves = 0;
        foreach ($nodes as $node) {
            $stats = $node->stats[$cohort];
            // Coverage is a property of the outcome, not of the cohort being
            // read, so it is judged against every stored result.
            $overall = $node->stats[self::COHORT_ALL];
            if (!$node->children) {
                $leaves++;
            }
            // An outcome that produced results was assessed by something, whatever
            // the current mappings say — a mapping retired after calculation is a
            // provenance question, not a coverage gap. Gaps are listed at the grain
            // a mapping is actually made at; a parent whose whole subtree is empty
            // is the same finding said once per level, and the hollow-claim group
            // below already names it where it matters.
            if (!$overall->graded && !$node->children && $node->assessedcontent === false) {
                $unassessed[] = $node;
            } else if ($policy->floor !== null && $stats->graded > 0
                    && $stats->graded < $policy->floor) {
                $thin[] = $node;
            }
        }
        // A parent whose evidence has run out underneath it is a more useful
        // finding than the individual outcomes, because it names the claim at risk.
        $hollow = [];
        foreach ($nodes as $node) {
            if (!$node->children) {
                continue;
            }
            $missing = array_values(array_filter(
                $node->children,
                static fn(int $childid): bool => isset($nodes[$childid])
                    && !$nodes[$childid]->stats[$cohort]->graded
            ));
            if (count($missing) >= 2 && count($missing) >= count($node->children) / 2) {
                $hollow[] = (object) ['node' => $node, 'missing' => $missing];
            }
        }
        usort($hollow, static fn(\stdClass $a, \stdClass $b): int
            => count($b->missing) <=> count($a->missing));
        return (object) [
            'unassessed' => $unassessed,
            'thin' => $thin,
            'hollow' => $hollow,
            'leaves' => $leaves,
            'total' => count($nodes),
            'tiers' => count($tiers),
        ];
    }

    /**
     * Rank what a reader should look at first.
     *
     * Ranked by how much a finding should change a decision, not by how low the
     * number is: a strong result on five learners is more actionable than a
     * middling one on sixty, and an outcome that fails to separate learners who
     * passed from learners who did not is not measuring anything at all.
     *
     * @param \stdClass $report Partially built report.
     * @return \stdClass[] Findings, most severe first.
     */
    private static function build_priorities(\stdClass $report): array {
        $policy = $report->policy;
        $cohort = $report->cohort;
        $found = [];
        $add = static function (string $key, \stdClass $node, float $severity, array $a) use (&$found): void {
            $found[] = (object) [
                'key' => $key,
                'code' => $node->code,
                'itemid' => $node->itemid,
                'severity' => $severity,
                'args' => (object) $a,
            ];
        };

        $splitready = $report->cohortrule !== null;
        foreach ($report->nodes as $node) {
            $all = $node->stats[$cohort];
            $done = $node->stats[self::COHORT_COMPLETED];
            $notdone = $node->stats[self::COHORT_NOTCOMPLETED];
            $istop = $node->tier === 0;

            if ($policy->target !== null && $all->metpct !== null
                    && $all->metpct + 0.05 < $policy->target) {
                $behind = count(array_filter(
                    $node->children,
                    static fn(int $id): bool => isset($report->nodes[$id])
                        && $report->nodes[$id]->stats[$cohort]->metpct !== null
                        && $report->nodes[$id]->stats[$cohort]->metpct + 0.05 < $policy->target
                ));
                $add('belowbenchmark', $node, ($istop ? 60 : 30) + ($policy->target - $all->metpct), [
                    'code' => $node->code,
                    'metpct' => self::pct($all->metpct),
                    'target' => self::pct($policy->target),
                    'met' => $all->met,
                    'judged' => $all->judged,
                    'children' => count($node->children),
                    'behind' => $behind,
                ]);
            }

            if ($policy->floor !== null && $all->graded > 0 && $all->graded < $policy->floor
                    && $all->metpct !== null) {
                $tier = $report->tiers[$node->tier]->stats[$cohort]->metpct;
                if ($tier !== null && $all->metpct > $tier) {
                    $add('thinflattering', $node, 40 + ($all->metpct - $tier) / 2, [
                        'code' => $node->code,
                        'metpct' => self::pct($all->metpct),
                        'graded' => $all->graded,
                        'floor' => $policy->floor,
                        'tier' => self::pct($tier),
                    ]);
                }
            }

            if ($splitready && $done->judged > 0 && $notdone->judged > 0) {
                $spread = $done->metpct - $notdone->metpct;
                if (abs($spread) < self::ALIKE_SPREAD) {
                    $add('nodiscrimination', $node, 35 + (self::ALIKE_SPREAD - abs($spread)), [
                        'code' => $node->code,
                        'completed' => self::pct($done->metpct),
                        'notcompleted' => self::pct($notdone->metpct),
                    ]);
                } else if ($spread > 0) {
                    $add('widestgap', $node, 10 + $spread / 4, [
                        'code' => $node->code,
                        'completed' => self::pct($done->metpct),
                        'notcompleted' => self::pct($notdone->metpct),
                        'spread' => self::pct($spread),
                    ]);
                }
            }

            if ($splitready && $policy->target !== null && $done->metpct !== null
                    && $done->judged > 0 && $done->metpct + 0.05 < $policy->target) {
                $add('completersshortfall', $node, 55 + ($policy->target - $done->metpct), [
                    'code' => $node->code,
                    'completed' => self::pct($done->metpct),
                    'target' => self::pct($policy->target),
                    'judged' => $done->judged,
                ]);
            }
        }

        foreach ($report->gaps->hollow as $hollow) {
            $add('hollowclaim', $hollow->node, 45 + count($hollow->missing), [
                'code' => $hollow->node->code,
                'missing' => count($hollow->missing),
                'children' => count($hollow->node->children),
                'codes' => implode(', ', array_map(
                    static fn(int $id): string => $report->nodes[$id]->code,
                    array_slice($hollow->missing, 0, 8)
                )),
            ]);
        }

        // One finding per outcome: the most severe thing true of it is the thing
        // worth acting on, and five restatements of one problem is not a list.
        $best = [];
        foreach ($found as $finding) {
            $existing = $best[$finding->itemid] ?? null;
            if ($existing === null || $finding->severity > $existing->severity) {
                $best[$finding->itemid] = $finding;
            }
        }
        $ranked = array_values($best);
        usort($ranked, static fn(\stdClass $a, \stdClass $b): int => $b->severity <=> $a->severity);

        // A course with a whole thinly-assessed unit produces the same finding
        // once per outcome, and five copies of one problem is not a list of five
        // problems. Each kind gets at most two places before the next kind is
        // offered one; anything still short is topped up in severity order.
        $shown = [];
        $counts = [];
        foreach ($ranked as $finding) {
            if (count($shown) >= self::MAX_PRIORITIES) {
                break;
            }
            if (($counts[$finding->key] ?? 0) >= self::MAX_PER_FINDING) {
                continue;
            }
            $counts[$finding->key] = ($counts[$finding->key] ?? 0) + 1;
            $shown[$finding->itemid . ':' . $finding->key] = $finding;
        }
        foreach ($ranked as $finding) {
            if (count($shown) >= self::MAX_PRIORITIES) {
                break;
            }
            $shown[$finding->itemid . ':' . $finding->key] = $finding;
        }
        return array_values($shown);
    }

    /**
     * Report the top level across every course in the program that claims it.
     *
     * A single course cannot evidence a program outcome on its own, so a course
     * page that showed only its own contribution would invite exactly the wrong
     * conclusion. Sibling courses are matched on this course's reporting periods,
     * because pooling periods pools different cohorts.
     *
     * @param \stdClass $report Partially built report.
     * @param int $at Effective timestamp.
     * @return \stdClass Column outcomes and one row per sibling course.
     */
    private static function build_rollup(\stdClass $report, int $at): \stdClass {
        global $DB;
        $absent = (object) ['available' => false, 'outcomes' => [], 'courses' => []];
        if ($report->program === null || $report->toptier === null) {
            return $absent;
        }
        // Only outcomes shared beyond this course can be compared across courses;
        // a course's own outcomes are not claimed by anybody else.
        $outcomes = array_values(array_filter(
            $report->toptier->nodes,
            static fn(\stdClass $n): bool => $n->ownertype !== framework_service::OWNER_COURSE
        ));
        if (!$outcomes) {
            return $absent;
        }

        [$periodsql, $params] = $DB->get_in_or_equal($report->periodcodes, SQL_PARAMS_NAMED, 'pc');
        $params += [
            'programid' => (int) $report->program->id,
            'pcstatus' => workflow::APPROVED,
            'cistatus' => workflow::APPROVED,
            'at1' => $at,
            'at2' => $at,
        ];
        $siblings = $DB->get_records_sql(
            "SELECT ci.id AS cinstid, cc.id AS catalogid, cc.code, cc.name,
                    ci.moodlecourseid, ci.periodcode
               FROM {local_outcomemap_progcourse} pc
               JOIN {local_outcomemap_course} cc ON cc.id = pc.courseid
               JOIN {local_outcomemap_cinst} ci ON ci.courseid = cc.id
              WHERE pc.programid = :programid
                AND pc.status = :pcstatus
                AND pc.effectivefrom <= :at1
                AND (pc.effectiveto IS NULL OR pc.effectiveto > :at2)
                AND ci.status = :cistatus
                AND ci.confirmed = 1
                AND ci.periodcode $periodsql
           ORDER BY cc.code, ci.periodcode",
            $params
        );
        if (!$siblings) {
            return $absent;
        }

        $itemids = array_map(static fn(\stdClass $n): int => $n->itemid, $outcomes);
        [$itemsql, $resultparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'it');
        [$cinstsql, $cinstparams] = $DB->get_in_or_equal(
            array_map(static fn(\stdClass $s): int => (int) $s->cinstid, $siblings),
            SQL_PARAMS_NAMED,
            'ci'
        );
        $resultparams += $cinstparams + ['coursescope' => calculation_service::SCOPE_COURSE];
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.cinstid, r.userid, r.percentage, r.policyid,
                    v.itemid, b.code AS bandcode, b.sortorder AS bandorder, b.name AS bandname
               FROM {local_outcomemap_result} r
               JOIN {local_outcomemap_itemver} v ON v.id = r.itemverid
          LEFT JOIN {local_outcomemap_band} b ON b.id = r.bandid
              WHERE r.cinstid $cinstsql
                AND v.itemid $itemsql
                AND r.supersededby IS NULL
                AND r.scopetype = :coursescope",
            $resultparams
        );
        $bounds = self::band_bounds($rows);

        $bycourse = [];
        foreach ($siblings as $sibling) {
            $key = (int) $sibling->catalogid;
            $bycourse[$key] ??= (object) [
                'catalogid' => $key,
                'code' => (string) $sibling->code,
                'name' => (string) $sibling->name,
                'moodlecourseids' => [],
                'cinstids' => [],
                'current' => false,
                'learners' => [],
                'cells' => [],
            ];
            $bycourse[$key]->cinstids[(int) $sibling->cinstid] = (int) $sibling->cinstid;
            $bycourse[$key]->moodlecourseids[(int) $sibling->moodlecourseid] = (int) $sibling->moodlecourseid;
            $bycourse[$key]->current = $bycourse[$key]->current
                || (int) $sibling->moodlecourseid === $report->courseid;
        }
        $cinstowner = [];
        foreach ($bycourse as $course) {
            foreach ($course->cinstids as $cinstid) {
                $cinstowner[$cinstid] = $course->catalogid;
            }
        }
        $buckets = [];
        foreach ($rows as $row) {
            $owner = $cinstowner[(int) $row->cinstid] ?? null;
            if ($owner === null) {
                continue;
            }
            $buckets[$owner][(int) $row->itemid][] = $row;
            $bycourse[$owner]->learners[(int) $row->userid] = true;
        }
        foreach ($bycourse as $course) {
            foreach ($outcomes as $outcome) {
                $course->cells[$outcome->itemid] = self::tally(
                    $buckets[$course->catalogid][$outcome->itemid] ?? [],
                    $report->policy,
                    $bounds
                );
            }
            $course->learners = count($course->learners);
        }
        return (object) [
            'available' => true,
            'outcomes' => $outcomes,
            'courses' => array_values($bycourse),
        ];
    }

    /**
     * Whether a figure must be withheld rather than shown with a caveat.
     *
     * Under the accreditation lens the suppression floor is enforced everywhere,
     * exactly as it would be in a submission, so a reader cannot read a number on
     * screen that the export would refuse to print.
     *
     * @param \stdClass $report Report model.
     * @param \stdClass $stats Tally for one outcome and cohort.
     * @return bool
     */
    public static function is_withheld(\stdClass $report, \stdClass $stats): bool {
        return $report->lens === self::LENS_ACCREDITATION
            && $report->policy->floor !== null
            && $stats->graded > 0
            && $stats->graded < $report->policy->floor;
    }

    /**
     * Round a percentage to one decimal place for display arguments.
     *
     * @param float|null $value Percentage.
     * @return string Formatted percentage, or an em dash.
     */
    public static function pct(?float $value): string {
        return $value === null ? '—' : number_format($value, 1);
    }
}
