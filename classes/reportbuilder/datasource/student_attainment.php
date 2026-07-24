<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\reportbuilder\datasource;

use core\context_helper;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\course_selector;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format as report_format;
use core_reportbuilder\local\report\column;
use lang_string;
use local_outcomemap\reportbuilder\local\access;
use local_outcomemap\reportbuilder\local\entities\report_record;
use local_outcomemap\reportbuilder\local\filter_options;
use local_outcomemap\reportbuilder\local\format;
use local_outcomemap\reportbuilder\local\secured_datasource;

/**
 * Current, non-superseded student outcome attainment results.
 *
 * Course-owned results intentionally repeat once per approved program
 * membership. Program is exposed by default so this grain is never hidden.
 * Exact decimal fields are non-aggregatable, and the base query is constrained
 * to preloaded course contexts where view-all-results is actually granted.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class student_attainment extends secured_datasource {
    /** @return string */
    public static function get_name(): string {
        return get_string('report_source_student_attainment', 'local_outcomemap');
    }

    /** @return string[] */
    protected static function get_required_capabilities(): array {
        return ['local/outcomemap:viewallresults'];
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
               JOIN {local_outcomemap_result} r ON r.cinstid = ci.id
              WHERE ctx.contextlevel = :contextlevel
                AND r.userid IS NOT NULL
                AND r.supersededby IS NULL",
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

    /** @return bool */
    protected static function can_view_scoped(): bool {
        return self::allowed_course_ids() !== [];
    }

    /** Build the source. */
    protected function initialise_source(): void {
        $entity = new report_record(
            [
                'local_outcomemap_result',
                'user',
                'local_outcomemap_itemver',
                'local_outcomemap_item',
                'local_outcomemap_fw',
                'local_outcomemap_policy',
                'local_outcomemap_band',
            ],
            new lang_string('report_source_student_attainment', 'local_outcomemap')
        );
        $result = $entity->get_table_alias('local_outcomemap_result');
        $user = $entity->get_table_alias('user');
        $outcomeversion = $entity->get_table_alias('local_outcomemap_itemver');
        $outcome = $entity->get_table_alias('local_outcomemap_item');
        $framework = $entity->get_table_alias('local_outcomemap_fw');
        $policy = $entity->get_table_alias('local_outcomemap_policy');
        $band = $entity->get_table_alias('local_outcomemap_band');
        $courseinstance = database::generate_alias('courseinstance');
        $catalogcourse = database::generate_alias('catalogcourse');
        $frameworkcourse = database::generate_alias('frameworkcourse');
        $moodlecourse = database::generate_alias('moodlecourse');
        $membership = database::generate_alias('membership');
        $membershipprogram = database::generate_alias('membershipprogram');
        $frameworkprogram = database::generate_alias('frameworkprogram');
        $quizattempt = database::generate_alias('quizattempt');
        $coursemodule = database::generate_alias('coursemodule');
        $module = database::generate_alias('module');
        $quiz = database::generate_alias('quiz');

        $this->add_join("JOIN {user} {$user} ON {$user}.id = {$result}.userid");
        $this->add_join("JOIN {local_outcomemap_itemver} {$outcomeversion}
                             ON {$outcomeversion}.id = {$result}.itemverid");
        $this->add_join("JOIN {local_outcomemap_item} {$outcome}
                             ON {$outcome}.id = {$outcomeversion}.itemid");
        $this->add_join("JOIN {local_outcomemap_fw} {$framework}
                             ON {$framework}.id = {$outcome}.frameworkid");
        $this->add_join("JOIN {local_outcomemap_policy} {$policy}
                             ON {$policy}.id = {$result}.policyid");
        $this->add_join("LEFT JOIN {local_outcomemap_band} {$band}
                             ON {$band}.id = {$result}.bandid");
        $this->add_join("LEFT JOIN {local_outcomemap_cinst} {$courseinstance}
                             ON {$courseinstance}.id = {$result}.cinstid");
        $this->add_join("LEFT JOIN {local_outcomemap_course} {$catalogcourse}
                             ON {$catalogcourse}.id = {$courseinstance}.courseid");
        $this->add_join("LEFT JOIN {local_outcomemap_course} {$frameworkcourse}
                             ON {$frameworkcourse}.id = {$framework}.ownerid
                            AND {$framework}.ownertype = 'catalog_course'");
        $this->add_join("LEFT JOIN {course} {$moodlecourse}
                             ON {$moodlecourse}.id = {$courseinstance}.moodlecourseid");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$frameworkprogram}
                             ON {$frameworkprogram}.id = {$framework}.ownerid
                            AND {$framework}.ownertype = 'program'");
        $this->add_join("LEFT JOIN (
                           SELECT DISTINCT programid, courseid
                             FROM {local_outcomemap_progcourse}
                            WHERE status = 'approved'
                       ) {$membership}
                             ON {$membership}.courseid = {$catalogcourse}.id
                            AND {$framework}.ownertype <> 'program'");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$membershipprogram}
                             ON {$membershipprogram}.id = {$membership}.programid");
        $this->add_join("LEFT JOIN {quiz_attempts} {$quizattempt}
                             ON {$quizattempt}.id = {$result}.scopeid
                            AND {$result}.scopetype = 'quiz_attempt'
                            AND {$quizattempt}.userid = {$result}.userid");
        $this->add_join("LEFT JOIN {modules} {$module} ON {$module}.name = 'quiz'");
        $this->add_join("LEFT JOIN {course_modules} {$coursemodule}
                             ON {$coursemodule}.course = {$moodlecourse}.id
                            AND {$coursemodule}.module = {$module}.id
                            AND (({$result}.scopetype = 'assessment'
                                  AND {$coursemodule}.id = {$result}.scopeid)
                                 OR ({$result}.scopetype = 'quiz_attempt'
                                     AND {$coursemodule}.instance = {$quizattempt}.quiz))");
        $this->add_join("LEFT JOIN {quiz} {$quiz}
                             ON {$quiz}.id = {$coursemodule}.instance
                            AND {$quiz}.course = {$moodlecourse}.id");
        $this->add_base_condition_sql("{$result}.supersededby IS NULL");
        $this->add_base_condition_sql("{$result}.userid IS NOT NULL");
        $this->add_allowed_id_condition(
            "{$moodlecourse}.id",
            self::allowed_course_ids(),
            static::has_global_access()
        );

        $programid = "COALESCE({$frameworkprogram}.id, {$membershipprogram}.id)";
        $programcode = "COALESCE({$frameworkprogram}.code, {$membershipprogram}.code)";
        $programname = "COALESCE({$frameworkprogram}.name, {$membershipprogram}.name)";
        $catalogcourseid = "COALESCE({$catalogcourse}.id, {$frameworkcourse}.id)";
        $catalogcoursecode = "COALESCE({$catalogcourse}.code, {$frameworkcourse}.code)";
        $catalogcoursename = "COALESCE({$catalogcourse}.name, {$frameworkcourse}.name)";

        $entity
            ->define_column('recordid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$result}.id"])
            ->define_column('userid', new lang_string('user'),
                column::TYPE_INTEGER, ["{$user}.id"])
            ->define_column('username', new lang_string('username'),
                column::TYPE_TEXT, ["{$user}.username"])
            ->define_column('userfullname', new lang_string('fullnameuser'), column::TYPE_TEXT, [
                'firstname' => "{$user}.firstname",
                'lastname' => "{$user}.lastname",
                'firstnamephonetic' => "{$user}.firstnamephonetic",
                'lastnamephonetic' => "{$user}.lastnamephonetic",
                'middlename' => "{$user}.middlename",
                'alternatename' => "{$user}.alternatename",
            ], true, [format::class, 'user_fullname'], ['lastname', 'firstname'])
            ->define_column('programid', new lang_string('reportcolumn_programid', 'local_outcomemap'),
                column::TYPE_INTEGER, ['programid' => $programid])
            ->define_column('programcode', new lang_string('program', 'local_outcomemap'),
                column::TYPE_TEXT, ['programcode' => $programcode])
            ->define_column('programname', new lang_string('name', 'local_outcomemap'),
                column::TYPE_TEXT, ['programname' => $programname])
            ->define_column('catalogcourseid',
                new lang_string('reportcolumn_catalogcourseid', 'local_outcomemap'),
                column::TYPE_INTEGER, ['catalogcourseid' => $catalogcourseid])
            ->define_column('catalogcoursecode', new lang_string('catalogcourse', 'local_outcomemap'),
                column::TYPE_TEXT, ['catalogcoursecode' => $catalogcoursecode])
            ->define_column('catalogcoursename', new lang_string('name', 'local_outcomemap'),
                column::TYPE_TEXT, ['catalogcoursename' => $catalogcoursename])
            ->define_column('moodlecourseid',
                new lang_string('reportcolumn_moodlecourseid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$moodlecourse}.id"])
            ->define_column('moodlecoursename', new lang_string('moodlecourse', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$moodlecourse}.fullname"])
            ->define_column('periodcode', new lang_string('periodcode', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.periodcode"])
            ->define_column('assessmentid',
                new lang_string('reportcolumn_assessmentid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$coursemodule}.id"])
            ->define_column('assessmentname', new lang_string('assessment', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$quiz}.name"])
            ->define_column('outcomeversionid',
                new lang_string('reportcolumn_outcomeversionid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$outcomeversion}.id"])
            ->define_column('outcomecode', new lang_string('outcome', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$outcome}.code"])
            ->define_column('outcomestatement', new lang_string('statement', 'local_outcomemap'),
                column::TYPE_LONGTEXT, ["{$outcomeversion}.statement"])
            ->define_column('scopetype', new lang_string('policyscope', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.scopetype"])
            ->define_column('scopeid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$result}.scopeid"])
            ->define_column('numerator', new lang_string('reportcolumn_numerator', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.numerator"], true, null, [], true)
            ->define_column('denominator', new lang_string('weightedpossiblepoints', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.denominator"], true, null, [], true)
            ->define_column('percentage', new lang_string('reportcolumn_percentage', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.percentage"], true, null, [], true)
            ->define_column('distinctitems', new lang_string('contributingitems', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$result}.distinctitems"])
            ->define_column('state', new lang_string('reportcolumn_state', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.state"])
            ->define_column('band', new lang_string('reportcolumn_band', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$band}.code"])
            ->define_column('bandname', new lang_string('performanceband', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$band}.name"])
            ->define_column('stale', new lang_string('resultstate_stale', 'local_outcomemap'),
                column::TYPE_BOOLEAN, ["{$result}.stale"], true, [report_format::class, 'boolean_as_text'])
            ->define_column('policy', new lang_string('policy', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$policy}.name"])
            ->define_column('algoversion', new lang_string('version', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.algoversion"])
            ->define_column('inputhash', new lang_string('reportcolumn_inputhash', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.inputhash"])
            ->define_column('lineagehash', new lang_string('reportcolumn_lineagehash', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.lineagehash"])
            ->define_column('timecalculated', new lang_string('calculationtimestamp', 'local_outcomemap'),
                column::TYPE_TIMESTAMP, ["{$result}.timecalculated"], true,
                [report_format::class, 'userdate'])
            ->define_filter('programid', new lang_string('program', 'local_outcomemap'),
                number::class, $programid)
            ->define_filter('catalogcourseid', new lang_string('catalogcourse', 'local_outcomemap'),
                number::class, $catalogcourseid)
            ->define_filter('moodlecourseid', new lang_string('moodlecourse', 'local_outcomemap'),
                course_selector::class, "{$moodlecourse}.id")
            ->define_filter('cohortid', new lang_string('cohort', 'local_outcomemap'),
                \local_outcomemap\reportbuilder\local\filters\cohort_membership::class, "{$result}.userid")
            ->define_filter('periodcode', new lang_string('periodcode', 'local_outcomemap'),
                text::class, "{$result}.periodcode")
            ->define_filter('assessmentid', new lang_string('assessment', 'local_outcomemap'),
                number::class, "{$coursemodule}.id")
            ->define_filter('outcomeversionid', new lang_string('outcomeversion', 'local_outcomemap'),
                number::class, "{$outcomeversion}.id")
            ->define_filter('outcomecode', new lang_string('outcome', 'local_outcomemap'),
                text::class, "{$outcome}.code")
            ->define_filter('scopetype', new lang_string('policyscope', 'local_outcomemap'),
                select::class, "{$result}.scopetype", filter_options::result_scopes())
            ->define_filter('state', new lang_string('reportcolumn_state', 'local_outcomemap'),
                select::class, "{$result}.state", filter_options::result_states())
            ->define_filter('band', new lang_string('performanceband', 'local_outcomemap'),
                text::class, "{$band}.code")
            ->define_filter('percentage', new lang_string('reportcolumn_percentage', 'local_outcomemap'),
                number::class, "{$result}.percentage")
            ->define_filter('stale', new lang_string('resultstate_stale', 'local_outcomemap'),
                boolean_select::class, "{$result}.stale");

        $this->register_entity($entity, 'local_outcomemap_result');
    }

    /** @return string[] */
    public function get_default_columns(): array {
        return [
            'outcomemap:userfullname',
            'outcomemap:programcode',
            'outcomemap:moodlecoursename',
            'outcomemap:periodcode',
            'outcomemap:outcomecode',
            'outcomemap:scopetype',
            'outcomemap:percentage',
            'outcomemap:state',
            'outcomemap:band',
            'outcomemap:distinctitems',
            'outcomemap:timecalculated',
        ];
    }

    /** @return string[] */
    public function get_default_filters(): array {
        return [
            'outcomemap:programid',
            'outcomemap:catalogcourseid',
            'outcomemap:moodlecourseid',
            'outcomemap:cohortid',
            'outcomemap:periodcode',
            'outcomemap:assessmentid',
            'outcomemap:outcomeversionid',
            'outcomemap:state',
            'outcomemap:band',
        ];
    }
}
