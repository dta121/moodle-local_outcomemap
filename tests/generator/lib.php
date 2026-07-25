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

defined('MOODLE_INTERNAL') || die();

use local_outcomemap\local\service\content_mapping_service;
use local_outcomemap\local\service\framework_service;
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
}
