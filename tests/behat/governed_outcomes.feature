@local @local_outcomemap @javascript
Feature: Administrators govern frameworks and outcome versions
  In order to maintain auditable outcome definitions
  As authorized governance staff
  I need separate drafting and approval workflows

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | reviewer | Outcome   | Reviewer | reviewer@example.com |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | reviewer | manager | System       |           |

  Scenario: A framework and outcome are created and independently approved
    Given I log in as "admin"
    And I navigate to "Learning outcome mapping > Outcomes & alignment" in site administration
    And I click on "Add framework" "link"
    And I set the following fields to these values:
      | Code       | MBA614          |
      | Name       | MBA614 outcomes |
      | Owner type | Institution     |
    And I press "Save changes"
    And I click on "Submit for review" "link" in the ".lom-fwbar" "css_element"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "MBA614" "table_row"
    And I press "Continue"
    And I log out
    And I log in as "admin"
    And I navigate to "Learning outcome mapping > Outcomes & alignment" in site administration
    And I click on "Add outcome" "link"
    And I set the following fields to these values:
      | Framework      | MBA614                              |
      | Code           | CLO1                                |
      | Statement      | Evaluate strategic alternatives.    |
      | Effective from | 1 January 2026, 00:00               |
    And I press "Save changes"
    And I click on "Submit for review" "link" in the ".lom-node[data-search*='clo1']" "css_element"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "CLO1" "table_row"
    And I press "Continue"
    Then I should see "The record was approved."
    And I navigate to "Learning outcome mapping > Outcomes & alignment" in site administration
    And I should see "Approved" in the ".lom-node[data-search*='clo1']" "css_element"

  Scenario: An administrator creates a typed program with a generic credential
    Given I log in as "admin"
    And I navigate to "Learning outcome mapping > Curriculum" in site administration
    When I click on "Add program" "link"
    Then I should see "Graduate degree"
    And I should see "Undergraduate degree"
    And I should see "Specialization"
    And I should see "Degree"
    And I should see "Certificate"
    When I click on "Undergraduate degree" "radio"
    And I click on "Certificate" "radio"
    And I set the following fields to these values:
      | Code | UGCERT                              |
      | Name | Undergraduate analytics certificate |
    And I press "Save changes"
    Then I should see "Undergraduate degree programs"
    And I should see "UGCERT"
    And I should see "Certificate"
