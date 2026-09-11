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
 * Learning Outcome Mapping plugin component.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap;

use local_outcomemap\local\service\foundation_import_service;
use local_outcomemap\local\service\outcome_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\validation_exception;
use local_outcomemap\local\workflow;

/**
 * Tests for CSV preview binding and all-or-nothing commit.
 *
 * @covers \local_outcomemap\local\service\foundation_import_service
 */
final class foundation_import_service_test extends \advanced_testcase {
    public function test_import_size_and_row_limits_are_enforced_before_preview(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        try {
            foundation_import_service::load(
                str_repeat('x', foundation_import_service::MAX_IMPORT_BYTES + 1),
                'UTF-8',
                'comma'
            );
            $this->fail('An oversized CSV import was accepted.');
        } catch (validation_exception $e) {
            $this->assertSame('importtoolarge', $e->errorcode);
        }

        $csv = "uuid,code,name,description,externalid\n";
        for ($index = 0; $index <= foundation_import_service::MAX_IMPORT_ROWS; $index++) {
            $csv .= ',CODE' . $index . ',Name ' . $index . ",,\n";
        }
        try {
            foundation_import_service::load($csv, 'UTF-8', 'comma');
            $this->fail('A CSV import above the row limit was accepted.');
        } catch (validation_exception $e) {
            $this->assertSame('importtoomanyrows', $e->errorcode);
        }
    }

    public function test_valid_preview_requires_explicit_commit(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $csv = "uuid,code,name,description,externalid\n"
            . ",MBA,Master of Business Administration,,\n"
            . ",MEI,Master of Entrepreneurship and Innovation,,\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, foundation_import_service::PROGRAMS);
        $this->assertTrue($preview->valid);
        $this->assertCount(2, $preview->rows);
        $this->assertEquals(0, $DB->count_records('local_outcomemap_program'));

        $count = foundation_import_service::commit(
            $importid,
            foundation_import_service::PROGRAMS,
            $preview->hash,
        );
        foundation_import_service::cleanup($importid);
        $this->assertSame(2, $count);
        $this->assertEquals(2, $DB->count_records('local_outcomemap_program'));
        $this->assertEquals(1, $DB->count_records('local_outcomemap_audit', ['action' => 'import']));
    }

    public function test_stale_preview_does_not_partially_commit(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $csv = "uuid,code,name,description,externalid\n,NEW1,New one,,\n,RACE,Racing record,,\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, foundation_import_service::PROGRAMS);
        $this->assertTrue($preview->valid);

        program_service::create(['code' => 'RACE', 'name' => 'Created after preview']);
        try {
            foundation_import_service::commit(
                $importid,
                foundation_import_service::PROGRAMS,
                $preview->hash,
            );
            $this->fail('A stale preview was committed.');
        } catch (validation_exception $e) {
            $this->assertSame('duplicatecode', $e->errorcode);
        } finally {
            foundation_import_service::cleanup($importid);
        }
        $this->assertFalse($DB->record_exists('local_outcomemap_program', ['code' => 'NEW1']));
        $this->assertTrue($DB->record_exists('local_outcomemap_program', ['code' => 'RACE']));
    }

    public function test_program_import_accepts_explicit_type_and_credential(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $csv = "uuid,code,name,description,externalid,programtype,credential\n" .
            ",SP-MKT,Digital Marketing Specialization,,,specialization,certificate\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, foundation_import_service::PROGRAMS);
        $this->assertTrue($preview->valid);

        foundation_import_service::commit(
            $importid,
            foundation_import_service::PROGRAMS,
            $preview->hash,
        );
        foundation_import_service::cleanup($importid);

        $program = $DB->get_record('local_outcomemap_program', ['code' => 'SP-MKT'], '*', MUST_EXIST);
        $this->assertSame(program_service::TYPE_SPECIALIZATION, $program->programtype);
        $this->assertSame(program_service::CREDENTIAL_CERTIFICATE, $program->credential);
    }

    public function test_invalid_row_blocks_entire_import(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $csv = "uuid,code,name,description,externalid\n,VALID,Valid program,,\n,,Missing code,,\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, foundation_import_service::PROGRAMS);
        $this->assertFalse($preview->valid);
        try {
            foundation_import_service::commit(
                $importid,
                foundation_import_service::PROGRAMS,
                $preview->hash,
            );
            $this->fail('An invalid import was committed.');
        } catch (validation_exception $e) {
            $this->assertSame('importerrors', $e->errorcode);
        } finally {
            foundation_import_service::cleanup($importid);
        }
        $this->assertEquals(0, $DB->count_records('local_outcomemap_program'));
    }
    /**
     * Reference columns resolve codes as well as UUIDs, and refuse an ambiguous code.
     */
    public function test_reference_columns_accept_codes(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $programid = program_service::create(['code' => 'MBA', 'name' => 'Master of Business Administration']);
        $courseid = catalog_course_service::create(['code' => 'MBA601', 'name' => 'Financial Management']);

        // Frameworks owned by code rather than UUID.
        $csv = "uuid,code,name,description,ownertype,owneruuid\n"
            . ",MBA-PLO,Program outcomes,,program,MBA\n"
            . ",MBA601-CLO,Course outcomes,,catalog_course,MBA601\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, foundation_import_service::FRAMEWORKS);
        $this->assertTrue($preview->valid, json_encode(array_map(static fn($r) => $r->errors, $preview->rows)));
        $this->assertSame(2, foundation_import_service::commit(
            $importid,
            foundation_import_service::FRAMEWORKS,
            $preview->hash
        ));
        $plo = $DB->get_record('local_outcomemap_fw', ['code' => 'MBA-PLO'], '*', MUST_EXIST);
        $this->assertSame('program', $plo->ownertype);
        $this->assertSame($programid, (int) $plo->ownerid);
        $clo = $DB->get_record('local_outcomemap_fw', ['code' => 'MBA601-CLO'], '*', MUST_EXIST);
        $this->assertSame($courseid, (int) $clo->ownerid);

        // Memberships by code, mixed with a UUID on the same row.
        $courseuuid = $DB->get_field('local_outcomemap_course', 'uuid', ['id' => $courseid]);
        $csv = "uuid,programuuid,courseuuid,effectivefrom,effectiveto\n"
            . ",MBA,{$courseuuid},2026-01-01,\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, foundation_import_service::PROGRAM_COURSES);
        $this->assertTrue($preview->valid);
        foundation_import_service::commit($importid, foundation_import_service::PROGRAM_COURSES, $preview->hash);
        $this->assertTrue($DB->record_exists('local_outcomemap_progcourse', [
            'programid' => $programid,
            'courseid' => $courseid,
        ]));

        // Relations by FRAMEWORK.CODE label.
        foreach (['PLO1', 'PLO2'] as $code) {
            outcome_service::create([
                'frameworkid' => (int) $plo->id,
                'code' => $code,
                'statement' => 'Outcome ' . $code,
                'effectivefrom' => 1704067200,
            ]);
        }
        $csv = "relationuuid,sourceuuid,targetuuid,type,weight,effectivefrom,effectiveto,notes\n"
            . ",MBA-PLO.PLO2,MBA-PLO.PLO1,aligns_to,,2026-01-01,,\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, foundation_import_service::RELATIONS);
        $this->assertTrue($preview->valid, json_encode(array_map(static fn($r) => $r->errors, $preview->rows)));
        foundation_import_service::commit($importid, foundation_import_service::RELATIONS, $preview->hash);
        $this->assertSame(1, $DB->count_records('local_outcomemap_rel', ['type' => 'aligns_to']));

        // A framework code reused under another owner is ambiguous, so the row is refused.
        framework_service::create([
            'code' => 'MBA601-CLO',
            'name' => 'Same code, program owner',
            'ownertype' => framework_service::OWNER_PROGRAM,
            'ownerid' => $programid,
        ]);
        $csv = "uuid,versionuuid,frameworkuuid,code,statement,shortstatement,bloomlevel,effectivefrom,effectiveto,changereason\n"
            . ",,MBA601-CLO,0a,Demonstrate financial literacy,,,2026-01-01,,\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, foundation_import_service::OUTCOMES);
        $this->assertFalse($preview->valid);
        $this->assertStringContainsString('more than one', implode(' ', $preview->rows[0]->errors));

        // An unknown code names itself in the error.
        $csv = "uuid,code,name,description,ownertype,owneruuid\n"
            . ",MBA602-CLO,Course outcomes,,catalog_course,MBA602\n";
        $importid = foundation_import_service::load($csv, 'UTF-8', 'comma');
        $preview = foundation_import_service::preview($importid, foundation_import_service::FRAMEWORKS);
        $this->assertFalse($preview->valid);
        $this->assertStringContainsString('catalog_course', implode(' ', $preview->rows[0]->errors));
    }
}
