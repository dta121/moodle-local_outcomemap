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
    global $CFG, $DB;

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

    if ($oldversion < 2026072500) {
        $table = new xmldb_table('local_outcomemap_policy');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('policyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
        $table->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('policytype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('scopetype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL);
        $table->add_field('scopeid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('configjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('confighash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('effectivefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('effectiveto', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('approvedat', XMLDB_TYPE_INTEGER, '10');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('policyversion_uq', XMLDB_KEY_UNIQUE, ['policyuuid', 'version']);
        $table->add_index('typescopestatus_ix', XMLDB_INDEX_NOTUNIQUE,
            ['policytype', 'scopetype', 'scopeid', 'status']);
        $table->add_index('effectivefrom_ix', XMLDB_INDEX_NOTUNIQUE, ['effectivefrom']);
        $table->add_index('effectiveto_ix', XMLDB_INDEX_NOTUNIQUE, ['effectiveto']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_outcomemap_band');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('policyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('description', XMLDB_TYPE_TEXT);
        $table->add_field('minpercent', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('mininclusive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('maxpercent', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('maxinclusive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('policy_fk', XMLDB_KEY_FOREIGN, ['policyid'], 'local_outcomemap_policy', ['id']);
        $table->add_key('policycode_uq', XMLDB_KEY_UNIQUE, ['policyid', 'code']);
        $table->add_key('policysort_uq', XMLDB_KEY_UNIQUE, ['policyid', 'sortorder']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_outcomemap_evidence');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
        $table->add_field('lineageuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
        $table->add_field('dedupekey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('sourceevidenceid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('relationpathjson', XMLDB_TYPE_TEXT);
        $table->add_field('cinstid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('assessmentcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('quizattemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('questionusageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('slot', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('questionattemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('questionversionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('itemverid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('mappingid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('policyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('evidencetype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('rawfraction', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('rawmark', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('maxmark', XMLDB_TYPE_NUMBER, '20, 10', null, XMLDB_NOTNULL);
        $table->add_field('mappingweight', XMLDB_TYPE_NUMBER, '20, 10', null, XMLDB_NOTNULL);
        $table->add_field('relationweight', XMLDB_TYPE_NUMBER, '20, 10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('weightedearned', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('weightedpossible', XMLDB_TYPE_NUMBER, '20, 10', null, XMLDB_NOTNULL);
        $table->add_field('gradingstate', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL);
        $table->add_field('attempttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('gradingtime', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('supersededby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cinst_fk', XMLDB_KEY_FOREIGN, ['cinstid'], 'local_outcomemap_cinst', ['id']);
        $table->add_key('itemver_fk', XMLDB_KEY_FOREIGN, ['itemverid'], 'local_outcomemap_itemver', ['id']);
        $table->add_key('mapping_fk', XMLDB_KEY_FOREIGN, ['mappingid'], 'local_outcomemap_qmap', ['id']);
        $table->add_key('policy_fk', XMLDB_KEY_FOREIGN, ['policyid'], 'local_outcomemap_policy', ['id']);
        $table->add_key('source_fk', XMLDB_KEY_FOREIGN, ['sourceevidenceid'], 'local_outcomemap_evidence', ['id']);
        $table->add_key('superseded_fk', XMLDB_KEY_FOREIGN, ['supersededby'], 'local_outcomemap_evidence', ['id']);
        $table->add_key('uuid_uq', XMLDB_KEY_UNIQUE, ['uuid']);
        $table->add_key('dedupekey_uq', XMLDB_KEY_UNIQUE, ['dedupekey']);
        $table->add_index('lineageuuid_ix', XMLDB_INDEX_NOTUNIQUE, ['lineageuuid']);
        $table->add_index('usercinstitem_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'cinstid', 'itemverid']);
        $table->add_index('quizattemptid_ix', XMLDB_INDEX_NOTUNIQUE, ['quizattemptid']);
        $table->add_index('questionattemptid_ix', XMLDB_INDEX_NOTUNIQUE, ['questionattemptid']);
        $table->add_index('questionversionid_ix', XMLDB_INDEX_NOTUNIQUE, ['questionversionid']);
        $table->add_index('gradingstate_ix', XMLDB_INDEX_NOTUNIQUE, ['gradingstate']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_outcomemap_result');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
        $table->add_field('resultkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('cinstid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('scopetype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL);
        $table->add_field('scopeid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('periodcode', XMLDB_TYPE_CHAR, '100');
        $table->add_field('itemverid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('policyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('numerator', XMLDB_TYPE_NUMBER, '20, 10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('denominator', XMLDB_TYPE_NUMBER, '20, 10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('percentage', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('distinctitems', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('bandid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('state', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL);
        $table->add_field('stale', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('algoversion', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('inputhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('lineagejson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('lineagehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('supersededby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecalculated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cinst_fk', XMLDB_KEY_FOREIGN, ['cinstid'], 'local_outcomemap_cinst', ['id']);
        $table->add_key('itemver_fk', XMLDB_KEY_FOREIGN, ['itemverid'], 'local_outcomemap_itemver', ['id']);
        $table->add_key('policy_fk', XMLDB_KEY_FOREIGN, ['policyid'], 'local_outcomemap_policy', ['id']);
        $table->add_key('band_fk', XMLDB_KEY_FOREIGN, ['bandid'], 'local_outcomemap_band', ['id']);
        $table->add_key('superseded_fk', XMLDB_KEY_FOREIGN, ['supersededby'], 'local_outcomemap_result', ['id']);
        $table->add_key('uuid_uq', XMLDB_KEY_UNIQUE, ['uuid']);
        $table->add_key('resultkeyversion_uq', XMLDB_KEY_UNIQUE, ['resultkey', 'version']);
        $table->add_index('usercinstitemstate_ix', XMLDB_INDEX_NOTUNIQUE,
            ['userid', 'cinstid', 'itemverid', 'state']);
        $table->add_index('scope_ix', XMLDB_INDEX_NOTUNIQUE, ['scopetype', 'scopeid', 'periodcode']);
        $table->add_index('inputhash_ix', XMLDB_INDEX_NOTUNIQUE, ['inputhash']);
        $table->add_index('lineagehash_ix', XMLDB_INDEX_NOTUNIQUE, ['lineagehash']);
        $table->add_index('stale_ix', XMLDB_INDEX_NOTUNIQUE, ['stale']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072500, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072502) {
        $table = new xmldb_table('local_outcomemap_remed');

        $field = new xmldb_field('purpose', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null,
            'review', 'targettype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null,
            '0', 'priority');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $key = new xmldb_key('band_fk', XMLDB_KEY_FOREIGN, ['bandid'], 'local_outcomemap_band', ['id']);
        $keyindex = new xmldb_index('band_fk', XMLDB_INDEX_NOTUNIQUE, ['bandid']);
        if (!$dbman->index_exists($table, $keyindex)) {
            $dbman->add_key($table, $key);
        }

        upgrade_plugin_savepoint(true, 2026072502, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072503) {
        $table = new xmldb_table('local_outcomemap_policyrel');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('policyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('releasedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('policy_fk', XMLDB_KEY_FOREIGN, ['policyid'], 'local_outcomemap_policy', ['id']);
        $table->add_key('policy_uq', XMLDB_KEY_UNIQUE, ['policyid']);
        $table->add_index('releasedat_ix', XMLDB_INDEX_NOTUNIQUE, ['releasedat']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072503, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072508) {
        $table = new xmldb_table('local_outcomemap_program');

        $field = new xmldb_field('programtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null,
            'graduate', 'externalid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('credential', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null,
            'degree', 'programtype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('programtype_ix', XMLDB_INDEX_NOTUNIQUE, ['programtype']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026072508, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072600) {
        $table = new xmldb_table('local_outcomemap_snapshot');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('snapshotuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
        $table->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('previousid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('programid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('periodcode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('cohortid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('policyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('notes', XMLDB_TYPE_TEXT);
        $table->add_field('correctionreason', XMLDB_TYPE_TEXT);
        $table->add_field('populationsource', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('retentionbasis', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('populationat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('populationcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('suppressionthreshold', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('subjecthashmethod', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('pluginversion', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('algoversion', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('payloadhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('manifesthash', XMLDB_TYPE_CHAR, '64');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('approvedat', XMLDB_TYPE_INTEGER, '10');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('previous_fk', XMLDB_KEY_FOREIGN, ['previousid'], 'local_outcomemap_snapshot', ['id']);
        $table->add_key('program_fk', XMLDB_KEY_FOREIGN, ['programid'], 'local_outcomemap_program', ['id']);
        $table->add_key('policy_fk', XMLDB_KEY_FOREIGN, ['policyid'], 'local_outcomemap_policy', ['id']);
        $table->add_key('uuidversion_uq', XMLDB_KEY_UNIQUE, ['snapshotuuid', 'version']);
        $table->add_index('programperiodstatus_ix', XMLDB_INDEX_NOTUNIQUE,
            ['programid', 'periodcode', 'status']);
        $table->add_index('cohortid_ix', XMLDB_INDEX_NOTUNIQUE, ['cohortid']);
        $table->add_index('approvedat_ix', XMLDB_INDEX_NOTUNIQUE, ['approvedat']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_outcomemap_snapitem');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('snapshotid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('itemtype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL);
        $table->add_field('stablekey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('subjectref', XMLDB_TYPE_CHAR, '64');
        $table->add_field('sourceuuid', XMLDB_TYPE_CHAR, '36');
        $table->add_field('sourceid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('cinstid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('itemverid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('state', XMLDB_TYPE_CHAR, '30');
        $table->add_field('bandcode', XMLDB_TYPE_CHAR, '50');
        $table->add_field('numerator', XMLDB_TYPE_NUMBER, '20, 10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('denominator', XMLDB_TYPE_NUMBER, '20, 10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('percentage', XMLDB_TYPE_NUMBER, '20, 10');
        $table->add_field('subjectcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('suppressed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('payloadjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('payloadhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('snapshot_fk', XMLDB_KEY_FOREIGN, ['snapshotid'], 'local_outcomemap_snapshot', ['id']);
        $table->add_key('cinst_fk', XMLDB_KEY_FOREIGN, ['cinstid'], 'local_outcomemap_cinst', ['id']);
        $table->add_key('itemver_fk', XMLDB_KEY_FOREIGN, ['itemverid'], 'local_outcomemap_itemver', ['id']);
        $table->add_key('item_uq', XMLDB_KEY_UNIQUE, ['snapshotid', 'itemtype', 'stablekey']);
        $table->add_index('snapshottypeorder_ix', XMLDB_INDEX_NOTUNIQUE,
            ['snapshotid', 'itemtype', 'sortorder']);
        $table->add_index('subjectref_ix', XMLDB_INDEX_NOTUNIQUE, ['subjectref']);
        $table->add_index('sourceuuid_ix', XMLDB_INDEX_NOTUNIQUE, ['sourceuuid']);
        $table->add_index('itemversuppressed_ix', XMLDB_INDEX_NOTUNIQUE, ['itemverid', 'suppressed']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072600, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072601) {
        $table = new xmldb_table('local_outcomemap_remed_event');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('eventuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
        $table->add_field('remediationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('resultid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('eventtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('occurredat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('remediation_fk', XMLDB_KEY_FOREIGN,
            ['remediationid'], 'local_outcomemap_remed', ['id']);
        $table->add_key('result_fk', XMLDB_KEY_FOREIGN,
            ['resultid'], 'local_outcomemap_result', ['id']);
        $table->add_key('eventuuid_uq', XMLDB_KEY_UNIQUE, ['eventuuid']);
        $table->add_index('useroccurred_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'occurredat']);
        $table->add_index('remedevent_ix', XMLDB_INDEX_NOTUNIQUE,
            ['remediationid', 'eventtype', 'occurredat']);
        $table->add_index('resultevent_ix', XMLDB_INDEX_NOTUNIQUE, ['resultid', 'eventtype']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072601, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072700) {
        $table = new xmldb_table('local_outcomemap_privkey');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('keyvalue', XMLDB_TYPE_CHAR, '64');
        $table->add_field('legacyerased', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userhash_uq', XMLDB_KEY_UNIQUE, ['userhash']);
        $table->add_index('legacyerased_ix', XMLDB_INDEX_NOTUNIQUE, ['legacyerased']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072700, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072701) {
        $table = new xmldb_table('local_outcomemap_privkey');
        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('userid_ix', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Recover discoverability for active 0.7.0 keys once. Runtime Privacy
        // API discovery then uses userid_ix and never scans Moodle's user table.
        if (!empty($CFG->passwordsaltmain)) {
            $keyids = [];
            $keys = $DB->get_records_select(
                'local_outcomemap_privkey',
                'userid IS NULL AND keyvalue IS NOT NULL',
                [],
                '',
                'id,userhash'
            );
            foreach ($keys as $key) {
                $keyids[(string) $key->userhash] = (int) $key->id;
            }
            if ($keyids) {
                $users = $DB->get_recordset('user', null, '', 'id');
                foreach ($users as $user) {
                    $hash = hash_hmac(
                        'sha256',
                        'local_outcomemap:privacy-subject:' . (int) $user->id,
                        (string) $CFG->passwordsaltmain
                    );
                    if (isset($keyids[$hash])) {
                        $DB->set_field('local_outcomemap_privkey', 'userid', (int) $user->id, [
                            'id' => $keyids[$hash],
                        ]);
                    }
                }
                $users->close();
            }
        }

        // This one-time privacy migration is the only supported rewrite of
        // historical audit payloads. It removes learner data written before
        // insertion-time minimisation; normal APIs remain strictly insert-only.
        $audits = $DB->get_recordset(
            'local_outcomemap_audit',
            null,
            'id ASC',
            'id,objecttype,beforejson,afterjson'
        );
        foreach ($audits as $audit) {
            $before = \local_outcomemap\local\audit_payload::minimise_json(
                (string) $audit->objecttype,
                $audit->beforejson
            );
            $after = \local_outcomemap\local\audit_payload::minimise_json(
                (string) $audit->objecttype,
                $audit->afterjson
            );
            if ($before !== $audit->beforejson || $after !== $audit->afterjson) {
                $DB->update_record('local_outcomemap_audit', (object) [
                    'id' => (int) $audit->id,
                    'beforejson' => $before,
                    'afterjson' => $after,
                ]);
            }
        }
        $audits->close();

        upgrade_plugin_savepoint(true, 2026072701, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072702) {
        $table = new xmldb_table('local_outcomemap_qmap');

        $field = new xmldb_field('sourceqmapid', XMLDB_TYPE_INTEGER, '10', null, null, null,
            null, 'questionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('sourcequestionversionid', XMLDB_TYPE_INTEGER, '10', null, null, null,
            null, 'sourceqmapid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The foreign key supplies its own index on sourceqmapid, so no separate
        // sourceqmapid index is declared -- one would collide with it in XMLDB.
        // Guard on that index alone: find_key_name() reports a match here even
        // when the index has not been created, which skipped the add entirely
        // and left the upgraded schema short of install.xml.
        $key = new xmldb_key('sourceqmap_fk', XMLDB_KEY_FOREIGN,
            ['sourceqmapid'], 'local_outcomemap_qmap', ['id']);
        $keyindex = new xmldb_index('sourceqmap_fk', XMLDB_INDEX_NOTUNIQUE, ['sourceqmapid']);
        if (!$dbman->index_exists($table, $keyindex)) {
            $dbman->add_key($table, $key);
        }

        $index = new xmldb_index('sourceqver_ix', XMLDB_INDEX_NOTUNIQUE, ['sourcequestionversionid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026072702, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072703) {
        // Subject markers move off the legacy $CFG->passwordsaltmain, which
        // modern Moodle neither sets nor uses, onto a durable plugin secret.
        // Seed from the legacy value while it is still present so every marker
        // already issued keeps hashing to the same value; erased markers retain
        // no user ID and could never be recomputed under a different secret.
        if (get_config('local_outcomemap', 'privacysubjectsecret') === false
                && !empty($CFG->passwordsaltmain)) {
            set_config('privacysubjectsecret', (string) $CFG->passwordsaltmain, 'local_outcomemap');
        }

        upgrade_plugin_savepoint(true, 2026072703, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072801) {
        // Accreditation reporting states the share of learners who met the
        // policy's achievement criterion, not only the pooled score. These
        // columns index that statistic so report builder and the CSV export can
        // read it without decoding payloadjson. Snapshots frozen before this
        // upgrade keep zeroed counts and null rates: they were captured under a
        // policy that stated no criterion, so no rate can be reconstructed for
        // them, and a frozen snapshot is never recalculated.
        $table = new xmldb_table('local_outcomemap_snapitem');
        $fields = [
            new xmldb_field('assessedcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0',
                'subjectcount'),
            new xmldb_field('metcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0',
                'assessedcount'),
            new xmldb_field('attainmentpercent', XMLDB_TYPE_NUMBER, '20, 10', null, null, null, null,
                'metcount'),
            new xmldb_field('criterionpercent', XMLDB_TYPE_NUMBER, '20, 10', null, null, null, null,
                'attainmentpercent'),
            new xmldb_field('benchmarkpercent', XMLDB_TYPE_NUMBER, '20, 10', null, null, null, null,
                'criterionpercent'),
            new xmldb_field('benchmarkmet', XMLDB_TYPE_INTEGER, '1', null, null, null, null,
                'benchmarkpercent'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026072801, 'local', 'outcomemap');
    }

    if ($oldversion < 2026072906) {
        // Counting captured rows per type meant summing suppressed over every
        // row, which cannot be answered from an index and so read the whole
        // capture: 67s on a programme-wide snapshot of 171,713 rows, and the
        // report page could not render inside any request budget because of it.
        // Carrying suppressed in the index makes that count index-only, which
        // measured 0.1s on the same capture.
        $table = new xmldb_table('local_outcomemap_snapitem');
        $index = new xmldb_index('snapshottypesuppressed_ix', XMLDB_INDEX_NOTUNIQUE,
            ['snapshotid', 'itemtype', 'suppressed']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026072906, 'local', 'outcomemap');
    }

    return true;
}
