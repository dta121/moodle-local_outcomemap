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

use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Moves question and content mappings between sites as CSV.
 *
 * A course backup carries mappings by outcome UUID and drops silently whatever
 * it cannot resolve, so a site that created its outcomes independently cannot
 * receive mappings that way. These files name everything by what people see:
 * the course shortname, quiz and question names, activity identifiers or
 * names, section numbers, and the outcome's FRAMEWORK.CODE label. A file
 * exported from one course therefore reads straight into another course with
 * the same structure, and a row that already has its mapping is skipped so a
 * re-import changes nothing.
 */
final class mapping_transfer_service extends base_service {
    /**
     * @var string[] Columns of the question mapping file.
     */
    public const QUESTION_HEADERS = [
        'course', 'quiz', 'question', 'questionversion', 'outcome', 'role', 'weight',
        'effectivefrom', 'effectiveto', 'notes',
    ];

    /**
     * @var string[] Columns of the content mapping file.
     */
    public const CONTENT_HEADERS = [
        'course', 'targettype', 'target', 'outcome', 'role', 'weight', 'priority',
        'effectivefrom', 'effectiveto', 'notes',
    ];

    /**
     * @var string Target type value naming an activity or resource.
     */
    public const TARGET_MODULE = 'module';

    /**
     * @var string Target type value naming a course section.
     */
    public const TARGET_SECTION = 'section';

    // ---------------------------------------------------------------- export.

    /**
     * Rows describing every live question mapping on a course's quizzes.
     *
     * @param int $courseid Moodle course id.
     * @return array<int,array> Header row followed by one row per mapping.
     */
    public static function export_question_mappings(int $courseid): array {
        $course = get_course($courseid);
        $rows = [self::QUESTION_HEADERS];
        $seen = [];
        foreach (question_browser_service::quizzes($courseid) as $quiz) {
            $detail = question_browser_service::quiz_detail($courseid, (int) $quiz->cmid);
            foreach ($detail->slots as $slot) {
                foreach ($slot->questions as $question) {
                    if ($question->missing || isset($seen[$question->questionversionid])) {
                        continue;
                    }
                    $seen[$question->questionversionid] = true;
                    foreach ($question->mappings as $mapping) {
                        if ($mapping->status === workflow::RETIRED) {
                            continue;
                        }
                        $rows[] = [
                            $course->shortname,
                            $detail->name,
                            $question->name,
                            (string) $question->questionversion,
                            $mapping->frameworkcode . '.' . $mapping->outcomecode,
                            $mapping->role,
                            $mapping->weight === null ? '' : (string) $mapping->weight,
                            (string) (int) $mapping->effectivefrom,
                            $mapping->effectiveto === null ? '' : (string) (int) $mapping->effectiveto,
                            (string) ($mapping->notes ?? ''),
                        ];
                    }
                }
            }
        }
        return $rows;
    }

    /**
     * Rows describing every live content mapping on a course.
     *
     * Activities are named by idnumber when they have one, otherwise by name;
     * sections by number. Both are what the import resolves against.
     *
     * @param int $courseid Moodle course id.
     * @return array<int,array> Header row followed by one row per mapping.
     */
    public static function export_content_mappings(int $courseid): array {
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($courseid);
        $mappings = content_mapping_service::list_for_course($courseid);
        $rows = [self::CONTENT_HEADERS];
        foreach ($mappings['modules'] as $mapping) {
            if ($mapping->status === workflow::RETIRED || !isset($modinfo->cms[(int) $mapping->cmid])) {
                continue;
            }
            $cm = $modinfo->get_cm((int) $mapping->cmid);
            $rows[] = self::content_row(
                $course->shortname,
                self::TARGET_MODULE,
                trim((string) $cm->idnumber) !== '' ? (string) $cm->idnumber : $cm->get_formatted_name(),
                $mapping
            );
        }
        foreach ($mappings['sections'] as $mapping) {
            if ($mapping->status === workflow::RETIRED) {
                continue;
            }
            $rows[] = self::content_row($course->shortname, self::TARGET_SECTION, (string) $mapping->sectionnumber, $mapping);
        }
        return $rows;
    }

    /**
     * One content mapping export row.
     *
     * @param string $shortname Course shortname.
     * @param string $targettype module or section.
     * @param string $target Activity idnumber or name, or section number.
     * @param \stdClass $mapping Mapping record with outcome fields.
     * @return array
     */
    private static function content_row(string $shortname, string $targettype, string $target, \stdClass $mapping): array {
        return [
            $shortname,
            $targettype,
            $target,
            $mapping->frameworkcode . '.' . $mapping->outcomecode,
            $mapping->role,
            $mapping->weight === null ? '' : (string) $mapping->weight,
            (string) (int) $mapping->priority,
            (string) (int) $mapping->effectivefrom,
            $mapping->effectiveto === null ? '' : (string) (int) $mapping->effectiveto,
            (string) ($mapping->notes ?? ''),
        ];
    }

    // ---------------------------------------------------------------- import.

    /**
     * Resolve and validate question mapping rows without writing anything.
     *
     * @param array $rows Raw rows keyed by header.
     * @return array{0: array, 1: bool} Preview rows (number, data, errors, note) and validity.
     */
    public static function preview_question_rows(array $rows): array {
        return self::preview_rows($rows, [self::class, 'prepare_question_row']);
    }

    /**
     * Resolve and validate content mapping rows without writing anything.
     *
     * @param array $rows Raw rows keyed by header.
     * @return array{0: array, 1: bool} Preview rows and validity.
     */
    public static function preview_content_rows(array $rows): array {
        return self::preview_rows($rows, [self::class, 'prepare_content_row']);
    }

    /**
     * Create the question mappings a validated file describes.
     *
     * Rows are created question version by question version, and each new
     * draft is then offered to the submission boundary; an assessed set that
     * is still incomplete stays draft, exactly as it would from the page.
     *
     * @param array $rows Raw rows keyed by header.
     * @return int Mappings created.
     */
    public static function commit_question_rows(array $rows): int {
        $cache = [];
        $byversion = [];
        foreach ($rows as $row) {
            $prepared = self::prepare_question_row($row, $cache);
            if ($prepared->skip) {
                continue;
            }
            $byversion[$prepared->questionversionid][] = $prepared;
        }
        $created = 0;
        foreach ($byversion as $prepared) {
            $ids = [];
            foreach ($prepared as $item) {
                $ids[] = question_mapping_service::create([
                    'questionversionid' => $item->questionversionid,
                    'itemverid' => $item->itemverid,
                    'role' => $item->role,
                    'weight' => $item->weight,
                    'notes' => $item->notes,
                    'effectivefrom' => $item->effectivefrom,
                    'effectiveto' => $item->effectiveto,
                ]);
                $created++;
            }
            foreach ($ids as $id) {
                if (question_mapping_service::get($id)->status !== workflow::DRAFT) {
                    continue;
                }
                try {
                    question_mapping_service::submit_for_review($id);
                } catch (validation_exception $e) {
                    // The set is not complete; the draft waits for the rest of it.
                    continue;
                }
            }
        }
        return $created;
    }

    /**
     * Create the content mappings a validated file describes, each carried to submission.
     *
     * @param array $rows Raw rows keyed by header.
     * @return int Mappings created.
     */
    public static function commit_content_rows(array $rows): int {
        $cache = [];
        $created = 0;
        foreach ($rows as $row) {
            $prepared = self::prepare_content_row($row, $cache);
            if ($prepared->skip) {
                continue;
            }
            $data = [
                'cinstid' => $prepared->cinstid,
                'itemverid' => $prepared->itemverid,
                'role' => $prepared->role,
                'weight' => $prepared->weight,
                'priority' => $prepared->priority,
                'notes' => $prepared->notes,
                'effectivefrom' => $prepared->effectivefrom,
                'effectiveto' => $prepared->effectiveto,
            ];
            if ($prepared->targettype === self::TARGET_MODULE) {
                $data['cmid'] = $prepared->targetid;
                $id = content_mapping_service::create_course_module($data);
                $type = content_mapping_service::TARGET_MODULE;
            } else {
                $data['sectionid'] = $prepared->targetid;
                $id = content_mapping_service::create_section($data);
                $type = content_mapping_service::TARGET_SECTION;
            }
            $created++;
            try {
                content_mapping_service::submit_for_review($type, $id);
            } catch (validation_exception $e) {
                continue;
            }
        }
        return $created;
    }

    /**
     * Run one row preparer over every row, collecting errors and notes.
     *
     * @param array $rows Raw rows.
     * @param callable $prepare Row preparer.
     * @return array{0: array, 1: bool}
     */
    private static function preview_rows(array $rows, callable $prepare): array {
        $cache = [];
        $preview = [];
        $valid = true;
        foreach ($rows as $index => $row) {
            $errors = [];
            $note = '';
            try {
                $prepared = $prepare($row, $cache);
                if ($prepared->skip) {
                    $note = get_string('importmapping_exists', 'local_outcomemap');
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                $valid = false;
            }
            $preview[] = (object) [
                'number' => $index + 2,
                'data' => $row,
                'errors' => $errors,
                'note' => $note,
                'validationexception' => null,
            ];
        }
        return [$preview, $valid];
    }

    /**
     * Resolve one question mapping row.
     *
     * @param array $row Raw row.
     * @param array $cache Per-import lookup cache.
     * @return \stdClass Prepared row with a skip flag.
     */
    private static function prepare_question_row(array $row, array &$cache): \stdClass {
        global $DB;
        $course = self::course($row['course'], $cache);
        $quiz = self::quiz($course, $row['quiz'], $cache);
        $question = self::question($course, $quiz, $row['question'], $row['questionversion'], $cache);
        $outcome = self::outcome_version($row['outcome'], $cache);
        $role = self::role($row['role'], question_mapping_service::ROLES);
        $weight = trim((string) $row['weight']);
        if ($role === content_mapping_service::ROLE_ASSESSES && $weight === '') {
            throw new validation_exception('assessedweightrequired', 'weight');
        }
        $effectivefrom = foundation_import_service::parse_date($row['effectivefrom'], 'effectivefrom');
        $effectiveto = foundation_import_service::parse_optional_date($row['effectiveto'], 'effectiveto');
        self::require_within_outcome_version($outcome, $effectivefrom, $effectiveto);

        $exists = $DB->record_exists_select(
            'local_outcomemap_qmap',
            'questionversionid = :qv AND itemverid = :iv AND role = :role AND status <> :retired'
                . ' AND (effectiveto IS NULL OR effectiveto > :from)',
            [
                'qv' => $question->questionversionid,
                'iv' => (int) $outcome->id,
                'role' => $role,
                'retired' => workflow::RETIRED,
                'from' => $effectivefrom,
            ]
        );
        return (object) [
            'skip' => $exists,
            'questionversionid' => (int) $question->questionversionid,
            'itemverid' => (int) $outcome->id,
            'role' => $role,
            'weight' => $weight === '' ? null : $weight,
            'notes' => trim((string) $row['notes']) === '' ? null : trim((string) $row['notes']),
            'effectivefrom' => $effectivefrom,
            'effectiveto' => $effectiveto,
        ];
    }

    /**
     * Resolve one content mapping row.
     *
     * @param array $row Raw row.
     * @param array $cache Per-import lookup cache.
     * @return \stdClass Prepared row with a skip flag.
     */
    private static function prepare_content_row(array $row, array &$cache): \stdClass {
        global $DB;
        $course = self::course($row['course'], $cache);
        $cinst = self::course_instance($course, $cache);
        $targettype = strtolower(trim((string) $row['targettype']));
        if (!in_array($targettype, [self::TARGET_MODULE, self::TARGET_SECTION], true)) {
            throw new validation_exception('importmapping_targettype', 'targettype', $targettype);
        }
        $targetid = $targettype === self::TARGET_MODULE
            ? self::module($course, $row['target'], $cache)
            : self::section($course, $row['target']);
        $outcome = self::outcome_version($row['outcome'], $cache);
        $role = self::role($row['role'], content_mapping_service::ROLES);
        $weight = trim((string) $row['weight']);
        if ($role === content_mapping_service::ROLE_ASSESSES && $weight === '') {
            throw new validation_exception('assessedweightrequired', 'weight');
        }
        $priority = trim((string) $row['priority']);
        if ($priority !== '' && !preg_match('/^\d+$/D', $priority)) {
            throw new validation_exception('invalidfield', 'priority', $priority);
        }
        $effectivefrom = foundation_import_service::parse_date($row['effectivefrom'], 'effectivefrom');
        $effectiveto = foundation_import_service::parse_optional_date($row['effectiveto'], 'effectiveto');
        self::require_within_outcome_version($outcome, $effectivefrom, $effectiveto);

        $table = $targettype === self::TARGET_MODULE ? 'local_outcomemap_cmmap' : 'local_outcomemap_secmap';
        $field = $targettype === self::TARGET_MODULE ? 'cmid' : 'sectionid';
        $exists = $DB->record_exists_select(
            $table,
            "cinstid = :cinst AND $field = :target AND itemverid = :iv AND role = :role AND status <> :retired"
                . ' AND (effectiveto IS NULL OR effectiveto > :from)',
            [
                'cinst' => (int) $cinst->id,
                'target' => $targetid,
                'iv' => (int) $outcome->id,
                'role' => $role,
                'retired' => workflow::RETIRED,
                'from' => $effectivefrom,
            ]
        );
        return (object) [
            'skip' => $exists,
            'cinstid' => (int) $cinst->id,
            'targettype' => $targettype,
            'targetid' => $targetid,
            'itemverid' => (int) $outcome->id,
            'role' => $role,
            'weight' => $weight === '' ? null : $weight,
            'priority' => $priority === '' ? 0 : (int) $priority,
            'notes' => trim((string) $row['notes']) === '' ? null : trim((string) $row['notes']),
            'effectivefrom' => $effectivefrom,
            'effectiveto' => $effectiveto,
        ];
    }

    // ------------------------------------------------------------- resolvers.

    /**
     * Course by shortname.
     *
     * @param string $shortname Shortname cell.
     * @param array $cache Lookup cache.
     * @return \stdClass Course record.
     */
    private static function course(string $shortname, array &$cache): \stdClass {
        global $DB;
        $shortname = trim($shortname);
        if (!isset($cache['course'][$shortname])) {
            $course = $shortname === '' ? false : $DB->get_record('course', ['shortname' => $shortname]);
            if (!$course) {
                throw new validation_exception('importmapping_nocourse', 'course', $shortname);
            }
            $cache['course'][$shortname] = $course;
        }
        return $cache['course'][$shortname];
    }

    /**
     * The single approved, confirmed course instance of a Moodle course.
     *
     * @param \stdClass $course Course.
     * @param array $cache Lookup cache.
     * @return \stdClass Course instance record.
     */
    private static function course_instance(\stdClass $course, array &$cache): \stdClass {
        global $DB;
        if (!isset($cache['cinst'][$course->id])) {
            $instances = $DB->get_records('local_outcomemap_cinst', [
                'moodlecourseid' => (int) $course->id,
                'status' => workflow::APPROVED,
                'confirmed' => 1,
            ]);
            if (!$instances) {
                throw new validation_exception('importmapping_nocinst', 'course', $course->shortname);
            }
            if (count($instances) > 1) {
                throw new validation_exception('importmapping_ambiguouscinst', 'course', $course->shortname);
            }
            $cache['cinst'][$course->id] = reset($instances);
        }
        return $cache['cinst'][$course->id];
    }

    /**
     * Quiz by name within a course.
     *
     * @param \stdClass $course Course.
     * @param string $name Quiz name cell.
     * @param array $cache Lookup cache.
     * @return \stdClass Quiz summary with cmid.
     */
    private static function quiz(\stdClass $course, string $name, array &$cache): \stdClass {
        $name = trim($name);
        if (!isset($cache['quizzes'][$course->id])) {
            $cache['quizzes'][$course->id] = question_browser_service::quizzes((int) $course->id);
        }
        $matches = array_values(array_filter(
            $cache['quizzes'][$course->id],
            static fn($quiz): bool => \core_text::strtolower($quiz->name) === \core_text::strtolower($name)
        ));
        if (!$matches) {
            throw new validation_exception('importmapping_noquiz', 'quiz', $name);
        }
        if (count($matches) > 1) {
            throw new validation_exception('importmapping_ambiguousquiz', 'quiz', $name);
        }
        return $matches[0];
    }

    /**
     * Question by name among the exact versions a quiz uses.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $quiz Quiz summary.
     * @param string $name Question name cell.
     * @param string $version Question version cell, blank for whatever the quiz uses.
     * @param array $cache Lookup cache.
     * @return \stdClass Question row from the quiz detail.
     */
    private static function question(\stdClass $course, \stdClass $quiz, string $name, string $version, array &$cache): \stdClass {
        $name = trim($name);
        if (!isset($cache['questions'][$quiz->cmid])) {
            $detail = question_browser_service::quiz_detail((int) $course->id, (int) $quiz->cmid);
            $byname = [];
            foreach ($detail->slots as $slot) {
                foreach ($slot->questions as $question) {
                    if ($question->missing) {
                        continue;
                    }
                    $byname[\core_text::strtolower($question->name)][$question->questionversionid] = $question;
                }
            }
            $cache['questions'][$quiz->cmid] = $byname;
        }
        $matches = $cache['questions'][$quiz->cmid][\core_text::strtolower($name)] ?? [];
        if (!$matches) {
            throw new validation_exception('importmapping_noquestion', 'question', $name);
        }
        if (count($matches) > 1) {
            throw new validation_exception('importmapping_ambiguousquestion', 'question', $name);
        }
        $question = reset($matches);
        $version = trim($version);
        if ($version !== '' && (int) $version !== (int) $question->questionversion) {
            throw new validation_exception('importmapping_questionversion', 'questionversion', (object) [
                'question' => $name,
                'expected' => $version,
                'actual' => $question->questionversion,
            ]);
        }
        return $question;
    }

    /**
     * Latest approved outcome version by FRAMEWORK.CODE label, or by version or outcome UUID.
     *
     * @param string $value Outcome cell.
     * @param array $cache Lookup cache.
     * @return \stdClass Outcome version record.
     */
    private static function outcome_version(string $value, array &$cache): \stdClass {
        global $DB;
        $value = trim($value);
        if (isset($cache['outcome'][$value])) {
            return $cache['outcome'][$value];
        }
        $isuuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value);
        if ($isuuid) {
            $uuid = strtolower($value);
            $records = $DB->get_records_sql(
                "SELECT v.*
                   FROM {local_outcomemap_itemver} v
                   JOIN {local_outcomemap_item} i ON i.id = v.itemid
                  WHERE (v.uuid = :vuuid OR i.uuid = :iuuid) AND v.status = :status
               ORDER BY v.version DESC",
                ['vuuid' => $uuid, 'iuuid' => $uuid, 'status' => workflow::APPROVED]
            );
        } else {
            $dot = strpos($value, '.');
            $records = [];
            if ($dot !== false && $dot > 0 && $dot < strlen($value) - 1) {
                $records = $DB->get_records_sql(
                    "SELECT v.*
                       FROM {local_outcomemap_itemver} v
                       JOIN {local_outcomemap_item} i ON i.id = v.itemid
                       JOIN {local_outcomemap_fw} fw ON fw.id = i.frameworkid
                      WHERE fw.code = :fwcode AND i.code = :code AND v.status = :status AND fw.status <> :retired
                   ORDER BY v.version DESC",
                    [
                        'fwcode' => substr($value, 0, $dot),
                        'code' => substr($value, $dot + 1),
                        'status' => workflow::APPROVED,
                        'retired' => workflow::RETIRED,
                    ]
                );
            }
        }
        if (!$records) {
            throw new validation_exception('importmapping_nooutcome', 'outcome', $value);
        }
        $items = [];
        foreach ($records as $record) {
            $items[(int) $record->itemid] = true;
        }
        if (count($items) > 1) {
            throw new validation_exception('ambiguouscode', 'outcome', $value);
        }
        $cache['outcome'][$value] = reset($records);
        return $cache['outcome'][$value];
    }

    /**
     * Activity by idnumber, then by unique name, within a course.
     *
     * @param \stdClass $course Course.
     * @param string $target Target cell.
     * @param array $cache Lookup cache.
     * @return int Course module id.
     */
    private static function module(\stdClass $course, string $target, array &$cache): int {
        $target = trim($target);
        if (!isset($cache['cms'][$course->id])) {
            $cache['cms'][$course->id] = get_fast_modinfo($course)->get_cms();
        }
        $byid = [];
        $byname = [];
        foreach ($cache['cms'][$course->id] as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            if (trim((string) $cm->idnumber) !== '' && trim((string) $cm->idnumber) === $target) {
                $byid[] = (int) $cm->id;
            }
            if (\core_text::strtolower($cm->get_formatted_name()) === \core_text::strtolower($target)) {
                $byname[] = (int) $cm->id;
            }
        }
        $matches = $byid ?: $byname;
        if (!$matches) {
            throw new validation_exception('importmapping_notarget', 'target', $target);
        }
        if (count($matches) > 1) {
            throw new validation_exception('importmapping_ambiguoustarget', 'target', $target);
        }
        return $matches[0];
    }

    /**
     * Section by number, or by unique name, within a course.
     *
     * @param \stdClass $course Course.
     * @param string $target Target cell.
     * @return int Course section id.
     */
    private static function section(\stdClass $course, string $target): int {
        global $DB;
        $target = trim($target);
        if (preg_match('/^\d+$/D', $target)) {
            $id = $DB->get_field('course_sections', 'id', ['course' => (int) $course->id, 'section' => (int) $target]);
            if (!$id) {
                throw new validation_exception('importmapping_notarget', 'target', $target);
            }
            return (int) $id;
        }
        $matches = [];
        foreach ($DB->get_records('course_sections', ['course' => (int) $course->id]) as $section) {
            $name = get_section_name($course, $section);
            if (\core_text::strtolower($name) === \core_text::strtolower($target)) {
                $matches[] = (int) $section->id;
            }
        }
        if (!$matches) {
            throw new validation_exception('importmapping_notarget', 'target', $target);
        }
        if (count($matches) > 1) {
            throw new validation_exception('importmapping_ambiguoustarget', 'target', $target);
        }
        return $matches[0];
    }

    /**
     * A recognised mapping role.
     *
     * @param string $value Role cell.
     * @param string[] $roles Allowed roles.
     * @return string
     */
    private static function role(string $value, array $roles): string {
        $role = strtolower(trim($value));
        if (!in_array($role, $roles, true)) {
            throw new validation_exception('importmapping_role', 'role', $value);
        }
        return $role;
    }

    /**
     * A mapping's range must sit inside its outcome version's range, as the services require.
     *
     * Surfaced in the preview so an outcome version dated after the mapping —
     * the usual state on a site that recorded its outcomes late — is named
     * before anything is written.
     *
     * @param \stdClass $outcome Outcome version.
     * @param int $from Mapping start.
     * @param int|null $to Mapping end.
     */
    private static function require_within_outcome_version(\stdClass $outcome, int $from, ?int $to): void {
        if (
            $from < (int) $outcome->effectivefrom
                || ($outcome->effectiveto !== null && ($to === null || $to > (int) $outcome->effectiveto))
        ) {
            throw new validation_exception('mappingoutsideoutcomeversion', 'effectivefrom', userdate((int) $outcome->effectivefrom));
        }
    }
}
