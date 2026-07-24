<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\reportbuilder\datasource;

use core\context_helper;
use core_reportbuilder\local\filters\course_selector;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\column;
use lang_string;
use local_outcomemap\reportbuilder\local\access;
use local_outcomemap\reportbuilder\local\entities\report_record;
use local_outcomemap\reportbuilder\local\filter_options;
use local_outcomemap\reportbuilder\local\secured_datasource;

/**
 * Exact question-version mappings and observed assessment coverage.
 *
 * Assessment/course dimensions are populated only where authoritative
 * evidence proves the mapped version was used. Course-owned outcomes repeat
 * once per approved program membership by design; program is therefore a
 * default column and evidence counts cannot be aggregated across that grain.
 * Question rows are restricted to preloaded category contexts where both local
 * capabilities and Moodle's ownership-aware view capability apply.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assessment_coverage extends secured_datasource {
    /** @return string */
    public static function get_name(): string {
        return get_string('report_source_assessment_coverage', 'local_outcomemap');
    }

    /** @return string[] */
    protected static function get_required_capabilities(): array {
        return [
            'local/outcomemap:viewdefinitions',
            'local/outcomemap:mapquestions',
        ];
    }

    /** @return string[] */
    protected static function get_any_capabilities(): array {
        return [
            'moodle/question:viewall',
            'moodle/question:viewmine',
        ];
    }

    /**
     * Bulk-load every question-category context represented by this source.
     *
     * @return \stdClass[]
     */
    private static function question_context_records(): array {
        static $records = null;
        if ($records !== null) {
            return $records;
        }

        global $DB;
        $preload = context_helper::get_preload_record_columns_sql('ctx');
        $records = $DB->get_records_sql(
            "SELECT DISTINCT {$preload}
               FROM {context} ctx
               JOIN {question_categories} qc ON qc.contextid = ctx.id
               JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {local_outcomemap_qmap} qm ON qm.questionversionid = qv.id"
        );
        return $records;
    }

    /**
     * Context IDs allowed for one core question capability.
     *
     * @param string $questioncapability Core viewall or viewmine capability.
     * @return int[]
     */
    private static function allowed_question_context_ids(string $questioncapability): array {
        static $cache = [];
        if (isset($cache[$questioncapability])) {
            return $cache[$questioncapability];
        }

        $required = array_merge(self::get_required_capabilities(), [$questioncapability]);
        $cache[$questioncapability] = array_values(access::allowed_context_ids(
            self::question_context_records(),
            $required
        ));
        return $cache[$questioncapability];
    }

    /** @return bool */
    protected static function can_view_scoped(): bool {
        return self::allowed_question_context_ids('moodle/question:viewall') !== []
            || self::allowed_question_context_ids('moodle/question:viewmine') !== [];
    }

    /** Build the source. */
    protected function initialise_source(): void {
        global $DB, $USER;

        $entity = new report_record(
            [
                'local_outcomemap_qmap',
                'question_versions',
                'question',
                'question_bank_entries',
                'question_categories',
                'local_outcomemap_itemver',
                'local_outcomemap_item',
                'local_outcomemap_fw',
                'local_outcomemap_progcourse',
            ],
            new lang_string('report_source_assessment_coverage', 'local_outcomemap')
        );
        $mapping = $entity->get_table_alias('local_outcomemap_qmap');
        $questionversion = $entity->get_table_alias('question_versions');
        $question = $entity->get_table_alias('question');
        $entry = $entity->get_table_alias('question_bank_entries');
        $category = $entity->get_table_alias('question_categories');
        $outcomeversion = $entity->get_table_alias('local_outcomemap_itemver');
        $outcome = $entity->get_table_alias('local_outcomemap_item');
        $framework = $entity->get_table_alias('local_outcomemap_fw');
        $membership = $entity->get_table_alias('local_outcomemap_progcourse');
        $ownerprogram = database::generate_alias('ownerprogram');
        $membershipprogram = database::generate_alias('membershipprogram');
        $ownercourse = database::generate_alias('ownercourse');
        $observed = database::generate_alias('observed');
        $evidence = database::generate_alias('evidence');
        $courseinstance = database::generate_alias('courseinstance');
        $observedcourse = database::generate_alias('observedcourse');
        $moodlecourse = database::generate_alias('moodlecourse');
        $coursemodule = database::generate_alias('coursemodule');
        $module = database::generate_alias('module');
        $quiz = database::generate_alias('quiz');

        $this->add_join("JOIN {question_versions} {$questionversion}
                             ON {$questionversion}.id = {$mapping}.questionversionid
                            AND {$questionversion}.questionid = {$mapping}.questionid");
        $this->add_join("JOIN {question} {$question} ON {$question}.id = {$mapping}.questionid");
        $this->add_join("JOIN {question_bank_entries} {$entry}
                             ON {$entry}.id = {$questionversion}.questionbankentryid");
        $this->add_join("JOIN {question_categories} {$category}
                             ON {$category}.id = {$entry}.questioncategoryid");
        $this->add_join("JOIN {local_outcomemap_itemver} {$outcomeversion}
                             ON {$outcomeversion}.id = {$mapping}.itemverid");
        $this->add_join("JOIN {local_outcomemap_item} {$outcome}
                             ON {$outcome}.id = {$outcomeversion}.itemid");
        $this->add_join("JOIN {local_outcomemap_fw} {$framework}
                             ON {$framework}.id = {$outcome}.frameworkid");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$ownerprogram}
                             ON {$ownerprogram}.id = {$framework}.ownerid
                            AND {$framework}.ownertype = 'program'");
        $this->add_join("LEFT JOIN {local_outcomemap_course} {$ownercourse}
                             ON {$ownercourse}.id = {$framework}.ownerid
                            AND {$framework}.ownertype = 'catalog_course'");
        $this->add_join("LEFT JOIN (
                           SELECT {$evidence}.mappingid, {$evidence}.cinstid,
                                  {$evidence}.assessmentcmid,
                                  COUNT({$evidence}.id) AS evidencecount
                             FROM {local_outcomemap_evidence} {$evidence}
                            WHERE {$evidence}.supersededby IS NULL
                         GROUP BY {$evidence}.mappingid, {$evidence}.cinstid,
                                  {$evidence}.assessmentcmid
                       ) {$observed} ON {$observed}.mappingid = {$mapping}.id");
        $this->add_join("LEFT JOIN {local_outcomemap_cinst} {$courseinstance}
                             ON {$courseinstance}.id = {$observed}.cinstid");
        $this->add_join("LEFT JOIN {local_outcomemap_course} {$observedcourse}
                             ON {$observedcourse}.id = {$courseinstance}.courseid");
        $this->add_join("LEFT JOIN (
                           SELECT DISTINCT programid, courseid
                             FROM {local_outcomemap_progcourse}
                            WHERE status = 'approved'
                       ) {$membership}
                             ON {$membership}.courseid = COALESCE({$observedcourse}.id, {$ownercourse}.id)
                            AND {$framework}.ownertype <> 'program'");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$membershipprogram}
                             ON {$membershipprogram}.id = {$membership}.programid");
        $this->add_join("LEFT JOIN {course} {$moodlecourse}
                             ON {$moodlecourse}.id = {$courseinstance}.moodlecourseid");
        $this->add_join("LEFT JOIN {course_modules} {$coursemodule}
                             ON {$coursemodule}.id = {$observed}.assessmentcmid
                            AND {$coursemodule}.course = {$moodlecourse}.id");
        $this->add_join("LEFT JOIN {modules} {$module}
                             ON {$module}.id = {$coursemodule}.module
                            AND {$module}.name = 'quiz'");
        $this->add_join("LEFT JOIN {quiz} {$quiz}
                             ON {$quiz}.id = {$coursemodule}.instance
                            AND {$quiz}.course = {$moodlecourse}.id
                            AND {$module}.id IS NOT NULL");

        $scopeconditions = [];
        $scopeparams = [];
        $viewallcontexts = self::allowed_question_context_ids('moodle/question:viewall');
        if ($viewallcontexts !== []) {
            $prefix = database::generate_param_name() . 'qall';
            [$insql, $params] = $DB->get_in_or_equal($viewallcontexts, SQL_PARAMS_NAMED, $prefix);
            $scopeconditions[] = "{$category}.contextid {$insql}";
            $scopeparams += $params;
        }
        $viewminecontexts = self::allowed_question_context_ids('moodle/question:viewmine');
        if ($viewminecontexts !== []) {
            $prefix = database::generate_param_name() . 'qmine';
            [$insql, $params] = $DB->get_in_or_equal($viewminecontexts, SQL_PARAMS_NAMED, $prefix);
            $ownerparam = database::generate_param_name();
            $scopeconditions[] = "({$category}.contextid {$insql}
                                   AND {$question}.createdby = :{$ownerparam})";
            $scopeparams += $params;
            $scopeparams[$ownerparam] = (int) $USER->id;
        }
        $this->add_base_condition_sql(
            $scopeconditions === [] ? '1 = 0' : '(' . implode(' OR ', $scopeconditions) . ')',
            $scopeparams
        );

        $programid = "COALESCE({$ownerprogram}.id, {$membershipprogram}.id)";
        $programcode = "COALESCE({$ownerprogram}.code, {$membershipprogram}.code)";
        $catalogcourseid = "COALESCE({$observedcourse}.id, {$ownercourse}.id)";
        $catalogcoursecode = "COALESCE({$observedcourse}.code, {$ownercourse}.code)";
        $catalogcoursename = "COALESCE({$observedcourse}.name, {$ownercourse}.name)";

        $entity
            ->define_column('recordid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$mapping}.id"])
            ->define_column('mappinguuid', new lang_string('uuid', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$mapping}.mappinguuid"])
            ->define_column('mappingversion', new lang_string('version', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$mapping}.version"])
            ->define_column('mappingrole', new lang_string('mappingrole', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$mapping}.role"])
            ->define_column('mappingweight', new lang_string('mappingweight', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$mapping}.weight"], true, null, [], true)
            ->define_column('mappingstatus', new lang_string('status', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$mapping}.status"])
            ->define_column('questionversionid', new lang_string('question_version', 'question'),
                column::TYPE_INTEGER, ["{$questionversion}.id"])
            ->define_column('questionversion', new lang_string('version', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$questionversion}.version"])
            ->define_column('questionid', new lang_string('question'),
                column::TYPE_INTEGER, ["{$question}.id"])
            ->define_column('questionname', new lang_string('questionname', 'question'),
                column::TYPE_TEXT, ["{$question}.name"])
            ->define_column('questioncategory', new lang_string('category', 'question'),
                column::TYPE_TEXT, ["{$category}.name"])
            ->define_column('outcomeversionid',
                new lang_string('reportcolumn_outcomeversionid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$outcomeversion}.id"])
            ->define_column('outcomecode', new lang_string('outcome', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$outcome}.code"])
            ->define_column('outcomestatement', new lang_string('statement', 'local_outcomemap'),
                column::TYPE_LONGTEXT, ["{$outcomeversion}.statement"])
            ->define_column('programid', new lang_string('reportcolumn_programid', 'local_outcomemap'),
                column::TYPE_INTEGER, ['programid' => $programid])
            ->define_column('programcode', new lang_string('program', 'local_outcomemap'),
                column::TYPE_TEXT, ['programcode' => $programcode])
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
                column::TYPE_TEXT, ["{$courseinstance}.periodcode"])
            ->define_column('assessmentid',
                new lang_string('reportcolumn_assessmentid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$coursemodule}.id"])
            ->define_column('assessmentname', new lang_string('assessment', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$quiz}.name"])
            ->define_column('evidencecount', new lang_string('itemcount', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$observed}.evidencecount"], true, null, [], true)
            ->define_filter('programid', new lang_string('program', 'local_outcomemap'),
                number::class, $programid)
            ->define_filter('catalogcourseid', new lang_string('catalogcourse', 'local_outcomemap'),
                number::class, $catalogcourseid)
            ->define_filter('moodlecourseid', new lang_string('moodlecourse', 'local_outcomemap'),
                course_selector::class, "{$moodlecourse}.id")
            ->define_filter('periodcode', new lang_string('periodcode', 'local_outcomemap'),
                text::class, "{$courseinstance}.periodcode")
            ->define_filter('assessmentid', new lang_string('assessment', 'local_outcomemap'),
                number::class, "{$coursemodule}.id")
            ->define_filter('questionversionid', new lang_string('question_version', 'question'),
                number::class, "{$questionversion}.id")
            ->define_filter('outcomeversionid', new lang_string('outcomeversion', 'local_outcomemap'),
                number::class, "{$outcomeversion}.id")
            ->define_filter('outcomecode', new lang_string('outcome', 'local_outcomemap'),
                text::class, "{$outcome}.code")
            ->define_filter('mappingrole', new lang_string('mappingrole', 'local_outcomemap'),
                select::class, "{$mapping}.role", filter_options::mapping_roles())
            ->define_filter('mappingstatus', new lang_string('status', 'local_outcomemap'),
                select::class, "{$mapping}.status", filter_options::workflow_states());

        $this->register_entity($entity, 'local_outcomemap_qmap');
    }

    /** @return string[] */
    public function get_default_columns(): array {
        return [
            'outcomemap:programcode',
            'outcomemap:questionname',
            'outcomemap:questionversion',
            'outcomemap:outcomecode',
            'outcomemap:mappingrole',
            'outcomemap:mappingweight',
            'outcomemap:mappingstatus',
            'outcomemap:assessmentname',
            'outcomemap:moodlecoursename',
            'outcomemap:evidencecount',
        ];
    }

    /** @return string[] */
    public function get_default_filters(): array {
        return [
            'outcomemap:programid',
            'outcomemap:catalogcourseid',
            'outcomemap:moodlecourseid',
            'outcomemap:periodcode',
            'outcomemap:assessmentid',
            'outcomemap:outcomeversionid',
            'outcomemap:mappingrole',
            'outcomemap:mappingstatus',
        ];
    }
}
