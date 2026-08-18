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
 * Approval queue aggregation and dispatch service.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * * Aggregates and dispatches records in the Milestone 1 approval queue.
 */
final class approval_service extends base_service {
    /**
     * Return all pending governed records.
     *
     * @return array Pending governed records.
     */
    public static function list_pending(): array {
        global $DB;
        self::require_system('local/outcomemap:approve');
        $pending = [];
        $definitions = [
            'program' => ['local_outcomemap_program', 'code', 'name'],
            'catalog_course' => ['local_outcomemap_course', 'code', 'name'],
            'framework' => ['local_outcomemap_fw', 'code', 'name'],
        ];
        foreach ($definitions as $type => [$table, $codefield, $namefield]) {
            $records = $DB->get_records($table, ['status' => workflow::NEEDS_REVIEW], $codefield . ' ASC');
            foreach ($records as $record) {
                $pending[] = (object) [
                    'objecttype' => $type,
                    'id' => $record->id,
                    'code' => $record->{$codefield},
                    'name' => $record->{$namefield},
                    'createdby' => $record->createdby,
                    'timemodified' => $record->timemodified,
                ];
            }
        }
        $memberships = $DB->get_records_sql(
            "SELECT pc.id, p.code AS programcode, c.code AS coursecode, pc.createdby, pc.timemodified
               FROM {local_outcomemap_progcourse} pc
               JOIN {local_outcomemap_program} p ON p.id = pc.programid
               JOIN {local_outcomemap_course} c ON c.id = pc.courseid
              WHERE pc.status = :status ORDER BY p.code, c.code",
            ['status' => workflow::NEEDS_REVIEW]
        );
        foreach ($memberships as $record) {
            $pending[] = (object) [
                'objecttype' => 'program_course',
                'id' => $record->id,
                'code' => $record->programcode . ' / ' . $record->coursecode,
                'name' => '',
                'createdby' => $record->createdby,
                'timemodified' => $record->timemodified,
            ];
        }
        $instances = $DB->get_records_sql(
            "SELECT ci.id, c.code, mc.fullname, ci.periodcode, ci.createdby, ci.timemodified
               FROM {local_outcomemap_cinst} ci
               JOIN {local_outcomemap_course} c ON c.id = ci.courseid
               JOIN {course} mc ON mc.id = ci.moodlecourseid
              WHERE ci.status = :status ORDER BY c.code, ci.periodcode",
            ['status' => workflow::NEEDS_REVIEW]
        );
        foreach ($instances as $record) {
            $pending[] = (object) [
                'objecttype' => 'course_instance',
                'id' => $record->id,
                'code' => $record->code . ' / ' . $record->periodcode,
                'name' => $record->fullname,
                'createdby' => $record->createdby,
                'timemodified' => $record->timemodified,
            ];
        }
        $versions = $DB->get_records_sql(
            "SELECT v.id, i.code, v.statement, v.createdby, v.timemodified
               FROM {local_outcomemap_itemver} v
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
              WHERE v.status = :status ORDER BY i.code",
            ['status' => workflow::NEEDS_REVIEW]
        );
        foreach ($versions as $record) {
            $pending[] = (object) [
                'objecttype' => 'outcome_version',
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->statement,
                'createdby' => $record->createdby,
                'timemodified' => $record->timemodified,
            ];
        }
        $relations = $DB->get_records_sql(
            "SELECT r.id, source.code AS sourcecode, target.code AS targetcode, r.type,
                    r.createdby, r.timemodified
               FROM {local_outcomemap_rel} r
               JOIN {local_outcomemap_item} source ON source.id = r.sourceitemid
               JOIN {local_outcomemap_item} target ON target.id = r.targetitemid
              WHERE r.status = :status ORDER BY source.code, target.code",
            ['status' => workflow::NEEDS_REVIEW]
        );
        foreach ($relations as $record) {
            $pending[] = (object) [
                'objecttype' => 'relation',
                'id' => $record->id,
                'code' => $record->sourcecode . ' ' . $record->type . ' ' . $record->targetcode,
                'name' => '',
                'createdby' => $record->createdby,
                'timemodified' => $record->timemodified,
            ];
        }
        $contentmappings = $DB->get_records_sql(
            "SELECT m.id, m.createdby, m.timemodified, m.role, i.code AS outcomecode,
                    f.code AS frameworkcode, c.fullname AS coursename
               FROM {local_outcomemap_cmmap} m
               JOIN {local_outcomemap_cinst} ci ON ci.id = m.cinstid
               JOIN {course} c ON c.id = ci.moodlecourseid
               JOIN {local_outcomemap_itemver} v ON v.id = m.itemverid
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE m.status = :status ORDER BY c.fullname, f.code, i.code",
            ['status' => workflow::NEEDS_REVIEW]
        );
        foreach ($contentmappings as $record) {
            $pending[] = (object) [
                'objecttype' => 'course_module_mapping',
                'id' => $record->id,
                'code' => $record->frameworkcode . '.' . $record->outcomecode . ' / ' . $record->role,
                'name' => $record->coursename,
                'createdby' => $record->createdby,
                'timemodified' => $record->timemodified,
            ];
        }
        $sectionmappings = $DB->get_records_sql(
            "SELECT m.id, m.createdby, m.timemodified, m.role, i.code AS outcomecode,
                    f.code AS frameworkcode, c.fullname AS coursename
               FROM {local_outcomemap_secmap} m
               JOIN {local_outcomemap_cinst} ci ON ci.id = m.cinstid
               JOIN {course} c ON c.id = ci.moodlecourseid
               JOIN {local_outcomemap_itemver} v ON v.id = m.itemverid
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE m.status = :status ORDER BY c.fullname, f.code, i.code",
            ['status' => workflow::NEEDS_REVIEW]
        );
        foreach ($sectionmappings as $record) {
            $pending[] = (object) [
                'objecttype' => 'course_section_mapping',
                'id' => $record->id,
                'code' => $record->frameworkcode . '.' . $record->outcomecode . ' / ' . $record->role,
                'name' => $record->coursename,
                'createdby' => $record->createdby,
                'timemodified' => $record->timemodified,
            ];
        }
        $policies = $DB->get_records(
            'local_outcomemap_policy',
            ['status' => workflow::NEEDS_REVIEW],
            'policytype, name'
        );
        foreach ($policies as $record) {
            $pending[] = (object) [
                'objecttype' => 'policy',
                'id' => $record->id,
                'code' => $record->policytype . ' / ' . $record->scopetype,
                'name' => $record->name,
                'createdby' => $record->createdby,
                'timemodified' => $record->timemodified,
            ];
        }
        $questionmappings = $DB->get_records_sql(
            "SELECT m.id, m.createdby, m.timemodified, m.role, m.weight, i.code AS outcomecode,
                    f.code AS frameworkcode, q.name AS questionname, qv.version AS questionversion
               FROM {local_outcomemap_qmap} m
               JOIN {question} q ON q.id = m.questionid
               JOIN {question_versions} qv ON qv.id = m.questionversionid
               JOIN {local_outcomemap_itemver} v ON v.id = m.itemverid
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE m.status = :status ORDER BY q.name, f.code, i.code",
            ['status' => workflow::NEEDS_REVIEW]
        );
        foreach ($questionmappings as $record) {
            $pending[] = (object) [
                'objecttype' => 'question_mapping',
                'id' => $record->id,
                'code' => $record->frameworkcode . '.' . $record->outcomecode . ' / ' . $record->role,
                'name' => $record->questionname . ' (v' . $record->questionversion . ')',
                'createdby' => $record->createdby,
                'timemodified' => $record->timemodified,
            ];
        }
        // With remediation off, its pending drafts drop out of the queue. The
        // records are untouched and reappear if the feature is turned back on.
        $remediations = !\local_outcomemap\local\feature::remediation_enabled() ? [] : $DB->get_records_sql(
            "SELECT r.id, r.createdby, r.timemodified, r.title, i.code AS outcomecode,
                    f.code AS frameworkcode, c.fullname AS coursename
               FROM {local_outcomemap_remed} r
               JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
               JOIN {course} c ON c.id = ci.moodlecourseid
               JOIN {local_outcomemap_itemver} v ON v.id = r.itemverid
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
               JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
              WHERE r.status = :status ORDER BY c.fullname, f.code, i.code",
            ['status' => workflow::NEEDS_REVIEW]
        );
        foreach ($remediations as $record) {
            $pending[] = (object) [
                'objecttype' => 'remediation',
                'id' => $record->id,
                'code' => $record->frameworkcode . '.' . $record->outcomecode,
                'name' => $record->coursename . ' / ' . $record->title,
                'createdby' => $record->createdby,
                'timemodified' => $record->timemodified,
            ];
        }
        return $pending;
    }

    /**
     * Dispatch approval to the authoritative service.
     *
     * @param string $objecttype Governed object type.
     * @param int $id Governed record identifier.
     * @param string|null $reason Optional approval reason.
     */
    public static function approve(string $objecttype, int $id, ?string $reason = null): void {
        switch ($objecttype) {
            case 'program':
                program_service::approve($id, $reason);
                return;
            case 'catalog_course':
                catalog_course_service::approve($id, $reason);
                return;
            case 'course_instance':
                course_instance_service::confirm($id, $reason);
                return;
            case 'program_course':
                program_course_service::approve($id, $reason);
                return;
            case 'framework':
                framework_service::approve($id, $reason);
                return;
            case 'outcome_version':
                outcome_service::approve($id, $reason);
                return;
            case 'relation':
                relation_service::approve($id, $reason);
                return;
            case 'course_module_mapping':
                content_mapping_service::approve(content_mapping_service::TARGET_MODULE, $id, $reason);
                return;
            case 'course_section_mapping':
                content_mapping_service::approve(content_mapping_service::TARGET_SECTION, $id, $reason);
                return;
            case 'question_mapping':
                question_mapping_service::approve($id, $reason);
                return;
            case 'policy':
                policy_service::approve($id, $reason);
                return;
            case 'remediation':
                remediation_service::approve($id, $reason);
                return;
            default:
                throw new validation_exception('invalidfield', 'objecttype', $objecttype);
        }
    }
}
