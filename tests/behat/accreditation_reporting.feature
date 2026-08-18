@local @local_outcomemap @javascript
Feature: Governed accreditation reporting
  In order to provide reproducible accreditation evidence
  As independently authorized reporting staff
  I need to capture, freeze, and export an immutable governed population

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | reviewer | Report    | Reviewer | reviewer@example.com  |
      | learner1 | First     | Learner  | learner1@example.com  |
      | learner2 | Second    | Learner  | learner2@example.com  |
    And the following "courses" exist:
      | fullname                   | shortname       | category |
      | M6 accreditation reporting | M6ACCREDITATION | 0        |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | reviewer | manager | System       |           |
    And the M6 accreditation reporting fixture for "M6ACCREDITATION" contains learners "learner1" and "learner2"

  Scenario: A snapshot creator and independent reviewer complete the export workflow
    Given I log in as "admin"
    And I navigate to "Learning outcome mapping > Accreditation snapshots" in site administration
    And I click on "Create snapshot draft" "link"
    And I set the following fields to these values:
      | Program               | M6-PROGRAM — M6 reporting program                     |
      | Reporting period code | 2026-T1                                                |
      | Moodle cohort         | M6 accreditation cohort [M6-ACCREDITATION]             |
      | Reviewer notes        | Baseline accreditation evidence for independent review |
    When I press "Create snapshot draft"
    Then I should see "The snapshot draft was created."
    And I should see "Draft" in the "M6-PROGRAM" "table_row"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Learning outcome mapping > Accreditation snapshots" in site administration
    And I click on "Freeze snapshot" "link" in the "M6-PROGRAM" "table_row"
    And I press "Continue"
    Then I should see "The snapshot was verified and frozen."
    And I should see "Frozen" in the "M6-PROGRAM" "table_row"
    When I click on "View" "link" in the "M6-PROGRAM" "table_row"
    Then I should see "Population size"
    And I should see "Suppression threshold"
    And I should see "Payload hash"
    And I should see "Manifest hash"
    And "Canonical JSON package" "button" should exist
    And "Summary CSV" "button" should exist
    And "Include de-identified subject evidence" "button" should exist
    And the latest frozen accreditation export for "reviewer" reconstructs "85.0000000000" percent

  Scenario: Authorized staff can discover every governed Report Builder source
    Given I log in as "reviewer"
    When I navigate to "Learning outcome mapping > Outcome reports" in site administration
    Then I should see "Outcome definitions and versions"
    And I should see "Mapping coverage"
    And I should see "Assessment and question coverage"
    And I should see "Student attainment"
    And I should see "Course and cohort aggregates"
    And I should see "Program aggregates"
    And I should see "Remediation recommendations and engagement"
    And I should see "Mapping, calculation, and snapshot audit history"
    And "Open custom reports" "button" should exist
