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
 * Remediation recommendation service.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;
use local_outcomemap\api\outcome_search;
use local_outcomemap\local\audit_writer;
use local_outcomemap\local\effective_dates;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * * Governed remediation recommendations for exact outcome versions.
 */
final class remediation_service extends base_service {
    /**
     * Remediation mapping table.
     */
    private const TABLE = 'local_outcomemap_remed';

    /**
     * Course-module remediation target.
     */
    public const TARGET_MODULE = 'course_module';

    /**
     * Course-section remediation target.
     */
    public const TARGET_SECTION = 'course_section';

    /**
     * External URL remediation target.
     */
    public const TARGET_EXTERNAL = 'external_url';

    /**
     * Supported remediation target types.
     */
    public const TARGETS = [self::TARGET_MODULE, self::TARGET_SECTION, self::TARGET_EXTERNAL];

    /**
     * General review recommendation.
     */
    public const PURPOSE_REVIEW = 'review';

    /**
     * Practice activity recommendation.
     */
    public const PURPOSE_PRACTICE = 'practice';

    /**
     * Reassessment activity recommendation.
     */
    public const PURPOSE_REASSESSMENT = 'reassessment';

    /**
     * Supported student-facing recommendation purposes.
     */
    public const PURPOSES = [self::PURPOSE_REVIEW, self::PURPOSE_PRACTICE, self::PURPOSE_REASSESSMENT];

    /**
     * Create a version-one draft recommendation.
     *
     * @param array $data Recommendation data.
     * @return int The new recommendation record ID.
     */
    public static function create(array $data): int {
        $record = self::build_record($data, uuid::normalize_or_generate($data['mappinguuid'] ?? null), 1);
        return self::insert($record, 'create');
    }

    /**
     * Update a draft recommendation.
     *
     * @param int $id Recommendation record ID.
     * @param array $data Updated recommendation data.
     * @return void
     */
    public static function update_draft(int $id, array $data): void {
        global $DB;
        $before = self::get_required(self::TABLE, $id, 'remediation');
        self::require_capabilities($before);
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('approvedimmutable', 'remediation', $id);
        }
        $after = self::build_record(
            array_merge((array) $before, $data),
            $before->mappinguuid,
            (int) $before->version
        );
        $actorid = self::require_capabilities($after);
        $after->id = $id;
        $after->createdby = $before->createdby;
        $after->timecreated = $before->timecreated;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                'update',
                'remediation',
                $id,
                $after->mappinguuid,
                $before,
                $after,
                $data['reason'] ?? null,
                self::context_for($after),
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Create the next draft version without modifying approved history.
     *
     * @param int $id Approved recommendation record ID.
     * @param array $data New version data.
     * @return int The new recommendation record ID.
     */
    public static function create_version(int $id, array $data): int {
        global $DB;
        $previous = self::get_required(self::TABLE, $id, 'remediation');
        self::require_capabilities($previous);
        if ($previous->status !== workflow::APPROVED) {
            throw new validation_exception('invalidtransition', 'status', $previous->status . ':new_version');
        }
        $data['cinstid'] = $previous->cinstid;
        $data['itemverid'] = $previous->itemverid;
        $maxversion = (int) $DB->get_field_sql(
            'SELECT MAX(version) FROM {local_outcomemap_remed} WHERE mappinguuid = :mappinguuid',
            ['mappinguuid' => $previous->mappinguuid]
        );
        $record = self::build_record(
            array_merge((array) $previous, $data),
            $previous->mappinguuid,
            $maxversion + 1
        );
        return self::insert($record, 'create_version');
    }

    /**
     * Submit a draft recommendation for review.
     *
     * @param int $id Recommendation record ID.
     * @param string|null $reason Optional submission reason.
     * @return void
     */
    public static function submit_for_review(int $id, ?string $reason = null): void {
        global $DB;
        $before = self::get_required(self::TABLE, $id, 'remediation');
        $actorid = self::require_capabilities($before);
        if ($before->status !== workflow::DRAFT) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':needs_review');
        }
        self::validate_record($before);
        $after = clone $before;
        $after->status = workflow::NEEDS_REVIEW;
        $after->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                'submit_review',
                'remediation',
                $id,
                $after->mappinguuid,
                $before,
                $after,
                $reason,
                self::context_for($after),
                $actorid
            );
            if (!workflow::requires_independent_approval()) {
                self::approve($id, $reason);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Approve a recommendation after rechecking all references and ranges.
     *
     * @param int $id Recommendation record ID.
     * @param string|null $reason Optional approval reason.
     * @return void
     */
    public static function approve(int $id, ?string $reason = null): void {
        global $DB, $USER;
        $before = self::get_required(self::TABLE, $id, 'remediation');
        self::require_capabilities($before, true);
        if ($before->status !== workflow::NEEDS_REVIEW) {
            throw new validation_exception('invalidtransition', 'status', $before->status . ':approved');
        }
        $actorid = (int) $USER->id;
        workflow::require_approver_separation((int) $before->createdby, $actorid);
        self::validate_record($before);
        self::require_no_approved_overlap($before);
        $after = clone $before;
        $after->status = workflow::APPROVED;
        $after->approvedby = $actorid;
        $after->approvedat = time();
        $after->timemodified = $after->approvedat;
        $transaction = $DB->start_delegated_transaction();
        try {
            self::validate_record($before);
            self::require_no_approved_overlap($before);
            $DB->update_record(self::TABLE, $after);
            audit_writer::write(
                'approve',
                'remediation',
                $id,
                $after->mappinguuid,
                $before,
                $after,
                $reason,
                self::context_for($after),
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Get one recommendation.
     *
     * @param int $id Recommendation record ID.
     * @param int|null $expectedcourseid Optional course ID the caller is operating within.
     * @return \stdClass The recommendation record.
     */
    public static function get(int $id, ?int $expectedcourseid = null): \stdClass {

        $record = self::get_required(self::TABLE, $id, 'remediation');
        $context = self::context_for($record);
        require_capability('local/outcomemap:viewdefinitions', $context);
        if ($expectedcourseid !== null && (int) $context->instanceid !== $expectedcourseid) {
            throw new validation_exception('recordnotfound', 'remediation', $id);
        }
        return $record;
    }

    /**
     * Return all recommendations for a Moodle course in one query.
     *
     * @param int $courseid Moodle course ID.
     * @return array Recommendation records keyed by record ID.
     */
    public static function list_for_course(int $courseid): array {
        global $DB;
        $context = \context_course::instance($courseid, MUST_EXIST);
        require_capability('local/outcomemap:viewdefinitions', $context);
        $sql = "SELECT r.*, ci.periodcode, f.code AS frameworkcode, i.code AS outcomecode,
                       v.version AS outcomeversion, v.statement AS outcomestatement,
                       b.code AS bandcode, b.name AS bandname
                  FROM {local_outcomemap_remed} r
                  JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
                  JOIN {local_outcomemap_itemver} v ON v.id = r.itemverid
                  JOIN {local_outcomemap_item} i ON i.id = v.itemid
                  JOIN {local_outcomemap_fw} f ON f.id = i.frameworkid
             LEFT JOIN {local_outcomemap_band} b ON b.id = r.bandid
                 WHERE ci.moodlecourseid = :courseid
              ORDER BY f.code, i.code, r.priority DESC, r.sortorder, r.id";
        return $DB->get_records_sql($sql, ['courseid' => $courseid]);
    }

    /**
     * Return approved performance-band choices applicable to a Moodle course.
     *
     * @param int $courseid Moodle course ID.
     * @return array Band IDs to labels.
     */
    public static function band_options_for_course(int $courseid): array {
        global $DB;
        $context = \context_course::instance($courseid, MUST_EXIST);
        require_capability('local/outcomemap:viewdefinitions', $context);
        $instances = $DB->get_records('local_outcomemap_cinst', [
            'moodlecourseid' => $courseid, 'status' => workflow::APPROVED, 'confirmed' => 1,
        ], '', 'id, courseid');
        $cinstids = array_map('intval', array_keys($instances));
        $catalogids = [];
        foreach ($instances as $instance) {
            $catalogids[(int) $instance->courseid] = true;
        }
        $cmids = array_map('intval', $DB->get_fieldset_select(
            'course_modules',
            'id',
            'course = :courseid',
            ['courseid' => $courseid]
        ));
        $sql = "SELECT b.id, b.code, b.name AS bandname, p.name AS policyname,
                       p.scopetype, p.scopeid
                  FROM {local_outcomemap_band} b
                  JOIN {local_outcomemap_policy} p ON p.id = b.policyid
                 WHERE p.policytype = :policytype AND p.status = :status
              ORDER BY p.name, b.sortorder, b.id";
        $records = $DB->get_records_sql($sql, [
            'policytype' => policy_service::TYPE_CALCULATION,
            'status' => workflow::APPROVED,
        ]);
        $options = [];
        foreach ($records as $record) {
            $applicable = $record->scopetype === policy_service::SCOPE_INSTITUTION
                || ($record->scopetype === policy_service::SCOPE_CATALOG_COURSE
                    && isset($catalogids[(int) $record->scopeid]))
                || ($record->scopetype === policy_service::SCOPE_COURSE_INSTANCE
                    && in_array((int) $record->scopeid, $cinstids, true))
                || ($record->scopetype === policy_service::SCOPE_ASSESSMENT
                    && in_array((int) $record->scopeid, $cmids, true));
            if ($applicable) {
                $options[(int) $record->id] = format_string($record->policyname) . ' — '
                    . format_string($record->bandname) . ' (' . $record->code . ')';
            }
        }
        return $options;
    }

    /**
     * Insert a recommendation and write its audit event.
     *
     * @param \stdClass $record Recommendation record to insert.
     * @param string $action Audit action.
     * @return int The new recommendation record ID.
     */
    private static function insert(\stdClass $record, string $action): int {
        global $DB;
        $actorid = self::require_capabilities($record);
        $record->createdby = $actorid;
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record(self::TABLE, $record);
            $record->id = $id;
            audit_writer::write(
                $action,
                'remediation',
                $id,
                $record->mappinguuid,
                null,
                $record,
                $record->explanation,
                self::context_for($record),
                $actorid
            );
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Build and validate a draft recommendation record.
     *
     * @param array $data Recommendation data.
     * @param string $mappinguuid Stable recommendation UUID.
     * @param int $version Recommendation version number.
     * @return \stdClass The validated draft recommendation record.
     */
    private static function build_record(array $data, string $mappinguuid, int $version): \stdClass {
        $now = time();
        $targettype = input::required_text($data['targettype'] ?? '', 'targettype', 20);
        if (!in_array($targettype, self::TARGETS, true)) {
            throw new validation_exception('invalidtargettype', 'targettype', $targettype);
        }
        $targetid = empty($data['targetid']) ? null : input::positive_int($data['targetid'], 'targetid');
        $externalurl = self::external_url($data['externalurl'] ?? null);
        if (
            ($targettype === self::TARGET_EXTERNAL && ($targetid !== null || $externalurl === null))
                || ($targettype !== self::TARGET_EXTERNAL && ($targetid === null || $externalurl !== null))
        ) {
            throw new validation_exception('remediationtargetinvalid', 'targettype');
        }
        $bandid = empty($data['bandid']) ? null : input::positive_int($data['bandid'], 'bandid');
        $purpose = input::required_text($data['purpose'] ?? self::PURPOSE_REVIEW, 'purpose', 20);
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new validation_exception('invalidfield', 'purpose', $purpose);
        }
        $min = self::percentage($data['minpercent'] ?? null, 'minpercent');
        $max = self::percentage($data['maxpercent'] ?? null, 'maxpercent');
        if ($min !== null && $max !== null && self::compare_decimals($min, $max) > 0) {
            throw new validation_exception('percentagerangeinvalid', 'maxpercent');
        }
        $record = (object) [
            'mappinguuid' => uuid::normalize($mappinguuid),
            'version' => $version,
            'cinstid' => input::positive_int($data['cinstid'] ?? 0, 'cinstid'),
            'itemverid' => input::positive_int($data['itemverid'] ?? 0, 'itemverid'),
            'bandid' => $bandid,
            'targettype' => $targettype,
            'purpose' => $purpose,
            'targetid' => $targetid,
            'externalurl' => $externalurl,
            'title' => input::required_text($data['title'] ?? '', 'title', 255),
            'explanation' => input::optional_multiline($data['explanation'] ?? null),
            'priority' => self::nonnegative_int($data['priority'] ?? 0, 'priority'),
            'sortorder' => self::nonnegative_int($data['sortorder'] ?? 0, 'sortorder'),
            'required' => empty($data['required']) ? 0 : 1,
            'minpercent' => $min,
            'maxpercent' => $max,
            'status' => workflow::DRAFT,
            'effectivefrom' => input::positive_int($data['effectivefrom'] ?? $now, 'effectivefrom'),
            'effectiveto' => input::optional_timestamp($data['effectiveto'] ?? null, 'effectiveto'),
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
        ];
        effective_dates::validate(
            (int) $record->effectivefrom,
            $record->effectiveto === null ? null : (int) $record->effectiveto
        );
        self::validate_record($record);
        return $record;
    }

    /**
     * Validate recommendation references, dates, and target ownership.
     *
     * @param \stdClass $record Recommendation record.
     * @return void
     */
    private static function validate_record(\stdClass $record): void {

        global $DB;
        if (!in_array($record->targettype, self::TARGETS, true)) {
            throw new validation_exception('invalidtargettype', 'targettype', $record->targettype);
        }
        $externalurl = self::external_url($record->externalurl ?? null);
        $targetid = $record->targetid === null ? null : input::positive_int($record->targetid, 'targetid');
        if (
            ($record->targettype === self::TARGET_EXTERNAL && ($targetid !== null || $externalurl === null))
                || ($record->targettype !== self::TARGET_EXTERNAL && ($targetid === null || $externalurl !== null))
        ) {
            throw new validation_exception('remediationtargetinvalid', 'targettype');
        }
        $record->targetid = $targetid;
        $record->externalurl = $externalurl;
        $cinst = self::get_required('local_outcomemap_cinst', (int) $record->cinstid, 'course_instance');
        if ($cinst->status !== workflow::APPROVED || !(int) $cinst->confirmed) {
            throw new validation_exception('courseinstancenotconfirmed', 'cinstid', $record->cinstid);
        }
        $itemversion = self::get_required('local_outcomemap_itemver', (int) $record->itemverid, 'outcome_version');
        if ($itemversion->status !== workflow::APPROVED) {
            throw new validation_exception('outcomeversionnotapproved', 'itemverid', $record->itemverid);
        }
        outcome_search::require_visible_version(self::context_for($record), $itemversion->uuid, (int) $record->effectivefrom);
        if (
            (int) $record->effectivefrom < (int) $itemversion->effectivefrom
                || ($itemversion->effectiveto !== null
                    && ($record->effectiveto === null || (int) $record->effectiveto > (int) $itemversion->effectiveto))
        ) {
            throw new validation_exception('mappingoutsideoutcomeversion', 'effectivefrom');
        }
        if ($record->bandid !== null) {
            $sql = "SELECT b.id, p.policytype, p.scopetype, p.scopeid, p.status,
                           p.effectivefrom, p.effectiveto
                      FROM {local_outcomemap_band} b
                      JOIN {local_outcomemap_policy} p ON p.id = b.policyid
                     WHERE b.id = :bandid";
            $band = $DB->get_record_sql($sql, ['bandid' => $record->bandid]);
            $applicable = $band && $band->policytype === policy_service::TYPE_CALCULATION
            && $band->status === workflow::APPROVED;
            if ($applicable && $band->scopetype === policy_service::SCOPE_CATALOG_COURSE) {
                $applicable = (int) $band->scopeid === (int) $cinst->courseid;
            } else if ($applicable && $band->scopetype === policy_service::SCOPE_COURSE_INSTANCE) {
                $applicable = (int) $band->scopeid === (int) $cinst->id;
            } else if ($applicable && $band->scopetype === policy_service::SCOPE_ASSESSMENT) {
                $applicable = $DB->record_exists('course_modules', [
                'id' => $band->scopeid,
                'course' => $cinst->moodlecourseid,
                ]);
            } else if ($applicable && $band->scopetype !== policy_service::SCOPE_INSTITUTION) {
                $applicable = false;
            }
            if ($applicable) {
                $applicable = (int) $record->effectivefrom >= (int) $band->effectivefrom
                && ($band->effectiveto === null || ($record->effectiveto !== null
                    && (int) $record->effectiveto <= (int) $band->effectiveto));
            }
            if (!$applicable) {
                throw new validation_exception('bandnotapplicable', 'bandid', $record->bandid);
            }
        }
        if ($record->targettype === self::TARGET_MODULE) {
            $targetcourseid = $DB->get_field('course_modules', 'course', ['id' => $record->targetid]);
        } else if ($record->targettype === self::TARGET_SECTION) {
            $targetcourseid = $DB->get_field('course_sections', 'course', ['id' => $record->targetid]);
        } else {
            $targetcourseid = $cinst->moodlecourseid;
        }
        if (!$targetcourseid || (int) $targetcourseid !== (int) $cinst->moodlecourseid) {
            throw new validation_exception('targetcoursemismatch', 'targetid', $record->targetid);
        }
    }

    /**
     * Require the capabilities needed to manage a recommendation.
     *
     * @param \stdClass $record Recommendation record.
     * @param bool $approval Whether to require approval capability.
     * @return int The acting user ID.
     */
    private static function require_capabilities(\stdClass $record, bool $approval = false): int {
        global $USER;
        $context = self::context_for($record);
        require_capability('local/outcomemap:mapcourse', $context);
        require_capability('moodle/course:update', $context);
        if ($approval && workflow::requires_independent_approval()) {
            require_capability('local/outcomemap:approve', $context);
        }
        return (int) $USER->id;
    }

    /**
     * Resolve the course context for a recommendation.
     *
     * @param \stdClass $record Recommendation record.
     * @return \context_course The recommendation's course context.
     */
    private static function context_for(\stdClass $record): \context_course {
        global $DB;
        $courseid = $DB->get_field('local_outcomemap_cinst', 'moodlecourseid', ['id' => $record->cinstid], MUST_EXIST);
        return \context_course::instance((int) $courseid, MUST_EXIST);
    }

    /**
     * Require that a candidate does not overlap an approved version of the same recommendation.
     *
     * @param \stdClass $candidate Candidate recommendation record.
     * @return void
     */
    private static function require_no_approved_overlap(\stdClass $candidate): void {
        global $DB;
        [$overlapsql, $params] = effective_dates::overlap_sql(
            '',
            (int) $candidate->effectivefrom,
            $candidate->effectiveto === null ? null : (int) $candidate->effectiveto,
            'remed'
        );
        $params += [
            'mappinguuid' => $candidate->mappinguuid,
            'status' => workflow::APPROVED,
            'id' => $candidate->id,
        ];
        if (
            $DB->record_exists_select(
                self::TABLE,
                'mappinguuid = :mappinguuid AND status = :status AND id <> :id AND ' . $overlapsql,
                $params
            )
        ) {
            throw new validation_exception('effectiverangeoverlap', 'effectivefrom');
        }
    }

    /**
     * Validate and normalize an optional external URL.
     *
     * @param mixed $value URL value to validate.
     * @return string|null The normalized URL, or null when no URL is supplied.
     */
    private static function external_url($value): ?string {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $url = clean_param(trim((string) $value), PARAM_URL);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($url === '' || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            throw new validation_exception('invalidexternalurl', 'externalurl');
        }
        return $url;
    }

    /**
     * Validate and normalize an optional percentage.
     *
     * @param mixed $value Percentage value to validate.
     * @param string $field Field name used in validation errors.
     * @return string|null The fixed-precision percentage, or null when no value is supplied.
     */
    private static function percentage($value, string $field): ?string {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,10}))?$/D', $value, $matches)) {
            throw new validation_exception('invalidpercentage', $field, $value);
        }
        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 10, '0');
        if ($whole > 100 || ($whole === 100 && trim($fraction, '0') !== '')) {
            throw new validation_exception('invalidpercentage', $field, $value);
        }
        return $whole . '.' . $fraction;
    }

    /**
     * Compare two normalized fixed-precision decimal strings.
     *
     * @param string $left Left decimal value.
     * @param string $right Right decimal value.
     * @return int A value less than, equal to, or greater than zero.
     */
    private static function compare_decimals(string $left, string $right): int {
        [$leftwhole, $leftfraction] = explode('.', $left);
        [$rightwhole, $rightfraction] = explode('.', $right);
        return [(int) $leftwhole, $leftfraction] <=> [(int) $rightwhole, $rightfraction];
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
