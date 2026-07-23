<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\api;

use local_outcomemap\local\dto\outcome;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/** Public context-scoped search service for approved effective outcomes. */
final class outcome_search {
    public const API_VERSION = '1.0';

    /**
     * Search approved outcome versions visible in a Moodle context.
     *
     * @param \context $context Authoritative caller context.
     * @param string $query Code or statement fragment.
     * @param int|null $effectiveat Effective timestamp; defaults to now.
     * @param int $limit Maximum 1-200 records.
     * @return outcome[]
     */
    public static function search(\context $context, string $query = '', ?int $effectiveat = null,
            int $limit = 50): array {
        global $DB;
        require_capability('local/outcomemap:viewdefinitions', $context);
        $effectiveat = $effectiveat ?? time();
        $limit = max(1, min(200, $limit));
        $params = [
            'approvedfw' => workflow::APPROVED,
            'approveditem' => workflow::APPROVED,
            'approvedversion' => workflow::APPROVED,
            'effectiveat1' => $effectiveat,
            'effectiveat2' => $effectiveat,
        ];
        $where = [
            'f.status = :approvedfw',
            'i.status = :approveditem',
            'v.status = :approvedversion',
            'v.effectivefrom <= :effectiveat1',
            '(v.effectiveto IS NULL OR v.effectiveto > :effectiveat2)',
        ];
        $query = trim(clean_param($query, PARAM_TEXT));
        if ($query !== '') {
            $params['querycode'] = '%' . $DB->sql_like_escape($query) . '%';
            $params['querystatement'] = '%' . $DB->sql_like_escape($query) . '%';
            $where[] = '(' . $DB->sql_like('i.code', ':querycode', false)
                . ' OR ' . $DB->sql_like('v.statement', ':querystatement', false) . ')';
        }
        self::add_context_scope($context, $effectiveat, $where, $params);
        $sql = "SELECT v.id, i.uuid, i.code, f.uuid AS frameworkuuid, f.code AS frameworkcode,
                       v.uuid AS versionuuid, v.version, v.statement, v.shortstatement,
                       v.bloomlevel, v.effectivefrom, v.effectiveto
                  FROM {local_outcomemap_itemver} v
                  JOIN {local_outcomemap_item} i ON i.id = v.itemid
                  JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
                 WHERE " . implode(' AND ', $where) . '
              ORDER BY f.code, i.code, v.version DESC';
        $records = $DB->get_records_sql($sql, $params, 0, $limit);
        $results = [];
        foreach ($records as $record) {
            $results[] = new outcome(
                $record->uuid,
                $record->code,
                $record->frameworkuuid,
                $record->frameworkcode,
                $record->versionuuid,
                (int) $record->version,
                $record->statement,
                $record->shortstatement,
                $record->bloomlevel,
                (int) $record->effectivefrom,
                $record->effectiveto === null ? null : (int) $record->effectiveto,
            );
        }
        return $results;
    }

    /** Add owner scoping for non-system contexts. */
    private static function add_context_scope(\context $context, int $effectiveat, array &$where, array &$params): void {
        global $DB;
        if ($context->contextlevel === CONTEXT_SYSTEM) {
            return;
        }
        if ($context->contextlevel === CONTEXT_COURSE) {
            $moodlecourseid = (int) $context->instanceid;
        } else if ($context->contextlevel === CONTEXT_MODULE) {
            $moodlecourseid = (int) $DB->get_field('course_modules', 'course', ['id' => $context->instanceid], MUST_EXIST);
        } else {
            throw new validation_exception('invalidfield', 'context', 'course or system context required');
        }
        $courseinstance = $DB->get_record('local_outcomemap_cinst', [
            'moodlecourseid' => $moodlecourseid,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
        ], 'id,courseid', IGNORE_MULTIPLE);
        $scope = ['f.ownertype = :institution'];
        $params['institution'] = 'institution';
        if ($courseinstance) {
            $params['catalogtype'] = 'catalog_course';
            $params['catalogid'] = $courseinstance->courseid;
            $scope[] = '(f.ownertype = :catalogtype AND f.ownerid = :catalogid)';
            $sql = 'SELECT programid FROM {local_outcomemap_progcourse}
                     WHERE courseid = :courseid AND status = :status
                       AND effectivefrom <= :at1 AND (effectiveto IS NULL OR effectiveto > :at2)';
            $programids = $DB->get_fieldset_sql($sql, [
                'courseid' => $courseinstance->courseid,
                'status' => workflow::APPROVED,
                'at1' => $effectiveat,
                'at2' => $effectiveat,
            ]);
            if ($programids) {
                [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $programids), SQL_PARAMS_NAMED, 'program');
                $params['programtype'] = 'program';
                $params += $inparams;
                $scope[] = '(f.ownertype = :programtype AND f.ownerid ' . $insql . ')';
            }
        }
        $where[] = '(' . implode(' OR ', $scope) . ')';
    }
}
