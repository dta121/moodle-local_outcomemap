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
 * Course quiz and question-bank browsing service for question outcome mappings.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\service;

use core_question\local\bank\question_version_status;
use local_outcomemap\local\validation_exception;

/**
 * Projects a course's quizzes onto the exact question versions they use.
 *
 * Question outcome mappings are bound to exact question versions, so a course
 * page that offers mapping must resolve the same versions a quiz attempt would
 * use. Slot resolution is therefore delegated to `mod_quiz`, and random slots
 * are expanded to the pool a draw could select from, because a random final
 * exam is the case where per-question mapping matters most.
 *
 * Read access requires `local/outcomemap:viewdefinitions` on the course. Write
 * capability is reported per question for the user interface only; the
 * authoritative checks stay in {@see question_mapping_service}.
 */
final class question_browser_service extends base_service {
    /** Maximum question versions resolved for one page. */
    private const MAX_VERSIONS = 1000;

    /**
     * Summarise every quiz in a course with its mapping coverage.
     *
     * @param int $courseid Moodle course ID.
     * @return \stdClass[] One row per visible quiz, in course order.
     */
    public static function quizzes(int $courseid): array {
        $context = \context_course::instance($courseid, MUST_EXIST);
        require_capability('local/outcomemap:viewdefinitions', $context);

        // Resolve every quiz's slots first, collecting the categories the random
        // slots draw from, so the pools of the whole page cost one query rather
        // than one per slot. A randomly drawn final exam can carry fifty slots.
        $pending = [];
        $allcategories = [];
        $descendants = [];
        foreach (self::quiz_modules($courseid) as $cm) {
            $structure = self::slot_structure($cm);
            $fixed = [];
            $categories = [];
            $randomslots = 0;
            foreach ($structure as $slot) {
                if (self::is_random_slot($slot)) {
                    $randomslots++;
                    foreach (self::pool_categories($slot, $descendants) as $categoryid) {
                        $categories[$categoryid] = $categoryid;
                        $allcategories[$categoryid] = $categoryid;
                    }
                    continue;
                }
                if (!empty($slot->versionid)) {
                    $fixed[(int) $slot->versionid] = (int) $slot->versionid;
                }
            }
            $pending[] = [$cm, count($structure), $randomslots, $fixed, $categories];
        }
        if (!$pending) {
            return [];
        }

        $bycategory = self::pool_versions_by_category($allcategories);
        $quizzes = [];
        $versionids = [];
        foreach ($pending as [$cm, $slotcount, $randomslots, $slotversions, $categories]) {
            // A random slot's questions are the pool it could draw from. Omitting
            // them reports a fully mapped random exam as having nothing mapped,
            // because such an exam has few fixed slots or none at all.
            foreach ($categories as $categoryid) {
                foreach ($bycategory[$categoryid] ?? [] as $versionid) {
                    $slotversions[$versionid] = $versionid;
                }
            }
            $quizzes[] = (object) [
                'cmid' => (int) $cm->id,
                'name' => $cm->get_formatted_name(),
                'sectionname' => get_section_name($courseid, $cm->sectionnum),
                'slotcount' => $slotcount,
                'randomslots' => $randomslots,
                'versionids' => $slotversions,
                'questioncount' => count($slotversions),
                'mappedcount' => 0,
                'assessedcount' => 0,
            ];
            $versionids += $slotversions;
        }

        // One mapping load for the whole page rather than one per quiz.
        $mappings = self::load_mappings($versionids);
        foreach ($quizzes as $quiz) {
            foreach ($quiz->versionids as $versionid) {
                $records = $mappings[$versionid] ?? [];
                if (!$records) {
                    continue;
                }
                $quiz->mappedcount++;
                foreach ($records as $record) {
                    if ($record->role === content_mapping_service::ROLE_ASSESSES) {
                        $quiz->assessedcount++;
                        break;
                    }
                }
            }
            unset($quiz->versionids);
        }
        return $quizzes;
    }

    /**
     * Resolve one quiz into its slots, question versions, and mapping state.
     *
     * @param int $courseid Moodle course ID.
     * @param int $cmid Quiz course-module ID.
     * @return \stdClass Quiz name, slot rows, and the banks the slots draw from.
     */
    public static function quiz_detail(int $courseid, int $cmid): \stdClass {
        $context = \context_course::instance($courseid, MUST_EXIST);
        require_capability('local/outcomemap:viewdefinitions', $context);

        $cm = null;
        foreach (self::quiz_modules($courseid) as $candidate) {
            if ((int) $candidate->id === $cmid) {
                $cm = $candidate;
                break;
            }
        }
        if ($cm === null) {
            throw new validation_exception('recordnotfound', 'quiz', $cmid);
        }

        $slots = [];
        $versionids = [];
        foreach (self::slot_structure($cm) as $slot) {
            $row = (object) [
                'slot' => (int) $slot->slot,
                'displaynumber' => $slot->displaynumber !== null && $slot->displaynumber !== ''
                    ? (string) $slot->displaynumber
                    : (string) (int) $slot->slot,
                'maxmark' => (string) $slot->maxmark,
                'random' => self::is_random_slot($slot),
                'questions' => [],
                'poolname' => null,
                'pooltruncated' => false,
            ];
            if ($row->random) {
                [$row->questions, $row->poolname, $row->pooltruncated] = self::random_pool($slot);
            } else if (!empty($slot->versionid)) {
                $row->questions[] = (object) [
                    'questionid' => (int) $slot->questionid,
                    'questionversionid' => (int) $slot->versionid,
                    'questionversion' => (int) $slot->version,
                    'name' => (string) $slot->name,
                    'qtype' => (string) $slot->qtype,
                    'status' => (string) $slot->status,
                    'contextid' => (int) $slot->contextid,
                    'createdby' => (int) ($slot->createdby ?? 0),
                    'missing' => false,
                ];
            } else {
                // The slot's question has been deleted. Surface it as an
                // unmappable row rather than dropping the slot silently, so the
                // list and the quiz's own slot count still agree.
                $row->questions[] = (object) [
                    'questionid' => 0,
                    'questionversionid' => 0,
                    'questionversion' => 0,
                    'name' => (string) ($slot->name ?? get_string('missingquestion', 'quiz')),
                    'qtype' => '',
                    'status' => '',
                    'contextid' => 0,
                    'createdby' => 0,
                    'missing' => true,
                ];
            }
            foreach ($row->questions as $question) {
                if ($question->questionversionid > 0) {
                    $versionids[$question->questionversionid] = $question->questionversionid;
                }
            }
            $slots[] = $row;
        }

        $mappings = self::load_mappings($versionids);
        $bankcontexts = [];
        foreach ($slots as $slot) {
            foreach ($slot->questions as $question) {
                $question->mappings = $mappings[$question->questionversionid] ?? [];
                $question->canedit = !$question->missing && self::can_edit_question($question);
                if ($question->contextid > 0) {
                    $bankcontexts[$question->contextid] = $question->contextid;
                }
            }
        }

        return (object) [
            'cmid' => (int) $cm->id,
            'name' => $cm->get_formatted_name(),
            'slots' => $slots,
            'banks' => self::describe_banks($bankcontexts),
        ];
    }

    /**
     * Whether any question bank in the course exposes a mappable question.
     *
     * Used to decide whether the page can offer its apply panel at all, without
     * asserting that every individual question is writable.
     *
     * @param int $courseid Moodle course ID.
     * @return bool
     */
    public static function course_has_quizzes(int $courseid): bool {
        return self::quiz_modules($courseid) !== [];
    }

    /**
     * Return the visible, non-deleted quiz modules of a course.
     *
     * @param int $courseid Moodle course ID.
     * @return \cm_info[]
     */
    private static function quiz_modules(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $quizzes = [];
        foreach ($modinfo->get_instances_of('quiz') as $cm) {
            if ($cm->deletioninprogress || !$cm->uservisible) {
                continue;
            }
            $quizzes[(int) $cm->id] = $cm;
        }
        return $quizzes;
    }

    /**
     * Load one quiz's slot structure through the owning module.
     *
     * `mod_quiz` owns slot-to-version resolution, including requested versions,
     * latest usable versions, and missing questions. Reimplementing that here
     * would drift from what an attempt actually uses.
     *
     * @param \cm_info $cm Quiz course module.
     * @return \stdClass[] Slot rows indexed by slot number.
     */
    private static function slot_structure(\cm_info $cm): array {
        if (!class_exists(\mod_quiz\question\bank\qbank_helper::class)) {
            return [];
        }
        return \mod_quiz\question\bank\qbank_helper::get_question_structure(
            (int) $cm->instance,
            \context_module::instance((int) $cm->id)
        );
    }

    /**
     * Whether one slot draws a random question rather than naming a fixed one.
     *
     * Moodle 5.x sets a `random` flag on the slot, but Moodle 4.5 does not — it
     * only sets `qtype` to `random`. Both releases unpack the set reference into
     * `filtercondition` through the same converter, so that is the one signal
     * present on every supported version. Testing `random` alone silently
     * misreads every random slot on 4.5 as a deleted question.
     *
     * @param \stdClass $slot Slot row from the quiz structure.
     * @return bool
     */
    private static function is_random_slot(\stdClass $slot): bool {
        return !empty($slot->filtercondition) || ($slot->qtype ?? '') === 'random';
    }

    /**
     * Resolve the categories one random slot draws from.
     *
     * @param \stdClass $slot Slot row carrying an unpacked filter condition.
     * @param array $descendants Per-request cache of resolved descendant trees.
     * @return int[] Category IDs, empty when the slot names no category.
     */
    private static function pool_categories(\stdClass $slot, array &$descendants = []): array {
        $categoryid = (int) ($slot->category ?? 0);
        if (!$categoryid) {
            return [];
        }
        if (empty($slot->filtercondition['filter']['category']['filteroptions']['includesubcategories'])) {
            return [$categoryid];
        }
        if (!isset($descendants[$categoryid])) {
            $descendants[$categoryid] = self::descendant_categories($categoryid);
        }
        return array_merge([$categoryid], $descendants[$categoryid]);
    }

    /**
     * Group the latest ready question version of each entry by its category.
     *
     * The quiz list needs version IDs but none of the presentation columns
     * {@see random_pool()} loads, so this stays a narrow projection and resolves
     * every pool on the page in one statement.
     *
     * @param int[] $categoryids Category IDs keyed by ID.
     * @return array<int,int[]> Question-version IDs grouped by category ID.
     */
    private static function pool_versions_by_category(array $categoryids): array {
        global $DB;

        $categoryids = array_values($categoryids);
        if (!$categoryids) {
            return [];
        }
        $grouped = [];
        foreach (array_chunk($categoryids, self::MAX_VERSIONS) as $batch) {
            [$insql, $params] = $DB->get_in_or_equal($batch, SQL_PARAMS_NAMED, 'qc');
            $params['ready'] = question_version_status::QUESTION_STATUS_READY;
            $records = $DB->get_records_sql(
                "SELECT qv.id, qbe.questioncategoryid
                   FROM {question_bank_entries} qbe
                   JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                        AND qv.version = (
                            SELECT MAX(lv.version)
                              FROM {question_versions} lv
                             WHERE lv.questionbankentryid = qbe.id
                               AND lv.status = :ready
                        )
                  WHERE qbe.questioncategoryid $insql",
                $params
            );
            foreach ($records as $record) {
                $grouped[(int) $record->questioncategoryid][] = (int) $record->id;
            }
        }
        return $grouped;
    }

    /**
     * Expand a random slot into the pool a draw could select from.
     *
     * @param \stdClass $slot Slot row carrying an unpacked filter condition.
     * @return array{0:\stdClass[],1:string|null,2:bool} Questions, pool name, truncation flag.
     */
    private static function random_pool(\stdClass $slot): array {
        global $DB;

        $categoryid = (int) ($slot->category ?? 0);
        if (!$categoryid) {
            return [[], null, false];
        }
        $category = $DB->get_record('question_categories', ['id' => $categoryid], 'id,name,contextid');
        if (!$category) {
            return [[], null, false];
        }
        $categoryids = self::pool_categories($slot);

        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'qc');
        $params['ready'] = question_version_status::QUESTION_STATUS_READY;
        // One row per bank entry: the latest ready version, which is what a draw uses.
        $records = $DB->get_records_sql(
            "SELECT qv.id AS questionversionid, qv.version AS questionversion, qv.status,
                    q.id AS questionid, q.name, q.qtype, q.createdby, qc.contextid
               FROM {question_bank_entries} qbe
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                    AND qv.version = (
                        SELECT MAX(lv.version)
                          FROM {question_versions} lv
                         WHERE lv.questionbankentryid = qbe.id
                           AND lv.status = :ready
                    )
               JOIN {question} q ON q.id = qv.questionid
              WHERE qbe.questioncategoryid $insql
           ORDER BY q.name, q.id",
            $params,
            0,
            self::MAX_VERSIONS + 1
        );
        $truncated = count($records) > self::MAX_VERSIONS;
        if ($truncated) {
            $records = array_slice($records, 0, self::MAX_VERSIONS, true);
        }
        $questions = [];
        foreach ($records as $record) {
            $questions[] = (object) [
                'questionid' => (int) $record->questionid,
                'questionversionid' => (int) $record->questionversionid,
                'questionversion' => (int) $record->questionversion,
                'name' => (string) $record->name,
                'qtype' => (string) $record->qtype,
                'status' => (string) $record->status,
                'contextid' => (int) $record->contextid,
                'createdby' => (int) $record->createdby,
                'missing' => false,
            ];
        }
        return [$questions, (string) $category->name, $truncated];
    }

    /**
     * Collect every descendant of a question category.
     *
     * @param int $categoryid Root category ID.
     * @return int[] Descendant category IDs.
     */
    private static function descendant_categories(int $categoryid): array {
        global $DB;
        $found = [];
        $queue = [$categoryid];
        // Bounded by the category tree, and guarded against a cyclic parent.
        while ($queue) {
            $parent = (int) array_shift($queue);
            $children = $DB->get_fieldset_select('question_categories', 'id', 'parent = :parent', ['parent' => $parent]);
            foreach ($children as $child) {
                $child = (int) $child;
                if ($child === $categoryid || isset($found[$child])) {
                    continue;
                }
                $found[$child] = $child;
                $queue[] = $child;
            }
        }
        return array_values($found);
    }

    /**
     * Bulk-load mappings for the resolved version set.
     *
     * @param int[] $versionids Question-version IDs keyed by ID.
     * @return array Mapping records grouped by question-version ID.
     */
    private static function load_mappings(array $versionids): array {
        $versionids = array_values($versionids);
        if (!$versionids) {
            return [];
        }
        $mappings = [];
        foreach (array_chunk($versionids, self::MAX_VERSIONS) as $batch) {
            $mappings += question_mapping_service::get_for_question_versions($batch);
        }
        return $mappings;
    }

    /**
     * Report whether the current user may change one question's mappings.
     *
     * Mirrors the authoritative check in {@see question_mapping_service} so the
     * page offers only controls that would succeed.
     *
     * @param \stdClass $question Resolved question row.
     * @return bool
     */
    private static function can_edit_question(\stdClass $question): bool {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        // Deliberately not memoised across calls: a capability answer depends on
        // the acting user as well as the context, and Moodle already caches
        // capability lookups per user in loaded access data.
        $contextid = (int) $question->contextid;
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context || !has_capability('local/outcomemap:mapquestions', $context)) {
            return false;
        }
        return question_has_capability_on((object) [
            'id' => (int) $question->questionid,
            'contextid' => $contextid,
            'createdby' => (int) $question->createdby,
        ], 'edit');
    }

    /**
     * Name the question banks a quiz draws from, with a link target when known.
     *
     * @param int[] $contextids Question-category context IDs.
     * @return \stdClass[] Bank descriptions.
     */
    private static function describe_banks(array $contextids): array {
        $banks = [];
        foreach ($contextids as $contextid) {
            $context = \context::instance_by_id((int) $contextid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            $bankcmid = null;
            if ($context->contextlevel === CONTEXT_MODULE) {
                $bankcmid = (int) $context->instanceid;
            }
            $banks[] = (object) [
                'contextid' => (int) $contextid,
                'name' => $context->get_context_name(false, true),
                'cmid' => $bankcmid,
            ];
        }
        usort($banks, static fn(\stdClass $a, \stdClass $b): int => strcmp($a->name, $b->name));
        return $banks;
    }
}
