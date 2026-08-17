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
 * CSV import service for foundation entities.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use local_outcomemap\local\audit_writer;
use local_outcomemap\local\canonical_json;
use local_outcomemap\local\decimal;
use local_outcomemap\local\effective_dates;
use local_outcomemap\local\import_preview;
use local_outcomemap\local\input;
use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Provides preview-and-commit CSV imports for foundation entities.
 */
final class foundation_import_service extends base_service {
    /** Maximum accepted upload size before CSV parsing. */
    public const MAX_IMPORT_BYTES = 5 * 1024 * 1024;

    /** Maximum number of data rows accepted in one atomic import. */
    public const MAX_IMPORT_ROWS = 10000;

    /**
     * Programs import entity.
     *
     * @var string
     */
    public const PROGRAMS = 'programs';

    /**
     * Catalog courses import entity.
     *
     * @var string
     */
    public const COURSES = 'courses';

    /**
     * Program-course memberships import entity.
     *
     * @var string
     */
    public const PROGRAM_COURSES = 'program_courses';

    /**
     * Course instances import entity.
     *
     * @var string
     */
    public const COURSE_INSTANCES = 'course_instances';

    /**
     * Frameworks import entity.
     *
     * @var string
     */
    public const FRAMEWORKS = 'frameworks';

    /**
     * Outcomes import entity.
     *
     * @var string
     */
    public const OUTCOMES = 'outcomes';

    /**
     * Outcome relations import entity.
     *
     * @var string
     */
    public const RELATIONS = 'relations';

    /**
     * Supported import entities.
     *
     * @var string[]
     */
    public const ENTITIES = [
        self::PROGRAMS,
        self::COURSES,
        self::PROGRAM_COURSES,
        self::COURSE_INSTANCES,
        self::FRAMEWORKS,
        self::OUTCOMES,
        self::RELATIONS,
        self::HIERARCHY,
    ];

    /**
     * Outcome hierarchy import entity, in the shape the hierarchy CSV exports.
     *
     * @var string
     */
    public const HIERARCHY = 'hierarchy';

    /** @var string Relationship the Maps to column expresses. */
    private const HIERARCHY_RELATION = relation_service::ALIGNS_TO;

    /**
     * Exact CSV headers for each import entity.
     *
     * @var array<string, string[]>
     */
    public const HEADERS = [
        self::PROGRAMS => [
            'uuid', 'code', 'name', 'description', 'externalid', 'programtype', 'credential',
        ],
        self::COURSES => ['uuid', 'code', 'name', 'description', 'siskey'],
        self::PROGRAM_COURSES => ['uuid', 'programuuid', 'courseuuid', 'effectivefrom', 'effectiveto'],
        self::COURSE_INSTANCES => ['uuid', 'catalogcourseuuid', 'moodlecourseid', 'periodcode', 'externalid'],
        self::FRAMEWORKS => ['uuid', 'code', 'name', 'description', 'ownertype', 'owneruuid'],
        self::OUTCOMES => [
            'uuid', 'versionuuid', 'frameworkuuid', 'code', 'statement', 'shortstatement', 'bloomlevel',
            'effectivefrom', 'effectiveto', 'changereason',
        ],
        self::RELATIONS => [
            'relationuuid', 'sourceuuid', 'targetuuid', 'type', 'weight', 'effectivefrom', 'effectiveto', 'notes',
        ],
        // Exactly the columns the outcome hierarchy exports, so a file taken out
        // of the plugin can be read back into it.
        self::HIERARCHY => ['Type', 'Framework', 'Code', 'Statement', 'Maps to', 'Version', 'Status'],
    ];

    /** @var string[] Previous Programs header retained for backward-compatible imports. */
    private const LEGACY_PROGRAM_HEADERS = ['uuid', 'code', 'name', 'description', 'externalid'];

    /**
     * Store uploaded CSV content using Moodle's temporary CSV reader.
     *
     * @param string $content CSV content.
     * @param string $encoding Source character encoding.
     * @param string $delimiter CSV delimiter name.
     * @return int Import identifier.
     */
    public static function load(string $content, string $encoding, string $delimiter): int {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');
        self::require_system('local/outcomemap:manageframeworks');
        if (strlen($content) > self::MAX_IMPORT_BYTES) {
            throw new validation_exception('importtoolarge', 'csvfile', display_size(self::MAX_IMPORT_BYTES));
        }
        $importid = \csv_import_reader::get_new_iid('local_outcomemap');
        $reader = new \csv_import_reader($importid, 'local_outcomemap');
        $count = $reader->load_csv_content($content, $encoding, $delimiter);
        if ($count === false) {
            $reader->cleanup();
            throw new validation_exception('invalidfield', 'csvfile', $reader->get_error());
        }
        if ($count === 0) {
            $reader->cleanup();
            throw new validation_exception('importempty', 'csvfile');
        }
        if ($count > self::MAX_IMPORT_ROWS + 1) {
            $reader->cleanup();
            throw new validation_exception('importtoomanyrows', 'csvfile', self::MAX_IMPORT_ROWS);
        }
        return $importid;
    }

    /**
     * Return an exact header-only template.
     *
     * @param string $entity Import entity.
     * @return string CSV template content.
     */
    public static function template(string $entity): string {
        self::require_entity($entity);
        return self::csv_line(self::HEADERS[$entity]);
    }

    /**
     * Validate every row without committing any database changes.
     *
     * @param int $importid Import identifier.
     * @param string $entity Import entity.
     * @return import_preview Import preview.
     */
    public static function preview(int $importid, string $entity): import_preview {
        self::require_system('local/outcomemap:manageframeworks');
        if ($entity === self::HIERARCHY) {
            return self::preview_hierarchy($importid);
        }
        $rows = self::read_rows($importid, $entity);
        $seen = [];
        $previewrows = [];
        $valid = true;
        foreach ($rows as $index => $row) {
            $errors = [];
            $validationexception = null;
            try {
                self::prepare_row($entity, $row, $seen);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                if ($e instanceof validation_exception) {
                    $validationexception = $e;
                }
                $valid = false;
            }
            $previewrows[] = (object) [
                'number' => $index + 2,
                'data' => $row,
                'errors' => $errors,
                'validationexception' => $validationexception,
            ];
        }
        $hash = hash('sha256', canonical_json::encode([
            'entity' => $entity,
            'headers' => self::HEADERS[$entity],
            'rows' => $rows,
        ]));
        return new import_preview($previewrows, $hash, $valid);
    }

    /**
     * Revalidate and commit every row under one outer transaction.
     *
     * @param int $importid Import identifier.
     * @param string $entity Import entity.
     * @param string $expectedhash Expected preview hash.
     * @return int Number of committed data rows.
     */
    public static function commit(int $importid, string $entity, string $expectedhash): int {
        global $DB;
        $actorid = self::require_system('local/outcomemap:manageframeworks');
        if ($entity === self::HIERARCHY) {
            return self::commit_hierarchy($importid, $expectedhash, $actorid);
        }
        $preview = self::preview($importid, $entity);
        if (!hash_equals($preview->hash, strtolower($expectedhash))) {
            throw new validation_exception('importchanged', 'previewhash');
        }
        if (!$preview->valid) {
            foreach ($preview->rows as $row) {
                $exception = $row->validationexception;
                if ($exception instanceof validation_exception && $exception->errorcode === 'duplicatecode') {
                    throw $exception;
                }
            }
            throw new validation_exception('importerrors', 'csvfile');
        }
        $rows = array_map(static fn($row) => $row->data, $preview->rows);
        $seen = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($rows as $row) {
                $data = self::prepare_row($entity, $row, $seen);
                self::commit_row($entity, $data);
            }
            audit_writer::write('import', 'foundation_import', null, null, null, [
                'entity' => $entity,
                'rowcount' => count($rows),
                'previewhash' => $preview->hash,
            ], null, \context_system::instance(), $actorid);
            $transaction->allow_commit();
            return count($rows);
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Validate an outcome hierarchy file across all of its rows at once.
     *
     * The hierarchy is the one import whose rows are not independent: the Maps to
     * column may name an outcome defined further down the same file, so a target
     * is resolved against the outcomes the file declares as well as the ones the
     * site already holds. That cannot be judged one row at a time, which is why
     * this entity does not go through prepare_row().
     *
     * @param int $importid Import identifier.
     * @return import_preview
     */
    private static function preview_hierarchy(int $importid): import_preview {
        $rows = self::read_rows($importid, self::HIERARCHY);
        $frameworks = self::frameworks_by_code();
        $declared = [];
        $rowerrors = [];

        // First pass: the outcomes this file defines, and anything wrong with them.
        foreach ($rows as $index => $row) {
            $errors = [];
            $frameworkcode = trim($row['Framework']);
            $code = trim($row['Code']);
            if ($frameworkcode === '' || !isset($frameworks[$frameworkcode])) {
                $errors[] = get_string('importhierarchy_noframework', 'local_outcomemap', s($frameworkcode));
            }
            if ($code === '') {
                $errors[] = get_string('importhierarchy_nocode', 'local_outcomemap');
            }
            if (trim($row['Statement']) === '') {
                $errors[] = get_string('importhierarchy_nostatement', 'local_outcomemap');
            }
            $label = $frameworkcode . '.' . $code;
            if ($errors === []) {
                if (isset($declared[$label])) {
                    $errors[] = get_string('importhierarchy_duplicate', 'local_outcomemap', s($label));
                } else {
                    $declared[$label] = true;
                }
            }
            $rowerrors[$index] = $errors;
        }

        // Second pass: every alignment target must resolve, in the file or the site.
        $existing = self::outcomes_by_label();
        $previewrows = [];
        $valid = true;
        foreach ($rows as $index => $row) {
            $errors = $rowerrors[$index];
            $source = trim($row['Framework']) . '.' . trim($row['Code']);
            foreach (self::hierarchy_targets($row['Maps to']) as $target) {
                if (!isset($declared[$target]) && !isset($existing[$target])) {
                    $errors[] = get_string('importhierarchy_notarget', 'local_outcomemap', s($target));
                } else if ($target === $source) {
                    $errors[] = get_string('selfrelation', 'local_outcomemap');
                }
            }
            if ($errors !== []) {
                $valid = false;
            }
            $previewrows[] = (object) [
                'number' => $index + 2,
                'data' => $row,
                'errors' => $errors,
                'validationexception' => null,
            ];
        }

        $hash = hash('sha256', canonical_json::encode([
            'entity' => self::HIERARCHY,
            'headers' => self::HEADERS[self::HIERARCHY],
            'rows' => $rows,
        ]));
        return new import_preview($previewrows, $hash, $valid);
    }

    /**
     * Create the outcomes an approved hierarchy file declares, then align them.
     *
     * Outcomes are created before any alignment is attempted because a relation
     * may only join approved outcomes. An outcome or alignment that already
     * exists is left alone, so re-importing the same file changes nothing.
     *
     * @param int $importid Import identifier.
     * @param string $expectedhash Expected preview hash.
     * @param int $actorid Acting user.
     * @return int Number of committed data rows.
     */
    private static function commit_hierarchy(int $importid, string $expectedhash, int $actorid): int {
        global $DB;
        $preview = self::preview_hierarchy($importid);
        if (!hash_equals($preview->hash, strtolower($expectedhash))) {
            throw new validation_exception('importchanged', 'previewhash');
        }
        if (!$preview->valid) {
            throw new validation_exception('importerrors', 'csvfile');
        }
        $rows = array_map(static fn($row) => $row->data, $preview->rows);
        $frameworks = self::frameworks_by_code();
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $items = self::outcomes_by_label();
            foreach ($rows as $row) {
                $frameworkcode = trim($row['Framework']);
                $code = trim($row['Code']);
                $label = $frameworkcode . '.' . $code;
                if (isset($items[$label])) {
                    continue;
                }
                $itemid = outcome_service::create([
                    'frameworkid' => (int) $frameworks[$frameworkcode]->id,
                    'code' => $code,
                    'statement' => trim($row['Statement']),
                    'effectivefrom' => $now,
                ]);
                $versionid = (int) $DB->get_field('local_outcomemap_itemver', 'id',
                    ['itemid' => $itemid], MUST_EXIST);
                // An alignment may only join approved outcomes, so each new
                // outcome is carried through the submission boundary. Where the
                // site requires independent approval it stops there, and the
                // alignments below are reported as deferred rather than forced.
                outcome_service::submit_for_review($versionid);
                $items[$label] = $DB->get_record('local_outcomemap_item', ['id' => $itemid], '*', MUST_EXIST);
            }

            $aligned = 0;
            foreach ($rows as $row) {
                $source = $items[trim($row['Framework']) . '.' . trim($row['Code'])] ?? null;
                if ($source === null || $source->status !== workflow::APPROVED) {
                    continue;
                }
                foreach (self::hierarchy_targets($row['Maps to']) as $targetlabel) {
                    $target = $items[$targetlabel] ?? null;
                    if ($target === null || $target->status !== workflow::APPROVED
                            || (int) $target->id === (int) $source->id) {
                        continue;
                    }
                    if (self::alignment_exists((int) $source->id, (int) $target->id)) {
                        continue;
                    }
                    $relationid = relation_service::create([
                        'sourceitemid' => (int) $source->id,
                        'targetitemid' => (int) $target->id,
                        'type' => self::HIERARCHY_RELATION,
                        'effectivefrom' => $now,
                    ]);
                    relation_service::submit_for_review($relationid);
                    $aligned++;
                }
            }

            audit_writer::write('import', 'foundation_import', null, null, null, [
                'entity' => self::HIERARCHY,
                'rowcount' => count($rows),
                'alignments' => $aligned,
                'previewhash' => $preview->hash,
            ], null, \context_system::instance(), $actorid);
            $transaction->allow_commit();
            return count($rows);
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
    }

    /**
     * Split a Maps to cell into outcome labels.
     *
     * @param string $value Raw cell value.
     * @return string[] Trimmed labels.
     */
    private static function hierarchy_targets(string $value): array {
        $targets = [];
        foreach (preg_split('/[;,]/', $value) as $target) {
            $target = trim($target);
            if ($target !== '') {
                $targets[] = $target;
            }
        }
        return array_values(array_unique($targets));
    }

    /**
     * Return non-retired frameworks keyed by code.
     *
     * @return \stdClass[]
     */
    private static function frameworks_by_code(): array {
        global $DB;
        $frameworks = [];
        foreach ($DB->get_records('local_outcomemap_fw') as $framework) {
            if ($framework->status !== workflow::RETIRED) {
                $frameworks[$framework->code] = $framework;
            }
        }
        return $frameworks;
    }

    /**
     * Return outcome items keyed by their "FRAMEWORK.CODE" label.
     *
     * @return \stdClass[]
     */
    private static function outcomes_by_label(): array {
        global $DB;
        $sql = "SELECT i.id, i.code, i.status, i.frameworkid, fw.code AS frameworkcode
                  FROM {local_outcomemap_item} i
                  JOIN {local_outcomemap_fw} fw ON fw.id = i.frameworkid";
        $items = [];
        foreach ($DB->get_records_sql($sql) as $item) {
            $items[$item->frameworkcode . '.' . $item->code] = $item;
        }
        return $items;
    }

    /**
     * Whether a live alignment already joins two outcomes.
     *
     * @param int $sourceid Source outcome item id.
     * @param int $targetid Target outcome item id.
     * @return bool
     */
    private static function alignment_exists(int $sourceid, int $targetid): bool {
        global $DB;
        return $DB->record_exists_select('local_outcomemap_rel',
            'sourceitemid = :source AND targetitemid = :target AND type = :type AND status <> :retired', [
                'source' => $sourceid,
                'target' => $targetid,
                'type' => self::HIERARCHY_RELATION,
                'retired' => workflow::RETIRED,
            ]);
    }

    /**
     * Remove a stored CSV import.
     *
     * @param int $importid Import identifier.
     */
    public static function cleanup(int $importid): void {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');
        $reader = new \csv_import_reader($importid, 'local_outcomemap');
        $reader->cleanup(true);
    }

    /**
     * Read and map all rows after checking exact headers.
     *
     * @param int $importid Import identifier.
     * @param string $entity Import entity.
     * @return array Imported rows.
     */
    private static function read_rows(int $importid, string $entity): array {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');
        self::require_entity($entity);
        $reader = new \csv_import_reader($importid, 'local_outcomemap');
        $columns = $reader->get_columns();
        $columnlist = $columns === false ? [] : array_values($columns);
        $legacyprogram = $entity === self::PROGRAMS && $columnlist === self::LEGACY_PROGRAM_HEADERS;
        if ($columnlist !== self::HEADERS[$entity] && !$legacyprogram) {
            throw new validation_exception('importheader', 'csvfile', implode(',', self::HEADERS[$entity]));
        }
        $reader->init();
        $rows = [];
        while (($values = $reader->next()) !== false) {
            if (count($rows) >= self::MAX_IMPORT_ROWS) {
                $reader->close();
                throw new validation_exception('importtoomanyrows', 'csvfile', self::MAX_IMPORT_ROWS);
            }
            $values = array_pad(array_values($values), count($columns), '');
            $row = array_combine($columns, array_slice($values, 0, count($columns)));
            $rows[] = array_replace(array_fill_keys(self::HEADERS[$entity], ''), $row);
        }
        if (!$rows) {
            throw new validation_exception('importempty', 'csvfile');
        }
        return $rows;
    }

    /**
     * Validate and transform one row to service input.
     *
     * @param string $entity Import entity.
     * @param array $row Raw CSV row.
     * @param array $seen Keys already seen in this import.
     * @return array Validated service input.
     */
    private static function prepare_row(string $entity, array $row, array &$seen): array {
        global $DB;
        switch ($entity) {
            case self::PROGRAMS:
                $data = [
                    'uuid' => self::optional_uuid($row['uuid']),
                    'code' => input::required_text($row['code'], 'code', 100),
                    'name' => input::required_text($row['name'], 'name', 255),
                    'description' => input::optional_multiline($row['description']),
                    'externalid' => input::optional_text($row['externalid'], 'externalid', 255),
                    'programtype' => program_service::normalize_program_type($row['programtype']),
                ];
                $data['credential'] = program_service::normalize_credential(
                    $row['credential'],
                    $data['programtype']
                );
                self::unique_seen($seen, 'code:' . $data['code']);
                self::assert_not_exists('local_outcomemap_program', 'code', $data['code'], 'duplicatecode');
                self::assert_uuid_available('local_outcomemap_program', $data['uuid']);
                return $data;

            case self::COURSES:
                $data = [
                    'uuid' => self::optional_uuid($row['uuid']),
                    'code' => input::required_text($row['code'], 'code', 100),
                    'name' => input::required_text($row['name'], 'name', 255),
                    'description' => input::optional_multiline($row['description']),
                    'siskey' => input::optional_text($row['siskey'], 'siskey', 255),
                ];
                self::unique_seen($seen, 'code:' . $data['code']);
                self::assert_not_exists('local_outcomemap_course', 'code', $data['code'], 'duplicatecode');
                self::assert_uuid_available('local_outcomemap_course', $data['uuid']);
                return $data;

            case self::PROGRAM_COURSES:
                $program = self::record_by_uuid('local_outcomemap_program', $row['programuuid'], 'program');
                $course = self::record_by_uuid('local_outcomemap_course', $row['courseuuid'], 'catalog_course');
                $from = self::parse_date($row['effectivefrom'], 'effectivefrom');
                $to = self::parse_optional_date($row['effectiveto'], 'effectiveto');
                effective_dates::validate($from, $to);
                self::unique_seen($seen, $program->id . ':' . $course->id . ':' . $from);
                if (
                    $DB->record_exists('local_outcomemap_progcourse', [
                    'programid' => $program->id, 'courseid' => $course->id, 'effectivefrom' => $from,
                    ])
                ) {
                    throw new validation_exception('duplicatecode', 'program_course');
                }
                $data = [
                    'uuid' => self::optional_uuid($row['uuid']),
                    'programid' => $program->id,
                    'courseid' => $course->id,
                    'effectivefrom' => $from,
                    'effectiveto' => $to,
                ];
                self::assert_uuid_available('local_outcomemap_progcourse', $data['uuid']);
                return $data;

            case self::COURSE_INSTANCES:
                $course = self::record_by_uuid('local_outcomemap_course', $row['catalogcourseuuid'], 'catalog_course');
                $moodlecourseid = input::positive_int($row['moodlecourseid'], 'moodlecourseid');
                if (!$DB->record_exists('course', ['id' => $moodlecourseid])) {
                    throw new validation_exception('moodlecoursenotfound', 'moodlecourseid', $moodlecourseid);
                }
                $periodcode = input::required_text($row['periodcode'], 'periodcode', 100);
                self::unique_seen($seen, $moodlecourseid . ':' . $periodcode);
                if (
                    $DB->record_exists('local_outcomemap_cinst', [
                    'moodlecourseid' => $moodlecourseid, 'periodcode' => $periodcode,
                    ])
                ) {
                    throw new validation_exception('courseinstanceexists', 'periodcode', $periodcode);
                }
                $data = [
                    'uuid' => self::optional_uuid($row['uuid']),
                    'courseid' => $course->id,
                    'moodlecourseid' => $moodlecourseid,
                    'periodcode' => $periodcode,
                    'externalid' => input::optional_text($row['externalid'], 'externalid', 255),
                ];
                self::assert_uuid_available('local_outcomemap_cinst', $data['uuid']);
                return $data;

            case self::FRAMEWORKS:
                $ownertype = input::required_text($row['ownertype'], 'ownertype', 20);
                $ownerid = null;
                if ($ownertype === framework_service::OWNER_PROGRAM) {
                    $ownerid = self::record_by_uuid('local_outcomemap_program', $row['owneruuid'], 'program')->id;
                } else if ($ownertype === framework_service::OWNER_COURSE) {
                    $ownerid = self::record_by_uuid('local_outcomemap_course', $row['owneruuid'], 'catalog_course')->id;
                } else if ($ownertype !== framework_service::OWNER_INSTITUTION || trim($row['owneruuid']) !== '') {
                    throw new validation_exception('invalidowner', 'ownertype', $ownertype);
                }
                $code = input::required_text($row['code'], 'code', 100);
                self::unique_seen($seen, $ownertype . ':' . ($ownerid ?? 'null') . ':' . $code);
                $select = 'ownertype = :type AND code = :code';
                $params = ['type' => $ownertype, 'code' => $code];
                if ($ownerid === null) {
                    $select .= ' AND ownerid IS NULL';
                } else {
                    $select .= ' AND ownerid = :ownerid';
                    $params['ownerid'] = $ownerid;
                }
                if ($DB->record_exists_select('local_outcomemap_fw', $select, $params)) {
                    throw new validation_exception('duplicatecode', 'code', $code);
                }
                $data = [
                    'uuid' => self::optional_uuid($row['uuid']),
                    'code' => $code,
                    'name' => input::required_text($row['name'], 'name', 255),
                    'description' => input::optional_multiline($row['description']),
                    'ownertype' => $ownertype,
                    'ownerid' => $ownerid,
                ];
                self::assert_uuid_available('local_outcomemap_fw', $data['uuid']);
                return $data;

            case self::OUTCOMES:
                $framework = self::record_by_uuid('local_outcomemap_fw', $row['frameworkuuid'], 'framework');
                $code = input::required_text($row['code'], 'code', 100);
                self::unique_seen($seen, $framework->id . ':' . $code);
                if ($DB->record_exists('local_outcomemap_item', ['frameworkid' => $framework->id, 'code' => $code])) {
                    throw new validation_exception('duplicatecode', 'code', $code);
                }
                $from = self::parse_date($row['effectivefrom'], 'effectivefrom');
                $to = self::parse_optional_date($row['effectiveto'], 'effectiveto');
                effective_dates::validate($from, $to);
                $data = [
                    'uuid' => self::optional_uuid($row['uuid']),
                    'versionuuid' => self::optional_uuid($row['versionuuid']),
                    'frameworkid' => $framework->id,
                    'code' => $code,
                    'statement' => input::required_text($row['statement'], 'statement', 1333),
                    'shortstatement' => input::optional_text($row['shortstatement'], 'shortstatement', 255),
                    'bloomlevel' => input::optional_text($row['bloomlevel'], 'bloomlevel', 50),
                    'effectivefrom' => $from,
                    'effectiveto' => $to,
                    'changereason' => input::optional_multiline($row['changereason']),
                ];
                self::assert_uuid_available('local_outcomemap_item', $data['uuid']);
                self::assert_uuid_available('local_outcomemap_itemver', $data['versionuuid']);
                return $data;

            case self::RELATIONS:
                $source = self::record_by_uuid('local_outcomemap_item', $row['sourceuuid'], 'source_outcome');
                $target = self::record_by_uuid('local_outcomemap_item', $row['targetuuid'], 'target_outcome');
                if ($source->id === $target->id) {
                    throw new validation_exception('selfrelation', 'targetuuid');
                }
                $type = input::required_text($row['type'], 'type', 30);
                if (!in_array($type, relation_service::TYPES, true)) {
                    throw new validation_exception('invalidrelationtype', 'type', $type);
                }
                if ($type === relation_service::CONTRIBUTES_TO) {
                    $weight = decimal::positive($row['weight']);
                } else {
                    if (trim($row['weight']) !== '') {
                        throw new validation_exception('weightnotallowed', 'weight');
                    }
                    $weight = null;
                }
                $from = self::parse_date($row['effectivefrom'], 'effectivefrom');
                $to = self::parse_optional_date($row['effectiveto'], 'effectiveto');
                effective_dates::validate($from, $to);
                $relationuuid = self::optional_uuid($row['relationuuid']);
                self::unique_seen($seen, $relationuuid ?: $source->id . ':' . $target->id . ':' . $type . ':' . $from);
                if (
                    $relationuuid !== null && $DB->record_exists('local_outcomemap_rel', [
                    'relationuuid' => $relationuuid, 'version' => 1,
                    ])
                ) {
                    throw new validation_exception('duplicateuuid', 'relationuuid', $relationuuid);
                }
                return [
                    'relationuuid' => $relationuuid,
                    'sourceitemid' => $source->id,
                    'targetitemid' => $target->id,
                    'type' => $type,
                    'weight' => $weight,
                    'effectivefrom' => $from,
                    'effectiveto' => $to,
                    'notes' => input::optional_multiline($row['notes']),
                ];
        }
        throw new validation_exception('invalidfield', 'entity', $entity);
    }

    /**
     * Dispatch one transformed row to its transactional service.
     *
     * @param string $entity Import entity.
     * @param array $data Validated service input.
     */
    private static function commit_row(string $entity, array $data): void {
        switch ($entity) {
            case self::PROGRAMS:
                program_service::create($data);
                return;
            case self::COURSES:
                catalog_course_service::create($data);
                return;
            case self::PROGRAM_COURSES:
                program_course_service::create($data);
                return;
            case self::COURSE_INSTANCES:
                course_instance_service::create($data);
                return;
            case self::FRAMEWORKS:
                framework_service::create($data);
                return;
            case self::OUTCOMES:
                outcome_service::create($data);
                return;
            case self::RELATIONS:
                relation_service::create($data);
                return;
        }
    }

    /**
     * Require a supported import entity.
     *
     * @param string $entity Import entity.
     */
    private static function require_entity(string $entity): void {
        if (!in_array($entity, self::ENTITIES, true)) {
            throw new validation_exception('invalidfield', 'entity', $entity);
        }
    }

    /**
     * Normalize an optional UUID.
     *
     * @param string $value UUID value.
     * @return string|null Normalized UUID, or null for an empty value.
     */
    private static function optional_uuid(string $value): ?string {
        return trim($value) === '' ? null : uuid::normalize($value);
    }

    /**
     * Assert that an optional UUID is not already in use.
     *
     * @param string $table Database table.
     * @param string|null $value UUID value.
     */
    private static function assert_uuid_available(string $table, ?string $value): void {
        global $DB;
        if ($value !== null && $DB->record_exists($table, ['uuid' => $value])) {
            throw new validation_exception('duplicateuuid', 'uuid', $value);
        }
    }

    /**
     * Assert that a field value does not already exist.
     *
     * @param string $table Database table.
     * @param string $field Database field.
     * @param string $value Field value.
     * @param string $error Validation error code.
     */
    private static function assert_not_exists(string $table, string $field, string $value, string $error): void {
        global $DB;
        if ($DB->record_exists($table, [$field => $value])) {
            throw new validation_exception($error, $field, $value);
        }
    }

    /**
     * Load a database record by UUID.
     *
     * @param string $table Database table.
     * @param string $value UUID value.
     * @param string $type Validation object type.
     * @return \stdClass Database record.
     */
    private static function record_by_uuid(string $table, string $value, string $type): \stdClass {
        global $DB;
        $value = uuid::normalize($value);
        $record = $DB->get_record($table, ['uuid' => $value]);
        if (!$record) {
            throw new validation_exception('recordnotfound', $type, $value);
        }
        return $record;
    }

    /**
     * Require a key to be unique within the current import.
     *
     * @param array $seen Keys already seen in this import.
     * @param string $key Candidate key.
     */
    private static function unique_seen(array &$seen, string $key): void {
        if (isset($seen[$key])) {
            throw new validation_exception('duplicatecode', 'csvfile', $key);
        }
        $seen[$key] = true;
    }

    /**
     * Parse a positive timestamp or ISO calendar date.
     *
     * @param string $value Date value.
     * @param string $field Validation field name.
     * @return int Unix timestamp.
     */
    private static function parse_date(string $value, string $field): int {
        $value = trim($value);
        if (preg_match('/^[1-9]\d*$/D', $value)) {
            return input::positive_int($value, $field);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new validation_exception('invaliddate', $field, $value);
        }
        return $date->getTimestamp();
    }

    /**
     * Parse an optional timestamp or ISO calendar date.
     *
     * @param string $value Date value.
     * @param string $field Validation field name.
     * @return int|null Unix timestamp, or null for an empty value.
     */
    private static function parse_optional_date(string $value, string $field): ?int {
        return trim($value) === '' ? null : self::parse_date($value, $field);
    }

    /**
     * Encode values as one CSV line.
     *
     * @param array $values CSV field values.
     * @return string CSV line.
     */
    private static function csv_line(array $values): string {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $values, ',', '"', '');
        rewind($stream);
        $line = stream_get_contents($stream);
        fclose($stream);
        return $line;
    }
}
