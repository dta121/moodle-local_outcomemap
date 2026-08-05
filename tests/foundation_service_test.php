<?php
// This file is part of Moodle - http://moodle.org/

namespace local_outcomemap;

use local_outcomemap\api\outcome_search;
use local_outcomemap\local\dto\outcome;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\program_course_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/** Tests for Milestone 1 definition, version, and multi-program services. */
final class foundation_service_test extends \advanced_testcase {
    /** Create a user with explicit system approval/read capabilities. */
    private function create_approver(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Outcome approver', 'outcomeapprover', 'Test approval role');
        $context = \context_system::instance();
        assign_capability('local/outcomemap:approve', CAP_ALLOW, $roleid, $context->id);
        assign_capability('local/outcomemap:viewdefinitions', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * The display label of an approved outcome can be corrected in place.
     *
     * A learner report resolves a stored result to the exact version it was
     * calculated against, so a label added on a later version would never reach
     * a result already calculated. The correction fills the label without
     * touching the statement, and records why against every row it moves.
     */
    public function test_correct_shortstatement_labels_an_approved_outcome(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $approver = $this->create_approver();

        $frameworkid = framework_service::create([
            'code' => 'MBA602-CLO',
            'name' => 'Course outcomes',
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        $this->setUser($approver);
        framework_service::approve($frameworkid);
        $this->setAdminUser();

        $statement = 'Describe the role that marketing plays in the economy, and its importance '
            . 'within the corporate structure, and the broader global environment';
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => '0a',
            'statement' => $statement,
            'effectivefrom' => 1704067200,
        ]);
        $versionid = (int) $DB->get_field('local_outcomemap_itemver', 'id',
            ['itemid' => $itemid, 'version' => 1], MUST_EXIST);
        outcome_service::submit_for_review($versionid);
        $this->setUser($approver);
        outcome_service::approve($versionid);
        $this->setAdminUser();

        $this->assertNull($DB->get_field('local_outcomemap_itemver', 'shortstatement',
            ['id' => $versionid], MUST_EXIST), 'The label starts unset.');

        $corrected = outcome_service::correct_shortstatement(
            [$versionid => "Marketing's role in the economy"],
            'Populated the learner-facing label from the approved course design.'
        );

        $this->assertSame(1, $corrected);
        $stored = $DB->get_record('local_outcomemap_itemver', ['id' => $versionid], '*', MUST_EXIST);
        $this->assertSame("Marketing's role in the economy", $stored->shortstatement);
        $this->assertSame($statement, $stored->statement, 'The normative wording must not move.');
        $this->assertSame(1, (int) $stored->version, 'No new version is created.');
        $this->assertSame(workflow::APPROVED, $stored->status);
        $this->assertTrue($DB->record_exists('local_outcomemap_audit', [
            'objecttype' => 'outcome_version',
            'action' => 'correct_shortstatement',
        ]), 'The correction must be audited.');

        // Idempotent: re-applying the same label writes nothing.
        $this->assertSame(0, outcome_service::correct_shortstatement(
            [$versionid => "Marketing's role in the economy"], 'Re-run.'));

        // A reason is mandatory, because this amends an approved record.
        try {
            outcome_service::correct_shortstatement([$versionid => 'Something else'], '  ');
            $this->fail('A correction was accepted without a reason.');
        } catch (validation_exception $e) {
            $this->assertSame('requiredfield', $e->errorcode);
        }

        // A draft is not a correction target; it should be edited instead.
        $draftid = outcome_service::create_version($itemid, [
            'statement' => $statement,
            'effectivefrom' => 1735689600,
            'changereason' => 'Later revision',
        ]);
        try {
            outcome_service::correct_shortstatement([$draftid => 'Draft label'], 'Not approved.');
            $this->fail('A draft version was accepted as a correction target.');
        } catch (validation_exception $e) {
            $this->assertSame('invalidtransition', $e->errorcode);
        }
    }

    /** Framework and outcome approval preserves immutable historical versions. */
    public function test_outcome_approval_and_effective_versions(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $approver = $this->create_approver();

        $frameworkid = framework_service::create([
            'code' => 'MBA',
            'name' => 'MBA program outcomes',
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        try {
            framework_service::approve($frameworkid);
            $this->fail('A creator was able to approve their own framework.');
        } catch (validation_exception $e) {
            $this->assertSame('creatorcannotapprove', $e->errorcode);
        }

        $this->setUser($approver);
        framework_service::approve($frameworkid);
        $this->setAdminUser();
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => 'PLO1',
            'statement' => 'Apply ethical leadership principles.',
            'effectivefrom' => 1704067200,
            'effectiveto' => 1735689600,
        ]);
        $version1 = $DB->get_record('local_outcomemap_itemver', ['itemid' => $itemid, 'version' => 1], '*', MUST_EXIST);
        outcome_service::submit_for_review($version1->id);
        $this->setUser($approver);
        outcome_service::approve($version1->id);

        $this->setAdminUser();
        $version2id = outcome_service::create_version($itemid, [
            'statement' => 'Apply ethical and evidence-informed leadership principles.',
            'effectivefrom' => 1735689600,
            'changereason' => 'Scheduled curriculum revision',
        ]);
        outcome_service::submit_for_review($version2id);
        $this->setUser($approver);
        outcome_service::approve($version2id);

        $stored1 = $DB->get_record('local_outcomemap_itemver', ['id' => $version1->id], '*', MUST_EXIST);
        $stored2 = $DB->get_record('local_outcomemap_itemver', ['id' => $version2id], '*', MUST_EXIST);
        $this->assertSame('Apply ethical leadership principles.', $stored1->statement);
        $this->assertSame(workflow::APPROVED, $stored1->status);
        $this->assertSame(2, (int) $stored2->version);
        $this->assertSame(workflow::APPROVED, $stored2->status);
        $this->assertGreaterThanOrEqual(6, $DB->count_records('local_outcomemap_audit'));

        $this->setAdminUser();
        $results = outcome_search::search(\context_system::instance(), 'PLO1', 1735689601);
        $this->assertCount(1, $results);
        $this->assertInstanceOf(outcome::class, $results[0]);
        $this->assertSame($stored2->uuid, $results[0]->versionuuid);
    }

    /** One catalog course can belong to multiple programs without text parsing. */
    public function test_catalog_course_can_belong_to_multiple_programs(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $approver = $this->create_approver();

        $mba = program_service::create(['code' => 'MBA', 'name' => 'Master of Business Administration']);
        $mei = program_service::create(['code' => 'MEI', 'name' => 'Master of Entrepreneurship and Innovation']);
        $course = catalog_course_service::create(['code' => 'MBA614', 'name' => 'Strategic Leadership']);
        $first = program_course_service::create([
            'programid' => $mba, 'courseid' => $course, 'effectivefrom' => 1704067200,
        ]);
        $second = program_course_service::create([
            'programid' => $mei, 'courseid' => $course, 'effectivefrom' => 1704067200,
        ]);
        program_course_service::submit_for_review($first);
        program_course_service::submit_for_review($second);

        $this->setUser($approver);
        program_course_service::approve($first);
        program_course_service::approve($second);

        $this->assertEquals(2, $DB->count_records('local_outcomemap_progcourse', [
            'courseid' => $course,
            'status' => workflow::APPROVED,
        ]));
    }

    /** Program type and credential values are explicit, validated, and backward compatible. */
    public function test_program_type_and_credential_are_governed(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $certificateid = program_service::create([
            'code' => 'UGCERT',
            'name' => 'Undergraduate analytics certificate',
            'programtype' => program_service::TYPE_UNDERGRADUATE,
            'credential' => program_service::CREDENTIAL_CERTIFICATE,
        ]);
        $certificate = $DB->get_record('local_outcomemap_program', ['id' => $certificateid], '*', MUST_EXIST);
        $this->assertSame(program_service::TYPE_UNDERGRADUATE, $certificate->programtype);
        $this->assertSame(program_service::CREDENTIAL_CERTIFICATE, $certificate->credential);

        $legacyid = program_service::create(['code' => 'LEGACY', 'name' => 'Legacy caller']);
        $legacy = $DB->get_record('local_outcomemap_program', ['id' => $legacyid], '*', MUST_EXIST);
        $this->assertSame(program_service::TYPE_GRADUATE, $legacy->programtype);
        $this->assertSame(program_service::CREDENTIAL_DEGREE, $legacy->credential);

        try {
            program_service::create([
                'code' => 'BADTYPE',
                'name' => 'Invalid type',
                'programtype' => 'doctoral',
            ]);
            $this->fail('An unsupported program type was accepted.');
        } catch (validation_exception $e) {
            $this->assertSame('invalidprogramtype', $e->errorcode);
        }

        try {
            program_service::create([
                'code' => 'BADCRED',
                'name' => 'Invalid credential',
                'credential' => 'diploma',
            ]);
            $this->fail('An unsupported credential was accepted.');
        } catch (validation_exception $e) {
            $this->assertSame('invalidcredential', $e->errorcode);
        }
    }
}
