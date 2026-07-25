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

namespace local_outcomemap;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/outcomemap/db/upgrade.php');

/**
 * Upgrade-path regression tests.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class upgrade_test extends \advanced_testcase {
    /** Current plugin version under test. */
    private const CURRENT_VERSION = 2026072701;

    /**
     * Executes an upgrade from the supplied version with a matching saved version.
     *
     * @param int $oldversion Installed version to emulate.
     */
    private function run_upgrade_from(int $oldversion): void {
        set_config('version', $oldversion, 'local_outcomemap');
        $this->assertTrue(xmldb_local_outcomemap_upgrade($oldversion));
        $this->assertSame(self::CURRENT_VERSION, (int) get_config('local_outcomemap', 'version'));
    }

    /**
     * Drops tables introduced after the foundation milestone, in dependency-safe order.
     */
    private function remove_post_foundation_schema(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $tables = [
            'local_outcomemap_privkey',
            'local_outcomemap_remed_event',
            'local_outcomemap_snapitem',
            'local_outcomemap_snapshot',
            'local_outcomemap_result',
            'local_outcomemap_evidence',
            'local_outcomemap_policyrel',
            'local_outcomemap_remed',
            'local_outcomemap_band',
            'local_outcomemap_policy',
            'local_outcomemap_qmap',
            'local_outcomemap_secmap',
            'local_outcomemap_cmmap',
        ];
        foreach ($tables as $tablename) {
            $table = new \xmldb_table($tablename);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }

        $programtable = new \xmldb_table('local_outcomemap_program');
        $programtypeindex = new \xmldb_index('programtype_ix', XMLDB_INDEX_NOTUNIQUE, ['programtype']);
        if ($dbman->index_exists($programtable, $programtypeindex)) {
            $dbman->drop_index($programtable, $programtypeindex);
        }
        foreach (['credential', 'programtype'] as $fieldname) {
            $field = new \xmldb_field($fieldname);
            if ($dbman->field_exists($programtable, $field)) {
                $dbman->drop_field($programtable, $field);
            }
        }
    }

    /**
     * Asserts that the live plugin schema matches install.xml.
     */
    private function assert_schema_matches_install_xml(): void {
        global $CFG, $DB;

        $file = new \xmldb_file($CFG->dirroot . '/local/outcomemap/db/install.xml');
        $this->assertTrue($file->loadXMLStructure(), 'install.xml must load as a valid XMLDB structure.');
        $errors = $DB->get_manager()->check_database_schema($file->getStructure(), [
            'extratables' => false,
            'missingtables' => true,
            'extracolumns' => true,
            'missingcolumns' => true,
            'changedcolumns' => true,
            'missingindexes' => true,
            'extraindexes' => false,
        ]);
        $this->assertSame([], $errors, json_encode($errors, JSON_PRETTY_PRINT));
    }

    /**
     * Reconstructs every post-foundation milestone from the oldest supported schema.
     */
    public function test_upgrade_from_foundation_schema_matches_fresh_install(): void {
        $this->resetAfterTest(true);
        $this->remove_post_foundation_schema();

        $this->run_upgrade_from(2026072200);
        $this->assert_schema_matches_install_xml();
    }

    /**
     * Confirms an interrupted upgrade can resume when schema changes preceded a savepoint.
     */
    public function test_upgrade_resumes_when_schema_is_ahead_of_saved_version(): void {
        global $DB;

        $this->resetAfterTest(true);
        $record = (object) [
            'userhash' => hash('sha256', 'preserved-during-resume'),
            'keyvalue' => bin2hex(random_bytes(32)),
            'legacyerased' => 0,
            'timecreated' => 1704067200,
            'timemodified' => 1704067200,
        ];
        $record->id = $DB->insert_record('local_outcomemap_privkey', $record);

        $this->run_upgrade_from(2026072500);

        $preserved = $DB->get_record('local_outcomemap_privkey', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame($record->userhash, $preserved->userhash);
        $this->assert_schema_matches_install_xml();
    }

    /**
     * Creates the privacy-key schema when upgrading from the prior release.
     */
    public function test_privacy_key_upgrade_step_creates_complete_schema(): void {
        global $DB;

        $this->resetAfterTest(true);
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_outcomemap_privkey');
        $dbman->drop_table($table);

        $this->run_upgrade_from(2026072603);

        $this->assertTrue($dbman->table_exists($table));
        foreach (['id', 'userhash', 'keyvalue', 'legacyerased', 'timecreated', 'timemodified'] as $fieldname) {
            $this->assertTrue($dbman->field_exists($table, new \xmldb_field($fieldname)));
        }
        $this->assertTrue($dbman->index_exists(
            $table,
            new \xmldb_index('userhash_uq', XMLDB_INDEX_UNIQUE, ['userhash'])
        ));
        $this->assertTrue($dbman->index_exists(
            $table,
            new \xmldb_index('legacyerased_ix', XMLDB_INDEX_NOTUNIQUE, ['legacyerased'])
        ));
        $this->assert_schema_matches_install_xml();
    }
}
