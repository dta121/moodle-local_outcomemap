# local_outcomemap operations guide

This guide covers production operation of `local_outcomemap` 0.7.x. The plugin is the system of record for outcome definitions, mappings, policies, evidence, results, remediation, audit history, and accreditation snapshots. The optional `qbank_outcomemap` component provides question-bank presentation and workflows only.

## Supported environment and installation

- Minimum Moodle version: 4.5 (`2024100700`).
- Validation target: Moodle 5.2 with PHP 8.3; plugin source remains PHP 8.1-compatible for Moodle 4.5.
- Install this repository as `<moodleroot>/local/outcomemap`.
- Install the optional companion as `<moodleroot>/question/bank/outcomemap` only after the required `local_outcomemap` version is present.
- Run Moodle's normal upgrade process and cron. Do not copy either repository into Moodle core directories other than its component installation path.

`qbank_outcomemap` is optional for local operation. If it is disabled or absent, existing exact-question-version mappings remain owned by `local_outcomemap` and remain available to approved calculations, evidence lineage, backup/restore, audit, reports, and privacy processing. The outcome column, filters, per-question editor, and qbank bulk action are unavailable until the companion is enabled again.

## Role and capability model

Always combine the local capability with Moodle's capability for the underlying course, activity, question, learner data, or export. Assign capabilities at the narrowest context that supports the user's work.

| Audience | Typical local capabilities | Operational boundary |
| --- | --- | --- |
| Site administrator | All capabilities | Installs/upgrades components, configures governance, scheduled tasks, privacy, backup, and role assignments. |
| Governance manager | `viewdefinitions`, `manageprograms`, `managecatalogcourses`, `manageframeworks`, `managepolicies` | Creates governed definitions and policies. Manager archetypes receive these by default. |
| Instructor or instructional designer | `viewdefinitions`, `mapcourse`, `mapactivities`, optionally `mapquestions`, and `viewallresults` | Views definitions and coverage; drafts course, activity, remediation, or question mappings where the corresponding Moodle capability is also granted. Editing teachers receive these local capabilities by default. |
| Independent reviewer | `viewdefinitions`, `approve`, plus only the read capabilities needed for the reviewed scope | Uses the approval queue. A creator cannot approve the same governed record when independent approval is enabled. A dedicated reviewer account or custom role is recommended. |
| Accreditation reviewer | `managesnapshots`, `exportaccreditation`, and, only when evidence detail is required, `viewallresults` | Creates/reviews frozen snapshots and exports. Evidence-detail export requires the stronger results capability. |
| Student | `viewownresults` | Sees only the user's released outcome-level results and accessible curated recommendations. Students do not receive definition, mapping, policy, evidence-detail, qbank, or accreditation controls. |

`viewdefinitions` is intentionally separate from mutation capabilities. A definition reader may open coverage, content-mapping, and remediation pages, but Add, Edit, Submit, and New version controls are omitted unless the applicable local and Moodle capabilities are both present. Service-layer checks remain authoritative even when a control is hidden.

Review role overrides after changing archetypes. In particular, a course or module `Prevent`/`Prohibit` override can remove inherited access even when a user is an editing teacher or manager.

## Initial administrator setup

1. Record institutional decisions before entering production data: authoritative program/course identifiers, reporting periods, assessed-item rules, explicit weighting method, attempt selection, evidence sufficiency, performance bands, release timing, suppression threshold, population source, retention basis, and snapshot correction policy.
2. In **Site administration > General > Learning outcome mapping**, create programs, catalog courses, program-course memberships, frameworks, and outcome versions.
3. Submit governed drafts. When independent approval is enabled, a different authorized reviewer approves them in **Approval queue**.
4. Associate each Moodle course shell with a stable catalog course and reporting-period code. Submit and confirm the association before it governs mappings or results.
5. Create and approve the required attempt-selection, calculation/band, feedback-release, and accreditation policies. The plugin seeds no institutional thresholds or weights.
6. Configure `local_outcomemap | autocopyquestionmappings` only if new question versions should receive copies of approved previous mappings. Copies are always drafts and require explicit review/finalization.
7. Confirm Moodle cron is running. Quiz events enqueue lightweight recalculation work; scheduled reconciliation repairs missing or stale evidence/results.
8. Grant custom reviewer and accreditation roles only the capabilities and contexts they require.

## Instructor workflow

1. Open **Course outcome mapping > Outcome coverage** to inspect the exact approved outcome versions and mapped course content.
2. Use **Content mappings** to draft section or activity/resource mappings. Select the mapping role deliberately; `teaches`, `practices`, `remediates`, and `alignment_only` do not become assessed evidence merely because a mapping exists.
3. For `assesses`, enter an approved explicit decimal weight. Never infer equal weights for a multi-outcome item.
4. Use **Question mappings** to browse the course's quizzes, drill into the exact question versions each quiz uses, and map outcomes onto them without leaving the course. The page appears only when `qbank_outcomemap` is installed and enabled. Random slots list the pool a draw can select from, so a randomised exam can be mapped question by question. The assessed weight entered there applies to every selected question: a weight splits one question's marks across the outcomes that question assesses, so `1.0000000000` is correct when the outcome is the only one each selected question assesses. Relative influence on an outcome comes from each question's maximum mark in the quiz, not from the mapping weight.
5. Use **Remediation** to curate an approved course module, section, or HTTP(S) URL for an exact outcome version and optional performance band. Learners see only released recommendations that pass current Moodle visibility, availability, group, and access checks.
6. Submit drafts for review. Approved records are immutable; create a new version for later changes.
7. Use **Manual feedback release** only for an effective approved manual-release policy. Release is audited and cannot be reversed.

Question authors use the companion workflow documented in the `qbank_outcomemap` operations guide. Every question mapping binds to `question_versions.id`, not only a question-bank entry.

## Reviewer workflow

1. Use a reviewer account distinct from the creator when independent approval is enabled.
2. Open **Approval queue** and verify the stable object, exact version, effective range, role, explicit assessed weight, and scope before approval.
3. For assessed mappings, confirm the approved weights for the exact question version total exactly `1.0000000000`. Pending or missing weights must not be approved.
4. Verify policy scope and configuration. No pass threshold, population, or release default is supplied implicitly.
5. Verify snapshot population, suppression threshold, policy version, hashes, and creator before freezing. A frozen snapshot is immutable; a correction creates a linked new version with a reason.
6. To report a different figure, correct the snapshot. Delete a version only when the capture should never have been taken — a wrong period, a wrong population, or a capture taken to rehearse the workflow. **Accreditation snapshots** offers deletion for the newest version of a snapshot only, so any remaining correction chain still verifies; it removes every row that version captured and records the deletion in the audit history.

## Seeding examples on an evaluation site

An evaluation or demonstration site can be given one example custom report per governed data source, plus one frozen accreditation snapshot, without hand-building them:

```
php local/outcomemap/cli/seed_examples.php
```

- The examples are built from records the site already holds. The script creates no outcome, mapping, or attainment data.
- Each example report takes its source's own default columns, filters, and sorting, and appears in **Outcome Report Builder sources** against the source it uses.
- The snapshot targets the approved program and reporting period with the most captured course-scope results, unless `--program` and `--period` name one. It is captured and frozen through the ordinary snapshot service, so its payload and manifest hashes verify like any other.
- A capture needs an effective approved accreditation policy. Any policy the institution has already approved is used unchanged. Only when there is none does the script draft one, with an explicit example suppression threshold set by `--mincohortsize`. The plugin itself still supplies no threshold, population, or retention default.
- Seeding is idempotent, and `--reports`, `--snapshot`, and `--draft` narrow what it does. Run `--help` for the full list.
- `--snapshot --replace` reseeds: it deletes every existing snapshot version of the selected program and period, then captures the example again from current results. Use it after the underlying mappings, policies, or results change. The deletions are audited and cannot be undone.

Do not run this on a production site: it writes governed records, and it can delete existing ones.

## Student behavior

Students access **Outcome results** from course navigation only when they hold `viewownresults`. The page:

- shows released outcome-level aggregates, states, scope, calculation summary, and curated recommendations;
- distinguishes not assessed, insufficient evidence, pending, stale, unreleased, and calculated states;
- does not expose protected question text, answers, correctness, response data, or answer keys; and
- does not treat resource access or activity completion as mastery unless a separately approved evidence policy explicitly enables it.

If no effective release policy permits display, results remain unavailable rather than falling back to an implicit release rule.

## Privacy and retention operations

`local_outcomemap` implements Moodle's Privacy API for learner evidence, versioned results, explicit remediation engagement, governance attribution, append-only audit records, and frozen accreditation subject references.

- A user export includes authorized context-scoped learner data, actor-attributed institutional events, learner-owned evidence/result events resolved through live records, and relevant system governance/snapshot information.
- Audit JSON is privacy-minimised before insertion. Evidence/result events retain only non-personal transition fields; scores, attempt identifiers, result keys, hashes, lineage, and direct user-reference keys are excluded. Audit rows, actor attribution, reasons, and correlations are retained as append-only institutional history and are never rewritten by runtime privacy deletion.
- Deletion removes mutable learner evidence, results, and remediation-engagement records. That removal also breaks the subject join used to associate actorless calculation events with a learner. User attribution on mutable governed records is cleared.
- Frozen snapshot rows, canonical hashes, and creator/approver attribution are immutable institutional governance metadata. Creator/approver IDs are included in the attributed user's system export but are not edited during erasure; corrections require a new snapshot version.
- Per-user pseudonymisation key material and its indexed active-user reference are destroyed on erasure, and legacy lookup is permanently blocked. The retained site-keyed marker cannot recreate the old linkage, so the erased user can no longer be resolved to immutable subject rows.
- Both accreditation retention-basis values record the institution's approved legal/records decision. Neither authorizes mutation or physical deletion of a frozen snapshot; the supported privacy action is cryptographic de-linking.
- `qbank_outcomemap` declares a null privacy provider because it owns no personal-data tables.

Use Moodle's standard privacy request and data-registry workflows. Test the institution's approved retention basis before production release; do not manually delete plugin rows or rewrite audit/snapshot payloads. Releases upgrading from `2026072700` perform one explicit, idempotent historical audit-payload minimisation migration; normal application code remains insert-only.

## Backup and restore

Use Moodle course backup/restore plus normal database and moodledata backups.

- Local backup data includes governed course-instance/content/remediation data and exact question-version mappings within the supported backup scope.
- Restored course-instance associations do not become official silently; an authorized user must review/confirm the restored association.
- Restored exact question-version mappings are created as drafts and require review before they can contribute approved evidence.
- The qbank companion owns no schema and contributes no duplicate backup payload.
- Frozen institutional snapshots are site-level governed records, not mutable course-copy data.

After a restore, verify target course/module/section/question-version remapping, course-instance status, effective dates, mapping status, and calculation queues before exposing results.

## Upgrade procedure

There is no supported in-place downgrade. A rollback means restoring the pre-upgrade database, code, and moodledata backup together.

1. Read both repositories' release notes/checklists and confirm the target qbank dependency matches the target local version.
2. Take verified database and moodledata backups and retain the currently deployed plugin code.
3. Complete or pause administrative imports/exports and establish a maintenance window. Stop cron workers while code and schema are changing.
4. Deploy `local_outcomemap` first. Do not deploy a qbank release whose required local version is unavailable.
5. Run Moodle's normal CLI upgrade from the Moodle root, for example:
   ```sh
   php admin/cli/upgrade.php --non-interactive
   ```
6. Deploy/upgrade `qbank_outcomemap`, then rerun the upgrade command if it was not already present during the first pass.
7. Purge Moodle caches, leave maintenance mode, and restart cron workers.
8. Verify plugin versions, scheduled/ad hoc task health, course navigation, approval queue, qbank mapping UI (when installed), Privacy API discovery, backup/restore smoke behavior, and a nonproduction calculation lineage.
9. Record the deployed commit IDs, database backup identifier, operators, timestamps, and smoke-test evidence.

The 0.7.0 upgrade coverage includes reconstruction from the pre-hardening schema, interrupted/schema-ahead recovery, the privacy subject-key migration, and comparison of the upgraded schema with `db/install.xml`.

## Monitoring and troubleshooting

- **Mappings are visible but controls are absent:** inspect both the local capability and corresponding Moodle course/activity/question capability in the current context, including overrides.
- **A question mapping is missing:** confirm the exact question version. Mappings on an older version remain historical and are not silently rebound.
- **A result is stale or pending:** verify cron/ad hoc task execution, completed grading, effective approved mappings/policies, and evidence sufficiency.
- **A learner sees no result:** verify enrolment/context access, `viewownresults`, release policy, grade/quiz visibility, and release time.
- **A remediation link is omitted:** verify recommendation approval/effective dates and Moodle visibility/availability/access for that learner.
- **A snapshot cannot freeze:** verify an effective approved accreditation policy, nonempty governed population, creator/reviewer separation, and integrity hashes.
- **The qbank UI disappears:** verify the companion is installed and enabled, its dependency is satisfied, and the user has `viewdefinitions` plus the relevant qbank capabilities. Local records are not lost while it is absent.

Never repair production state with direct SQL. Use public services, Moodle administration workflows, a forward migration, or a restored backup.
