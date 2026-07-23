# Learning Outcome Mapping, Attainment, and Remediation Plugins

Status: Build specification and implementation plan  
Compatibility: Moodle 4.5 (`2024100700`) through Moodle 5.2 (`2026042000.00`)  
Validation environment: installed Moodle 5.2 and PHP 8.3+; plugin source remains PHP 8.1-compatible for Moodle 4.5  
Primary component: `local_outcomemap`  
Companion component: `qbank_outcomemap`

## 1. Purpose

Build a Moodle extension that lets the university:

1. Maintain governed program, course, and instructional learning outcomes.
2. Map outcomes many-to-many across levels and across programs.
3. Align Moodle activities, resources, course sections, and question versions to one or more outcomes.
4. Calculate student performance by course learning outcome from quiz-question and later other assessment evidence.
5. Give students outcome-level feedback and targeted links to relevant course materials.
6. Produce reproducible, auditable course and program reports for accreditation.

The system must support examples such as:

- A course outcome mapping to an MBA program outcome and an MEI program outcome.
- A quiz question mapping to several instructional or course outcomes.
- A final-exam result showing `CLO1: 85.0%`, `CLO2: 81.4%`, and so on.
- A student who does not meet CLO4 being directed to curated materials such as Unit 2.3, Unit 2.5, Unit 4.1, and Unit 4.4.

## 2. Architectural decision

Implement two installable Moodle plugins.

### 2.1 `local_outcomemap`

This is the system of record and owns:

- Programs and catalog courses.
- Outcome frameworks, stable outcome identities, and outcome versions.
- Cross-framework and cross-program relationships.
- Moodle course-instance associations.
- Activity, resource, course-section, question-version, and remediation mappings.
- Calculation and feedback policies.
- Evidence records, calculated results, frozen snapshots, and audit history.
- Course, student, program, and accreditation user interfaces.
- Report Builder data sources, exports, scheduled tasks, privacy support, and web-service boundaries.

### 2.2 `qbank_outcomemap`

This is a thin question-bank user-interface integration and owns:

- An outcome column in the question bank.
- Outcome filters.
- Per-question and bulk mapping actions.
- Question-version mapping review during version changes.
- Question-bank-specific capability checks and UI tests.

It must call public services in `local_outcomemap`. It must not duplicate outcome, mapping, evidence, or result tables.

### 2.3 Optional future component

An optional `block_outcomeprogress` may later show a compact dashboard card. It is not part of the initial build because the local plugin can provide the student report page and course navigation.

## 3. Definitions

- **Program outcome (PLO/PO):** An outcome governed by a degree or academic program.
- **Course learning outcome (CLO):** An outcome governed by a catalog course.
- **Instructional outcome (ILO/ULO/LO):** A unit, lesson, or topic outcome that may map to one or more CLOs.
- **Alignment:** A relationship showing that content teaches, supports, practices, or assesses an outcome.
- **Direct evidence:** A scored observation intentionally used to calculate an outcome result.
- **Inherited evidence:** Direct evidence propagated once through an approved outcome relationship.
- **Attainment:** A calculated result for a student and outcome within a defined scope and reporting period.
- **Remediation:** Curated material or an activity recommended when a result falls within a configured performance band.
- **Frozen snapshot:** An immutable accreditation result set tied to exact definitions, mappings, policies, and source evidence.

## 4. Guiding rules

1. Alignment does not automatically mean assessment.
2. Viewing a resource does not demonstrate mastery.
3. Activity completion is evidence only when an approved policy explicitly says so.
4. Missing evidence is `not assessed`, not zero.
5. Too little evidence is `insufficient evidence`, not pass or fail.
6. A single evidence record may propagate through the outcome graph but may not be counted twice at the same destination.
7. Historical approved definitions, mappings, policies, results, and snapshots are never silently rewritten.
8. Multi-outcome assessment mappings require explicit weights or an alignment-only role.
9. Student-facing release follows Moodle activity visibility, access restrictions, grade visibility, and quiz review settings.
10. Every reported number must expose its calculation lineage to authorized staff.

## 5. Stable academic structure

Do not equate a Moodle course row with a catalog course. A catalog course such as MBA614 may have multiple Moodle shells across terms and may belong to several programs.

Required logical entities:

- `program`: stable institutional program code and title.
- `catalog_course`: stable catalog code, title, and optional SIS key.
- `course_instance`: Moodle `course.id` linked to a catalog course and reporting period.
- `framework`: an outcome collection owned by the institution, a program, or a catalog course.
- `outcome`: a stable UUID and code whose wording may have several versions.
- `outcome_version`: statement, Bloom level, status, and effective dates.
- `outcome_relation`: a directed, versioned relationship between outcomes.
- `program_course`: many-to-many relationship between programs and catalog courses.

The outcome graph must be acyclic for hierarchical and attainment-propagation relationship types. Cross-framework relationships are allowed.

## 6. Mapping model

### 6.1 Outcome-to-outcome relationships

Support at least:

- `is_child_of`
- `aligns_to`
- `contributes_to`
- `replaced_by`
- `related_to`

Only approved `contributes_to` relationships participate in attainment propagation. They may carry a decimal contribution weight and effective dates.

### 6.2 Moodle content mappings

Mapping targets must support:

- Moodle course module: `course_modules.id`
- Moodle course section: `course_sections.id`
- Moodle question version: `question_versions.id`
- Future rubric criterion or other assessment subcomponent
- External remediation URL when no Moodle object exists

Mapping roles must support:

- `teaches`
- `practices`
- `assesses`
- `remediates`
- `alignment_only`

Mappings contain approval status, effective dates, optional weight, priority, notes, creator, approver, and timestamps.

Internal Moodle destinations must be stored by identifiers rather than copied URLs. Resolve URLs at display time so course moves and URL configuration changes do not invalidate the mapping.

### 6.3 Question version behavior

Question mappings attach to `question_versions.id`, not merely a question-bank entry.

When a new version is created:

1. Offer to copy the previous mappings into draft state.
2. Require a reviewer to confirm or modify them before they can generate approved evidence.
3. Preserve mappings on older versions for attempts that used them.
4. Record both the question version and underlying `question.id` in evidence provenance.

## 7. Measurement model

### 7.1 Evidence unit

For quiz evidence, the atomic observation is a scored question attempt identified by enough provenance to locate:

- User
- Moodle course instance
- Quiz and quiz attempt
- Question usage and slot
- Question and question version
- Raw mark, maximum mark, and fraction
- Mapping version
- Calculation policy version
- Attempt and grading timestamps

Evidence ingestion and recalculation must be idempotent. Regrading supersedes or updates the current evidence state without deleting the historical audit trail.

### 7.2 Direct outcome calculation

For outcome `o` within an assessment scope:

```text
weightedearned(o)  = sum(questionfraction * questionmaxmark * mappingweight)
weightedpossible(o) = sum(questionmaxmark * mappingweight)
percentage(o)       = 100 * weightedearned(o) / weightedpossible(o)
```

Use fixed-precision decimal values for stored marks, weights, and percentages. Do not use binary floating-point values for authoritative calculations.

### 7.3 Multi-outcome questions

For mappings with role `assesses`:

- Require explicit weights greater than zero.
- Require the assessed mapping weights for a question version to total exactly 1.00000 within an approved tolerance.
- Permit additional outcomes with `alignment_only`, `teaches`, or `practices` roles without evidence weight.
- Do not silently default two outcomes to 50/50.
- Imported mappings without weights enter `needs_review` and do not produce approved attainment results.

### 7.4 Outcome propagation

Evidence should normally be recorded at the lowest directly assessed outcome and propagated to CLOs and PLOs through approved `contributes_to` relationships.

Each evidence record receives a stable lineage identifier. The propagation service must ensure that two graph paths do not count the same lineage twice at one destination outcome.

Direct and inherited evidence must remain separately reportable. If a question is deliberately direct evidence for a CLO and an instructional outcome, a policy must define which path is authoritative to prevent duplication.

### 7.5 Attempt-selection policies

Support versioned policies including:

- First completed attempt
- Latest completed attempt
- Highest graded attempt
- Moodle quiz-grade-selected attempt
- All designated attempts
- Instructor-designated summative attempt

The policy may be set at institution, catalog course, course instance, or assessment level, with the most specific approved policy taking precedence.

### 7.6 Evidence sufficiency and bands

A policy defines:

- Minimum number of distinct assessment items
- Minimum weighted possible points
- Percentage rounding and displayed precision
- Performance bands and inclusive/exclusive boundaries
- Whether manual grading must be complete
- Treatment of partially graded or abandoned attempts

Standard result states:

- `not_assessed`
- `insufficient_evidence`
- `calculation_pending`
- `calculated`
- `superseded`
- `frozen`

Do not hardcode institutional pass thresholds. Seed no default threshold unless the university explicitly approves it.

### 7.7 Result scopes

Calculate and label results separately for:

- One quiz attempt
- One assessment after the attempt-selection policy
- Course-to-date
- Final catalog-course reporting period
- Program reporting period

Student pages must clearly identify the scope. A final-exam CLO result must not be presented as the final course CLO result unless the configured course policy says the final exam is the sole evidence source.

## 8. Student feedback and remediation

### 8.1 Student outcome report

The student report must show, for each relevant CLO:

- Outcome code and short statement
- Percentage with policy-defined precision
- Performance band or non-calculated state
- Number of contributing items and weighted possible points
- Assessment or course scope
- Calculation timestamp
- Band-specific feedback
- Curated remediation links when applicable

The report may show a summary table and an expandable details view. It must not reveal protected question text, responses, correctness, or answer keys when Moodle review settings prohibit them.

### 8.2 Remediation mappings

Allow authorized instructors or instructional designers to map an outcome and optional performance band to:

- A course module
- A course section
- An external URL
- A future practice or reassessment activity

Each remediation mapping has:

- Priority and display order
- Student-facing title and explanation
- Optional minimum and maximum percentage
- Required or recommended designation
- Effective dates and approval state
- Course-instance applicability

Return curated recommendations rather than every item carrying the outcome. Filter recommendations through Moodle visibility, availability, group, completion, and access checks before display.

### 8.3 Feedback release

Provide a policy-controlled release time:

- Immediately after a fully graded attempt
- When the Moodle quiz grade becomes visible
- When the quiz closes
- At an instructor-selected timestamp
- Manual release

If the percentage can be released but question review is restricted, show only outcome-level results, feedback, and remediation.

## 9. Governance and accreditation

### 9.1 Workflow states

Definitions, relationships, mappings, policies, and remediation rules use:

- `draft`
- `needs_review`
- `approved`
- `retired`

Only approved records participate in official calculations. Draft calculations may be available to authorized testers and must be visibly labeled nonofficial.

### 9.2 Versioning

- Stable identities use UUIDs.
- Statements and governed properties use effective-dated versions.
- New versions never overwrite historical wording used by prior snapshots.
- Retired outcomes remain resolvable in historical reports.
- Mapping or policy changes mark affected nonfrozen results stale and queue recalculation.

### 9.3 Audit

Record append-only audit events for:

- Creation, editing, approval, retirement, and replacement
- Weight and threshold changes
- Recalculation and regrading
- Manual overrides
- Snapshot creation and release
- Import and export operations

Audit entries include actor, action, object type/id, before/after summary or change payload, reason, timestamp, and request/correlation identifier.

### 9.4 Frozen snapshots

Authorized accreditation staff can freeze a reporting period. A snapshot records:

- Included courses, students, assessments, and evidence IDs
- Outcome and relationship versions
- Mapping and policy versions
- Calculated numerators, denominators, percentages, and bands
- Algorithm version and plugin version
- Creator, approval, timestamp, and explanatory notes

Normal recalculation must never mutate a frozen snapshot. Corrections create a new snapshot version with a documented reason.

## 10. Suggested Moodle database tables

Final XMLDB names must satisfy Moodle identifier limits and be verified with the XMLDB editor. Suggested short names:

- `local_outcomemap_program`
- `local_outcomemap_course`
- `local_outcomemap_cinst`
- `local_outcomemap_progcourse`
- `local_outcomemap_fw`
- `local_outcomemap_item`
- `local_outcomemap_itemver`
- `local_outcomemap_rel`
- `local_outcomemap_cmmap`
- `local_outcomemap_secmap`
- `local_outcomemap_qmap`
- `local_outcomemap_policy`
- `local_outcomemap_band`
- `local_outcomemap_evidence`
- `local_outcomemap_result`
- `local_outcomemap_remed`
- `local_outcomemap_snapshot`
- `local_outcomemap_snapitem`
- `local_outcomemap_audit`

Before implementation, create an entity-relationship ADR specifying every field, index, unique constraint, foreign key strategy, deletion behavior, and context relationship.

Use Moodle IDs for local references and UUIDs/external keys for stable interchange. Avoid hard database foreign keys to core Moodle tables if Moodle plugin conventions or backup/restore behavior make them unsafe; enforce those references in service-layer validation and cleanup tasks.

## 11. Capabilities and contexts

Define granular capabilities, including:

- `local/outcomemap:viewdefinitions`
- `local/outcomemap:manageprograms`
- `local/outcomemap:managecatalogcourses`
- `local/outcomemap:manageframeworks`
- `local/outcomemap:mapcourse`
- `local/outcomemap:mapactivities`
- `local/outcomemap:mapquestions`
- `local/outcomemap:approve`
- `local/outcomemap:viewownresults`
- `local/outcomemap:viewallresults`
- `local/outcomemap:managepolicies`
- `local/outcomemap:managesnapshots`
- `local/outcomemap:exportaccreditation`
- `local/outcomemap:overridecalculations`

Use system context for institutional and program governance, course context for course-instance mappings and results, and the appropriate question-bank context for question mapping. Require both the outcomemap capability and the relevant Moodle question/course capability.

## 12. User interfaces

### 12.1 Site administration

- Programs and catalog courses
- Frameworks and outcome versions
- Outcome relationship graph/table
- Approval work queue
- Calculation and feedback policies
- Reporting periods and snapshots
- Import/export and validation dashboard
- Scheduled-task and stale-result status

### 12.2 Course administration

- Link Moodle course instance to catalog course and reporting period
- Course outcome list
- CLO-to-PLO mapping matrix
- Content/outcome coverage matrix
- Assessment blueprint and evidence coverage
- Remediation editor
- Student and cohort results
- Mapping and result validation warnings

### 12.3 Activity and resource editing

Add a supported Moodle 4.5–5.2 course-module form section with:

- Multi-select outcome picker
- Mapping role
- Optional assessed weight when relevant
- Remediation priority and explanation when role is `remediates`
- Approval state or submission-for-review action

Use the Moodle 4.5-compatible callbacks verified against the installed Moodle 5.2 tree in ADR 0001. Keep callback bridges minimal and delegate to autoloaded services.

### 12.4 Question bank

Implemented by `qbank_outcomemap` as specified in its repository.

### 12.5 Student report

- Course navigation link titled `Outcome results`
- Accessible result table and band indicators that do not rely on color alone
- Expandable calculation summary
- Curated review recommendations
- Optional practice/reassessment call to action

## 13. Calculation processing

### 13.1 Event-driven queue

Do not perform heavy calculations in the request that submits or grades a quiz.

Use the cross-version events verified in ADR 0001 to enqueue an ad hoc recalculation task after relevant attempt, grading, and regrading changes. Keep the installed Moodle 5.2 tree as the validation reference, and do not invent event names.

### 13.2 Reconciliation task

Run a scheduled reconciliation task that:

- Detects graded attempts with missing or stale evidence
- Detects results affected by mapping/policy changes
- Recalculates idempotently
- Records errors without losing prior valid results
- Exposes counts and last-run status to administrators

Essay/manual questions remain `calculation_pending` until grading is complete.

## 14. Reporting

Provide Moodle Report Builder data sources for:

- Outcome definitions and versions
- Course/program mapping coverage
- Assessment/question coverage
- Student outcome attainment
- Course/cohort aggregates
- Program outcome aggregates
- Remediation recommendations and engagement
- Mapping, calculation, and snapshot audit history

Reports must support filtering by program, catalog course, Moodle course instance, reporting period, assessment, cohort, outcome, result state, and performance band.

Protect small cohorts using an institution-configurable suppression rule in program/accreditation aggregate exports. Authorized evidence-detail reports may remain available under a stronger capability.

## 15. Import, export, and interoperability

### 15.1 Initial import

Provide validated CSV import templates for:

- Programs
- Catalog courses and program-course relationships
- Frameworks and outcomes
- Outcome relationships
- Course-instance associations
- Content and remediation mappings
- Question-version mappings

Imports use preview, validation, and explicit commit. Invalid or ambiguous rows must not be partially committed.

### 15.2 Export

Accreditation exports include stable identifiers, version numbers, mapping lineage, weighted numerators/denominators, result states, and snapshot metadata.

### 15.3 CASE readiness

Use UUIDs and explicit association types so a later milestone can implement 1EdTech CASE 1.1 import/export. Full CASE conformance is not required for the MVP.

## 16. Privacy, retention, backup, and restore

- Implement the Moodle Privacy API for all student evidence, results, recommendations, overrides, and relevant audit data.
- Document which governance/audit records are institutional records and which are user personal data.
- Apply configurable retention rules without deleting frozen accreditation records contrary to institutional policy.
- Implement course backup/restore for course-instance mappings, course-module/section mappings, policies, and permitted result data.
- Remap course-module, section, question-bank-entry, question-version, and course IDs during restore.
- Prevent restored test courses from being mistaken for official catalog-course instances until an authorized user confirms the association.
- Define behavior when `qbank_outcomemap` is absent: mappings remain stored and calculable, but qbank UI features are unavailable.

## 17. Security and reliability

- Validate context and capability on every read and mutation.
- Use Moodle forms, sesskey protection, parameter cleaning, and DML APIs.
- Never trust question IDs, course IDs, or outcome IDs received from the browser.
- Filter remediation through current Moodle access and availability checks.
- Escape all output through Moodle output/rendering APIs.
- Make mutation services transactional and calculation jobs retry-safe.
- Avoid N+1 queries in question-bank and report views.
- Cache governed definitions and mappings with correct invalidation after changes.
- Support MariaDB/MySQL and PostgreSQL-compatible Moodle DML.

## 18. Public service boundary

Expose documented service classes for companion plugins. At minimum:

- Outcome search/list service scoped to a context
- Get mappings for question versions in bulk
- Create/update/delete draft mappings
- Submit mappings for approval
- Validate assessed weights
- Copy mappings to a new question version as draft
- Capability and context resolver

Do not allow the qbank plugin to write tables directly. Public service methods return stable DTOs or exporters rather than leaking internal database records.

## 19. Testing requirements

### 19.1 PHPUnit

- Definition and effective-date services
- Cycle detection in outcome relationships
- Multi-program mappings
- Mapping approval and versioning
- Question assessed-weight validation
- Attempt-selection policies
- Decimal calculation and rounding boundaries
- Evidence lineage deduplication
- Regrade and idempotency behavior
- Minimum-evidence states
- Remediation selection and access filtering
- Frozen snapshot immutability
- Privacy provider
- Backup/restore mappings
- Report Builder data sources

### 19.2 Behat

- Administrator creates and approves frameworks/outcomes
- Instructor maps activities/resources
- Question author maps one and several outcomes
- Reviewer approves question mappings
- Student completes a quiz and sees released CLO results
- Student below a band sees allowed remediation links
- Protected quiz information remains hidden
- Instructor views course coverage and cohort results
- Accreditation user creates and exports a frozen snapshot

### 19.3 Golden calculation fixtures

Create fixed fixtures based on MBA614-like cases:

1. Six CLOs with questions mapped across them.
2. At least one question with several alignment outcomes and explicit assessed weights.
3. One CLO mapping to outcomes in two programs.
4. A student result that produces percentages such as 85.0%, 81.4%, and 90.0%.
5. CLO4 below threshold with remediation targets Unit 2.3, Unit 2.5, Unit 4.1, and Unit 4.4.
6. A CLO with too little evidence returning `insufficient_evidence`.
7. A regrade changing a nonfrozen result while leaving a prior frozen snapshot unchanged.

Keep expected numerators, denominators, weights, percentages, and bands in the fixture so tests prove the calculation, not merely the displayed result.

## 20. Performance targets

- Question-bank outcome columns must bulk-load mappings for the visible page.
- Attempt submission must only enqueue lightweight work.
- Recalculation must be chunked and resumable.
- Course reports must page and filter through Report Builder.
- Program aggregation should use stored authoritative results or snapshots rather than recalculating every question attempt during page load.
- Define indexes from measured query plans before production release.

## 21. Accessibility and usability

- Meet Moodle accessibility conventions and WCAG 2.2 AA expectations.
- Do not communicate performance bands by color alone.
- Provide meaningful link text for remediation.
- Ensure tables work at narrow widths and with keyboard navigation.
- Use Moodle notifications and confirmation dialogs for consequential actions.
- Clearly label draft, unofficial, stale, pending, and frozen results.

## 22. Non-goals for the MVP

- Replacing Moodle gradebook
- Creating a new activity module or question type
- Predictive analytics or AI-generated remediation
- Automatic inference of outcome mappings from content
- Automatic assignment of multi-outcome weights
- Full 1EdTech CASE certification
- A separate mobile app
- Program-level degree audit or transcript functionality

## 23. Implementation milestones

### Milestone 0: Moodle 4.5–5.2 API spike and ADRs

Deliver:

- Verified course-module form extension mechanism
- Verified qbank column, filter, and bulk-action APIs
- Verified question-version and attempt provenance model
- Verified backup/restore extension points for local and qbank data
- Verified Report Builder and privacy integration approach
- [API compatibility ADR](adr/0001-moodle-api-compatibility.md), [schema ADR](adr/0002-entity-schema.md), [calculation ADR](adr/0003-deterministic-calculation.md), [context/capability ADR](adr/0004-contexts-and-capabilities.md), and [plugin-dependency ADR](adr/0005-plugin-dependencies.md)

Exit criterion: no unresolved dependency on invented or deprecated APIs.

### Milestone 1: Governed outcome foundation

Deliver:

- Installable `local_outcomemap`
- Programs, catalog courses, course instances, frameworks, outcomes, versions, and relations
- Capabilities, administration pages, validation, audit, and PHPUnit coverage
- CSV preview/import for the foundation entities

Exit criterion: administrators can model the MBA614 hierarchy and cross-program relationships without assessment data.

### Milestone 2: Course content mapping

Deliver:

- Course-instance association
- Activity/resource and section mapping UI
- Course coverage matrix
- Remediation mapping editor
- Backup/restore coverage

Exit criterion: authorized staff can map and restore course content without changing Moodle core.

### Milestone 3: Question-bank mapping

Deliver:

- Installable `qbank_outcomemap`
- Question-bank column, filter, per-question action, and bulk mapping
- Version-copy-as-draft workflow
- Approval and weight validation

Exit criterion: multi-outcome question mappings are efficient, governed, and tied to exact question versions.

### Milestone 4: Quiz evidence and calculation

Deliver:

- Evidence ingestion queue and reconciliation task
- Versioned calculation policies
- Attempt, assessment, and course-scope CLO results
- Regrade and stale-result handling
- Golden calculation fixtures

Exit criterion: repeat calculation from the same inputs produces exactly the same numerator, denominator, percentage, band, and lineage.

### Milestone 5: Student feedback and remediation

Deliver:

- Student outcome-results page
- Feedback bands and release controls
- Curated remediation links with access filtering
- Protection of restricted quiz details
- Relevant Behat coverage

Exit criterion: a student below CLO4 sees only the approved, accessible review items at the allowed release time.

### Milestone 6: Accreditation reporting

Deliver:

- Report Builder data sources
- Course/program aggregate reports
- Small-cohort suppression
- Frozen snapshot creation, versioning, and export
- Complete audit lineage

Exit criterion: an authorized reviewer can reproduce a reported percentage from the exported evidence and version metadata.

### Milestone 7: Hardening and release

Deliver:

- Privacy validation
- Full backup/restore and upgrade testing
- Performance profiling and indexes
- Accessibility review
- Administrator, instructor, reviewer, and student documentation
- Upgrade path and release checklist for both plugins

Exit criterion: production-readiness checklist approved by the university.

## 24. Open institutional decisions

Resolve and record these before Milestone 4:

1. Which assessments count toward final course attainment?
2. Which quiz attempt policy applies by default?
3. What minimum item/point evidence is sufficient?
4. What performance bands and thresholds are approved?
5. How are multi-outcome question weights established and reviewed?
6. Are PLO results derived through CLO mappings, directly assessed, or both under separate measures?
7. How many decimal places are stored, reported, and displayed?
8. When may students see outcome results and remediation?
9. Are remediation activities optional, required, or eligible for reassessment?
10. What academic periods and cohorts define accreditation snapshots?
11. What small-cohort suppression threshold applies?
12. What retention and correction policies apply to evidence and snapshots?
13. What system is authoritative for program, catalog-course, term, and enrollment identifiers?

## 25. Definition of done

The plugin suite is complete only when:

- All requested many-to-many mappings are represented without text parsing.
- Question mappings are version-specific and approved.
- Activity/resource mappings distinguish teaching, practice, assessment, and remediation.
- Student CLO percentages are deterministic and traceable.
- Insufficient evidence is not misreported as failure.
- Multi-path propagation does not double-count evidence.
- Regrades update current results and preserve frozen snapshots.
- Student feedback respects Moodle release and review controls.
- Remediation links are curated and access-checked.
- Course and program reports expose complete version lineage.
- Privacy, backup/restore, security, accessibility, and automated tests pass.
- No Moodle core files are modified.
