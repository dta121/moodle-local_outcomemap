<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Catalog courses page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\program_course_service;
use local_outcomemap\local\workflow;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * Build the searchable catalog course page with program memberships inline.
 *
 * A catalog course is only meaningful through two relationships: the programs its
 * outcomes roll up into, and the outcome framework it governs. Both used to live
 * in separate tables further down the page, so a course attached to no program —
 * whose outcomes therefore roll up nowhere — looked identical to a healthy one.
 * Each course now carries its own memberships, and the two states worth acting on
 * are filterable.
 */
final class catalog_courses_page implements renderable, templatable {
    /** @var \stdClass[] Catalog courses with governed outcome and association counts. */
    private array $courses;

    /** @var \stdClass[][] Non-retired memberships grouped by catalog course id. */
    private array $memberships = [];

    /** Load every catalog course and its memberships once. */
    public function __construct() {
        $this->courses = catalog_course_service::list_with_summary();
        foreach (program_course_service::list_all() as $membership) {
            if ($membership->status === workflow::RETIRED) {
                continue;
            }
            $this->memberships[(int) $membership->courseid][] = $membership;
        }
    }

    /** Export the template context. */
    public function export_for_template(renderer_base $output): array {
        $context = \context_system::instance();
        $canmanagecourses = has_capability('local/outcomemap:managecatalogcourses', $context);
        $canmanageprograms = has_capability('local/outcomemap:manageprograms', $context);
        $baseurl = new moodle_url('/local/outcomemap/catalogcourses.php');
        $rows = [];
        $noprogram = 0;
        $nooutcomes = 0;

        foreach ($this->courses as $course) {
            $memberships = $this->memberships[(int) $course->id] ?? [];
            $hasoutcomes = (int) $course->courseoutcomecount + (int) $course->unitoutcomecount > 0;
            if ($memberships === []) {
                $noprogram++;
            }
            if (!$hasoutcomes) {
                $nooutcomes++;
            }
            $rows[] = $this->row($course, $memberships, $hasoutcomes, $baseurl,
                $canmanagecourses, $canmanageprograms);
        }

        $filters = [
            [
                'id' => 'all',
                'label' => get_string('catalogcourses_filter_all', 'local_outcomemap'),
                'count' => count($rows),
                'active' => true,
            ],
            [
                'id' => 'noprogram',
                'label' => get_string('catalogcourses_filter_noprogram', 'local_outcomemap'),
                'count' => $noprogram,
                'active' => false,
            ],
            [
                'id' => 'nooutcomes',
                'label' => get_string('catalogcourses_filter_nooutcomes', 'local_outcomemap'),
                'count' => $nooutcomes,
                'active' => false,
            ],
        ];

        return [
            'canmanage' => $canmanagecourses,
            'canmanageprograms' => $canmanageprograms,
            'addurl' => (new moodle_url($baseurl, ['action' => 'add']))->out(false),
            'filters' => $filters,
            'rows' => $rows,
            'hascourses' => $rows !== [],
            'statsline' => get_string('catalogcourses_statsline', 'local_outcomemap', (object) [
                'total' => count($rows),
                'noprogram' => $noprogram,
                'nooutcomes' => $nooutcomes,
            ]),
        ];
    }

    /**
     * Build one catalog course card.
     *
     * @param \stdClass $course Catalog course with its summary counts.
     * @param \stdClass[] $memberships Non-retired memberships for the course.
     * @param bool $hasoutcomes Whether the course governs any live outcome.
     * @param moodle_url $baseurl Catalog courses page URL.
     * @param bool $canmanagecourses Whether the reader may act on catalog courses.
     * @param bool $canmanageprograms Whether the reader may act on memberships.
     * @return array Template row context.
     */
    private function row(\stdClass $course, array $memberships, bool $hasoutcomes, moodle_url $baseurl,
            bool $canmanagecourses, bool $canmanageprograms): array {
        $id = (int) $course->id;
        $name = format_string($course->name);
        $description = trim((string) ($course->description ?? ''));
        $row = [
            'id' => $id,
            'code' => $course->code,
            'name' => $name,
            'meta' => $hasoutcomes
                ? get_string('catalogcourses_meta', 'local_outcomemap', (object) [
                    'courseoutcomes' => (int) $course->courseoutcomecount,
                    'unitoutcomes' => (int) $course->unitoutcomecount,
                ])
                : get_string('catalogcourses_meta_noframework', 'local_outcomemap'),
            'hasoutcomes' => $hasoutcomes,
            'instanceline' => $this->instanceline($course),
            'statuslabel' => workflow::status_label($course->status),
            'statusclass' => $this->statusclass($course->status),
            'hasprograms' => $memberships !== [],
            'programs' => [],
            'memberships' => [],
            'outcomesurl' => (new moodle_url('/local/outcomemap/frameworks.php'))->out(false),
            'instancesurl' => (new moodle_url('/local/outcomemap/courseinstances.php', [
                'catalog' => $course->code,
            ]))->out(false),
            'addmembershipurl' => (new moodle_url($baseurl, [
                'action' => 'addmembership',
                'courseid' => $id,
            ]))->out(false),
            'canedit' => false,
            'cansubmit' => false,
        ];
        foreach ($memberships as $membership) {
            $effective = $this->effective($membership);
            $typeclass = clean_param((string) $membership->programtype, PARAM_ALPHA);
            $row['programs'][] = [
                'code' => $membership->programcode,
                'typeclass' => $typeclass,
                'title' => format_string($membership->programname) . ' · ' . $effective,
            ];
            $membershiprow = [
                'code' => $membership->programcode,
                'typeclass' => $typeclass,
                'name' => format_string($membership->programname),
                'effective' => $effective,
                'statuslabel' => workflow::status_label($membership->status),
                'statusclass' => $this->statusclass($membership->status),
                'cansubmit' => false,
            ];
            if ($canmanageprograms && $membership->status === workflow::DRAFT) {
                $membershiprow['cansubmit'] = true;
                $membershiprow['submitlabel'] = workflow::submit_action_label();
                $membershiprow['submiturl'] = (new moodle_url($baseurl, [
                    'action' => 'submit',
                    'type' => 'membership',
                    'id' => (int) $membership->id,
                    'sesskey' => sesskey(),
                ]))->out(false);
            }
            $row['memberships'][] = $membershiprow;
        }
        $row['searchtext'] = \core_text::strtolower(implode(' ', array_merge([
            $course->code,
            $course->name,
            $description,
            $course->siskey ?? '',
            workflow::status_label($course->status),
        ], array_column($row['programs'], 'code'))));
        if ($canmanagecourses && $course->status === workflow::DRAFT) {
            $row['canedit'] = true;
            $row['editurl'] = (new moodle_url($baseurl, ['action' => 'edit', 'id' => $id]))->out(false);
            $row['cansubmit'] = true;
            $row['submitlabel'] = workflow::submit_action_label();
            $row['submiturl'] = (new moodle_url($baseurl, [
                'action' => 'submit',
                'id' => $id,
                'sesskey' => sesskey(),
            ]))->out(false);
        }
        return $row;
    }

    /**
     * Describe how many Moodle course shells the catalog course is delivered in.
     *
     * @param \stdClass $course Catalog course with its association counts.
     * @return string Human-readable association line.
     */
    private function instanceline(\stdClass $course): string {
        $total = (int) $course->instancecount;
        if ($total === 0) {
            return get_string('catalogcourses_instances_none', 'local_outcomemap');
        }
        return get_string(
            $total === 1 ? 'catalogcourses_instances_one' : 'catalogcourses_instances',
            'local_outcomemap',
            (object) ['total' => $total, 'confirmed' => (int) $course->confirmedinstancecount]
        );
    }

    /**
     * Describe the effective range of a membership.
     *
     * @param \stdClass $membership Membership record.
     * @return string Human-readable effective range.
     */
    private function effective(\stdClass $membership): string {
        $format = get_string('strftimedate', 'core_langconfig');
        $from = userdate((int) $membership->effectivefrom, $format);
        if ($membership->effectiveto === null) {
            return get_string('catalogcourses_effective_open', 'local_outcomemap', $from);
        }
        return get_string('catalogcourses_effective', 'local_outcomemap', (object) [
            'from' => $from,
            'to' => userdate((int) $membership->effectiveto, $format),
        ]);
    }

    /**
     * Map a governed status onto its presentation class.
     *
     * @param string $status Canonical workflow status.
     * @return string CSS state suffix.
     */
    private function statusclass(string $status): string {
        $classes = [
            workflow::APPROVED => 'approved',
            workflow::NEEDS_REVIEW => 'review',
            workflow::DRAFT => 'draft',
            workflow::RETIRED => 'retired',
        ];
        return $classes[$status] ?? 'retired';
    }
}
