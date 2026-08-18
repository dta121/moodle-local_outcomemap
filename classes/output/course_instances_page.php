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
 * Course instances page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\workflow;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * Build the searchable, catalog-grouped course-instance administration page.
 *
 * An association is read in two registers at once: its governance state, which
 * decides whether it may govern mappings and results, and the delivery window of
 * the Moodle shell behind it, which decides whether anyone is learning in it
 * right now. The page shows both in one badge so neither has to be inferred.
 */
final class course_instances_page implements renderable, templatable {
    /**
     * @var \stdClass[] Associations with their catalog and Moodle course facts.
     */
    private array $instances;

    /**
     * @var string Catalog course code the page opens filtered to, if any.
     */
    private string $catalogcode;

    /**
     * @var int Reference time for delivery-window comparisons.
     */
    private int $now;

    /**
     * Load every association once.
     *
     * @param string $catalogcode Optional catalog course code to prefill the search with.
     * @param int|null $now Reference time, for deterministic tests.
     */
    public function __construct(string $catalogcode = '', ?int $now = null) {
        $this->instances = course_instance_service::list_with_summary();
        $this->catalogcode = $catalogcode;
        $this->now = $now ?? time();
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output Output.
     */
    public function export_for_template(renderer_base $output): array {
        $context = \context_system::instance();
        $canmanage = has_capability('local/outcomemap:managecatalogcourses', $context);
        $baseurl = new moodle_url('/local/outcomemap/courseinstances.php');
        $counts = [instance_state::PHASE_ACTIVE => 0, instance_state::PHASE_DRAFT => 0];
        $groups = [];

        foreach ($this->instances as $instance) {
            $phase = instance_state::phase($instance, $this->now);
            if (isset($counts[$phase])) {
                $counts[$phase]++;
            }
            $catalogid = (int) $instance->courseid;
            if (!isset($groups[$catalogid])) {
                $groups[$catalogid] = [
                    'code' => $instance->catalogcode,
                    'name' => format_string($instance->catalogname),
                    'rows' => [],
                    'activecount' => 0,
                ];
            }
            $groups[$catalogid]['rows'][] = $this->row($instance, $phase, $baseurl, $canmanage);
            if ($phase === instance_state::PHASE_ACTIVE) {
                $groups[$catalogid]['activecount']++;
            }
        }

        foreach ($groups as $catalogid => $group) {
            $total = count($group['rows']);
            $groups[$catalogid]['countline'] = get_string(
                $total === 1 ? 'instances_count_one' : 'instances_count',
                'local_outcomemap',
                (object) ['total' => $total, 'active' => $group['activecount']]
            );
        }

        $phases = [
            ['id' => 'all', 'label' => get_string('all', 'local_outcomemap'), 'count' => count($this->instances)],
            [
                'id' => instance_state::PHASE_ACTIVE,
                'label' => get_string('instances_filter_active', 'local_outcomemap'),
                'count' => $counts[instance_state::PHASE_ACTIVE],
            ],
            [
                'id' => instance_state::PHASE_DRAFT,
                'label' => get_string('instances_filter_draft', 'local_outcomemap'),
                'count' => $counts[instance_state::PHASE_DRAFT],
            ],
        ];
        $filters = [];
        foreach ($phases as $index => $phase) {
            $filters[] = $phase + ['active' => $index === 0];
        }

        return [
            'canmanage' => $canmanage,
            'addurl' => (new moodle_url($baseurl, ['action' => 'add']))->out(false),
            'searchprefill' => $this->catalogcode,
            'filters' => $filters,
            'groups' => array_values($groups),
            'hasinstances' => $this->instances !== [],
            'draftcount' => $counts[instance_state::PHASE_DRAFT],
            'hasdrafts' => $counts[instance_state::PHASE_DRAFT] > 0,
            'draftnotice' => get_string(
                $counts[instance_state::PHASE_DRAFT] === 1 ? 'instances_draftnotice_one' : 'instances_draftnotice',
                'local_outcomemap',
                $counts[instance_state::PHASE_DRAFT]
            ),
            'statsline' => get_string('instances_statsline', 'local_outcomemap', (object) [
                'total' => count($this->instances),
                'courses' => count($groups),
                'active' => $counts[instance_state::PHASE_ACTIVE],
                'draft' => $counts[instance_state::PHASE_DRAFT],
            ]),
            'introline' => get_string(
                workflow::requires_independent_approval()
                    ? 'courseinstances_intro'
                    : 'courseinstances_intro_finalization',
                'local_outcomemap'
            ),
            'visibilityline' => get_string('courseinstances_coursevisibility', 'local_outcomemap'),
        ];
    }

    /**
     * Build one association row.
     *
     * @param \stdClass $instance Association record.
     * @param string $phase Resolved lifecycle phase.
     * @param moodle_url $baseurl Course instances page URL.
     * @param bool $canmanage Whether the reader may act on associations.
     * @return array Template row context.
     */
    private function row(\stdClass $instance, string $phase, moodle_url $baseurl, bool $canmanage): array {
        $id = (int) $instance->id;
        $moodlecourseid = (int) $instance->moodlecourseid;
        $moodlename = format_string($instance->moodlename);
        $unconfirmed = $phase === instance_state::PHASE_DRAFT;
        $row = [
            'periodcode' => $instance->periodcode,
            'window' => instance_state::window($instance),
            'moodlename' => $moodlename,
            'moodleurl' => (new moodle_url('/course/view.php', ['id' => $moodlecourseid]))->out(false),
            'hidden' => (int) $instance->moodlevisible === 0,
            'enrolledline' => instance_state::enrolled($instance),
            'statelabel' => instance_state::label($instance, $phase),
            'stateclass' => instance_state::cssclass($instance, $phase),
            'externalid' => $instance->externalid,
            'cansubmit' => false,
            'candelete' => false,
            'blockers' => '',
        ];
        $row['searchtext'] = \core_text::strtolower(implode(' ', [
            $instance->catalogcode,
            $instance->catalogname,
            $instance->periodcode,
            $moodlename,
            $instance->moodleshortname,
            $instance->externalid ?? '',
            $row['statelabel'],
        ]));
        $row['phase'] = $unconfirmed ? instance_state::PHASE_DRAFT : $phase;
        if (!$unconfirmed) {
            $row['coverageurl'] = (new moodle_url('/local/outcomemap/coverage.php', [
                'courseid' => $moodlecourseid,
            ]))->out(false);
            $row['mappingurl'] = (new moodle_url('/local/outcomemap/contentmapping.php', [
                'courseid' => $moodlecourseid,
            ]))->out(false);
        }
        if (!$canmanage) {
            return $row;
        }
        if ($instance->status === workflow::DRAFT) {
            $row['cansubmit'] = true;
            $row['submitlabel'] = workflow::submit_action_label();
            $row['submiturl'] = (new moodle_url($baseurl, [
                'action' => 'submit',
                'id' => $id,
                'sesskey' => sesskey(),
            ]))->out(false);
        }
        // Removal is offered only while nothing depends on the association, and
        // the reason is shown when it does so the absence of the link is not a
        // mystery.
        $blockers = course_instance_service::deletion_blockers($id);
        if ($blockers === []) {
            $row['candelete'] = true;
            $row['deleteurl'] = (new moodle_url($baseurl, [
                'action' => 'delete',
                'id' => $id,
                'sesskey' => sesskey(),
            ]))->out(false);
        } else {
            $row['blockers'] = get_string('courseinstancenotremovable', 'local_outcomemap', implode(' ', $blockers));
        }
        return $row;
    }
}
