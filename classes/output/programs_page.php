<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Programs page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\service\program_service;
use local_outcomemap\local\workflow;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/** Build the searchable, status-grouped program administration page. */
final class programs_page implements renderable, templatable {
    /** @var \stdClass[] Programs with aggregate counts. */
    private array $programs;

    /** Load all program summaries once. */
    public function __construct() {
        $this->programs = program_service::list_with_summary();
    }

    /** Export the template context. */
    public function export_for_template(renderer_base $output): array {
        $context = \context_system::instance();
        $canmanage = has_capability('local/outcomemap:manageprograms', $context);
        $baseurl = new moodle_url('/local/outcomemap/programs.php');
        $frameworksurl = new moodle_url('/local/outcomemap/frameworks.php');
        $statusmeta = [
            workflow::APPROVED => ['class' => 'approved', 'title' => 'programs_group_approved'],
            workflow::NEEDS_REVIEW => ['class' => 'review', 'title' => 'programs_group_review'],
            workflow::DRAFT => ['class' => 'draft', 'title' => 'programs_group_draft'],
            workflow::RETIRED => ['class' => 'retired', 'title' => 'programs_group_retired'],
        ];
        $counts = array_fill_keys(array_keys($statusmeta), 0);
        foreach ($this->programs as $program) {
            if (isset($counts[$program->status])) {
                $counts[$program->status]++;
            }
        }

        $groups = [];
        foreach ($statusmeta as $status => $meta) {
            $rows = [];
            foreach ($this->programs as $program) {
                if ($program->status !== $status) {
                    continue;
                }
                $description = trim((string) ($program->description ?? ''));
                $searchtext = implode(' ', [
                    $program->code,
                    $program->name,
                    $description,
                    $program->externalid ?? '',
                    get_string('status_' . $program->status, 'local_outcomemap'),
                ]);
                $row = [
                    'code' => $program->code,
                    'name' => $program->name,
                    'description' => $description !== ''
                        ? $description
                        : get_string('programs_nodescription', 'local_outcomemap'),
                    'searchtext' => \core_text::strtolower($searchtext),
                    'coursecountline' => get_string(
                        (int) $program->coursecount === 1 ? 'programs_courses_one' : 'programs_courses',
                        'local_outcomemap',
                        (int) $program->coursecount
                    ),
                    'outcomecountline' => get_string(
                        (int) $program->outcomecount === 1 ? 'programs_outcomes_one' : 'programs_outcomes',
                        'local_outcomemap',
                        (int) $program->outcomecount
                    ),
                    'frameworkcountline' => get_string(
                        (int) $program->frameworkcount === 1 ? 'programs_frameworks_one' : 'programs_frameworks',
                        'local_outcomemap',
                        (int) $program->frameworkcount
                    ),
                    'statuslabel' => get_string('status_' . $program->status, 'local_outcomemap'),
                    'statusclass' => $meta['class'],
                    'outcomesurl' => $frameworksurl->out(false),
                    'canedit' => false,
                    'cansubmit' => false,
                ];
                if ($canmanage && $program->status === workflow::DRAFT) {
                    $row['canedit'] = true;
                    $row['editurl'] = (new moodle_url($baseurl, [
                        'action' => 'edit',
                        'id' => (int) $program->id,
                    ]))->out(false);
                    $row['cansubmit'] = true;
                    $row['submiturl'] = (new moodle_url($baseurl, [
                        'action' => 'submit',
                        'id' => (int) $program->id,
                        'sesskey' => sesskey(),
                    ]))->out(false);
                }
                $rows[] = $row;
            }
            if ($rows === []) {
                continue;
            }
            $groups[] = [
                'status' => $status,
                'statusclass' => $meta['class'],
                'title' => get_string($meta['title'], 'local_outcomemap'),
                'description' => get_string('programs_group_' . $meta['class'] . '_desc', 'local_outcomemap'),
                'countline' => get_string(count($rows) === 1 ? 'programs_count_one' : 'programs_count',
                    'local_outcomemap', count($rows)),
                'rows' => $rows,
            ];
        }

        return [
            'canmanage' => $canmanage,
            'addurl' => (new moodle_url($baseurl, ['action' => 'add']))->out(false),
            'hasprograms' => $this->programs !== [],
            'groups' => $groups,
            'statsline' => get_string('programs_statsline', 'local_outcomemap', (object) [
                'total' => count($this->programs),
                'approved' => $counts[workflow::APPROVED],
                'review' => $counts[workflow::NEEDS_REVIEW],
                'draft' => $counts[workflow::DRAFT],
                'retired' => $counts[workflow::RETIRED],
            ]),
        ];
    }
}
