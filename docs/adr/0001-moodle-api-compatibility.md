# ADR 0001: Moodle 4.5–5.2 API compatibility

- Status: Accepted
- Date: 2026-07-22
- Decision owners: `local_outcomemap` maintainers
- Scope: Milestone 0 API spike

## Context

The initial implementation must run on Moodle 4.5 while remaining valid against the locally installed Moodle 5.2 tree. The plugin must not modify core or depend on invented, internal-only, or deprecated extension points.

Compatibility baseline:

- Minimum Moodle version: `2024100700` (Moodle 4.5).
- Validation target: `2026042000.00` (installed Moodle 5.2).
- Source syntax baseline: PHP 8.1-compatible syntax; deployment on the local Moodle 5.2 tree requires PHP 8.3.
- All heavy evidence processing is deferred to ad hoc and scheduled tasks.

## Decision

### Activity settings form

Use the course-module callbacks dispatched by `moodleform_mod`; the similarly named `core_course\hook\after_form_*` classes extend the course edit form, not activity/resource settings.

Implement thin functions in `lib.php`:

- `local_outcomemap_coursemodule_standard_elements(moodleform_mod $form, MoodleQuickForm $mform)` adds the outcome section.
- `local_outcomemap_coursemodule_definition_after_data(moodleform_mod $form, MoodleQuickForm $mform)` loads mapping defaults.
- `local_outcomemap_coursemodule_validation(moodleform_mod $form, stdClass $data): array` validates roles, weights, and permissions.
- `local_outcomemap_coursemodule_edit_post_actions(stdClass $moduleinfo, stdClass $course): stdClass` persists through an autoloaded transactional service after the course module ID exists.

The callbacks exist in Moodle 4.5 and the installed Moodle 5.2 source. Callback bodies must only adapt Moodle form data to public service DTOs. They must not contain DML or calculation logic.

### Question-bank extension

The companion `qbank_outcomemap` will use these supported classes:

- Entry point: `qbank_outcomemap\plugin_feature extends core_question\local\bank\plugin_features_base`.
- Column: subclass `core_question\local\bank\column_base`; register with `get_question_columns()` and bulk-load visible version mappings in `load_additional_data()`.
- Filter: subclass `core_question\local\bank\condition`; implement `get_title()`, `get_filter_class()`, `get_condition_key()`, `get_initial_values()`, `get_filteroptions()`, and `build_query_from_filter()` explicitly.
- Bulk action: subclass `core_question\local\bank\bulk_action_base`; implement title, key, URL, and capabilities; register with `get_bulk_actions(?view $qbank = null): array`.

Compatibility constraints:

1. Moodle 4.5 declares `get_bulk_actions(?view $qbank = null)` while 5.2 declares a non-null `view` and `array` return. The companion override will accept `?view` and return `array`, which is compatible with both.
2. Moodle 4.5 bulk actions are instantiated without a qbank view; Moodle 5.2's base extends `view_component`. The first implementation will not depend on a stored view in the bulk action and will use a static redirect URL plus posted selected question IDs.
3. Moodle 4.5 requires `condition::get_filter_class()`; implement it even though Moodle 5.2 supplies a nullable default.
4. The column must use `load_additional_data()` for one bulk service call. It must not query once per row.
5. Selected IDs received by a bulk action are untrusted. Resolve each `question.id` to `question_versions.id`, validate the question-bank context, and require both `local/outcomemap:mapquestions` and the applicable Moodle question capabilities.

### Question versions and attempt provenance

Question mappings use `question_versions.id`. The stable question-bank identity remains `question_bank_entries.id`; a concrete version joins as:

```text
question_bank_entries.id
  -> question_versions.questionbankentryid
  -> question_versions.questionid
  -> question.id
```

A quiz attempt joins to the question engine as:

```text
quiz_attempts.id
  -> quiz_attempts.uniqueid
  -> question_usages.id
  -> question_attempts.questionusageid + slot
  -> question_attempts.questionid
  -> question_versions.questionid
```

For ingestion, load the usage with `question_engine::load_questions_usage_by_activity($attempt->uniqueid)`, iterate `get_slots()`, and use the public usage methods `get_question()`, `get_question_fraction()`, `get_question_mark()`, `get_question_max_mark()`, and `get_variant()`. Resolve the version with a DML lookup on `question_versions.questionid`. Store both IDs because regrading can use another version of the same bank entry.

Observe `core\event\question_created` and query `question_versions` by the event's `question.id`. If that row has version greater than one, offer/carry out policy-authorized copying of the immediately preceding approved mappings as drafts. The event itself does not contain the version ID, so the observer must resolve and validate it.

Common Moodle 4.5/5.2 quiz events used to enqueue lightweight work are:

- `mod_quiz\event\attempt_submitted`
- `mod_quiz\event\attempt_regraded`
- `mod_quiz\event\question_manually_graded`
- `mod_quiz\event\attempt_deleted`

Moodle 5.2 also has `attempt_graded` and `attempt_manual_grading_completed`, but they are not part of the 4.5 baseline. A reconciliation task covers pending manual grading and missed/version-specific events. Event observers enqueue a deduplicated task only; they do not calculate.

### Backup and restore

Use Moodle's plugin structure connection points:

- `backup/moodle2/backup_local_outcomemap_plugin.class.php` extends `backup_local_plugin`.
- `backup/moodle2/restore_local_outcomemap_plugin.class.php` extends `restore_local_plugin`.
- The local plugin implements course, section, module, and question connection-point methods only where it owns corresponding rows.
- The companion qbank plugin extends `backup_qbank_plugin` and `restore_qbank_plugin` for qbank-owned UI metadata only. Outcome mappings remain owned and backed up by `local_outcomemap` through the question/local connection point.

Use `get_plugin_element()` and `get_recommended_name()` on backup. Restore paths use `get_pathfor()`, processors use `get_mappingid()`, and insert new records rather than retaining source primary keys. Remap `course`, `course_module`, `course_section`, `question_bank_entry`, `question`, and question-version identities. A restored course-instance association is always draft/unconfirmed until an authorized user confirms it.

Global governed definitions are not duplicated into every course backup. Course backups carry stable UUID/code references and the course-owned mappings needed for restore. On another site, unresolved definitions produce validation warnings and draft mappings rather than guessed links.

### Report Builder

Use supported Report Builder base classes present in both versions:

- Custom accreditation sources extend `core_reportbuilder\datasource`.
- Embedded administrative/course reports extend `core_reportbuilder\system_report`.
- Reusable outcome/result entities extend `core_reportbuilder\local\entities\base`.

Implement source/entity classes using the Moodle 4.5 signatures. In particular, pass entity names as strings to `add_*_from_entity()`; Moodle 5.2 additionally accepts entity objects, but that is not used. Every `system_report::can_view()` performs context and capability checks because AJAX retrieval invokes report access independently of the containing page. Custom sources expose only columns that can be safely filtered by their audience and apply small-cohort suppression in authoritative query/service logic, not only in formatting callbacks.

### Privacy API

`classes/privacy/provider.php` will implement:

- `core_privacy\local\metadata\provider`
- `core_privacy\local\request\plugin\provider`
- `core_privacy\local\request\core_userlist_provider`

Use `get_metadata()`, `get_contexts_for_userid()`, `export_user_data()`, `delete_data_for_all_users_in_context()`, `delete_data_for_user()`, `get_users_in_context()`, and `delete_data_for_users()`.

Governed definitions and frozen institutional snapshots are institutional records, but user-linked evidence, results, recommendations, overrides, and non-anonymised audit payloads are personal data. Deletion follows the retention decision in the privacy implementation: delete or anonymise nonfrozen personal rows; preserve frozen records only where the configured institutional retention basis requires it, replacing direct identifiers with a nonreversible subject reference when legally approved.

## Rejected alternatives

- Editing each activity module's form: rejected because it modifies core/modules and cannot cover third-party modules.
- Using `core_course\hook\after_form_definition`: rejected because it targets course settings, not course-module settings.
- Mapping `question_bank_entries.id` only: rejected because attempts use concrete question versions.
- Using Moodle 5.2-only quiz events as the sole trigger: rejected because Moodle 4.5 is the compatibility floor.
- Direct qbank DML into local tables: rejected because it breaks ownership and public service boundaries.
- Building bespoke paged reports: rejected where Report Builder already supplies supported filtering, paging, export, and access callbacks.

## Verification evidence

Installed Moodle 5.2 files inspected:

- `course/moodleform_mod.php`, `course/modlib.php`
- `question/classes/local/bank/{plugin_features_base,column_base,bulk_action_base,condition}.php`
- core qbank implementations under `question/bank/`
- `lib/db/install.xml`, `mod/quiz/db/install.xml`, and question-engine classes
- `backup/moodle2/{backup,restore}_{local,qbank}_plugin.class.php`
- `reportbuilder/classes/{datasource,system_report}.php`
- Privacy metadata/request interfaces

Moodle 4.5 contracts were checked against the official branch, including [activity form callbacks](https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/course/moodleform_mod.php), [qbank feature registration](https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/question/classes/local/bank/plugin_features_base.php), [question schema](https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/lib/db/install.xml), [Report Builder datasource](https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/reportbuilder/classes/datasource.php), [backup local plugin](https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/backup/moodle2/backup_local_plugin.class.php), and [Privacy API metadata provider](https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/privacy/classes/local/metadata/provider.php).

Content from upstream files is summarized and rephrased for licensing compliance.

## Consequences

Milestones 1–7 may implement against named APIs without another discovery spike unless the supported Moodle range changes. Version-specific enhancements require guarded adapters and tests on both 4.5 and 5.2; core-version conditionals are not permitted in domain services.
