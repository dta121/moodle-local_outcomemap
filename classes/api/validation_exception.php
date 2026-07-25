<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\api;

/**
 * Stable validation exception exposed by public service facades.
 *
 * Companion plugins may catch this type without depending on implementation
 * namespaces. The legacy local exception extends this class for compatibility.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validation_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $errorcode Language string/error code.
     * @param string|null $field Field associated with the error.
     * @param mixed $detail Optional error detail.
     */
    public function __construct(string $errorcode, ?string $field = null, $detail = null) {
        $a = (object) ['field' => $field ?? '', 'detail' => $detail ?? ''];
        parent::__construct($errorcode, 'local_outcomemap', '', $a);
    }
}
