<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Seed the plugin's example custom reports and example accreditation snapshot.
 *
 * The examples are built from records the site already holds. Run this on an
 * evaluation or demonstration site: it writes governed records, including an
 * immutable frozen snapshot, exactly as the interactive pages would.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\local\service\example_seed_service;

define('CLI_SCRIPT', true);

$configpath = __DIR__ . '/../../../config.php';
if (!is_readable($configpath)) {
    // A symlinked or junctioned plugin directory resolves outside the Moodle
    // tree, so fall back to the nearest config.php at or above the working
    // directory. Run the script from the Moodle root for that fallback to work.
    $searchdir = getcwd();
    while ($searchdir !== '' && !is_readable($searchdir . '/config.php')) {
        $parent = dirname($searchdir);
        $searchdir = $parent === $searchdir ? '' : $parent;
    }
    if ($searchdir === '') {
        fwrite(STDERR, "Unable to locate config.php. Run this script from the Moodle root.\n");
        exit(1);
    }
    $configpath = $searchdir . '/config.php';
    unset($searchdir, $parent);
}
require($configpath);
unset($configpath);
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'reports' => false,
    'snapshot' => false,
    'program' => null,
    'period' => null,
    'mincohortsize' => null,
    'draft' => false,
    'replace' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'core_admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln(<<<'EOT'
Seed example outcome-mapping reports and one example accreditation snapshot.

With no selection option both are seeded. Seeding is idempotent: an example that
already exists is reported and left untouched.

A capture needs an effective approved accreditation policy for the program. Any
policy the institution has already approved is used unchanged. Only when there is
none does this script draft one, with an explicit example suppression threshold:
the plugin supplies no threshold, population, or retention default of its own.

Options:
  -h, --help             Print this help.
      --reports          Seed only the example custom reports.
      --snapshot         Seed only the example accreditation snapshot.
      --program=ID       Program record ID to capture. Default: auto-select.
      --period=CODE      Reporting period code to capture. Default: auto-select.
      --mincohortsize=N  Suppression threshold of a seeded example policy.
      --draft            Leave the capture as a draft instead of freezing it.
      --replace          Delete every existing snapshot version of the selected
                         program and period, then capture the example again from
                         current results. Deletion is audited and irreversible.

Example:
  php local/outcomemap/cli/seed_examples.php --snapshot --period=MBA601
  php local/outcomemap/cli/seed_examples.php --snapshot --program=1 --replace
EOT);
    exit(0);
}

// A snapshot capture holds every evidence, result, and aggregate row of the
// reporting period in memory before it is hashed, so give the capture room.
raise_memory_limit(MEMORY_HUGE);

// Governed writes are attributed, so run as the primary site administrator.
\core\session\manager::set_user(get_admin());

$doreports = $options['reports'] || !$options['snapshot'];
$dosnapshot = $options['snapshot'] || !$options['reports'];

if ($doreports) {
    cli_heading('Example custom reports');
    foreach (example_seed_service::seed_reports() as $report) {
        cli_writeln(sprintf(
            '  [%s] %s (report id %d)',
            $report['created'] ? 'created' : 'exists',
            $report['name'],
            $report['reportid']
        ));
    }
}

if ($dosnapshot) {
    cli_heading('Example accreditation snapshot');
    $result = example_seed_service::seed_snapshot([
        'programid' => $options['program'] === null ? null : (int) $options['program'],
        'periodcode' => $options['period'] === null ? null : (string) $options['period'],
        'mincohortsize' => $options['mincohortsize'] === null ? null : (int) $options['mincohortsize'],
        'freeze' => !$options['draft'],
        'replace' => $options['replace'],
    ]);
    cli_writeln(sprintf(
        '  accreditation policy id %d [%s]',
        $result['policyid'],
        $result['policycreated'] ? 'created' : 'exists'
    ));
    if ($result['replaced'] > 0) {
        cli_writeln(sprintf('  [deleted] %d existing snapshot version(s)', $result['replaced']));
    }
    cli_writeln(sprintf(
        '  [%s] snapshot id %d for program %d period %s (%s)',
        $result['created'] ? 'created' : 'exists',
        $result['snapshotid'],
        $result['programid'],
        $result['periodcode'],
        $result['frozen'] ? 'frozen' : 'draft'
    ));
}

exit(0);
