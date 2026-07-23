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
 * Restore writer for outcome mappings and course associations.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\backup;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\uuid;
use local_outcomemap\local\workflow;

/**
 * Restores outcome mapping records as unconfirmed drafts.
 */
final class mapping_restorer {
    /**
     * Restore a course-instance association without carrying source approval.
     *
     * @param object $data Backup course-instance data.
     * @param int $newcourseid Restored Moodle course identifier.
     * @return int|null Restored course-instance identifier, or null when unresolved.
     */
    public static function restore_course_instance(object $data, int $newcourseid): ?int {
        global $DB;
        $catalogcourseid = $DB->get_field('local_outcomemap_course', 'id', [
            'uuid' => strtolower(trim((string) ($data->catalogcourseuuid ?? ''))),
        ]);
        if (!$catalogcourseid || !$DB->record_exists('course', ['id' => $newcourseid])) {
            return null;
        }
        $periodcode = clean_param((string) ($data->periodcode ?? ''), PARAM_TEXT);
        if ($periodcode === '') {
            return null;
        }
        $existing = $DB->get_record('local_outcomemap_cinst', [
            'moodlecourseid' => $newcourseid,
            'periodcode' => $periodcode,
        ]);
        if (
            $existing && (int) $existing->courseid === (int) $catalogcourseid
                && $existing->status === workflow::DRAFT && !(int) $existing->confirmed
        ) {
            return (int) $existing->id;
        }
        if ($existing) {
            $base = \core_text::substr($periodcode, 0, 80);
            $counter = 1;
            do {
                $periodcode = $base . '-RESTORED-' . $counter++;
            } while (
                $DB->record_exists('local_outcomemap_cinst', [
                'moodlecourseid' => $newcourseid,
                'periodcode' => $periodcode,
                ])
            );
        }
        $now = time();
        $record = (object) [
            'uuid' => uuid::generate(),
            'courseid' => (int) $catalogcourseid,
            'moodlecourseid' => $newcourseid,
            'periodcode' => \core_text::substr($periodcode, 0, 100),
            'externalid' => null,
            'status' => workflow::DRAFT,
            'confirmed' => 0,
            'confirmedby' => null,
            'confirmedat' => null,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record('local_outcomemap_cinst', $record);
            $record->id = $id;
            audit_writer::write(
                'restore_draft',
                'course_instance',
                $id,
                $record->uuid,
                null,
                $record,
                'Restored from course backup; confirmation required.',
                \context_course::instance($newcourseid),
                null
            );
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Restore a course-module or section mapping as a version-one draft.
     *
     * @param string $targettype Mapping target type.
     * @param object $data Backup mapping data.
     * @param int $cinstid Restored course-instance identifier.
     * @param int $targetid Restored target identifier.
     * @return int|null Restored mapping identifier, or null when unresolved.
     */
    public static function restore_content_mapping(
        string $targettype,
        object $data,
        int $cinstid,
        int $targetid
    ): ?int {
        global $DB;
        $definition = [
            'course_module' => ['local_outcomemap_cmmap', 'cmid'],
            'course_section' => ['local_outcomemap_secmap', 'sectionid'],
        ][$targettype] ?? null;
        if ($definition === null) {
            return null;
        }
        [$table, $targetfield] = $definition;
        $itemverid = self::resolve_item_version($data);
        if (!$itemverid || !$DB->record_exists('local_outcomemap_cinst', ['id' => $cinstid])) {
            return null;
        }
        $role = clean_param((string) ($data->role ?? ''), PARAM_ALPHANUMEXT);
        if (!in_array($role, ['teaches', 'practices', 'assesses', 'remediates', 'alignment_only'], true)) {
            return null;
        }
        $now = time();
        $record = (object) [
            'mappinguuid' => uuid::generate(),
            'version' => 1,
            'cinstid' => $cinstid,
            $targetfield => $targetid,
            'itemverid' => $itemverid,
            'role' => $role,
            'weight' => self::nullable_decimal($data->weight ?? null),
            'priority' => max(0, (int) ($data->priority ?? 0)),
            'notes' => self::nullable_text($data->notes ?? null),
            'status' => workflow::DRAFT,
            'effectivefrom' => max(1, (int) ($data->effectivefrom ?? $now)),
            'effectiveto' => empty($data->effectiveto) ? null : (int) $data->effectiveto,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
        ];
        return self::insert_draft($table, 'content_mapping', $record, $record->mappinguuid, $cinstid);
    }

    /**
     * Restore an internal or external remediation recommendation as a draft.
     *
     * @param string $targettype Remediation target type.
     * @param object $data Backup remediation data.
     * @param int $cinstid Restored course-instance identifier.
     * @param int|null $targetid Restored internal target identifier.
     * @return int|null Restored remediation identifier, or null when unresolved.
     */
    public static function restore_remediation(
        string $targettype,
        object $data,
        int $cinstid,
        ?int $targetid = null
    ): ?int {
        global $DB;
        if (!in_array($targettype, ['course_module', 'course_section', 'external_url'], true)) {
            return null;
        }
        $itemverid = self::resolve_item_version($data);
        if (!$itemverid || !$DB->record_exists('local_outcomemap_cinst', ['id' => $cinstid])) {
            return null;
        }
        $externalurl = $targettype === 'external_url'
            ? clean_param((string) ($data->externalurl ?? ''), PARAM_URL) : null;
        if ($targettype === 'external_url' && $externalurl === '') {
            return null;
        }
        $title = clean_param((string) ($data->title ?? ''), PARAM_TEXT);
        if ($title === '') {
            return null;
        }
        $now = time();
        $purpose = clean_param((string) ($data->purpose ?? 'review'), PARAM_ALPHANUMEXT);
        if (!in_array($purpose, ['review', 'practice', 'reassessment'], true)) {
            $purpose = 'review';
        }
        $sourcehasband = trim((string) ($data->bandpolicyuuid ?? '')) !== ''
            || (int) ($data->bandpolicyversion ?? 0) > 0
            || trim((string) ($data->bandcode ?? '')) !== '';
        $bandid = self::resolve_band($data);
        // Never broaden a band-specific source recommendation to "any band"
        // when its exact governed policy version is unavailable on restore.
        if ($sourcehasband && $bandid === null) {
            return null;
        }
        $record = (object) [
            'mappinguuid' => uuid::generate(),
            'version' => 1,
            'cinstid' => $cinstid,
            'itemverid' => $itemverid,
            'bandid' => $bandid,
            'targettype' => $targettype,
            'purpose' => $purpose,
            'targetid' => $targettype === 'external_url' ? null : $targetid,
            'externalurl' => $externalurl,
            'title' => \core_text::substr($title, 0, 255),
            'explanation' => self::nullable_text($data->explanation ?? null),
            'priority' => max(0, (int) ($data->priority ?? 0)),
            'sortorder' => max(0, (int) ($data->sortorder ?? 0)),
            'required' => empty($data->required) ? 0 : 1,
            'minpercent' => self::nullable_decimal($data->minpercent ?? null),
            'maxpercent' => self::nullable_decimal($data->maxpercent ?? null),
            'status' => workflow::DRAFT,
            'effectivefrom' => max(1, (int) ($data->effectivefrom ?? $now)),
            'effectiveto' => empty($data->effectiveto) ? null : (int) $data->effectiveto,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
        ];
        return self::insert_draft(
            'local_outcomemap_remed',
            'remediation',
            $record,
            $record->mappinguuid,
            $cinstid
        );
    }

    /**
     * Restore a question-version mapping as a version-one draft.
     *
     * Restored questions are frequently matched to existing bank questions
     * rather than duplicated, so a mapping is only created when the target
     * question version does not already carry one for the same exact outcome
     * version and role.
     *
     * @param object $data Backup mapping data.
     * @param int $questionversionid Restored question-version identifier.
     * @return int|null Restored mapping identifier, or null when unresolved or already present.
     */
    public static function restore_question_mapping(object $data, int $questionversionid): ?int {
        global $DB;
        $questionversion = $DB->get_record('question_versions', ['id' => $questionversionid]);
        if (!$questionversion) {
            return null;
        }
        $itemverid = self::resolve_item_version($data);
        if (!$itemverid) {
            return null;
        }
        $role = clean_param((string) ($data->role ?? ''), PARAM_ALPHANUMEXT);
        if (!in_array($role, ['teaches', 'practices', 'assesses', 'remediates', 'alignment_only'], true)) {
            return null;
        }
        if (
            $DB->record_exists('local_outcomemap_qmap', [
                'questionversionid' => $questionversionid,
                'itemverid' => $itemverid,
                'role' => $role,
            ])
        ) {
            return null;
        }
        $now = time();
        $record = (object) [
            'mappinguuid' => uuid::generate(),
            'version' => 1,
            'questionversionid' => $questionversionid,
            'questionid' => (int) $questionversion->questionid,
            'itemverid' => $itemverid,
            'role' => $role,
            'weight' => $role === 'assesses' ? self::nullable_decimal($data->weight ?? null) : null,
            'notes' => self::nullable_text($data->notes ?? null),
            'status' => workflow::DRAFT,
            'effectivefrom' => max(1, (int) ($data->effectivefrom ?? $now)),
            'effectiveto' => empty($data->effectiveto) ? null : (int) $data->effectiveto,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => null,
        ];
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record('local_outcomemap_qmap', $record);
            $record->id = $id;
            audit_writer::write(
                'restore_draft',
                'question_mapping',
                $id,
                $record->mappinguuid,
                null,
                $record,
                'Restored from backup; review required.',
                \local_outcomemap\api\context_resolver::for_question_version($questionversionid),
                null
            );
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Resolve an optional backed-up band by stable policy UUID and band code.
     *
     * @param object $data Backup remediation data.
     * @return int|null Matching local band ID, or null when unresolved.
     */
    private static function resolve_band(object $data): ?int {
        global $DB;
        $policyuuid = strtolower(trim((string) ($data->bandpolicyuuid ?? '')));
        $policyversion = (int) ($data->bandpolicyversion ?? 0);
        $bandcode = trim((string) ($data->bandcode ?? ''));
        if ($policyuuid === '' || $policyversion < 1 || $bandcode === '') {
            return null;
        }
        $sql = "SELECT b.id
                  FROM {local_outcomemap_band} b
                  JOIN {local_outcomemap_policy} p ON p.id = b.policyid
                 WHERE p.policyuuid = :policyuuid AND p.version = :policyversion
                       AND b.code = :bandcode";
        $id = $DB->get_field_sql($sql, [
            'policyuuid' => $policyuuid,
            'policyversion' => $policyversion,
            'bandcode' => $bandcode,
        ]);
        return $id ? (int) $id : null;
    }

    /**
     * Resolve a backed-up outcome version to its local identifier.
     *
     * @param object $data Backup record containing outcome UUIDs.
     * @return int|null Outcome-version identifier, or null when unresolved.
     */
    private static function resolve_item_version(object $data): ?int {
        global $DB;
        $sql = 'SELECT v.id
                  FROM {local_outcomemap_itemver} v
                  JOIN {local_outcomemap_item} i ON i.id = v.itemid
                 WHERE v.uuid = :versionuuid AND i.uuid = :itemuuid';
        $id = $DB->get_field_sql($sql, [
            'versionuuid' => strtolower(trim((string) ($data->outcomeversionuuid ?? ''))),
            'itemuuid' => strtolower(trim((string) ($data->outcomeuuid ?? ''))),
        ]);
        return $id ? (int) $id : null;
    }

    /**
     * Insert and audit a restored draft record.
     *
     * @param string $table Database table.
     * @param string $objecttype Audit object type.
     * @param object $record Draft record.
     * @param string $objectuuid Stable object UUID.
     * @param int $cinstid Course-instance identifier.
     * @return int Inserted record identifier.
     */
    private static function insert_draft(
        string $table,
        string $objecttype,
        object $record,
        string $objectuuid,
        int $cinstid
    ): int {
        global $DB;
        $courseid = $DB->get_field('local_outcomemap_cinst', 'moodlecourseid', ['id' => $cinstid], MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        try {
            $id = $DB->insert_record($table, $record);
            $record->id = $id;
            audit_writer::write(
                'restore_draft',
                $objecttype,
                $id,
                $objectuuid,
                null,
                $record,
                'Restored from course backup; review required.',
                \context_course::instance((int) $courseid),
                null
            );
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Normalize an optional nonnegative decimal value.
     *
     * @param mixed $value Decimal value.
     * @return string|null Normalized decimal, or null for invalid or empty input.
     */
    private static function nullable_decimal($value): ?string {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        return preg_match('/^\d{1,10}(?:\.\d{1,10})?$/D', $value) ? $value : null;
    }

    /**
     * Normalize optional text.
     *
     * @param mixed $value Text value.
     * @return string|null Cleaned text, or null for empty input.
     */
    private static function nullable_text($value): ?string {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return clean_param((string) $value, PARAM_TEXT);
    }
}
