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
 * Course content mapping service.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\decimal;
use local_outcomemap\local\effective_dates;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Governed mappings from course content to exact outcome versions.
 */
final class content_mapping_service extends base_service {
    /** Course-module mapping target. */
    public const TARGET_MODULE = 'course_module';

    /** Course-section mapping target. */
    public const TARGET_SECTION = 'course_section';

    /** Teaching mapping role. */
    public const ROLE_TEACHES = 'teaches';

    /** Practice mapping role. */
    public const ROLE_PRACTICES = 'practices';

    /** Assessment mapping role. */
    public const ROLE_ASSESSES = 'assesses';

    /** Remediation mapping role. */
    public const ROLE_REMEDIATES = 'remediates';

    /** Alignment-only mapping role. */
    public const ROLE_ALIGNMENT_ONLY = 'alignment_only';

    /** Supported mapping roles. */
    public const ROLES = [
        self::ROLE_TEACHES,
        self::ROLE_PRACTICES,
        self::ROLE_ASSESSES,
        self::ROLE_REMEDIATES,
        self::ROLE_ALIGNMENT_ONLY,
    ];

    /** Mapping target definitions keyed by target type. */
    private const TABLES = [
        self::TARGET_MODULE => ['local_outcomemap_cmmap', 'cmid'],
        self::TARGET_SECTION => ['local_outcomemap_secmap', 'sectionid'],
    ];

    /**
     * Create a draft course-module mapping.
     *
     * @param array $data Mapping data.
     * @return int The new mapping record ID.
     */
    public static function create_course_module(array $data): int {
        return self::create(self::TARGET_MODULE, $data);
    }

    /**
     * Create a draft course-section mapping.
     *
     * @param array $data Mapping data.
     * @return int The new mapping record ID.
     */
    public static function create_section(array $data): int {
        return self::create(self::TARGET_SECTION, $data);
    }

    /**
     * Update a draft mapping.
     *
     * @param string $targettype Mapping target type.
     * @param int $id Mapping record ID.
     * @param array $data Updated mapping data.
     * @return void
     */
    public static function update_draft(string $targettype, int $id, array $data): void {
        global $DB;
        [$table, $targetfield] = self::table_definition($targettype);
        $before = self::get_required($table, $id, 'content_mapping');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'content_mapping', $id);
        }
        $merged = array_merge((array) $before, $data);
        $after = self::build_record($targettype, $merged, $before->mappinguuid, (int) $before->version);
        $actorid = self::require_mapping_capabilities($targettype, $after);
        $after->id = $id;
        $after->createdby = $before->createdby;
        $after->timecreated = $before->timecreated;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record($table, $after);
            audit_writer::write(
                'update',
                'content_mapping',
                $id,
                $after->mappinguuid,
                $before,
                $after,
                $data['reason'] ?? null,
                self::mapping_context($targettype, (int) $after->{$targetfield}),
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Create the next draft version of an approved mapping.
     *
     * @param string $targettype Mapping target type.
     * @param int $id Approved mapping record ID.
     * @param array $data New version data.
     * @return int The new mapping record ID.
     */
    public static function create_version(string $targettype, int $id, array $data): int {
        global $DB;
        [$table, $targetfield] = self::table_definition($targettype);
        $previous = self::get_required($table, $id, 'content_mapping');
        if ($previous->status !== workflow::APPROVED) {
            throw new validation_exception('invalidtransition', 'status', $previous->status . ':new_version');
        }
        $data['cinstid'] = $previous->cinstid;
        $data[$targetfield] = $previous->{$targetfield};
        $data['itemverid'] = $previous->itemverid;
        $maxversion = (int) $DB->get_field_sql(
            "SELECT MAX(version) FROM {{$table}} WHERE mappinguuid = :mappinguuid",
            ['mappinguuid' => $previous->mappinguuid]
        );
        $record = self::build_record(
            $targettype,
            array_merge((array) $previous, $data),
            $previous->mappinguuid,
            $maxversion + 1
        );
        return self::insert($targettype, $record, 'create_version');
    }

    /**
     * Submit a draft mapping for review.
     *
     * @param string $targettype Mapping target type.
     * @param int $id Mapping record ID.
     * @param string|null $reason Optional submission reason.
     * @return void
     */
    public static function submit_for_review(string $targettype, int $id, ?string $reason = null): void {
        global $DB;
        [$table, $targetfield] = self::table_definition($targettype);
        $before = self::get_required($table, $id, 'content_mapping');
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':needs_review');
        }
        $actorid = self::require_mapping_capabilities($targettype, $before);
        self::validate_record($targettype, $before, true);
        $after = clone $before;
        $after->status = workflow::NEEDS_REVIEW;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record($table, $after);
            audit_writer::write(
                'submit_review',
                'content_mapping',
                $id,
                $after->mappinguuid,
                $before,
                $after,
                $reason,
                self::mapping_context($targettype, (int) $after->{$targetfield}),
                $actorid
            );
            if (!workflow::requires_independent_approval()) {
                self::approve($targettype, $id, $reason);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Approve a mapping after rechecking target ownership and exact outcome version.
     *
     * @param string $targettype Mapping target type.
     * @param int $id Mapping record ID.
     * @param string|null $reason Optional approval reason.
     * @return void
     */
    public static function approve(string $targettype, int $id, ?string $reason = null): void {
        global $DB, $USER;
        [$table, $targetfield] = self::table_definition($targettype);
        $before = self::get_required($table, $id, 'content_mapping');
        if ($before->status !== workflow::NEEDS_REVIEW) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':approved');
        }
        self::require_mapping_capabilities($targettype, $before, true);
        $actorid = (int) $USER->id;
        workflow::require_approver_separation((int) $before->createdby, $actorid);
        self::validate_record($targettype, $before, true);
        self::require_no_approved_overlap($table, $before);
        self::require_no_duplicate_scope($table, $targetfield, $before);
        $after = clone $before;
        $after->status = workflow::APPROVED;
        $after->approvedby = $actorid;
        $after->approvedat = time();
        $after->timemodified = $after->approvedat;
        $transaction = $DB->start_delegated_transaction();
        try {
            self::validate_record($targettype, $before, true);
            self::require_no_approved_overlap($table, $before);
            self::require_no_duplicate_scope($table, $targetfield, $before);
            $DB->update_record($table, $after);
            audit_writer::write(
                'approve',
                'content_mapping',
                $id,
                $after->mappinguuid,
                $before,
                $after,
                $reason,
                self::mapping_context($targettype, (int) $after->{$targetfield}),
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Load one mapping for an editor without accepting arbitrary table names.
     *
     * @param string $targettype Mapping target type.
     * @param int $id Mapping record ID.
     * @return \stdClass The mapping record with its target type.
     */
    public static function get(string $targettype, int $id): \stdClass {
        [$table] = self::table_definition($targettype);
        $record = self::get_required($table, $id, 'content_mapping');
        $record->targettype = $targettype;
        return $record;
    }

    /**
     * Return all mappings for a course in two bulk queries.
     *
     * @param int $courseid Moodle course ID.
     * @return array Course-module and course-section mapping records.
     */
    public static function list_for_course(int $courseid): array {
        global $DB;
        $context = \context_course::instance($courseid, MUST_EXIST);
        require_capability('local/outcomemap:viewdefinitions', $context);
        $fields = 'm.*, ci.periodcode, i.code AS outcomecode, v.version AS outcomeversion, '
            . 'v.statement AS outcomestatement, f.code AS frameworkcode';
        $joins = ' JOIN {local_outcomemap_cinst} ci ON ci.id = m.cinstid'
            . ' JOIN {local_outcomemap_itemver} v ON v.id = m.itemverid'
            . ' JOIN {local_outcomemap_item} i ON i.id = v.itemid'
            . ' JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid';
        $modules = $DB->get_records_sql(
            "SELECT $fields, cm.instance, md.name AS modulename
               FROM {local_outcomemap_cmmap} m $joins
               JOIN {course_modules} cm ON cm.id = m.cmid
               JOIN {modules} md ON md.id = cm.module
              WHERE ci.moodlecourseid = :courseid
           ORDER BY f.code, i.code, m.priority DESC, m.id",
            ['courseid' => $courseid]
        );
        $sections = $DB->get_records_sql(
            "SELECT $fields, cs.section AS sectionnumber, cs.name AS sectionname
               FROM {local_outcomemap_secmap} m $joins
               JOIN {course_sections} cs ON cs.id = m.sectionid
              WHERE ci.moodlecourseid = :courseid
           ORDER BY f.code, i.code, m.priority DESC, m.id",
            ['courseid' => $courseid]
        );
        return ['modules' => $modules, 'sections' => $sections];
    }

    /**
     * Return form options in one course-scoped service call.
     *
     * @param int $courseid Moodle course ID.
     * @return array Course instance, outcome, module, and section options.
     */
    public static function editor_options(int $courseid): array {
        global $DB;
        $context = \context_course::instance($courseid, MUST_EXIST);
        require_capability('local/outcomemap:viewdefinitions', $context);
        $instances = $DB->get_records('local_outcomemap_cinst', [
            'moodlecourseid' => $courseid,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
        ], 'periodcode ASC', 'id,periodcode');
        $instanceoptions = [];
        foreach ($instances as $instance) {
            $instanceoptions[$instance->id] = $instance->periodcode;
        }
        $outcomeoptions = self::outcome_options();
        $modinfo = get_fast_modinfo($courseid);
        $moduleoptions = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->deletioninprogress) {
                $moduleoptions[$cm->id] = $cm->get_formatted_name();
            }
        }
        $sectionoptions = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $name = get_section_name($courseid, $section->section);
            $sectionoptions[$section->id] = $name;
        }
        return [
            'instances' => $instanceoptions,
            'outcomes' => $outcomeoptions,
            'modules' => $moduleoptions,
            'sections' => $sectionoptions,
        ];
    }

    /**
     * Return options used by the standard course-module form callback.
     *
     * @param int $courseid Moodle course ID.
     * @return array Course instance and outcome options, or an empty array when unavailable.
     */
    public static function module_form_options(int $courseid): array {
        global $DB;
        $context = \context_course::instance($courseid, MUST_EXIST);
        if (
            !has_capability('local/outcomemap:mapactivities', $context)
                || !has_capability('moodle/course:manageactivities', $context)
        ) {
            return [];
        }
        $instances = $DB->get_records('local_outcomemap_cinst', [
            'moodlecourseid' => $courseid,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
        ], 'periodcode ASC', 'id,periodcode');
        if (!$instances) {
            return [];
        }
        $instanceoptions = [];
        foreach ($instances as $instance) {
            $instanceoptions[$instance->id] = $instance->periodcode;
        }
        return ['instances' => $instanceoptions, 'outcomes' => self::outcome_options()];
    }

    /**
     * Validate the extra standard module-form fields before a module ID exists.
     *
     * @param int $courseid Moodle course ID.
     * @param array $data Submitted module form data.
     * @return array Validation errors keyed by form field.
     */
    public static function validate_module_form_data(int $courseid, array $data): array {
        global $DB;
        if (empty($data['outcomemap_itemverid'])) {
            return [];
        }
        try {
            $cinstid = input::positive_int($data['outcomemap_cinstid'] ?? 0, 'outcomemap_cinstid');
            $cinst = self::get_required('local_outcomemap_cinst', $cinstid, 'course_instance');
            if (
                (int) $cinst->moodlecourseid !== $courseid || $cinst->status !== workflow::APPROVED
                    || !(int) $cinst->confirmed
            ) {
                throw new validation_exception('targetcoursemismatch', 'outcomemap_cinstid');
            }
            self::require_approved_item_version(input::positive_int(
                $data['outcomemap_itemverid'],
                'outcomemap_itemverid'
            ));
            self::validate_role_weight($data['outcomemap_role'] ?? '', $data['outcomemap_weight'] ?? null);
        } catch (validation_exception $e) {
            $field = match ($e->errorcode) {
                'targetcoursemismatch', 'courseinstancenotconfirmed' => 'outcomemap_cinstid',
                'invalidmappingrole' => 'outcomemap_role',
                'invaliddecimal' => 'outcomemap_weight',
                default => 'outcomemap_itemverid',
            };
            return [$field => $e->getMessage()];
        }
        return [];
    }

    /**
     * Persist one explicit mapping selected in a standard module form.
     *
     * @param int $cmid Course-module ID.
     * @param array $data Submitted module form data.
     * @return int|null The mapping record ID, or null when no outcome was selected.
     */
    public static function save_module_form_mapping(int $cmid, array $data): ?int {
        global $DB;
        if (empty($data['outcomemap_itemverid'])) {
            return null;
        }
        $mappingdata = [
            'cinstid' => $data['outcomemap_cinstid'] ?? 0,
            'cmid' => $cmid,
            'itemverid' => $data['outcomemap_itemverid'],
            'role' => $data['outcomemap_role'] ?? '',
            'weight' => $data['outcomemap_weight'] ?? null,
            'priority' => $data['outcomemap_priority'] ?? 0,
            'notes' => $data['outcomemap_notes'] ?? null,
            'effectivefrom' => $data['outcomemap_effectivefrom'] ?? time(),
            'effectiveto' => $data['outcomemap_effectiveto'] ?? null,
        ];
        $mappingdata['weight'] = self::validate_role_weight($mappingdata['role'], $mappingdata['weight']);
        $params = [
            'cinstid' => input::positive_int($mappingdata['cinstid'], 'cinstid'),
            'cmid' => $cmid,
            'itemverid' => input::positive_int($mappingdata['itemverid'], 'itemverid'),
        ];
        $existing = $DB->get_records('local_outcomemap_cmmap', $params, 'version DESC', '*', 0, 1);
        if (!$existing) {
            return self::create_course_module($mappingdata);
        }
        $current = reset($existing);
        $same = $current->role === $mappingdata['role']
            && (string) ($current->weight ?? '') === (string) ($mappingdata['weight'] ?? '')
            && (int) $current->priority === (int) $mappingdata['priority']
            && (string) ($current->notes ?? '') === trim((string) ($mappingdata['notes'] ?? ''));
        if ($same) {
            return (int) $current->id;
        }
        if ($current->status === workflow::DRAFT) {
            self::update_draft(self::TARGET_MODULE, (int) $current->id, $mappingdata);
            return (int) $current->id;
        }
        if ($current->status === workflow::APPROVED) {
            return self::create_version(self::TARGET_MODULE, (int) $current->id, $mappingdata);
        }
        throw new validation_exception('mappingunderreview', 'content_mapping', $current->id);
    }

    /**
     * Return public exact-version options for course mapping forms.
     *
     * @return array Outcome labels keyed by outcome-version ID.
     */
    public static function outcome_options(): array {
        global $DB;
        $now = time();
        $sql = "SELECT v.id, f.code AS frameworkcode, i.code, v.version, v.shortstatement, v.statement
                  FROM {local_outcomemap_itemver} v
                  JOIN {local_outcomemap_item} i ON i.id = v.itemid
                  JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
                 WHERE v.status = :vstatus AND i.status = :istatus AND f.status = :fstatus
                   AND v.effectivefrom <= :at1 AND (v.effectiveto IS NULL OR v.effectiveto > :at2)
              ORDER BY f.code, i.code, v.version DESC";
        $records = $DB->get_records_sql($sql, [
            'vstatus' => workflow::APPROVED,
            'istatus' => workflow::APPROVED,
            'fstatus' => workflow::APPROVED,
            'at1' => $now,
            'at2' => $now,
        ]);
        $options = [];
        foreach ($records as $record) {
            $label = $record->frameworkcode . '.' . $record->code . ' v' . $record->version;
            $label .= ' — ' . ($record->shortstatement ?: $record->statement);
            $options[$record->id] = $label;
        }
        return $options;
    }

    /**
     * Create a version-one draft mapping for a target type.
     *
     * @param string $targettype Mapping target type.
     * @param array $data Mapping data.
     * @return int The new mapping record ID.
     */
    private static function create(string $targettype, array $data): int {
        $record = self::build_record(
            $targettype,
            $data,
            uuid::normalize_or_generate($data['mappinguuid'] ?? null),
            1
        );
        return self::insert($targettype, $record, 'create');
    }

    /**
     * Insert a mapping and write its audit event.
     *
     * @param string $targettype Mapping target type.
     * @param \stdClass $record Mapping record to insert.
     * @param string $action Audit action.
     * @return int The new mapping record ID.
     */
    private static function insert(string $targettype, \stdClass $record, string $action): int {
        global $DB;
        [$table, $targetfield] = self::table_definition($targettype);
        $actorid = self::require_mapping_capabilities($targettype, $record);
        $record->createdby = $actorid;
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record($table, $record);
            $record->id = $id;
            audit_writer::write(
                $action,
                'content_mapping',
                $id,
                $record->mappinguuid,
                null,
                $record,
                $record->notes,
                self::mapping_context($targettype, (int) $record->{$targetfield}),
                $actorid
            );
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Build and validate a draft mapping record.
     *
     * @param string $targettype Mapping target type.
     * @param array $data Mapping data.
     * @param string $mappinguuid Stable mapping UUID.
     * @param int $version Mapping version number.
     * @return \stdClass The validated draft mapping record.
     */
    private static function build_record(string $targettype, array $data, string $mappinguuid, int $version): \stdClass {
        [, $targetfield] = self::table_definition($targettype);
        $now = time();
        $record = (object) [
            'mappinguuid' => uuid::normalize($mappinguuid),
            'version' => $version,
            'cinstid' => input::positive_int($data['cinstid'] ?? 0, 'cinstid'),
            $targetfield => input::positive_int($data[$targetfield] ?? 0, $targetfield),
            'itemverid' => input::positive_int($data['itemverid'] ?? 0, 'itemverid'),
            'role' => input::required_text($data['role'] ?? '', 'role', 20),
            'weight' => null,
            'priority' => self::nonnegative_int($data['priority'] ?? 0, 'priority'),
            'notes' => input::optional_multiline($data['notes'] ?? null),
            'status' => workflow::DRAFT,
            'effectivefrom' => input::positive_int($data['effectivefrom'] ?? $now, 'effectivefrom'),
            'effectiveto' => input::optional_timestamp($data['effectiveto'] ?? null, 'effectiveto'),
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
        ];
        $record->weight = self::validate_role_weight($record->role, $data['weight'] ?? null);
        effective_dates::validate(
            (int) $record->effectivefrom,
            $record->effectiveto === null ? null : (int) $record->effectiveto
        );
        self::validate_record($targettype, $record, true);
        return $record;
    }

    /**
     * Validate the references, dates, role, and weight of a mapping record.
     *
     * @param string $targettype Mapping target type.
     * @param \stdClass $record Mapping record.
     * @param bool $requireconfirmed Whether the course instance must be approved and confirmed.
     * @return void
     */
    private static function validate_record(string $targettype, \stdClass $record, bool $requireconfirmed): void {
        global $DB;
        [, $targetfield] = self::table_definition($targettype);
        $cinst = self::get_required('local_outcomemap_cinst', (int) $record->cinstid, 'course_instance');
        if ($requireconfirmed && ($cinst->status !== workflow::APPROVED || !(int) $cinst->confirmed)) {
            throw new validation_exception('courseinstancenotconfirmed', 'cinstid', $record->cinstid);
        }
        if ($targettype === self::TARGET_MODULE) {
            $targetcourseid = $DB->get_field('course_modules', 'course', ['id' => $record->{$targetfield}]);
        } else {
            $targetcourseid = $DB->get_field('course_sections', 'course', ['id' => $record->{$targetfield}]);
        }
        if (!$targetcourseid || (int) $targetcourseid !== (int) $cinst->moodlecourseid) {
            throw new validation_exception('targetcoursemismatch', $targetfield, $record->{$targetfield});
        }
        $itemversion = self::require_approved_item_version((int) $record->itemverid);
        if (
            (int) $record->effectivefrom < (int) $itemversion->effectivefrom
                || ($itemversion->effectiveto !== null
                    && ($record->effectiveto === null || (int) $record->effectiveto > (int) $itemversion->effectiveto))
        ) {
            throw new validation_exception('mappingoutsideoutcomeversion', 'effectivefrom');
        }
        self::validate_role_weight($record->role, $record->weight);
    }

    /**
     * Require an approved outcome version.
     *
     * @param int $itemverid Outcome-version ID.
     * @return \stdClass The approved outcome-version record.
     */
    private static function require_approved_item_version(int $itemverid): \stdClass {
        $version = self::get_required('local_outcomemap_itemver', $itemverid, 'outcome_version');
        if ($version->status !== workflow::APPROVED) {
            throw new validation_exception('outcomeversionnotapproved', 'itemverid', $itemverid);
        }
        return $version;
    }

    /**
     * Validate a mapping role and its optional weight.
     *
     * @param string $role Mapping role.
     * @param mixed $weight Optional mapping weight.
     * @return string|null The normalized weight, or null when no weight is supplied.
     */
    private static function validate_role_weight(string $role, $weight): ?string {
        if (!in_array($role, self::ROLES, true)) {
            throw new validation_exception('invalidmappingrole', 'role', $role);
        }
        if ($weight === null || trim((string) $weight) === '') {
            return null;
        }
        return decimal::positive($weight, 'weight');
    }

    /**
     * Require the capabilities needed to manage a mapping.
     *
     * @param string $targettype Mapping target type.
     * @param \stdClass $record Mapping record.
     * @param bool $approval Whether to require approval capability.
     * @return int The acting user ID.
     */
    private static function require_mapping_capabilities(
        string $targettype,
        \stdClass $record,
        bool $approval = false
    ): int {
        global $USER;
        [, $targetfield] = self::table_definition($targettype);
        $context = self::mapping_context($targettype, (int) $record->{$targetfield});
        if ($targettype === self::TARGET_MODULE) {
            require_capability('local/outcomemap:mapactivities', $context);
            require_capability('moodle/course:manageactivities', $context);
        } else {
            require_capability('local/outcomemap:mapcourse', $context);
            require_capability('moodle/course:update', $context);
        }
        if ($approval && workflow::requires_independent_approval()) {
            require_capability('local/outcomemap:approve', $context);
        }
        return (int) $USER->id;
    }

    /**
     * Resolve the Moodle context for a mapping target.
     *
     * @param string $targettype Mapping target type.
     * @param int $targetid Mapping target ID.
     * @return \context The module or course context for the target.
     */
    private static function mapping_context(string $targettype, int $targetid): \context {
        if ($targettype === self::TARGET_MODULE) {
            return \context_module::instance($targetid, MUST_EXIST);
        }
        global $DB;
        $courseid = $DB->get_field('course_sections', 'course', ['id' => $targetid], MUST_EXIST);
        return \context_course::instance((int) $courseid, MUST_EXIST);
    }

    /**
     * Require that a candidate does not overlap an approved version of the same mapping.
     *
     * @param string $table Mapping table name selected from the target definition.
     * @param \stdClass $candidate Candidate mapping record.
     * @return void
     */
    private static function require_no_approved_overlap(string $table, \stdClass $candidate): void {
        global $DB;
        [$overlapsql, $params] = effective_dates::overlap_sql(
            '',
            (int) $candidate->effectivefrom,
            $candidate->effectiveto === null ? null : (int) $candidate->effectiveto,
            'mapping'
        );
        $params += [
            'mappinguuid' => $candidate->mappinguuid,
            'status' => workflow::APPROVED,
            'id' => $candidate->id,
        ];
        if (
            $DB->record_exists_select(
                $table,
                'mappinguuid = :mappinguuid AND status = :status AND id <> :id AND ' . $overlapsql,
                $params
            )
        ) {
            throw new validation_exception('effectiverangeoverlap', 'effectivefrom');
        }
    }

    /**
     * Require that a candidate does not duplicate another approved mapping in the same scope.
     *
     * @param string $table Mapping table name selected from the target definition.
     * @param string $targetfield Target ID field selected from the target definition.
     * @param \stdClass $candidate Candidate mapping record.
     * @return void
     */
    private static function require_no_duplicate_scope(
        string $table,
        string $targetfield,
        \stdClass $candidate
    ): void {
        global $DB;
        [$overlapsql, $params] = effective_dates::overlap_sql(
            '',
            (int) $candidate->effectivefrom,
            $candidate->effectiveto === null ? null : (int) $candidate->effectiveto,
            'scope'
        );
        $params += [
            'cinstid' => $candidate->cinstid,
            'targetid' => $candidate->{$targetfield},
            'itemverid' => $candidate->itemverid,
            'role' => $candidate->role,
            'status' => workflow::APPROVED,
            'id' => $candidate->id,
            'mappinguuid' => $candidate->mappinguuid,
        ];
        $select = "cinstid = :cinstid AND $targetfield = :targetid AND itemverid = :itemverid"
            . ' AND role = :role AND status = :status AND id <> :id AND mappinguuid <> :mappinguuid AND '
            . $overlapsql;
        if ($DB->record_exists_select($table, $select, $params)) {
            throw new validation_exception('duplicatemapping', 'itemverid');
        }
    }

    /**
     * Return the table and target field for a supported mapping target type.
     *
     * @param string $targettype Mapping target type.
     * @return array The mapping table and target field pair.
     */
    private static function table_definition(string $targettype): array {
        if (!isset(self::TABLES[$targettype])) {
            throw new validation_exception('invalidtargettype', 'targettype', $targettype);
        }
        return self::TABLES[$targettype];
    }

    /**
     * Validate and normalize a nonnegative integer.
     *
     * @param mixed $value Value to validate.
     * @param string $field Field name used in validation errors.
     * @return int The normalized nonnegative integer.
     */
    private static function nonnegative_int($value, string $field): int {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0) {
            throw new validation_exception('invalidfield', $field, 'non-negative integer required');
        }
        return (int) $value;
    }
}
