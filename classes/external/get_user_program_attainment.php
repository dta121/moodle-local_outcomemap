<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_outcomemap\local\service\attainment_export_service;

/**
 * External function: one learner's released program-outcome attainment.
 *
 * The student information system renders "what your degree certifies" from
 * this — one pooled figure per program learning outcome. It is a LIVE read on
 * purpose: release is a read-time decision (module visibility, grade
 * visibility, quiz review state, lineage verification), so a synced copy
 * would keep showing figures Moodle has since begun withholding. Percentages
 * travel as canonical scale-10 decimal strings, never floats, and a state
 * accompanies every row so a consumer cannot mistake not_assessed or
 * insufficient_evidence for zero (ADR 0003, spec §4/§7.7).
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_program_attainment extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id of the learner'),
            'programcode' => new external_value(
                PARAM_TEXT,
                'Restrict to one program code; empty for every program the learner has results in',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Execute the export.
     *
     * @param int $userid Learner's Moodle user id.
     * @param string $programcode Optional program-code filter.
     * @return array
     */
    public static function execute(int $userid, string $programcode = ''): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'programcode' => $programcode,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/outcomemap:exportattainment', $systemcontext);

        // Refuse an unknown or deleted learner rather than answering an empty
        // report: to the SIS those are different facts — "no attainment yet"
        // invites rendering an empty section, "no such user" is a linkage
        // fault someone must fix.
        $DB->get_record('user', ['id' => $params['userid'], 'deleted' => 0], 'id', MUST_EXIST);

        return attainment_export_service::get_user_program_attainment(
            (int) $params['userid'],
            $params['programcode'] === '' ? null : $params['programcode']
        );
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $decimal = static fn(string $description): external_value =>
            new external_value(PARAM_RAW, $description . ' (canonical scale-10 decimal string)', VALUE_REQUIRED, null, NULL_ALLOWED);
        return new external_single_structure([
            'generatedat' => new external_value(PARAM_INT, 'Evaluation timestamp'),
            'algoversion' => new external_value(PARAM_RAW, 'Calculation algorithm version the figures carry'),
            'programs' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_RAW, 'Program code'),
                'name' => new external_value(PARAM_RAW, 'Program name'),
                'expectedpercent' => $decimal('Program-wide expected threshold, only when every outcome shares one'),
                'strongpercent' => $decimal('Program-wide strong threshold, only when every outcome shares one'),
                'outcomes' => new external_multiple_structure(new external_single_structure([
                    'itemid' => new external_value(PARAM_INT, 'Stable outcome item id'),
                    'code' => new external_value(PARAM_RAW, 'Outcome code, e.g. PLO1'),
                    'statement' => new external_value(PARAM_RAW, 'Current effective outcome statement'),
                    'shortstatement' => new external_value(PARAM_RAW, 'Short form of the statement'),
                    'state' => new external_value(PARAM_ALPHAEXT,
                        'calculated, insufficient_evidence, calculation_pending, stale, not_released, or not_assessed'),
                    'percentage' => $decimal('Pooled attainment percentage; null unless state is calculated'),
                    'coursesassessed' => new external_value(PARAM_INT, 'Catalog courses contributing released evidence'),
                    'coursestotal' => new external_value(PARAM_INT, 'Catalog courses the program currently promises'),
                    'gradeditems' => new external_value(PARAM_INT, 'Distinct graded items across contributing courses'),
                    'expectedpercent' => $decimal('Expected threshold from the judging policies\' band ladder'),
                    'strongpercent' => $decimal('Strong threshold from the judging policies\' band ladder'),
                    'timecalculated' => new external_value(PARAM_INT, 'Most recent contributing calculation time',
                        VALUE_REQUIRED, null, NULL_ALLOWED),
                ])),
            ])),
        ]);
    }
}
