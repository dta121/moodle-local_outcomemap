<?php
// This file is part of Moodle - http://moodle.org/

namespace local_outcomemap\local;

/** Immutable result of validating a foundation CSV import. */
final class import_preview {
    /** @var array<int,object> */
    public readonly array $rows;
    public readonly string $hash;
    public readonly bool $valid;

    /** Constructor. */
    public function __construct(array $rows, string $hash, bool $valid) {
        $this->rows = $rows;
        $this->hash = $hash;
        $this->valid = $valid;
    }

    /** Number of data rows. */
    public function count(): int {
        return count($this->rows);
    }
}
