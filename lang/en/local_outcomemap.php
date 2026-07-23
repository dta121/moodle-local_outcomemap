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
 * English language strings for local_outcomemap.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Learning outcome mapping';

$string['outcomemap:viewdefinitions'] = 'View outcome definitions';
$string['outcomemap:manageprograms'] = 'Manage programs and program-course memberships';
$string['outcomemap:managecatalogcourses'] = 'Manage catalog courses and course-instance associations';
$string['outcomemap:manageframeworks'] = 'Manage frameworks, outcomes, versions, and relations';
$string['outcomemap:mapcourse'] = 'Map and confirm a course instance';
$string['outcomemap:mapactivities'] = 'Map course activities, resources, and sections';
$string['outcomemap:mapquestions'] = 'Map question versions';
$string['outcomemap:approve'] = 'Approve governed outcome records';
$string['outcomemap:viewownresults'] = 'View own outcome results';
$string['outcomemap:viewallresults'] = 'View all authorized outcome results';
$string['outcomemap:managepolicies'] = 'Manage calculation and release policies';
$string['outcomemap:managesnapshots'] = 'Manage accreditation snapshots';
$string['outcomemap:exportaccreditation'] = 'Export accreditation data';
$string['outcomemap:overridecalculations'] = 'Override outcome calculations';

$string['nav_dashboard'] = 'Dashboard';
$string['nav_programs'] = 'Programs';
$string['nav_catalogcourses'] = 'Catalog courses';
$string['nav_courseinstances'] = 'Course instances';
$string['nav_frameworks'] = 'Frameworks and outcomes';
$string['nav_outcomes'] = 'Outcomes';
$string['nav_relations'] = 'Outcome relations';
$string['nav_approvalqueue'] = 'Approval queue';
$string['nav_csvimport'] = 'CSV import';

$string['dashboard_heading'] = 'Learning outcome mapping';
$string['dashboard_summary'] = 'Governed programs, catalog courses, course instances, frameworks, outcomes, versions, and relationships.';
$string['programs_heading'] = 'Programs';
$string['catalogcourses_heading'] = 'Catalog courses';
$string['courseinstances_heading'] = 'Course instances';
$string['frameworks_heading'] = 'Frameworks and outcomes';
$string['relations_heading'] = 'Outcome relations';
$string['approvalqueue_heading'] = 'Approval queue';
$string['csvimport_heading'] = 'Foundation CSV import';

$string['addprogram'] = 'Add program';
$string['editprogram'] = 'Edit program';
$string['addcatalogcourse'] = 'Add catalog course';
$string['editcatalogcourse'] = 'Edit catalog course';
$string['addcourseinstance'] = 'Add course instance';
$string['editcourseinstance'] = 'Edit course instance';
$string['addprogramcourse'] = 'Add program-course membership';
$string['addframework'] = 'Add framework';
$string['editframework'] = 'Edit framework';
$string['addoutcome'] = 'Add outcome';
$string['editoutcome'] = 'Edit outcome draft';
$string['newoutcomeversion'] = 'Create new outcome version';
$string['addrelation'] = 'Add relation';
$string['editrelation'] = 'Edit relation draft';
$string['newrelationversion'] = 'Create new relation version';
$string['submitreview'] = 'Submit for review';
$string['approve'] = 'Approve';
$string['retire'] = 'Retire';
$string['confirm'] = 'Confirm';
$string['actions'] = 'Actions';
$string['view'] = 'View';
$string['none'] = 'None';
$string['all'] = 'All';
$string['savechanges'] = 'Save changes';

$string['uuid'] = 'UUID';
$string['code'] = 'Code';
$string['name'] = 'Name';
$string['description'] = 'Description';
$string['externalid'] = 'External ID';
$string['siskey'] = 'SIS key';
$string['status'] = 'Status';
$string['createdby'] = 'Created by';
$string['timecreated'] = 'Created';
$string['timemodified'] = 'Modified';
$string['program'] = 'Program';
$string['catalogcourse'] = 'Catalog course';
$string['moodlecourse'] = 'Moodle course';
$string['periodcode'] = 'Reporting period code';
$string['confirmed'] = 'Confirmed';
$string['effectivefrom'] = 'Effective from';
$string['effectiveto'] = 'Effective to';
$string['framework'] = 'Framework';
$string['ownertype'] = 'Owner type';
$string['owner'] = 'Owner';
$string['outcome'] = 'Outcome';
$string['version'] = 'Version';
$string['statement'] = 'Statement';
$string['shortstatement'] = 'Short statement';
$string['bloomlevel'] = 'Bloom level';
$string['changereason'] = 'Change reason';
$string['sourceoutcome'] = 'Source outcome';
$string['targetoutcome'] = 'Target outcome';
$string['relationtype'] = 'Relationship type';
$string['weight'] = 'Contribution weight';
$string['notes'] = 'Notes';
$string['approvedby'] = 'Approved by';
$string['approvedat'] = 'Approved';
$string['reason'] = 'Reason';
$string['objecttype'] = 'Object type';

$string['status_draft'] = 'Draft';
$string['status_needs_review'] = 'Needs review';
$string['status_approved'] = 'Approved';
$string['status_retired'] = 'Retired';
$string['owner_institution'] = 'Institution';
$string['owner_program'] = 'Program';
$string['owner_catalog_course'] = 'Catalog course';
$string['relation_is_child_of'] = 'Is child of';
$string['relation_aligns_to'] = 'Aligns to';
$string['relation_contributes_to'] = 'Contributes to';
$string['relation_replaced_by'] = 'Replaced by';
$string['relation_related_to'] = 'Related to';

$string['saved'] = 'Changes saved.';
$string['submittedforreview'] = 'The record was submitted for review.';
$string['approved'] = 'The record was approved.';
$string['retired'] = 'The record was retired.';
$string['confirmapprove'] = 'Approve this governed record? Approved versions are immutable.';
$string['confirmretire'] = 'Retire this governed record?';
$string['emptytable'] = 'No records found.';
$string['approval_creator_denied'] = 'Creators cannot approve their own governed records.';

$string['csvfile'] = 'CSV file';
$string['csventity'] = 'Entity type';
$string['csvdelimiter'] = 'Delimiter';
$string['encoding'] = 'Encoding';
$string['preview'] = 'Preview';
$string['commitimport'] = 'Commit all rows';
$string['downloadtemplate'] = 'Download template';
$string['importpreview'] = 'Import preview';
$string['importvalid'] = 'All rows are valid. Review the preview before committing.';
$string['importinvalid'] = 'The file contains validation errors. Nothing can be committed.';
$string['importcommitted'] = '{$a} rows were committed in one transaction.';
$string['importnotcommitted'] = 'Nothing was committed.';
$string['rownumber'] = 'Row';
$string['validation'] = 'Validation';
$string['valid'] = 'Valid';
$string['invalid'] = 'Invalid';
$string['entity_programs'] = 'Programs';
$string['entity_courses'] = 'Catalog courses';
$string['entity_program_courses'] = 'Program-course memberships';
$string['entity_course_instances'] = 'Course instances';
$string['entity_frameworks'] = 'Frameworks';
$string['entity_outcomes'] = 'Outcomes and initial versions';
$string['entity_relations'] = 'Outcome relations';

$string['invalidstatus'] = 'Invalid workflow status "{$a->detail}".';
$string['invalidtransition'] = 'Invalid workflow transition "{$a->detail}".';
$string['invaliduuid'] = 'Invalid UUID "{$a->detail}".';
$string['invalidjson'] = 'The {$a->field} value cannot be encoded as JSON: {$a->detail}';
$string['requiredfield'] = 'The {$a->field} field is required.';
$string['invalidfield'] = 'The {$a->field} field is invalid: {$a->detail}';
$string['duplicatecode'] = 'The code already exists in this scope.';
$string['duplicateuuid'] = 'The UUID already exists.';
$string['recordnotfound'] = 'The requested {$a->field} record was not found.';
$string['approvedimmutable'] = 'Approved governed records are immutable. Create a new version instead.';
$string['creatorcannotapprove'] = 'The creator cannot approve their own record.';
$string['effectiverangeinvalid'] = 'The effective end must be later than the effective start.';
$string['effectiverangeoverlap'] = 'The approved effective range overlaps an existing approved record.';
$string['invalidowner'] = 'The framework owner is invalid.';
$string['invalidrelationtype'] = 'The relationship type is invalid.';
$string['selfrelation'] = 'An outcome cannot relate to itself.';
$string['weightnotallowed'] = 'Only contributes-to relationships may have a weight.';
$string['weightrequired'] = 'A contributes-to relationship requires a positive decimal weight.';
$string['cycle'] = 'Approving this relationship would create a cycle.';
$string['moodlecoursenotfound'] = 'The selected Moodle course does not exist.';
$string['courseinstanceexists'] = 'This Moodle course and reporting-period association already exists.';
$string['importheader'] = 'The CSV header is invalid. Expected: {$a}';
$string['importempty'] = 'The CSV file contains no data rows.';
$string['importexpired'] = 'The import preview expired. Upload and validate the file again.';
$string['importchanged'] = 'The submitted import content does not match the validated preview.';
$string['importerrors'] = 'The import contains validation errors and was not committed.';
$string['invaliddate'] = 'The value must be a Unix timestamp or ISO date (YYYY-MM-DD).';
$string['invalidboolean'] = 'The value must be 0 or 1.';
$string['invalidinteger'] = 'The value must be an integer.';
$string['invaliddecimal'] = 'The value must be a positive decimal number with at most 10 decimal places.';

$string['privacy:metadata'] = 'The governed outcome foundation stores creator, modifier, approver, confirmer, and audit actor identifiers. Student evidence and results are introduced in later milestones.';
$string['privacy:metadata:local_outcomemap_audit'] = 'Append-only governance audit history.';
$string['privacy:metadata:local_outcomemap_audit:actorid'] = 'The user who performed the audited action.';
$string['privacy:metadata:local_outcomemap_audit:timecreated'] = 'When the audited action occurred.';


$string['courseoutcomemapping'] = 'Course outcome mapping';
$string['nav_coverage'] = 'Outcome coverage';
$string['nav_contentmapping'] = 'Content mappings';
$string['nav_remediation'] = 'Remediation';
$string['coverage_heading'] = 'Course outcome coverage';
$string['contentmapping_heading'] = 'Course content mappings';
$string['remediation_heading'] = 'Outcome remediation';
$string['modulemapping_heading'] = 'Outcome mapping';
$string['modulemapping_intro'] = 'Optionally add one explicit draft mapping for this activity. Manage additional mappings from the course content mappings page.';
$string['addcontentmapping'] = 'Add content mapping';
$string['editcontentmapping'] = 'Edit content mapping draft';
$string['newmappingversion'] = 'Create new mapping version';
$string['addremediation'] = 'Add remediation recommendation';
$string['editremediation'] = 'Edit remediation draft';
$string['newremediationversion'] = 'Create new remediation version';
$string['courseinstance'] = 'Course instance';
$string['target'] = 'Target';
$string['targettype'] = 'Target type';
$string['coursemodule'] = 'Course activity or resource';
$string['coursemodules'] = 'Activities and resources';
$string['coursesection'] = 'Course section';
$string['coursesections'] = 'Course sections';
$string['outcomeversion'] = 'Exact outcome version';
$string['mappingrole'] = 'Mapping role';
$string['mappingweight'] = 'Explicit weight';
$string['mappingweight_help'] = 'Optional governed decimal weight. Leaving this blank does not assign an implicit or equal weight.';
$string['priority'] = 'Priority';
$string['externalurl'] = 'External URL';
$string['title'] = 'Title';
$string['explanation'] = 'Explanation';
$string['requiredremediation'] = 'Required recommendation';
$string['minpercent'] = 'Minimum percentage';
$string['maxpercent'] = 'Maximum percentage';
$string['target_course_module'] = 'Course activity or resource';
$string['target_course_section'] = 'Course section';
$string['target_external_url'] = 'External URL';
$string['mappingrole_teaches'] = 'Teaches';
$string['mappingrole_practices'] = 'Practices';
$string['mappingrole_assesses'] = 'Assesses';
$string['mappingrole_remediates'] = 'Remediates';
$string['mappingrole_alignment_only'] = 'Alignment only';
$string['nocourseinstance'] = 'This course has no approved, confirmed course-instance association.';
$string['nocoveragemappings'] = 'No course-content mappings are available for this course.';
$string['invalidtargettype'] = 'The mapping target type is invalid.';
$string['targetcoursemismatch'] = 'The selected target does not belong to the mapped course instance.';
$string['courseinstancenotconfirmed'] = 'The course-instance association must be approved and confirmed before it can be mapped.';
$string['outcomeversionnotapproved'] = 'Mappings must bind to an approved exact outcome version.';
$string['mappingoutsideoutcomeversion'] = 'The mapping effective range must be contained within the exact outcome version range.';
$string['invalidmappingrole'] = 'The mapping role is invalid.';
$string['duplicatemapping'] = 'An approved mapping already covers this target, outcome version, role, and effective range.';
$string['mappingunderreview'] = 'This mapping is under review and cannot be changed from the activity form.';
$string['remediationtargetinvalid'] = 'Select exactly one valid internal target or external URL.';

$string['nav_settings'] = 'Settings';
$string['autocopyquestionmappings'] = 'Copy question mappings to new versions';
$string['autocopyquestionmappings_desc'] = 'When a new question version is created, copy the approved outcome mappings of the immediately preceding version onto the new version as drafts. Copied drafts must be reviewed and approved before they generate attainment evidence.';
$string['questionmapping'] = 'Question mapping';
$string['questionversionmismatch'] = 'The question and question-version identifiers do not match the Moodle question bank.';
$string['assessedweightrequired'] = 'An assesses mapping requires an explicit positive weight. Weights are never inferred.';
$string['weightnotallowedforrole'] = 'Only assesses mappings carry an evidence weight; the "{$a->detail}" role must not have one.';
$string['assessedweighttotalinvalid'] = 'Approved assessed weights for a question version must total exactly 1.0000000000; the resulting total is {$a->detail}.';

$string['taskreconcile'] = 'Reconcile outcome evidence and stale results';
$string['invalidpolicyconfig'] = 'The policy configuration field {$a->field} is invalid: {$a->detail}';
$string['bandsoverlap'] = 'Performance band ranges must not overlap: {$a->detail}';
$string['divisionbyzero'] = 'A zero denominator never produces a percentage.';
$string['policy'] = 'Policy';
$string['policytype_attempt_selection'] = 'Attempt selection';
$string['policytype_calculation'] = 'Calculation and bands';
$string['bandnotavailable'] = 'Performance-band remediation becomes available when governed calculation policies are installed.';
$string['percentagerangeinvalid'] = 'The maximum percentage must be greater than or equal to the minimum percentage.';
$string['invalidexternalurl'] = 'Enter a valid HTTP or HTTPS URL.';
$string['invalidpercentage'] = 'Enter a percentage from 0 to 100 with at most 10 decimal places.';
