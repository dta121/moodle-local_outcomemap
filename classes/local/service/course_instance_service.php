<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/** Transactional service for Moodle course-instance associations. */
final class course_instance_service extends base_service {
    private const TABLE = 'local_outcomemap_cinst';

    /** Create an unconfirmed draft course-instance association. */
    public static function create(array $data): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $courseid = input::positive_int($data['courseid'] ?? 0, 'courseid');
        self::get_required('local_outcomemap_course', $courseid, 'catalog_course');
        $moodlecourseid = input::positive_int($data['moodlecourseid'] ?? 0, 'moodlecourseid');
        if (!$DB->record_exists('course', ['id' => $moodlecourseid])) {
            throw new validation_exception('moodlecoursenotfound', 'moodlecourseid', $moodlecourseid);
        }
        $periodcode = input::required_text($data['periodcode'] ?? '', 'periodcode', 100);
        if ($DB->record_exists(self::TABLE, ['moodlecourseid' => $moodlecourseid, 'periodcode' => $periodcode])) {
            throw new validation_exception('courseinstanceexists', 'periodcode', $periodcode);
        }
        $now = time();
        $record = (object) [
            'uuid' => uuid::normalize_or_generate($data['uuid'] ?? null),
            'courseid' => $courseid,
            'moodlecourseid' => $moodlecourseid,
            'periodcode' => $periodcode,
            'externalid' => input::optional_text($data['externalid'] ?? null, 'externalid', 255),
            'status' => workflow::DRAFT,
            'confirmed' => 0,
            'confirmedby' => null,
            'confirmedat' => null,
            'createdby' => $actorid,
            'modifiedby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        if ($DB->record_exists(self::TABLE, ['uuid' => $record->uuid])) {
            throw new validation_exception('duplicateuuid', 'uuid', $record->uuid);
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record(self::TABLE, $record);
            $record->id = $id;
            audit_writer::write('create', 'course_instance', $id, $record->uuid, null, $record, null,
                \context_course::instance($moodlecourseid), $actorid);
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Submit an association for independent confirmation. */
    public static function submit_for_review(int $id, ?string $reason = null): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $before = self::get_required(self::TABLE, $id, 'course_instance');
        if ($before->status !== workflow::DRAFT || (int) $before->confirmed === 1) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':needs_review');
        }
        $after = clone $before;
        $after->status = workflow::NEEDS_REVIEW;
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write('submit_review', 'course_instance', $id, $after->uuid, $before, $after, $reason,
                \context_course::instance($after->moodlecourseid), $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /** Confirm an association in its authoritative course context. */
    public static function confirm(int $id, ?string $reason = null): void {
        global $DB, $USER;
        $before = self::get_required(self::TABLE, $id, 'course_instance');
        $context = \context_course::instance($before->moodlecourseid);
        if (!has_capability('local/outcomemap:approve', $context)
                && !has_capability('local/outcomemap:managecatalogcourses', \context_system::instance())) {
            require_capability('local/outcomemap:approve', $context);
        }
        $actorid = (int) $USER->id;
        if ($before->status !== workflow::NEEDS_REVIEW || (int) $before->confirmed === 1) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':approved');
        }
        if ((int) $before->createdby === $actorid) {
            throw new validation_exception('creatorcannotapprove', 'createdby', $actorid);
        }
        if (!$DB->record_exists('course', ['id' => $before->moodlecourseid])) {
            throw new validation_exception('moodlecoursenotfound', 'moodlecourseid', $before->moodlecourseid);
        }
        $after = clone $before;
        $after->status = workflow::APPROVED;
        $after->confirmed = 1;
        $after->confirmedby = $actorid;
        $after->confirmedat = time();
        $after->modifiedby = $actorid;
        $after->timemodified = $after->confirmedat;
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write('confirm', 'course_instance', $id, $after->uuid, $before, $after, $reason,
                $context, $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    public static function list_all(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        $sql = "SELECT ci.*, c.code AS catalogcode, c.name AS catalogname, mc.fullname AS moodlename
                  FROM {local_outcomemap_cinst} ci
                  JOIN {local_outcomemap_course} c ON c.id = ci.courseid
                  JOIN {course} mc ON mc.id = ci.moodlecourseid
              ORDER BY ci.periodcode DESC, c.code ASC";
        return $DB->get_records_sql($sql);
    }
}
