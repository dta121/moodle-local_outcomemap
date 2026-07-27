<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Accreditation snapshot list page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\service\snapshot_service;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * List accreditation snapshots grouped by their correction lineage.
 *
 * Corrections never overwrite a frozen snapshot; they add a version alongside it.
 * A flat list of versions therefore hides the one thing a reader needs to know —
 * which version is current for a program and period, and what it superseded — so
 * versions of the same snapshot are grouped under one heading, newest first.
 */
final class snapshots_page implements renderable, templatable {
    /** @var \stdClass[] Snapshot versions with program metadata and row counts. */
    private array $snapshots;

    /** Load every snapshot version once. */
    public function __construct() {
        $this->snapshots = snapshot_service::list_all();
    }

    /** Export the template context. */
    public function export_for_template(renderer_base $output): array {
        $baseurl = new moodle_url('/local/outcomemap/snapshots.php');
        $groups = [];
        $frozen = 0;
        $drafts = 0;
        // A version that a correction builds on cannot be withdrawn without
        // breaking the chain, so the list offers withdrawal only at the end of
        // each lineage. The whole list is already loaded, so the superseded
        // versions are read off it rather than requeried per row.
        $superseded = [];
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->previousid !== null) {
                $superseded[(int) $snapshot->previousid] = true;
            }
        }
        foreach ($this->snapshots as $snapshot) {
            $isfrozen = $snapshot->status === snapshot_service::STATUS_FROZEN;
            $isfrozen ? $frozen++ : $drafts++;
            $uuid = (string) $snapshot->snapshotuuid;
            if (!isset($groups[$uuid])) {
                $groups[$uuid] = [
                    'programcode' => $snapshot->programcode,
                    'programname' => format_string($snapshot->programname),
                    'periodcode' => $snapshot->periodcode,
                    'reference' => substr($uuid, 0, 8),
                    'rows' => [],
                ];
            }
            $groups[$uuid]['rows'][] = [
                'version' => (int) $snapshot->version,
                'versionlabel' => get_string('snapreport_shortversion', 'local_outcomemap',
                    (int) $snapshot->version),
                'iscorrection' => $snapshot->previousid !== null,
                'statuslabel' => get_string('snapshotstatus_' . $snapshot->status, 'local_outcomemap'),
                'statusclass' => $isfrozen ? 'approved' : 'draft',
                'isfrozen' => $isfrozen,
                'population' => get_string(
                    (int) $snapshot->populationcount === 1 ? 'snapreport_learners_one' : 'snapreport_learners',
                    'local_outcomemap',
                    number_format((int) $snapshot->populationcount)
                ),
                'itemcount' => get_string('snapshots_rowcount', 'local_outcomemap',
                    number_format((int) $snapshot->itemcount)),
                'created' => userdate((int) $snapshot->timecreated),
                'viewurl' => (new moodle_url($baseurl, [
                    'action' => 'view',
                    'id' => (int) $snapshot->id,
                ]))->out(false),
                'freezeurl' => (new moodle_url($baseurl, [
                    'action' => 'freeze',
                    'id' => (int) $snapshot->id,
                ]))->out(false),
                'correcturl' => (new moodle_url($baseurl, [
                    'action' => 'correct',
                    'id' => (int) $snapshot->id,
                ]))->out(false),
                'candelete' => !isset($superseded[(int) $snapshot->id]),
                'deleteurl' => (new moodle_url($baseurl, [
                    'action' => 'delete',
                    'id' => (int) $snapshot->id,
                ]))->out(false),
            ];
        }
        foreach ($groups as $uuid => $group) {
            $count = count($group['rows']);
            $groups[$uuid]['countline'] = get_string(
                $count === 1 ? 'snapshots_versioncount_one' : 'snapshots_versioncount',
                'local_outcomemap',
                $count
            );
            $groups[$uuid]['searchtext'] = \core_text::strtolower(implode(' ', [
                $group['programcode'],
                $group['programname'],
                $group['periodcode'],
                $uuid,
            ]));
        }

        return [
            'addurl' => (new moodle_url($baseurl, ['action' => 'add']))->out(false),
            'groups' => array_values($groups),
            'hassnapshots' => $this->snapshots !== [],
            'statsline' => get_string('snapshots_statsline', 'local_outcomemap', (object) [
                'total' => count($this->snapshots),
                'lineages' => count($groups),
                'frozen' => $frozen,
                'draft' => $drafts,
            ]),
            'introline' => get_string('snapshots_intro', 'local_outcomemap'),
        ];
    }
}
