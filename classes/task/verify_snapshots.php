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

use local_outcomemap\local\service\snapshot_service;

/**
 * Nightly full re-verification of every accreditation snapshot.
 *
 * The report page reuses a passing verdict for a day instead of re-hashing a
 * programme-wide capture on every view. This task is what keeps that verdict
 * honest: it verifies every capture in full, refreshes the verdicts that still
 * pass, and forgets the ones that do not, so the next view verifies again and
 * shows the failure. A failure also fails the task, so it surfaces in the task
 * logs rather than waiting for someone to open the report.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class verify_snapshots extends \core\task\scheduled_task {
    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskverifysnapshots', 'local_outcomemap');
    }

    /**
     * Verify every snapshot version.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        // Each capture is streamed, so memory stays flat; time is the cost.
        \core_php_time_limit::raise();
        $failed = [];
        $snapshots = $DB->get_records('local_outcomemap_snapshot', null, 'id ASC');
        foreach ($snapshots as $snapshot) {
            $start = microtime(true);
            try {
                snapshot_service::verify_and_record($snapshot);
                mtrace(sprintf('Snapshot %d verified (%.1fs).', $snapshot->id, microtime(true) - $start));
            } catch (\Throwable $e) {
                $failed[] = (int) $snapshot->id;
                mtrace(sprintf('Snapshot %d FAILED verification: %s', $snapshot->id, $e->getMessage()));
            }
        }
        if ($failed) {
            throw new \moodle_exception('snapshotverifyfailed', 'local_outcomemap', '', implode(', ', $failed));
        }
    }
}
