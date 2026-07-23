# ADR 0005: Plugin ownership and dependency boundaries

- Status: Accepted
- Date: 2026-07-22
- Scope: `local_outcomemap`, companion `qbank_outcomemap`, and optional future integrations

## Context

The suite needs rich question-bank UI without splitting authoritative data or creating circular install/runtime dependencies. The local plugin must remain functional when the qbank companion is absent.

## Decision

### Ownership

`local_outcomemap` exclusively owns:

- academic programs, catalog courses, course instances;
- frameworks, outcomes, versions, and relations;
- all content, section, question-version, and remediation mappings;
- policies, bands, evidence, results, snapshots, and audit;
- calculation, privacy, backup/restore of authoritative rows, reporting, imports, exports, and public service contracts.

`qbank_outcomemap` owns only question-bank presentation/integration code: column, filter, row/bulk actions, forms, renderers, and UI tests. It has no authoritative mapping/evidence/result tables.

### Declared dependencies

`local_outcomemap/version.php` will use:

```php
$plugin->component = 'local_outcomemap';
$plugin->requires = 2024100700; // Moodle 4.5.
```

It declares no dependency on `qbank_outcomemap`.

The companion plugin declares a hard dependency on the first released Milestone 3 service version of `local_outcomemap`. That version number is assigned when Milestone 3 is implemented; the ADR does not invent it early.

Optional future consumers, including `block_outcomeprogress`, depend on the documented public API version, never on tables or internal classes.

### Public API boundary

Public classes live under `local_outcomemap\api` (interfaces/facades), immutable DTOs under `local_outcomemap\local\dto`, and exporters under `local_outcomemap\external`. Companion-safe minimum services are:

- context-scoped outcome search/list;
- bulk get mappings by question-version IDs;
- create/update/delete draft mappings;
- submit mappings for review;
- assessed-weight validation;
- copy previous-version mappings as drafts;
- capability/context resolution.

Public methods return DTOs/exporters, scalar IDs, or explicit result objects. They do not return mutable `stdClass` database rows, delegated transactions, SQL fragments, or Moodle form objects.

Each public API has an explicit semantic version constant. Backward-compatible optional fields/methods are additive. Removing/renaming methods or changing meaning requires a new API major version and a companion migration window.

### Runtime behavior

- `local_outcomemap` never calls qbank classes and remains installable/calculable without the companion.
- `qbank_outcomemap` calls local services after checking plugin availability/version at install time.
- Local event observers, tasks, backup, privacy, calculations, and reports do not depend on qbank UI.
- If the companion is removed, mappings remain stored, approved, backed up, and calculable; only qbank UI disappears.
- Cache definitions belong to the plugin that owns the cached data. The local service invalidates authoritative mapping caches.

### Transactions and events

A local mutation service owns its complete transaction and audit event. The companion must not start a transaction around local service calls. Domain events are emitted only after successful commit or queued through a post-commit-safe mechanism; consumers treat them as notifications, not the source of truth.

### Backup/restore

Authoritative question mappings are backed up/restored by `local_outcomemap` through local/question connection points. The qbank companion may back up only UI-specific preferences if such state is later introduced. Restore calls local services or dedicated restore repositories and never writes local tables from qbank code.

### Failure behavior

Service exceptions use stable local exception/error codes and do not expose SQL. Bulk operations return per-item validation results for preview, then commit all-or-nothing when requested. Missing/outdated local plugin blocks companion installation or disables its UI with an administrator-facing diagnostic; it never falls back to duplicate storage.

## Rejected alternatives

- Circular dependencies: Moodle plugin installation and upgrades become fragile.
- Shared tables owned by qbank: uninstalling UI could destroy the system of record.
- Direct DML from companion: bypasses validation, audit, contexts, and compatibility guarantees.
- Copying outcome labels into qbank records: versions and historical reporting diverge.
- Making qbank mandatory for local calculations: mappings must survive absent UI.

## Consequences

Milestone 1 can publish foundation search/context APIs without installing qbank. Milestone 3 pins the companion to a released local API version. Database changes remain private and can evolve behind backward-compatible DTO/service contracts.
