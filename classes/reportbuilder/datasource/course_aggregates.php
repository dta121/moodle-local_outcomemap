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

namespace local_outcomemap\reportbuilder\datasource;

use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format as report_format;
use core_reportbuilder\local\report\column;
use lang_string;
use local_outcomemap\reportbuilder\local\entities\report_record;
use local_outcomemap\reportbuilder\local\filter_options;
use local_outcomemap\reportbuilder\local\format;
use local_outcomemap\reportbuilder\local\secured_datasource;

/**
 * Suppression-safe frozen course/cohort aggregate rows.
 *
 * Snapshot and aggregate rows determine existence and stored index IDs determine
 * identity. Every mutable dimension is LEFT joined only as a fallback. Display
 * labels come from preloaded immutable companion snapitem payloads, so deleted
 * or renamed live records cannot remove or relabel a frozen aggregate.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_aggregates extends secured_datasource {
    /**
     * Returns the report source name.
     * @return string
     */
    public static function get_name(): string {
        return get_string('report_source_course_aggregates', 'local_outcomemap');
    }

    /**
     * Returns the capabilities required to access the source.
     * @return string[]
     */
    protected static function get_required_capabilities(): array {
        return ['local/outcomemap:exportaccreditation'];
    }

    /**
     * Build the source.
     */
    protected function initialise_source(): void {
        $entity = new report_record(
            [
                'local_outcomemap_snapitem',
                'local_outcomemap_snapshot',
                'local_outcomemap_program',
                'cohort',
                'local_outcomemap_cinst',
                'local_outcomemap_course',
                'course',
                'local_outcomemap_itemver',
                'local_outcomemap_item',
                'local_outcomemap_fw',
            ],
            new lang_string('report_source_course_aggregates', 'local_outcomemap')
        );
        $item = $entity->get_table_alias('local_outcomemap_snapitem');
        $snapshot = $entity->get_table_alias('local_outcomemap_snapshot');
        $program = $entity->get_table_alias('local_outcomemap_program');
        $cohort = $entity->get_table_alias('cohort');
        $courseinstance = $entity->get_table_alias('local_outcomemap_cinst');
        $catalogcourse = $entity->get_table_alias('local_outcomemap_course');
        $moodlecourse = $entity->get_table_alias('course');
        $outcomeversion = $entity->get_table_alias('local_outcomemap_itemver');
        $outcome = $entity->get_table_alias('local_outcomemap_item');
        $framework = $entity->get_table_alias('local_outcomemap_fw');
        $programcapture = database::generate_alias('programcapture');
        $cohortcapture = database::generate_alias('cohortcapture');
        $coursecapture = database::generate_alias('coursecapture');
        $outcomecapture = database::generate_alias('outcomecapture');

        $this->add_join("JOIN {local_outcomemap_snapshot} {$snapshot}
                             ON {$snapshot}.id = {$item}.snapshotid");
        $this->add_join("LEFT JOIN {local_outcomemap_snapitem} {$programcapture}
                             ON {$programcapture}.snapshotid = {$item}.snapshotid
                            AND {$programcapture}.itemtype = 'program'
                            AND {$programcapture}.sourceid = {$snapshot}.programid");
        $this->add_join("LEFT JOIN {local_outcomemap_snapitem} {$cohortcapture}
                             ON {$cohortcapture}.snapshotid = {$item}.snapshotid
                            AND {$cohortcapture}.itemtype = 'cohort'
                            AND {$cohortcapture}.sourceid = {$snapshot}.cohortid");
        $this->add_join("LEFT JOIN {local_outcomemap_snapitem} {$coursecapture}
                             ON {$coursecapture}.snapshotid = {$item}.snapshotid
                            AND {$coursecapture}.itemtype = 'course_instance'
                            AND {$coursecapture}.cinstid = {$item}.cinstid");
        $this->add_join("LEFT JOIN {local_outcomemap_snapitem} {$outcomecapture}
                             ON {$outcomecapture}.snapshotid = {$item}.snapshotid
                            AND {$outcomecapture}.itemtype = 'outcome_version'
                            AND {$outcomecapture}.itemverid = {$item}.itemverid");

        // Live dimensions are optional fallbacks only; they never determine row existence.
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$program}
                             ON {$program}.id = {$snapshot}.programid");
        $this->add_join("LEFT JOIN {cohort} {$cohort}
                             ON {$cohort}.id = {$snapshot}.cohortid");
        $this->add_join("LEFT JOIN {local_outcomemap_cinst} {$courseinstance}
                             ON {$courseinstance}.id = {$item}.cinstid");
        $this->add_join("LEFT JOIN {local_outcomemap_course} {$catalogcourse}
                             ON {$catalogcourse}.id = {$courseinstance}.courseid");
        $this->add_join("LEFT JOIN {course} {$moodlecourse}
                             ON {$moodlecourse}.id = {$courseinstance}.moodlecourseid");
        $this->add_join("LEFT JOIN {local_outcomemap_itemver} {$outcomeversion}
                             ON {$outcomeversion}.id = {$item}.itemverid");
        $this->add_join("LEFT JOIN {local_outcomemap_item} {$outcome}
                             ON {$outcome}.id = {$outcomeversion}.itemid");
        $this->add_join("LEFT JOIN {local_outcomemap_fw} {$framework}
                             ON {$framework}.id = {$outcome}.frameworkid");

        $typeparam = database::generate_param_name();
        $statusparam = database::generate_param_name();
        $this->add_base_condition_sql("{$item}.itemtype = :{$typeparam}", [
            $typeparam => 'course_aggregate',
        ]);
        $this->add_base_condition_sql("{$snapshot}.status = :{$statusparam}", [
            $statusparam => 'frozen',
        ]);

        $numerator = "CASE WHEN {$item}.suppressed = 1 THEN NULL ELSE {$item}.numerator END";
        $denominator = "CASE WHEN {$item}.suppressed = 1 THEN NULL ELSE {$item}.denominator END";
        $percentage = "CASE WHEN {$item}.suppressed = 1 THEN NULL ELSE {$item}.percentage END";
        // The criterion and benchmark are governed policy, so they survive
        // suppression; the learner counts and the rate drawn from them do not.
        $assessedcount = "CASE WHEN {$item}.suppressed = 1 THEN NULL ELSE {$item}.assessedcount END";
        $metcount = "CASE WHEN {$item}.suppressed = 1 THEN NULL ELSE {$item}.metcount END";
        $attainment = "CASE WHEN {$item}.suppressed = 1 THEN NULL ELSE {$item}.attainmentpercent END";
        $benchmarkmet = "CASE WHEN {$item}.suppressed = 1 THEN NULL ELSE {$item}.benchmarkmet END";

        $entity
            ->define_column(
                'recordid',
                new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$item}.id"]
            )
            ->define_column(
                'snapshotuuid',
                new lang_string('snapshotuuid', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$snapshot}.snapshotuuid"]
            )
            ->define_column(
                'snapshotversion',
                new lang_string('snapshotversion', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$snapshot}.version"]
            )
            ->define_column(
                'programid',
                new lang_string('reportcolumn_programid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$snapshot}.programid"]
            )
            ->define_column(
                'programcode',
                new lang_string('program', 'local_outcomemap'),
                column::TYPE_TEXT,
                [
                    'payloadjson' => "{$programcapture}.payloadjson",
                    'payloadfield' => "'code'",
                    'fallback' => "{$program}.code",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'programname',
                new lang_string('name', 'local_outcomemap'),
                column::TYPE_TEXT,
                [
                    'payloadjson' => "{$programcapture}.payloadjson",
                    'payloadfield' => "'name'",
                    'fallback' => "{$program}.name",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'courseinstanceid',
                new lang_string('courseinstance', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$item}.cinstid"]
            )
            ->define_column(
                'courseinstanceuuid',
                new lang_string('uuid', 'local_outcomemap'),
                column::TYPE_TEXT,
                [
                    'payloadjson' => "{$coursecapture}.payloadjson",
                    'payloadfield' => "'uuid'",
                    'fallback' => "{$courseinstance}.uuid",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'catalogcourseid',
                new lang_string('reportcolumn_catalogcourseid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                [
                    'payloadjson' => "{$coursecapture}.payloadjson",
                    'payloadfield' => "'catalogcourseid'",
                    'fallback' => "{$catalogcourse}.id",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'catalogcoursecode',
                new lang_string('catalogcourse', 'local_outcomemap'),
                column::TYPE_TEXT,
                [
                    'payloadjson' => "{$coursecapture}.payloadjson",
                    'payloadfield' => "'coursecode'",
                    'fallback' => "{$catalogcourse}.code",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'catalogcoursename',
                new lang_string('name', 'local_outcomemap'),
                column::TYPE_TEXT,
                [
                    'payloadjson' => "{$coursecapture}.payloadjson",
                    'payloadfield' => "'coursename'",
                    'fallback' => "{$catalogcourse}.name",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'moodlecourseid',
                new lang_string('reportcolumn_moodlecourseid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                [
                    'payloadjson' => "{$coursecapture}.payloadjson",
                    'payloadfield' => "'moodlecourseid'",
                    'fallback' => "{$moodlecourse}.id",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'moodlecoursename',
                new lang_string('moodlecourse', 'local_outcomemap'),
                column::TYPE_TEXT,
                [
                    'payloadjson' => "{$coursecapture}.payloadjson",
                    'payloadfield' => "'moodlecoursename'",
                    'fallback' => "{$moodlecourse}.fullname",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'periodcode',
                new lang_string('reportcolumn_periodcode', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$snapshot}.periodcode"]
            )
            ->define_column(
                'cohortid',
                new lang_string('reportcolumn_cohortid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$snapshot}.cohortid"]
            )
            ->define_column(
                'cohortname',
                new lang_string('cohort', 'local_outcomemap'),
                column::TYPE_TEXT,
                [
                    'payloadjson' => "{$cohortcapture}.payloadjson",
                    'payloadfield' => "'name'",
                    'fallback' => "{$cohort}.name",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'outcomeversionid',
                new lang_string('reportcolumn_outcomeversionid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$item}.itemverid"]
            )
            ->define_column(
                'outcomecode',
                new lang_string('outcome', 'local_outcomemap'),
                column::TYPE_TEXT,
                [
                    'payloadjson' => "{$outcomecapture}.payloadjson",
                    'payloadfield' => "'code'",
                    'fallback' => "{$outcome}.code",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'outcomestatement',
                new lang_string('statement', 'local_outcomemap'),
                column::TYPE_LONGTEXT,
                [
                    'payloadjson' => "{$outcomecapture}.payloadjson",
                    'payloadfield' => "'statement'",
                    'fallback' => "{$outcomeversion}.statement",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'frameworkcode',
                new lang_string('framework', 'local_outcomemap'),
                column::TYPE_TEXT,
                [
                    'payloadjson' => "{$outcomecapture}.payloadjson",
                    'payloadfield' => "'frameworkcode'",
                    'fallback' => "{$framework}.code",
                ],
                false,
                [format::class, 'snapshot_payload_value']
            )
            ->define_column(
                'state',
                new lang_string('reportcolumn_state', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$item}.state"]
            )
            ->define_column(
                'subjectcount',
                new lang_string('reportcolumn_subjectcount', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$item}.subjectcount"]
            )
            ->define_column(
                'suppressed',
                new lang_string('reportcolumn_suppressed', 'local_outcomemap'),
                column::TYPE_BOOLEAN,
                ["{$item}.suppressed"],
                true,
                [report_format::class, 'boolean_as_text']
            )
            ->define_column(
                'numerator',
                new lang_string('reportcolumn_numerator', 'local_outcomemap'),
                column::TYPE_TEXT,
                ['numerator' => $numerator],
                true,
                null,
                [],
                true
            )
            ->define_column(
                'denominator',
                new lang_string('weightedpossiblepoints', 'local_outcomemap'),
                column::TYPE_TEXT,
                ['denominator' => $denominator],
                true,
                null,
                [],
                true
            )
            ->define_column(
                'percentage',
                new lang_string('reportcolumn_percentage', 'local_outcomemap'),
                column::TYPE_TEXT,
                ['percentage' => $percentage],
                true,
                null,
                [],
                true
            )
            ->define_column(
                'criterionpercent',
                new lang_string('achievementminpercent', 'local_outcomemap'),
                column::TYPE_TEXT,
                ['criterionpercent' => "{$item}.criterionpercent"],
                true,
                null,
                [],
                true
            )
            ->define_column(
                'benchmarkpercent',
                new lang_string('benchmarkpercent', 'local_outcomemap'),
                column::TYPE_TEXT,
                ['benchmarkpercent' => "{$item}.benchmarkpercent"],
                true,
                null,
                [],
                true
            )
            ->define_column(
                'assessedcount',
                new lang_string('reportcolumn_assessedcount', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ['assessedcount' => $assessedcount]
            )
            ->define_column(
                'metcount',
                new lang_string('reportcolumn_metcount', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ['metcount' => $metcount]
            )
            ->define_column(
                'attainmentpercent',
                new lang_string('attainmentrate', 'local_outcomemap'),
                column::TYPE_TEXT,
                ['attainmentpercent' => $attainment],
                true,
                null,
                [],
                true
            )
            ->define_column(
                'benchmarkmet',
                new lang_string('benchmarkmet', 'local_outcomemap'),
                column::TYPE_BOOLEAN,
                ['benchmarkmet' => $benchmarkmet],
                true,
                [report_format::class, 'boolean_as_text']
            )
            ->define_column(
                'populationcount',
                new lang_string('populationcount', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$snapshot}.populationcount"]
            )
            ->define_column(
                'suppressionthreshold',
                new lang_string('suppressionthreshold', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$snapshot}.suppressionthreshold"]
            )
            ->define_column(
                'populationat',
                new lang_string('populationat', 'local_outcomemap'),
                column::TYPE_TIMESTAMP,
                ["{$snapshot}.populationat"],
                true,
                [report_format::class, 'userdate']
            )
            ->define_column(
                'payloadhash',
                new lang_string('payloadhash', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$item}.payloadhash"]
            )
            ->define_column(
                'manifesthash',
                new lang_string('manifesthash', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$snapshot}.manifesthash"]
            )
            ->define_filter(
                'programid',
                new lang_string('program', 'local_outcomemap'),
                number::class,
                "{$snapshot}.programid"
            )
            ->define_filter(
                'courseinstanceid',
                new lang_string('courseinstance', 'local_outcomemap'),
                number::class,
                "{$item}.cinstid"
            )
            ->define_filter(
                'periodcode',
                new lang_string('periodcode', 'local_outcomemap'),
                text::class,
                "{$snapshot}.periodcode"
            )
            ->define_filter(
                'cohortid',
                new lang_string('cohort', 'local_outcomemap'),
                number::class,
                "{$snapshot}.cohortid"
            )
            ->define_filter(
                'outcomeversionid',
                new lang_string('outcomeversion', 'local_outcomemap'),
                number::class,
                "{$item}.itemverid"
            )
            ->define_filter(
                'state',
                new lang_string('reportcolumn_state', 'local_outcomemap'),
                select::class,
                "{$item}.state",
                filter_options::aggregate_states()
            )
            ->define_filter(
                'percentage',
                new lang_string('reportcolumn_percentage', 'local_outcomemap'),
                number::class,
                $percentage
            )
            ->define_filter(
                'attainmentpercent',
                new lang_string('attainmentrate', 'local_outcomemap'),
                number::class,
                $attainment
            );

        $this->register_entity($entity, 'local_outcomemap_snapitem');
    }

    /**
     * Returns the default report columns.
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'outcomemap:programcode',
            'outcomemap:catalogcoursecode',
            'outcomemap:moodlecoursename',
            'outcomemap:periodcode',
            'outcomemap:cohortname',
            'outcomemap:outcomecode',
            'outcomemap:subjectcount',
            'outcomemap:suppressed',
            'outcomemap:percentage',
            'outcomemap:attainmentpercent',
            'outcomemap:benchmarkpercent',
            'outcomemap:benchmarkmet',
            'outcomemap:state',
        ];
    }

    /**
     * Returns the default report filters.
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'outcomemap:programid',
            'outcomemap:courseinstanceid',
            'outcomemap:periodcode',
            'outcomemap:cohortid',
            'outcomemap:outcomeversionid',
            'outcomemap:state',
        ];
    }
}
