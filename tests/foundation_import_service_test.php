<?php
// This file is part of Moodle - http://moodle.org/

namespace local_outcomemap;

use local_outcomemap\local\service\foundation_import_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\validation_exception;

/** Tests for CSV preview binding and all-or-nothing commit. */
final class foundation_import_service_test extends \advanced_testcase {
    public function test_valid_preview_requires_explicit_commit(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $csv = "uuid,code,name,description,externalid\n,MBA,Master of Business Administration,,\n,MEI,Master of Entrepreneurship and Innovation,,\n";
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
}
