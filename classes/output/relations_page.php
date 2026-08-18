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
 * Outcome relations page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\local\workflow;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * Build the alignment grid of governed outcome relations, and its CSV export.
 *
 * Alignments are read in the matrix view of the Outcomes and alignment page
 * rather than on a page of their own, so this supplies that view's data and the
 * export behind its Alignments CSV button. It no longer renders a template of
 * its own.
 */
final class relations_page implements renderable, templatable {
    /**
     * @var \stdClass[] All relation versions with endpoint details.
     */
    private array $relations;

    /**
     * @var \stdClass[] Latest version of each outcome, keyed by stable item id.
     */
    private array $outcomes = [];

    /**
     * Load relation and outcome read models once.
     */
    public function __construct() {
        $this->relations = relation_service::list_detailed();
        $latest = [];
        $currentapproved = [];
        $now = time();
        foreach (outcome_service::list_all() as $outcome) {
            $itemid = (int) $outcome->itemid;
            if (!isset($latest[$itemid])) {
                $latest[$itemid] = $outcome;
            }
            $iscurrentapproved = $outcome->versionstatus === workflow::APPROVED
                && (int) $outcome->effectivefrom <= $now
                && ($outcome->effectiveto === null || (int) $outcome->effectiveto > $now);
            if ($iscurrentapproved && !isset($currentapproved[$itemid])) {
                $currentapproved[$itemid] = $outcome;
            }
        }
        foreach ($latest as $itemid => $outcome) {
            $this->outcomes[$itemid] = $currentapproved[$itemid] ?? $outcome;
        }
    }

    /**
     * Return a safe CSS suffix for a workflow status.
     */
    private function status_class(string $status): string {
        return match ($status) {
            workflow::APPROVED => 'approved',
            workflow::NEEDS_REVIEW => 'review',
            workflow::DRAFT => 'draft',
            workflow::RETIRED => 'retired',
            default => 'neutral',
        };
    }

    /**
     * Return actions available for one relation version.
     */
    private function actions(\stdClass $relation, bool $canmanage, moodle_url $baseurl): array {
        if (!$canmanage) {
            return [];
        }
        if ($relation->status === workflow::DRAFT) {
            return [
                [
                    'label' => get_string('edit'),
                    'url' => (new moodle_url($baseurl, [
                        'action' => 'edit',
                        'id' => (int) $relation->id,
                    ]))->out(false),
                ],
                [
                    'label' => workflow::submit_action_label(),
                    'url' => (new moodle_url($baseurl, [
                        'action' => 'submit',
                        'id' => (int) $relation->id,
                        'sesskey' => sesskey(),
                    ]))->out(false),
                ],
            ];
        }
        if ($relation->status === workflow::APPROVED) {
            return [[
                'label' => get_string('relations_newversion_short', 'local_outcomemap'),
                'url' => (new moodle_url($baseurl, [
                    'action' => 'newversion',
                    'id' => (int) $relation->id,
                ]))->out(false),
            ]];
        }
        return [];
    }

    /**
     * Build one matrix cell for a source/target pair.
     */
    private function matrix_cell(
        ?\stdClass $relation,
        \stdClass $source,
        \stdClass $target,
        string $type,
        bool $canmanage,
        moodle_url $baseurl
    ): array {
        $sourcelabel = $source->frameworkcode . '.' . $source->code;
        $targetlabel = $target->frameworkcode . '.' . $target->code;
        if ($relation) {
            $actions = $this->actions($relation, $canmanage, $baseurl);
            return [
                'hasrelation' => true,
                'statusclass' => $this->status_class($relation->status),
                'title' => get_string('relations_matrix_existing', 'local_outcomemap', (object) [
                    'source' => $sourcelabel,
                    'target' => $targetlabel,
                    'status' => workflow::status_label($relation->status),
                    'version' => (int) $relation->version,
                ]),
                'hasaction' => $actions !== [],
                'actionurl' => $actions === [] ? '' : $actions[0]['url'],
            ];
        }
        return [
            'hasrelation' => false,
            'title' => get_string('relations_matrix_empty', 'local_outcomemap', (object) [
                'source' => $sourcelabel,
                'target' => $targetlabel,
            ]),
            'canadd' => $canmanage,
            'addurl' => $canmanage ? (new moodle_url($baseurl, [
                'action' => 'add',
                'sourceitemid' => (int) $source->itemid,
                'targetitemid' => (int) $target->itemid,
                'relationtype' => $type,
            ]))->out(false) : '',
        ];
    }

    /**
     * Export the template context.
     */
    public function export_for_template(renderer_base $output): array {
        $canmanage = has_capability('local/outcomemap:manageframeworks', \context_system::instance());
        $baseurl = new moodle_url('/local/outcomemap/relations.php');
        $rawgroups = [];
        foreach ($this->relations as $relation) {
            $key = implode(':', [
                (int) $relation->sourceframeworkid,
                (int) $relation->targetframeworkid,
                $relation->type,
            ]);
            if (!isset($rawgroups[$key])) {
                $rawgroups[$key] = [
                    'sourceframeworkid' => (int) $relation->sourceframeworkid,
                    'targetframeworkid' => (int) $relation->targetframeworkid,
                    'sourceframework' => $relation->sourceframework,
                    'sourceframeworkname' => $relation->sourceframeworkname,
                    'targetframework' => $relation->targetframework,
                    'targetframeworkname' => $relation->targetframeworkname,
                    'type' => $relation->type,
                    'relations' => [],
                ];
            }
            $rawgroups[$key]['relations'][] = $relation;
        }

        $outcomesbyframework = [];
        foreach ($this->outcomes as $outcome) {
            $outcomesbyframework[(int) $outcome->frameworkid][] = $outcome;
        }
        foreach ($outcomesbyframework as &$frameworkoutcomes) {
            usort($frameworkoutcomes, static fn($a, $b) => strnatcasecmp($a->code, $b->code));
        }
        unset($frameworkoutcomes);

        $groups = [];
        foreach ($rawgroups as $rawgroup) {
            // Only the newest version of each source-target pair occupies a cell,
            // so the grid is drawn from the latest rather than every version.
            $latestbypair = [];
            foreach ($rawgroup['relations'] as $relation) {
                $pairkey = (int) $relation->sourceitemid . ':' . (int) $relation->targetitemid;
                if (
                    !isset($latestbypair[$pairkey])
                        || (int) $relation->version > (int) $latestbypair[$pairkey]->version
                        || ((int) $relation->version === (int) $latestbypair[$pairkey]->version
                            && (int) $relation->timemodified > (int) $latestbypair[$pairkey]->timemodified)
                ) {
                    $latestbypair[$pairkey] = $relation;
                }
            }

            $columns = [];
            $targetoutcomes = $outcomesbyframework[$rawgroup['targetframeworkid']] ?? [];
            foreach ($targetoutcomes as $target) {
                $columns[] = [
                    'code' => $target->code,
                    'label' => $target->frameworkcode . '.' . $target->code,
                    'statement' => $target->statement,
                ];
            }
            $matrixrows = [];
            foreach ($outcomesbyframework[$rawgroup['sourceframeworkid']] ?? [] as $source) {
                $cells = [];
                $searchparts = [$source->frameworkcode, $source->code, $source->statement];
                foreach ($targetoutcomes as $target) {
                    $pairkey = (int) $source->itemid . ':' . (int) $target->itemid;
                    $relation = $latestbypair[$pairkey] ?? null;
                    $cells[] = $this->matrix_cell(
                        $relation,
                        $source,
                        $target,
                        $rawgroup['type'],
                        $canmanage,
                        $baseurl
                    );
                    if ($relation) {
                        $searchparts[] = $target->frameworkcode;
                        $searchparts[] = $target->code;
                        $searchparts[] = $target->statement;
                    }
                }
                $matrixrows[] = [
                    'code' => $source->frameworkcode . '.' . $source->code,
                    'statement' => $source->statement,
                    'searchtext' => \core_text::strtolower(implode(' ', $searchparts)),
                    'cells' => $cells,
                ];
            }
            $groupsearch = implode(' ', [
                $rawgroup['sourceframework'],
                $rawgroup['sourceframeworkname'],
                $rawgroup['targetframework'],
                $rawgroup['targetframeworkname'],
                get_string('relation_' . $rawgroup['type'], 'local_outcomemap'),
            ]);
            $groups[] = [
                'title' => get_string('relations_group_title', 'local_outcomemap', (object) [
                    'source' => $rawgroup['sourceframework'],
                    'target' => $rawgroup['targetframework'],
                ]),
                'subtitle' => get_string(
                    'relations_group_subtitle',
                    'local_outcomemap',
                    get_string('relation_' . $rawgroup['type'], 'local_outcomemap')
                ),
                'countline' => get_string(
                    count($rawgroup['relations']) === 1 ? 'relations_count_one' : 'relations_count',
                    'local_outcomemap',
                    count($rawgroup['relations'])
                ),
                'searchtext' => \core_text::strtolower($groupsearch),
                'columns' => $columns,
                'matrixrows' => $matrixrows,
                'hasmatrix' => $columns !== [] && $matrixrows !== [],
            ];
        }

        $latestbyuuid = [];
        $statuscounts = [workflow::DRAFT => 0, workflow::NEEDS_REVIEW => 0];
        foreach ($this->relations as $relation) {
            if (
                !isset($latestbyuuid[$relation->relationuuid])
                    || (int) $relation->version > (int) $latestbyuuid[$relation->relationuuid]->version
            ) {
                $latestbyuuid[$relation->relationuuid] = $relation;
            }
            if (isset($statuscounts[$relation->status])) {
                $statuscounts[$relation->status]++;
            }
        }
        $activecount = count(array_filter(
            $latestbyuuid,
            static fn($relation) => $relation->status !== workflow::RETIRED
        ));

        return [
            'canmanage' => $canmanage,
            'hasrelations' => $this->relations !== [],
            'groups' => $groups,
            'addurl' => (new moodle_url($baseurl, ['action' => 'add']))->out(false),
            'exporturl' => (new moodle_url($baseurl, ['action' => 'exportcsv']))->out(false),
            'statsline' => get_string(
                workflow::requires_independent_approval() ? 'relations_statsline' : 'relations_statsline_finalization',
                'local_outcomemap',
                (object) [
                'active' => $activecount,
                'versions' => count($this->relations),
                'review' => $statuscounts[workflow::NEEDS_REVIEW],
                'draft' => $statuscounts[workflow::DRAFT],
                ]
            ),
        ];
    }

    /**
     * Return rows for the relation CSV export.
     */
    public function csv_rows(): array {
        $rows = [[
            get_string('sourceoutcome', 'local_outcomemap'),
            get_string('relationtype', 'local_outcomemap'),
            get_string('targetoutcome', 'local_outcomemap'),
            get_string('weight', 'local_outcomemap'),
            get_string('version', 'local_outcomemap'),
            get_string('status', 'local_outcomemap'),
            get_string('effectivefrom', 'local_outcomemap'),
            get_string('effectiveto', 'local_outcomemap'),
        ]];
        foreach ($this->relations as $relation) {
            $rows[] = [
                $relation->sourceframework . '.' . $relation->sourcecode,
                get_string('relation_' . $relation->type, 'local_outcomemap'),
                $relation->targetframework . '.' . $relation->targetcode,
                $relation->weight ?? '',
                (int) $relation->version,
                get_string('status_' . $relation->status, 'local_outcomemap'),
                userdate((int) $relation->effectivefrom, get_string('strftimedatetimeshort', 'langconfig')),
                $relation->effectiveto === null ? ''
                    : userdate((int) $relation->effectiveto, get_string('strftimedatetimeshort', 'langconfig')),
            ];
        }
        return $rows;
    }
}
