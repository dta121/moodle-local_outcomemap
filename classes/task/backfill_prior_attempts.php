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

namespace local_outcomemap\task;

use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\workflow;

/**
 * Catch-up recalculation of attempts finished before their governance existed.
 *
 * Walks every learner and mapped quiz in a stable (course, quiz, learner)
 * order, recalculating a bounded batch per run and requeueing itself with a
 * cursor until the walk is complete. Recalculation is idempotent, so a learner
 * whose results do not change is a no-op, and no result is marked stale while
 * the walk runs: the figures learners and staff already see stay visible.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_prior_attempts extends \core\task\adhoc_task {
    /**
     * Maximum recalculation tuples per run.
     */
    private const BATCH = 200;

    /**
     * Queue a deduplicated catch-up from the start of the walk.
     *
     * @param int|null $cmid Quiz course-module ID to limit the walk to, or null for the site.
     * @return void
     */
    public static function queue(?int $cmid): void {
        self::queue_from($cmid, null);
    }

    /**
     * Queue a walk continuing after a cursor.
     *
     * @param int|null $cmid Quiz course-module ID, or null for the site.
     * @param int[]|null $after Last processed [courseid, cmid, userid], or null to start.
     * @return void
     */
    private static function queue_from(?int $cmid, ?array $after): void {
        $task = new self();
        $task->set_custom_data(['cmid' => $cmid, 'after' => $after]);
        $task->set_component('local_outcomemap');
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Execute one batch of the walk.
     *
     * @return void
     */
    public function execute(): void {
        $data = (object) ($this->get_custom_data() ?? new \stdClass());
        $cmid = empty($data->cmid) ? null : (int) $data->cmid;
        $after = empty($data->after) ? null : array_map('intval', (array) $data->after);
        $tuples = self::next_tuples($cmid, $after, self::BATCH + 1);
        $more = count($tuples) > self::BATCH;
        $tuples = array_slice($tuples, 0, self::BATCH);
        $processed = 0;
        $errors = 0;
        $last = $after;
        foreach ($tuples as $tuple) {
            $last = [(int) $tuple->courseid, (int) $tuple->cmid, (int) $tuple->userid];
            try {
                calculation_service::recalculate_user_assessment(
                    (int) $tuple->courseid,
                    (int) $tuple->cmid,
                    (int) $tuple->userid
                );
                $processed++;
            } catch (\Throwable $e) {
                // Errors never erase prior valid results; surface and continue.
                $errors++;
                mtrace('local_outcomemap prior-attempt catch-up error: ' . $e->getMessage());
            }
        }
        mtrace("local_outcomemap prior-attempt catch-up: {$processed} recalculated, {$errors} errors.");
        if ($more) {
            self::queue_from($cmid, $last);
        }
    }

    /**
     * Select the next course, quiz, and learner tuples after a cursor.
     *
     * Only quizzes in courses with a confirmed approved course instance, and
     * attempts touching a question with a current approved assessed mapping,
     * can produce evidence, so nothing else is walked.
     *
     * @param int|null $cmid Quiz course-module ID, or null for the site.
     * @param int[]|null $after Last processed [courseid, cmid, userid], or null.
     * @param int $limit Maximum tuples.
     * @return \stdClass[] Tuples of courseid, cmid, and userid in walk order.
     */
    public static function next_tuples(?int $cmid, ?array $after, int $limit): array {
        global $DB;
        $where = '';
        $params = [
            'approved' => workflow::APPROVED,
            'mapapproved' => workflow::APPROVED,
            'currentmapapproved' => workflow::APPROVED,
        ];
        if ($cmid !== null) {
            $where .= ' AND cm.id = :cmid';
            $params['cmid'] = $cmid;
        }
        if ($after !== null) {
            [$course, $module, $user] = $after;
            $where .= ' AND (cm.course > :c1
                         OR (cm.course = :c2 AND cm.id > :m1)
                         OR (cm.course = :c3 AND cm.id = :m2 AND qa.userid > :u1))';
            $params += ['c1' => $course, 'c2' => $course, 'c3' => $course,
                'm1' => $module, 'm2' => $module, 'u1' => $user];
        }
        // A recordset, because get_records_sql() keys rows by their first
        // column and courseid repeats across learners and quizzes.
        $recordset = $DB->get_recordset_sql(
            "SELECT DISTINCT cm.course AS courseid, cm.id AS cmid, qa.userid
               FROM {quiz_attempts} qa
               JOIN {course_modules} cm ON cm.instance = qa.quiz
               JOIN {modules} md ON md.id = cm.module AND md.name = 'quiz'
              WHERE qa.state = 'finished' AND qa.preview = 0 $where
                AND EXISTS (
                    SELECT 1 FROM {local_outcomemap_cinst} ci
                     WHERE ci.moodlecourseid = cm.course AND ci.status = :approved AND ci.confirmed = 1)
                AND EXISTS (
                    SELECT 1
                      FROM {question_attempts} sqa
                      JOIN {question_versions} sqv ON sqv.questionid = sqa.questionid
                      JOIN {local_outcomemap_qmap} sm ON sm.questionversionid = sqv.id
                           AND sm.role = 'assesses' AND sm.status = :mapapproved
                           AND sm.version = (SELECT MAX(currentsm.version)
                                               FROM {local_outcomemap_qmap} currentsm
                                              WHERE currentsm.mappinguuid = sm.mappinguuid
                                                AND currentsm.status = :currentmapapproved)
                     WHERE sqa.questionusageid = qa.uniqueid)
           ORDER BY cm.course ASC, cm.id ASC, qa.userid ASC",
            $params,
            0,
            $limit
        );
        $tuples = [];
        foreach ($recordset as $row) {
            $tuples[] = $row;
        }
        $recordset->close();
        return $tuples;
    }
}
