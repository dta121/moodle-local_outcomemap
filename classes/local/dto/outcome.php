<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\dto;

/** Immutable companion-safe representation of an approved outcome version. */
final class outcome implements \JsonSerializable {
    public readonly string $uuid;
    public readonly string $code;
    public readonly string $frameworkuuid;
    public readonly string $frameworkcode;
    public readonly string $versionuuid;
    public readonly int $version;
    public readonly string $statement;
    public readonly ?string $shortstatement;
    public readonly ?string $bloomlevel;
    public readonly int $effectivefrom;
    public readonly ?int $effectiveto;

    /** Constructor. */
    public function __construct(
        string $uuid,
        string $code,
        string $frameworkuuid,
        string $frameworkcode,
        string $versionuuid,
        int $version,
        string $statement,
        ?string $shortstatement,
        ?string $bloomlevel,
        int $effectivefrom,
        ?int $effectiveto
    ) {
        $this->uuid = $uuid;
        $this->code = $code;
        $this->frameworkuuid = $frameworkuuid;
        $this->frameworkcode = $frameworkcode;
        $this->versionuuid = $versionuuid;
        $this->version = $version;
        $this->statement = $statement;
        $this->shortstatement = $shortstatement;
        $this->bloomlevel = $bloomlevel;
        $this->effectivefrom = $effectivefrom;
        $this->effectiveto = $effectiveto;
    }

    /** Return a stable serialization without internal database IDs. */
    public function jsonSerialize(): array {
        return get_object_vars($this);
    }
}
