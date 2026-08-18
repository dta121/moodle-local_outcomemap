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

use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\output\policies_page;

/**
 * Tests the outcome policies page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class policies_page_test extends \advanced_testcase {
    /**
     * @var int Fixed reference time; policies below start well before it.
     */
    private const NOW = 1785110400;

    /**
     * Create a policy and carry it to approved.
     *
     * @param string $type Policy type.
     * @param string $scopetype Scope type.
     * @param int|null $scopeid Scope id.
     * @param array $config Typed configuration.
     * @return int Policy id.
     */
    private function policy(
        string $type,
        string $scopetype,
        ?int $scopeid = null,
        array $config = []
    ): int {
        $defaults = [
            policy_service::TYPE_ATTEMPT_SELECTION => ['method' => policy_service::METHOD_LATEST_COMPLETED],
            policy_service::TYPE_CALCULATION => [
                'minitems' => 1,
                'requiremanualgrading' => false,
                'displayscale' => 1,
            ],
            policy_service::TYPE_RELEASE => ['mode' => policy_service::RELEASE_FULLY_GRADED],
            policy_service::TYPE_ACCREDITATION => [
                'mincohortsize' => 5,
                'populationsource' => 'active_enrolments_at_freeze',
                'retentionbasis' => 'institutional_record_anonymised',
                'achievementminpercent' => '70',
                'benchmarkpercent' => '70',
                'aggregationmethod' => 'sum_numerators_denominators',
                'correctionmethod' => 'new_snapshot_version',
            ],
        ];
        $id = policy_service::create([
            'policytype' => $type,
            'scopetype' => $scopetype,
            'scopeid' => $scopeid,
            'name' => $type . ' at ' . $scopetype,
            'config' => $config ?: $defaults[$type],
            'effectivefrom' => self::NOW - (30 * DAYSECS),
        ]);
        policy_service::submit_for_review($id);
        return $id;
    }

    /**
     * Export the page context for one grouping.
     *
     * @param string $view Grouping to render.
     * @return array Template context.
     */
    private function export(string $view = policies_page::VIEW_DECISION): array {
        global $PAGE;
        return (new policies_page($view, self::NOW))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Every decision gets a group, whether or not a policy settles it.
     *
     * A flat list hides the decision nobody has configured, which is the one
     * worth acting on.
     */
    public function test_every_decision_is_a_group_even_with_no_policy(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->policy(policy_service::TYPE_ATTEMPT_SELECTION, policy_service::SCOPE_INSTITUTION);

        $context = $this->export();
        $this->assertCount(count(policy_service::TYPES), $context['groups']);

        $groups = array_column($context['groups'], null, 'title');
        $attempt = $groups[get_string('policytype_attempt_selection', 'local_outcomemap')];
        $this->assertTrue($attempt['hasrows']);
        $this->assertFalse($attempt['badgewarn'], 'An institution default is set for this decision.');

        $calculation = $groups[get_string('policytype_calculation', 'local_outcomemap')];
        $this->assertFalse($calculation['hasrows']);
        $this->assertTrue(
            $calculation['badgewarn'],
            'A decision nobody has settled must be flagged, not omitted.'
        );
    }

    /**
     * * The gap callout counts only the decisions with no institution default.
     */
    public function test_gap_callout_counts_missing_institution_defaults(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $context = $this->export();
        $this->assertTrue($context['hasgaps']);
        $this->assertSame(4, $context['gapcount']);

        $this->policy(policy_service::TYPE_ATTEMPT_SELECTION, policy_service::SCOPE_INSTITUTION);
        $this->policy(policy_service::TYPE_CALCULATION, policy_service::SCOPE_INSTITUTION);
        $context = $this->export();
        $this->assertSame(2, $context['gapcount']);
        $this->assertStringContainsString(
            get_string('policytype_release', 'local_outcomemap'),
            $context['gapline']
        );
    }

    /**
     * Each decision states the scope chain it is actually resolved through.
     *
     * The chains differ: accreditation is resolved by suppression_service through
     * program then institution, while everything else is resolved by
     * policy_service through assessment, course instance, catalog course, then
     * institution. A single precedence sentence for the page would be wrong for
     * one of them, so each group carries its own.
     */
    public function test_each_decision_states_its_own_resolution_chain(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $groups = array_column($this->export()['groups'], null, 'title');
        $calculation = $groups[get_string('policytype_calculation', 'local_outcomemap')]['chainline'];
        $accreditation = $groups[get_string('policytype_accreditation', 'local_outcomemap')]['chainline'];

        $program = get_string('policyscope_program', 'local_outcomemap');
        $instance = get_string('policyscope_course_instance', 'local_outcomemap');
        $this->assertStringContainsString($instance, $calculation);
        $this->assertStringNotContainsString(
            $program,
            $calculation,
            'Calculation is never resolved through a program.'
        );
        $this->assertStringContainsString($program, $accreditation);
        $this->assertStringNotContainsString(
            $instance,
            $accreditation,
            'Accreditation is never resolved through a course instance.'
        );
    }

    /**
     * The service refuses a scope its type is never resolved through.
     *
     * This is why the page does not warn about unreachable policies: the state
     * cannot arise. Asserting it here keeps that reasoning honest, so the page
     * gains the warning again if the service ever stops enforcing it.
     */
    public function test_service_refuses_a_scope_outside_the_resolution_chain(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $programid = program_service::create([
            'code' => 'MBA',
            'name' => 'Master of Business Administration',
            'programtype' => program_service::TYPE_GRADUATE,
        ]);

        try {
            $this->policy(policy_service::TYPE_CALCULATION, policy_service::SCOPE_PROGRAM, $programid);
            $this->fail('A program-scoped calculation policy must be refused.');
        } catch (\local_outcomemap\local\validation_exception $e) {
            $this->assertSame('invalidfield', $e->errorcode);
        }

        // The same scope is the correct one for accreditation.
        $this->policy(policy_service::TYPE_ACCREDITATION, policy_service::SCOPE_PROGRAM, $programid);
        $groups = array_column($this->export()['groups'], null, 'title');
        $this->assertCount(
            1,
            $groups[get_string('policytype_accreditation', 'local_outcomemap')]['rows']
        );
    }

    /**
     * * Courses that no in-force policy reaches are named for each decision.
     */
    public function test_uncovered_catalog_courses_are_named(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $covered = catalog_course_service::create(['code' => 'MBA601', 'name' => 'Financial Management']);
        catalog_course_service::create(['code' => 'MBA699', 'name' => 'Uncovered course']);
        $this->policy(policy_service::TYPE_CALCULATION, policy_service::SCOPE_CATALOG_COURSE, $covered);

        $groups = array_column($this->export()['groups'], null, 'title');
        $calculation = $groups[get_string('policytype_calculation', 'local_outcomemap')];

        $this->assertTrue($calculation['hasuncovered']);
        $this->assertStringContainsString('MBA699', $calculation['uncoveredline']);
        $this->assertStringNotContainsString(
            'MBA601',
            $calculation['uncoveredline'],
            'A course with its own in-force policy is covered.'
        );
    }

    /**
     * * An institution default covers everything, so nothing is left uncovered.
     */
    public function test_institution_default_leaves_nothing_uncovered(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        catalog_course_service::create(['code' => 'MBA601', 'name' => 'Financial Management']);
        $this->policy(policy_service::TYPE_CALCULATION, policy_service::SCOPE_INSTITUTION);

        $groups = array_column($this->export()['groups'], null, 'title');
        $this->assertFalse($groups[get_string('policytype_calculation', 'local_outcomemap')]['hasuncovered']);
    }

    /**
     * * The scope grouping reports how much of the estate each scope settles.
     */
    public function test_scope_view_reports_what_each_scope_settles(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->policy(policy_service::TYPE_ATTEMPT_SELECTION, policy_service::SCOPE_INSTITUTION);
        $this->policy(policy_service::TYPE_CALCULATION, policy_service::SCOPE_INSTITUTION);

        $context = $this->export(policies_page::VIEW_SCOPE);
        $this->assertTrue($context['isscope']);
        $this->assertCount(1, $context['groups'], 'Both policies sit at the one scope.');
        $group = $context['groups'][0];
        $this->assertCount(2, $group['rows']);
        $this->assertTrue($group['badgewarn']);
        $this->assertStringContainsString('2', $group['badge']);
        $this->assertStringContainsString(
            get_string('policytype_release', 'local_outcomemap'),
            $group['uncoveredline']
        );
    }

    /**
     * * A version outside its effective range is not treated as in force.
     */
    public function test_future_version_is_not_in_force(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $id = policy_service::create([
            'policytype' => policy_service::TYPE_CALCULATION,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'name' => 'Future calculation policy',
            'config' => ['minitems' => 1, 'requiremanualgrading' => false, 'displayscale' => 1],
            'effectivefrom' => self::NOW + (30 * DAYSECS),
        ]);
        policy_service::submit_for_review($id);

        $context = $this->export();
        $groups = array_column($context['groups'], null, 'title');
        $calculation = $groups[get_string('policytype_calculation', 'local_outcomemap')];

        $this->assertTrue(
            $calculation['badgewarn'],
            'A version that has not started yet is not the institution default.'
        );
        $this->assertSame('ended', $calculation['rows'][0]['statusclass']);
    }
}
