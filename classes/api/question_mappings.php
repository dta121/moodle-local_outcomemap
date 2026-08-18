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

namespace local_outcomemap\api;

use local_outcomemap\local\dto\question_mapping;
use local_outcomemap\local\service\question_mapping_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;

/**
 * Public question-version mapping boundary for companion plugins.
 *
 * The companion `qbank_outcomemap` plugin must use this facade instead of the
 * plugin database tables. Every method repeats context, plugin-capability, and
 * Moodle question-capability checks; user-interface checks are not trusted.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_mappings {
    /**
     * Public API version.
     */
    public const API_VERSION = '1.1';

    /**
     * Bulk operation identifier.
     */
    public const BULK_INSPECT = 'inspect';
    /**
     * Bulk operation identifier.
     */
    public const BULK_ADD = 'add';
    /**
     * Bulk operation identifier.
     */
    public const BULK_CHANGE_ROLE = 'change_role';
    /**
     * Bulk operation identifier.
     */
    public const BULK_DELETE_DRAFTS = 'delete_drafts';
    /**
     * Bulk operation identifier.
     */
    public const BULK_SUBMIT_DRAFTS = 'submit_drafts';

    /**
     * Preview a bulk operation against selected core question IDs.
     *
     * @param int[] $questionids Untrusted selected core question IDs.
     * @param array $operation Public operation data using stable UUIDs.
     * @return \stdClass Structured, capability-filtered validation preview.
     */
    public static function preview_bulk(array $questionids, array $operation): \stdClass {
        $publicoperation = self::normalize_bulk_operation($operation);
        $serviceoperation = $publicoperation;
        if ($publicoperation['action'] === self::BULK_ADD) {
            $serviceoperation['itemverid'] = self::resolve_outcome_version(
                (string) $publicoperation['outcomeversionuuid']
            );
        }
        $preview = question_mapping_service::preview_bulk($questionids, $serviceoperation);
        $preview->operation = $publicoperation;
        unset(
            $preview->actorid,
            $preview->reason,
            $preview->_changes,
            $preview->_assessedquestionversions
        );
        foreach ($preview->questions as $index => $question) {
            $question = clone $question;
            $context = \context::instance_by_id((int) $question->contextid);
            $question->name = format_string($question->name, true, ['context' => $context]);
            unset($question->createdby, $question->contextid);
            $preview->questions[$index] = $question;
        }
        return $preview;
    }

    /**
     * Atomically commit a bulk operation after an explicit preview.
     *
     * The service re-resolves exact question versions, repeats every capability
     * and validation check, and rejects stale preview tokens.
     *
     * @param int[] $questionids Untrusted selected core question IDs.
     * @param array $operation Public operation data returned with the preview.
     * @param string $previewtoken Preview token.
     * @return \stdClass Commit summary.
     */
    public static function commit_bulk(
        array $questionids,
        array $operation,
        string $previewtoken
    ): \stdClass {
        $publicoperation = self::normalize_bulk_operation($operation);
        if ($publicoperation['action'] === self::BULK_INSPECT) {
            throw new validation_exception('invalidfield', 'operation', self::BULK_INSPECT);
        }
        $serviceoperation = $publicoperation;
        if ($publicoperation['action'] === self::BULK_ADD) {
            $serviceoperation['itemverid'] = self::resolve_outcome_version(
                (string) $publicoperation['outcomeversionuuid']
            );
        }
        return question_mapping_service::commit_bulk(
            $questionids,
            $serviceoperation,
            $previewtoken
        );
    }

    /**
     * Normalize the public request so preview and commit hash identical data.
     */
    private static function normalize_bulk_operation(array $operation): array {
        $action = (string) ($operation['action'] ?? '');
        $normalized = ['action' => $action];
        if ($action === self::BULK_ADD || $action === self::BULK_CHANGE_ROLE) {
            $normalized['role'] = (string) ($operation['role'] ?? '');
        }
        if ($action === self::BULK_ADD) {
            $normalized['outcomeversionuuid'] = uuid::normalize(
                (string) ($operation['outcomeversionuuid'] ?? '')
            );
            $normalized['effectivefrom'] = isset($operation['effectivefrom'])
                ? (int) $operation['effectivefrom']
                : time();
        }
        if (
            in_array($action, [
            self::BULK_CHANGE_ROLE,
            self::BULK_DELETE_DRAFTS,
            self::BULK_SUBMIT_DRAFTS,
            ], true)
        ) {
            $mappingids = array_values(array_unique(array_filter(array_map(
                'intval',
                (array) ($operation['mappingids'] ?? [])
            ))));
            sort($mappingids);
            $normalized['mappingids'] = $mappingids;
        }
        $weights = [];
        foreach ((array) ($operation['weights'] ?? []) as $id => $weight) {
            $id = (int) $id;
            if ($id > 0 && trim((string) $weight) !== '') {
                $weights[$id] = trim((string) $weight);
            }
        }
        ksort($weights);
        if ($weights) {
            $normalized['weights'] = $weights;
        }
        foreach (['notes', 'reason'] as $field) {
            $value = trim((string) ($operation[$field] ?? ''));
            if ($value !== '') {
                $normalized[$field] = $value;
            }
        }
        return $normalized;
    }

    /**
     * Bulk-load mappings for a page of question versions.
     *
     * Question versions the caller may not view are omitted.
     *
     * @param int[] $questionversionids Question-version IDs, maximum 1000.
     * @return question_mapping[][] DTO lists keyed by question-version ID.
     */
    public static function get_for_question_versions(array $questionversionids): array {
        $grouped = question_mapping_service::get_for_question_versions($questionversionids);
        $result = [];
        foreach ($grouped as $questionversionid => $records) {
            $result[$questionversionid] = array_map(
                static fn(\stdClass $record): question_mapping => new question_mapping($record),
                $records
            );
        }
        return $result;
    }

    /**
     * Build a parameterized exact-version mapped-state qbank filter.
     *
     * Retained for companion compatibility. New callers should use
     * {@see question_mapping_filters::build()} directly, which additionally
     * accepts the full set of question-bank contexts for the current view.
     *
     * @param \context $context Question-bank context authorizing mapping metadata access.
     * @param bool $mapped True for mapped versions, false for unmapped versions.
     * @return array{0:string,1:array} WHERE fragment and named parameters.
     */
    public static function build_mapped_filter_query(\context $context, bool $mapped): array {
        require_capability('local/outcomemap:viewdefinitions', $context);
        return question_mapping_filters::build(
            question_mapping_filters::MAPPED,
            ['values' => [$mapped ? 1 : 0]],
            [$context]
        );
    }

    /**
     * Build a parameterized exact-version outcome-code qbank filter.
     *
     * Retained for companion compatibility. New callers should use
     * {@see question_mapping_filters::build()} directly, which additionally
     * accepts the full set of question-bank contexts for the current view.
     *
     * @param \context $context Question-bank context authorizing mapping metadata access.
     * @param string[] $values Outcome-code or framework.outcome-code fragments.
     * @param int $jointype Core data-filter join type.
     * @return array{0:string,1:array} WHERE fragment and named parameters.
     */
    public static function build_outcome_filter_query(\context $context, array $values, int $jointype): array {
        require_capability('local/outcomemap:viewdefinitions', $context);
        return question_mapping_filters::build(
            question_mapping_filters::OUTCOME,
            ['values' => $values, 'jointype' => $jointype],
            [$context]
        );
    }

    /**
     * Create a draft mapping bound to an exact question version and outcome version.
     *
     * The outcome version is addressed by its stable UUID as returned by
     * {@see outcome_search}; internal record identifiers stay private.
     *
     * @param int $questionversionid Core question-version ID.
     * @param string $outcomeversionuuid Approved outcome-version UUID.
     * @param string $role Mapping role.
     * @param string|null $weight Canonical assessed weight; required for `assesses`.
     * @param string|null $notes Optional notes.
     * @param int|null $effectivefrom Effective start; defaults to now.
     * @param int|null $effectiveto Optional effective end.
     * @return int The new draft mapping ID.
     */
    public static function create_draft(
        int $questionversionid,
        string $outcomeversionuuid,
        string $role,
        ?string $weight = null,
        ?string $notes = null,
        ?int $effectivefrom = null,
        ?int $effectiveto = null
    ): int {
        return question_mapping_service::create([
            'questionversionid' => $questionversionid,
            'itemverid' => self::resolve_outcome_version($outcomeversionuuid),
            'role' => $role,
            'weight' => $weight,
            'notes' => $notes,
            'effectivefrom' => $effectivefrom ?? time(),
            'effectiveto' => $effectiveto,
        ]);
    }

    /**
     * Update a draft mapping.
     *
     * @param int $mappingid Draft mapping ID.
     * @param array $data Changed fields: outcomeversionuuid, role, weight, notes,
     *     effectivefrom, effectiveto, and optional audit reason.
     * @return void
     */
    public static function update_draft(int $mappingid, array $data): void {
        if (array_key_exists('outcomeversionuuid', $data)) {
            $data['itemverid'] = self::resolve_outcome_version((string) $data['outcomeversionuuid']);
            unset($data['outcomeversionuuid']);
        }
        question_mapping_service::update_draft($mappingid, $data);
    }

    /**
     * Delete a draft mapping.
     *
     * @param int $mappingid Draft mapping ID.
     * @param string|null $reason Optional reason.
     * @return void
     */
    public static function delete_draft(int $mappingid, ?string $reason = null): void {
        question_mapping_service::delete_draft($mappingid, $reason);
    }

    /**
     * Submit a draft mapping for review.
     *
     * @param int $mappingid Draft mapping ID.
     * @param string|null $reason Optional reason.
     * @return void
     */
    public static function submit_for_review(int $mappingid, ?string $reason = null): void {
        question_mapping_service::submit_for_review($mappingid, $reason);
    }

    /**
     * Approve a reviewed mapping.
     *
     * Approving an `assesses` mapping approves every pending `assesses` mapping
     * of the same question version together and requires the approved weights
     * to total exactly 1.0000000000.
     *
     * @param int $mappingid Mapping ID in needs-review state.
     * @param string|null $reason Optional reason.
     * @return void
     */
    public static function approve(int $mappingid, ?string $reason = null): void {
        question_mapping_service::approve($mappingid, $reason);
    }

    /**
     * Report assessed-weight validity for a question version.
     *
     * @param int $questionversionid Core question-version ID.
     * @param int|null $effectiveat Effective timestamp; defaults to now.
     * @return \stdClass Totals and validity flags.
     */
    public static function validate_assessed_weights(int $questionversionid, ?int $effectiveat = null): \stdClass {
        return question_mapping_service::validate_assessed_weights($questionversionid, $effectiveat);
    }

    /**
     * Preview eligible mappings from the immediately preceding question version.
     *
     * @param int $targetquestionversionid Target exact question-version ID.
     * @param int|null $sourcequestionversionid Optional explicit earlier source.
     * @return \stdClass Companion-safe eligibility and provenance summary.
     */
    public static function preview_copy_to_version(
        int $targetquestionversionid,
        ?int $sourcequestionversionid = null
    ): \stdClass {
        return question_mapping_service::preview_copy_to_version(
            $targetquestionversionid,
            $sourcequestionversionid
        );
    }

    /**
     * Copy currently effective approved mappings onto a new question version as drafts.
     *
     * @param int $targetquestionversionid Target question-version ID.
     * @param int|null $sourcequestionversionid Source version; defaults to the
     *     immediately preceding version of the same question bank entry.
     * @param string|null $reason Optional reason.
     * @return int[] New draft mapping IDs.
     */
    public static function copy_to_version(
        int $targetquestionversionid,
        ?int $sourcequestionversionid = null,
        ?string $reason = null
    ): array {
        return question_mapping_service::copy_to_version(
            $targetquestionversionid,
            $sourcequestionversionid,
            $reason
        );
    }

    /**
     * Resolve a stable outcome-version UUID to its internal record ID.
     *
     * @param string $outcomeversionuuid Outcome-version UUID.
     * @return int Internal outcome-version ID.
     */
    private static function resolve_outcome_version(string $outcomeversionuuid): int {
        global $DB;
        $id = $DB->get_field('local_outcomemap_itemver', 'id', ['uuid' => uuid::normalize($outcomeversionuuid)]);
        if (!$id) {
            throw new validation_exception('recordnotfound', 'outcome_version', $outcomeversionuuid);
        }
        return (int) $id;
    }
}
