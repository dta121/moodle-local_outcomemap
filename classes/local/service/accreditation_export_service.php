<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\canonical_json;
use local_outcomemap\local\decimal;
use local_outcomemap\local\validation_exception;

/**
 * Verified, suppression-safe exports of frozen accreditation snapshots.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class accreditation_export_service {
    /**
     * Build a deterministic canonical export package.
     *
     * Normal packages include de-identified learner result rows only for
     * non-suppressed program outcomes, allowing aggregate reconstruction.
     * Atomic evidence and suppressed subject rows require the additional
     * all-results capability and an explicit evidence-detail request.
     *
     * @param int $snapshotid Snapshot ID.
     * @param bool $includeevidence Include protected subject/evidence detail.
     * @return array
     */
    public static function package(int $snapshotid, bool $includeevidence = false): array {
        [$snapshot, $items] = self::load_verified($snapshotid, $includeevidence);
        $exportitems = [];
        foreach ($items as $item) {
            if (!self::include_item($item, $includeevidence)) {
                continue;
            }
            if ((int) $item->suppressed === 1 && !$includeevidence) {
                $exportitems[] = self::suppressed_item($item);
                continue;
            }
            $exportitems[] = [
                'itemtype' => (string) $item->itemtype,
                'stablekey' => (string) $item->stablekey,
                'payloadhash' => (string) $item->payloadhash,
                'payload' => json_decode($item->payloadjson, true),
            ];
        }
        $manifest = audit_lineage_service::manifest($snapshot, count($items));
        $manifest['manifesthash'] = (string) $snapshot->manifesthash;
        return [
            'schema' => 'local_outcomemap-accreditation-export-v1',
            'mode' => $includeevidence ? 'evidence_detail' : 'standard',
            'manifest' => $manifest,
            'items' => $exportitems,
        ];
    }

    /**
     * Encode a package as canonical JSON.
     *
     * @param int $snapshotid Snapshot ID.
     * @param bool $includeevidence Include protected evidence detail.
     * @return string
     */
    public static function json(int $snapshotid, bool $includeevidence = false): string {
        return canonical_json::encode(self::package($snapshotid, $includeevidence)) . "\n";
    }

    /**
     * Export aggregate summary rows as RFC 4180-compatible UTF-8 CSV.
     *
     * Suppressed rows retain identifiers, subject count, and the suppression
     * marker while numeric result and band fields are blank.
     *
     * @param int $snapshotid Snapshot ID.
     * @return string
     */
    public static function summary_csv(int $snapshotid): string {
        [$snapshot, $items] = self::load_verified($snapshotid, false);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \coding_exception('Unable to create the accreditation CSV stream.');
        }
        fputcsv($stream, [
            'snapshot_uuid',
            'snapshot_version',
            'program_id',
            'period_code',
            'item_type',
            'course_instance_id',
            'outcome_version_id',
            'state',
            'band',
            'subject_count',
            'suppressed',
            'numerator',
            'denominator',
            'percentage',
            'criterion_percent',
            'benchmark_percent',
            'assessed_count',
            'met_count',
            'attainment_percent',
            'benchmark_met',
            'payload_hash',
            'manifest_hash',
        ]);
        foreach ($items as $item) {
            if (!in_array($item->itemtype, [
                snapshot_service::ITEM_COURSE_AGGREGATE,
                snapshot_service::ITEM_PROGRAM_AGGREGATE,
            ], true)) {
                continue;
            }
            $suppressed = (int) $item->suppressed === 1;
            fputcsv($stream, [
                (string) $snapshot->snapshotuuid,
                (int) $snapshot->version,
                (int) $snapshot->programid,
                (string) $snapshot->periodcode,
                (string) $item->itemtype,
                $item->cinstid === null ? '' : (int) $item->cinstid,
                $item->itemverid === null ? '' : (int) $item->itemverid,
                $item->state === null ? '' : (string) $item->state,
                $suppressed || $item->bandcode === null ? '' : (string) $item->bandcode,
                (int) $item->subjectcount,
                $suppressed ? 1 : 0,
                $suppressed ? '' : decimal::canonical($item->numerator, 'numerator'),
                $suppressed ? '' : decimal::canonical($item->denominator, 'denominator'),
                $suppressed || $item->percentage === null
                    ? '' : decimal::canonical($item->percentage, 'percentage'),
                // The criterion and benchmark are governed policy rather than
                // learner data, so they stay readable on suppressed rows; the
                // met counts and rate derived from a small cohort do not.
                $item->criterionpercent === null
                    ? '' : decimal::canonical($item->criterionpercent, 'criterionpercent'),
                $item->benchmarkpercent === null
                    ? '' : decimal::canonical($item->benchmarkpercent, 'benchmarkpercent'),
                $suppressed ? '' : (int) $item->assessedcount,
                $suppressed ? '' : (int) $item->metcount,
                $suppressed || $item->attainmentpercent === null
                    ? '' : decimal::canonical($item->attainmentpercent, 'attainmentpercent'),
                $suppressed || $item->benchmarkmet === null ? '' : (int) $item->benchmarkmet,
                (string) $item->payloadhash,
                (string) $snapshot->manifesthash,
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new \coding_exception('Unable to read the accreditation CSV stream.');
        }
        return $csv;
    }

    /**
     * Return a safe deterministic download stem.
     *
     * @param \stdClass $snapshot Snapshot record.
     * @return string
     */
    public static function filename_stem(\stdClass $snapshot): string {
        return 'outcomemap-' . clean_filename((string) $snapshot->snapshotuuid)
            . '-v' . (int) $snapshot->version;
    }

    /**
     * Load and cryptographically verify a frozen snapshot.
     *
     * @param int $snapshotid Snapshot ID.
     * @param bool $includeevidence Whether the stronger capability is required.
     * @return array{0:\stdClass,1:array}
     */
    private static function load_verified(int $snapshotid, bool $includeevidence): array {
        global $DB;
        $context = \context_system::instance();
        require_capability('local/outcomemap:exportaccreditation', $context);
        if ($includeevidence) {
            require_capability('local/outcomemap:viewallresults', $context);
        }
        $snapshot = $DB->get_record('local_outcomemap_snapshot', ['id' => $snapshotid], '*', MUST_EXIST);
        if ($snapshot->status !== snapshot_service::STATUS_FROZEN) {
            throw new validation_exception('exportnotfrozen', 'status', $snapshot->status);
        }
        $items = array_values($DB->get_records('local_outcomemap_snapitem',
            ['snapshotid' => $snapshotid], 'sortorder ASC, id ASC'));
        snapshot_service::verify($snapshot, $items);
        return [$snapshot, $items];
    }

    /**
     * Whether a stored item belongs in an export mode.
     *
     * @param \stdClass $item Snapshot item.
     * @param bool $includeevidence Evidence-detail mode.
     * @return bool
     */
    private static function include_item(\stdClass $item, bool $includeevidence): bool {
        if ($includeevidence) {
            return true;
        }
        if (in_array($item->itemtype, [
            snapshot_service::ITEM_POPULATION,
            snapshot_service::ITEM_EVIDENCE,
        ], true)) {
            return false;
        }
        if ($item->itemtype === snapshot_service::ITEM_RESULT && (int) $item->suppressed === 1) {
            return false;
        }
        return true;
    }

    /**
     * Append an audit event after a verified snapshot export is generated.
     *
     * Keeping this separate from package construction avoids recording test or
     * preview reads as downloads. HTTP and web-service boundaries call it only
     * after the complete response body has been built successfully.
     *
     * @param int $snapshotid Frozen snapshot ID.
     * @param string $format Export format: json or csv.
     * @param bool $includeevidence Whether protected evidence detail was included.
     */
    public static function record_export(
        int $snapshotid,
        string $format,
        bool $includeevidence = false
    ): void {
        global $USER;

        $format = strtolower(trim($format));
        if (!in_array($format, ['json', 'csv'], true) || ($format === 'csv' && $includeevidence)) {
            throw new validation_exception('invalidfield', 'format', $format);
        }
        [$snapshot, $items] = self::load_verified($snapshotid, $includeevidence);
        \local_outcomemap\local\audit_writer::write(
            'export_snapshot',
            'snapshot',
            (int) $snapshot->id,
            (string) $snapshot->snapshotuuid,
            null,
            [
                'format' => $format,
                'mode' => $includeevidence ? 'evidence_detail' : 'standard',
                'snapshotversion' => (int) $snapshot->version,
                'itemcount' => count($items),
                'payloadhash' => (string) $snapshot->payloadhash,
                'manifesthash' => (string) $snapshot->manifesthash,
            ],
            null,
            \context_system::instance(),
            (int) $USER->id
        );
    }

    /**
     * Build a redacted proof row for a suppressed aggregate.
     *
     * @param \stdClass $item Suppressed item.
     * @return array
     */
    private static function suppressed_item(\stdClass $item): array {
        $decoded = json_decode($item->payloadjson, true);
        return [
            'itemtype' => (string) $item->itemtype,
            'stablekey' => (string) $item->stablekey,
            'payloadhash' => (string) $item->payloadhash,
            'redacted' => true,
            'payload' => [
                'type' => (string) $item->itemtype,
                'identity' => $decoded['identity'] ?? [],
                'index' => [
                    'cinstid' => $item->cinstid === null ? null : (int) $item->cinstid,
                    'itemverid' => $item->itemverid === null ? null : (int) $item->itemverid,
                    'subjectcount' => (int) $item->subjectcount,
                    'suppressed' => 1,
                    'numerator' => null,
                    'denominator' => null,
                    'percentage' => null,
                    'bandcode' => null,
                ],
            ],
        ];
    }
}
