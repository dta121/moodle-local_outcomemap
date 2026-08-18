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

use core\context_helper;
use core_reportbuilder\local\filters\course_selector;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use lang_string;
use local_outcomemap\reportbuilder\local\access;
use local_outcomemap\reportbuilder\local\entities\report_record;
use local_outcomemap\reportbuilder\local\filter_options;
use local_outcomemap\reportbuilder\local\secured_datasource;

/**
 * Course-to-program outcome and content mapping coverage.
 *
 * The grain is program-membership version, course instance, course-owned
 * outcome version, and matching program-outcome relation version. Membership,
 * outcome, and relation intervals must overlap. Independent content-mapping
 * counts are pre-aggregated before joining and are non-aggregatable because
 * they repeat across this explicit program/relation grain.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mapping_coverage extends secured_datasource {
    /**
     * Returns the report source name.
     * @return string
     */
    public static function get_name(): string {
        return get_string('report_source_mapping_coverage', 'local_outcomemap');
    }

    /**
     * Returns the capabilities required to access the source.
     * @return string[]
     */
    protected static function get_required_capabilities(): array {
        return ['local/outcomemap:viewdefinitions'];
    }

    /**
     * Course IDs where the current user has the source capability after overrides.
     *
     * @return int[]
     */
    private static function allowed_course_ids(): array {
        static $allowedcourseids = null;
        if ($allowedcourseids !== null) {
            return $allowedcourseids;
        }

        global $DB;
        $preload = context_helper::get_preload_record_columns_sql('ctx');
        $records = $DB->get_records_sql(
            "SELECT DISTINCT {$preload}
               FROM {context} ctx
               JOIN {local_outcomemap_cinst} ci ON ci.moodlecourseid = ctx.instanceid
              WHERE ctx.contextlevel = :contextlevel",
            ['contextlevel' => CONTEXT_COURSE]
        );
        $allowedcontexts = access::allowed_context_ids($records, self::get_required_capabilities());
        $allowedcourseids = [];
        foreach ($records as $record) {
            if (isset($allowedcontexts[(int) $record->ctxid])) {
                $allowedcourseids[(int) $record->ctxinstance] = (int) $record->ctxinstance;
            }
        }
        return array_values($allowedcourseids);
    }

    /**
     * Checks whether the current user can view the scoped source.
     * @return bool
     */
    protected static function can_view_scoped(): bool {
        return self::allowed_course_ids() !== [];
    }

    /**
     * Build the source.
     */
    protected function initialise_source(): void {
        $entity = new report_record(
            [
                'local_outcomemap_itemver',
                'local_outcomemap_item',
                'local_outcomemap_fw',
                'local_outcomemap_course',
                'local_outcomemap_progcourse',
                'local_outcomemap_program',
                'local_outcomemap_cinst',
                'course',
            ],
            new lang_string('report_source_mapping_coverage', 'local_outcomemap')
        );
        $version = $entity->get_table_alias('local_outcomemap_itemver');
        $item = $entity->get_table_alias('local_outcomemap_item');
        $framework = $entity->get_table_alias('local_outcomemap_fw');
        $catalogcourse = $entity->get_table_alias('local_outcomemap_course');
        $membership = $entity->get_table_alias('local_outcomemap_progcourse');
        $program = $entity->get_table_alias('local_outcomemap_program');
        $courseinstance = $entity->get_table_alias('local_outcomemap_cinst');
        $moodlecourse = $entity->get_table_alias('course');
        $relation = database::generate_alias('relation');
        $relationbase = database::generate_alias('relationbase');
        $targetitem = database::generate_alias('targetitem');
        $targetframework = database::generate_alias('targetframework');
        $modulecoverage = database::generate_alias('modulecoverage');
        $modulemap = database::generate_alias('modulemap');
        $latestmodulemap = database::generate_alias('latestmodulemap');
        $sectioncoverage = database::generate_alias('sectioncoverage');
        $sectionmap = database::generate_alias('sectionmap');
        $latestsectionmap = database::generate_alias('latestsectionmap');

        $this->add_join("JOIN {local_outcomemap_item} {$item} ON {$item}.id = {$version}.itemid");
        $this->add_join("JOIN {local_outcomemap_fw} {$framework} ON {$framework}.id = {$item}.frameworkid");
        $this->add_join("JOIN {local_outcomemap_course} {$catalogcourse}
                             ON {$catalogcourse}.id = {$framework}.ownerid
                            AND {$framework}.ownertype = 'catalog_course'");
        $this->add_join("JOIN {local_outcomemap_progcourse} {$membership}
                             ON {$membership}.courseid = {$catalogcourse}.id
                            AND ({$membership}.effectivefrom < {$version}.effectiveto
                                 OR {$version}.effectiveto IS NULL)
                            AND ({$version}.effectivefrom < {$membership}.effectiveto
                                 OR {$membership}.effectiveto IS NULL)");
        $this->add_join("JOIN {local_outcomemap_program} {$program}
                             ON {$program}.id = {$membership}.programid");
        $this->add_join("LEFT JOIN {local_outcomemap_cinst} {$courseinstance}
                             ON {$courseinstance}.courseid = {$catalogcourse}.id");
        $this->add_join("LEFT JOIN {course} {$moodlecourse}
                             ON {$moodlecourse}.id = {$courseinstance}.moodlecourseid");
        $this->add_join("LEFT JOIN (
                           SELECT {$relationbase}.id, {$relationbase}.relationuuid,
                                  {$relationbase}.version, {$relationbase}.sourceitemid,
                                  {$relationbase}.targetitemid, {$relationbase}.type,
                                  {$relationbase}.weight, {$relationbase}.status,
                                  {$relationbase}.effectivefrom, {$relationbase}.effectiveto,
                                  {$targetitem}.code AS targetcode,
                                  {$targetframework}.ownerid AS targetprogramid
                             FROM {local_outcomemap_rel} {$relationbase}
                             JOIN {local_outcomemap_item} {$targetitem}
                               ON {$targetitem}.id = {$relationbase}.targetitemid
                             JOIN {local_outcomemap_fw} {$targetframework}
                               ON {$targetframework}.id = {$targetitem}.frameworkid
                              AND {$targetframework}.ownertype = 'program'
                       ) {$relation}
                             ON {$relation}.sourceitemid = {$item}.id
                            AND {$relation}.targetprogramid = {$program}.id
                            AND ({$relation}.effectivefrom < {$version}.effectiveto
                                 OR {$version}.effectiveto IS NULL)
                            AND ({$version}.effectivefrom < {$relation}.effectiveto
                                 OR {$relation}.effectiveto IS NULL)
                            AND ({$relation}.effectivefrom < {$membership}.effectiveto
                                 OR {$membership}.effectiveto IS NULL)
                            AND ({$membership}.effectivefrom < {$relation}.effectiveto
                                 OR {$relation}.effectiveto IS NULL)");
        $this->add_join("LEFT JOIN (
                           SELECT {$modulemap}.cinstid, {$modulemap}.itemverid,
                                  COUNT({$modulemap}.id) AS mappingcount
                             FROM {local_outcomemap_cmmap} {$modulemap}
                             JOIN (
                                   SELECT mappinguuid, MAX(version) AS version
                                     FROM {local_outcomemap_cmmap}
                                    WHERE status = 'approved'
                                 GROUP BY mappinguuid
                                  ) {$latestmodulemap}
                               ON {$latestmodulemap}.mappinguuid = {$modulemap}.mappinguuid
                              AND {$latestmodulemap}.version = {$modulemap}.version
                            WHERE {$modulemap}.status = 'approved'
                         GROUP BY {$modulemap}.cinstid, {$modulemap}.itemverid
                       ) {$modulecoverage}
                             ON {$modulecoverage}.cinstid = {$courseinstance}.id
                            AND {$modulecoverage}.itemverid = {$version}.id");
        $this->add_join("LEFT JOIN (
                           SELECT {$sectionmap}.cinstid, {$sectionmap}.itemverid,
                                  COUNT({$sectionmap}.id) AS mappingcount
                             FROM {local_outcomemap_secmap} {$sectionmap}
                             JOIN (
                                   SELECT mappinguuid, MAX(version) AS version
                                     FROM {local_outcomemap_secmap}
                                    WHERE status = 'approved'
                                 GROUP BY mappinguuid
                                  ) {$latestsectionmap}
                               ON {$latestsectionmap}.mappinguuid = {$sectionmap}.mappinguuid
                              AND {$latestsectionmap}.version = {$sectionmap}.version
                            WHERE {$sectionmap}.status = 'approved'
                         GROUP BY {$sectionmap}.cinstid, {$sectionmap}.itemverid
                       ) {$sectioncoverage}
                             ON {$sectioncoverage}.cinstid = {$courseinstance}.id
                            AND {$sectioncoverage}.itemverid = {$version}.id");

        $this->add_allowed_id_condition(
            "{$moodlecourse}.id",
            self::allowed_course_ids(),
            static::has_global_access()
        );

        $modulemappingcount = "COALESCE({$modulecoverage}.mappingcount, 0)";
        $sectionmappingcount = "COALESCE({$sectioncoverage}.mappingcount, 0)";

        $entity
            ->define_column(
                'recordid',
                new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$version}.id"]
            )
            ->define_column(
                'programid',
                new lang_string('reportcolumn_programid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$program}.id"]
            )
            ->define_column(
                'programcode',
                new lang_string('program', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$program}.code"]
            )
            ->define_column(
                'programname',
                new lang_string('name', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$program}.name"]
            )
            ->define_column(
                'membershipstatus',
                new lang_string('status', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$membership}.status"]
            )
            ->define_column(
                'membershipeffectivefrom',
                new lang_string('effectivefrom', 'local_outcomemap'),
                column::TYPE_TIMESTAMP,
                ["{$membership}.effectivefrom"],
                true,
                [format::class, 'userdate']
            )
            ->define_column(
                'membershipeffectiveto',
                new lang_string('effectiveto', 'local_outcomemap'),
                column::TYPE_TIMESTAMP,
                ["{$membership}.effectiveto"],
                true,
                [format::class, 'userdate']
            )
            ->define_column(
                'catalogcourseid',
                new lang_string('reportcolumn_catalogcourseid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$catalogcourse}.id"]
            )
            ->define_column(
                'catalogcoursecode',
                new lang_string('catalogcourse', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$catalogcourse}.code"]
            )
            ->define_column(
                'catalogcoursename',
                new lang_string('name', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$catalogcourse}.name"]
            )
            ->define_column(
                'moodlecourseid',
                new lang_string('reportcolumn_moodlecourseid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$moodlecourse}.id"]
            )
            ->define_column(
                'moodlecoursename',
                new lang_string('moodlecourse', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$moodlecourse}.fullname"]
            )
            ->define_column(
                'periodcode',
                new lang_string('reportcolumn_periodcode', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$courseinstance}.periodcode"]
            )
            ->define_column(
                'outcomeversionid',
                new lang_string('reportcolumn_outcomeversionid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$version}.id"]
            )
            ->define_column(
                'outcomecode',
                new lang_string('outcome', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$item}.code"]
            )
            ->define_column(
                'outcomestatement',
                new lang_string('statement', 'local_outcomemap'),
                column::TYPE_LONGTEXT,
                ["{$version}.statement"]
            )
            ->define_column(
                'outcomeversion',
                new lang_string('version', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$version}.version"]
            )
            ->define_column(
                'outcomestatus',
                new lang_string('status', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$version}.status"]
            )
            ->define_column(
                'outcomeeffectivefrom',
                new lang_string('effectivefrom', 'local_outcomemap'),
                column::TYPE_TIMESTAMP,
                ["{$version}.effectivefrom"],
                true,
                [format::class, 'userdate']
            )
            ->define_column(
                'outcomeeffectiveto',
                new lang_string('effectiveto', 'local_outcomemap'),
                column::TYPE_TIMESTAMP,
                ["{$version}.effectiveto"],
                true,
                [format::class, 'userdate']
            )
            ->define_column(
                'relationid',
                new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$relation}.id"]
            )
            ->define_column(
                'targetoutcomecode',
                new lang_string('targetoutcome', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$relation}.targetcode"]
            )
            ->define_column(
                'relationtype',
                new lang_string('relationtype', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$relation}.type"]
            )
            ->define_column(
                'relationversion',
                new lang_string('version', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ["{$relation}.version"]
            )
            ->define_column(
                'relationstatus',
                new lang_string('status', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$relation}.status"]
            )
            ->define_column(
                'relationeffectivefrom',
                new lang_string('effectivefrom', 'local_outcomemap'),
                column::TYPE_TIMESTAMP,
                ["{$relation}.effectivefrom"],
                true,
                [format::class, 'userdate']
            )
            ->define_column(
                'relationeffectiveto',
                new lang_string('effectiveto', 'local_outcomemap'),
                column::TYPE_TIMESTAMP,
                ["{$relation}.effectiveto"],
                true,
                [format::class, 'userdate']
            )
            ->define_column(
                'relationweight',
                new lang_string('weight', 'local_outcomemap'),
                column::TYPE_TEXT,
                ["{$relation}.weight"],
                true,
                null,
                [],
                true
            )
            ->define_column(
                'modulemappingcount',
                new lang_string('coursemodules', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ['modulemappingcount' => $modulemappingcount],
                true,
                null,
                [],
                true
            )
            ->define_column(
                'sectionmappingcount',
                new lang_string('coursesections', 'local_outcomemap'),
                column::TYPE_INTEGER,
                ['sectionmappingcount' => $sectionmappingcount],
                true,
                null,
                [],
                true
            )
            ->define_filter(
                'programid',
                new lang_string('program', 'local_outcomemap'),
                number::class,
                "{$program}.id"
            )
            ->define_filter(
                'catalogcourseid',
                new lang_string('catalogcourse', 'local_outcomemap'),
                number::class,
                "{$catalogcourse}.id"
            )
            ->define_filter(
                'moodlecourseid',
                new lang_string('moodlecourse', 'local_outcomemap'),
                course_selector::class,
                "{$moodlecourse}.id"
            )
            ->define_filter(
                'periodcode',
                new lang_string('periodcode', 'local_outcomemap'),
                text::class,
                "{$courseinstance}.periodcode"
            )
            ->define_filter(
                'outcomeversionid',
                new lang_string('outcomeversion', 'local_outcomemap'),
                number::class,
                "{$version}.id"
            )
            ->define_filter(
                'outcomecode',
                new lang_string('outcome', 'local_outcomemap'),
                text::class,
                "{$item}.code"
            )
            ->define_filter(
                'membershipstatus',
                new lang_string('status', 'local_outcomemap'),
                select::class,
                "{$membership}.status",
                filter_options::workflow_states()
            )
            ->define_filter(
                'relationtype',
                new lang_string('relationtype', 'local_outcomemap'),
                select::class,
                "{$relation}.type",
                filter_options::relation_types()
            )
            ->define_filter(
                'relationstatus',
                new lang_string('status', 'local_outcomemap'),
                select::class,
                "{$relation}.status",
                filter_options::workflow_states()
            );

        $this->register_entity($entity, 'local_outcomemap_itemver');
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
            'outcomemap:outcomecode',
            'outcomemap:targetoutcomecode',
            'outcomemap:relationtype',
            'outcomemap:relationstatus',
            'outcomemap:modulemappingcount',
            'outcomemap:sectionmappingcount',
        ];
    }

    /**
     * Returns the default report filters.
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'outcomemap:programid',
            'outcomemap:catalogcourseid',
            'outcomemap:moodlecourseid',
            'outcomemap:periodcode',
            'outcomemap:outcomeversionid',
            'outcomemap:relationtype',
            'outcomemap:relationstatus',
        ];
    }
}
