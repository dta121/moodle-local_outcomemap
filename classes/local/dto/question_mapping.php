<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\dto;

/**
 * Immutable companion-safe representation of a question-version mapping.
 *
 * The record ID is exposed deliberately: it is the stable handle companion
 * plugins pass back to the draft mutation services. Internal foreign keys
 * other than the core question identifiers stay private to this plugin.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_mapping implements \JsonSerializable {
    /** @var int Mapping record ID used with the draft mutation services. */
    public readonly int $id;
    /** @var string Stable mapping UUID. */
    public readonly string $mappinguuid;
    /** @var int Mapping version number. */
    public readonly int $mappingversion;
    /** @var int Core question-version ID the mapping binds to. */
    public readonly int $questionversionid;
    /** @var int Core question ID recorded for provenance. */
    public readonly int $questionid;
    /** @var int|null Source mapping record ID when copied from an earlier question version. */
    public readonly ?int $sourceqmapid;
    /** @var int|null Source core question-version ID when copied. */
    public readonly ?int $sourcequestionversionid;
    /** @var string|null Stable source mapping UUID when copied. */
    public readonly ?string $sourcemappinguuid;
    /** @var int|null Source mapping version number when copied. */
    public readonly ?int $sourcemappingversion;
    /** @var int|null Human-facing source question version number when copied. */
    public readonly ?int $sourcequestionversion;
    /** @var string Stable outcome UUID. */
    public readonly string $outcomeuuid;
    /** @var string Outcome code. */
    public readonly string $outcomecode;
    /** @var string Exact outcome-version UUID. */
    public readonly string $outcomeversionuuid;
    /** @var int Exact outcome-version number. */
    public readonly int $outcomeversion;
    /** @var string Outcome statement. */
    public readonly string $outcomestatement;
    /** @var string|null Outcome short statement. */
    public readonly ?string $outcomeshortstatement;
    /** @var string Framework UUID. */
    public readonly string $frameworkuuid;
    /** @var string Framework code. */
    public readonly string $frameworkcode;
    /** @var string Mapping role. */
    public readonly string $role;
    /** @var string|null Canonical assessed weight. */
    public readonly ?string $weight;
    /** @var string|null Mapping notes. */
    public readonly ?string $notes;
    /** @var string Workflow status. */
    public readonly string $status;
    /** @var int Effective start timestamp. */
    public readonly int $effectivefrom;
    /** @var int|null Effective end timestamp. */
    public readonly ?int $effectiveto;

    /**
     * Constructor from a joined internal mapping record.
     *
     * @param \stdClass $record Joined mapping record from the internal service.
     */
    public function __construct(\stdClass $record) {
        $this->id = (int) $record->id;
        $this->mappinguuid = $record->mappinguuid;
        $this->mappingversion = (int) $record->version;
        $this->questionversionid = (int) $record->questionversionid;
        $this->questionid = (int) $record->questionid;
        $this->sourceqmapid = empty($record->sourceqmapid) ? null : (int) $record->sourceqmapid;
        $this->sourcequestionversionid = empty($record->sourcequestionversionid)
            ? null
            : (int) $record->sourcequestionversionid;
        $this->sourcemappinguuid = empty($record->sourcemappinguuid)
            ? null
            : (string) $record->sourcemappinguuid;
        $this->sourcemappingversion = empty($record->sourcemappingversion)
            ? null
            : (int) $record->sourcemappingversion;
        $this->sourcequestionversion = empty($record->sourcequestionversion)
            ? null
            : (int) $record->sourcequestionversion;
        $this->outcomeuuid = $record->outcomeuuid;
        $this->outcomecode = $record->outcomecode;
        $this->outcomeversionuuid = $record->outcomeversionuuid;
        $this->outcomeversion = (int) $record->outcomeversion;
        $this->outcomestatement = $record->outcomestatement;
        $this->outcomeshortstatement = $record->outcomeshortstatement;
        $this->frameworkuuid = $record->frameworkuuid;
        $this->frameworkcode = $record->frameworkcode;
        $this->role = $record->role;
        $this->weight = $record->weight === null ? null : (string) $record->weight;
        $this->notes = $record->notes;
        $this->status = $record->status;
        $this->effectivefrom = (int) $record->effectivefrom;
        $this->effectiveto = $record->effectiveto === null ? null : (int) $record->effectiveto;
    }

    /**
     * Return a stable serialization.
     *
     * @return array
     */
    public function jsonSerialize(): array {
        return get_object_vars($this);
    }
}
