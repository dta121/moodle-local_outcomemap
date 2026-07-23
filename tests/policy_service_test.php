<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap;

use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Tests for governed policy draft management and versioning.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class policy_service_test extends \advanced_testcase {
    /**
     * Creates a system manager who can independently approve policies.
     *
     * @return \stdClass Reviewer user.
     */
    private function create_reviewer(): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $user->id, \context_system::instance()->id);
        return $user;
    }

    /**
     * Tests create, read, update, list, and delete for a draft policy.
     */
    public function test_draft_crud_replaces_bands_and_is_audited(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $id = policy_service::create([
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Initial calculation policy',
            'config' => [
                'minitems' => 2,
                'minweightedpossible' => '5',
                'requiremanualgrading' => true,
                'displayscale' => 1,
            ],
            'bands' => [
                [
                    'code' => 'developing',
                    'name' => 'Developing',
                    'maxpercent' => '70',
                    'maxinclusive' => false,
                ],
                [
                    'code' => 'achieved',
                    'name' => 'Achieved',
                    'minpercent' => '70',
                    'mininclusive' => true,
                ],
            ],
            'effectivefrom' => 1704067200,
        ]);

        $record = policy_service::get($id);
        $this->assertSame(workflow::DRAFT, $record->status);
        $this->assertSame('5.0000000000', $record->config['minweightedpossible']);
        $this->assertCount(2, $record->bands);

        policy_service::update_draft($id, [
            'name' => 'Revised calculation policy',
            'config' => [
                'minitems' => 3,
                'minweightedpossible' => '10.5',
                'requiremanualgrading' => false,
                'displayscale' => 2,
            ],
            'bands' => [
                [
                    'code' => 'review',
                    'name' => 'Review needed',
                    'maxpercent' => '80',
                    'maxinclusive' => false,
                ],
                [
                    'code' => 'met',
                    'name' => 'Met',
                    'minpercent' => '80',
                    'mininclusive' => true,
                ],
            ],
            'reason' => 'Governance draft correction',
        ]);

        $record = policy_service::get($id);
        $this->assertSame('Revised calculation policy', $record->name);
        $this->assertSame(3, $record->config['minitems']);
        $this->assertSame('10.5000000000', $record->config['minweightedpossible']);
        $this->assertSame(['review', 'met'], array_column($record->bands, 'code'));

        // A partial draft metadata edit must preserve typed config and bands.
        policy_service::update_draft($id, ['name' => 'Final calculation policy']);
        $record = policy_service::get($id);
        $this->assertSame('Final calculation policy', $record->name);
        $this->assertSame(3, $record->config['minitems']);
        $this->assertSame(['review', 'met'], array_column($record->bands, 'code'));
        $listed = policy_service::list_all();
        $this->assertArrayHasKey($id, $listed);
        $this->assertCount(2, $listed[$id]->bands);
        $this->assertTrue($DB->record_exists('local_outcomemap_audit', [
            'objecttype' => 'policy',
            'objectid' => $id,
            'action' => 'update',
        ]));

        policy_service::delete_draft($id, 'Discarded before submission');
        $this->assertFalse($DB->record_exists('local_outcomemap_policy', ['id' => $id]));
        $this->assertFalse($DB->record_exists('local_outcomemap_band', ['policyid' => $id]));
        $this->assertTrue($DB->record_exists('local_outcomemap_audit', [
            'objecttype' => 'policy',
            'objectid' => $id,
            'action' => 'delete',
        ]));
    }

    /**
     * Tests that submitted policies are immutable and approved versions fork safely.
     */
    public function test_submitted_policy_is_immutable_and_new_version_keeps_identity(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();

        $id = policy_service::create([
            'policytype' => policy_service::TYPE_ATTEMPT_SELECTION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Attempt policy',
            'config' => ['method' => policy_service::METHOD_LATEST_COMPLETED],
            'effectivefrom' => 1704067200,
            'effectiveto' => 1735689600,
        ]);
        policy_service::submit_for_review($id);

        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    policy_service::update_draft($id, ['name' => 'Not allowed']);
                } else {
                    policy_service::delete_draft($id);
                }
                $this->fail('A submitted policy must not be mutable as a draft.');
            } catch (validation_exception $e) {
                $this->assertSame('approvedimmutable', $e->errorcode);
            }
        }

        $this->setUser($reviewer);
        policy_service::approve($id);
        $this->setAdminUser();
        $versionid = policy_service::create_version($id, [
            // These identity fields must be ignored for a governed new version.
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_ASSESSMENT,
            'scopeid' => 999999,
            'name' => 'Attempt policy v2',
            'config' => ['method' => policy_service::METHOD_HIGHEST_GRADED],
            'effectivefrom' => 1735689600,
        ]);

        $first = policy_service::get($id);
        $second = policy_service::get($versionid);
        $this->assertSame($first->policyuuid, $second->policyuuid);
        $this->assertSame(2, (int) $second->version);
        $this->assertSame(policy_service::TYPE_ATTEMPT_SELECTION, $second->policytype);
        $this->assertSame(policy_service::SCOPE_INSTITUTION, $second->scopetype);
        $this->assertNull($second->scopeid);
        $this->assertSame(policy_service::METHOD_HIGHEST_GRADED, $second->config['method']);
        $this->assertSame(workflow::DRAFT,
            $DB->get_field('local_outcomemap_policy', 'status', ['id' => $versionid]));
    }

    /**
     * Tests duplicate codes and touching inclusive band boundaries.
     */
    public function test_band_validation_rejects_ambiguous_ranges(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $base = [
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Invalid bands',
            'config' => ['minitems' => 1, 'displayscale' => 1],
            'effectivefrom' => 1704067200,
        ];

        try {
            policy_service::create($base + ['bands' => [
                ['code' => 'same', 'name' => 'First', 'maxpercent' => '50'],
                ['code' => 'same', 'name' => 'Second', 'minpercent' => '50'],
            ]]);
            $this->fail('Duplicate performance-band codes must be rejected before DML.');
        } catch (validation_exception $e) {
            $this->assertSame('duplicatebandcode', $e->errorcode);
        }

        try {
            policy_service::create($base + ['bands' => [
                [
                    'code' => 'lower',
                    'name' => 'Lower',
                    'maxpercent' => '50',
                    'maxinclusive' => true,
                ],
                [
                    'code' => 'upper',
                    'name' => 'Upper',
                    'minpercent' => '50',
                    'mininclusive' => true,
                ],
            ]]);
            $this->fail('Two inclusive bands must not share the same boundary value.');
        } catch (validation_exception $e) {
            $this->assertSame('bandsoverlap', $e->errorcode);
        }
    }

    /**
     * Tests typed validation and normalization for every release mode.
     */
    public function test_release_policy_config_supports_all_governed_modes(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $effectivefrom = time() - 100;

        foreach (policy_service::RELEASE_MODES as $index => $mode) {
            $config = ['mode' => $mode, 'releaseat' => $effectivefrom + 500];
            $id = policy_service::create([
                'policytype' => policy_service::TYPE_RELEASE,
                'scopetype' => policy_service::SCOPE_INSTITUTION,
                'name' => 'Release mode ' . $index,
                'config' => $config,
                'effectivefrom' => $effectivefrom,
            ]);
            $policy = policy_service::get($id);
            $expected = ['mode' => $mode];
            if ($mode === policy_service::RELEASE_SCHEDULED) {
                $expected['releaseat'] = $effectivefrom + 500;
            }
            $this->assertSame($expected, $policy->config, $mode);
            $this->assertSame([], $policy->bands, $mode);
        }

        foreach ([
            ['mode' => 'unknown'],
            ['mode' => policy_service::RELEASE_SCHEDULED],
            ['mode' => policy_service::RELEASE_SCHEDULED, 'releaseat' => 0],
        ] as $config) {
            try {
                policy_service::create([
                    'policytype' => policy_service::TYPE_RELEASE,
                    'scopetype' => policy_service::SCOPE_INSTITUTION,
                    'name' => 'Invalid release mode',
                    'config' => $config,
                    'effectivefrom' => $effectivefrom,
                ]);
                $this->fail('Invalid release configuration was accepted.');
            } catch (validation_exception $e) {
                $this->assertSame('invalidpolicyconfig', $e->errorcode);
            }
        }
    }

    /**
     * Tests set-based release resolution precedence and explicit no-default behavior.
     */
    public function test_resolve_many_uses_scope_precedence_and_returns_null_without_policy(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $catalogid = catalog_course_service::create([
            'code' => 'RELEASE-PRECEDENCE',
            'name' => 'Release precedence',
        ]);
        $cinstid = course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $course->id,
            'periodcode' => '2026-T1',
        ]);
        $othercinstid = course_instance_service::create([
            'courseid' => $catalogid,
            'moodlecourseid' => $othercourse->id,
            'periodcode' => '2026-T1',
        ]);
        $at = time();
        $requests = [
            'assessment' => ['cinstid' => $cinstid, 'cmid' => $cm->id],
            'instance' => ['cinstid' => $cinstid],
            'catalog' => ['cinstid' => $othercinstid],
            'institution' => [],
        ];
        $unconfigured = policy_service::resolve_many(policy_service::TYPE_RELEASE, $requests, $at);
        $this->assertSame(array_keys($requests), array_keys($unconfigured));
        foreach ($unconfigured as $policy) {
            $this->assertNull($policy);
        }

        $approve = function(array $data) use ($reviewer): int {
            $this->setAdminUser();
            $id = policy_service::create($data);
            policy_service::submit_for_review($id);
            $this->setUser($reviewer);
            policy_service::approve($id);
            $this->setAdminUser();
            return $id;
        };
        $base = [
            'policytype' => policy_service::TYPE_RELEASE,
            'config' => ['mode' => policy_service::RELEASE_MANUAL],
            'effectivefrom' => $at - 100,
        ];
        $institutionid = $approve($base + [
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Institution release',
        ]);
        $catalogpolicyid = $approve($base + [
            'scopetype' => policy_service::SCOPE_CATALOG_COURSE,
            'scopeid' => $catalogid,
            'name' => 'Catalog release',
        ]);
        $instancepolicyid = $approve($base + [
            'scopetype' => policy_service::SCOPE_COURSE_INSTANCE,
            'scopeid' => $cinstid,
            'name' => 'Instance release',
        ]);
        $assessmentpolicyid = $approve($base + [
            'scopetype' => policy_service::SCOPE_ASSESSMENT,
            'scopeid' => $cm->id,
            'name' => 'Assessment release',
        ]);

        $resolved = policy_service::resolve_many(policy_service::TYPE_RELEASE, $requests, $at);
        $this->assertSame($assessmentpolicyid, (int) $resolved['assessment']->id);
        $this->assertSame($instancepolicyid, (int) $resolved['instance']->id);
        $this->assertSame($catalogpolicyid, (int) $resolved['catalog']->id);
        $this->assertSame($institutionid, (int) $resolved['institution']->id);
    }

    /**
     * Tests that manual release is a separate, irreversible, audited action.
     */
    public function test_manual_release_requires_explicit_action_and_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $reviewer = $this->create_reviewer();
        $policyid = policy_service::create([
            'policytype' => policy_service::TYPE_RELEASE,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Explicit manual release',
            'config' => ['mode' => policy_service::RELEASE_MANUAL],
            'effectivefrom' => time() - 100,
        ]);
        policy_service::submit_for_review($policyid);
        $this->setUser($reviewer);
        policy_service::approve($policyid);

        $this->assertNull(policy_service::get($policyid)->manualreleasedat);
        $this->setAdminUser();
        $releaseid = policy_service::release_manual($policyid, 'Instructor authorized release');
        $this->assertSame($releaseid, policy_service::release_manual($policyid));
        $policy = policy_service::get($policyid);
        $this->assertGreaterThan(0, $policy->manualreleasedat);
        $this->assertSame(
            $policy->manualreleasedat,
            policy_service::manual_release_times([$policyid])[$policyid]
        );
        $this->assertTrue($DB->record_exists('local_outcomemap_audit', [
            'action' => 'manual_release',
            'objecttype' => 'policy_release',
            'objectid' => $releaseid,
        ]));
        $this->assertCount(1, $DB->get_records('local_outcomemap_policyrel', ['policyid' => $policyid]));
    }
}
