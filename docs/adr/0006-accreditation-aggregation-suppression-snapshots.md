# ADR 0006: Accreditation aggregation, suppression, and snapshots

- Status: Accepted
- Date: 2026-07-23
- Decision owners: `local_outcomemap` maintainers
- Scope: Milestone 6 accreditation reporting

## Context

Accreditation reports must be reproducible without rewriting historical learner results. The institution has not yet supplied a universal cohort definition, suppression threshold, retention basis, or historical enrolment source. Hardcoding any of those choices would make an apparently official export non-governed.

Learner results already preserve canonical scale-10 numerators, denominators, exact outcome/policy versions, algorithm version, input hash, and evidence lineage. Aggregates and exports must retain those properties.

## Decision

### Governed accreditation policy

Accreditation reporting uses a versioned `accreditation` policy in the existing policy store. It may be scoped to an institution or program. An approved, effective policy is mandatory before a snapshot can be created. The policy explicitly records:

- `mincohortsize`, with no plugin default;
- `populationsource`, either active Moodle enrolments at freeze time or a named Moodle cohort at freeze time;
- `retentionbasis`, either anonymised institutional-record retention or privacy deletion;
- the fixed aggregation method `sum_numerators_denominators`;
- the fixed correction method `new_snapshot_version`.

The plugin fails closed when no policy resolves or required policy data is absent. The policy records a decision made by the institution; it does not pretend that current enrolments reconstruct historical enrolments.

### Aggregate arithmetic

Aggregates sum canonical learner-result numerators and denominators in stable row order using `local_outcomemap\local\decimal`. They never average learner percentages. A percentage is produced only when the summed denominator is non-zero:

`percentage = 100 × sum(numerator) / sum(denominator)`

Course rows group by exact course instance and outcome version. Program rows group by exact outcome version across included course instances. State counts and distinct subject counts remain explicit.

### Suppression

Suppression is evaluated for every aggregate row using its distinct subject count and the approved policy threshold. Suppressed rows retain a suppression marker and population count, but official aggregate exports omit numerator, denominator, percentage, and band. Subject-level evidence is excluded from the normal accreditation package for any suppressed program outcome. An evidence-detail export requires both accreditation-export and all-results capabilities.

Suppression is authoritative in stored snapshot rows and export services, not a presentation callback.

### Snapshot model

`local_outcomemap_snapshot` stores immutable snapshot-version metadata and hashes. `local_outcomemap_snapitem` stores canonical payload rows and per-row hashes for populations, course instances, results, evidence, outcome versions, mapping versions, relationship versions, policy versions, and course/program aggregates.

Subject identifiers in snapshots are non-reversible snapshot-specific SHA-256 references. Raw Moodle user IDs are not stored in snapshot items or exported payloads.

Snapshot creation writes a complete draft version in one transaction. A different authorized reviewer freezes it after hash verification. Once frozen, neither metadata nor items are modified. A correction re-reads authoritative live records into the next version under the same snapshot UUID and requires a reason; it never edits the previous version.

The payload hash covers the ordered item-key/item-hash list. The final manifest hash covers stable snapshot metadata, policy identity, payload hash, item count, creator, approver, and freeze timestamp. Export verifies all hashes before returning data.

### Population limitations

`active_enrolments_at_freeze` uses Moodle's public active-enrolment SQL at the creation timestamp. `moodle_cohort_at_freeze` captures current membership of an explicitly selected cohort. Neither is described as historical population reconstruction. Institutions needing SIS-effective-dated historical populations must add an approved source adapter in a later version.

## Consequences

- Official exports cannot run from mutable live aggregate queries.
- Missing policy decisions stop snapshot creation instead of silently selecting defaults.
- Recalculation and regrading cannot mutate frozen accreditation evidence.
- Percentages can be reconstructed by summing exported weighted evidence/result numerators and denominators for non-suppressed rows.
- Retention implementation can distinguish ordinary personal records from anonymised frozen institutional records.

## Rejected alternatives

- Averaging learner percentages: mathematically incorrect when denominators differ.
- A site setting with a built-in threshold: not versioned, not independently approved, and not reproducible.
- Exporting directly from live tables: historical results change after regrading or policy replacement.
- Editing a frozen row for corrections: destroys the submitted accreditation record.
- Storing raw user IDs in snapshots: unnecessary for reproducibility and increases privacy risk.
