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

use local_outcomemap\local\service\foundation_import_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\relation_service;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Tests importing the outcome hierarchy in the shape the plugin exports it.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class hierarchy_import_test extends \advanced_testcase {
    /** @var string The header the hierarchy export writes. */
    private const HEADER = 'Type,Framework,Code,Statement,"Maps to",Version,Status';

    /**
     * Create an approved framework.
     *
     * @param string $code Framework code.
     * @return int Framework id.
     */
    private function framework(string $code): int {
        $id = framework_service::create([
            'code' => $code,
            'name' => 'Framework ' . $code,
            'ownertype' => framework_service::OWNER_INSTITUTION,
        ]);
        framework_service::submit_for_review($id);
        return $id;
    }

    /**
     * Preview and commit one hierarchy CSV.
     *
     * @param string $csv Full CSV content including its header.
     * @return int Rows committed.
     */
    private function import(string $csv): int {
        $importid = foundation_import_service::load($csv, 'UTF-8', ',');
        try {
            $preview = foundation_import_service::preview($importid, foundation_import_service::HIERARCHY);
            $this->assertTrue($preview->valid, 'Preview reported: ' . json_encode(
                array_map(static fn($r) => $r->errors, $preview->rows)));
            return foundation_import_service::commit($importid,
                foundation_import_service::HIERARCHY, $preview->hash);
        } finally {
            foundation_import_service::cleanup($importid);
        }
    }

    /**
     * Return the preview of one hierarchy CSV without committing it.
     *
     * @param string $csv Full CSV content including its header.
     * @return \local_outcomemap\local\import_preview
     */
    private function preview(string $csv) {
        $importid = foundation_import_service::load($csv, 'UTF-8', ',');
        try {
            return foundation_import_service::preview($importid, foundation_import_service::HIERARCHY);
        } finally {
            foundation_import_service::cleanup($importid);
        }
    }

    /**
     * The exported hierarchy can be read back in, outcomes and alignments alike.
     */
    public function test_exported_hierarchy_imports_with_its_alignments(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->framework('MBA-PLO');
        $this->framework('MBA601-CLO');

        // The course outcome names its program outcome before that outcome has
        // been read, which is exactly the forward reference a row-at-a-time
        // importer cannot resolve.
        $csv = self::HEADER . "\n"
            . '"Course outcome",MBA601-CLO,0a,"Demonstrate financial literacy",MBA-PLO.PLO1,1,Approved' . "\n"
            . '"Program outcome",MBA-PLO,PLO1,"Analyse business problems",,1,Approved' . "\n"
            . '"Course outcome",MBA601-CLO,0b,"Read a balance sheet","MBA-PLO.PLO1; MBA-PLO.PLO2",1,Approved' . "\n"
            . '"Program outcome",MBA-PLO,PLO2,"Integrate functional areas",,1,Approved' . "\n";

        $this->assertSame(4, $this->import($csv));

        $this->assertSame(4, $DB->count_records('local_outcomemap_item'));
        $plo1 = $DB->get_record('local_outcomemap_item', ['code' => 'PLO1'], '*', MUST_EXIST);
        $this->assertSame(workflow::APPROVED, $plo1->status);
        $statement = $DB->get_field_sql(
            'SELECT statement FROM {local_outcomemap_itemver} WHERE itemid = ?', [$plo1->id]);
        $this->assertSame('Analyse business problems', $statement);

        // Three alignments: 0a to PLO1, and 0b to both program outcomes.
        $this->assertSame(3, $DB->count_records('local_outcomemap_rel',
            ['type' => relation_service::ALIGNS_TO]));
    }

    /**
     * Re-importing the same file changes nothing.
     */
    public function test_reimport_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->framework('MBA-PLO');
        $this->framework('MBA601-CLO');

        $csv = self::HEADER . "\n"
            . '"Program outcome",MBA-PLO,PLO1,"Analyse business problems",,1,Approved' . "\n"
            . '"Course outcome",MBA601-CLO,0a,"Demonstrate financial literacy",MBA-PLO.PLO1,1,Approved' . "\n";

        $this->import($csv);
        $items = $DB->count_records('local_outcomemap_item');
        $relations = $DB->count_records('local_outcomemap_rel');

        $this->import($csv);
        $this->assertSame($items, $DB->count_records('local_outcomemap_item'),
            'A second import of the same file must not duplicate outcomes.');
        $this->assertSame($relations, $DB->count_records('local_outcomemap_rel'),
            'A second import of the same file must not duplicate alignments.');
    }

    /**
     * A framework the file names but the site does not hold is reported, by name.
     */
    public function test_missing_framework_is_named_in_the_preview(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');

        $csv = self::HEADER . "\n"
            . '"Program outcome",MBA-PLO,PLO1,"Analyse business problems",,1,Approved' . "\n";
        $preview = $this->preview($csv);

        $this->assertFalse($preview->valid);
        $this->assertStringContainsString('MBA-PLO', implode(' ', $preview->rows[0]->errors));
    }

    /**
     * An alignment naming an outcome that exists nowhere is reported, not dropped.
     */
    public function test_unresolvable_alignment_is_reported(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->framework('MBA601-CLO');

        $csv = self::HEADER . "\n"
            . '"Course outcome",MBA601-CLO,0a,"Demonstrate financial literacy",MBA-PLO.NOPE,1,Approved' . "\n";
        $preview = $this->preview($csv);

        $this->assertFalse($preview->valid);
        $this->assertStringContainsString('MBA-PLO.NOPE', implode(' ', $preview->rows[0]->errors));
    }

    /**
     * The same outcome twice in one file is a mistake, and is named as one.
     */
    public function test_duplicate_outcome_in_one_file_is_reported(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('requireapproval', 0, 'local_outcomemap');
        $this->framework('MBA-PLO');

        $csv = self::HEADER . "\n"
            . '"Program outcome",MBA-PLO,PLO1,"First wording",,1,Approved' . "\n"
            . '"Program outcome",MBA-PLO,PLO1,"Second wording",,1,Approved' . "\n";
        $preview = $this->preview($csv);

        $this->assertFalse($preview->valid);
        $this->assertStringContainsString('MBA-PLO.PLO1', implode(' ', $preview->rows[1]->errors));
    }

    /**
     * The header error names the columns it wanted, rather than a placeholder.
     */
    public function test_header_error_names_the_expected_columns(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $importid = foundation_import_service::load("wrong,header\n1,2\n", 'UTF-8', ',');
        try {
            foundation_import_service::preview($importid, foundation_import_service::HIERARCHY);
            $this->fail('A file with the wrong header must be refused.');
        } catch (validation_exception $e) {
            $this->assertSame('importheader', $e->errorcode);
            $this->assertStringContainsString('Maps to', $e->getMessage(),
                'The message must name the expected columns, not print a raw placeholder.');
            $this->assertStringNotContainsString('{$a}', $e->getMessage());
        } finally {
            foundation_import_service::cleanup($importid);
        }
    }
}
