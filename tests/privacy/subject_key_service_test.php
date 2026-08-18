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

namespace local_outcomemap\local\privacy;

use local_outcomemap\local\uuid;

/**
 * Subject-marker derivation must not depend on a legacy site setting.
 *
 * $CFG->passwordsaltmain is a pre-2.5 password-salting leftover that modern
 * Moodle neither sets nor uses. Markers therefore derive from a durable
 * plugin-owned secret, seeded from the legacy value only where one exists so
 * that markers already issued keep resolving.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_outcomemap\local\privacy\subject_key_service
 */
final class subject_key_service_test extends \advanced_testcase {
    /**
     * * Subject keys work on a site that has no legacy site secret at all.
     */
    public function test_references_issue_without_legacy_site_secret(): void {
        global $CFG;

        $this->resetAfterTest(true);
        unset($CFG->passwordsaltmain);

        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $snapshotuuid = uuid::generate();

        $reference = subject_key_service::reference($snapshotuuid, $userid);
        $this->assertNotEmpty($reference);
        $this->assertTrue(subject_key_service::has_record($userid));

        // The same snapshot and user must resolve to the same reference.
        $this->assertSame(
            $reference,
            subject_key_service::reference($snapshotuuid, $userid, false)
        );

        // A legacy reference is unresolvable rather than hashed with an empty key.
        $this->assertNull(subject_key_service::legacy_reference($snapshotuuid, $userid));
    }

    /**
     * * Erasure still de-links the subject when no legacy site secret exists.
     */
    public function test_forget_delinks_without_legacy_site_secret(): void {
        global $CFG;

        $this->resetAfterTest(true);
        unset($CFG->passwordsaltmain);

        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $snapshotuuid = uuid::generate();

        subject_key_service::reference($snapshotuuid, $userid);
        subject_key_service::forget($userid);

        // The marker survives so the erasure remains recorded, but the key does not.
        $this->assertTrue(subject_key_service::has_record($userid));
        $this->assertNull(subject_key_service::reference($snapshotuuid, $userid, false));

        $status = subject_key_service::export_status($userid);
        $this->assertNotNull($status);
        $this->assertFalse($status['activekey']);
        $this->assertTrue($status['legacyresolutionblocked']);
    }

    /**
     * Markers issued under the legacy secret keep resolving once it is removed.
     *
     * This is the migration guarantee: an erased marker retains no user ID, so a
     * marker that stopped matching would make an erased user look un-erased.
     */
    public function test_markers_survive_removal_of_legacy_site_secret(): void {
        global $CFG;

        $this->resetAfterTest(true);
        $CFG->passwordsaltmain = 'local_outcomemap_legacy_secret';

        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $snapshotuuid = uuid::generate();

        $reference = subject_key_service::reference($snapshotuuid, $userid);
        $this->assertTrue(subject_key_service::has_record($userid));

        // The administrator removes the legacy setting from config.php.
        unset($CFG->passwordsaltmain);

        $this->assertTrue(subject_key_service::has_record($userid));
        $this->assertSame(
            $reference,
            subject_key_service::reference($snapshotuuid, $userid, false)
        );
    }

    /**
     * * A stored plugin secret is never silently replaced on later calls.
     */
    public function test_secret_is_generated_once_and_reused(): void {
        global $CFG;

        $this->resetAfterTest(true);
        unset($CFG->passwordsaltmain);

        $user = $this->getDataGenerator()->create_user();
        subject_key_service::has_record((int) $user->id);

        $secret = get_config('local_outcomemap', 'privacysubjectsecret');
        $this->assertNotEmpty($secret);

        subject_key_service::reference(uuid::generate(), (int) $user->id);
        $this->assertSame($secret, get_config('local_outcomemap', 'privacysubjectsecret'));
    }
}
