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
 * Learning Outcome Mapping plugin component.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Transactional service for stable catalog courses.
 */
final class catalog_course_service extends base_service {
    /**
     * Database table name.
     */
    private const TABLE = 'local_outcomemap_course';

    /**
     * Create a draft catalog course.
     *
     * @param array $data Data.
     */
    public static function create(array $data): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $now = time();
        $record = (object) [
            'uuid' => uuid::normalize_or_generate($data['uuid'] ?? null),
            'code' => input::required_text($data['code'] ?? '', 'code', 100),
            'name' => input::required_text($data['name'] ?? '', 'name', 255),
            'description' => input::optional_multiline($data['description'] ?? null),
            'siskey' => input::optional_text($data['siskey'] ?? null, 'siskey', 255),
            'status' => workflow::DRAFT,
            'createdby' => $actorid,
            'modifiedby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        self::require_unique($record->uuid, $record->code);
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record(self::TABLE, $record);
            $record->id = $id;
            audit_writer::write(
                'create',
                'catalog_course',
                $id,
                $record->uuid,
                null,
                $record,
                null,
                \context_system::instance(),
                $actorid
            );
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Update a draft catalog course.
     *
     * @param int $id Id.
     * @param array $data Data.
     */
    public static function update(int $id, array $data): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $before = self::get_required(self::TABLE, $id, 'catalog_course');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'catalog_course', $id);
        }
        $after = clone $before;
        $after->code = input::required_text($data['code'] ?? $before->code, 'code', 100);
        $after->name = input::required_text($data['name'] ?? $before->name, 'name', 255);
        $after->description = input::optional_multiline($data['description'] ?? $before->description);
        $after->siskey = input::optional_text($data['siskey'] ?? $before->siskey, 'siskey', 255);
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        self::require_unique($after->uuid, $after->code, $id);
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                'update',
                'catalog_course',
                $id,
                $after->uuid,
                $before,
                $after,
                $data['reason'] ?? null,
                \context_system::instance(),
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Submits the record for review.
     *
     * @param int $id Id.
     * @param ?string $reason Reason.
     */
    public static function submit_for_review(int $id, ?string $reason = null): void {
        self::change_status(
            $id,
            workflow::DRAFT,
            workflow::NEEDS_REVIEW,
            'submit_review',
            $reason,
            'local/outcomemap:managecatalogcourses',
            false
        );
    }

    /**
     * Approves the record.
     *
     * @param int $id Id.
     * @param ?string $reason Reason.
     */
    public static function approve(int $id, ?string $reason = null): void {
        $capability = workflow::requires_independent_approval()
            ? 'local/outcomemap:approve'
            : 'local/outcomemap:managecatalogcourses';
        self::change_status(
            $id,
            workflow::NEEDS_REVIEW,
            workflow::APPROVED,
            'approve',
            $reason,
            $capability,
            true
        );
    }

    /**
     * Retires the record.
     *
     * @param int $id Id.
     * @param string $reason Reason.
     */
    public static function retire(int $id, string $reason): void {
        global $DB;
        $actorid = self::require_system('local/outcomemap:managecatalogcourses');
        $before = self::get_required(self::TABLE, $id, 'catalog_course');
        if ($before->status === workflow::RETIRED) {
            return;
        }
        $after = clone $before;
        $after->status = workflow::RETIRED;
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                'retire',
                'catalog_course',
                $id,
                $after->uuid,
                $before,
                $after,
                $reason,
                \context_system::instance(),
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Lists all records.
     */
    public static function list_all(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        return $DB->get_records(self::TABLE, null, 'code ASC');
    }

    /**
     * Return catalog courses with their governed outcome and association counts.
     *
     * Counts are loaded in a fixed number of queries so presentation code never
     * queries per course. Retired frameworks, outcomes, and associations are
     * excluded because they no longer describe what the catalog course claims.
     *
     * The course-level and unit-level split follows the same ULO code-suffix
     * convention the outcome hierarchy uses. It is resolved in PHP rather than
     * with a case-insensitive LIKE, which is not portable across databases.
     *
     * @return \stdClass[] Catalog courses keyed by id, ordered by code.
     */
    public static function list_with_summary(): array {
        global $DB;
        self::require_system('local/outcomemap:viewdefinitions');
        $courses = $DB->get_records(self::TABLE, null, 'code ASC');
        if (!$courses) {
            return [];
        }
        foreach ($courses as $course) {
            $course->frameworkcount = 0;
            $course->courseoutcomecount = 0;
            $course->unitoutcomecount = 0;
            $course->instancecount = 0;
            $course->confirmedinstancecount = 0;
        }
        $frameworks = $DB->get_records_sql(
            "SELECT fw.id, fw.ownerid, fw.code,
                    (SELECT COUNT(1)
                       FROM {local_outcomemap_item} item
                      WHERE item.frameworkid = fw.id
                        AND item.status <> :itemretired) AS outcomecount
               FROM {local_outcomemap_fw} fw
              WHERE fw.ownertype = :ownertype
                AND fw.status <> :fwretired",
            [
                'itemretired' => workflow::RETIRED,
                'ownertype' => framework_service::OWNER_COURSE,
                'fwretired' => workflow::RETIRED,
            ]
        );
        foreach ($frameworks as $framework) {
            $ownerid = (int) $framework->ownerid;
            if (!isset($courses[$ownerid])) {
                continue;
            }
            $courses[$ownerid]->frameworkcount++;
            $field = preg_match('/ULO$/i', $framework->code) ? 'unitoutcomecount' : 'courseoutcomecount';
            $courses[$ownerid]->{$field} += (int) $framework->outcomecount;
        }
        $instances = $DB->get_records_sql(
            "SELECT ci.courseid, COUNT(1) AS instancecount, SUM(ci.confirmed) AS confirmedcount
               FROM {local_outcomemap_cinst} ci
              WHERE ci.status <> :retired
           GROUP BY ci.courseid",
            ['retired' => workflow::RETIRED]
        );
        foreach ($instances as $courseid => $counts) {
            if (!isset($courses[$courseid])) {
                continue;
            }
            $courses[$courseid]->instancecount = (int) $counts->instancecount;
            $courses[$courseid]->confirmedinstancecount = (int) $counts->confirmedcount;
        }
        return $courses;
    }

    /**
     * Changes the record workflow status.
     *
     * @param int $id Id.
     * @param string $required Required.
     * @param string $status Status.
     * @param string $action Action.
     * @param ?string $reason Reason.
     * @param string $capability Capability.
     * @param bool $separateapprover Separateapprover.
     */
    private static function change_status(
        int $id,
        string $required,
        string $status,
        string $action,
        ?string $reason,
        string $capability,
        bool $separateapprover
    ): void {
        global $DB;
        $actorid = self::require_system($capability);
        $before = self::get_required(self::TABLE, $id, 'catalog_course');
        if ($before->status !== $required) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':' . $status);
        }
        if ($separateapprover) {
            workflow::require_approver_separation((int) $before->createdby, $actorid);
        }
        $after = clone $before;
        $after->status = $status;
        $after->modifiedby = $actorid;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                $action,
                'catalog_course',
                $id,
                $after->uuid,
                $before,
                $after,
                $reason,
                \context_system::instance(),
                $actorid
            );
            if ($status === workflow::NEEDS_REVIEW && !workflow::requires_independent_approval()) {
                self::approve($id, $reason);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Requires unique record identifiers.
     *
     * @param string $uuidvalue Uuidvalue.
     * @param string $code Code.
     * @param int $excludeid Excludeid.
     */
    private static function require_unique(string $uuidvalue, string $code, int $excludeid = 0): void {
        global $DB;
        if (
            $DB->record_exists_select(
                self::TABLE,
                'uuid = :uuid AND id <> :id',
                ['uuid' => $uuidvalue, 'id' => $excludeid]
            )
        ) {
            throw new validation_exception('duplicateuuid', 'uuid', $uuidvalue);
        }
        if (
            $DB->record_exists_select(
                self::TABLE,
                'code = :code AND id <> :id',
                ['code' => $code, 'id' => $excludeid]
            )
        ) {
            throw new validation_exception('duplicatecode', 'code', $code);
        }
    }
}
