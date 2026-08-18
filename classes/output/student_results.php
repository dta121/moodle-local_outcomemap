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
 * Student outcome-results template context.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\decimal;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\student_result_service;

/**
 * Turns the learner-safe report DTO into the learner-facing progress page.
 *
 * The page answers three questions in order: where do I stand, what should I
 * do next, and what does each figure actually mean. Every sentence it builds
 * is derived from the report — the achievement thresholds come from the
 * calculation policy's band ladder, the groupings from approved relations —
 * so nothing here asserts more than the data supports.
 */
final class student_results implements \renderable, \templatable {
    /**
     * Number of "start here" cards the page will show.
     */
    private const MAX_ACTIONS = 3;

    /**
     * Number of names listed before a group summary says "and more".
     */
    private const MAX_NAMES = 4;

    /**
     * Result states that mean a figure exists but the learner cannot see it yet.
     */
    private const STATES_AWAITING = [
        student_result_service::STATE_NOT_RELEASED,
        student_result_service::STATE_STALE,
        calculation_service::STATE_PENDING,
    ];

    /**
     * @var array Learner-safe report data.
     */
    private array $report;

    /**
     * @var string|null Percentage at which an outcome counts as demonstrated.
     */
    private ?string $expected;

    /**
     * @var string|null Percentage at which an outcome counts as exceeded.
     */
    private ?string $strong;

    /**
     *
     * @param array $report Learner-safe report data.
     */
    public function __construct(array $report) {
        $this->report = $report;
        $this->expected = $report['expectedpercent'] ?? null;
        // A ladder whose top boundary equals its pass mark cannot distinguish
        // strong work from adequate work, so the page stops claiming it can.
        $strong = $report['strongpercent'] ?? null;
        $this->strong = $strong !== null && $this->expected !== null
            && decimal::cmp($strong, $this->expected) > 0 ? $strong : null;
    }

    /**
     * Export the Mustache context.
     *
     * @param \renderer_base $output Renderer.
     * @return array Template context.
     */
    public function export_for_template(\renderer_base $output): array {
        $rows = $this->report['rows'] ?? [];
        $skills = $this->rows_of_tier($rows, student_result_service::TIER_COURSE);
        $units = $this->rows_of_tier($rows, student_result_service::TIER_UNIT);
        $programs = $this->rows_of_tier($rows, student_result_service::TIER_PROGRAM);

        if (!$rows) {
            return ['hasrows' => false];
        }

        $measured = array_filter($skills, fn(array $row): bool => $row['percentage'] !== null);
        $below = array_filter($measured, fn(array $row): bool => $this->tone($row) === 'below');
        $ontrack = array_filter($measured, fn(array $row): bool => $this->tone($row) !== 'below');
        $strong = array_filter($measured, fn(array $row): bool => $this->tone($row) === 'strong');
        $children = $this->children_by_parent($skills, $units);

        return [
            'hasrows' => true,
            'hasthresholds' => $this->expected !== null,
            'expected' => $this->percent($this->expected),
            'strong' => $this->percent($this->strong),
            'hero' => $this->hero($skills, $measured, $ontrack, $strong, $below),
            'standing' => $this->standing($skills, $measured, $ontrack, $strong, $below),
            'actions' => $this->actions($skills, $children, $programs, $below),
            'skills' => $this->skills($skills, $children),
            'filters' => $this->filters($skills, $measured, $ontrack, $strong, $below),
            'blanks' => $this->blanks($skills, $units, $children),
            'strengths' => $this->strengths($strong),
            'degree' => $this->degree($programs, $skills),
            'faq' => $this->faq(),
            'glossary' => $this->glossary(),
            'footnote' => $this->report['generatedat'] === null ? null : get_string(
                'sr_footnote',
                'local_outcomemap',
                userdate((int) $this->report['generatedat'])
            ),
            'privacynote' => get_string('outcomeresults_intro', 'local_outcomemap'),
        ];
    }

    /**
     * Build the opening statement of where the learner stands.
     *
     * @param array $skills Course-level rows.
     * @param array $measured Course-level rows carrying a percentage.
     * @param array $ontrack Measured rows at or above the pass mark.
     * @param array $strong Measured rows at or above the top boundary.
     * @param array $below Measured rows under the pass mark.
     * @return array Hero context.
     */
    private function hero(
        array $skills,
        array $measured,
        array $ontrack,
        array $strong,
        array $below
    ): array {
        $counts = (object) [
            'total' => count($skills),
            'measured' => count($measured),
            'ontrack' => count($ontrack),
            'strong' => count($strong),
            'below' => count($below),
            'unmeasured' => count($skills) - count($measured),
        ];
        if (!$measured) {
            return [
                'title' => get_string('sr_hero_title_none', 'local_outcomemap', $counts),
                'lede' => get_string('sr_hero_lede_none', 'local_outcomemap', $counts),
                'second' => get_string('sr_hero_blanks', 'local_outcomemap'),
            ];
        }
        if ($this->expected === null) {
            // With no band ladder there is no mark to be on track against, so
            // the page reports the figures without judging them.
            return [
                'title' => get_string('sr_hero_title_plain', 'local_outcomemap', $counts),
                'lede' => get_string('sr_hero_lede_plain', 'local_outcomemap', $counts),
                'second' => get_string('sr_hero_blanks', 'local_outcomemap'),
            ];
        }
        // Named apart from the counts above: strong is how many skills, while
        // strongpct is the boundary they cleared.
        $counts->expectedpct = $this->percent($this->expected);
        $counts->strongpct = $this->percent($this->strong);
        return [
            'title' => get_string(
                $counts->unmeasured > 0 ? 'sr_hero_title_partial' : 'sr_hero_title',
                'local_outcomemap',
                $counts
            ),
            'lede' => get_string($this->strong === null
                ? 'sr_hero_lede_nostrong' : 'sr_hero_lede', 'local_outcomemap', $counts),
            'second' => get_string('sr_hero_blanks', 'local_outcomemap'),
        ];
    }

    /**
     * Build the tally beside the hero.
     *
     * @param array $skills Course-level rows.
     * @param array $measured Course-level rows carrying a percentage.
     * @param array $ontrack Measured rows at or above the pass mark.
     * @param array $strong Measured rows at or above the top boundary.
     * @param array $below Measured rows under the pass mark.
     * @return array Standing context.
     */
    private function standing(
        array $skills,
        array $measured,
        array $ontrack,
        array $strong,
        array $below
    ): array {
        if ($this->expected === null || !$measured) {
            return ['has' => false, 'lines' => []];
        }
        $lines = [];
        if ($this->strong !== null) {
            $comfortable = array_filter($ontrack, fn(array $row): bool => $this->tone($row) !== 'strong');
            $lines[] = $this->standing_line(
                count($strong),
                'sr_standing_strong',
                'strong',
                $strong,
                $this->percent($this->strong)
            );
            $lines[] = $this->standing_line(
                count($comfortable),
                'sr_standing_ontrack',
                'ontrack',
                $comfortable,
                $this->percent($this->expected)
            );
        } else {
            $lines[] = $this->standing_line(
                count($ontrack),
                'sr_standing_ontrack',
                'ontrack',
                $ontrack,
                $this->percent($this->expected)
            );
        }
        $lines[] = $this->standing_line(
            count($below),
            'sr_standing_below',
            'below',
            $below,
            $this->percent($this->expected)
        );
        $unmeasured = array_filter($skills, fn(array $row): bool => $row['percentage'] === null);
        if ($unmeasured) {
            $lines[] = $this->standing_line(
                count($unmeasured),
                'sr_standing_unmeasured',
                'none',
                $unmeasured
            );
        }
        return [
            'has' => true,
            'title' => get_string('sr_standing_title', 'local_outcomemap', count($skills)),
            'lines' => $lines,
            'note' => get_string('sr_standing_note', 'local_outcomemap', $this->percent($this->expected)),
        ];
    }

    /**
     * Build one line of the standing tally.
     *
     * @param int $count Number of outcomes on this line.
     * @param string $labelkey Language string for the label.
     * @param string $tone Tone class suffix.
     * @param array $members Rows counted, used for the example list.
     * @param string|null $labelarg Argument for the label string.
     * @return array Line context.
     */
    private function standing_line(
        int $count,
        string $labelkey,
        string $tone,
        array $members,
        ?string $labelarg = null
    ): array {
        return [
            'count' => $count,
            'label' => get_string($labelkey, 'local_outcomemap', $labelarg),
            'tone' => $tone,
            'note' => $this->name_list($members),
        ];
    }

    /**
     * Build the prioritised "start here" cards.
     *
     * Two rules produce a card, in this order: a course skill under the pass
     * mark, worst first; and a skill that is itself fine but hides a unit
     * outcome under the mark, which an average conceals. Nothing is invented —
     * a card exists only where the data shows the pattern.
     *
     * @param array $skills Course-level rows.
     * @param array $children Unit rows keyed by parent item ID.
     * @param array $programs Programme-level rows.
     * @param array $below Measured rows under the pass mark.
     * @return array Actions context.
     */
    private function actions(array $skills, array $children, array $programs, array $below): array {
        // Deliberately not gated on the page-level ladder. When a report mixes
        // calculation policies that value is null, but each row still knows the
        // mark it was judged against — and a gap a learner can act on must not
        // disappear because some other row used a different ladder.
        $cards = [];
        uasort($below, fn(array $left, array $right): int
            => decimal::cmp($left['percentage'], $right['percentage']));
        foreach ($below as $row) {
            $cards[] = $this->action_card($row, $children, $this->sole_route_to($row, $programs));
        }
        foreach ($skills as $row) {
            if (count($cards) >= self::MAX_ACTIONS) {
                break;
            }
            if ($row['percentage'] === null || $this->tone($row) === 'below') {
                continue;
            }
            $weak = $this->weak_children($children[$row['itemid']] ?? []);
            if (!$weak) {
                continue;
            }
            $cards[] = $this->hidden_weakness_card($row, $weak);
        }
        $cards = array_slice($cards, 0, self::MAX_ACTIONS);
        foreach ($cards as $index => $card) {
            $cards[$index]['step'] = get_string('sr_actionstep', 'local_outcomemap', $index + 1);
        }
        return [
            'has' => (bool) $cards,
            'title' => get_string('sr_actions_title', 'local_outcomemap'),
            'intro' => get_string(
                count($cards) === 1 ? 'sr_actions_intro_one' : 'sr_actions_intro',
                'local_outcomemap',
                count($cards)
            ),
            'cards' => $cards,
        ];
    }

    /**
     * Build a card for one course skill under the pass mark.
     *
     * @param array $row Course-level row.
     * @param array $children Unit rows keyed by parent item ID.
     * @param array|null $programme Programme row this skill alone reaches, if any.
     * @return array Card context.
     */
    private function action_card(array $row, array $children, ?array $programme): array {
        $units = $children[$row['itemid']] ?? [];
        $weak = $this->weak_children($units);
        $unmeasured = array_filter($units, fn(array $unit): bool => $unit['percentage'] === null);
        $body = [get_string('sr_action_below', 'local_outcomemap', (object) [
            'score' => $this->score($row),
            'expected' => $this->percent($this->expected_for($row)),
        ])];
        if ($weak) {
            $body[] = get_string('sr_action_weakunits', 'local_outcomemap', (object) [
                'count' => count($weak),
                'names' => $this->name_list($weak),
            ]);
        } else if ($unmeasured && count($unmeasured) === count($units)) {
            $body[] = get_string('sr_action_nounits', 'local_outcomemap', count($units));
        } else if ($unmeasured) {
            $body[] = get_string('sr_action_someunits', 'local_outcomemap', (object) [
                'unmeasured' => count($unmeasured),
                'total' => count($units),
            ]);
        }
        if ($programme !== null) {
            $body[] = get_string('sr_action_soleroute', 'local_outcomemap', (object) [
                'name' => $programme['shortstatement'],
                'score' => $this->score($programme),
            ]);
        }
        return [
            'score' => $this->score($row),
            'title' => $row['shortstatement'],
            'code' => $row['code'],
            'body' => implode(' ', $body),
            'links' => $this->links($row),
        ];
    }

    /**
     * Build a card for a passing skill that hides a failing unit outcome.
     *
     * @param array $row Course-level row.
     * @param array $weak Unit rows under the pass mark.
     * @return array Card context.
     */
    private function hidden_weakness_card(array $row, array $weak): array {
        $worst = reset($weak);
        return [
            'score' => $this->score($row),
            'title' => get_string('sr_action_hidden_title', 'local_outcomemap', $row['shortstatement']),
            'code' => $row['code'],
            'body' => get_string('sr_action_hidden', 'local_outcomemap', (object) [
                'skill' => $row['shortstatement'],
                'skillscore' => $this->score($row),
                'unit' => $worst['shortstatement'],
                'unitscore' => $this->score($worst),
                'expected' => $this->percent($this->expected_for($worst)),
            ]),
            'links' => $this->links($row),
        ];
    }

    /**
     * Format the curated recommendations attached to one row.
     *
     * @param array $row Report row.
     * @return array Links context.
     */
    private function links(array $row): array {
        $links = [];
        foreach ($row['remediation'] as $recommendation) {
            $links[] = [
                'url' => $recommendation['url'],
                'title' => $recommendation['title'],
                'explanation' => $recommendation['explanation'],
                'designation' => get_string($recommendation['required']
                    ? 'remediation_required' : 'remediation_recommended', 'local_outcomemap'),
                'purpose' => get_string(
                    'remediationpurpose_' . $recommendation['purpose'],
                    'local_outcomemap'
                ),
            ];
        }
        return [
            'has' => (bool) $links,
            'label' => get_string('sr_action_links', 'local_outcomemap'),
            'items' => $links,
        ];
    }

    /**
     * Build the full list of course skills with their unit detail.
     *
     * @param array $skills Course-level rows.
     * @param array $children Unit rows keyed by parent item ID.
     * @return array Skills context.
     */
    private function skills(array $skills, array $children): array {
        $items = [];
        foreach ($skills as $row) {
            $units = $children[$row['itemid']] ?? [];
            $items[] = [
                'code' => $row['code'],
                'name' => $row['shortstatement'],
                'statement' => $this->statement_of($row),
                'score' => $this->score($row),
                'band' => $this->band($row),
                'bandfeedback' => $row['bandfeedback'],
                'tone' => $this->tone($row),
                'filter' => $this->filter_group($row),
                'scope' => $this->scope($row),
                'bar' => $this->bar($row),
                'hasunits' => (bool) $units,
                'unitstitle' => get_string(
                    count($units) === 1 ? 'sr_units_one' : 'sr_units',
                    'local_outcomemap',
                    count($units)
                ),
                // A unit row is rendered nowhere else, so its own curated
                // recommendations travel with it or they are lost — and those
                // are the most specific help the report can offer.
                'units' => array_map(fn(array $unit): array => [
                    'code' => $unit['code'],
                    'name' => $unit['shortstatement'],
                    'statement' => $this->statement_of($unit),
                    'status' => $this->unit_status($unit),
                    'tone' => $this->tone($unit),
                    'links' => $this->links($unit),
                ], array_values($units)),
                'explain' => $this->explain($row, $units),
                'evidence' => $this->evidence($row),
                // Curated recommendations stay on the outcome they belong to,
                // and outside the disclosure, so a required review item is
                // never hidden behind a click or behind a band ladder existing.
                'links' => $this->links($row),
            ];
        }
        return [
            'has' => (bool) $items,
            'title' => get_string('sr_skills_title', 'local_outcomemap'),
            'intro' => get_string($this->expected === null
                ? 'sr_skills_intro_plain'
                : 'sr_skills_intro', 'local_outcomemap', $this->percent($this->expected)),
            'items' => $items,
        ];
    }

    /**
     * Return the normative wording to show beneath a heading.
     *
     * Null when it would merely repeat the heading, which is the case for any
     * outcome that has no separate display label — there the statement already
     * *is* the heading, and printing it twice reads as a rendering fault.
     *
     * @param array $row Report row.
     * @return string|null Full statement, or null when it duplicates the label.
     */
    private function statement_of(array $row): ?string {
        $statement = $row['statement'] ?? null;
        if ($statement === null || trim($statement) === '') {
            return null;
        }
        return trim($statement) === trim((string) $row['shortstatement']) ? null : $statement;
    }

    /**
     * Describe how much graded work stands behind one figure.
     *
     * @param array $row Report row.
     * @return string|null Evidence summary, or null when there is none.
     */
    private function evidence(array $row): ?string {
        if ($row['distinctitems'] === null || (int) $row['distinctitems'] < 1) {
            return null;
        }
        return get_string(
            (int) $row['distinctitems'] === 1 ? 'sr_evidence_one' : 'sr_evidence',
            'local_outcomemap',
            (int) $row['distinctitems']
        );
    }

    /**
     * Explain what the unit outcomes underneath a skill do and do not cover.
     *
     * @param array $row Course-level row.
     * @param array $units Unit rows under this skill.
     * @return string Explanation sentence.
     */
    private function explain(array $row, array $units): string {
        if (!$units) {
            return get_string('sr_explain_direct', 'local_outcomemap');
        }
        $counts = (object) [
            'total' => count($units),
            'measured' => count(array_filter(
                $units,
                fn(array $unit): bool => $unit['percentage'] !== null
            )),
            'awaiting' => count(array_filter(
                $units,
                fn(array $unit): bool => in_array($unit['state'], self::STATES_AWAITING, true)
            )),
            'score' => $this->score($row),
        ];
        $counts->nowork = $counts->total - $counts->measured - $counts->awaiting;
        if ($counts->measured === 0) {
            return get_string('sr_explain_nonemeasured', 'local_outcomemap', $counts);
        }
        $sentence = get_string('sr_explain_measured', 'local_outcomemap', $counts);
        if ($counts->awaiting > 0) {
            $sentence .= ' ' . get_string('sr_explain_awaiting', 'local_outcomemap', $counts->awaiting);
        }
        if ($counts->nowork > 0) {
            $sentence .= ' ' . get_string('sr_explain_nowork', 'local_outcomemap', $counts->nowork);
        }
        return $sentence;
    }

    /**
     * Build the filter control over the skills list.
     *
     * @param array $skills Course-level rows.
     * @param array $measured Course-level rows carrying a percentage.
     * @param array $ontrack Measured rows at or above the pass mark.
     * @param array $strong Measured rows at or above the top boundary.
     * @param array $below Measured rows under the pass mark.
     * @return array Filters context.
     */
    private function filters(
        array $skills,
        array $measured,
        array $ontrack,
        array $strong,
        array $below
    ): array {
        // Same reasoning as actions(): the groups are tone-based, so they stay
        // meaningful under mixed policies. They are only meaningless when no
        // row anywhere has a mark to be measured against.
        if (!$measured || !$this->any_threshold($measured)) {
            return ['has' => false, 'options' => []];
        }
        $candidates = [
            ['all', count($skills)],
            ['below', count($below)],
            ['ontrack', count($ontrack) - count($strong)],
            ['strong', count($strong)],
            ['unmeasured', count($skills) - count($measured)],
        ];
        $options = [];
        foreach ($candidates as [$value, $count]) {
            if ($value !== 'all' && $count < 1) {
                continue;
            }
            $options[] = [
                'value' => $value,
                'id' => 'lom-sr-filter-' . $value,
                'label' => get_string('sr_filter_' . $value, 'local_outcomemap', $count),
                'checked' => $value === 'all',
            ];
        }
        return [
            'has' => count($options) > 1,
            'legend' => get_string('sr_filter_legend', 'local_outcomemap'),
            'options' => $options,
        ];
    }

    /**
     * Build the two explanations for rows with no figure.
     *
     * @param array $skills Course-level rows.
     * @param array $units Unit-level rows.
     * @param array $children Unit rows keyed by parent item ID.
     * @return array Blanks context.
     */
    private function blanks(array $skills, array $units, array $children): array {
        $parents = [];
        foreach ($children as $parentitemid => $group) {
            foreach ($group as $unit) {
                $parents[$unit['itemid']] = $parentitemid;
            }
        }
        $byparent = [];
        foreach ($skills as $row) {
            $byparent[$row['itemid']] = $row;
        }
        $groups = function (array $rows) use ($parents, $byparent): ?string {
            $names = [];
            foreach ($rows as $row) {
                $parentid = $parents[$row['itemid']] ?? null;
                $owner = $parentid === null ? $row : ($byparent[$parentid] ?? $row);
                $names[$owner['itemid']] = $owner;
            }
            return $this->name_list(array_values($names));
        };
        $candidates = array_merge(array_values($units), array_values($skills));
        $awaiting = array_filter(
            $candidates,
            fn(array $row): bool => in_array($row['state'], self::STATES_AWAITING, true)
        );
        $nowork = array_filter($candidates, fn(array $row): bool => in_array(
            $row['state'],
            [calculation_service::STATE_INSUFFICIENT, calculation_service::STATE_NOT_ASSESSED],
            true
        ));
        $cards = [];
        if ($nowork) {
            $cards[] = [
                'title' => get_string('sr_blank_nowork_title', 'local_outcomemap'),
                'count' => count($nowork),
                'body' => get_string('sr_blank_nowork', 'local_outcomemap'),
                'groups' => $groups($nowork),
                'next' => get_string('sr_blank_nowork_next', 'local_outcomemap'),
            ];
        }
        if ($awaiting) {
            $cards[] = [
                'title' => get_string('sr_blank_awaiting_title', 'local_outcomemap'),
                'count' => count($awaiting),
                'body' => get_string('sr_blank_awaiting', 'local_outcomemap'),
                'groups' => $groups($awaiting),
                'next' => get_string('sr_blank_awaiting_next', 'local_outcomemap'),
            ];
        }
        return [
            'has' => (bool) $cards,
            'title' => get_string('sr_blanks_title', 'local_outcomemap'),
            'intro' => get_string('sr_blanks_intro', 'local_outcomemap'),
            'cards' => $cards,
        ];
    }

    /**
     * Build the list of the learner's strongest skills.
     *
     * @param array $strong Measured rows at or above the top boundary.
     * @return array Strengths context.
     */
    private function strengths(array $strong): array {
        return [
            'has' => (bool) $strong,
            'title' => get_string('sr_strengths_title', 'local_outcomemap'),
            'intro' => get_string('sr_strengths_intro', 'local_outcomemap'),
            'items' => array_map(fn(array $row): array => [
                'name' => $row['shortstatement'],
                'code' => $row['code'],
                'score' => $this->score($row),
                'note' => $this->evidence($row),
            ], array_values($strong)),
        ];
    }

    /**
     * Build the programme-level roll-up.
     *
     * @param array $programs Programme-level rows.
     * @param array $skills Course-level rows.
     * @return array Degree context.
     */
    private function degree(array $programs, array $skills): array {
        $items = [];
        foreach ($programs as $row) {
            $contributors = $this->contributors_to($row, $skills);
            $items[] = [
                'code' => $row['code'],
                'name' => $row['shortstatement'],
                'statement' => $this->statement_of($row),
                'score' => $this->score($row),
                'band' => $this->band($row),
                'tone' => $this->tone($row),
                'bar' => $this->bar($row),
                'evidence' => $this->evidence($row),
                'reading' => $this->degree_reading($row, $contributors),
                'contributors' => $this->name_list($contributors),
            ];
        }
        return [
            'has' => (bool) $items,
            'title' => get_string('sr_degree_title', 'local_outcomemap'),
            'intro' => get_string('sr_degree_intro', 'local_outcomemap', count($items)),
            'items' => $items,
        ];
    }

    /**
     * Say what one programme figure means for the learner.
     *
     * @param array $row Programme-level row.
     * @param array $contributors Course-level rows feeding this programme outcome.
     * @return string Reading sentence.
     */
    private function degree_reading(array $row, array $contributors): string {
        if ($row['percentage'] === null) {
            return get_string('sr_degree_unmeasured', 'local_outcomemap');
        }
        if ($this->expected === null) {
            return get_string('sr_degree_plain', 'local_outcomemap', count($contributors));
        }
        $tone = $this->tone($row);
        if ($tone === 'below' && count($contributors) === 1) {
            return get_string('sr_degree_below_sole', 'local_outcomemap', reset($contributors)['shortstatement']);
        }
        return get_string('sr_degree_' . $tone, 'local_outcomemap', $this->percent($this->expected));
    }

    /**
     * Return the programme row this skill is the only reported route to.
     *
     * A skill worth acting on first is one where a gap cannot be offset
     * elsewhere. That is only true when the skill is the sole contributor this
     * report can see, and the programme outcome is itself under the mark.
     *
     * @param array $row Course-level row.
     * @param array $programs Programme-level rows.
     * @return array|null The programme row, or null when there is no such route.
     */
    private function sole_route_to(array $row, array $programs): ?array {
        foreach ($programs as $programme) {
            if ($programme['percentage'] === null || $this->tone($programme) !== 'below') {
                continue;
            }
            if (!in_array($programme['itemid'], $row['parentitemids'], true)) {
                continue;
            }
            $others = 0;
            foreach ($this->report['rows'] as $candidate) {
                if (
                    $candidate['tier'] === student_result_service::TIER_COURSE
                        && $candidate['itemid'] !== $row['itemid']
                        && in_array($programme['itemid'], $candidate['parentitemids'], true)
                ) {
                    $others++;
                }
            }
            if ($others === 0) {
                return $programme;
            }
        }
        return null;
    }

    /**
     * Return the course-level rows feeding one programme outcome.
     *
     * @param array $programme Programme-level row.
     * @param array $skills Course-level rows.
     * @return array Contributing rows.
     */
    private function contributors_to(array $programme, array $skills): array {
        return array_values(array_filter(
            $skills,
            fn(array $row): bool => in_array($programme['itemid'], $row['parentitemids'], true)
        ));
    }

    /**
     * Group unit rows under the course row they build towards.
     *
     * @param array $skills Course-level rows.
     * @param array $units Unit-level rows.
     * @return array<int, array> Unit rows keyed by parent item ID.
     */
    private function children_by_parent(array $skills, array $units): array {
        $known = array_column($skills, 'itemid');
        $children = [];
        foreach ($units as $unit) {
            foreach ($unit['parentitemids'] as $parentitemid) {
                if (in_array($parentitemid, $known, true)) {
                    $children[$parentitemid][] = $unit;
                }
            }
        }
        foreach ($children as $parentitemid => $group) {
            usort($group, fn(array $left, array $right): int
                => strnatcasecmp($left['code'], $right['code']));
            $children[$parentitemid] = $group;
        }
        return $children;
    }

    /**
     * Return unit rows under the pass mark, worst first.
     *
     * @param array $units Unit rows.
     * @return array Failing unit rows.
     */
    private function weak_children(array $units): array {
        $weak = array_filter($units, fn(array $unit): bool
            => $unit['percentage'] !== null && $this->tone($unit) === 'below');
        usort($weak, fn(array $left, array $right): int
            => decimal::cmp($left['percentage'], $right['percentage']));
        return $weak;
    }

    /**
     * Return the rows of one hierarchy tier, preserving report order.
     *
     * @param array $rows Report rows.
     * @param string $tier One of the student_result_service TIER_* constants.
     * @return array Matching rows.
     */
    private function rows_of_tier(array $rows, string $tier): array {
        return array_values(array_filter(
            $rows,
            fn(array $row): bool => ($row['tier'] ?? student_result_service::TIER_COURSE) === $tier
        ));
    }

    /**
     * Whether any of these rows was judged against a mark at all.
     *
     * @param array $rows Report rows.
     * @return bool True when at least one row has a threshold.
     */
    private function any_threshold(array $rows): bool {
        foreach ($rows as $row) {
            if ($this->expected_for($row) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the pass mark one row was actually judged against.
     *
     * A row's own threshold wins over the page-level one, which is null
     * whenever the report spans more than one calculation policy.
     *
     * @param array $row Report row.
     * @return string|null Canonical percentage, or null when there is no mark.
     */
    private function expected_for(array $row): ?string {
        return $row['expectedpercent'] ?? $this->expected;
    }

    /**
     * Classify a row against the band ladder.
     *
     * @param array $row Report row.
     * @return string One of strong, ontrack, below, or none.
     */
    private function tone(array $row): string {
        if ($row['percentage'] === null) {
            return 'none';
        }
        $expected = $this->expected_for($row);
        if ($expected === null) {
            return 'ontrack';
        }
        if (decimal::cmp($row['percentage'], $expected) < 0) {
            return 'below';
        }
        $strong = $row['strongpercent'] ?? $this->strong;
        if (
            $strong !== null && decimal::cmp($strong, $expected) > 0
                && decimal::cmp($row['percentage'], $strong) >= 0
        ) {
            return 'strong';
        }
        return 'ontrack';
    }

    /**
     * Format a row's percentage for display.
     *
     * @param array $row Report row.
     * @return string Localized percentage, or the not-available text.
     */
    private function score(array $row): string {
        if ($row['percentage'] === null) {
            return get_string('sr_noscore', 'local_outcomemap');
        }
        return get_string('resultpercentage', 'local_outcomemap', self::format_decimal(
            $row['percentage'],
            (int) $row['displayscale']
        ));
    }

    /**
     * Name the band or state a row sits in.
     *
     * @param array $row Report row.
     * @return string Band or state name.
     */
    private function band(array $row): string {
        if ($row['bandname'] !== null) {
            return $row['bandname'];
        }
        return get_string('resultstate_' . $row['state'], 'local_outcomemap');
    }

    /**
     * Describe what a row's figure was calculated over.
     *
     * @param array $row Report row.
     * @return string Scope description.
     */
    private function scope(array $row): string {
        $scope = $row['scopetype'] === calculation_service::SCOPE_ASSESSMENT
            ? get_string('resultscope_assessment_named', 'local_outcomemap', $row['scopename'] ?? '')
            : get_string('resultscope_course', 'local_outcomemap');
        return $row['periodcode'] === '' ? $scope : $scope . ' — ' . $row['periodcode'];
    }

    /**
     * Build the progress-bar geometry for one row.
     *
     * @param array $row Report row.
     * @return array|null Bar context, or null when there is nothing to plot.
     */
    private function bar(array $row): ?array {
        if ($row['percentage'] === null) {
            return null;
        }
        $expected = $this->expected_for($row);
        return [
            'fill' => $this->offset($row['percentage']),
            'hasmark' => $expected !== null,
            'mark' => $expected === null ? null : $this->offset($expected),
            'marklabel' => $expected === null ? null : get_string(
                'sr_bar_mark',
                'local_outcomemap',
                $this->percent($expected)
            ),
        ];
    }

    /**
     * Describe the state of one unit outcome in a sentence fragment.
     *
     * @param array $row Unit-level row.
     * @return string Status text.
     */
    private function unit_status(array $row): string {
        if ($row['percentage'] !== null) {
            // Judge a unit against its own result's ladder, matching tone().
            if ($this->expected_for($row) === null) {
                return $this->score($row);
            }
            return get_string(
                $this->tone($row) === 'below' ? 'sr_unit_below' : 'sr_unit_ontrack',
                'local_outcomemap',
                $this->score($row)
            );
        }
        if (in_array($row['state'], self::STATES_AWAITING, true)) {
            return get_string('sr_unit_awaiting', 'local_outcomemap');
        }
        return get_string('sr_unit_nowork', 'local_outcomemap');
    }

    /**
     * Return the filter group a skill belongs to.
     *
     * @param array $row Course-level row.
     * @return string Filter group value.
     */
    private function filter_group(array $row): string {
        $tone = $this->tone($row);
        return $tone === 'none' ? 'unmeasured' : $tone;
    }

    /**
     * Build the frequently-asked questions.
     *
     * @return array Question and answer pairs.
     */
    private function faq(): array {
        $keys = ['grade', 'changed', 'record', 'hundred', 'disagree'];
        $items = [];
        foreach ($keys as $key) {
            $items[] = [
                'question' => get_string('sr_faq_' . $key . '_q', 'local_outcomemap'),
                'answer' => get_string('sr_faq_' . $key . '_a', 'local_outcomemap'),
            ];
        }
        return [
            'title' => get_string('sr_faq_title', 'local_outcomemap'),
            'items' => $items,
        ];
    }

    /**
     * Build the plain-language glossary of the page's own vocabulary.
     *
     * @return array Glossary context.
     */
    private function glossary(): array {
        $items = [
            [
                'term' => get_string('sr_glossary_skill_term', 'local_outcomemap'),
                'definition' => get_string('sr_glossary_skill_def', 'local_outcomemap'),
            ],
            [
                'term' => get_string('sr_glossary_unit_term', 'local_outcomemap'),
                'definition' => get_string('sr_glossary_unit_def', 'local_outcomemap'),
            ],
        ];
        if ($this->expected !== null) {
            $items[] = [
                'term' => get_string(
                    'sr_glossary_mark_term',
                    'local_outcomemap',
                    $this->percent($this->expected)
                ),
                'definition' => get_string('sr_glossary_mark_def', 'local_outcomemap'),
            ];
        }
        if ($this->strong !== null) {
            $items[] = [
                'term' => get_string('sr_glossary_strong_term', 'local_outcomemap'),
                'definition' => get_string(
                    'sr_glossary_strong_def',
                    'local_outcomemap',
                    $this->percent($this->strong)
                ),
            ];
        }
        $items[] = [
            'term' => get_string('sr_glossary_degree_term', 'local_outcomemap'),
            'definition' => get_string('sr_glossary_degree_def', 'local_outcomemap'),
        ];
        return [
            'title' => get_string('sr_glossary_title', 'local_outcomemap'),
            'items' => $items,
        ];
    }

    /**
     * Join outcome names into a readable list, capped in length.
     *
     * @param array $rows Report rows.
     * @return string|null Name list, or null when there is nothing to list.
     */
    private function name_list(array $rows): ?string {
        $names = [];
        foreach ($rows as $row) {
            $names[] = $row['shortstatement'];
        }
        if (!$names) {
            return null;
        }
        $shown = array_slice($names, 0, self::MAX_NAMES);
        $list = implode(get_string('sr_listsep', 'local_outcomemap'), $shown);
        if (count($names) > count($shown)) {
            $list = get_string('sr_andmore', 'local_outcomemap', (object) [
                'names' => $list,
                'count' => count($names) - count($shown),
            ]);
        }
        return $list;
    }

    /**
     * Format a threshold percentage without trailing zeroes.
     *
     * @param string|null $value Canonical decimal, or null.
     * @return string|null Localized percentage text.
     */
    private function percent(?string $value): ?string {
        return $value === null ? null : self::format_decimal($value, decimal::SCALE, true);
    }

    /**
     * Convert a canonical percentage into a CSS-safe offset.
     *
     * This is presentation geometry only, so a float is appropriate here where
     * it would not be for a stored figure.
     *
     * @param string $value Canonical decimal percentage.
     * @return string Offset between 0 and 100, in percent.
     */
    private function offset(string $value): string {
        $offset = max(0.0, min(100.0, (float) $value));
        return rtrim(rtrim(number_format($offset, 2, '.', ''), '0'), '.') . '%';
    }

    /**
     * Format a canonical decimal without converting it to float.
     *
     * @param string $value Canonical decimal.
     * @param int $scale Number of displayed fractional digits.
     * @param bool $trimzeroes Trim trailing fractional zeroes.
     * @return string Localized decimal text.
     */
    private static function format_decimal(string $value, int $scale, bool $trimzeroes = false): string {
        $quantized = decimal::quantize($value, $scale);
        [$whole, $fraction] = explode('.', $quantized);
        $fraction = substr($fraction, 0, $scale);
        if ($trimzeroes) {
            $fraction = rtrim($fraction, '0');
        }
        if ($fraction === '') {
            return $whole;
        }
        return $whole . get_string('decsep', 'langconfig') . $fraction;
    }
}
