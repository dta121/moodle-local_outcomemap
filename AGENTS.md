# Codex instructions

Read `docs/OUTCOME_MAPPING_SPEC.md` completely before implementing or changing this plugin.

## Scope

- This repository is the source for the Moodle component `local_outcomemap`.
- The companion `qbank_outcomemap` repository may depend on public service classes in this plugin.
- Keep outcome definitions, mappings, calculation policies, evidence, results, remediation, audit history, and reporting ownership in this plugin.
- Do not move the system of record into the qbank plugin.

## Local Moodle reference

- Installed Moodle source: `D:\wamp64\www\moodle502\public`
- Target Moodle branch: 5.2 (`2026042000.00`)
- PHP requirement from the installed tree: PHP 8.3 or later.

Inspect the installed Moodle 5.2 source before choosing callbacks, hooks, event classes, question-bank APIs, backup APIs, or Report Builder base classes. Do not invent API names and do not edit Moodle core.

## Working rules

- Implement one specification milestone at a time.
- Keep schema and service changes backward-compatible once a milestone is marked complete.
- Use Moodle DML/XMLDB, contexts, capabilities, forms, output, privacy, task, backup/restore, and testing APIs.
- Keep calculations deterministic, decimal-safe, idempotent, and fully traceable.
- Do not silently assign weights to multi-outcome assessment items.
- Do not treat resource access or activity completion as evidence of mastery unless an approved policy explicitly enables it.
- Do not modify historical approved mappings or frozen snapshots in place.
- Add PHPUnit tests for services and Behat coverage for critical workflows before declaring a milestone complete.
