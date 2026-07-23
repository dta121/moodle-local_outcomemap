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
 * Database upgrades for local_outcomemap.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade local_outcomemap.
 *
 * @param int $oldversion Installed plugin version.
 * @return bool
 */
function xmldb_local_outcomemap_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026072300) {
        $table = new xmldb_table('local_outcomemap_cmmap');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('mappinguuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
        $table->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('cinstid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('itemverid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('weight', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('priority', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('notes', XMLDB_TYPE_TEXT);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('effectivefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('effectiveto', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('approvedat', XMLDB_TYPE_INTEGER, '10');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cinst_fk', XMLDB_KEY_FOREIGN, ['cinstid'], 'local_outcomemap_cinst', ['id']);
        $table->add_key('itemver_fk', XMLDB_KEY_FOREIGN, ['itemverid'], 'local_outcomemap_itemver', ['id']);
        $table->add_key('mappingversion_uq', XMLDB_KEY_UNIQUE, ['mappinguuid', 'version']);
        $table->add_index('cinstcmstatus_ix', XMLDB_INDEX_NOTUNIQUE, ['cinstid', 'cmid', 'status']);
        $table->add_index('itemverrolestatus_ix', XMLDB_INDEX_NOTUNIQUE, ['itemverid', 'role', 'status']);
        $table->add_index('effectivefrom_ix', XMLDB_INDEX_NOTUNIQUE, ['effectivefrom']);
        $table->add_index('effectiveto_ix', XMLDB_INDEX_NOTUNIQUE, ['effectiveto']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_outcomemap_secmap');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('mappinguuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
        $table->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('cinstid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('itemverid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('weight', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('priority', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('notes', XMLDB_TYPE_TEXT);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('effectivefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('effectiveto', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('approvedat', XMLDB_TYPE_INTEGER, '10');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cinst_fk', XMLDB_KEY_FOREIGN, ['cinstid'], 'local_outcomemap_cinst', ['id']);
        $table->add_key('itemver_fk', XMLDB_KEY_FOREIGN, ['itemverid'], 'local_outcomemap_itemver', ['id']);
        $table->add_key('mappingversion_uq', XMLDB_KEY_UNIQUE, ['mappinguuid', 'version']);
        $table->add_index('cinstsectionstatus_ix', XMLDB_INDEX_NOTUNIQUE, ['cinstid', 'sectionid', 'status']);
        $table->add_index('itemverrolestatus_ix', XMLDB_INDEX_NOTUNIQUE, ['itemverid', 'role', 'status']);
        $table->add_index('effectivefrom_ix', XMLDB_INDEX_NOTUNIQUE, ['effectivefrom']);
        $table->add_index('effectiveto_ix', XMLDB_INDEX_NOTUNIQUE, ['effectiveto']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_outcomemap_remed');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('mappinguuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
        $table->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('cinstid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('itemverid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('bandid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('targettype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('externalurl', XMLDB_TYPE_TEXT);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('explanation', XMLDB_TYPE_TEXT);
        $table->add_field('priority', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('minpercent', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('maxpercent', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('effectivefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('effectiveto', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('approvedat', XMLDB_TYPE_INTEGER, '10');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cinst_fk', XMLDB_KEY_FOREIGN, ['cinstid'], 'local_outcomemap_cinst', ['id']);
        $table->add_key('itemver_fk', XMLDB_KEY_FOREIGN, ['itemverid'], 'local_outcomemap_itemver', ['id']);
        $table->add_key('mappingversion_uq', XMLDB_KEY_UNIQUE, ['mappinguuid', 'version']);
        $table->add_index('cinstitemstatus_ix', XMLDB_INDEX_NOTUNIQUE, ['cinstid', 'itemverid', 'status']);
        $table->add_index('bandidstatus_ix', XMLDB_INDEX_NOTUNIQUE, ['bandid', 'status']);
        $table->add_index('target_ix', XMLDB_INDEX_NOTUNIQUE, ['targettype', 'targetid']);
        $table->add_index('prioritystatus_ix', XMLDB_INDEX_NOTUNIQUE, ['priority', 'status']);
        $table->add_index('effectivefrom_ix', XMLDB_INDEX_NOTUNIQUE, ['effectivefrom']);
        $table->add_index('effectiveto_ix', XMLDB_INDEX_NOTUNIQUE, ['effectiveto']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072300, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072400) {
        $table = new xmldb_table('local_outcomemap_qmap');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('mappinguuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
        $table->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('questionversionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('itemverid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('weight', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('notes', XMLDB_TYPE_TEXT);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('effectivefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('effectiveto', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('approvedat', XMLDB_TYPE_INTEGER, '10');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('itemver_fk', XMLDB_KEY_FOREIGN, ['itemverid'], 'local_outcomemap_itemver', ['id']);
        $table->add_key('mappingversion_uq', XMLDB_KEY_UNIQUE, ['mappinguuid', 'version']);
        $table->add_index('qverstatus_ix', XMLDB_INDEX_NOTUNIQUE, ['questionversionid', 'status']);
        $table->add_index('questionstatus_ix', XMLDB_INDEX_NOTUNIQUE, ['questionid', 'status']);
        $table->add_index('itemverrolestatus_ix', XMLDB_INDEX_NOTUNIQUE, ['itemverid', 'role', 'status']);
        $table->add_index('effectivefrom_ix', XMLDB_INDEX_NOTUNIQUE, ['effectivefrom']);
        $table->add_index('effectiveto_ix', XMLDB_INDEX_NOTUNIQUE, ['effectiveto']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072400, 'local', 'outcomemap');
    }

    return true;
}
