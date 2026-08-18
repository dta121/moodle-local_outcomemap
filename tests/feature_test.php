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

use local_outcomemap\local\feature;
use local_outcomemap\local\service\approval_service;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\remediation_service;
use local_outcomemap\local\workflow;
use local_outcomemap\reportbuilder\datasource\remediation_engagement;

/**
 * Tests for the optional-feature switches.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class feature_test extends \advanced_testcase {
    /**
     * Remediation is on unless an administrator turns it off, including when the
     * setting has never been written.
     */
    public function test_remediation_defaults_to_enabled(): void {
        $this->resetAfterTest(true);
        unset_config('enableremediation', 'local_outcomemap');
        $this->assertTrue(
            feature::remediation_enabled(),
            'An unset switch must not withdraw a feature an institution already relies on.'
        );

        set_config('enableremediation', 1, 'local_outcomemap');
        $this->assertTrue(feature::remediation_enabled());

        set_config('enableremediation', 0, 'local_outcomemap');
        $this->assertFalse(feature::remediation_enabled());
    }

    /**
     * * A request that reaches a disabled feature by direct URL is refused.
     */
    public function test_require_enabled_refuses_a_disabled_feature(): void {
        $this->resetAfterTest(true);
        // Enabled: no exception.
        feature::require_enabled(true, 'remediationdisabled');

        $this->expectException(\moodle_exception::class);
        feature::require_enabled(false, 'remediationdisabled');
    }

    /**
     * The remediation report source disappears from report creation when the
     * feature is off, even for a user with system-wide access.
     */
    public function test_remediation_report_source_is_withdrawn_when_disabled(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        set_config('enableremediation', 1, 'local_outcomemap');
        $this->assertTrue(
            remediation_engagement::is_available(),
            'An administrator should be offered the source while the feature is on.'
        );

        set_config('enableremediation', 0, 'local_outcomemap');
        $this->assertFalse(
            remediation_engagement::is_available(),
            'System-wide access must not keep a disabled feature in the source list.'
        );
    }

    /**
     * Turning remediation off removes its pending drafts from the approval queue
     * without touching the stored records.
     */
    public function test_approval_queue_drops_remediation_when_disabled(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $remediationid = $this->create_pending_remediation();

        // With the feature on, the reviewer has work to do.
        set_config('enableremediation', 1, 'local_outcomemap');
        $objecttypes = array_column(approval_service::list_pending(), 'objecttype');
        $this->assertContains(
            'remediation',
            $objecttypes,
            'A pending remediation draft must reach the approval queue while the feature is on.'
        );

        set_config('enableremediation', 0, 'local_outcomemap');
        $objecttypes = array_column(approval_service::list_pending(), 'objecttype');
        $this->assertNotContains(
            'remediation',
            $objecttypes,
            'A disabled feature must not queue work for a reviewer.'
        );

        $this->assertTrue(
            $DB->record_exists('local_outcomemap_remed', ['id' => $remediationid]),
            'Disabling a feature must not delete its records.'
        );
        $this->assertSame(
            workflow::NEEDS_REVIEW,
            $DB->get_field('local_outcomemap_remed', 'status', ['id' => $remediationid]),
            'The withdrawn draft must keep its state so re-enabling restores the queue.'
        );
    }

    /**
     * Create one remediation recommendation awaiting review.
     *
     * @return int The recommendation ID.
     */
    private function create_pending_remediation(): int {
        global $DB;
        $now = time();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $catalogid = catalog_course_service::create([
            'code' => 'REMED' . strtoupper(random_string(4)),
            'name' => 'Remediation switch course',
        ]);
        $cinstid = course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-T1',
        ]);
        course_instance_service::submit_for_review($cinstid);
        $reviewer = $this->getDataGenerator()->create_user();
        role_assign(
            (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST),
            $reviewer->id,
            \context_system::instance()->id
        );
        $this->setUser($reviewer);
        course_instance_service::confirm($cinstid);
        $this->setAdminUser();

        $frameworkid = framework_service::create([
            'code' => 'REMFW' . strtoupper(random_string(4)),
            'name' => 'Remediation switch outcomes',
            'ownertype' => framework_service::OWNER_COURSE,
            'ownerid' => $catalogid,
        ]);
        framework_service::submit_for_review($frameworkid);
        if (workflow::requires_independent_approval()) {
            $this->setUser($reviewer);
            framework_service::approve($frameworkid);
            $this->setAdminUser();
        }
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => 'R1',
            'statement' => 'Remediation switch outcome',
            'effectivefrom' => $now - 86400,
        ]);
        $itemverid = (int) $DB->get_field('local_outcomemap_itemver', 'id', ['itemid' => $itemid], MUST_EXIST);
        outcome_service::submit_for_review($itemverid);
        if (workflow::requires_independent_approval()) {
            $this->setUser($reviewer);
            outcome_service::approve($itemverid);
            $this->setAdminUser();
        }

        // Left in needs_review so it sits in the queue.
        $id = remediation_service::create([
            'cinstid' => $cinstid,
            'itemverid' => $itemverid,
            'purpose' => remediation_service::PURPOSE_REVIEW,
            'targettype' => remediation_service::TARGET_MODULE,
            'targetid' => $cm->id,
            'title' => 'Review the worked example',
            'explanation' => 'Revisit the unit before reattempting.',
            'required' => 1,
            'minpercent' => '0',
            'maxpercent' => '69.9999999999',
            'effectivefrom' => $now - 86400,
        ]);
        remediation_service::submit_for_review($id);
        return $id;
    }
}
