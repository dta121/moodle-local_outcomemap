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
 * Frameworks and outcomes hierarchy page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\workflow;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * Builds the program/course outcome hierarchy for templates and CSV export.
 */
class outcomes_hierarchy implements renderable, templatable {
    /** The alignment matrix view. */
    public const VIEW_MATRIX = 'matrix';

    /** @var moodle_url Page base URL. */
    private moodle_url $baseurl;

    /** @var string The view being rendered. */
    private string $view;

    /** @var \stdClass[] Programs keyed by id. */
    private array $programs;

    /** @var \stdClass[] Catalog courses keyed by id. */
    private array $courses;

    /** @var \stdClass[] Frameworks keyed by id, each annotated with a hierarchy kind. */
    private array $frameworks;

    /** @var \stdClass[] Latest outcome version per stable item, keyed by item id. */
    private array $items;

    /** @var int[][] Alignment target item ids keyed by source item id. */
    private array $mapsbysource = [];

    /** @var int[][] Alignment source item ids keyed by target item id. */
    private array $mapsbytarget = [];

    /** Load all governed records once. */
    public function __construct(string $view = 'program') {
        global $DB;
        $this->view = in_array($view, ['program', 'course', self::VIEW_MATRIX], true) ? $view : 'program';
        $this->baseurl = new moodle_url('/local/outcomemap/frameworks.php');
        $this->programs = $DB->get_records('local_outcomemap_program', null, 'code ASC');
        $this->courses = $DB->get_records('local_outcomemap_course', null, 'code ASC');
        $this->frameworks = $DB->get_records('local_outcomemap_fw', null, 'ownertype ASC, code ASC');
        foreach ($this->frameworks as $framework) {
            // Course-owned frameworks hold unit-level outcomes when their code
            // carries the ULO suffix convention; anything else is course level.
            if ($framework->ownertype === framework_service::OWNER_COURSE) {
                $framework->kind = preg_match('/ULO$/i', $framework->code) ? 'unit' : 'course';
            } else {
                $framework->kind = 'program';
            }
        }
        $versions = $DB->get_records_sql("
            SELECT v.id AS versionid, v.version, v.statement, v.status AS versionstatus,
                   v.shortstatement, v.bloomlevel,
                   i.id AS itemid, i.code, i.frameworkid, i.status AS itemstatus
              FROM {local_outcomemap_itemver} v
              JOIN {local_outcomemap_item} i ON i.id = v.itemid
             WHERE v.version = (SELECT MAX(v2.version)
                                  FROM {local_outcomemap_itemver} v2
                                 WHERE v2.itemid = v.itemid)");
        $this->items = [];
        foreach ($versions as $version) {
            $this->items[(int) $version->itemid] = $version;
        }
        uasort($this->items, static fn($a, $b) => strnatcasecmp($a->code, $b->code));
        $relations = $DB->get_records_sql("
            SELECT r.id, r.sourceitemid, r.targetitemid, r.status
              FROM {local_outcomemap_rel} r
             WHERE r.type = :alignsto
               AND r.status <> :retired
               AND r.version = (SELECT MAX(r2.version)
                                  FROM {local_outcomemap_rel} r2
                                 WHERE r2.relationuuid = r.relationuuid)", [
            'alignsto' => \local_outcomemap\local\service\relation_service::ALIGNS_TO,
            'retired' => workflow::RETIRED,
        ]);
        foreach ($relations as $relation) {
            $this->mapsbysource[(int) $relation->sourceitemid][] = (int) $relation->targetitemid;
            $this->mapsbytarget[(int) $relation->targetitemid][] = (int) $relation->sourceitemid;
        }
    }

    /** Return the hierarchy kind of an item's framework. */
    private function kind(\stdClass $item): string {
        return $this->frameworks[(int) $item->frameworkid]->kind;
    }

    /** Return the full display label of an item, e.g. "MBA-PLO.PLO1". */
    private function label(\stdClass $item): string {
        return $this->frameworks[(int) $item->frameworkid]->code . '.' . $item->code;
    }

    /** Return the catalog course id owning an item's framework, or null. */
    private function courseid(\stdClass $item): ?int {
        $framework = $this->frameworks[(int) $item->frameworkid];
        return $framework->ownertype === framework_service::OWNER_COURSE ? (int) $framework->ownerid : null;
    }

    /** Return items of one hierarchy kind, optionally limited to one catalog course. */
    private function items_of_kind(string $kind, ?int $courseid = null): array {
        return array_filter($this->items, function ($item) use ($kind, $courseid) {
            return $this->kind($item) === $kind
                && ($courseid === null || $this->courseid($item) === $courseid);
        });
    }

    /** True when an item has no outgoing alignment. */
    private function is_unmapped(\stdClass $item): bool {
        return empty($this->mapsbysource[(int) $item->itemid]);
    }

    /** Governance status, version, and action fields shared by every row. */
    private function status_fields(\stdClass $item, bool $canmanage, bool $canapprove): array {
        $status = $item->versionstatus;
        $row = [
            'itemid' => (int) $item->itemid,
            'versionid' => (int) $item->versionid,
            'code' => $item->code,
            'statement' => $item->statement,
            'nextversion' => (int) $item->version + 1,
            'editnote' => get_string(
                workflow::requires_independent_approval() ? 'hier_editnote' : 'hier_editnote_finalization',
                'local_outcomemap',
                (int) $item->version + 1
            ),
            'approvedinfo' => null,
            'pendingbadge' => null,
            'canapprove' => false,
            'approveurl' => null,
            'cansubmit' => false,
            'submiturl' => null,
            'submitlabel' => workflow::submit_action_label(),
            'caneditinline' => false,
            'editformurl' => null,
        ];
        if ($status === workflow::APPROVED) {
            $row['approvedinfo'] = get_string('hier_statusinfo', 'local_outcomemap', (object) [
                'status' => workflow::status_label($status),
                'version' => (int) $item->version,
            ]);
            $row['caneditinline'] = $canmanage && $item->itemstatus === workflow::APPROVED;
        } else {
            $row['pendingbadge'] = get_string('hier_statusinfo', 'local_outcomemap', (object) [
                'status' => workflow::status_label($status),
                'version' => (int) $item->version,
            ]);
            if ($status === workflow::NEEDS_REVIEW && $canapprove) {
                $row['canapprove'] = true;
                $row['approveurl'] = (new moodle_url($this->baseurl, ['action' => 'approveversion',
                    'id' => (int) $item->versionid, 'sesskey' => sesskey()]))->out(false);
            }
            if ($status === workflow::DRAFT && $canmanage) {
                $row['cansubmit'] = true;
                $row['submiturl'] = (new moodle_url($this->baseurl, ['action' => 'submit', 'type' => 'outcome',
                    'id' => (int) $item->versionid, 'sesskey' => sesskey()]))->out(false);
                $row['editformurl'] = (new moodle_url($this->baseurl, ['action' => 'editoutcome',
                    'id' => (int) $item->versionid]))->out(false);
            }
        }
        return $row;
    }

    /** Lower-case haystack the client-side search matches against. */
    private function searchtext(\stdClass $item): string {
        return \core_text::strtolower($this->label($item) . ' ' . $item->code . ' ' . $item->statement);
    }

    /** Build one unit-outcome row. */
    private function ulo_row(\stdClass $item, bool $canmanage, bool $canapprove): array {
        $courseid = $this->courseid($item);
        return $this->status_fields($item, $canmanage, $canapprove) + [
            'badge' => get_string('hier_ulobadge', 'local_outcomemap', $item->code),
            'searchtext' => $this->searchtext($item),
            'canmap' => $canmanage,
            'pickerkey' => 'course-' . $courseid,
            'mappedjson' => json_encode(array_values($this->mapsbysource[(int) $item->itemid] ?? [])),
        ];
    }

    /** Build one course-outcome row with its aligned unit outcomes. */
    private function clo_row(\stdClass $item, bool $withchips, bool $canmanage, bool $canapprove): array {
        $ulos = [];
        foreach ($this->mapsbytarget[(int) $item->itemid] ?? [] as $sourceid) {
            $source = $this->items[$sourceid] ?? null;
            if ($source && $this->kind($source) === 'unit') {
                $ulos[$sourceid] = $source;
            }
        }
        uasort($ulos, static fn($a, $b) => strnatcasecmp($a->code, $b->code));
        $chips = array_map(
            fn($targetid) => isset($this->items[$targetid]) ? $this->label($this->items[$targetid]) : '',
            $this->mapsbysource[(int) $item->itemid] ?? []
        );
        $chips = array_values(array_filter($chips));
        sort($chips);
        $count = count($ulos);
        return $this->status_fields($item, $canmanage, $canapprove) + [
            'badge' => get_string('hier_clobadge', 'local_outcomemap', $item->code),
            'searchtext' => $this->searchtext($item),
            'ulocountchip' => get_string($count === 1 ? 'hier_unitoutcomes_one' : 'hier_unitoutcomes',
                'local_outcomemap', $count),
            'haschips' => $withchips && $chips !== [],
            'chips' => $chips,
            'isunmapped' => $this->is_unmapped($item),
            'canmap' => $canmanage,
            'pickerkey' => 'plo',
            'mappedjson' => json_encode(array_values($this->mapsbysource[(int) $item->itemid] ?? [])),
            'hastoggle' => true,
            'ulos' => array_map(fn($ulo) => $this->ulo_row($ulo, $canmanage, $canapprove), array_values($ulos)),
            'noulos' => $ulos === [],
        ];
    }

    /** Export the full template context. */
    public function export_for_template(renderer_base $output): array {
        $context = \context_system::instance();
        $canmanage = has_capability('local/outcomemap:manageframeworks', $context);
        $canapprove = workflow::requires_independent_approval()
            && has_capability('local/outcomemap:approve', $context);

        $plos = $this->items_of_kind('program');
        $clos = $this->items_of_kind('course');
        $ulos = $this->items_of_kind('unit');

        // Frameworks summary line.
        $labels = [];
        $allapproved = true;
        foreach ($this->frameworks as $framework) {
            $label = $framework->code;
            if ($framework->status !== workflow::APPROVED) {
                $label .= ' (' . workflow::status_label($framework->status) . ')';
                $allapproved = false;
            }
            $labels[] = $label;
        }
        $frameworkslinekey = 'hier_frameworksline';
        if ($allapproved && $labels !== []) {
            $frameworkslinekey = workflow::requires_independent_approval()
                ? 'hier_frameworksline_allapproved'
                : 'hier_frameworksline_allfinalized';
        }
        $frameworksline = get_string($frameworkslinekey, 'local_outcomemap', implode(' · ', $labels));

        $statsline = get_string('hier_statsline', 'local_outcomemap', (object) [
            'plos' => count($plos), 'clos' => count($clos), 'ulos' => count($ulos),
        ]);

        // Program view: one card per program-level framework.
        $programcards = [];
        foreach ($this->frameworks as $framework) {
            if ($framework->kind !== 'program') {
                continue;
            }
            $ownername = $framework->ownertype === framework_service::OWNER_PROGRAM
                ? ($this->programs[(int) $framework->ownerid]->name ?? $framework->name)
                : $framework->name;
            $rows = [];
            $fwitems = array_filter($plos, fn($item) => (int) $item->frameworkid === (int) $framework->id);
            foreach ($fwitems as $plo) {
                $groups = [];
                $totalclos = 0;
                foreach ($this->courses as $course) {
                    $groupclos = [];
                    foreach ($this->mapsbytarget[(int) $plo->itemid] ?? [] as $sourceid) {
                        $source = $this->items[$sourceid] ?? null;
                        if ($source && $this->kind($source) === 'course'
                                && $this->courseid($source) === (int) $course->id) {
                            $groupclos[$sourceid] = $source;
                        }
                    }
                    if ($groupclos === []) {
                        continue;
                    }
                    uasort($groupclos, static fn($a, $b) => strnatcasecmp($a->code, $b->code));
                    $totalclos += count($groupclos);
                    $groups[] = [
                        'label' => get_string('hier_grouplabel', 'local_outcomemap', $course->code),
                        'clos' => array_map(fn($clo) => $this->clo_row($clo, false, $canmanage, $canapprove),
                            array_values($groupclos)),
                    ];
                }
                $rows[] = $this->status_fields($plo, $canmanage, $canapprove) + [
                    'badge' => $plo->code,
                    'searchtext' => $this->searchtext($plo),
                    'countchip' => get_string($totalclos === 1 ? 'hier_courseoutcomes_one' : 'hier_courseoutcomes',
                        'local_outcomemap', $totalclos),
                    'canmap' => false,
                    'hastoggle' => true,
                    'groups' => $groups,
                    'nogroups' => $groups === [],
                ];
            }
            $programcards[] = [
                'title' => $ownername,
                'subtitle' => get_string('hier_frameworklabel', 'local_outcomemap', (object) [
                    'code' => $framework->code,
                    'status' => workflow::status_label($framework->status),
                ]),
                'countline' => get_string(count($fwitems) === 1 ? 'hier_programoutcomes_one' : 'hier_programoutcomes',
                    'local_outcomemap', count($fwitems)),
                'searchtext' => \core_text::strtolower($ownername . ' ' . $framework->code),
                'rows' => $rows,
            ];
        }

        // Course view: one card per catalog course owning frameworks.
        $coursecards = [];
        foreach ($this->courses as $course) {
            $ownedfws = array_filter($this->frameworks,
                fn($fw) => $fw->ownertype === framework_service::OWNER_COURSE && (int) $fw->ownerid === (int) $course->id);
            if ($ownedfws === []) {
                continue;
            }
            $courseclos = $this->items_of_kind('course', (int) $course->id);
            $courseulos = $this->items_of_kind('unit', (int) $course->id);
            $unmappedulos = array_filter($courseulos, fn($item) => $this->is_unmapped($item));
            $coursecards[] = [
                'course' => $course->code,
                'subtitle' => get_string('hier_courseframeworks', 'local_outcomemap',
                    implode(', ', array_map(fn($fw) => $fw->code, $ownedfws))),
                'countline' => get_string('hier_coursecountline', 'local_outcomemap', (object) [
                    'clos' => count($courseclos), 'ulos' => count($courseulos),
                ]),
                'searchtext' => \core_text::strtolower($course->code . ' ' . $course->name),
                'clos' => array_map(fn($clo) => $this->clo_row($clo, true, $canmanage, $canapprove),
                    array_values($courseclos)),
                'hasunmappedulos' => $unmappedulos !== [],
                'unmappedcount' => count($unmappedulos),
                'unmappedulos' => array_map(fn($ulo) => $this->ulo_row($ulo, $canmanage, $canapprove),
                    array_values($unmappedulos)),
            ];
        }

        // Unmapped-only view, grouped by catalog course.
        $unmappeditems = array_filter($this->items,
            fn($item) => $this->kind($item) !== 'program' && $this->is_unmapped($item));
        $unmappedgroups = [];
        foreach ($this->courses as $course) {
            $rows = [];
            foreach ($unmappeditems as $item) {
                if ($this->courseid($item) !== (int) $course->id) {
                    continue;
                }
                $base = $this->kind($item) === 'course'
                    ? $this->clo_row($item, false, $canmanage, $canapprove)
                    : $this->ulo_row($item, $canmanage, $canapprove);
                $base['badge'] = $this->label($item);
                unset($base['hastoggle'], $base['ulos'], $base['noulos']);
                $rows[] = $base;
            }
            if ($rows !== []) {
                $unmappedgroups[] = ['label' => $course->code, 'rows' => $rows];
            }
        }
        $unmappedcount = count($unmappeditems);

        // Map pickers: one option template per alignment target set.
        $pickers = [[
            'key' => 'plo',
            'title' => get_string('hier_mapto_plo', 'local_outcomemap'),
            'options' => array_map(fn($plo) => [
                'itemid' => (int) $plo->itemid,
                'label' => $this->label($plo),
                'text' => $plo->statement,
            ], array_values($plos)),
        ]];
        foreach ($this->courses as $course) {
            $courseclos = $this->items_of_kind('course', (int) $course->id);
            if ($courseclos === []) {
                continue;
            }
            $pickers[] = [
                'key' => 'course-' . $course->id,
                'title' => get_string('hier_mapto_clo', 'local_outcomemap', $course->code),
                'options' => array_map(fn($clo) => [
                    'itemid' => (int) $clo->itemid,
                    'label' => $this->label($clo),
                    'text' => $clo->statement,
                ], array_values($courseclos)),
            ];
        }

        $ismatrix = $this->view === self::VIEW_MATRIX;
        $relationsurl = new moodle_url('/local/outcomemap/relations.php');
        // The alignment grid is an outcome-by-outcome table, so it is large enough
        // that building it for the two hierarchy views would be paid for on every
        // page load and thrown away. It is built only when it is the view shown.
        $alignment = $ismatrix ? (new relations_page())->export_for_template($output) : null;
        $alignmentcount = 0;
        foreach ($this->mapsbysource as $targets) {
            $alignmentcount += count($targets);
        }

        return [
            'baseurl' => $this->baseurl->out(false),
            'sesskey' => sesskey(),
            'canmanage' => $canmanage,
            'frameworksline' => $frameworksline,
            'statsline' => $statsline,
            'unmappedcount' => $unmappedcount,
            'hasunmapped' => $unmappedcount > 0,
            'calloutmessage' => get_string('hier_callout', 'local_outcomemap', $unmappedcount),
            'addframeworkurl' => (new moodle_url($this->baseurl, ['action' => 'addframework']))->out(false),
            'addoutcomeurl' => (new moodle_url($this->baseurl, ['action' => 'addoutcome']))->out(false),
            'exporturl' => (new moodle_url($this->baseurl, ['action' => 'exportcsv']))->out(false),
            'alignmentexporturl' => (new moodle_url($relationsurl, ['action' => 'exportcsv']))->out(false),
            'addalignmenturl' => (new moodle_url($relationsurl, ['action' => 'add']))->out(false),
            'viewtabs' => $this->viewtabs($ismatrix),
            'ismatrix' => $ismatrix,
            'initialview' => $this->view,
            'alignment' => $alignment,
            'hierarchyline' => get_string('outcomes_hierarchyline', 'local_outcomemap', (object) [
                'alignments' => $alignmentcount,
                'unaligned' => $unmappedcount,
            ]),
            'programcards' => $ismatrix ? [] : $programcards,
            'coursecards' => $ismatrix ? [] : $coursecards,
            'unmappedgroups' => $ismatrix ? [] : $unmappedgroups,
            'pickers' => $ismatrix ? [] : $pickers,
        ];
    }

    /**
     * Build the three view tabs.
     *
     * The two hierarchy views are both in the page at once and switch instantly in
     * the browser, so they stay buttons. The matrix is a separate render, so it is
     * a link — and once it is showing, the hierarchy views become links back.
     *
     * @param bool $ismatrix Whether the matrix view is the one being rendered.
     * @return array[] Tab descriptors.
     */
    private function viewtabs(bool $ismatrix): array {
        $tabs = [];
        foreach (['program' => 'hier_byprogram', 'course' => 'hier_bycourse',
                self::VIEW_MATRIX => 'outcomes_matrixview'] as $id => $stringid) {
            $islink = $ismatrix || $id === self::VIEW_MATRIX;
            $tabs[] = [
                'id' => $id,
                'label' => get_string($stringid, 'local_outcomemap'),
                'active' => $id === $this->view,
                'islink' => $islink,
                'isbutton' => !$islink,
                'url' => (new moodle_url($this->baseurl, ['view' => $id]))->out(false),
            ];
        }
        return $tabs;
    }

    /** Rows for the CSV export: type, framework, code, statement, maps to, version, status. */
    public function csv_rows(): array {
        $rows = [[
            get_string('hier_csv_type', 'local_outcomemap'),
            get_string('framework', 'local_outcomemap'),
            get_string('code', 'local_outcomemap'),
            get_string('statement', 'local_outcomemap'),
            get_string('hier_csv_mapsto', 'local_outcomemap'),
            get_string('version', 'local_outcomemap'),
            get_string('status', 'local_outcomemap'),
        ]];
        $typenames = [
            'program' => get_string('hier_csv_programoutcome', 'local_outcomemap'),
            'course' => get_string('hier_csv_courseoutcome', 'local_outcomemap'),
            'unit' => get_string('hier_csv_unitoutcome', 'local_outcomemap'),
        ];
        foreach (['program', 'course', 'unit'] as $kind) {
            foreach ($this->items_of_kind($kind) as $item) {
                $mapsto = array_map(
                    fn($targetid) => isset($this->items[$targetid]) ? $this->label($this->items[$targetid]) : '',
                    $this->mapsbysource[(int) $item->itemid] ?? []);
                $mapsto = array_values(array_filter($mapsto));
                sort($mapsto);
                $rows[] = [
                    $typenames[$kind],
                    $this->frameworks[(int) $item->frameworkid]->code,
                    $item->code,
                    $item->statement,
                    implode('; ', $mapsto),
                    (int) $item->version,
                    get_string('status_' . $item->versionstatus, 'local_outcomemap'),
                ];
            }
        }
        return $rows;
    }

    /** Current alignment target ids for one source item (latest relation versions, not retired). */
    public static function current_targets(int $itemid): array {
        global $DB;
        $records = $DB->get_records_sql("
            SELECT r.id, r.targetitemid
              FROM {local_outcomemap_rel} r
             WHERE r.type = :alignsto
               AND r.sourceitemid = :sourceitemid
               AND r.status <> :retired
               AND r.version = (SELECT MAX(r2.version)
                                  FROM {local_outcomemap_rel} r2
                                 WHERE r2.relationuuid = r.relationuuid)", [
            'alignsto' => \local_outcomemap\local\service\relation_service::ALIGNS_TO,
            'sourceitemid' => $itemid,
            'retired' => workflow::RETIRED,
        ]);
        return array_values(array_unique(array_map(static fn($r) => (int) $r->targetitemid, $records)));
    }
}
