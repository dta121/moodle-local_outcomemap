<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Outcome mapping dashboard.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_outcomemap\output\dashboard_page;

$configpath = __DIR__ . '/../../config.php';
if (!is_readable($configpath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    // Windows junctions resolve __DIR__ to the repository target rather than
    // the Moodle local-plugin directory. The webroot loader remains portable
    // across Moodle's classic and 5.2 public-directory layouts.
    $configpath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config.php';
}
require_once($configpath);
unset($configpath);
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_outcomemap_dashboard');
require_capability('local/outcomemap:viewdefinitions', context_system::instance());

$page = new dashboard_page();
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_outcomemap/dashboard_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
