<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

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
    /** Association is finalized and inside its delivery window. */
    private const PHASE_ACTIVE = 'active';

    /** Association is finalized but its Moodle course has ended. */
    private const PHASE_ENDED = 'ended';

    /** Association is finalized and its Moodle course has not started. */
    private const PHASE_UPCOMING = 'upcoming';

    /** Association has not been confirmed and cannot govern anything yet. */
    private const PHASE_DRAFT = 'draft';

    /** Association is retired. */
    private const PHASE_RETIRED = 'retired';

    /** @var \stdClass[] Associations with their catalog and Moodle course facts. */
    private array $instances;

    /** @var string Catalog course code the page opens filtered to, if any. */
    private string $catalogcode;

    /** @var int Reference time for delivery-window comparisons. */
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

    /** Export the template context. */
    public function export_for_template(renderer_base $output): array {
        $context = \context_system::instance();
        $canmanage = has_capability('local/outcomemap:managecatalogcourses', $context);
        $baseurl = new moodle_url('/local/outcomemap/courseinstances.php');
        $counts = [self::PHASE_ACTIVE => 0, self::PHASE_DRAFT => 0];
        $groups = [];

        foreach ($this->instances as $instance) {
            $phase = $this->phase($instance);
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
            if ($phase === self::PHASE_ACTIVE) {
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
                'id' => self::PHASE_ACTIVE,
                'label' => get_string('instances_filter_active', 'local_outcomemap'),
                'count' => $counts[self::PHASE_ACTIVE],
            ],
            [
                'id' => self::PHASE_DRAFT,
                'label' => get_string('instances_filter_draft', 'local_outcomemap'),
                'count' => $counts[self::PHASE_DRAFT],
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
            'draftcount' => $counts[self::PHASE_DRAFT],
            'hasdrafts' => $counts[self::PHASE_DRAFT] > 0,
            'draftnotice' => get_string(
                $counts[self::PHASE_DRAFT] === 1 ? 'instances_draftnotice_one' : 'instances_draftnotice',
                'local_outcomemap',
                $counts[self::PHASE_DRAFT]
            ),
            'statsline' => get_string('instances_statsline', 'local_outcomemap', (object) [
                'total' => count($this->instances),
                'courses' => count($groups),
                'active' => $counts[self::PHASE_ACTIVE],
                'draft' => $counts[self::PHASE_DRAFT],
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
     * Classify an association by governance state and delivery window.
     *
     * @param \stdClass $instance Association with its Moodle course window.
     * @return string One of the PHASE_* constants.
     */
    private function phase(\stdClass $instance): string {
        if ($instance->status === workflow::RETIRED) {
            return self::PHASE_RETIRED;
        }
        if ($instance->status !== workflow::APPROVED || (int) $instance->confirmed !== 1) {
            return self::PHASE_DRAFT;
        }
        $end = (int) $instance->moodleenddate;
        $start = (int) $instance->moodlestartdate;
        if ($end > 0 && $end < $this->now) {
            return self::PHASE_ENDED;
        }
        if ($start > $this->now) {
            return self::PHASE_UPCOMING;
        }
        return self::PHASE_ACTIVE;
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
        $statuslabel = workflow::status_label($instance->status);
        $unconfirmed = $phase === self::PHASE_DRAFT;
        $row = [
            'periodcode' => $instance->periodcode,
            'window' => $this->window($instance),
            'moodlename' => $moodlename,
            'moodleurl' => (new moodle_url('/course/view.php', ['id' => $moodlecourseid]))->out(false),
            'hidden' => (int) $instance->moodlevisible === 0,
            'enrolledline' => $this->enrolled($instance),
            'statelabel' => $phase === self::PHASE_RETIRED
                ? $statuslabel
                : get_string('instances_state', 'local_outcomemap', (object) [
                    'status' => $statuslabel,
                    'phase' => get_string('instances_phase_' . $this->phaselabel($instance, $phase), 'local_outcomemap'),
                ]),
            'stateclass' => $unconfirmed
                ? ($instance->status === workflow::NEEDS_REVIEW ? 'review' : 'draft')
                : ($phase === self::PHASE_ACTIVE ? 'active' : ($phase === self::PHASE_RETIRED ? 'retired' : 'ended')),
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
        $row['phase'] = $unconfirmed ? self::PHASE_DRAFT : $phase;
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

    /**
     * Return the phase suffix shown beside the governance status.
     *
     * @param \stdClass $instance Association record.
     * @param string $phase Resolved lifecycle phase.
     * @return string Language string suffix.
     */
    private function phaselabel(\stdClass $instance, string $phase): string {
        if ($phase !== self::PHASE_DRAFT) {
            return $phase;
        }
        return $instance->status === workflow::NEEDS_REVIEW ? 'awaiting' : 'unconfirmed';
    }

    /**
     * Describe the delivery window of the Moodle course shell.
     *
     * @param \stdClass $instance Association with its Moodle course window.
     * @return string Human-readable window.
     */
    private function window(\stdClass $instance): string {
        $start = (int) $instance->moodlestartdate;
        $end = (int) $instance->moodleenddate;
        $format = get_string('strftimedate', 'core_langconfig');
        if ($start > 0 && $end > 0) {
            return get_string('instances_window', 'local_outcomemap', (object) [
                'from' => userdate($start, $format),
                'to' => userdate($end, $format),
            ]);
        }
        if ($start > 0) {
            return get_string('instances_window_open', 'local_outcomemap', userdate($start, $format));
        }
        if ($end > 0) {
            return get_string('instances_window_until', 'local_outcomemap', userdate($end, $format));
        }
        return get_string('instances_window_none', 'local_outcomemap');
    }

    /**
     * Describe how many learners hold an active enrolment in the Moodle shell.
     *
     * @param \stdClass $instance Association with its enrolment count.
     * @return string Human-readable enrolment line.
     */
    private function enrolled(\stdClass $instance): string {
        $count = (int) $instance->enrolledcount;
        if ($count === 0) {
            return get_string('instances_enrolled_none', 'local_outcomemap');
        }
        return get_string($count === 1 ? 'instances_enrolled_one' : 'instances_enrolled', 'local_outcomemap', $count);
    }
}
