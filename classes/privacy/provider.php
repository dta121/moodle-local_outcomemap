<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use local_outcomemap\local\privacy\user_data_service;

/**
 * Moodle Privacy API provider for outcome evidence and governance records.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe all personal data stored by the plugin.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_outcomemap_evidence', [
            'userid' => 'privacy:metadata:userid',
            'cinstid' => 'privacy:metadata:coursecontext',
            'assessmentcmid' => 'privacy:metadata:evidence:provenance',
            'quizattemptid' => 'privacy:metadata:evidence:provenance',
            'questionusageid' => 'privacy:metadata:evidence:provenance',
            'questionattemptid' => 'privacy:metadata:evidence:provenance',
            'questionversionid' => 'privacy:metadata:evidence:provenance',
            'questionid' => 'privacy:metadata:evidence:provenance',
            'rawfraction' => 'privacy:metadata:evidence:score',
            'rawmark' => 'privacy:metadata:evidence:score',
            'maxmark' => 'privacy:metadata:evidence:score',
            'weightedearned' => 'privacy:metadata:evidence:score',
            'weightedpossible' => 'privacy:metadata:evidence:score',
            'attempttime' => 'privacy:metadata:time',
            'gradingtime' => 'privacy:metadata:time',
        ], 'privacy:metadata:local_outcomemap_evidence');

        $collection->add_database_table('local_outcomemap_result', [
            'userid' => 'privacy:metadata:userid',
            'cinstid' => 'privacy:metadata:coursecontext',
            'scopetype' => 'privacy:metadata:result:scope',
            'scopeid' => 'privacy:metadata:result:scope',
            'periodcode' => 'privacy:metadata:result:scope',
            'numerator' => 'privacy:metadata:result:score',
            'denominator' => 'privacy:metadata:result:score',
            'percentage' => 'privacy:metadata:result:score',
            'distinctitems' => 'privacy:metadata:result:score',
            'state' => 'privacy:metadata:result:state',
            'timecalculated' => 'privacy:metadata:time',
        ], 'privacy:metadata:local_outcomemap_result');

        $collection->add_database_table('local_outcomemap_remed_event', [
            'userid' => 'privacy:metadata:local_outcomemap_remed_event:userid',
            'remediationid' => 'privacy:metadata:local_outcomemap_remed_event:remediationid',
            'resultid' => 'privacy:metadata:local_outcomemap_remed_event:resultid',
            'eventtype' => 'privacy:metadata:local_outcomemap_remed_event:eventtype',
            'occurredat' => 'privacy:metadata:local_outcomemap_remed_event:occurredat',
        ], 'privacy:metadata:local_outcomemap_remed_event');

        $collection->add_database_table('local_outcomemap_snapitem', [
            'subjectref' => 'privacy:metadata:snapshot:subjectref',
            'payloadjson' => 'privacy:metadata:snapshot:payload',
            'numerator' => 'privacy:metadata:result:score',
            'denominator' => 'privacy:metadata:result:score',
            'percentage' => 'privacy:metadata:result:score',
        ], 'privacy:metadata:local_outcomemap_snapitem');

        $collection->add_database_table('local_outcomemap_privkey', [
            'userid' => 'privacy:metadata:subjectkey:userid',
            'userhash' => 'privacy:metadata:subjectkey:userhash',
            'keyvalue' => 'privacy:metadata:subjectkey:keyvalue',
            'legacyerased' => 'privacy:metadata:subjectkey:legacyerased',
            'timemodified' => 'privacy:metadata:time',
        ], 'privacy:metadata:local_outcomemap_privkey');

        $collection->add_database_table('local_outcomemap_audit', [
            'actorid' => 'privacy:metadata:local_outcomemap_audit:actorid',
            'beforejson' => 'privacy:metadata:audit:payload',
            'afterjson' => 'privacy:metadata:audit:payload',
            'reason' => 'privacy:metadata:audit:reason',
            'timecreated' => 'privacy:metadata:local_outcomemap_audit:timecreated',
        ], 'privacy:metadata:local_outcomemap_audit');

        foreach (user_data_service::governance_tables() as $table => $fields) {
            $metadata = [];
            foreach ($fields as $field) {
                $metadata[$field] = 'privacy:metadata:governance:' . $field;
            }
            $collection->add_database_table($table, $metadata, 'privacy:metadata:governancerecord');
        }
        return $collection;
    }

    /**
     * Get contexts containing one user's data.
     *
     * @param int $userid Moodle user ID.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        user_data_service::add_contexts_for_user($contextlist, $userid);
        return $contextlist;
    }

    /**
     * Get users represented in one context.
     *
     * @param userlist $userlist User collector.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        user_data_service::add_users_in_context($userlist);
    }

    /**
     * Export one user's data in approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        user_data_service::export_user_data($contextlist);
    }

    /**
     * Delete all user data in one context.
     *
     * @param \context $context Approved context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        user_data_service::delete_all_in_context($context);
    }

    /**
     * Delete one user's data in approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts and user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        user_data_service::delete_for_user($contextlist);
    }

    /**
     * Delete several users' data in one approved context.
     *
     * @param approved_userlist $userlist Approved context and users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        user_data_service::delete_for_users($userlist);
    }
}
