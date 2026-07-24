<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\reportbuilder\datasource;

use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use lang_string;
use local_outcomemap\reportbuilder\local\entities\report_record;
use local_outcomemap\reportbuilder\local\filter_options;
use local_outcomemap\reportbuilder\local\secured_datasource;

/**
 * Outcome definitions and exact governed versions.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class outcome_definitions extends secured_datasource {
    /**
     * Source name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('report_source_outcome_definitions', 'local_outcomemap');
    }

    /**
     * Required capabilities.
     *
     * @return string[]
     */
    protected static function get_required_capabilities(): array {
        return ['local/outcomemap:viewdefinitions'];
    }

    /**
     * Build the source at one row per outcome version.
     */
    protected function initialise_source(): void {
        $entity = new report_record(
            [
                'local_outcomemap_itemver',
                'local_outcomemap_item',
                'local_outcomemap_fw',
                'local_outcomemap_program',
                'local_outcomemap_course',
            ],
            new lang_string('report_source_outcome_definitions', 'local_outcomemap')
        );
        $version = $entity->get_table_alias('local_outcomemap_itemver');
        $item = $entity->get_table_alias('local_outcomemap_item');
        $framework = $entity->get_table_alias('local_outcomemap_fw');
        $program = $entity->get_table_alias('local_outcomemap_program');
        $catalogcourse = $entity->get_table_alias('local_outcomemap_course');

        $this->add_join("JOIN {local_outcomemap_item} {$item} ON {$item}.id = {$version}.itemid");
        $this->add_join("JOIN {local_outcomemap_fw} {$framework} ON {$framework}.id = {$item}.frameworkid");
        $this->add_join("LEFT JOIN {local_outcomemap_program} {$program}
                             ON {$program}.id = {$framework}.ownerid
                            AND {$framework}.ownertype = 'program'");
        $this->add_join("LEFT JOIN {local_outcomemap_course} {$catalogcourse}
                             ON {$catalogcourse}.id = {$framework}.ownerid
                            AND {$framework}.ownertype = 'catalog_course'");

        $entity
            ->define_column('recordid', new lang_string('reportcolumn_recordid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$version}.id"])
            ->define_column('uuid', new lang_string('uuid', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$version}.uuid"])
            ->define_column('outcomeversionid',
                new lang_string('reportcolumn_outcomeversionid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$version}.id"])
            ->define_column('outcomecode', new lang_string('reportcolumn_code', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$item}.code"])
            ->define_column('version', new lang_string('version', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$version}.version"])
            ->define_column('statement', new lang_string('statement', 'local_outcomemap'),
                column::TYPE_LONGTEXT, ["{$version}.statement"])
            ->define_column('shortstatement', new lang_string('shortstatement', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$version}.shortstatement"])
            ->define_column('bloomlevel', new lang_string('bloomlevel', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$version}.bloomlevel"])
            ->define_column('status', new lang_string('status', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$version}.status"])
            ->define_column('effectivefrom', new lang_string('effectivefrom', 'local_outcomemap'),
                column::TYPE_TIMESTAMP, ["{$version}.effectivefrom"], true, [format::class, 'userdate'])
            ->define_column('effectiveto', new lang_string('effectiveto', 'local_outcomemap'),
                column::TYPE_TIMESTAMP, ["{$version}.effectiveto"], true, [format::class, 'userdate'])
            ->define_column('frameworkcode', new lang_string('framework', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$framework}.code"])
            ->define_column('frameworkname', new lang_string('name', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$framework}.name"])
            ->define_column('ownertype', new lang_string('ownertype', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$framework}.ownertype"])
            ->define_column('programid', new lang_string('reportcolumn_programid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$program}.id"])
            ->define_column('programcode', new lang_string('program', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$program}.code"])
            ->define_column('programname', new lang_string('name', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$program}.name"])
            ->define_column('catalogcourseid',
                new lang_string('reportcolumn_catalogcourseid', 'local_outcomemap'),
                column::TYPE_INTEGER, ["{$catalogcourse}.id"])
            ->define_column('catalogcoursecode', new lang_string('catalogcourse', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$catalogcourse}.code"])
            ->define_column('catalogcoursename', new lang_string('name', 'local_outcomemap'),
                column::TYPE_TEXT, ["{$catalogcourse}.name"])
            ->define_column('timecreated', new lang_string('reportcolumn_timecreated', 'local_outcomemap'),
                column::TYPE_TIMESTAMP, ["{$version}.timecreated"], true, [format::class, 'userdate'])
            ->define_filter('programid', new lang_string('program', 'local_outcomemap'),
                number::class, "{$program}.id")
            ->define_filter('catalogcourseid', new lang_string('catalogcourse', 'local_outcomemap'),
                number::class, "{$catalogcourse}.id")
            ->define_filter('outcomeversionid', new lang_string('outcomeversion', 'local_outcomemap'),
                number::class, "{$version}.id")
            ->define_filter('outcomecode', new lang_string('outcome', 'local_outcomemap'),
                text::class, "{$item}.code")
            ->define_filter('status', new lang_string('status', 'local_outcomemap'),
                select::class, "{$version}.status", filter_options::workflow_states())
            ->define_filter('ownertype', new lang_string('ownertype', 'local_outcomemap'),
                select::class, "{$framework}.ownertype", filter_options::owner_types())
            ->define_filter('effectivefrom', new lang_string('effectivefrom', 'local_outcomemap'),
                date::class, "{$version}.effectivefrom")
            ->define_filter('effectiveto', new lang_string('effectiveto', 'local_outcomemap'),
                date::class, "{$version}.effectiveto");

        $this->register_entity($entity, 'local_outcomemap_itemver');
    }

    /** @return string[] */
    public function get_default_columns(): array {
        return [
            'outcomemap:programcode',
            'outcomemap:catalogcoursecode',
            'outcomemap:frameworkcode',
            'outcomemap:outcomecode',
            'outcomemap:version',
            'outcomemap:shortstatement',
            'outcomemap:status',
            'outcomemap:effectivefrom',
        ];
    }

    /** @return string[] */
    public function get_default_filters(): array {
        return [
            'outcomemap:programid',
            'outcomemap:catalogcourseid',
            'outcomemap:outcomeversionid',
            'outcomemap:outcomecode',
            'outcomemap:status',
        ];
    }

    /** @return int[] */
    public function get_default_column_sorting(): array {
        return [
            'outcomemap:outcomecode' => SORT_ASC,
            'outcomemap:version' => SORT_DESC,
        ];
    }
}
