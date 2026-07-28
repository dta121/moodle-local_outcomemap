<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\canonical_json;
use local_outcomemap\local\validation_exception;

/**
 * Verifies and exposes complete result and snapshot audit lineage.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class audit_lineage_service {
    /**
     * Verify all snapshot item hashes and the ordered payload hash.
     *
     * @param \stdClass $snapshot Snapshot record.
     * @param \stdClass[] $items Snapshot items in sort order.
     * @return string Reconstructed payload hash.
     */
    public static function verify_snapshot_payload(\stdClass $snapshot, array $items): string {
        $hashes = [];
        foreach ($items as $item) {
            $expected = hash('sha256', (string) $item->payloadjson);
            if (!hash_equals((string) $item->payloadhash, $expected)) {
                throw new validation_exception('snapshotintegrityfailure', 'snapitem', $item->id);
            }
            $decoded = json_decode($item->payloadjson, true);
            if (!is_array($decoded) || canonical_json::encode($decoded) !== $item->payloadjson
                    || ($decoded['type'] ?? null) !== (string) $item->itemtype
                    || !is_array($decoded['identity'] ?? null)
                    || !is_array($decoded['index'] ?? null)) {
                throw new validation_exception('snapshotintegrityfailure', 'snapitem', $item->id);
            }
            $stablekey = hash('sha256', canonical_json::encode([
                'type' => (string) $item->itemtype,
                'identity' => $decoded['identity'],
            ]));
            if (!hash_equals((string) $item->stablekey, $stablekey)) {
                throw new validation_exception('snapshotintegrityfailure', 'stablekey', $item->id);
            }
            // Rebuilt through the same normalizer that produced it, so a new
            // indexed column cannot make the stored index and the recomputed
            // one disagree.
            $expected = snapshot_service::build_index((array) $item);
            $stored = $decoded['index'];
            // A snapshot frozen before an indexed column existed carries an
            // index without that key, and a frozen snapshot is never rewritten.
            // Comparing on the keys it actually holds keeps those verifiable.
            // This cannot hide a tampered column: payloadjson is hash-checked
            // above, so its key set is itself protected, and any stored key the
            // normalizer does not produce is rejected below.
            $comparable = array_intersect_key($expected, $stored);
            $storedkeys = array_keys($stored);
            $comparablekeys = array_keys($comparable);
            sort($storedkeys);
            sort($comparablekeys);
            if ($storedkeys !== $comparablekeys
                    || canonical_json::encode($comparable) !== canonical_json::encode($stored)) {
                throw new validation_exception('snapshotintegrityfailure', 'index', $item->id);
            }
            $hashes[] = [
                'key' => (string) $item->stablekey,
                'hash' => (string) $item->payloadhash,
            ];
        }
        $payloadhash = hash('sha256', canonical_json::encode($hashes));
        if (!hash_equals((string) $snapshot->payloadhash, $payloadhash)) {
            throw new validation_exception('snapshotintegrityfailure', 'snapshot', $snapshot->id);
        }
        return $payloadhash;
    }

    /**
     * Build the canonical final manifest body used for hashing and export.
     *
     * @param \stdClass $snapshot Snapshot record.
     * @param int $itemcount Number of snapshot items.
     * @return array Canonical manifest data without its own hash.
     */
    public static function manifest(\stdClass $snapshot, int $itemcount): array {
        return [
            'snapshotuuid' => (string) $snapshot->snapshotuuid,
            'version' => (int) $snapshot->version,
            'previousid' => $snapshot->previousid === null ? null : (int) $snapshot->previousid,
            'programid' => (int) $snapshot->programid,
            'periodcode' => (string) $snapshot->periodcode,
            'cohortid' => $snapshot->cohortid === null ? null : (int) $snapshot->cohortid,
            'policyid' => (int) $snapshot->policyid,
            'status' => (string) $snapshot->status,
            'notes' => $snapshot->notes === null ? null : (string) $snapshot->notes,
            'correctionreason' => $snapshot->correctionreason === null
                ? null : (string) $snapshot->correctionreason,
            'populationsource' => (string) $snapshot->populationsource,
            'retentionbasis' => (string) $snapshot->retentionbasis,
            'populationat' => (int) $snapshot->populationat,
            'populationcount' => (int) $snapshot->populationcount,
            'suppressionthreshold' => (int) $snapshot->suppressionthreshold,
            'subjecthashmethod' => (string) $snapshot->subjecthashmethod,
            'pluginversion' => (string) $snapshot->pluginversion,
            'algoversion' => (string) $snapshot->algoversion,
            'payloadhash' => (string) $snapshot->payloadhash,
            'itemcount' => $itemcount,
            'createdby' => (int) $snapshot->createdby,
            'approvedby' => $snapshot->approvedby === null ? null : (int) $snapshot->approvedby,
            'timecreated' => (int) $snapshot->timecreated,
            'timemodified' => (int) $snapshot->timemodified,
            'approvedat' => $snapshot->approvedat === null ? null : (int) $snapshot->approvedat,
        ];
    }

    /**
     * Verify the final manifest hash of a frozen snapshot.
     *
     * @param \stdClass $snapshot Snapshot record.
     * @param int $itemcount Number of items.
     * @return string Reconstructed hash.
     */
    public static function verify_manifest(\stdClass $snapshot, int $itemcount): string {
        $hash = hash('sha256', canonical_json::encode(self::manifest($snapshot, $itemcount)));
        if (!hash_equals((string) $snapshot->manifesthash, $hash)) {
            throw new validation_exception('snapshotintegrityfailure', 'manifest', $snapshot->id);
        }
        return $hash;
    }

    /**
     * Verify one stored result and return its resolved active evidence rows.
     *
     * @param int $resultid Result ID.
     * @return array Result, decoded lineage, and evidence rows.
     */
    public static function result(int $resultid): array {
        global $DB;
        $result = $DB->get_record('local_outcomemap_result', ['id' => $resultid], '*', MUST_EXIST);
        if (!hash_equals((string) $result->lineagehash, hash('sha256', (string) $result->lineagejson))) {
            throw new validation_exception('resultintegrityfailure', 'result', $resultid);
        }
        $lineage = json_decode($result->lineagejson, true);
        if (!is_array($lineage)) {
            throw new validation_exception('resultintegrityfailure', 'lineage', $resultid);
        }
        $uuids = [];
        foreach ($lineage as $entry) {
            if (!is_array($entry) || empty($entry['uuid'])) {
                throw new validation_exception('resultintegrityfailure', 'lineage', $resultid);
            }
            $uuids[(string) $entry['uuid']] = true;
        }
        $evidence = $uuids
            ? $DB->get_records_list('local_outcomemap_evidence', 'uuid', array_keys($uuids), 'uuid ASC')
            : [];
        if (count($evidence) !== count($uuids)) {
            throw new validation_exception('resultintegrityfailure', 'evidence', $resultid);
        }
        return ['result' => $result, 'lineage' => $lineage, 'evidence' => array_values($evidence)];
    }
}
