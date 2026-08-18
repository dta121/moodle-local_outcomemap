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
    When I am on the "MBA614" course "Content mappings" outcome page
    Then I should see "Course content mappings"
    And "Apply to selected content" "button" should not exist
    And "Submit for review" "link" should not exist
    When I am on "Strategic Leadership" course homepage
    And I am on the "MBA614" course "Remediation" outcome page
    Then I should see "Outcome remediation"
    And "Add remediation recommendation" "button" should not exist
    And "Submit for review" "button" should not exist

  @javascript
  Scenario: An activity mapping is drafted and independently approved
    Given I log in as "admin"
    And I navigate to "Learning outcome mapping > Curriculum" in site administration
    And I click on "Add catalog course" "link"
    And I set the following fields to these values:
      | Code | MBA614               |
      | Name | Strategic Leadership |
    And I press "Save changes"
    And I click on "Add course instance" "link"
    And I set the following fields to these values:
      | Catalog course       | MBA614               |
      | Moodle course        | MBA614               |
      | Reporting period code | 2026-T1              |
    And I press "Save changes"
    And I click on "Submit for review" "link" in the ".lom-instance-row" "css_element"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "MBA614 / 2026-T1" "table_row"
    And I press "Continue"
    And I log out
    And I log in as "admin"
    And I navigate to "Learning outcome mapping > Outcomes & alignment" in site administration
    And I click on "Add framework" "link"
    And I set the following fields to these values:
      | Code       | MBA614-FW          |
      | Name       | MBA614 outcomes    |
      | Owner type | Institution        |
    And I press "Save changes"
    And I click on "Submit for review" "link" in the ".lom-fwbar" "css_element"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "MBA614-FW" "table_row"
    And I press "Continue"
    And I log out
    And I log in as "admin"
    And I navigate to "Learning outcome mapping > Outcomes & alignment" in site administration
    And I click on "Add outcome" "link"
    And I set the following fields to these values:
      | Framework      | MBA614-FW                         |
      | Code           | CLO1                              |
      | Statement      | Evaluate strategic alternatives. |
      | Effective from | ##1 January 2026 00:00##           |
    And I press "Save changes"
    And I click on "Submit for review" "link" in the ".lom-node[data-search*='clo1']" "css_element"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "CLO1" "table_row"
    And I press "Continue"
    And I log out
    And I log in as "admin"
    And I am on "Strategic Leadership" course homepage
    And I am on the "MBA614" course "Content mappings" outcome page
    And I click on "Evidence workshop" "checkbox"
    And I click on "MBA614-FW.CLO1 v1" "checkbox"
    And I click on "Assesses" "radio"
    And I click on ".lom-map-adv-head" "css_element"
    And I set the field "Contribution weight" to "1"
    And I press "Apply to selected content"
    And I click on "Submit for review" "link" in the ".lom-map-section" "css_element"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "MBA614-FW.CLO1 / assesses" "table_row"
    And I press "Continue"
    Then I should see "The record was approved."
