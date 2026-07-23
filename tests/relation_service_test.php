<?php
// This file is part of Moodle - http://moodle.org/

namespace local_outcomemap;

use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\local\validation_exception;

/** Tests for relationship validation and cycle detection. */
final class relation_service_test extends \advanced_testcase {
    private function create_approver(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Outcome approver', 'outcomeapprover2', 'Test approval role');
        $context = \context_system::instance();
        assign_capability('local/outcomemap:approve', CAP_ALLOW, $roleid, $context->id);
        assign_capability('local/outcomemap:viewdefinitions', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    private function create_approved_outcome(int $frameworkid, string $code, \stdClass $approver): int {
        global $DB;
        $this->setAdminUser();
        $itemid = outcome_service::create([
            'frameworkid' => $frameworkid,
            'code' => $code,
            'statement' => 'Statement for ' . $code,
            'effectivefrom' => 1704067200,
        ]);
        $versionid = $DB->get_field('local_outcomemap_itemver', 'id', ['itemid' => $itemid], MUST_EXIST);
        outcome_service::submit_for_review((int) $versionid);
        $this->setUser($approver);
        outcome_service::approve((int) $versionid);
        return $itemid;
    }

    private function approve_relation(int $source, int $target, \stdClass $approver): int {
        $this->setAdminUser();
        $id = relation_service::create([
            'sourceitemid' => $source,
            'targetitemid' => $target,
            'type' => relation_service::IS_CHILD_OF,
            'effectivefrom' => 1704067200,
        ]);
        relation_service::submit_for_review($id);
        $this->setUser($approver);
        relation_service::approve($id);
        return $id;
    }

    public function test_approval_rejects_mixed_hierarchy_cycle(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $approver = $this->create_approver();
        $frameworkid = framework_service::create([
            'code' => 'MBA614',
            'name' => 'MBA614 outcomes',
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($frameworkid);
        $this->setUser($approver);
        framework_service::approve($frameworkid);

        $a = $this->create_approved_outcome($frameworkid, 'CLO1', $approver);
        $b = $this->create_approved_outcome($frameworkid, 'CLO2', $approver);
        $c = $this->create_approved_outcome($frameworkid, 'CLO3', $approver);
        $this->approve_relation($a, $b, $approver);
        $this->approve_relation($b, $c, $approver);

        $this->setAdminUser();
        $cycleid = relation_service::create([
            'sourceitemid' => $c,
            'targetitemid' => $a,
            'type' => relation_service::CONTRIBUTES_TO,
            'weight' => '0.50000',
            'effectivefrom' => 1704067200,
        ]);
        relation_service::submit_for_review($cycleid);
        $this->setUser($approver);
        try {
            relation_service::approve($cycleid);
            $this->fail('A cyclic relationship was approved.');
        } catch (validation_exception $e) {
            $this->assertSame('cycle', $e->errorcode);
        }
        $this->assertSame('needs_review', $DB->get_field('local_outcomemap_rel', 'status', ['id' => $cycleid]));
    }

    public function test_contribution_weight_is_stored_at_fixed_scale(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $frameworkid = framework_service::create([
            'code' => 'WEIGHTS', 'name' => 'Weight test', 'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        $source = outcome_service::create([
            'frameworkid' => $frameworkid, 'code' => 'A', 'statement' => 'A', 'effectivefrom' => 1704067200,
        ]);
        $target = outcome_service::create([
            'frameworkid' => $frameworkid, 'code' => 'B', 'statement' => 'B', 'effectivefrom' => 1704067200,
        ]);
        $id = relation_service::create([
            'sourceitemid' => $source,
            'targetitemid' => $target,
            'type' => relation_service::CONTRIBUTES_TO,
            'weight' => '0.25',
            'effectivefrom' => 1704067200,
        ]);
        $this->assertSame('0.2500000000', $DB->get_field('local_outcomemap_rel', 'weight', ['id' => $id]));
    }
}
