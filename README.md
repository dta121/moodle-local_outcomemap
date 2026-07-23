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
- Milestone 3 is complete on the system-of-record side: the `local_outcomemap_qmap` table, `question_mapping_service` (draft lifecycle, set-based assessed-weight approval totalling exactly `1.0000000000`, version-copy-as-draft), the public `\local_outcomemap\api\question_mappings` facade and DTO for companion plugins, the `question_created` observer (setting `local_outcomemap | autocopyquestionmappings`), approval-queue integration, and question-level backup/restore. The remaining Milestone 3 work is the `qbank_outcomemap` user interface in the sibling repository.
- Milestone 4 (quiz evidence and calculation) is the next milestone in this repository.
