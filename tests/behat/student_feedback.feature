@local @local_outcomemap
Feature: Students see governed outcome feedback and accessible remediation
  In order to act on outcome-level feedback without exposing restricted quiz review data
  As a student
  I need results and curated review items only after their governed release time

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | M5        | Student  | student1@example.com |
      | manager1 | M5        | Manager  | manager1@example.com |
    And the following "courses" exist:
      | fullname             | shortname | category | numsections |
      | Strategic Leadership | MBA614    | 0        | 1           |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | MBA614 | student |
      | manager1 | MBA614 | manager |
    And the following "activities" exist:
      | activity | name                | course | idnumber     |
      | quiz     | CLO4 final quiz     | MBA614 | m5quiz       |
      | page     | Unit 2.3            | MBA614 | m5review     |
      | page     | Restricted Unit 4.4 | MBA614 | m5restricted |

  Scenario: A released below-band CLO4 result shows only approved accessible review items
    Given the M5 learner "student1" has completed the mapped quiz in "MBA614" with feedback "released"
    And I log in as "student1"
    And I am on "MBA614" course homepage
    When I click on "Outcome results" "link"
    Then I should see "CLO4"
    And I should see "50.0%"
    And I should see "Below expectations"
    And I should see "Unit 2.3"
    And I should not see "Restricted Unit 4.4"
    And I should not see "Unapproved review item"
    And I should not see "M5_PROTECTED_QUESTION_7f1"
    And I should not see "M5_PROTECTED_RESPONSE_7f1"
    And I should not see "M5_PROTECTED_CORRECTNESS_7f1"
    And I should not see "M5_PROTECTED_ANSWER_KEY_7f1"

  Scenario: A course manager explicitly releases a governed manual policy
    Given the M5 learner "student1" has completed the mapped quiz in "MBA614" with feedback "manual"
    And I log in as "manager1"
    And I am on "MBA614" course homepage
    When I am on the "MBA614" course "Manual feedback release" outcome page
    Then I should see "M5 manual feedback release"
    And I should see "Not manually released"
    When I click on "Release learner feedback now" "link"
    And I press "Continue"
    Then I should see "Learner feedback was manually released."
    And I log out
    And I log in as "student1"
    And I am on "MBA614" course homepage
    And I click on "Outcome results" "link"
    Then I should see "CLO4"
    And I should see "50.0%"
    And I should see "Unit 2.3"

  Scenario: A CLO4 result remains withheld before the governed release time
    Given the M5 learner "student1" has completed the mapped quiz in "MBA614" with feedback "withheld"
    And I log in as "student1"
    And I am on "MBA614" course homepage
    When I click on "Outcome results" "link"
    Then I should see "CLO4"
    And I should see "Not yet released"
    And I should not see "50.0%"
    And I should not see "Unit 2.3"
    And I should not see "Restricted Unit 4.4"
    And I should not see "Unapproved review item"
