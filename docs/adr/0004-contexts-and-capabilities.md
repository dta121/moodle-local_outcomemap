# ADR 0004: Context and capability model

- Status: Accepted
- Date: 2026-07-22
- Scope: All UI, service, task, report, import/export, and companion-plugin access

## Context

Institutional definitions are governed centrally, while course mappings/results and question-bank actions belong to Moodle contexts. A capability check detached from object validation can leak definitions, mappings, student results, or protected question information.

## Decision

### Capability definitions

Define these capabilities in `db/access.php`:

| Capability | Context level | Archetype default | Purpose |
|---|---|---|---|
| `local/outcomemap:viewdefinitions` | system, course | manager: allow | View definitions effective in context |
| `local/outcomemap:manageprograms` | system | manager: allow | Create/version/retire programs and memberships |
| `local/outcomemap:managecatalogcourses` | system | manager: allow | Manage catalog courses and course-instance links |
| `local/outcomemap:manageframeworks` | system | manager: allow | Draft/version frameworks and outcomes |
| `local/outcomemap:mapcourse` | course | editingteacher, manager: allow | Link/confirm course instance and map CLO/PLO relationships |
| `local/outcomemap:mapactivities` | course, module | editingteacher, manager: allow | Draft course-module/section/remediation mappings |
| `local/outcomemap:mapquestions` | course, module, question-category context | editingteacher, manager: allow | Draft question-version mappings |
| `local/outcomemap:approve` | system, course, module | manager: allow | Approve governed records within authority |
| `local/outcomemap:viewownresults` | course | student: allow | View own released results |
| `local/outcomemap:viewallresults` | course | editingteacher, manager: allow | View authorized learner/cohort results |
| `local/outcomemap:managepolicies` | system, course | manager: allow | Version calculation/release policies |
| `local/outcomemap:managesnapshots` | system | manager: allow | Create/approve/freeze snapshots |
| `local/outcomemap:exportaccreditation` | system | manager: allow | Export governed reports/snapshots |
| `local/outcomemap:overridecalculations` | course | manager: allow | Create audited overrides/designations |

No capability grants ownership by itself. Services also validate the supplied object belongs to the checked context and is effective/visible there.

### Context resolution

A public `context_resolver` service is the only companion-facing authority for mapping objects to contexts.

- Program, catalog course, institution framework, global outcome, global relation, institutional policy, import/export, and snapshots: `context_system`.
- Course instance, course-level result, course mapping, and course policy: `context_course::instance(moodlecourseid)`.
- Course module mapping: existing records use `context_module::instance(cmid)`; creation uses course context until the module exists, then persistence rechecks module context.
- Course section mapping: course context after validating `course_sections.course`.
- Question mapping: context of the question category resolved through `question_versions -> question_bank_entries -> question_categories.contextid`. If used from a module bank, the actual category context remains authoritative.
- Student evidence/result: course context for access and privacy export; system snapshots may retain a governed copy with stricter snapshot capability.

Never accept a browser-provided context ID as authoritative. Resolve from the target record.

### Combined Moodle capability checks

In addition to plugin capabilities:

- Activity mappings require the relevant course/module edit capability, normally `moodle/course:manageactivities`, at the resolved context.
- Question reads require the applicable Moodle question view/use capability; mutations require the applicable add/edit-all/edit-mine capability according to ownership and context. Use Moodle's question edit context/capability APIs rather than reducing these to one hardcoded capability.
- Viewing all results requires Moodle course/user access checks and must respect enrolment visibility.
- Student own results require matching `$USER->id`, released policy state, activity/grade visibility, availability, and quiz review controls.
- Accreditation export requires both `managesnapshots` or `exportaccreditation` as appropriate and explicit access to every selected scope.

The qbank UI checks both `local/outcomemap:mapquestions` and Moodle's question capability before displaying a column action or processing selected IDs. Public local-plugin services repeat all checks; UI checks are not trusted.

### Approval separation

Approval requires `local/outcomemap:approve` in the governed object's context. By default, the creator cannot approve their own record. A future explicit institutional policy may allow self-approval for designated roles, but it must be audited and is not seeded.

System approvers may approve cross-framework relations. Course approvers can approve course-owned mappings only when every referenced outcome is already approved and visible to that course.

### Service contract

Every public method receives either a resolved domain identifier plus actor ID or a request DTO. It performs, in order:

1. load object and linked records;
2. resolve authoritative context;
3. require plugin capability;
4. require relevant Moodle capability;
5. validate scope/ownership/effective state;
6. execute transactional mutation or return immutable DTO/exporter;
7. append audit and invalidate cache.

Tasks run as system processes without impersonating a capability. They process only already approved, policy-authorized records and record actor null plus a correlation ID. A task cannot turn a draft into approved state.

### Reporting and privacy

Report Builder `can_view()` repeats the context/capability decision for AJAX requests. Query builders receive an allowed-scope set and never fetch globally then hide rows in formatting.

Privacy export/delete methods are authorized by Moodle's Privacy API approved context/user lists, not normal page capabilities. Frozen institutional retention is handled by privacy policy, with direct identifiers minimized or pseudonymised where retention is lawful.

### Cache policy

Cache keys include user ID where output depends on capability, context ID, object/version ID, and effective timestamp bucket. Approval, retirement, context link changes, role/capability changes, and mapping/policy updates invalidate affected entries. Student release/availability checks are not cached beyond Moodle's own access-info lifecycle.

## Rejected alternatives

- System context for every operation: over-privileges instructors and leaks cross-course data.
- Course context for program governance: program/outcome definitions outlive any Moodle shell.
- Trusting qbank UI checks: external callers could bypass them.
- A single `manageall` capability: prevents separation of drafting, approval, results, policy, and accreditation duties.
- Hiding unauthorized Report Builder rows only in callbacks: data remains downloadable/queryable.

## Test obligations

PHPUnit covers resolver behavior, cross-context denial, creator/approver separation, question-category context, and task constraints. Behat covers role-visible navigation and denial paths. Report tests invoke AJAX-style access independently of page setup.
