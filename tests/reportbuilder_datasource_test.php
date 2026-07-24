<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');

use core_reportbuilder\local\helpers\report as report_helper;
use core_reportbuilder\local\report\filter;
use core_reportbuilder\manager;
use lang_string;
use local_outcomemap\reportbuilder\datasource\assessment_coverage;
use local_outcomemap\reportbuilder\datasource\audit_history;
use local_outcomemap\reportbuilder\datasource\course_aggregates;
use local_outcomemap\reportbuilder\datasource\mapping_coverage;
use local_outcomemap\reportbuilder\datasource\outcome_definitions;
use local_outcomemap\reportbuilder\datasource\program_aggregates;
use local_outcomemap\reportbuilder\datasource\remediation_engagement;
use local_outcomemap\reportbuilder\datasource\student_attainment;
use local_outcomemap\reportbuilder\local\filters\cohort_membership;

/**
 * Tests all governed custom Report Builder data sources.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reportbuilder_datasource_test extends \advanced_testcase {
    /**
     * Exact default contracts for every Milestone 6 source.
     *
     * @return array[]
     */
    public static function source_provider(): array {
        return [
            'Outcome definitions' => [
                outcome_definitions::class,
                [
                    'outcomemap:programcode', 'outcomemap:catalogcoursecode',
                    'outcomemap:frameworkcode', 'outcomemap:outcomecode', 'outcomemap:version',
                    'outcomemap:shortstatement', 'outcomemap:status', 'outcomemap:effectivefrom',
                ],
                [
                    'outcomemap:programid', 'outcomemap:catalogcourseid',
                    'outcomemap:outcomeversionid', 'outcomemap:outcomecode', 'outcomemap:status',
                ],
            ],
            'Mapping coverage' => [
                mapping_coverage::class,
                [
                    'outcomemap:programcode', 'outcomemap:catalogcoursecode',
                    'outcomemap:moodlecoursename', 'outcomemap:periodcode', 'outcomemap:outcomecode',
                    'outcomemap:targetoutcomecode', 'outcomemap:relationtype',
                    'outcomemap:relationstatus', 'outcomemap:modulemappingcount',
                    'outcomemap:sectionmappingcount',
                ],
                [
                    'outcomemap:programid', 'outcomemap:catalogcourseid',
                    'outcomemap:moodlecourseid', 'outcomemap:periodcode',
                    'outcomemap:outcomeversionid', 'outcomemap:relationtype',
                    'outcomemap:relationstatus',
                ],
            ],
            'Assessment coverage' => [
                assessment_coverage::class,
                [
                    'outcomemap:programcode', 'outcomemap:questionname',
                    'outcomemap:questionversion', 'outcomemap:outcomecode',
                    'outcomemap:mappingrole', 'outcomemap:mappingweight',
                    'outcomemap:mappingstatus', 'outcomemap:assessmentname',
                    'outcomemap:moodlecoursename', 'outcomemap:evidencecount',
                ],
                [
                    'outcomemap:programid', 'outcomemap:catalogcourseid',
                    'outcomemap:moodlecourseid', 'outcomemap:periodcode',
                    'outcomemap:assessmentid', 'outcomemap:outcomeversionid',
                    'outcomemap:mappingrole', 'outcomemap:mappingstatus',
                ],
            ],
            'Student attainment' => [
                student_attainment::class,
                [
                    'outcomemap:userfullname', 'outcomemap:programcode',
                    'outcomemap:moodlecoursename', 'outcomemap:periodcode',
                    'outcomemap:outcomecode', 'outcomemap:scopetype',
                    'outcomemap:percentage', 'outcomemap:state', 'outcomemap:band',
                    'outcomemap:distinctitems', 'outcomemap:timecalculated',
                ],
                [
                    'outcomemap:programid', 'outcomemap:catalogcourseid',
                    'outcomemap:moodlecourseid', 'outcomemap:cohortid',
                    'outcomemap:periodcode', 'outcomemap:assessmentid',
                    'outcomemap:outcomeversionid', 'outcomemap:state', 'outcomemap:band',
                ],
            ],
            'Course aggregates' => [
                course_aggregates::class,
                [
                    'outcomemap:programcode', 'outcomemap:catalogcoursecode',
                    'outcomemap:moodlecoursename', 'outcomemap:periodcode',
                    'outcomemap:cohortname', 'outcomemap:outcomecode',
                    'outcomemap:subjectcount', 'outcomemap:suppressed',
                    'outcomemap:percentage', 'outcomemap:state',
                ],
                [
                    'outcomemap:programid', 'outcomemap:courseinstanceid',
                    'outcomemap:periodcode', 'outcomemap:cohortid',
                    'outcomemap:outcomeversionid', 'outcomemap:state',
                ],
            ],
            'Program aggregates' => [
                program_aggregates::class,
                [
                    'outcomemap:programcode', 'outcomemap:periodcode',
                    'outcomemap:cohortname', 'outcomemap:outcomecode',
                    'outcomemap:subjectcount', 'outcomemap:suppressed',
                    'outcomemap:percentage', 'outcomemap:state', 'outcomemap:snapshotversion',
                ],
                [
                    'outcomemap:programid', 'outcomemap:periodcode', 'outcomemap:cohortid',
                    'outcomemap:outcomeversionid', 'outcomemap:policyid', 'outcomemap:state',
                ],
            ],
            'Remediation engagement' => [
                remediation_engagement::class,
                [
                    'outcomemap:programcode', 'outcomemap:catalogcoursecode',
                    'outcomemap:moodlecoursename', 'outcomemap:periodcode',
                    'outcomemap:outcomecode', 'outcomemap:band', 'outcomemap:title',
                    'outcomemap:targettype', 'outcomemap:purpose', 'outcomemap:required',
                    'outcomemap:status', 'outcomemap:engagementtype',
                    'outcomemap:userfullname', 'outcomemap:resultstate',
                    'outcomemap:resultpercentage', 'outcomemap:engagementtime',
                ],
                [
                    'outcomemap:programid', 'outcomemap:catalogcourseid',
                    'outcomemap:moodlecourseid', 'outcomemap:cohortid',
                    'outcomemap:engagementtype', 'outcomemap:periodcode',
                    'outcomemap:outcomeversionid', 'outcomemap:band',
                    'outcomemap:targettype', 'outcomemap:purpose', 'outcomemap:status',
                ],
            ],
            'Audit history' => [
                audit_history::class,
                [
                    'outcomemap:timecreated', 'outcomemap:actorname', 'outcomemap:action',
                    'outcomemap:objecttype', 'outcomemap:mappingtype',
                    'outcomemap:mappingversion', 'outcomemap:evidencetype',
                    'outcomemap:policyname', 'outcomemap:objectid', 'outcomemap:reason',
                    'outcomemap:programcode', 'outcomemap:moodlecoursename',
                    'outcomemap:periodcode', 'outcomemap:assessmentname',
                    'outcomemap:outcomecode', 'outcomemap:state', 'outcomemap:correlationid',
                ],
                [
                    'outcomemap:action', 'outcomemap:objecttype', 'outcomemap:mappingtype',
                    'outcomemap:evidencetype', 'outcomemap:evidencestate',
                    'outcomemap:policytype', 'outcomemap:policyscope',
                    'outcomemap:policystatus', 'outcomemap:actorid',
                    'outcomemap:programid', 'outcomemap:catalogcourseid',
                    'outcomemap:moodlecourseid', 'outcomemap:periodcode',
                    'outcomemap:assessmentid', 'outcomemap:cohortid',
                    'outcomemap:questionversionid', 'outcomemap:outcomeversionid',
                    'outcomemap:state', 'outcomemap:band', 'outcomemap:timecreated',
                ],
            ],
        ];
    }

    /**
     * Construct each custom source, assert its contract, and execute generated SQL.
     *
     * @param string $source Source class.
     * @param string[] $expectedcolumns Default columns.
     * @param string[] $expectedfilters Default filters.
     * @dataProvider source_provider
     */
    public function test_source_constructs_and_executes(
        string $source,
        array $expectedcolumns,
        array $expectedfilters
    ): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'M6 ' . $source,
            'source' => $source,
        ]);
        $instance = manager::get_report_from_persistent($report);

        $this->assertInstanceOf($source, $instance);
        $this->assertSame($expectedcolumns, $instance->get_default_columns());
        $this->assertSame($expectedfilters, $instance->get_default_filters());
        $this->assertSame(0, report_helper::get_report_row_count((int) $report->get('id')));
    }

    /**
     * Test cohort filtering uses correlated EXISTS and never multiplies learner rows.
     */
    public function test_cohort_membership_filter_preserves_user_grain(): void {
        global $DB;

        $this->resetAfterTest(true);
        $userone = $this->getDataGenerator()->create_user();
        $usertwo = $this->getDataGenerator()->create_user();
        $cohortone = $this->getDataGenerator()->create_cohort(['name' => 'M6 cohort one']);
        $cohorttwo = $this->getDataGenerator()->create_cohort(['name' => 'M6 cohort two']);
        cohort_add_member($cohortone->id, $userone->id);
        cohort_add_member($cohorttwo->id, $userone->id);
        cohort_add_member($cohortone->id, $usertwo->id);

        $filter = new filter(
            cohort_membership::class,
            'cohortid',
            new lang_string('cohort'),
            'outcomemap',
            'u.id'
        );
        $identifier = $filter->get_unique_identifier() . '_values';
        [$select, $params] = cohort_membership::create($filter)->get_sql_filter([
            $identifier => [$cohortone->id, $cohorttwo->id, $cohortone->id, 0],
        ]);

        $this->assertStringContainsString('EXISTS', $select);
        $this->assertStringContainsString('{cohort_members}', $select);
        $this->assertStringContainsString('.userid = u.id', $select);
        $this->assertCount(2, $params, 'Duplicate and empty cohort IDs must be normalized.');
        $userids = $DB->get_fieldset_sql("SELECT u.id FROM {user} u WHERE {$select} ORDER BY u.id", $params);
        $this->assertSame([$userone->id, $usertwo->id], array_map('intval', $userids));

        [$emptyselect, $emptyparams] = cohort_membership::create($filter)->get_sql_filter([
            $identifier => [],
        ]);
        $this->assertSame('', $emptyselect);
        $this->assertSame([], $emptyparams);
    }
}
