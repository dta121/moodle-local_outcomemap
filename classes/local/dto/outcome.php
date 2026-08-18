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

/**
 * Learning Outcome Mapping plugin component.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\local\dto;

/**
 * Immutable companion-safe representation of an approved outcome version.
 */
final class outcome implements \JsonSerializable {
    /**
     * Stable outcome UUID.
     *
     * @var string
     */
    public readonly string $uuid;
    /**
     * Stable outcome code.
     *
     * @var string
     */
    public readonly string $code;
    /**
     * Stable framework UUID.
     *
     * @var string
     */
    public readonly string $frameworkuuid;
    /**
     * Framework code.
     *
     * @var string
     */
    public readonly string $frameworkcode;
    /**
     * Stable outcome-version UUID.
     *
     * @var string
     */
    public readonly string $versionuuid;
    /**
     * Outcome version number.
     *
     * @var int
     */
    public readonly int $version;
    /**
     * Outcome statement.
     *
     * @var string
     */
    public readonly string $statement;
    /**
     * Optional short outcome statement.
     *
     * @var string|null
     */
    public readonly ?string $shortstatement;
    /**
     * Optional Bloom taxonomy level.
     *
     * @var string|null
     */
    public readonly ?string $bloomlevel;
    /**
     * Effective-from timestamp.
     *
     * @var int
     */
    public readonly int $effectivefrom;
    /**
     * Optional effective-to timestamp.
     *
     * @var int|null
     */
    public readonly ?int $effectiveto;

    /**
     * Constructor.
     *
     * @param string $uuid Uuid.
     * @param string $code Code.
     * @param string $frameworkuuid Frameworkuuid.
     * @param string $frameworkcode Frameworkcode.
     * @param string $versionuuid Versionuuid.
     * @param int $version Version.
     * @param string $statement Statement.
     * @param ?string $shortstatement Shortstatement.
     * @param ?string $bloomlevel Bloomlevel.
     * @param int $effectivefrom Effectivefrom.
     * @param ?int $effectiveto Effectiveto.
     */
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

    /**
     * Return a stable serialization without internal database IDs.
     */
    public function jsonSerialize(): array {
        return get_object_vars($this);
    }
}
