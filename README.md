# Moodle Learning Outcome Mapping

`local_outcomemap` is the system-of-record plugin for a university learning-outcome mapping, attainment, remediation, and accreditation platform.

The canonical build specification is in [docs/OUTCOME_MAPPING_SPEC.md](docs/OUTCOME_MAPPING_SPEC.md).

The companion question-bank plugin is maintained in the sibling `moodle-qbank_outcomemap` repository and installs as `qbank_outcomemap`.

## Repository and installation locations

- Repository: `D:\wamp64\www\moodle-local_outcomemap`
- Minimum supported Moodle version: 4.5 (`2024100700`)
- Moodle 5.2 validation target: `D:\wamp64\www\moodle502\public\local\outcomemap`
- Component name: `local_outcomemap`

## Milestone status

- Milestone 0 is complete in [`docs/adr`](docs/adr): the selected APIs are compatible with Moodle 4.5 and verified against the installed Moodle 5.2 tree.
- Milestone 1 (governed outcome foundation) and Milestone 2 (course content mapping, remediation, coverage, and backup/restore) are complete with passing PHPUnit coverage.
- Milestone 3 is complete across both repositories: the `local_outcomemap_qmap` table, `question_mapping_service` (draft lifecycle, set-based assessed-weight approval totalling exactly `1.0000000000`, version-copy-as-draft), the public `\local_outcomemap\api\question_mappings` facade and DTO, the `question_created` observer (setting `local_outcomemap | autocopyquestionmappings`), approval-queue integration, question-level backup/restore, and the `qbank_outcomemap` companion UI (column, filters, editor, bulk action).
- Milestone 4 (quiz evidence and calculation) is implemented: the `policy`, `band`, `evidence`, and `result` tables; the float-free signed decimal engine in `\local_outcomemap\local\decimal`; `policy_service` (versioned attempt-selection and calculation policies with bands, canonical config hashes, and assessment → course-instance → catalog-course → institution resolution — no thresholds are seeded); `calculation_service` (algorithm `outcomemap-v1`: DML-sourced evidence with dedupe keys, regrade supersede, `contributes_to` propagation with per-lineage path selection, fixed sufficiency-state order, banding on unrounded percentages, versioned results with input and lineage hashes); quiz event observers feeding a deduplicated ad hoc task plus a scheduled reconciliation task; and golden MBA614-like PHPUnit fixtures asserting canonical numerators, denominators, percentages, bands, and hashes.
- Remaining for Milestone 4 polish: a site-administration CRUD page for policies (they are currently created through `policy_service` and approved through the existing approval queue).
- Milestone 5 (student feedback and remediation display) is next.
