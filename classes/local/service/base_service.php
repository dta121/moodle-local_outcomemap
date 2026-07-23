<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\local\service;

use local_outcomemap\local\validation_exception;

/**
 * Shared helpers for internal transactional services.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_service {
    /**
     * Require a system capability and return the current actor ID.
     *
     * @param string $capability Capability name.
     * @return int
     */
    protected static function require_system(string $capability): int {
        global $USER;
        require_capability($capability, \context_system::instance());
        return (int) $USER->id;
    }

    /**
     * Load a required record.
     *
     * @param string $table Table name.
     * @param int $id Record ID.
     * @param string $objecttype Error object type.
     * @return \stdClass
     */
    protected static function get_required(string $table, int $id, string $objecttype): \stdClass {
        global $DB;
        $record = $DB->get_record($table, ['id' => $id]);
        if (!$record) {
            throw new validation_exception('recordnotfound', $objecttype, $id);
        }
        return $record;
    }

    /**
     * Roll a delegated transaction back and preserve the original exception.
     *
     * @param \moodle_transaction $transaction Transaction.
     * @param \Throwable $exception Original exception.
     * @return never
     */
    protected static function rollback(\moodle_transaction $transaction, \Throwable $exception): void {
        $transaction->rollback($exception);
        throw $exception;
    }
}
