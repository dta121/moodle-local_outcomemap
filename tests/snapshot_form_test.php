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

namespace local_outcomemap;

use local_outcomemap\api\workflow;
use local_outcomemap\form\snapshot_form;
use local_outcomemap\local\uuid;

/**
 * Tests that the capture form refuses the two submissions that used to slip through.
 *
 * The reporting period was a free-text box. Typing an academic year that no course
 * instance carried reached the service and died with "No approved, confirmed course
 * instances belong to this program and reporting period" on an error page. Typing a
 * period that WAS already captured did something worse: with no previous version,
 * create_draft() mints a fresh lineage at version one without checking, so a second
 * independent version-one record appeared for the same period and nothing recorded
 * which of the two was authoritative.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_outcomemap\form\snapshot_form
 */
final class snapshot_form_test extends \advanced_testcase {
    /** @var int Approved program owning the membership. */
    private int $programid;

    /** @var int Approved catalog course. */
    private int $catalogcourseid;

    /** @var string Period code that resolves to a course instance. */
    private string $period = '2026-T1';

    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $now = time();
        $course = $this->getDataGenerator()->create_course();

        $this->programid = (int) $DB->insert_record('local_outcomemap_program', (object) [
            'uuid' => uuid::generate(),
            'code' => 'SNAP-PROGRAM',
            'name' => 'Snapshot form program',
            'description' => null,
            'externalid' => null,
            'programtype' => 'graduate',
            'credential' => 'degree',
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $this->catalogcourseid = (int) $DB->insert_record('local_outcomemap_course', (object) [
            'uuid' => uuid::generate(),
            'code' => 'SNAP-COURSE',
            'name' => 'Snapshot form course',
            'description' => null,
            'siskey' => null,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_outcomemap_cinst', (object) [
            'uuid' => uuid::generate(),
            'courseid' => $this->catalogcourseid,
            'moodlecourseid' => $course->id,
            'periodcode' => $this->period,
            'externalid' => null,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
            'confirmedby' => null,
            'confirmedat' => $now,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_outcomemap_progcourse', (object) [
            'uuid' => uuid::generate(),
            'programid' => $this->programid,
            'courseid' => $this->catalogcourseid,
            'status' => workflow::APPROVED,
            'effectivefrom' => $now - DAYSECS,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
    }

    /**
     * Build the form the way snapshots.php does, so the option set under test is real.
     *
     * @param string $currentperiod Frozen period when correcting.
     * @param bool $iscorrection Whether this is a correction version.
     * @return snapshot_form
     */
    private function make_form(string $currentperiod = '', bool $iscorrection = false): snapshot_form {
        global $CFG;
        require_once($CFG->dirroot . '/local/outcomemap/lib.php');
        return new snapshot_form(new \moodle_url('/local/outcomemap/snapshots.php'), [
            'options' => \local_outcomemap_snapshot_options(),
            'iscorrection' => $iscorrection,
            'currentperiod' => $currentperiod,
        ]);
    }

    /**
     * Run the form's own validation over a simulated submission.
     *
     * @param array $submitted Simulated submission.
     * @param string $currentperiod Frozen period when correcting.
     * @param bool $iscorrection Whether this is a correction version.
     * @return array Validation errors keyed by field.
     */
    private function errors(array $submitted, string $currentperiod = '', bool $iscorrection = false): array {
        $form = $this->make_form($currentperiod, $iscorrection);
        return $form->validation($submitted + [
            'programid' => $this->programid,
            'periodcode' => $this->period,
            'cohortid' => 0,
            'notes' => '',
            'correctionreason' => '',
            'previousid' => 0,
        ], []);
    }

    /** Insert a frozen capture for the resolving period. */
    private function freeze_existing(): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('local_outcomemap_snapshot', (object) [
            'snapshotuuid' => uuid::generate(),
            'version' => 1,
            'previousid' => null,
            'programid' => $this->programid,
            'periodcode' => $this->period,
            'cohortid' => null,
            'policyid' => 0,
            'populationsource' => 'active_enrolments_at_freeze',
            'retentionbasis' => 'institutional_record_anonymised',
            'populationat' => $now,
            'populationcount' => 3,
            'suppressionthreshold' => 5,
            'subjecthashmethod' => 'sha256',
            'status' => 'frozen',
            'notes' => null,
            'correctionreason' => null,
            'manifesthash' => str_repeat('a', 64),
            'payloadhash' => str_repeat('b', 64),
            'pluginversion' => '2026072900',
            'algoversion' => 'outcomemap-v1',
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
    }

    /**
     * Only periods that resolve to an approved, confirmed instance are offered.
     */
    public function test_period_choices_offer_only_resolving_periods(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/outcomemap/lib.php');
        $periods = \local_outcomemap_snapshot_periods();

        $this->assertArrayHasKey($this->programid, $periods);
        $this->assertSame([$this->period => 1], $periods[$this->programid],
            'The period must be listed once, with its course-instance count.');
    }

    /**
     * A period no instance carries is refused by the form, not by the service.
     */
    public function test_unresolved_period_is_a_form_error(): void {
        $errors = $this->errors(['periodcode' => '2026']);

        $this->assertArrayHasKey('periodcode', $errors,
            'An academic year no instance carries must be caught before create_draft().');
        $this->assertStringContainsString($this->period, $errors['periodcode'],
            'The error must name the periods that would work.');
    }

    /**
     * A period that resolves and is not yet captured validates.
     */
    public function test_resolving_uncaptured_period_validates(): void {
        $this->assertSame([], $this->errors([]),
            'The one submission that should succeed must not be blocked.');
    }

    /**
     * A period already captured is refused, and points at the correction action.
     */
    public function test_already_captured_period_is_refused(): void {
        $existing = $this->freeze_existing();

        $errors = $this->errors([]);

        $this->assertArrayHasKey('periodcode', $errors,
            'A second version-one lineage for one period must not be creatable here.');
        $this->assertStringContainsString((string) $existing, $errors['periodcode'],
            'The error must identify the capture that already holds the period.');
    }

    /**
     * Correcting an existing capture is exempt: that is the supported route.
     */
    public function test_correction_is_not_blocked_by_the_existing_capture(): void {
        $existing = $this->freeze_existing();

        $errors = $this->errors([
            'previousid' => $existing,
            'correctionreason' => 'Recomputed after a mapping correction.',
        ], $this->period, true);

        $this->assertSame([], $errors,
            'The duplicate guard must not block the correction it recommends.');
    }

    /**
     * A correction still has to say why, which was already true and must stay true.
     */
    public function test_correction_without_a_reason_is_still_refused(): void {
        $existing = $this->freeze_existing();

        $errors = $this->errors(['previousid' => $existing], $this->period, true);

        $this->assertArrayHasKey('correctionreason', $errors);
    }
}
