<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\privacy;

use local_outcomemap\local\uuid;
use local_outcomemap\local\validation_exception;

/**
 * Manages erasable per-user keys for immutable snapshot subject references.
 *
 * Active records retain a nullable indexed Moodle user ID solely so Moodle's
 * Privacy API can discover represented users without scanning the site user
 * table. Erasure clears that ID and key material while retaining a site-keyed
 * hash marker, irreversibly de-linking all snapshots created with method v2
 * and preventing legacy site-secret references from being resolved. A later
 * snapshot may issue a fresh key, but it cannot recover references created
 * with a forgotten key.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class subject_key_service {
    /** Pseudonymous key table. */
    private const TABLE = 'local_outcomemap_privkey';

    /** Namespaced marker that can never be a positive integer user marker. */
    private const GLOBAL_LEGACY_ERASURE_MARKER = 'global-legacy-erasure';

    /** Plugin config name holding the durable marker secret. */
    private const SECRET_CONFIG_NAME = 'privacysubjectsecret';

    /**
     * Return a v2 snapshot reference, optionally creating fresh key material.
     *
     * @param string $snapshotuuid Snapshot UUID.
     * @param int $userid Moodle user ID.
     * @param bool $create Whether missing/forgotten key material may be created.
     * @return string|null Subject reference, or null when no active key exists.
     */
    public static function reference(string $snapshotuuid, int $userid, bool $create = true): ?string {
        global $DB;

        $snapshotuuid = uuid::normalize($snapshotuuid);
        $userhash = self::user_hash($userid);
        $record = $DB->get_record(self::TABLE, ['userhash' => $userhash]);
        if (!$record && !$create) {
            return null;
        }
        if (!$record) {
            $now = time();
            $record = (object) [
                'userid' => $userid,
                'userhash' => $userhash,
                'keyvalue' => bin2hex(random_bytes(32)),
                'legacyerased' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record(self::TABLE, $record);
        } else if ($record->keyvalue === null || $record->keyvalue === '') {
            if (!$create) {
                return null;
            }
            $record->userid = $userid;
            $record->keyvalue = bin2hex(random_bytes(32));
            $record->timemodified = time();
            $DB->update_record(self::TABLE, $record);
        } else if ($create && $record->userid === null) {
            $record->userid = $userid;
            $record->timemodified = time();
            $DB->update_record(self::TABLE, $record);
        }
        return hash_hmac('sha256', $snapshotuuid, (string) $record->keyvalue);
    }

    /**
     * Resolve a legacy site-secret reference unless privacy erasure blocks it.
     *
     * @param string $snapshotuuid Snapshot UUID.
     * @param int $userid Moodle user ID.
     * @return string|null Legacy subject reference, or null after erasure.
     */
    public static function legacy_reference(string $snapshotuuid, int $userid): ?string {
        $references = self::references_for_lookup([], [0 => $snapshotuuid], $userid);
        return $references[0] ?? null;
    }

    /**
     * Resolve active and legacy references with one key/marker query.
     *
     * Input arrays are keyed by snapshot ID and values are snapshot UUIDs. The
     * returned flat array preserves those keys and omits references that have
     * been erased or use unavailable key material.
     *
     * @param array<int,string> $active V2 snapshot UUIDs keyed by snapshot ID.
     * @param array<int,string> $legacy Legacy snapshot UUIDs keyed by snapshot ID.
     * @param int $userid Moodle user ID.
     * @return array<int,string> Resolvable subject references keyed by snapshot ID.
     */
    public static function references_for_lookup(array $active, array $legacy, int $userid): array {
        global $CFG;

        if (!$active && !$legacy) {
            return [];
        }
        [$userrecord, $globalrecord] = self::lookup_records($userid);
        $references = [];

        if ($userrecord && $userrecord->keyvalue !== null && $userrecord->keyvalue !== '') {
            foreach ($active as $snapshotid => $snapshotuuid) {
                $references[(int) $snapshotid] = hash_hmac(
                    'sha256',
                    uuid::normalize($snapshotuuid),
                    (string) $userrecord->keyvalue
                );
            }
        }

        $legacyblocked = ($userrecord && (int) $userrecord->legacyerased === 1)
            || ($globalrecord && (int) $globalrecord->legacyerased === 1);
        // Legacy references reproduce hashes frozen under the legacy site
        // secret, so they are only resolvable while that secret still exists.
        // Without it they are unresolvable, never hashed with an empty key.
        if (!$legacyblocked && !empty($CFG->passwordsaltmain)) {
            foreach ($legacy as $snapshotid => $snapshotuuid) {
                $references[(int) $snapshotid] = hash_hmac(
                    'sha256',
                    uuid::normalize($snapshotuuid) . ':' . $userid,
                    (string) $CFG->passwordsaltmain
                );
            }
        }
        return $references;
    }

    /**
     * Forget one user's active key and permanently block legacy resolution.
     *
     * @param int $userid Moodle user ID.
     * @return void
     */
    public static function forget(int $userid): void {
        global $DB;

        $userhash = self::user_hash($userid);
        $record = $DB->get_record(self::TABLE, ['userhash' => $userhash]);
        $now = time();
        if (!$record) {
            $DB->insert_record(self::TABLE, (object) [
                'userid' => null,
                'userhash' => $userhash,
                'keyvalue' => null,
                'legacyerased' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            return;
        }
        $record->userid = null;
        $record->keyvalue = null;
        $record->legacyerased = 1;
        $record->timemodified = $now;
        $DB->update_record(self::TABLE, $record);
    }

    /**
     * Forget all issued keys and block every legacy reference globally.
     *
     * Legacy subject hashes cannot enumerate the users they represent, so a
     * persistent global marker is required for a system-context delete-all.
     * Frozen snapshot rows and their canonical hashes remain unchanged.
     *
     * @return void
     */
    public static function forget_all(): void {
        global $DB;

        $now = time();
        $DB->set_field_select(self::TABLE, 'userid', null, '1 = 1', []);
        $DB->set_field_select(self::TABLE, 'keyvalue', null, '1 = 1', []);
        $DB->set_field_select(self::TABLE, 'legacyerased', 1, '1 = 1', []);
        $DB->set_field_select(self::TABLE, 'timemodified', $now, '1 = 1', []);

        $markerhash = self::global_marker_hash();
        if (!$DB->record_exists(self::TABLE, ['userhash' => $markerhash])) {
            $DB->insert_record(self::TABLE, (object) [
                'userid' => null,
                'userhash' => $markerhash,
                'keyvalue' => null,
                'legacyerased' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Return non-secret linkage status for privacy export.
     *
     * @param int $userid Moodle user ID.
     * @return array|null Status, or null when no key/erasure record exists.
     */
    public static function export_status(int $userid): ?array {
        [$userrecord, $globalrecord] = self::lookup_records($userid);
        if (!$userrecord && !$globalrecord) {
            return null;
        }
        $timerecord = $userrecord ?? $globalrecord;
        return [
            'activekey' => $userrecord
                && $userrecord->keyvalue !== null
                && $userrecord->keyvalue !== '',
            'legacyresolutionblocked' => (bool) (
                ($userrecord && (int) $userrecord->legacyerased === 1)
                || ($globalrecord && (int) $globalrecord->legacyerased === 1)
            ),
            'timecreated' => (int) $timerecord->timecreated,
            'timemodified' => (int) $timerecord->timemodified,
        ];
    }

    /**
     * Add users with active, discoverable key records to a system user list.
     *
     * Erased records deliberately have a null user ID and remain only as
     * irreversible hash markers, so discovery is a single indexed query and
     * never scans Moodle's user table.
     *
     * @param \core_privacy\local\request\userlist $userlist User collector.
     * @return void
     */
    public static function add_users(\core_privacy\local\request\userlist $userlist): void {
        $userlist->add_from_sql(
            'userid',
            'SELECT userid
               FROM {local_outcomemap_privkey}
              WHERE userid IS NOT NULL',
            []
        );
    }

    /**
     * Return whether a per-user key/erasure marker exists.
     *
     * @param int $userid Moodle user ID.
     * @return bool
     */
    public static function has_record(int $userid): bool {
        global $DB;

        return $DB->record_exists(self::TABLE, ['userhash' => self::user_hash($userid)]);
    }

    /**
     * Load one user's marker and the site-wide legacy-erasure marker together.
     *
     * @param int $userid Moodle user ID.
     * @return array{0:\stdClass|null,1:\stdClass|null} User and global records.
     */
    private static function lookup_records(int $userid): array {
        global $DB;

        $userhash = self::user_hash($userid);
        $globalhash = self::global_marker_hash();
        $records = $DB->get_records_list(
            self::TABLE,
            'userhash',
            [$userhash, $globalhash],
            '',
            'id,userhash,keyvalue,legacyerased,timecreated,timemodified'
        );
        $byhash = [];
        foreach ($records as $record) {
            $byhash[(string) $record->userhash] = $record;
        }
        return [$byhash[$userhash] ?? null, $byhash[$globalhash] ?? null];
    }

    /**
     * Build the site-keyed user marker without storing the raw ID.
     *
     * @param int $userid Moodle user ID.
     * @return string
     */
    private static function user_hash(int $userid): string {
        if ($userid < 1) {
            throw new validation_exception('invalidfield', 'userid', $userid);
        }
        return hash_hmac(
            'sha256',
            'local_outcomemap:privacy-subject:' . $userid,
            self::site_secret()
        );
    }

    /**
     * Build the reserved site-keyed marker for system-wide legacy erasure.
     *
     * @return string
     */
    private static function global_marker_hash(): string {
        return hash_hmac(
            'sha256',
            'local_outcomemap:privacy-subject:' . self::GLOBAL_LEGACY_ERASURE_MARKER,
            self::site_secret()
        );
    }

    /**
     * Return the durable plugin-owned secret used to derive subject markers.
     *
     * Markers must stay stable for the life of the site. An erased record keeps
     * only its hash marker, so a changed secret would orphan those markers and
     * make already-erased users appear un-erased. The secret is therefore
     * generated once and stored in plugin configuration rather than read from
     * site configuration on each call.
     *
     * Sites that issued markers under the legacy `$CFG->passwordsaltmain` seed
     * from that value, which preserves every existing marker byte-for-byte.
     * That setting is a pre-2.5 password-salting leftover that modern Moodle
     * neither sets nor uses, so sites without it get fresh random key material
     * instead of failing.
     *
     * @return string
     */
    private static function site_secret(): string {
        global $CFG;

        $secret = get_config('local_outcomemap', self::SECRET_CONFIG_NAME);
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        // Serialize generation so concurrent requests cannot store rival
        // secrets, which would orphan any marker written by the loser.
        $factory = \core\lock\lock_config::get_lock_factory('local_outcomemap');
        $lock = $factory->get_lock('privacy_subject_secret', 10);
        if (!$lock) {
            throw new validation_exception('privacysecretunavailable', 'userid', 'lock timeout');
        }
        try {
            $secret = get_config('local_outcomemap', self::SECRET_CONFIG_NAME);
            if (!is_string($secret) || $secret === '') {
                $secret = !empty($CFG->passwordsaltmain)
                    ? (string) $CFG->passwordsaltmain
                    : bin2hex(random_bytes(32));
                set_config(self::SECRET_CONFIG_NAME, $secret, 'local_outcomemap');
            }
        } finally {
            $lock->release();
        }
        return $secret;
    }
}
