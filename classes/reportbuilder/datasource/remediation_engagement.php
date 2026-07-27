<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\reportbuilder\datasource;

use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\course_selector;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format as report_format;
use core_reportbuilder\local\report\column;
use lang_string;
use local_outcomemap\local\service\remediation_engagement_service;
use local_outcomemap\reportbuilder\local\entities\report_record;
use local_outcomemap\reportbuilder\local\filter_options;
use local_outcomemap\reportbuilder\local\secured_datasource;

/**
 * Governed remediation recommendations and explicit learner engagement.
 *
 * The source emits one row per approved program membership and explicit event,
 * retaining one recommendation row with empty event fields when no learner has
 * opened it. Engagement comes only from the append-only plugin redirect event;
 * activity completion, access logs, and resource views are never inferred as
 * engagement or mastery evidence. Program is visible by default because a
 * catalog course may intentionally contribute the same fact to several
 * program-reporting grains.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remediation_engagement extends secured_datasource {
    /** @return string */
    public static function get_name(): string {
        return get_string('report_source_remediation_engagement', 'local_outcomemap');
    }

    /** @return string[] */
    protected static function get_required_capabilities(): array {
        return [
            'local/outcomemap:viewdefinitions',
            'local/outcomemap:mapcourse',
            'local/outcomemap:viewallresults',
        ];
    }

    /**
     * Moodle course IDs where all source capabilities survive overrides.
     *
     * @return int[]
     */
    private static function allowed_course_ids(): array {
        static $allowedcourseids = null;
        if ($allowedcourseids !== null) {
            return $allowedcourseids;
        }

        global $DB;
        $preload = \core\context_helper::get_preload_record_columns_sql('ctx');
        $records = $DB->get_records_sql(
            "SELECT DISTINCT {$preload}
               FROM {context} ctx
               JOIN {local_outcomemap_cinst} ci ON ci.moodlecourseid = ctx.instanceid
               JOIN {local_outcomemap_remed} r ON r.cinstid = ci.id
              WHERE ctx.contextlevel = :contextlevel",
            ['contextlevel' => CONTEXT_COURSE]
        );
        $allowedcontexts = \local_outcomemap\reportbuilder\local\access::allowed_context_ids(
            $records,
            self::get_required_capabilities()
        );
        $allowedcourseids = [];
        foreach ($records as $record) {
            if (isset($allowedcontexts[(int) $record->ctxid])) {
                $allowedcourseids[(int) $record->ctxinstance] = (int) $record->ctxinstance;
            }
        }
        return array_values($allowedcourseids);
    }

    /**
     * Withdraw the source from report creation when remediation is switched off.
     *
     * The gate belongs here rather than only in can_view_scoped(), because
     * can_view() ORs in system-context access and an administrator would
     * otherwise keep seeing the source after the feature was turned off.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return \local_outcomemap\local\feature::remediation_enabled() && parent::is_available();
    }

    /** @return bool */
    protected static function can_view_scoped(): bool {
        return \local_outcomemap\local\feature::remediation_enabled()
            && self::allowed_course_ids() !== [];
    }

    /** Build the source. */
    protected function initialise_source(): void {
        $entity = new report_record(
            [
                'local_outcomemap_remed',
                'local_outcomemap_remed_event',
                'local_outcomemap_result',
                'user',
                'local_outcomemap_cinst',
                'local_outcomemap_course',
                'course',
                'local_outcomemap_progcourse',
                'local_outcomemap_program',
                'local_outcomemap_itemver',
                'local_outcomemap_item',
                'local_outcomemap_fw',
                'local_outcomemap_band',
            ],
            new lang_string('report_source_remediation_engagement', 'local_outcomemap')
        );
        $recommendation = $entity->get_table_alias('local_outcomemap_remed');
        $engagement = $entity->get_table_alias('local_outcomemap_remed_event');
        $result = $entity->get_table_alias('local_outcomemap_result');
        $user = $entity->get_table_alias('user');
        $courseinstance = $entity->get_table_alias('local_outcomemap_cinst');
        $catalogcourse = $entity->get_table_alias('local_outcomemap_course');
        $moodlecourse = $entity->get_table_alias('course');
        $membership = $entity->get_table_alias('local_outcomemap_progcourse');
        $program = $entity->get_table_alias('local_outcomemap_program');
        $outcomeversion = $entity->get_table_alias('local_outcomemap_itemver');
        $outcome = $entity->get_table_alias('local_outcomemap_item');
        $framework = $entity->get_table_alias('local_outcomemap_fw');
        $band = $entity->get_table_alias('local_outcomemap_band');
        $coursemodule = database::generate_alias('coursemodule');
        $section = database::generate_alias('section');

        $this->add_allowed_id_condition("{$moodlecourse}.id", self::allowed_course_ids());

        $this->add_join("LEFT JOIN {local_outcomemap_remed_event} {$engagement}
                             ON {$engagement}.remediationid = {$recommendation}.id");
        $this->add_join("LEFT JOIN {local_outcomemap_result} {$result}
                             ON {$result}.id = {$engagement}.resultid
                            AND {$result}.userid = {$engagement}.userid");
        $this->add_join("LEFT JOIN {user} {$user} ON {$user}.id = {$engagement}.userid");
        $this->add_join("JOIN {local_outcomemap_cinst} {$courseinstance}
                             ON {$courseinstance}.id = {$recommendation}.cinstid");
        $this->add_join("JOIN {local_outcomemap_course} {$catalogcourse}
                             ON {$catalogcourse}.id = {$courseinstance}.courseid");
        $this->add_join("JOIN {course} {$moodlecourse}
                             ON {$moodlecourse}.id = {$courseinstance}.moodlecourseid");
        $this->add_join("LEFT JOIN (
                           SELECT DISTINCT programid, courseid
                             FROM {local_outcomemap_progcourse}
                            WHERE status = 'approved'
                       ) {$membership}
                             ON {$membership}.courseid = {$catalogcourse}.id");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$program}
                             ON {$program}.id = {$membership}.programid");
        $this->add_join("JOIN {local_outcomemap_itemver} {$outcomeversion}
                             ON {$outcomeversion}.id = {$recommendation}.itemverid");
        $this->add_join("JOIN {local_outcomemap_item} {$outcome}
                             ON {$outcome}.id = {$outcomeversion}.itemid");
        $this->add_join("JOIN {local_outcomemap_fw} {$framework}
                             ON {$framework}.id = {$outcome}.frameworkid");
        $this->add_join("LEFT JOIN {local_outcomemap_band} {$band}
                             ON {$band}.id = {$recommendation}.bandid");
        $this->add_join("LEFT JOIN {course_modules} {$coursemodule}
                             ON {$coursemodule}.id = {$recommendation}.targetid
                            AND {$recommendation}.targettype = 'course_module'
                            AND {$coursemodule}.course = {$moodlecourse}.id");
        $this->add_join("LEFT JOIN {course_sections} {$section}
                             ON {$section}.id = {$recommendation}.targetid
                            AND {$recommendation}.targettype = 'course_section'
                            AND {$section}.course = {$moodlecourse}.id");

        $entity
            ->define_column('recordid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$recommendation}.id"])
            ->define_column('mappinguuid', new lang_string('uuid', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$recommendation}.mappinguuid"])
            ->define_column('version', new lang_string('version', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$recommendation}.version"])
            ->define_column('engagementid', new lang_string('reportcolumn_engagementid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$engagement}.id"], true, null, [], true)
            ->define_column('engagementuuid', new lang_string('reportcolumn_engagementuuid', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$engagement}.eventuuid"])
            ->define_column('engagementtype', new lang_string('reportcolumn_engagementtype', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$engagement}.eventtype"])
            ->define_column('userid', new lang_string('user'),
                column::TYPE_INTEGER, ["{$engagement}.userid"], true, null, [], true)
            ->define_column('username', new lang_string('username'),
                column::TYPE_TEXT, ["{$user}.username"])
            ->define_column('userfullname', new lang_string('fullnameuser'), column::TYPE_TEXT, [
                'firstname' => "{$user}.firstname",
                'lastname' => "{$user}.lastname",
                'firstnamephonetic' => "{$user}.firstnamephonetic",
                'lastnamephonetic' => "{$user}.lastnamephonetic",
                'middlename' => "{$user}.middlename",
                'alternatename' => "{$user}.alternatename",
            ], true, [\local_outcomemap\reportbuilder\local\format::class, 'user_fullname'],
                ['lastname', 'firstname'])
            ->define_column('resultid', new lang_string('reportcolumn_resultid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$engagement}.resultid"], true, null, [], true)
            ->define_column('resultstate', new lang_string('reportcolumn_state', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.state"])
            ->define_column('resultpercentage', new lang_string('reportcolumn_percentage', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$result}.percentage"], true, null, [], true)
            ->define_column('engagementtime', new lang_string('reportcolumn_engagementtime', 'local_outcomemap'),
                column::TYPE_TIMESTAMP, ["{$engagement}.occurredat"], true,
                [report_format::class, 'userdate'])
            ->define_column('programid', new lang_string('reportcolumn_programid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$program}.id"])
            ->define_column('programcode', new lang_string('program', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$program}.code"])
            ->define_column('catalogcourseid',
                new lang_string('reportcolumn_catalogcourseid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$catalogcourse}.id"])
            ->define_column('catalogcoursecode', new lang_string('catalogcourse', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$catalogcourse}.code"])
            ->define_column('moodlecourseid',
                new lang_string('reportcolumn_moodlecourseid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$moodlecourse}.id"])
            ->define_column('moodlecoursename', new lang_string('moodlecourse', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$moodlecourse}.fullname"])
            ->define_column('periodcode', new lang_string('periodcode', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$courseinstance}.periodcode"])
            ->define_column('outcomeversionid',
                new lang_string('reportcolumn_outcomeversionid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$outcomeversion}.id"])
            ->define_column('outcomecode', new lang_string('outcome', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$outcome}.code"])
            ->define_column('outcomestatement', new lang_string('statement', 'local_outcomemap'),
                column::TYPE_LONGTEXT, ["{$outcomeversion}.statement"])
            ->define_column('band', new lang_string('performanceband', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$band}.code"])
            ->define_column('bandname', new lang_string('name', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$band}.name"])
            ->define_column('title', new lang_string('title', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$recommendation}.title"])
            ->define_column('explanation', new lang_string('explanation', 'local_outcomemap'),
                column::TYPE_LONGTEXT, ["{$recommendation}.explanation"])
            ->define_column('targettype', new lang_string('targettype', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$recommendation}.targettype"])
            ->define_column('targetid', new lang_string('target', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$recommendation}.targetid"])
            ->define_column('coursemoduleid', new lang_string('coursemodule', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$coursemodule}.id"])
            ->define_column('sectionname', new lang_string('coursesection', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$section}.name"])
            ->define_column('externalurl', new lang_string('externalurl', 'local_outcomemap'),
                column::TYPE_LONGTEXT, ["{$recommendation}.externalurl"])
            ->define_column('purpose', new lang_string('remediationpurpose', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$recommendation}.purpose"])
            ->define_column('priority', new lang_string('priority', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$recommendation}.priority"])
            ->define_column('sortorder', new lang_string('displayorder', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$recommendation}.sortorder"])
            ->define_column('required', new lang_string('requiredremediation', 'local_outcomemap'),
                column::TYPE_BOOLEAN, ["{$recommendation}.required"], true,
                [report_format::class, 'boolean_as_text'])
            ->define_column('minpercent', new lang_string('minpercent', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$recommendation}.minpercent"], true, null, [], true)
            ->define_column('maxpercent', new lang_string('maxpercent', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$recommendation}.maxpercent"], true, null, [], true)
            ->define_column('status', new lang_string('status', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$recommendation}.status"])
            ->define_column('effectivefrom', new lang_string('effectivefrom', 'local_outcomemap'),
                column::TYPE_TIMESTAMP, ["{$recommendation}.effectivefrom"], true,
                [report_format::class, 'userdate'])
            ->define_column('effectiveto', new lang_string('effectiveto', 'local_outcomemap'),
                column::TYPE_TIMESTAMP, ["{$recommendation}.effectiveto"], true,
                [report_format::class, 'userdate'])
            ->define_filter('programid', new lang_string('program', 'local_outcomemap'),
                number::class, "{$program}.id")
            ->define_filter('catalogcourseid', new lang_string('catalogcourse', 'local_outcomemap'),
                number::class, "{$catalogcourse}.id")
            ->define_filter('moodlecourseid', new lang_string('moodlecourse', 'local_outcomemap'),
                course_selector::class, "{$moodlecourse}.id")
            ->define_filter('cohortid', new lang_string('cohort', 'local_outcomemap'),
                \local_outcomemap\reportbuilder\local\filters\cohort_membership::class, "{$engagement}.userid")
            ->define_filter('userid', new lang_string('user'), number::class, "{$engagement}.userid")
            ->define_filter('engagementtype', new lang_string('reportcolumn_engagementtype', 'local_outcomemap'),
                select::class, "{$engagement}.eventtype", [
                    remediation_engagement_service::EVENT_OPENED =>
                        get_string('engagementevent_opened', 'local_outcomemap'),
                ])
            ->define_filter('periodcode', new lang_string('periodcode', 'local_outcomemap'),
                text::class, "{$courseinstance}.periodcode")
            ->define_filter('outcomeversionid', new lang_string('outcomeversion', 'local_outcomemap'),
                number::class, "{$outcomeversion}.id")
            ->define_filter('outcomecode', new lang_string('outcome', 'local_outcomemap'),
                text::class, "{$outcome}.code")
            ->define_filter('band', new lang_string('performanceband', 'local_outcomemap'),
                text::class, "{$band}.code")
            ->define_filter('targettype', new lang_string('targettype', 'local_outcomemap'),
                select::class, "{$recommendation}.targettype", filter_options::remediation_targets())
            ->define_filter('purpose', new lang_string('remediationpurpose', 'local_outcomemap'),
                select::class, "{$recommendation}.purpose", filter_options::remediation_purposes())
            ->define_filter('required', new lang_string('requiredremediation', 'local_outcomemap'),
                boolean_select::class, "{$recommendation}.required")
            ->define_filter('status', new lang_string('status', 'local_outcomemap'),
                select::class, "{$recommendation}.status", filter_options::workflow_states());

        $this->register_entity($entity, 'local_outcomemap_remed');
    }

    /** @return string[] */
    public function get_default_columns(): array {
        return [
            'outcomemap:programcode',
            'outcomemap:catalogcoursecode',
            'outcomemap:moodlecoursename',
            'outcomemap:periodcode',
            'outcomemap:outcomecode',
            'outcomemap:band',
            'outcomemap:title',
            'outcomemap:targettype',
            'outcomemap:purpose',
            'outcomemap:required',
            'outcomemap:status',
            'outcomemap:engagementtype',
            'outcomemap:userfullname',
            'outcomemap:resultstate',
            'outcomemap:resultpercentage',
            'outcomemap:engagementtime',
        ];
    }

    /** @return string[] */
    public function get_default_filters(): array {
        return [
            'outcomemap:programid',
            'outcomemap:catalogcourseid',
            'outcomemap:moodlecourseid',
            'outcomemap:cohortid',
            'outcomemap:engagementtype',
            'outcomemap:periodcode',
            'outcomemap:outcomeversionid',
            'outcomemap:band',
            'outcomemap:targettype',
            'outcomemap:purpose',
            'outcomemap:status',
        ];
    }
}
