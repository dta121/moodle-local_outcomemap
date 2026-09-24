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

use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\canonical_json;
use local_outcomemap\local\service\calculation_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\uuid;
use local_outcomemap\local\workflow;

/**
 * Test data generator for governed outcome definitions and mappings.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_outcomemap_generator extends component_generator_base {
    /**
     * Create approved institution-owned outcome versions.
     *
     * @param string[] $codes Outcome codes.
     * @return int[] Outcome-version IDs keyed by code.
     */
    public function create_approved_outcomes(array $codes): array {
        global $DB;
        $now = time();
        $framework = $DB->get_record('local_outcomemap_fw', ['code' => 'QB-BEHAT']);
        if (!$framework) {
            $frameworkid = $DB->insert_record('local_outcomemap_fw', (object) [
                'uuid' => uuid::generate(),
                'code' => 'QB-BEHAT',
                'name' => 'Question bank Behat outcomes',
                'description' => null,
                'ownertype' => framework_service::OWNER_INSTITUTION,
                'ownerid' => null,
                'status' => workflow::APPROVED,
                'createdby' => null,
                'modifiedby' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        } else {
            $frameworkid = (int) $framework->id;
        }

        $result = [];
        foreach ($codes as $code) {
            $code = trim(clean_param($code, PARAM_TEXT));
            if ($code === '') {
                continue;
            }
            $item = $DB->get_record('local_outcomemap_item', [
                'frameworkid' => $frameworkid,
                'code' => $code,
            ]);
            if (!$item) {
                $itemid = $DB->insert_record('local_outcomemap_item', (object) [
                    'uuid' => uuid::generate(),
                    'frameworkid' => $frameworkid,
                    'code' => $code,
                    'status' => workflow::APPROVED,
                    'createdby' => null,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            } else {
                $itemid = (int) $item->id;
            }
            $itemversion = $DB->get_record('local_outcomemap_itemver', [
                'itemid' => $itemid,
                'version' => 1,
            ]);
            if (!$itemversion) {
                $itemverid = $DB->insert_record('local_outcomemap_itemver', (object) [
                    'uuid' => uuid::generate(),
                    'itemid' => $itemid,
                    'version' => 1,
                    'statement' => 'Demonstrate governed outcome ' . $code . '.',
                    'shortstatement' => 'Governed outcome ' . $code,
                    'bloomlevel' => null,
                    'status' => workflow::APPROVED,
                    'effectivefrom' => $now - DAYSECS,
                    'effectiveto' => null,
                    'changereason' => null,
                    'createdby' => null,
                    'approvedby' => null,
                    'timecreated' => $now,
                    'timemodified' => $now,
                    'approvedat' => $now,
                ]);
            } else {
                $itemverid = (int) $itemversion->id;
            }
            $result[$code] = $itemverid;
        }
        return $result;
    }

    /**
     * Create an approved exact-version question mapping for a browser fixture.
     *
     * @param int $questionversionid Core question-version ID.
     * @param int $questionid Core question ID.
     * @param string $outcomecode Existing outcome code.
     * @param string $role Canonical mapping role.
     * @return int Mapping ID.
     */
    public function create_approved_question_mapping(
        int $questionversionid,
        int $questionid,
        string $outcomecode,
        string $role
    ): int {
        global $DB;
        if (!in_array($role, content_mapping_service::ROLES, true)) {
            throw new invalid_parameter_exception('Unknown mapping role: ' . $role);
        }
        $itemverid = (int) $DB->get_field_sql(
            'SELECT v.id
               FROM {local_outcomemap_itemver} v
               JOIN {local_outcomemap_item} i ON i.id = v.itemid
              WHERE i.code = :code AND v.status = :status',
            ['code' => $outcomecode, 'status' => workflow::APPROVED],
            MUST_EXIST
        );
        $now = time();
        $adminid = (int) get_admin()->id;
        return $DB->insert_record('local_outcomemap_qmap', (object) [
            'mappinguuid' => uuid::generate(),
            'version' => 1,
            'questionversionid' => $questionversionid,
            'questionid' => $questionid,
            'sourceqmapid' => null,
            'sourcequestionversionid' => null,
            'itemverid' => $itemverid,
            'role' => $role,
            'weight' => $role === content_mapping_service::ROLE_ASSESSES ? '1.0000000000' : null,
            'notes' => 'Approved browser-test source mapping.',
            'status' => workflow::APPROVED,
            'effectivefrom' => $now - DAYSECS,
            'effectiveto' => null,
            'createdby' => $adminid,
            'approvedby' => $adminid,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
    }

    /**
     * The core data generator, which a component generator is not given directly.
     *
     * @return \testing_data_generator
     */
    private function datagenerator(): \testing_data_generator {
        return \testing_util::get_data_generator();
    }

    /**
     * A program with one course, one outcome, and released results for N learners.
     *
     * Exists because a consumer cannot exercise the attainment path without one,
     * and building it by hand means reimplementing the plugin's schema in someone
     * else's repository. Every table here is one this plugin owns, so when the
     * schema moves this method moves with it and the consumers do not.
     *
     * The shape is the minimum that produces a program-tier row: an approved
     * program, a catalog course with an approved and confirmed instance bound to a
     * real Moodle course, an approved currently-effective membership, a
     * program-owned framework with one approved outcome version, an attempt
     * selection policy, a calculation policy, an approved question mapping, and
     * per learner one piece of graded evidence and the result it calculates to.
     *
     * WHAT IT DOES NOT PRODUCE, on purpose. The results arrive as not_released and
     * therefore carry no percentage. Releasing them needs more than an approved
     * release policy: the release gate fails closed unless every stored lineage
     * uuid resolves to current evidence whose quiz attempt and question usage
     * exist, and this fixture synthesises identifiers rather than real quiz
     * attempts. A consumer test should assert on the STATE rather than on a
     * number, which is also the case consumers get wrong: on one production site
     * 525 of 546 rows have no figure, and rendering those as zero tells almost
     * every learner they failed an outcome nobody has measured.
     *
     * @param int $learners How many enrolled learners to seed, at least one.
     * @param string $suffix Appended to the program code, so one test can build two.
     * @return array learnerids, programcode, programid, periodcode, courseid, outcomeversionid.
     */
    public function create_program_attainment(int $learners = 2, string $suffix = ''): array {
        global $CFG, $DB;

        if (empty($CFG->passwordsaltmain)) {
            $CFG->passwordsaltmain = 'local_outcomemap_phpunit_example_secret';
        }
        $now = time();
        $effectivefrom = $now - DAYSECS;
        // Synthetic identifiers have to differ between two fixtures in one test, or
        // the second one's evidence resolves against the first one's.
        $offset = $suffix === '' ? 0 : (abs(crc32($suffix)) % 1000) * 100;
        $course = $this->datagenerator()->create_course([
            'shortname' => 'SEED-COURSE' . $suffix,
            'fullname' => 'Seeded example course' . $suffix,
        ]);

        $programid = (int) $DB->insert_record('local_outcomemap_program', (object) [
            'uuid' => uuid::generate(),
            'code' => $suffix === '' ? 'SEED-PROGRAM' : 'SEED-PROGRAM' . $suffix,
            'name' => 'Seeded example program',
            'description' => null,
            'externalid' => null,
            'programtype' => program_service::TYPE_SPECIALIZATION,
            'credential' => program_service::CREDENTIAL_CERTIFICATE,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $catalogcourseid = (int) $DB->insert_record('local_outcomemap_course', (object) [
            'uuid' => uuid::generate(),
            'code' => 'SEED-CATALOG' . $suffix,
            'name' => 'Seeded catalog course',
            'description' => null,
            'siskey' => null,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $courseinstanceid = (int) $DB->insert_record('local_outcomemap_cinst', (object) [
            'uuid' => uuid::generate(),
            'courseid' => $catalogcourseid,
            'moodlecourseid' => $course->id,
            'periodcode' => 'SEED-T1' . $suffix,
            'externalid' => null,
            'status' => workflow::APPROVED,
            'confirmed' => 1,
            'confirmedby' => null,
            'confirmedat' => $now,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_outcomemap_progcourse', (object) [
            'uuid' => uuid::generate(),
            'programid' => $programid,
            'courseid' => $catalogcourseid,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
        $frameworkid = (int) $DB->insert_record('local_outcomemap_fw', (object) [
            'uuid' => uuid::generate(),
            'code' => 'SEED-PLO' . $suffix,
            'name' => 'Seeded program outcomes',
            'description' => null,
            'ownertype' => framework_service::OWNER_PROGRAM,
            'ownerid' => $programid,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'modifiedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $outcomeid = (int) $DB->insert_record('local_outcomemap_item', (object) [
            'uuid' => uuid::generate(),
            'frameworkid' => $frameworkid,
            'code' => 'PLO1' . $suffix,
            'status' => workflow::APPROVED,
            'createdby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $outcomeversionid = (int) $DB->insert_record('local_outcomemap_itemver', (object) [
            'uuid' => uuid::generate(),
            'itemid' => $outcomeid,
            'version' => 1,
            'statement' => 'Demonstrate the seeded program outcome.',
            'shortstatement' => 'Demonstrate the seeded outcome.',
            'bloomlevel' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'changereason' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        $selectionpolicyid = $this->insert_policy(
            policy_service::TYPE_ATTEMPT_SELECTION,
            ['method' => policy_service::METHOD_LATEST_COMPLETED],
            $effectivefrom
        );
        $calculationpolicyid = $this->insert_policy(
            policy_service::TYPE_CALCULATION,
            [
                'minitems' => 1,
                'minweightedpossible' => '0.0000000000',
                'requiremanualgrading' => true,
                'displayscale' => 1,
            ],
            $effectivefrom
        );
        $mappingid = (int) $DB->insert_record('local_outcomemap_qmap', (object) [
            'mappinguuid' => uuid::generate(),
            'version' => 1,
            'questionversionid' => 910001 + $offset,
            'questionid' => 900001 + $offset,
            'itemverid' => $outcomeversionid,
            'role' => 'assesses',
            'weight' => '1.0000000000',
            'notes' => null,
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);

        $learnerids = [];
        foreach (range(0, max(1, $learners) - 1) as $index) {
            $learner = $this->datagenerator()->create_user();
            $learnerids[] = (int) $learner->id;
            $this->datagenerator()->enrol_user($learner->id, $course->id);
            $evidenceuuid = uuid::generate();
            $DB->insert_record('local_outcomemap_evidence', (object) [
                'uuid' => $evidenceuuid,
                'lineageuuid' => uuid::generate(),
                'dedupekey' => hash('sha256', 'seed-evidence-' . $suffix . '-' . $index),
                'sourceevidenceid' => null,
                'relationpathjson' => canonical_json::encode([]),
                'cinstid' => $courseinstanceid,
                'userid' => $learner->id,
                'assessmentcmid' => 810001 + $offset,
                'quizattemptid' => 820001 + $offset + $index,
                'questionusageid' => 830001 + $offset + $index,
                'slot' => 1,
                'questionattemptid' => 840001 + $offset + $index,
                'questionversionid' => 910001 + $offset,
                'questionid' => 900001 + $offset,
                'itemverid' => $outcomeversionid,
                'mappingid' => $mappingid,
                'policyid' => $selectionpolicyid,
                'evidencetype' => calculation_service::TYPE_DIRECT,
                'rawfraction' => '0.8500000000',
                'rawmark' => '12.7500000000',
                'maxmark' => '15.0000000000',
                'mappingweight' => '1.0000000000',
                'relationweight' => '1.0000000000',
                'weightedearned' => '12.7500000000',
                'weightedpossible' => '15.0000000000',
                'gradingstate' => calculation_service::GRADING_GRADED,
                'attempttime' => $now - 100,
                'gradingtime' => $now - 50,
                'supersededby' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $lineagejson = canonical_json::encode([['uuid' => $evidenceuuid]]);
            $DB->insert_record('local_outcomemap_result', (object) [
                'uuid' => uuid::generate(),
                'resultkey' => hash('sha256', 'seed-result-' . $suffix . '-' . $index),
                'version' => 1,
                'cinstid' => $courseinstanceid,
                'userid' => $learner->id,
                'scopetype' => calculation_service::SCOPE_COURSE,
                'scopeid' => $courseinstanceid,
                'periodcode' => 'SEED-T1' . $suffix,
                'itemverid' => $outcomeversionid,
                'policyid' => $calculationpolicyid,
                'numerator' => '12.7500000000',
                'denominator' => '15.0000000000',
                'percentage' => '85.0000000000',
                'distinctitems' => 1,
                'bandid' => null,
                'state' => calculation_service::STATE_CALCULATED,
                'stale' => 0,
                'algoversion' => calculation_service::ALGO_VERSION,
                'inputhash' => hash('sha256', 'seed-input-' . $suffix . '-' . $index),
                'lineagejson' => $lineagejson,
                'lineagehash' => hash('sha256', $lineagejson),
                'supersededby' => null,
                'timecalculated' => $now,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        return [
            'learnerids' => $learnerids,
            'programcode' => $suffix === '' ? 'SEED-PROGRAM' : 'SEED-PROGRAM' . $suffix,
            'programid' => $programid,
            'periodcode' => 'SEED-T1' . $suffix,
            'courseid' => (int) $course->id,
            'outcomeversionid' => $outcomeversionid,
        ];
    }

    /**
     * Insert an approved institution-scope policy version.
     *
     * @param string $policytype Policy type.
     * @param array $config Typed policy configuration.
     * @param int $effectivefrom Effective start.
     * @return int Policy version ID.
     */
    private function insert_policy(string $policytype, array $config, int $effectivefrom): int {
        global $DB;

        $configjson = canonical_json::encode($config);
        $now = time();
        return (int) $DB->insert_record('local_outcomemap_policy', (object) [
            'policyuuid' => uuid::generate(),
            'version' => 1,
            'policytype' => $policytype,
            'scopetype' => policy_service::SCOPE_INSTITUTION,
            'scopeid' => null,
            'name' => 'Seed ' . $policytype,
            'configjson' => $configjson,
            'confighash' => hash('sha256', $configjson),
            'status' => workflow::APPROVED,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => null,
            'createdby' => null,
            'approvedby' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'approvedat' => $now,
        ]);
    }
}
