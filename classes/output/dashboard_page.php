<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Outcome mapping dashboard page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\service\coverage_service;
use local_outcomemap\local\service\dashboard_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\workflow;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * Lead the dashboard with what blocks accreditation reporting.
 *
 * A count of governed records says nothing about whether the site can report.
 * The gaps come first, then the work queue that closes them ordered by what
 * blocks reporting soonest, then per-program readiness, and only then the record
 * totals — demoted to an inventory list, because that is reference material
 * rather than a finding. Every figure links to the page that resolves it, so the
 * dashboard is a starting point rather than a report.
 */
final class dashboard_page implements renderable, templatable {
    /** @var array Readiness signals from the service. */
    private array $summary;

    /** Load every dashboard figure once. */
    public function __construct() {
        $this->summary = dashboard_service::summary();
    }

    /** Export the template context. */
    public function export_for_template(renderer_base $output): array {
        $tasks = $this->tasks();
        return [
            'tiles' => $this->tiles(),
            'tasks' => $tasks,
            'allclear' => $tasks === [],
            'programs' => $this->programs(),
            'hasprograms' => $this->summary['programs'] !== [],
            'activity' => $this->activity(),
            'hasactivity' => $this->summary['activity'] !== [],
            'inventory' => $this->inventory(),
        ];
    }

    /**
     * Build the gap tiles.
     *
     * A tile reads as resolved rather than empty when its count is zero, so a
     * clean site looks clean instead of looking unpopulated.
     *
     * @return array[]
     */
    private function tiles(): array {
        $unframed = $this->summary['unframedcourses'];
        $coveragegaps = $this->summary['nocontent'] + $this->summary['taughtnotassessed'];
        $codes = array_map(static fn(\stdClass $course): string => $course->code, $unframed);

        return [
            [
                'label' => get_string('dash_tile_unaligned', 'local_outcomemap'),
                'value' => $this->summary['unaligned'],
                'unit' => get_string('dash_tile_unaligned_unit', 'local_outcomemap'),
                'note' => get_string('dash_tile_unaligned_note', 'local_outcomemap'),
                'tone' => $this->summary['unaligned'] > 0 ? 'danger' : 'clear',
                'url' => (new moodle_url('/local/outcomemap/frameworks.php', ['view' => 'matrix']))->out(false),
            ],
            [
                'label' => get_string('dash_tile_coverage', 'local_outcomemap'),
                'value' => $coveragegaps,
                'unit' => get_string('dash_tile_coverage_unit', 'local_outcomemap'),
                'note' => get_string('dash_tile_coverage_note', 'local_outcomemap', (object) [
                    'nocontent' => $this->summary['nocontent'],
                    'notassessed' => $this->summary['taughtnotassessed'],
                ]),
                'tone' => $coveragegaps > 0 ? 'warn' : 'clear',
                'url' => $this->coverage_url(),
            ],
            [
                'label' => get_string('dash_tile_unframed', 'local_outcomemap'),
                'value' => count($unframed),
                'unit' => get_string('dash_tile_unframed_unit', 'local_outcomemap'),
                'note' => $codes === []
                    ? get_string('dash_tile_unframed_clear', 'local_outcomemap')
                    : implode(', ', $codes),
                'tone' => $unframed === [] ? 'clear' : 'warn',
                'url' => (new moodle_url('/local/outcomemap/curriculum.php'))->out(false),
            ],
            [
                'label' => get_string('dash_tile_pending', 'local_outcomemap'),
                'value' => $this->summary['pendingapproval'],
                'unit' => get_string('dash_tile_pending_unit', 'local_outcomemap'),
                'note' => get_string('dash_tile_pending_note', 'local_outcomemap'),
                'tone' => $this->summary['pendingapproval'] > 0 ? 'info' : 'clear',
                'url' => $this->approval_url(),
            ],
        ];
    }

    /**
     * Build the work queue, most blocking first.
     *
     * Ordering is fixed rather than sorted by count: an outcome that rolls up
     * nowhere stops reporting outright, while an unassessed outcome only limits
     * what can be measured. A larger count of the lesser problem does not make
     * it the more urgent one.
     *
     * @return array[]
     */
    private function tasks(): array {
        $tasks = [];
        $unaligned = (int) $this->summary['unaligned'];
        if ($unaligned > 0) {
            $tasks[] = [
                'title' => get_string($unaligned === 1 ? 'dash_task_unaligned_one' : 'dash_task_unaligned',
                    'local_outcomemap', $unaligned),
                'detail' => get_string('dash_task_unaligned_detail', 'local_outcomemap'),
                'severity' => get_string('dash_severity_blocks', 'local_outcomemap'),
                'tone' => 'danger',
                'action' => get_string('dash_task_unaligned_action', 'local_outcomemap'),
                'url' => (new moodle_url('/local/outcomemap/frameworks.php', ['view' => 'matrix']))->out(false),
            ];
        }

        $unframed = $this->summary['unframedcourses'];
        if ($unframed !== []) {
            $first = reset($unframed);
            $tasks[] = [
                'title' => get_string('dash_task_unframed', 'local_outcomemap', (object) [
                    'code' => $first->code,
                    'name' => format_string($first->name),
                ]),
                'detail' => get_string(
                    count($unframed) === 1 ? 'dash_task_unframed_detail_one' : 'dash_task_unframed_detail',
                    'local_outcomemap',
                    count($unframed) - 1
                ),
                'severity' => get_string('dash_severity_blocks', 'local_outcomemap'),
                'tone' => 'danger',
                'action' => get_string('dash_task_unframed_action', 'local_outcomemap'),
                'url' => (new moodle_url('/local/outcomemap/curriculum.php'))->out(false),
            ];
        }

        $worst = $this->summary['worstdelivery'];
        if ($worst !== null && $worst['nocontent'] > 0) {
            $tasks[] = [
                'title' => get_string(
                    $worst['nocontent'] === 1 ? 'dash_task_nocontent_one' : 'dash_task_nocontent',
                    'local_outcomemap',
                    (object) ['count' => $worst['nocontent'], 'code' => $worst['coursecode']]
                ),
                'detail' => get_string('dash_task_nocontent_detail', 'local_outcomemap'),
                'severity' => get_string('dash_severity_needswork', 'local_outcomemap'),
                'tone' => 'warn',
                'action' => get_string('dash_task_nocontent_action', 'local_outcomemap'),
                'url' => (new moodle_url('/local/outcomemap/contentmapping.php', [
                    'courseid' => $worst['moodlecourseid'],
                ]))->out(false),
            ];
        }
        if ($worst !== null && $worst['notassessed'] > 0) {
            $tasks[] = [
                'title' => get_string(
                    $worst['notassessed'] === 1 ? 'dash_task_notassessed_one' : 'dash_task_notassessed',
                    'local_outcomemap',
                    (object) ['count' => $worst['notassessed'], 'code' => $worst['coursecode']]
                ),
                'detail' => get_string('dash_task_notassessed_detail', 'local_outcomemap'),
                'severity' => get_string('dash_severity_needswork', 'local_outcomemap'),
                'tone' => 'warn',
                'action' => get_string('dash_task_notassessed_action', 'local_outcomemap'),
                // The coverage page's "taught" filter is exactly this finding.
                'url' => (new moodle_url('/local/outcomemap/coverage.php', [
                    'courseid' => $worst['moodlecourseid'],
                    'filter' => coverage_service::STATUS_TAUGHT,
                ]))->out(false),
            ];
        }

        $pending = (int) $this->summary['pendingapproval'];
        if ($pending > 0) {
            $tasks[] = [
                'title' => get_string($pending === 1 ? 'dash_task_pending_one' : 'dash_task_pending',
                    'local_outcomemap', $pending),
                'detail' => get_string('dash_task_pending_detail', 'local_outcomemap'),
                'severity' => get_string('dash_severity_review', 'local_outcomemap'),
                'tone' => 'info',
                'action' => workflow::requires_independent_approval()
                    ? get_string('dash_task_pending_action_approve', 'local_outcomemap')
                    : get_string('dash_task_pending_action_finalize', 'local_outcomemap'),
                'url' => $this->approval_url(),
            ];
        }
        return $tasks;
    }

    /**
     * Build the program readiness rows.
     *
     * @return array[]
     */
    private function programs(): array {
        $typeclasses = [
            program_service::TYPE_GRADUATE => 'graduate',
            program_service::TYPE_UNDERGRADUATE => 'undergraduate',
            program_service::TYPE_SPECIALIZATION => 'specialization',
        ];
        $rows = [];
        foreach ($this->summary['programs'] as $program) {
            $rows[] = [
                'code' => $program['code'],
                'name' => format_string($program['name']),
                'typeclass' => $typeclasses[$program['programtype']] ?? 'graduate',
                'meta' => $program['outcomecount'] === 0
                    ? get_string('dash_program_noframework', 'local_outcomemap', (object) [
                        'courses' => $program['coursecount'],
                    ])
                    : get_string('dash_program_meta', 'local_outcomemap', (object) [
                        'outcomes' => $program['outcomecount'],
                        'courses' => $program['coursecount'],
                    ]),
                'percent' => $program['percent'],
                'percentlabel' => $program['percent'] . '%',
                // Inline width is the only way to express a data-driven bar
                // length; every other visual property stays in the stylesheet.
                'barstyle' => 'width: ' . $program['percent'] . '%;',
                'bartone' => $this->bar_tone($program['percent']),
                'state' => get_string('dash_ready_' . $program['state'], 'local_outcomemap'),
                'statetone' => $program['state'],
                'scopeline' => $program['inscope'] === 0
                    ? get_string('dash_program_noscope', 'local_outcomemap')
                    : get_string('dash_program_scope', 'local_outcomemap', (object) [
                        'complete' => $program['complete'],
                        'inscope' => $program['inscope'],
                    ]),
            ];
        }
        return $rows;
    }

    /**
     * Bar colour band for a readiness percentage.
     *
     * @param int $percent Readiness percentage.
     * @return string
     */
    private function bar_tone(int $percent): string {
        if ($percent >= dashboard_service::READY_THRESHOLD) {
            return 'ready';
        }
        return $percent >= 50 ? 'partial' : 'low';
    }

    /**
     * Summarise recent governance changes in readable language.
     *
     * @return array[]
     */
    private function activity(): array {
        $rows = [];
        foreach ($this->summary['activity'] as $event) {
            $single = $event['count'] === 1;
            $objectkey = 'dash_object_' . $event['objecttype'] . ($single ? '' : '_many');
            $actionkey = 'dash_action_' . $event['action'];
            $rows[] = [
                'when' => userdate($event['timecreated'], get_string('strftimedatefullshort', 'core_langconfig')),
                'text' => get_string($single ? 'dash_activity_line_one' : 'dash_activity_line',
                    'local_outcomemap', (object) [
                        'count' => number_format($event['count']),
                        // An audit row records an object type and action this
                        // release may not have a phrase for yet, so the raw
                        // recorded value is shown rather than nothing at all.
                        'object' => self::phrase($objectkey, $event['objecttype']),
                        'action' => self::phrase($actionkey, $event['action']),
                    ]),
            ];
        }
        return $rows;
    }

    /**
     * Translate an audit vocabulary term, falling back to the recorded value.
     *
     * @param string $key Language string identifier.
     * @param string $recorded Value stored in the audit row.
     * @return string
     */
    private static function phrase(string $key, string $recorded): string {
        if (get_string_manager()->string_exists($key, 'local_outcomemap')) {
            return get_string($key, 'local_outcomemap');
        }
        return str_replace('_', ' ', $recorded);
    }

    /**
     * Build the inventory list with its navigation targets.
     *
     * @return array[]
     */
    private function inventory(): array {
        $targets = [
            'programs' => '/local/outcomemap/curriculum.php',
            'catalogcourses' => '/local/outcomemap/curriculum.php',
            'courseinstances' => '/local/outcomemap/curriculum.php',
            'frameworks' => '/local/outcomemap/frameworks.php',
            'outcomes' => '/local/outcomemap/frameworks.php',
            'relations' => '/local/outcomemap/frameworks.php?view=matrix',
        ];
        $rows = [];
        foreach ($this->summary['inventory'] as $type => $count) {
            $rows[] = [
                'label' => get_string('dash_inventory_' . $type, 'local_outcomemap'),
                'count' => number_format($count),
                'url' => (new moodle_url($targets[$type]))->out(false),
            ];
        }
        return $rows;
    }

    /**
     * Where coverage work starts.
     *
     * Coverage is a course page, so with no delivery to name the reader is sent
     * to the list of deliveries rather than to a broken link.
     *
     * @return string
     */
    private function coverage_url(): string {
        $worst = $this->summary['worstdelivery'];
        if ($worst === null) {
            return (new moodle_url('/local/outcomemap/curriculum.php'))->out(false);
        }
        return (new moodle_url('/local/outcomemap/coverage.php', [
            'courseid' => $worst['moodlecourseid'],
        ]))->out(false);
    }

    /**
     * Where a pending outcome version is signed off.
     *
     * The approval queue only exists while independent approval is enabled;
     * otherwise the author finalizes it from the frameworks page.
     *
     * @return string
     */
    private function approval_url(): string {
        $path = workflow::requires_independent_approval()
            ? '/local/outcomemap/approvalqueue.php'
            : '/local/outcomemap/frameworks.php';
        return (new moodle_url($path))->out(false);
    }
}
