<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\reportbuilder\datasource;

use core_reportbuilder\local\filters\cohort as cohort_filter;
use core_reportbuilder\local\filters\course_selector;
use core_reportbuilder\local\filters\date;
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
 * Append-only mapping, calculation, and snapshot audit history.
 *
 * Generic audit fields are always available. Conditional, set-based joins
 * enrich governed mapping, evidence, policy, relation, result, and snapshot
 * objects without parsing JSON or querying once per row. Deleted draft
 * objects remain reportable through their immutable before/after payloads.
 *
 * Course-associated events intentionally repeat once for every currently
 * approved program membership. Program is exposed by default so this explicit
 * reporting grain is visible rather than silently multiplying identical rows.
 * Policy scope joins themselves are one-to-one; assessment policy events do
 * not infer a catalog course through potentially multiple course instances.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class audit_history extends secured_datasource {
    /** @return string */
    public static function get_name(): string {
        return get_string('report_source_audit_history', 'local_outcomemap');
    }

    /** @return string[] */
    protected static function get_required_capabilities(): array {
        return [
            'local/outcomemap:exportaccreditation',
            'local/outcomemap:viewallresults',
            'local/outcomemap:managesnapshots',
        ];
    }

    /** Build the source. */
    protected function initialise_source(): void {
        $entity = new report_record(
            [
                'local_outcomemap_audit',
                'user',
                'context',
                'local_outcomemap_snapshot',
                'local_outcomemap_result',
                'local_outcomemap_evidence',
                'local_outcomemap_policy',
                'local_outcomemap_qmap',
                'local_outcomemap_cmmap',
                'local_outcomemap_secmap',
                'local_outcomemap_remed',
                'local_outcomemap_rel',
            ],
            new lang_string('report_source_audit_history', 'local_outcomemap')
        );
        $audit = $entity->get_table_alias('local_outcomemap_audit');
        $actor = $entity->get_table_alias('user');
        $context = $entity->get_table_alias('context');
        $snapshot = $entity->get_table_alias('local_outcomemap_snapshot');
        $result = $entity->get_table_alias('local_outcomemap_result');
        $evidence = $entity->get_table_alias('local_outcomemap_evidence');
        $policy = $entity->get_table_alias('local_outcomemap_policy');
        $questionmapping = $entity->get_table_alias('local_outcomemap_qmap');
        $modulemapping = $entity->get_table_alias('local_outcomemap_cmmap');
        $sectionmapping = $entity->get_table_alias('local_outcomemap_secmap');
        $remediation = $entity->get_table_alias('local_outcomemap_remed');
        $relation = $entity->get_table_alias('local_outcomemap_rel');
        $lineagepolicy = database::generate_alias('lineagepolicy');
        $evidencemapping = database::generate_alias('evidencemapping');
        $snapshotprogram = database::generate_alias('snapshotprogram');
        $cohort = database::generate_alias('cohort');
        $outcomeversion = database::generate_alias('outcomeversion');
        $outcome = database::generate_alias('outcome');
        $framework = database::generate_alias('framework');
        $frameworkprogram = database::generate_alias('frameworkprogram');
        $frameworkcourse = database::generate_alias('frameworkcourse');
        $relationsource = database::generate_alias('relationsource');
        $relationtarget = database::generate_alias('relationtarget');
        $relationsourceframework = database::generate_alias('relationsourceframework');
        $relationtargetframework = database::generate_alias('relationtargetframework');
        $relationsourceprogram = database::generate_alias('relationsourceprogram');
        $relationtargetprogram = database::generate_alias('relationtargetprogram');
        $relationsourcecourse = database::generate_alias('relationsourcecourse');
        $relationtargetcourse = database::generate_alias('relationtargetcourse');
        $policyprogram = database::generate_alias('policyprogram');
        $courseinstance = database::generate_alias('courseinstance');
        $catalogcourse = database::generate_alias('catalogcourse');
        $membership = database::generate_alias('membership');
        $membershipprogram = database::generate_alias('membershipprogram');
        $band = database::generate_alias('band');
        $quizattempt = database::generate_alias('quizattempt');
        $quizmodule = database::generate_alias('quizmodule');
        $coursemodule = database::generate_alias('coursemodule');
        $moodlecourse = database::generate_alias('moodlecourse');
        $module = database::generate_alias('module');
        $quiz = database::generate_alias('quiz');

        $this->add_join("LEFT JOIN {user} {$actor} ON {$actor}.id = {$audit}.actorid");
        $this->add_join("LEFT JOIN {context} {$context} ON {$context}.id = {$audit}.contextid");
        $this->add_join("LEFT JOIN {local_outcomemap_snapshot} {$snapshot}
                             ON {$snapshot}.id = {$audit}.objectid
                            AND {$snapshot}.snapshotuuid = {$audit}.objectuuid
                            AND {$audit}.objecttype = 'snapshot'");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$snapshotprogram}
                             ON {$snapshotprogram}.id = {$snapshot}.programid");
        $this->add_join("LEFT JOIN {cohort} {$cohort} ON {$cohort}.id = {$snapshot}.cohortid");
        $this->add_join("LEFT JOIN {local_outcomemap_result} {$result}
                             ON {$result}.id = {$audit}.objectid
                            AND {$result}.uuid = {$audit}.objectuuid
                            AND {$audit}.objecttype = 'result'");
        $this->add_join("LEFT JOIN {local_outcomemap_evidence} {$evidence}
                             ON {$evidence}.id = {$audit}.objectid
                            AND {$evidence}.uuid = {$audit}.objectuuid
                            AND {$audit}.objecttype = 'evidence'");
        $this->add_join("LEFT JOIN {local_outcomemap_policy} {$policy}
                             ON {$policy}.id = {$audit}.objectid
                            AND {$policy}.policyuuid = {$audit}.objectuuid
                            AND {$audit}.objecttype = 'policy'");
        $this->add_join("LEFT JOIN {local_outcomemap_policy} {$lineagepolicy}
                             ON {$lineagepolicy}.id = COALESCE(
                                    {$result}.policyid,
                                    {$evidence}.policyid,
                                    {$snapshot}.policyid
                                )");
        $this->add_join("LEFT JOIN {local_outcomemap_qmap} {$questionmapping}
                             ON {$questionmapping}.id = {$audit}.objectid
                            AND {$questionmapping}.mappinguuid = {$audit}.objectuuid
                            AND {$audit}.objecttype = 'question_mapping'");
        $this->add_join("LEFT JOIN {local_outcomemap_qmap} {$evidencemapping}
                             ON {$evidencemapping}.id = {$evidence}.mappingid");
        $this->add_join("LEFT JOIN {local_outcomemap_cmmap} {$modulemapping}
                             ON {$modulemapping}.id = {$audit}.objectid
                            AND {$modulemapping}.mappinguuid = {$audit}.objectuuid
                            AND {$audit}.objecttype = 'content_mapping'");
        $this->add_join("LEFT JOIN {local_outcomemap_secmap} {$sectionmapping}
                             ON {$sectionmapping}.id = {$audit}.objectid
                            AND {$sectionmapping}.mappinguuid = {$audit}.objectuuid
                            AND {$audit}.objecttype = 'content_mapping'");
        $this->add_join("LEFT JOIN {local_outcomemap_remed} {$remediation}
                             ON {$remediation}.id = {$audit}.objectid
                            AND {$remediation}.mappinguuid = {$audit}.objectuuid
                            AND {$audit}.objecttype = 'remediation'");
        $this->add_join("LEFT JOIN {local_outcomemap_rel} {$relation}
                             ON {$relation}.id = {$audit}.objectid
                            AND {$relation}.relationuuid = {$audit}.objectuuid
                            AND {$audit}.objecttype = 'relation'");
        $this->add_join("LEFT JOIN {local_outcomemap_itemver} {$outcomeversion}
                             ON {$outcomeversion}.id = COALESCE(
                                    {$result}.itemverid,
                                    {$evidence}.itemverid,
                                    {$questionmapping}.itemverid,
                                    {$modulemapping}.itemverid,
                                    {$sectionmapping}.itemverid,
                                    {$remediation}.itemverid
                                )");
        $this->add_join("LEFT JOIN {local_outcomemap_item} {$outcome}
                             ON {$outcome}.id = {$outcomeversion}.itemid");
        $this->add_join("LEFT JOIN {local_outcomemap_fw} {$framework}
                             ON {$framework}.id = {$outcome}.frameworkid");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$frameworkprogram}
                             ON {$frameworkprogram}.id = {$framework}.ownerid
                            AND {$framework}.ownertype = 'program'");
        $this->add_join("LEFT JOIN {local_outcomemap_course} {$frameworkcourse}
                             ON {$frameworkcourse}.id = {$framework}.ownerid
                            AND {$framework}.ownertype = 'catalog_course'");
        $this->add_join("LEFT JOIN {local_outcomemap_item} {$relationsource}
                             ON {$relationsource}.id = {$relation}.sourceitemid");
        $this->add_join("LEFT JOIN {local_outcomemap_item} {$relationtarget}
                             ON {$relationtarget}.id = {$relation}.targetitemid");
        $this->add_join("LEFT JOIN {local_outcomemap_fw} {$relationsourceframework}
                             ON {$relationsourceframework}.id = {$relationsource}.frameworkid");
        $this->add_join("LEFT JOIN {local_outcomemap_fw} {$relationtargetframework}
                             ON {$relationtargetframework}.id = {$relationtarget}.frameworkid");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$relationsourceprogram}
                             ON {$relationsourceprogram}.id = {$relationsourceframework}.ownerid
                            AND {$relationsourceframework}.ownertype = 'program'");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$relationtargetprogram}
                             ON {$relationtargetprogram}.id = {$relationtargetframework}.ownerid
                            AND {$relationtargetframework}.ownertype = 'program'");
        $this->add_join("LEFT JOIN {local_outcomemap_course} {$relationsourcecourse}
                             ON {$relationsourcecourse}.id = {$relationsourceframework}.ownerid
                            AND {$relationsourceframework}.ownertype = 'catalog_course'");
        $this->add_join("LEFT JOIN {local_outcomemap_course} {$relationtargetcourse}
                             ON {$relationtargetcourse}.id = {$relationtargetframework}.ownerid
                            AND {$relationtargetframework}.ownertype = 'catalog_course'");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$policyprogram}
                             ON {$policyprogram}.id = {$policy}.scopeid
                            AND {$policy}.scopetype = 'program'");
        $this->add_join("LEFT JOIN {local_outcomemap_cinst} {$courseinstance}
                             ON {$courseinstance}.id = COALESCE(
                                    {$result}.cinstid,
                                    {$evidence}.cinstid,
                                    {$modulemapping}.cinstid,
                                    {$sectionmapping}.cinstid,
                                    {$remediation}.cinstid,
                                    CASE WHEN {$policy}.scopetype = 'course_instance'
                                         THEN {$policy}.scopeid END
                                )");
        $this->add_join("LEFT JOIN {local_outcomemap_course} {$catalogcourse}
                             ON {$catalogcourse}.id = COALESCE(
                                    {$courseinstance}.courseid,
                                    CASE WHEN {$policy}.scopetype = 'catalog_course'
                                         THEN {$policy}.scopeid END
                                )");
        $this->add_join("LEFT JOIN (
                           SELECT DISTINCT programid, courseid
                             FROM {local_outcomemap_progcourse}
                            WHERE status = 'approved'
                       ) {$membership}
                             ON {$membership}.courseid = COALESCE(
                                    {$catalogcourse}.id,
                                    {$frameworkcourse}.id,
                                    {$relationsourcecourse}.id,
                                    {$relationtargetcourse}.id
                                )
                            AND {$policyprogram}.id IS NULL
                            AND {$frameworkprogram}.id IS NULL
                            AND {$relationsourceprogram}.id IS NULL
                            AND {$relationtargetprogram}.id IS NULL");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$membershipprogram}
                             ON {$membershipprogram}.id = {$membership}.programid");
        $this->add_join("LEFT JOIN {local_outcomemap_band} {$band}
                             ON {$band}.id = COALESCE({$result}.bandid, {$remediation}.bandid)");
        $this->add_join("LEFT JOIN {quiz_attempts} {$quizattempt}
                             ON {$quizattempt}.id = {$result}.scopeid
                            AND {$result}.scopetype = 'quiz_attempt'
                            AND {$quizattempt}.userid = {$result}.userid");
        $this->add_join("LEFT JOIN {modules} {$quizmodule} ON {$quizmodule}.name = 'quiz'");
        $this->add_join("LEFT JOIN {course_modules} {$coursemodule}
                             ON ({$coursemodule}.id = {$evidence}.assessmentcmid
                                 OR ({$policy}.scopetype = 'assessment'
                                     AND {$coursemodule}.id = {$policy}.scopeid)
                                 OR ({$result}.scopetype = 'assessment'
                                     AND {$coursemodule}.id = {$result}.scopeid)
                                 OR ({$result}.scopetype = 'quiz_attempt'
                                     AND {$coursemodule}.instance = {$quizattempt}.quiz
                                     AND {$coursemodule}.module = {$quizmodule}.id)
                                 OR ({$modulemapping}.role = 'assesses'
                                     AND {$coursemodule}.id = {$modulemapping}.cmid))");
        $this->add_join("LEFT JOIN {course} {$moodlecourse}
                             ON {$moodlecourse}.id = COALESCE(
                                    {$courseinstance}.moodlecourseid,
                                    {$coursemodule}.course
                                )");
        $this->add_join("LEFT JOIN {modules} {$module}
                             ON {$module}.id = {$coursemodule}.module");
        $this->add_join("LEFT JOIN {quiz} {$quiz}
                             ON {$quiz}.id = {$coursemodule}.instance
                            AND {$quiz}.course = {$moodlecourse}.id
                            AND {$module}.name = 'quiz'");

        $programid = "COALESCE(
            {$snapshotprogram}.id,
            {$policyprogram}.id,
            {$frameworkprogram}.id,
            {$relationtargetprogram}.id,
            {$relationsourceprogram}.id,
            {$membershipprogram}.id
        )";
        $programcode = "COALESCE(
            {$snapshotprogram}.code,
            {$policyprogram}.code,
            {$frameworkprogram}.code,
            {$relationtargetprogram}.code,
            {$relationsourceprogram}.code,
            {$membershipprogram}.code
        )";
        $catalogcourseid = "COALESCE(
            {$catalogcourse}.id,
            {$frameworkcourse}.id,
            {$relationsourcecourse}.id,
            {$relationtargetcourse}.id
        )";
        $catalogcoursecode = "COALESCE(
            {$catalogcourse}.code,
            {$frameworkcourse}.code,
            {$relationsourcecourse}.code,
            {$relationtargetcourse}.code
        )";
        $periodcode = "COALESCE({$snapshot}.periodcode, {$result}.periodcode, {$courseinstance}.periodcode)";
        $outcomecode = "COALESCE({$outcome}.code, {$relationsource}.code)";
        $evidencestate = "CASE
            WHEN {$evidence}.id IS NULL THEN NULL
            WHEN {$evidence}.supersededby IS NOT NULL THEN 'superseded'
            ELSE {$evidence}.gradingstate
        END";
        $state = "COALESCE(
            {$result}.state,
            {$evidencestate},
            {$snapshot}.status,
            {$policy}.status,
            {$questionmapping}.status,
            {$modulemapping}.status,
            {$sectionmapping}.status,
            {$remediation}.status,
            {$relation}.status
        )";
        $mappingtype = "CASE
            WHEN {$questionmapping}.id IS NOT NULL OR {$evidencemapping}.id IS NOT NULL
                THEN 'question_version'
            WHEN {$modulemapping}.id IS NOT NULL THEN 'course_module'
            WHEN {$sectionmapping}.id IS NOT NULL THEN 'course_section'
            WHEN {$remediation}.id IS NOT NULL THEN 'remediation'
            WHEN {$relation}.id IS NOT NULL THEN 'outcome_relation'
            ELSE NULL
        END";
        $mappingversion = "COALESCE(
            {$questionmapping}.version,
            {$evidencemapping}.version,
            {$modulemapping}.version,
            {$sectionmapping}.version,
            {$remediation}.version,
            {$relation}.version
        )";
        $mappingrole = "COALESCE(
            {$questionmapping}.role,
            {$evidencemapping}.role,
            {$modulemapping}.role,
            {$sectionmapping}.role
        )";
        $questionversionid = "COALESCE(
            {$evidence}.questionversionid,
            {$questionmapping}.questionversionid,
            {$evidencemapping}.questionversionid
        )";
        $questionid = "COALESCE(
            {$evidence}.questionid,
            {$questionmapping}.questionid,
            {$evidencemapping}.questionid
        )";
        $assessmentid = "COALESCE(
            {$evidence}.assessmentcmid,
            CASE WHEN {$policy}.scopetype = 'assessment' THEN {$policy}.scopeid END,
            CASE WHEN {$result}.scopetype = 'assessment' THEN {$result}.scopeid END,
            {$modulemapping}.cmid,
            {$coursemodule}.id
        )";
        $policyid = "COALESCE(
            {$policy}.id,
            CASE WHEN {$audit}.objecttype = 'policy' THEN {$audit}.objectid END,
            {$lineagepolicy}.id
        )";
        $policyuuid = "COALESCE(
            {$policy}.policyuuid,
            CASE WHEN {$audit}.objecttype = 'policy' THEN {$audit}.objectuuid END,
            {$lineagepolicy}.policyuuid
        )";
        $policyversion = "COALESCE({$policy}.version, {$lineagepolicy}.version)";
        $policyname = "COALESCE({$policy}.name, {$lineagepolicy}.name)";
        $policytype = "COALESCE({$policy}.policytype, {$lineagepolicy}.policytype)";
        $policyscope = "COALESCE({$policy}.scopetype, {$lineagepolicy}.scopetype)";
        $policyscopeid = "COALESCE({$policy}.scopeid, {$lineagepolicy}.scopeid)";
        $policyconfighash = "COALESCE({$policy}.confighash, {$lineagepolicy}.confighash)";
        $policystatus = "COALESCE({$policy}.status, {$lineagepolicy}.status)";

        $entity
            ->define_column('recordid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$audit}.id"])
            ->define_column('eventuuid', new lang_string('uuid', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$audit}.eventuuid"])
            ->define_column('action', new lang_string('actions', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$audit}.action"])
            ->define_column('objecttype', new lang_string('objecttype', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$audit}.objecttype"])
            ->define_column('objectid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$audit}.objectid"])
            ->define_column('objectuuid', new lang_string('uuid', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$audit}.objectuuid"])
            ->define_column('correlationid', new lang_string('externalid', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$audit}.correlationid"])
            ->define_column('actorid', new lang_string('createdby', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$actor}.id"])
            ->define_column('actorname', new lang_string('fullnameuser'), column::TYPE_TEXT, [
                'firstname' => "{$actor}.firstname",
                'lastname' => "{$actor}.lastname",
                'firstnamephonetic' => "{$actor}.firstnamephonetic",
                'lastnamephonetic' => "{$actor}.lastnamephonetic",
                'middlename' => "{$actor}.middlename",
                'alternatename' => "{$actor}.alternatename",
            ], true, [format::class, 'user_fullname'], ['lastname', 'firstname'])
            ->define_column('contextid', new lang_string('category'),
                column::TYPE_INTEGER, ["{$context}.id"])
            ->define_column('contextlevel', new lang_string('context', 'role'),
                column::TYPE_INTEGER, ["{$context}.contextlevel"])
            ->define_column('contextinstanceid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$context}.instanceid"])
            ->define_column('reason', new lang_string('reason', 'local_outcomemap'),
                column::TYPE_LONGTEXT, ["{$audit}.reason"])
            ->define_column('beforejson', new lang_string('reportcolumn_beforejson', 'local_outcomemap'),
                column::TYPE_LONGTEXT, ["{$audit}.beforejson"], false)
            ->define_column('afterjson', new lang_string('reportcolumn_afterjson', 'local_outcomemap'),
                column::TYPE_LONGTEXT, ["{$audit}.afterjson"], false)
            ->define_column('mappingtype', new lang_string('reportcolumn_recordtype', 'local_outcomemap'),
                column::TYPE_TEXT, ['mappingtype' => $mappingtype])
            ->define_column('mappingversion', new lang_string('version', 'local_outcomemap'),
                column::TYPE_INTEGER, ['mappingversion' => $mappingversion])
            ->define_column('mappingrole', new lang_string('mappingrole', 'local_outcomemap'),
                column::TYPE_TEXT, ['mappingrole' => $mappingrole])
            ->define_column('relationtype', new lang_string('relationtype', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$relation}.type"])
            ->define_column('remediationpurpose', new lang_string('remediationpurpose', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$remediation}.purpose"])
            ->define_column('evidenceuuid', new lang_string('uuid', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$evidence}.uuid"])
            ->define_column('evidencelineageuuid', new lang_string('uuid', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$evidence}.lineageuuid"])
            ->define_column('evidencetype', new lang_string('reportcolumn_recordtype', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$evidence}.evidencetype"])
            ->define_column('evidencestate', new lang_string('reportcolumn_state', 'local_outcomemap'),
                column::TYPE_TEXT, ['evidencestate' => $evidencestate])
            ->define_column('evidenceuserid', new lang_string('user'),
                column::TYPE_INTEGER, ["{$evidence}.userid"])
            ->define_column('evidencesourceid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$evidence}.sourceevidenceid"])
            ->define_column('evidencesupersededby', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$evidence}.supersededby"])
            ->define_column('questionversionid', new lang_string('question_version', 'question'),
                column::TYPE_INTEGER, ['questionversionid' => $questionversionid])
            ->define_column('questionid', new lang_string('question'),
                column::TYPE_INTEGER, ['questionid' => $questionid])
            ->define_column('policyid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ['policyid' => $policyid])
            ->define_column('policyuuid', new lang_string('uuid', 'local_outcomemap'),
                column::TYPE_TEXT, ['policyuuid' => $policyuuid])
            ->define_column('policyversion', new lang_string('version', 'local_outcomemap'),
                column::TYPE_INTEGER, ['policyversion' => $policyversion])
            ->define_column('policyname', new lang_string('policy', 'local_outcomemap'),
                column::TYPE_TEXT, ['policyname' => $policyname])
            ->define_column('policytype', new lang_string('policytype', 'local_outcomemap'),
                column::TYPE_TEXT, ['policytype' => $policytype])
            ->define_column('policyscope', new lang_string('policyscope', 'local_outcomemap'),
                column::TYPE_TEXT, ['policyscope' => $policyscope])
            ->define_column('policyscopeid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ['policyscopeid' => $policyscopeid])
            ->define_column('policyconfighash', new lang_string('payloadhash', 'local_outcomemap'),
                column::TYPE_TEXT, ['policyconfighash' => $policyconfighash])
            ->define_column('policystatus', new lang_string('status', 'local_outcomemap'),
                column::TYPE_TEXT, ['policystatus' => $policystatus])
            ->define_column('programid', new lang_string('reportcolumn_programid', 'local_outcomemap'),
                column::TYPE_INTEGER, ['programid' => $programid])
            ->define_column('programcode', new lang_string('program', 'local_outcomemap'),
                column::TYPE_TEXT, ['programcode' => $programcode])
            ->define_column('catalogcourseid',
                new lang_string('reportcolumn_catalogcourseid', 'local_outcomemap'),
                column::TYPE_INTEGER, ['catalogcourseid' => $catalogcourseid])
            ->define_column('catalogcoursecode', new lang_string('catalogcourse', 'local_outcomemap'),
                column::TYPE_TEXT, ['catalogcoursecode' => $catalogcoursecode])
            ->define_column('moodlecourseid',
                new lang_string('reportcolumn_moodlecourseid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$moodlecourse}.id"])
            ->define_column('moodlecoursename', new lang_string('moodlecourse', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$moodlecourse}.fullname"])
            ->define_column('periodcode', new lang_string('periodcode', 'local_outcomemap'),
                column::TYPE_TEXT, ['periodcode' => $periodcode])
            ->define_column('assessmentid',
                new lang_string('reportcolumn_assessmentid', 'local_outcomemap'),
                column::TYPE_INTEGER, ['assessmentid' => $assessmentid])
            ->define_column('assessmentname', new lang_string('assessment', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$quiz}.name"])
            ->define_column('cohortid', new lang_string('reportcolumn_cohortid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$cohort}.id"])
            ->define_column('cohortname', new lang_string('cohort', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$cohort}.name"])
            ->define_column('outcomeversionid',
                new lang_string('reportcolumn_outcomeversionid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$outcomeversion}.id"])
            ->define_column('outcomecode', new lang_string('outcome', 'local_outcomemap'),
                column::TYPE_TEXT, ['outcomecode' => $outcomecode])
            ->define_column('targetoutcomecode', new lang_string('targetoutcome', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$relationtarget}.code"])
            ->define_column('state', new lang_string('reportcolumn_state', 'local_outcomemap'),
                column::TYPE_TEXT, ['state' => $state])
            ->define_column('band', new lang_string('reportcolumn_band', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$band}.code"])
            ->define_column('resultversion', new lang_string('version', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$result}.version"])
            ->define_column('resultscope', new lang_string('policyscope', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.scopetype"])
            ->define_column('resultpercentage', new lang_string('reportcolumn_percentage', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.percentage"], true, null, [], true)
            ->define_column('inputhash', new lang_string('reportcolumn_inputhash', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.inputhash"])
            ->define_column('lineagehash', new lang_string('reportcolumn_lineagehash', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.lineagehash"])
            ->define_column('snapshotuuid', new lang_string('snapshotuuid', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$snapshot}.snapshotuuid"])
            ->define_column('snapshotversion', new lang_string('snapshotversion', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$snapshot}.version"])
            ->define_column('snapshotpayloadhash', new lang_string('payloadhash', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$snapshot}.payloadhash"])
            ->define_column('snapshotmanifesthash', new lang_string('manifesthash', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$snapshot}.manifesthash"])
            ->define_column('timecreated', new lang_string('reportcolumn_timecreated', 'local_outcomemap'),
                column::TYPE_TIMESTAMP, ["{$audit}.timecreated"], true,
                [report_format::class, 'userdate'])
            ->define_filter('action', new lang_string('actions', 'local_outcomemap'),
                text::class, "{$audit}.action")
            ->define_filter('objecttype', new lang_string('objecttype', 'local_outcomemap'),
                text::class, "{$audit}.objecttype")
            ->define_filter('objectid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                number::class, "{$audit}.objectid")
            ->define_filter('actorid', new lang_string('createdby', 'local_outcomemap'),
                number::class, "{$audit}.actorid")
            ->define_filter('correlationid', new lang_string('externalid', 'local_outcomemap'),
                text::class, "{$audit}.correlationid")
            ->define_filter('mappingtype', new lang_string('reportcolumn_recordtype', 'local_outcomemap'),
                text::class, $mappingtype)
            ->define_filter('mappingrole', new lang_string('mappingrole', 'local_outcomemap'),
                select::class, $mappingrole, filter_options::mapping_roles())
            ->define_filter('relationtype', new lang_string('relationtype', 'local_outcomemap'),
                select::class, "{$relation}.type", filter_options::relation_types())
            ->define_filter('evidencetype', new lang_string('reportcolumn_recordtype', 'local_outcomemap'),
                text::class, "{$evidence}.evidencetype")
            ->define_filter('evidencestate', new lang_string('reportcolumn_state', 'local_outcomemap'),
                text::class, $evidencestate)
            ->define_filter('evidencelineageuuid', new lang_string('uuid', 'local_outcomemap'),
                text::class, "{$evidence}.lineageuuid")
            ->define_filter('evidenceuserid', new lang_string('user'),
                number::class, "{$evidence}.userid")
            ->define_filter('policyid', new lang_string('policy', 'local_outcomemap'),
                number::class, $policyid)
            ->define_filter('policyuuid', new lang_string('uuid', 'local_outcomemap'),
                text::class, $policyuuid)
            ->define_filter('policyversion', new lang_string('version', 'local_outcomemap'),
                number::class, $policyversion)
            ->define_filter('policytype', new lang_string('policytype', 'local_outcomemap'),
                text::class, $policytype)
            ->define_filter('policyscope', new lang_string('policyscope', 'local_outcomemap'),
                text::class, $policyscope)
            ->define_filter('policystatus', new lang_string('status', 'local_outcomemap'),
                select::class, $policystatus, filter_options::workflow_states())
            ->define_filter('programid', new lang_string('program', 'local_outcomemap'),
                number::class, $programid)
            ->define_filter('catalogcourseid', new lang_string('catalogcourse', 'local_outcomemap'),
                number::class, $catalogcourseid)
            ->define_filter('moodlecourseid', new lang_string('moodlecourse', 'local_outcomemap'),
                course_selector::class, "{$moodlecourse}.id")
            ->define_filter('periodcode', new lang_string('periodcode', 'local_outcomemap'),
                text::class, $periodcode)
            ->define_filter('assessmentid', new lang_string('assessment', 'local_outcomemap'),
                number::class, $assessmentid)
            ->define_filter('cohortid', new lang_string('cohort', 'local_outcomemap'),
                cohort_filter::class, "{$cohort}.id")
            ->define_filter('questionversionid', new lang_string('question_version', 'question'),
                number::class, $questionversionid)
            ->define_filter('outcomeversionid', new lang_string('outcomeversion', 'local_outcomemap'),
                number::class, "{$outcomeversion}.id")
            ->define_filter('outcomecode', new lang_string('outcome', 'local_outcomemap'),
                text::class, $outcomecode)
            ->define_filter('state', new lang_string('reportcolumn_state', 'local_outcomemap'),
                text::class, $state)
            ->define_filter('band', new lang_string('performanceband', 'local_outcomemap'),
                text::class, "{$band}.code")
            ->define_filter('resultscope', new lang_string('policyscope', 'local_outcomemap'),
                select::class, "{$result}.scopetype", filter_options::result_scopes())
            ->define_filter('snapshotuuid', new lang_string('snapshotuuid', 'local_outcomemap'),
                text::class, "{$snapshot}.snapshotuuid")
            ->define_filter('timecreated', new lang_string('reportcolumn_timecreated', 'local_outcomemap'),
                date::class, "{$audit}.timecreated");

        $this->register_entity($entity, 'local_outcomemap_audit');
    }

    /** @return string[] */
    public function get_default_columns(): array {
        return [
            'outcomemap:timecreated',
            'outcomemap:actorname',
            'outcomemap:action',
            'outcomemap:objecttype',
            'outcomemap:mappingtype',
            'outcomemap:mappingversion',
            'outcomemap:evidencetype',
            'outcomemap:policyname',
            'outcomemap:objectid',
            'outcomemap:reason',
            'outcomemap:programcode',
            'outcomemap:moodlecoursename',
            'outcomemap:periodcode',
            'outcomemap:assessmentname',
            'outcomemap:outcomecode',
            'outcomemap:state',
            'outcomemap:correlationid',
        ];
    }

    /** @return string[] */
    public function get_default_filters(): array {
        return [
            'outcomemap:action',
            'outcomemap:objecttype',
            'outcomemap:mappingtype',
            'outcomemap:evidencetype',
            'outcomemap:evidencestate',
            'outcomemap:policytype',
            'outcomemap:policyscope',
            'outcomemap:policystatus',
            'outcomemap:actorid',
            'outcomemap:programid',
            'outcomemap:catalogcourseid',
            'outcomemap:moodlecourseid',
            'outcomemap:periodcode',
            'outcomemap:assessmentid',
            'outcomemap:cohortid',
            'outcomemap:questionversionid',
            'outcomemap:outcomeversionid',
            'outcomemap:state',
            'outcomemap:band',
            'outcomemap:timecreated',
        ];
    }

    /** @return int[] */
    public function get_default_column_sorting(): array {
        return ['outcomemap:timecreated' => SORT_DESC];
    }
}
