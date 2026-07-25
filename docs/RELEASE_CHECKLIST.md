# local_outcomemap 0.7.x release checklist

Use this checklist with the companion [`qbank_outcomemap` release checklist](https://github.com/dta121/moodle-qbank_outcomemap/blob/main/docs/RELEASE_CHECKLIST.md). A release is not production-ready until institutional owners approve the policy, privacy, accessibility, operations, and rollback evidence.

## 1. Release identity and source

- [ ] `version.php` contains the intended component, version, release, maturity, and Moodle minimum.
- [ ] The companion dependency points to this exact or an intentionally compatible local version.
- [ ] The release commit is reviewed, signed/tagged according to institutional policy, and reproducible from the remote branch.
- [ ] No Moodle core files, local secrets, generated test configuration, database dumps, or unrelated review artifacts are packaged.
- [ ] `git diff --check` passes and the release tree contains only intended files.
- [ ] `docs/OUTCOME_MAPPING_SPEC.md`, ADRs, README, and operations guidance match the release behavior.

## 2. Installation and upgrade

- [ ] A clean install creates the same XMLDB schema represented by `db/install.xml`.
- [ ] Upgrade tests pass from every supported historical checkpoint, including `2026072200` and the privacy-key migration checkpoint.
- [ ] Interrupted/schema-ahead upgrade recovery preserves existing data and completes idempotently.
- [ ] Foreign keys, indexes, defaults, precision, nullability, and identifier lengths pass Moodle XMLDB checks on each supported database family.
- [ ] The local plugin upgrades before `qbank_outcomemap`; an incompatible companion version is blocked by Moodle dependency resolution.
- [ ] Pre-upgrade database, moodledata, and code backups are verified and a full rollback rehearsal has been recorded.
- [ ] No in-place downgrade is promised; rollback instructions restore database, code, and moodledata as one consistent set.

## 3. Automated validation

Run in a generated Moodle PHPUnit/Behat environment, not in the source repository alone.

- [ ] Every PHP file passes `php -l` using the minimum supported PHP syntax baseline and the validation PHP version.
- [ ] `xmllint --noout db/install.xml` passes.
- [ ] Moodle coding-style/static checks pass for the component.
- [ ] `vendor/bin/phpunit --testsuite local_outcomemap_testsuite` passes.
- [ ] `php admin/tool/behat/cli/run.php --tags=@local_outcomemap` passes in each supported browser profile.
- [ ] Backup/restore, upgrade, privacy, golden calculation, release-policy, accreditation, and authorization tests pass without retries or order dependencies.
- [ ] The same authoritative calculation inputs reproduce exact numerators, denominators, percentages, bands, input hashes, and lineage hashes.
- [ ] Moodle 4.5 and installed Moodle 5.2 API compatibility checks pass without core edits or deprecation warnings attributable to the plugin.

## 4. Security and authorization

- [ ] Every page and public service enforces the local capability and applicable Moodle context capability.
- [ ] Read-only users can inspect permitted definitions/coverage but cannot see or invoke mutation controls.
- [ ] Creator/reviewer separation is enforced when independent approval is enabled.
- [ ] All mutations use cleaned parameters, sesskeys, Moodle forms or POST buttons, transactions where required, and server-side confirmation for consequential actions.
- [ ] Output escaping, external URL validation, learner access filtering, and restricted quiz-information tests pass.
- [ ] The qbank component has no direct access to `local_outcomemap` tables.

## 5. Privacy, retention, and audit

- [ ] Moodle Privacy API metadata, context/user discovery, export, individual deletion, bulk deletion, and context deletion tests pass.
- [ ] Mutable learner evidence/results/engagement are removed under erasure; mutable governance attribution is cleared; immutable snapshot creator/approver attribution is documented and exported as retained institutional history.
- [ ] Learner evidence/result audit events are discoverable and exportable through live subject ownership before deletion, while insertion-time payload minimisation excludes scores, attempts, learner provenance, hashes, and direct user-reference keys.
- [ ] Runtime privacy deletion leaves append-only audit bytes, actor attribution, reasons, and correlations unchanged; the one-time historical payload migration is independently verified.
- [ ] Per-user pseudonymisation key destruction clears active user discovery and makes immutable snapshot subjects unresolvable without changing frozen rows or canonical hashes.
- [ ] Legacy snapshot lookup blocking is tested.
- [ ] Both retention-basis values are documented as institutional policy decisions whose supported erasure mechanism is cryptographic de-linking, never mutation or physical deletion of frozen rows.
- [ ] Institutional retention, population, correction, and suppression decisions are documented and approved; no implicit defaults are assumed.
- [ ] Audit history remains append-only and consequential operations retain actor/reason/correlation information as designed.

## 6. Backup, restore, and data integrity

- [ ] Course backup/restore remaps course, module, section, question-bank entry, question, and exact question-version identifiers correctly.
- [ ] Restored course associations require authorized confirmation before becoming official.
- [ ] Restored question mappings are drafts and cannot generate approved evidence before review.
- [ ] Frozen snapshots remain byte-for-byte immutable; corrections create linked versions.
- [ ] Snapshot and result integrity verification detects payload, manifest, input, and lineage tampering.
- [ ] The site remains calculable and auditable when `qbank_outcomemap` is disabled or absent.

## 7. Performance and reliability

- [ ] Question-bank pages bulk-load visible mappings; no query is issued once per row.
- [ ] Bulk question actions load core metadata and existing mappings with a fixed query budget for batches up to 1,000.
- [ ] Critical query plans are reviewed against production-like cardinalities on supported databases.
- [ ] Existing indexes are justified by measured predicates/joins; no speculative duplicate index is shipped.
- [ ] Quiz requests enqueue lightweight work; scheduled/ad hoc calculation tasks are deterministic, retry-safe, idempotent, and observable.
- [ ] Regrade/reconciliation tests preserve historical audit and frozen snapshots while updating nonfrozen state.

## 8. Accessibility and usability

- [ ] Keyboard-only users can operate hierarchy toggles, filters, inline editors, confirmations, forms, and tables.
- [ ] Inline panels move focus on open, restore focus on close, and close with Escape.
- [ ] Buttons, labels, fieldsets/legends, headings, table captions, live regions, and accessible names are present and meaningful.
- [ ] Focus indicators, text contrast, and control targets meet WCAG 2.2 AA expectations.
- [ ] Wide tables work at narrow widths and relation/result states use text/shape in addition to color.
- [ ] Screen-reader and automated accessibility checks are recorded for administrator, instructor, reviewer, and student workflows.

## 9. Operational acceptance

- [ ] Administrator, instructor, reviewer, accreditation reviewer, and student guidance has been reviewed by representatives of those audiences.
- [ ] Cron, task failure alerting, backup retention, privacy requests, restore verification, incident escalation, and qbank-absence procedures have named owners.
- [ ] Institutional decisions for assessed evidence, attempts, sufficiency, bands, release, weighting, periods, cohorts, suppression, retention, and authoritative identifiers are signed off.
- [ ] A staging smoke test covers definition governance, course confirmation, content/question mapping, approval, calculation, release, learner display, remediation, snapshot freeze/export, privacy export/erasure, and restore.
- [ ] Production maintenance window, communications, rollback threshold, and post-release monitoring period are approved.

## 10. Release record

Record the following with the completed checklist:

- Local commit/tag and package checksum
- Companion commit/tag and package checksum (or “not installed”)
- Moodle/PHP/database/browser versions
- Source and target plugin versions
- Backup identifiers and restore rehearsal result
- PHPUnit, Behat, static, XMLDB, performance, and accessibility evidence links
- Institutional policy/privacy/security/accessibility approvals
- Deployment start/end times, operators, smoke-test result, and rollback decision
