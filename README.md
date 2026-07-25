# Moodle Learning Outcome Mapping

`local_outcomemap` is the system-of-record Moodle plugin for governed learning-outcome definitions, mappings, attainment, remediation, audit, privacy, and accreditation reporting. The optional [`qbank_outcomemap`](https://github.com/dta121/moodle-qbank_outcomemap) companion supplies question-bank presentation and workflows while all governed data remains here.

Current release: **0.7.0 beta** (`2026072701`).

## Compatibility and installation

- Minimum Moodle: 4.5 (`2024100700`)
- Validated source/API target: Moodle 5.2 with PHP 8.3
- Source syntax baseline: PHP 8.1-compatible
- Install path: `<moodleroot>/local/outcomemap`
- Component: `local_outcomemap`

Install and upgrade this plugin before installing a companion qbank release that depends on it. Run Moodle's normal plugin upgrade and cron; do not modify Moodle core.

## Architecture

This plugin owns all programs, catalog courses, course instances, frameworks, exact outcome versions, relationships, content/question/remediation mappings, policies, evidence, deterministic results, audit events, frozen snapshots, reports, backup/restore payloads, and personal-data handling.

Important invariants:

- approved and frozen history is never rewritten in place;
- question mappings bind to exact `question_versions.id` records;
- copied question mappings are drafts requiring explicit review;
- assessed multi-outcome weights are explicit and total exactly `1.0000000000` when approved;
- access/completion is not evidence of mastery without an approved policy;
- calculations are decimal-safe, idempotent, traceable, and preserve frozen snapshots; and
- companion plugins use public service classes rather than plugin tables.

If `qbank_outcomemap` is absent, existing mappings remain stored and calculable; only the qbank column, filters, editor, and bulk action are unavailable.

## Documentation

- [Canonical specification and milestone plan](docs/OUTCOME_MAPPING_SPEC.md)
- [Operations guide](docs/OPERATIONS.md)
- [Release checklist](docs/RELEASE_CHECKLIST.md)
- [Architecture decision records](docs/adr)
- [Companion qbank implementation plan](https://github.com/dta121/moodle-qbank_outcomemap/blob/main/docs/QBANK_IMPLEMENTATION_PLAN.md)

The operations guide covers administrator, instructor, reviewer, accreditation reviewer, and student workflows; capability boundaries; privacy/retention; backup/restore; qbank-absence behavior; upgrades; rollback; and troubleshooting.

## Milestone status

Milestones 0–6 are implemented. Milestone 7 hardening adds Privacy API coverage, immutable-snapshot erasure keys, upgrade/schema reconstruction tests, backup/restore review, fixed-query-budget qbank bulk loading, public filter boundaries, accessibility remediation, role operations guidance, and paired release checklists. Final production approval is governed by the release checklist and must include runnable PHPUnit/Behat evidence from a generated Moodle test environment.
