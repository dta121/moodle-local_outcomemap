# Question-mapping CSV import and export

Status: Implementation specification
Primary component: `local_outcomemap` (new service and public facade methods)
Companion component: `qbank_outcomemap` (new bank page and navigation node)
Target Moodle branch: 5.2 (`2026042000.00`), source kept 4.5-compatible

## 1. Problem

`foundation_import_service` imports foundation data only —
`programs`, `courses`, `program_courses`, `course_instances`, `frameworks`, `outcomes`,
`relations` (`classes/local/service/foundation_import_service.php:94-102`). Question-to-outcome
mappings can be created one question at a time (`qbank_outcomemap/edit.php`) or one outcome at a
time across a selection (`qbank_outcomemap/bulk.php`), but there is no way to author a whole
bank's mapping matrix offline, and no way to get the existing matrix out for review.

The target workflow is: export the current mappings for a bank, edit the CSV, re-upload,
preview, commit. That single round trip also solves the "one outcome per pass" limit of the
bulk action, because a CSV row set can attach many different outcomes to many different
questions in one commit.

## 2. Why this is not a new `foundation_import_service` entity

`foundation_import_service` authorises every operation with
`self::require_system('local/outcomemap:manageframeworks')` (`:140`, `:172`, `:214`) — a single
system-context check by a framework manager.

Question mappings are authorised per question context and additionally by core question
capabilities. `question_mapping_service::prepare_bulk()` establishes the required pattern
(`classes/local/service/question_mapping_service.php:928-956`):

```php
require_capability('local/outcomemap:mapquestions', $context);      // per question context
if (!question_has_capability_on($proxy, 'edit')) { ... }            // core question capability
```

Adding a `question_mappings` entity to `foundation_import_service` would let any framework
manager write mappings into any course's question bank with no `mapquestions` and no
`question:edit` check. That is a privilege escalation, so this feature gets its own service
with per-row context authorisation, and its UI lives in the question bank rather than on the
site-admin CSV page.

## 3. New service: `question_mapping_import_service`

`classes/local/service/question_mapping_import_service.php`, extending `base_service`,
mirroring the `load` / `template` / `preview` / `commit` / `cleanup` shape of
`foundation_import_service` so operators meet one import idiom.

### 3.1 Bank scope

Every entry point takes a `$contextid` identifying the bank being imported into, and all
question resolution is confined to that scope:

- module context (`CONTEXT_MODULE`, a `mod_qbank` instance in 5.2) — that context only;
- course context (`CONTEXT_COURSE`) — the course context plus every `mod_qbank` module
  context within the course, so a course-level import spans the course's banks.

Resolve the list once per call and reuse it. `question_mapping_filters::build()` already
accepts an array of bank contexts (`classes/api/question_mappings.php:184`); mirror how the
qbank view supplies them rather than inventing a second scoping rule.

`$contextid` is bound into the preview hash (§3.6) so a preview for one bank can never be
committed against another.

### 3.2 CSV headers

```
questionidnumber, questionname, questioncategory, questionversion,
frameworkcode, outcomecode, outcomeversionuuid,
role, weight, notes, effectivefrom, effectiveto
```

Header matching is exact, as in `foundation_import_service::read_rows()` (`:275-277`).

### 3.3 Question resolution

`question_bank_entries.idnumber` is unique per category (`lib/db/install.xml:1494`,
index `categoryidnumber`) and is the intended stable external key, so it is the primary
identifier. Most existing questions have no idnumber, so a name fallback is supported —
but only when it is unambiguous, never as a guess.

| Field | Rule |
|---|---|
| `questionidnumber` | Entry idnumber. Exactly one of this or `questionname` must be non-empty. |
| `questionname` | `question.name` of the latest version. Must match exactly one entry in scope. |
| `questioncategory` | Optional. `question_categories.idnumber`, then falling back to `name`, to disambiguate. |
| `questionversion` | Optional. Blank resolves the latest version; a value resolves that exact version and must exist. |

Errors: `importrowkey` (neither or both identifiers supplied), `importambiguousquestion`
(more than one match in scope), `recordnotfound` (no match, reusing the existing string).

Resolution is bulk-loaded once for the whole file, not per row — one query over the scoped
contexts returning `questionid`, `questionversionid`, `version`, `name`, `idnumber`,
`createdby`, `contextid`, then matched in PHP. A 500-row import must not issue 500 queries.

### 3.4 Outcome resolution

Exactly one of:

- `outcomeversionuuid` — an exact outcome version, as the bulk action already uses;
- `frameworkcode` + `outcomecode` — resolved to the approved version effective at the row's
  `effectivefrom`.

The code path is what makes a CSV authorable by hand. It resolves within the same owner
scoping as the search API, so an import cannot reach a framework the course has no claim on:
call `outcome_search::require_visible_version()`
(`classes/api/outcome_search.php:84-126`) for the resolved version against each distinct
question context, exactly as `prepare_bulk()` does (`question_mapping_service.php:1023-1034`).

If a code pair resolves to more than one candidate version, raise
`importambiguousoutcome` rather than picking the highest — make the rule explicit instead of
relying on effective ranges never overlapping.

### 3.5 Row validation

Per row, reusing existing validators so import and form paths cannot diverge:

| Field | Rule |
|---|---|
| `role` | Must be in `question_mapping_service::ROLES`, else `invalidmappingrole`. |
| `weight` | Required iff `role === assesses`, canonical via `decimal::require_canonical()`; must be empty otherwise. Delegate to `validate_role_weight()` (`question_mapping_service.php:1512`). |
| `notes` | `input::optional_multiline()`. |
| `effectivefrom` | `parse_date()` semantics — Unix timestamp or `YYYY-MM-DD`; blank defaults to import time. |
| `effectiveto` | `parse_optional_date()`, then `effective_dates::validate($from, $to)`. |

Capabilities per row, against the resolved question's own context, mirroring
`prepare_bulk()` (`:933-946`): `local/outcomemap:mapquestions` plus
`question_has_capability_on($proxy, 'edit')` with a proxy carrying `id`, `contextid`,
`createdby`. A row the operator may not write is a **row error**, not a thrown exception, so
the preview reports precisely which rows are refused instead of failing the whole file
opaquely. Cache the per-context result — a 500-row import over 3 contexts does 3 checks.

Every imported mapping is created as a **draft**. Import never approves, and never writes
`needs_review`; promotion goes through the existing workflow
(`question_mappings::submit_for_review()` / `approve()`, or the bulk action's
submit/finalize operation). This keeps the import outside the approval-governance path
entirely.

### 3.6 Cross-row validation

This is the part that carries the feature's value, and the part a row-at-a-time validator
would get wrong. A legitimate CSV supplies several `assesses` rows for one question that
together total `1.0000000000`, so totals must be evaluated per question version over the
whole file plus existing records — never per row.

1. **Duplicate scope within the file** — key on
   `questionversionid : itemverid : role : effectivefrom`; a repeat is `duplicatecode` on the
   later row, following `unique_seen()` (`foundation_import_service.php:591-596`).
2. **Duplicate against stored mappings** — an existing row with the same
   `questionversionid`, `itemverid` and `role` is `duplicatemapping`, matching the bulk add
   check (`question_mapping_service.php:1062-1066`).
3. **Assessed totals** — group all `assesses` rows by `questionversionid`, combine with the
   stored effective `assesses` records for that version, and evaluate at every effective-range
   boundary. This is exactly the algorithm in `bulk_hypothetical_assessed_total()`
   (`question_mapping_service.php:1248-1335`), including its `decimal::ONE` equality test.

   Extract that method into a reusable internal helper rather than reimplementing it; a second
   copy of the weight-total rule will drift. Report `assessedweighttotalinvalid` against
   **every row in the offending group**, so the operator sees the whole set that has to change
   rather than one arbitrary row.

Row cap: 1000, matching the lock cap in `acquire_bulk_locks()` (`:853`).

### 3.7 Preview, hash, commit

`preview(int $importid, int $contextid): import_preview` returns the existing DTO
(`classes/local/import_preview.php`) with one object per row carrying `number`, `data`,
`errors`, `validationexception` — identical to the foundation preview so `csvimport.php`'s
rendering pattern can be reused verbatim.

Hash:

```php
$hash = hash('sha256', canonical_json::encode([
    'entity' => 'question_mappings',
    'contextid' => $contextid,
    'headers' => self::HEADERS,
    'rows' => $rows,
]));
```

`commit(int $importid, int $contextid, string $expectedhash): int`:

1. Acquire per-question locks for every distinct resolved question ID. Promote
   `acquire_bulk_locks()` (`:850-869`) to an `@internal` public static on
   `question_mapping_service` and call it — deterministic ordering and the 1000 cap come free.
2. Re-run `preview()` inside the lock; reject on `!hash_equals(...)` with `importchanged`,
   and on `!$preview->valid` with `importerrors`, following `foundation_import_service::commit()`
   (`:212-227`).
3. One outer `start_delegated_transaction()`; per row call
   `question_mapping_service::create()`, which writes its own `create` audit entry at the
   question's context.
4. Write one summary audit entry at the bank context:
   `audit_writer::write('import', 'question_mapping_import', null, null, null,
   ['contextid' => $contextid, 'rowcount' => $n, 'previewhash' => $hash], null, $context, $actorid)`.
5. `allow_commit()`, then release locks in reverse order in a `finally`.

No `calculation_service::mark_stale_for_question_version()` call: imported rows are drafts and
drafts contribute no evidence, matching `create()`. Staleness is marked when they are later
approved.

### 3.8 Export

`export(int $contextid): string` emits the same headers with one row per currently effective
mapping in the bank — always writing `outcomeversionuuid` (exact, unambiguous) **and** the
`frameworkcode`/`outcomecode` pair (readable), and always writing `questionidnumber` when the
entry has one, otherwise `questionname`.

An exported file must re-import as a no-op: every row hits the §3.6 duplicate-scope rule, so
re-uploading an unedited export produces an all-rows-rejected preview. Document this — it is
the correct governance outcome, but it will surprise anyone who expects idempotence. The
practical workflow is: export, delete the rows you are not changing, edit the rest, upload.

Export requires `local/outcomemap:viewdefinitions` in the bank context, not `mapquestions` —
reading the matrix is not a write.

## 4. Public facade

`qbank_outcomemap` must not touch the service or the tables directly (`AGENTS.md:8`), so add to
`classes/api/question_mappings.php` and bump `API_VERSION` to `1.2`:

```php
public static function import_template(): string;
public static function import_load(string $content, string $encoding, string $delimiter, int $contextid): int;
public static function import_preview(int $importid, int $contextid): \stdClass;
public static function import_commit(int $importid, int $contextid, string $previewhash): \stdClass;
public static function import_cleanup(int $importid): void;
public static function export_csv(int $contextid): string;
```

`import_preview()` must not leak internals: `import_preview` is a `local\` class and its rows
carry `validationexception` objects. Convert to a plain `\stdClass` with `rows`
(`number`, `data`, `errors` only), `hash` and `valid`, following how `preview_bulk()` strips
`_changes`, `actorid` and `contextid` before returning (`question_mappings.php:53-65`).
Question names must be passed through `format_string()` with the question's context, as
`preview_bulk()` does at `:62`.

## 5. Companion UI — `qbank_outcomemap`

New page `import.php`, alongside `bulk.php`, reusing its `config.php` resolution comment and
`cmid` / `courseid` handling (`bulk.php:25-63`).

Reachable from the bank via a navigation node — core appends the bank's `cmid`/`courseid`
params to the registered URL (`lib/questionlib.php:1547-1548`) and gates the node on
`get_navigation_capabilities()` via `have_one_cap()` (`:1541-1545`):

```php
// classes/navigation.php extends \core_question\local\bank\navigation_node_base
public function get_navigation_key(): string { return 'outcomemapimport'; }
public function get_navigation_url(): \moodle_url {
    return new \moodle_url('/question/bank/outcomemap/import.php');
}
public function get_navigation_capabilities(): ?array {
    return ['local/outcomemap:mapquestions'];
}
```

Register it from `plugin_feature::get_navigation_node()`, returning `null` when
`dependency_available()` is false so the plugin still fails closed without `local_outcomemap`
(`plugin_feature.php:148-154`). Note `get_navigation_node()` receives no view, so the page
itself must re-derive its context from the request and re-check the capability — the node
governs visibility only.

Page flow, mirroring `csvimport.php:36-113`: upload form → preview table → commit form, plus
`?action=template` and `?action=export` download branches (`csvimport.php:26-34`).

Forms: `classes/form/import_form.php` and `classes/form/import_commit_form.php`, modelled on
`local_outcomemap\form\csv_import_form` with the entity selector dropped (there is one entity)
and hidden `cmid`/`courseid` carried through.

## 6. Strings

`local_outcomemap` — reuse `importheader`, `importempty`, `importchanged`, `importerrors`,
`importvalid`, `importinvalid`, `rownumber`, `valid`, `validation`, `duplicatemapping`,
`assessedweighttotalinvalid`, `invalidmappingrole`, `recordnotfound`. Add:

```php
$string['importrowkey'] = 'Provide exactly one of questionidnumber or questionname.';
$string['importoutcomekey'] = 'Provide exactly one of outcomeversionuuid or frameworkcode with outcomecode.';
$string['importambiguousquestion'] = 'More than one question in this bank matches "{$a}". Use questionidnumber, or add questioncategory.';
$string['importambiguousoutcome'] = 'More than one approved outcome version matches "{$a}" on the row\'s effective date.';
$string['importnomapcapability'] = 'You cannot map questions in the bank containing "{$a}".';
```

`qbank_outcomemap` — page title, heading, nav title, template/export link labels, and a
warning that imported mappings are created as drafts awaiting submission.

## 7. Tests

PHPUnit, new `tests/question_mapping_import_service_test.php`:

1. Happy path: three rows, three questions, three different outcomes, one commit; assert
   three drafts with the expected roles and weights.
2. Multi-row assessed split: two `assesses` rows for one question at `0.5000000000` each →
   valid; changing one to `0.6000000000` → both rows carry
   `assessedweighttotalinvalid` and `valid === false`.
3. Existing-record interaction: one `assesses` row of `1.0000000000` against a question that
   already has an effective approved `assesses` mapping → invalid.
4. Outcome resolution by `frameworkcode`/`outcomecode` equals resolution by
   `outcomeversionuuid` for the same target.
5. Owner scoping: a framework not owned by the course, its programs, or the institution is
   rejected for a course-scoped import.
6. Capability isolation: a two-context import where the actor holds `mapquestions` in only one
   → rows in the other are refused, rows in the first still preview valid.
7. `importrowkey`, `importoutcomekey`, `importambiguousquestion`.
8. Duplicate scope within the file, and against a stored mapping.
9. `importchanged` when the stored CSV is altered between preview and commit; and when a valid
   preview's hash is replayed against a different `contextid`.
10. Export round trip: `export_csv()` output re-imports as an all-rows-duplicate preview.
11. Row cap: 1001 rows rejected.

Behat, extending `qbank_outcomemap/tests/behat/question_outcome_mapping.feature`: upload a
two-row CSV from the bank's Import outcome mappings page, confirm the preview, commit, and
assert the Outcome column shows both mappings as drafts.

Per `AGENTS.md:29`, both suites land before the milestone is declared complete.

## 8. Sequencing

| Phase | Deliverable |
|---|---|
| 1 | Extract `bulk_hypothetical_assessed_total()` and `acquire_bulk_locks()` into reusable internal helpers, with tests proving bulk behaviour is unchanged. |
| 2 | `question_mapping_import_service` with `template`/`load`/`preview`/`cleanup` and the full validation set. Service-level tests. |
| 3 | `commit`, locks, transaction, audit. Tests 1-3, 8, 9, 11. |
| 4 | Facade methods, `API_VERSION` 1.2. |
| 5 | `import.php`, forms, navigation node, strings. Behat. |
| 6 | `export_csv()` and the export branch. Test 10. |

Phase 1 is a refactor of code covered by `tests/question_mapping_service_test.php`; run that
suite before and after and require it green with no edits to its assertions. Phases 2-3 are
where the real work sits. No schema change and no upgrade step at any phase — mappings are
written through the existing service into `local_outcomemap_qmap`.

## 9. Known limitation to state in the docs

Import addresses a question **version**. When a question is edited afterwards Moodle creates a
new version, and the imported mappings stay attached to the version they were written
against — the existing copy-forward path (`preview_copy_to_version()` /
`copy_to_version()`, `classes/api/question_mappings.php:316-345`) is how they move. A CSV
re-import is not a substitute for copy-forward, and the `Has copied mappings awaiting
finalization` filter remains the tool for that workflow.
