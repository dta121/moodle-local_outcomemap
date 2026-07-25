@local @local_outcomemap
Feature: Course staff map content to governed outcome versions
  In order to expose auditable curriculum coverage
  As authorized course staff and reviewers
  I need draft and approval workflows for exact-version content mappings

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | reviewer | Outcome   | Reviewer | reviewer@example.com |
      | readonly | Read      | Only     | readonly@example.com |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | reviewer | manager | System       |           |
    And the following "courses" exist:
      | fullname             | shortname | category | numsections |
      | Strategic Leadership | MBA614    | 0        | 1           |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | readonly | MBA614 | editingteacher |
    And the following "permission overrides" exist:
      | capability                         | permission | role           | contextlevel | reference |
      | local/outcomemap:mapcourse         | Prevent    | editingteacher | Course       | MBA614    |
      | local/outcomemap:mapactivities     | Prevent    | editingteacher | Course       | MBA614    |
    And the following "activities" exist:
      | activity | name              | course | idnumber |
      | page     | Evidence workshop | MBA614 | evidence |

  Scenario: Definition readers do not see mapping mutation controls
    Given I log in as "readonly"
    And I am on "Strategic Leadership" course homepage
    When I click on "Content mappings" "link"
    Then I should see "Course content mappings"
    And "Add content mapping" "button" should not exist
    And "Submit for review" "button" should not exist
    When I am on "Strategic Leadership" course homepage
    And I click on "Remediation" "link"
    Then I should see "Outcome remediation"
    And "Add remediation recommendation" "button" should not exist
    And "Submit for review" "button" should not exist

  Scenario: An activity mapping is drafted and independently approved
    Given I log in as "admin"
    And I navigate to "Plugins > Learning outcome mapping > Catalog courses" in site administration
    And I click on "Add catalog course" "button"
    And I set the following fields to these values:
      | Code | MBA614               |
      | Name | Strategic Leadership |
    And I press "Save changes"
    And I navigate to "Plugins > Learning outcome mapping > Course instances" in site administration
    And I click on "Add course instance" "button"
    And I set the following fields to these values:
      | Catalog course       | MBA614               |
      | Moodle course        | Strategic Leadership |
      | Reporting period code | 2026-T1              |
    And I press "Save changes"
    And I click on "Submit for review" "link" in the "MBA614" "table_row"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Plugins > Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "MBA614 / 2026-T1" "table_row"
    And I press "Continue"
    And I log out
    And I log in as "admin"
    And I navigate to "Plugins > Learning outcome mapping > Frameworks and outcomes" in site administration
    And I click on "Add framework" "button"
    And I set the following fields to these values:
      | Code       | MBA614-FW          |
      | Name       | MBA614 outcomes    |
      | Owner type | Institution        |
    And I press "Save changes"
    And I click on "Submit for review" "link" in the "MBA614-FW" "table_row"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Plugins > Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "MBA614-FW" "table_row"
    And I press "Continue"
    And I log out
    And I log in as "admin"
    And I navigate to "Plugins > Learning outcome mapping > Frameworks and outcomes" in site administration
    And I click on "Add outcome" "button"
    And I set the following fields to these values:
      | Framework      | MBA614-FW                         |
      | Code           | CLO1                              |
      | Statement      | Evaluate strategic alternatives. |
      | Effective from | 1 January 2026, 00:00             |
    And I press "Save changes"
    And I click on "Submit for review" "link" in the "CLO1" "table_row"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Plugins > Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "CLO1" "table_row"
    And I press "Continue"
    And I log out
    And I log in as "admin"
    And I am on "Strategic Leadership" course homepage
    And I click on "Content mappings" "link"
    And I click on "Add content mapping" "button"
    And I set the following fields to these values:
      | Course instance            | 2026-T1                 |
      | Target type                | Course activity or resource |
      | Course activity or resource | Evidence workshop       |
      | Exact outcome version      | MBA614-FW.CLO1 v1       |
      | Mapping role               | Assesses                |
      | Explicit weight            | 1                       |
    And I press "Save changes"
    And I click on "Submit for review" "button" in the "Evidence workshop" "table_row"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Plugins > Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "MBA614-FW.CLO1 / assesses" "table_row"
    And I press "Continue"
    Then I should see "The record was approved."
