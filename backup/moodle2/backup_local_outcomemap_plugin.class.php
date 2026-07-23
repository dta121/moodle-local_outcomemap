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

/**
 * Course-content backup support for local_outcomemap.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_outcomemap_plugin extends backup_local_plugin {
    /**
     * Attaches course-instance associations and external remediation.
     *
     * @return backup_plugin_element Plugin structure.
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $instances = new backup_nested_element('course_instances');
        $instance = new backup_nested_element('course_instance', ['id'], [
            'catalogcourseuuid', 'periodcode', 'externalid',
        ]);
        $wrapper->add_child($instances);
        $instances->add_child($instance);
        $instance->set_source_sql(
            'SELECT ci.id, c.uuid AS catalogcourseuuid, ci.periodcode, ci.externalid
               FROM {local_outcomemap_cinst} ci
               JOIN {local_outcomemap_course} c ON c.id = ci.courseid
              WHERE ci.moodlecourseid = ?',
            [backup::VAR_COURSEID]
        );

        $remediations = new backup_nested_element('external_remediations');
        $remediation = new backup_nested_element('external_remediation', ['id'], self::remediation_fields());
        $wrapper->add_child($remediations);
        $remediations->add_child($remediation);
        $remediation->set_source_sql(self::remediation_sql('r.targettype = :targettype AND ci.moodlecourseid = :courseid'), [
            'targettype' => backup_helper::is_sqlparam('external_url'),
            'courseid' => backup::VAR_COURSEID,
        ]);
        return $plugin;
    }

    /**
     * Attaches section mappings and section remediation.
     *
     * @return backup_plugin_element Plugin structure.
     */
    protected function define_section_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $mappings = new backup_nested_element('section_mappings');
        $mapping = new backup_nested_element('section_mapping', ['id'], self::mapping_fields());
        $wrapper->add_child($mappings);
        $mappings->add_child($mapping);
        $mapping->set_source_sql(self::mapping_sql('local_outcomemap_secmap', 'm.sectionid = :sectionid'), [
            'sectionid' => backup::VAR_SECTIONID,
        ]);

        $remediations = new backup_nested_element('section_remediations');
        $remediation = new backup_nested_element('section_remediation', ['id'], self::remediation_fields());
        $wrapper->add_child($remediations);
        $remediations->add_child($remediation);
        $remediation->set_source_sql(self::remediation_sql(
            'r.targettype = :targettype AND r.targetid = :sectionid'
        ), [
            'targettype' => backup_helper::is_sqlparam('course_section'),
            'sectionid' => backup::VAR_SECTIONID,
            ]);
        return $plugin;
    }

    /**
     * Attaches module mappings and module remediation.
     *
     * @return backup_plugin_element Plugin structure.
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $mappings = new backup_nested_element('module_mappings');
        $mapping = new backup_nested_element('module_mapping', ['id'], self::mapping_fields());
        $wrapper->add_child($mappings);
        $mappings->add_child($mapping);
        $mapping->set_source_sql(self::mapping_sql('local_outcomemap_cmmap', 'm.cmid = :cmid'), [
            'cmid' => backup::VAR_MODID,
        ]);

        $remediations = new backup_nested_element('module_remediations');
        $remediation = new backup_nested_element('module_remediation', ['id'], self::remediation_fields());
        $wrapper->add_child($remediations);
        $remediations->add_child($remediation);
        $remediation->set_source_sql(self::remediation_sql(
            'r.targettype = :targettype AND r.targetid = :cmid'
        ), [
            'targettype' => backup_helper::is_sqlparam('course_module'),
            'cmid' => backup::VAR_MODID,
            ]);
        return $plugin;
    }

    /**
     * Attaches question-version mappings to each backed-up question row.
     *
     * One backed-up question row corresponds to one concrete question version,
     * so the restored mappings can bind to the exact restored version.
     *
     * @return backup_plugin_element Plugin structure.
     */
    protected function define_question_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $mappings = new backup_nested_element('question_mappings');
        $mapping = new backup_nested_element('question_mapping', ['id'], [
            'outcomeuuid', 'outcomeversionuuid', 'role', 'weight', 'notes',
            'effectivefrom', 'effectiveto',
        ]);
        $wrapper->add_child($mappings);
        $mappings->add_child($mapping);
        $mapping->set_source_sql(
            'SELECT m.id, i.uuid AS outcomeuuid, v.uuid AS outcomeversionuuid,
                    m.role, m.weight, m.notes, m.effectivefrom, m.effectiveto
               FROM {local_outcomemap_qmap} m
               JOIN {question_versions} qv ON qv.id = m.questionversionid
               JOIN {local_outcomemap_itemver} v ON v.id = m.itemverid
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
              WHERE qv.questionid = ?',
            [backup::VAR_PARENTID]
        );
        return $plugin;
    }

    /**
     * Returns fields stored for content mappings.
     *
     * @return string[] Mapping field names.
     */
    private static function mapping_fields(): array {
        return [
            'cinstid', 'catalogcourseuuid', 'periodcode', 'outcomeuuid', 'outcomeversionuuid',
            'role', 'weight', 'priority', 'notes', 'effectivefrom', 'effectiveto',
        ];
    }

    /**
     * Returns fields stored for remediation recommendations.
     *
     * @return string[] Remediation field names.
     */
    private static function remediation_fields(): array {
        return [
            'cinstid', 'catalogcourseuuid', 'periodcode', 'outcomeuuid', 'outcomeversionuuid',
            'bandpolicyuuid', 'bandpolicyversion', 'bandcode', 'externalurl', 'title', 'explanation', 'purpose',
            'priority', 'sortorder', 'required', 'minpercent', 'maxpercent', 'effectivefrom', 'effectiveto',
        ];
    }

    /**
     * Builds the SQL query for content mappings.
     *
     * @param string $table Mapping table name.
     * @param string $where SQL condition.
     * @return string SQL query.
     */
    private static function mapping_sql(string $table, string $where): string {
        return "SELECT m.id, m.cinstid, c.uuid AS catalogcourseuuid, ci.periodcode,
                       i.uuid AS outcomeuuid, v.uuid AS outcomeversionuuid,
                       m.role, m.weight, m.priority, m.notes, m.effectivefrom, m.effectiveto
                  FROM {{$table}} m
                  JOIN {local_outcomemap_cinst} ci ON ci.id = m.cinstid
                  JOIN {local_outcomemap_course} c ON c.id = ci.courseid
                  JOIN {local_outcomemap_itemver} v ON v.id = m.itemverid
                  JOIN {local_outcomemap_item} i ON i.id = v.itemid
                 WHERE $where";
    }

    /**
     * Builds the SQL query for remediation recommendations.
     *
     * @param string $where SQL condition.
     * @return string SQL query.
     */
    private static function remediation_sql(string $where): string {
        return "SELECT r.id, r.cinstid, c.uuid AS catalogcourseuuid, ci.periodcode,
                       i.uuid AS outcomeuuid, v.uuid AS outcomeversionuuid,
                       p.policyuuid AS bandpolicyuuid, p.version AS bandpolicyversion,
                       b.code AS bandcode,
                       r.externalurl, r.title, r.explanation, r.purpose, r.priority, r.sortorder,
                       r.required, r.minpercent, r.maxpercent, r.effectivefrom, r.effectiveto
                  FROM {local_outcomemap_remed} r
                  JOIN {local_outcomemap_cinst} ci ON ci.id = r.cinstid
                  JOIN {local_outcomemap_course} c ON c.id = ci.courseid
                  JOIN {local_outcomemap_itemver} v ON v.id = r.itemverid
                  JOIN {local_outcomemap_item} i ON i.id = v.itemid
             LEFT JOIN {local_outcomemap_band} b ON b.id = r.bandid
             LEFT JOIN {local_outcomemap_policy} p ON p.id = b.policyid
                 WHERE $where";
    }
}
