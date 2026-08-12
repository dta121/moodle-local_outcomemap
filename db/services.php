<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Web service definitions for local_outcomemap.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    // One learner's released program-outcome attainment, pooled per outcome
    // across courses. This is the ONLY function the plugin exposes, and it is
    // a read: outcome governance (definitions, mappings, policies, approvals)
    // stays inside Moodle where the workflow capabilities live.
    'local_outcomemap_get_user_program_attainment' => [
        'classname'    => 'local_outcomemap\external\get_user_program_attainment',
        'description'  => 'One learner\'s released program-outcome attainment, pooled per outcome (SIS "what your degree certifies").',
        'type'         => 'read',
        // Server-to-server only. Page JavaScript has the learner's own report
        // page; an AJAX-exposed any-user read would be reachable with any
        // logged-in session cookie.
        'ajax'         => false,
        'capabilities' => 'local/outcomemap:exportattainment',
    ],
];

$services = [
    // Mirrors the Completion History SIS service posture: locked to
    // explicitly authorised users and disabled until an administrator turns
    // it on. A separate service (and therefore a separate token) from
    // completionhistory_sis, because a token authorises one service and this
    // plugin's data is governed under its own capabilities.
    'Outcome Map SIS' => [
        'functions'       => [
            'local_outcomemap_get_user_program_attainment',
        ],
        'restrictedusers' => 1,
        'enabled'         => 0,
        'shortname'       => 'outcomemap_sis',
    ],
];
