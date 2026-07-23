# ADR 0003: Deterministic calculation and lineage

- Status: Accepted
- Date: 2026-07-22
- Scope: Calculation behavior implemented from Milestone 4

## Context

Outcome results must be decimal-safe, repeatable, idempotent, policy-driven, and reproducible from frozen inputs. Binary floating point and implicit multi-outcome weights cannot be authoritative.

## Decision

### Decimal representation

Authoritative values use scale 10 decimal strings and XMLDB `NUMBER(20,10)` columns. Domain code uses an immutable decimal value object backed by signed integer strings. It implements parse, compare, add, subtract, multiply, divide, and quantize without PHP float and without requiring optional extensions such as BCMath.

Input normalization:

1. DML decimal values are accepted only as canonical decimal strings.
2. Question-engine source fields (`fraction`, `maxmark`) are read from DML source records at their stored precision, not converted from localized display strings.
3. Weights are canonical scale-10 strings.
4. Scientific notation, locale separators, NaN, and infinity are rejected.
5. Intermediate multiplication retains sufficient guard digits; final stored values are quantized to scale 10.

Rounding mode is half away from zero unless an approved policy explicitly selects another supported mode. Rounding applies only at policy boundaries: stored evidence contributions, stored aggregate values, and displayed percentage. Values are never rounded per display row and then re-summed.

### Direct evidence formula

For each approved `assesses` mapping:

```text
weightedearned   = fraction × maxmark × mappingweight
weightedpossible = maxmark × mappingweight
```

For outcome `o` and selected evidence set `E(o)`:

```text
numerator   = Σ weightedearned
 denominator = Σ weightedpossible
 percentage  = 100 × numerator / denominator
```

A null/ungraded fraction is not zero. It yields pending, excluded, or insufficient status according to the approved policy. Negative marks are retained when Moodle/question behavior permits them; policy may floor the aggregate only through an explicit versioned rule.

### Assessed mapping weights

For each question version, approval requires:

- every assessed weight is greater than zero;
- canonical sum equals `1.0000000000` within an explicitly configured approval tolerance;
- no default is inferred;
- non-assessment mappings carry no evidence weight.

The initial tolerance is exact at scale 10. A university-approved nonzero tolerance must be a versioned policy and approval normalizes only by an explicit, audited action; it never silently changes submitted weights.

### Attempt selection

The effective policy is selected by specificity:

1. assessment;
2. course instance;
3. catalog course;
4. institution.

Within the selected policy, candidates are completed/non-preview attempts unless the policy says otherwise. Deterministic tie breakers always end with `quiz_attempts.id`:

- first completed: `timefinish ASC, id ASC`;
- latest completed: `timefinish DESC, id DESC`;
- highest graded: authoritative grade/mark DESC, `timefinish DESC, id DESC`;
- Moodle quiz-grade-selected: reproduce the quiz `grademethod`, then apply the same stable tie breakers;
- all designated: ordered by `timefinish ASC, id ASC`;
- instructor designated: exact audited attempt ID.

The evidence row records the selected policy version and attempt. Changing a policy marks dependent nonfrozen results stale and queues recalculation.

### Sufficiency and state

Evaluation order is fixed:

1. no eligible direct or inherited evidence -> `not_assessed`;
2. selected evidence still awaiting required grading -> `calculation_pending`;
3. distinct item count or weighted possible points below policy minimum -> `insufficient_evidence`;
4. otherwise calculate percentage and band -> `calculated`.

A zero denominator never produces a percentage. Band boundaries are evaluated against the unrounded scale-10 percentage; display rounding cannot change the authoritative band unless the policy explicitly says banding uses rounded values.

### Propagation

Only approved, effective `contributes_to` relation versions propagate evidence. Traversal uses a deterministic topological order sorted by outcome UUID and relation ID.

Each direct observation receives a stable `lineageuuid`. The propagation accumulator key is:

```text
(lineageuuid, destination item version, result scope, policy version)
```

If several paths reach the same destination, the lineage contributes once. The authoritative path is selected by policy; the baseline rule selects the path with the greatest absolute cumulative contribution weight, then the lexicographically smallest sequence of relation IDs as a deterministic tie breaker. The selected relation path is retained in lineage detail. Direct and inherited evidence remain separately reportable.

When the same question deliberately maps directly to a destination and to a child that propagates there, approval is blocked until policy selects `direct`, `inherited`, or an explicit nonduplicating combination. The baseline is no implicit choice.

Cycle detection runs before approval and propagation also keeps a visited set as corruption defense.

### Idempotency and concurrency

The evidence dedupe key is SHA-256 over canonical fields including source question attempt, question version, mapping version, target item version, policy version, scope, and grading revision timestamp/state. The result input hash is SHA-256 over a sorted canonical list of evidence UUIDs and values plus all definition, relation, mapping, policy, algorithm, and plugin versions.

- Reprocessing the same dedupe key is a no-op.
- Same result key and same input hash returns the existing version.
- Changed input creates/supersedes a nonfrozen result version in one delegated transaction.
- Tasks use Moodle's ad hoc task duplicate suppression where available and database uniqueness as the final guard.
- Frozen snapshot data is never a recalculation target.

### Algorithm version

The first implemented algorithm identifier will be `outcomemap-v1`. Any change to arithmetic order, precision, rounding, selection, sufficiency, propagation path selection, or band evaluation increments this identifier and requires golden-fixture migration decisions.

### Calculation order

1. Resolve approved definitions/mappings/policy at the scope timestamp.
2. Select attempts.
3. Ingest/reconcile atomic question evidence.
4. Validate grading completeness.
5. Create direct weighted contributions.
6. Propagate deduplicated lineages.
7. Aggregate in stable evidence UUID order.
8. Apply sufficiency.
9. Calculate percentage and band.
10. persist evidence/result/audit atomically; then invalidate caches.

## Rejected alternatives

- PHP floats: platform-dependent binary rounding is not authoritative.
- Database-specific decimal expressions as the only calculator: behavior and return types differ across supported databases.
- BCMath requirement: it is an optional PHP extension and not guaranteed by Moodle.
- Equal weights for missing mappings: violates governance requirements.
- Counting every graph path: double-counts one observation.
- Updating frozen values after regrade: breaks accreditation reproducibility.

## Test obligations

Golden tests assert canonical strings for numerators, denominators, percentages, bands, hashes, and lineage paths. Boundary tests cover repeating division, exact band edges, negative values, zero denominator, tolerance boundaries, tie breaking, path duplication, regrade idempotency, and frozen-snapshot isolation.
