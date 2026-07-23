<?php
// This file is part of Moodle - http://moodle.org/

use local_outcomemap\local\service\approval_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_outcomemap_approvals');
$url = new moodle_url('/local/outcomemap/approvalqueue.php');
$action = optional_param('action', '', PARAM_ALPHA);
$objecttype = optional_param('objecttype', '', PARAM_ALPHANUMEXT);
$id = optional_param('id', 0, PARAM_INT);
$confirmed = optional_param('confirm', 0, PARAM_BOOL);

if ($action === 'approve' && $id && $objecttype) {
    if (!$confirmed) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirmapprove', 'local_outcomemap'),
            new moodle_url($url, ['action' => 'approve', 'objecttype' => $objecttype, 'id' => $id,
                'confirm' => 1, 'sesskey' => sesskey()]), $url);
        echo $OUTPUT->footer();
        exit;
    }
    require_sesskey();
    approval_service::approve($objecttype, $id);
    redirect($url, get_string('approved', 'local_outcomemap'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('approvalqueue_heading', 'local_outcomemap'));
$table = new html_table();
$table->head = [get_string('objecttype', 'local_outcomemap'), get_string('code', 'local_outcomemap'),
    get_string('name', 'local_outcomemap'), get_string('createdby', 'local_outcomemap'),
    get_string('timemodified', 'local_outcomemap'), get_string('actions', 'local_outcomemap')];
foreach (approval_service::list_pending() as $record) {
    $approveurl = new moodle_url($url, ['action' => 'approve', 'objecttype' => $record->objecttype, 'id' => $record->id]);
    $table->data[] = [s($record->objecttype), s($record->code), s($record->name), (int) $record->createdby,
        userdate($record->timemodified), html_writer::link($approveurl, get_string('approve', 'local_outcomemap'))];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
