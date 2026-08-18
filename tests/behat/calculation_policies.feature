@local @local_outcomemap @javascript
Feature: Site administrators govern calculation policies
  In order to calculate outcome attainment reproducibly
  As authorized policy staff
  I need to manage policy drafts separately from approval

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | reviewer | Policy    | Reviewer | reviewer@example.com |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | reviewer | manager | System       |           |

  Scenario: A calculation policy is drafted, edited, and independently approved
    Given I log in as "admin"
    And I navigate to "Learning outcome mapping > Calculation policies" in site administration
    And I click on "Add policy" "button"
    And I set the following fields to these values:
      | Name                              | Institution calculation policy |
      | Policy type                       | Calculation and bands           |
      | Scope                             | Institution                     |
      | Minimum distinct assessment items | 2                               |
      | Minimum weighted possible points  | 5                               |
      | Displayed decimal places          | 1                               |
      | Band code 1                       | achieved                        |
      | Band name 1                       | Achieved                        |
      | Minimum percentage 1              | 70                              |
    And I press "Save changes"
    And I click on "Edit" "link" in the "Institution calculation policy" "table_row"
    And I set the field "Name" to "Institution calculation policy 2026"
    And I press "Save changes"
    And I click on "Submit for review" "link" in the "Institution calculation policy 2026" "table_row"
    And I log out
    And I log in as "reviewer"
    And I navigate to "Learning outcome mapping > Approval queue" in site administration
    And I click on "Approve" "link" in the "Institution calculation policy 2026" "table_row"
    And I press "Continue"
    And I navigate to "Learning outcome mapping > Calculation policies" in site administration
    Then I should see "Approved" in the "Institution calculation policy 2026" "table_row"
