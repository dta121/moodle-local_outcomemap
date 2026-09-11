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
 * Course coverage projection service.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\workflow;

/**
 * * Builds course coverage projections from bulk-loaded mapping records.
 */
final class coverage_service extends base_service {
    /**
     * Taught and assessed by mapped content.
     */
    public const STATUS_FULL = 'full';

    /**
     * Assessed, but no teaching content is mapped.
     */
    public const STATUS_ASSESSED_ONLY = 'assessedonly';

    /**
     * Taught, but no assessing content is mapped.
     */
    public const STATUS_TAUGHT = 'taught';

    /**
     * No content mapped at all.
     */
    public const STATUS_NONE = 'none';

    /**
     * Nothing maps to the outcome itself, but an outcome aligned to it is covered.
     *
     * A course outcome is normally reached through the unit outcomes aligned
     * to it rather than mapped directly, so its coverage is the coverage of
     * those outcomes. Reported apart from direct coverage because it says
     * where to look, not that the outcome is unreachable.
     */
    public const STATUS_INHERITED = 'inherited';

    /**
     * Classify one matrix row by the roles of the content mapped to it.
     *
     * The assessing role is what makes an outcome measurable, so it is the axis
     * that separates a complete outcome from a gap. Every other role counts as
     * teaching content for this purpose.
     *
     * @param \stdClass $row Matrix row produced by {@see matrix()}.
     * @return string One of this class's STATUS_* values.
     */
    public static function row_status(\stdClass $row): string {
        $taught = false;
        $assessed = false;
        foreach (array_merge($row->sections, $row->modules, $row->questions ?? []) as $mapping) {
            if ($mapping->role === content_mapping_service::ROLE_ASSESSES) {
                $assessed = true;
            } else {
                $taught = true;
            }
        }
        if ($taught && $assessed) {
            return self::STATUS_FULL;
        }
        if ($assessed) {
            return self::STATUS_ASSESSED_ONLY;
        }
        if ($taught) {
            return self::STATUS_TAUGHT;
        }
        return ($row->inheritedfrom ?? []) !== [] ? self::STATUS_INHERITED : self::STATUS_NONE;
    }

    /**
     * Build a course coverage matrix without per-target queries.
     *
     * Every outcome the course is responsible for is represented, including
     * outcomes nothing maps to yet: an uncovered outcome is the finding a
     * coverage report exists to surface. Rows carry a `covered` flag so callers
     * can distinguish "no mapping" from "not applicable".
     *
     * @param int $courseid Moodle course identifier.
     * @param int|null $at Effective timestamp, defaulting to now.
     * @return array<int,object> Rows keyed by exact outcome-version ID.
     */
    public static function matrix(int $courseid, ?int $at = null): array {
        $at = $at ?? time();
        $rows = self::course_outcome_baseline($courseid, $at);
        $mappings = content_mapping_service::list_for_course($courseid);
        foreach (['sections', 'modules'] as $collection) {
            foreach ($mappings[$collection] as $mapping) {
                $itemverid = (int) $mapping->itemverid;
                if (!isset($rows[$itemverid])) {
                    // Mapped outside the course's own frameworks, or under an
                    // outcome version that is no longer current. Still reported.
                    $rows[$itemverid] = (object) [
                        'itemverid' => $itemverid,
                        'frameworkcode' => $mapping->frameworkcode,
                        'outcomecode' => $mapping->outcomecode,
                        'outcomeversion' => (int) $mapping->outcomeversion,
                        'statement' => $mapping->outcomestatement,
                        'sections' => [],
                        'modules' => [],
                        'questions' => [],
                        'covered' => false,
                    ];
                }
                $rows[$itemverid]->{$collection}[] = $mapping;
                $rows[$itemverid]->covered = true;
            }
        }
        foreach (question_browser_service::assessment_coverage($courseid, $at) as $itemverid => $questionmappings) {
            if (!isset($rows[$itemverid])) {
                $mapping = reset($questionmappings);
                $rows[$itemverid] = (object) [
                    'itemverid' => (int) $itemverid,
                    'frameworkcode' => $mapping->frameworkcode,
                    'outcomecode' => $mapping->outcomecode,
                    'outcomeversion' => (int) $mapping->outcomeversion,
                    'statement' => $mapping->outcomestatement,
                    'sections' => [],
                    'modules' => [],
                    'questions' => [],
                    'covered' => false,
                ];
            }
            $rows[$itemverid]->questions = array_merge(
                $rows[$itemverid]->questions ?? [],
                $questionmappings
            );
            $rows[$itemverid]->covered = true;
        }
        self::attach_inherited_coverage($rows, $at);
        uasort($rows, static function (\stdClass $a, \stdClass $b): int {
            return [$a->frameworkcode, $a->outcomecode, $a->outcomeversion]
                <=> [$b->frameworkcode, $b->outcomecode, $b->outcomeversion];
        });
        return $rows;
    }

    /**
     * Record, on each row, the covered outcomes aligned to it.
     *
     * Walks approved, current alignments whose target is in the matrix. An
     * outcome counts as covered through alignment when a source outcome is
     * covered directly or, in turn, through its own alignments, so a course
     * outcome sees the unit outcomes beneath it. Every row gains
     * `inheritedfrom` (label and assessed flag per covered source) and
     * `inheritedassessed`; a row nothing aligns to gets an empty list.
     *
     * @param array $rows Matrix rows keyed by outcome-version id.
     * @param int $at Effective timestamp for the report.
     */
    private static function attach_inherited_coverage(array $rows, int $at): void {
        global $DB;
        foreach ($rows as $row) {
            $row->inheritedfrom = [];
            $row->inheritedassessed = false;
        }
        if (!$rows) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'iv');
        $itemids = $DB->get_records_sql_menu(
            "SELECT id, itemid FROM {local_outcomemap_itemver} WHERE id $insql",
            $params
        );
        $rowsbyitem = [];
        foreach ($rows as $itemverid => $row) {
            if (isset($itemids[$itemverid])) {
                $rowsbyitem[(int) $itemids[$itemverid]][] = $row;
            }
        }
        if (!$rowsbyitem) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($rowsbyitem), SQL_PARAMS_NAMED, 'ti');
        $params += [
            'type' => relation_service::ALIGNS_TO,
            'status' => workflow::APPROVED,
            'at1' => $at,
            'at2' => $at,
        ];
        $relations = $DB->get_records_sql(
            "SELECT r.id, r.sourceitemid, r.targetitemid
               FROM {local_outcomemap_rel} r
              WHERE r.type = :type AND r.status = :status AND r.targetitemid $insql
                AND r.effectivefrom <= :at1
                AND (r.effectiveto IS NULL OR r.effectiveto > :at2)
                AND r.version = (SELECT MAX(r2.version)
                                   FROM {local_outcomemap_rel} r2
                                  WHERE r2.relationuuid = r.relationuuid)",
            $params
        );
        $sources = [];
        foreach ($relations as $relation) {
            $sources[(int) $relation->targetitemid][(int) $relation->sourceitemid] = true;
        }
        if (!$sources) {
            return;
        }

        // Direct coverage per item, from the rows already built.
        $direct = [];
        foreach ($rowsbyitem as $itemid => $itemrows) {
            $covered = false;
            $assessed = false;
            $label = '';
            foreach ($itemrows as $row) {
                $label = $row->frameworkcode . '.' . $row->outcomecode;
                foreach (array_merge($row->sections, $row->modules, $row->questions ?? []) as $mapping) {
                    $covered = true;
                    $assessed = $assessed || $mapping->role === content_mapping_service::ROLE_ASSESSES;
                }
            }
            $direct[$itemid] = (object) ['covered' => $covered, 'assessed' => $assessed, 'label' => $label];
        }

        // Resolve each item once; the alignment graph is walked with a guard so
        // a cycle in unapproved data cannot recurse forever.
        $memo = [];
        $resolve = static function (int $itemid, array $visiting) use (&$resolve, &$memo, $sources, $direct): array {
            if (isset($memo[$itemid])) {
                return $memo[$itemid];
            }
            $from = [];
            $visiting[$itemid] = true;
            foreach (array_keys($sources[$itemid] ?? []) as $sourceid) {
                if (!isset($direct[$sourceid]) || isset($visiting[$sourceid])) {
                    continue;
                }
                $source = $direct[$sourceid];
                if ($source->covered) {
                    $from[$sourceid] = (object) ['label' => $source->label, 'assessed' => $source->assessed];
                    continue;
                }
                $inherited = $resolve($sourceid, $visiting);
                if ($inherited !== []) {
                    $assessed = false;
                    foreach ($inherited as $entry) {
                        $assessed = $assessed || $entry->assessed;
                    }
                    $from[$sourceid] = (object) ['label' => $source->label, 'assessed' => $assessed];
                }
            }
            uasort($from, static fn($a, $b) => strnatcasecmp($a->label, $b->label));
            $memo[$itemid] = array_values($from);
            return $memo[$itemid];
        };
        foreach ($rowsbyitem as $itemid => $itemrows) {
            $from = $resolve($itemid, []);
            if ($from === []) {
                continue;
            }
            $assessed = false;
            foreach ($from as $entry) {
                $assessed = $assessed || $entry->assessed;
            }
            foreach ($itemrows as $row) {
                $row->inheritedfrom = $from;
                $row->inheritedassessed = $assessed;
            }
        }
    }

    /**
     * Return the currently effective outcomes the course is responsible for.
     *
     * Scope is the approved frameworks owned by the catalog courses this Moodle
     * course is associated with through an approved, confirmed course instance —
     * the same association that makes a mapping valid in the first place.
     *
     * @param int $courseid Moodle course identifier.
     * @param int|null $at Effective timestamp, defaulting to now.
     * @return array<int,object> Uncovered baseline rows keyed by outcome-version ID.
     */
    public static function course_outcome_baseline(int $courseid, ?int $at = null): array {
        global $DB;

        $at = $at ?? time();
        $records = $DB->get_records_sql(
            "SELECT v.id AS itemverid, f.code AS frameworkcode, i.code AS outcomecode,
                    v.version AS outcomeversion, v.statement AS outcomestatement
               FROM {local_outcomemap_cinst} ci
               JOIN {local_outcomemap_fw} f
                 ON f.ownertype = :ownertype AND f.ownerid = ci.courseid
               JOIN {local_outcomemap_item} i ON i.frameworkid = f.id
               JOIN {local_outcomemap_itemver} v ON v.itemid = i.id
              WHERE ci.moodlecourseid = :courseid
                AND ci.status = :cinststatus
                AND ci.confirmed = 1
                AND f.status = :fstatus
                AND i.status = :istatus
                AND v.status = :vstatus
                AND v.effectivefrom <= :at1
                AND (v.effectiveto IS NULL OR v.effectiveto > :at2)
           ORDER BY f.code, i.code, v.version",
            [
                'ownertype' => framework_service::OWNER_COURSE,
                'courseid' => $courseid,
                'cinststatus' => workflow::APPROVED,
                'fstatus' => workflow::APPROVED,
                'istatus' => workflow::APPROVED,
                'vstatus' => workflow::APPROVED,
                'at1' => $at,
                'at2' => $at,
            ]
        );
        $rows = [];
        foreach ($records as $record) {
            $rows[(int) $record->itemverid] = (object) [
                'itemverid' => (int) $record->itemverid,
                'frameworkcode' => $record->frameworkcode,
                'outcomecode' => $record->outcomecode,
                'outcomeversion' => (int) $record->outcomeversion,
                'statement' => $record->outcomestatement,
                'sections' => [],
                'modules' => [],
                'questions' => [],
                'covered' => false,
            ];
        }
        return $rows;
    }
}
