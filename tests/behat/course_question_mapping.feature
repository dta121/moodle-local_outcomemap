@local @local_outcomemap @requires_qbank_outcomemap
Feature: Course staff map quiz questions to governed outcome versions
  In order to record which questions assess which outcomes
  As authorized course staff
  I need to browse a course's quizzes and map outcomes onto the exact question versions they use

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | readonly | Read      | Only     | readonly@example.com |
    And the following "courses" exist:
      | fullname             | shortname | category |
      | Strategic Leadership | MBA614    | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | readonly | MBA614 | editingteacher |
    And the following "permission overrides" exist:
      | capability                    | permission | role           | contextlevel | reference |
      | local/outcomemap:mapquestions | Prohibit   | editingteacher | Course       | MBA614    |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | MBA614    | Final exam pool |
    And the following "questions" exist:
      | questioncategory | qtype     | name       | questiontext               |
      | Final exam pool  | truefalse | Question 1 | Answer the first question  |
      | Final exam pool  | truefalse | Question 2 | Answer the second question |
    And the following "activities" exist:
      | activity | name       | course | idnumber |
      | quiz     | Final exam | MBA614 | finalexam |
    And quiz "Final exam" contains the following questions:
      | question   | page |
      | Question 1 | 1    |
      | Question 2 | 1    |
    And quiz "Final exam" contains a random question from category "Final exam pool"
    And the governed qbank outcomes "CLO1,CLO2" exist

  # The course navigation container's child links are not present in the non-JS
  # DOM on the course homepage, which is why these scenarios open the page by URL
  # rather than by clicking the nav item. The sibling Content mappings feature has
  # the same limitation.
  Scenario: The question mappings page lists course quizzes and their questions
    Given I log in as "admin"
    When I am on the "MBA614" course question mapping page
    Then I should see "Question outcome mappings"
    And I should see "Final exam"
    When I click on "Final exam" "link"
    Then I should see "Slot 1"
    And I should see "Question 1"
    And I should see "Question 2"
    And I should see "not mapped"
    And I should see "Apply outcomes"
    # The random slot lists the pool a draw can select from, so it can be mapped.
    And I should see "Random from “Final exam pool”"
    And "Export mappings CSV" "link" should exist

  Scenario: An outcome is applied to selected questions and appears as a draft
    Given I log in as "admin"
    And I am on the "MBA614" course question mapping page for quiz "Final exam"
    When I select question "Question 1" for outcome mapping
    And I select question "Question 2" for outcome mapping
    And I select outcome "CLO1" for question mapping
    And I set the field with xpath "//input[@name='role' and @value='assesses']" to "assesses"
    And I set the field "Assessed weight" to "1.0000000000"
    And I press "Apply to selected questions"
    Then I should see "2 mapping(s) created as Assesses"
    And I should see "QB-BEHAT.CLO1"
    And question "Question 1" has a draft "assesses" mapping with notes ""

  Scenario: An assessed mapping without a weight is refused
    Given I log in as "admin"
    And I am on the "MBA614" course question mapping page for quiz "Final exam"
    When I select question "Question 1" for outcome mapping
    And I select outcome "CLO1" for question mapping
    And I set the field with xpath "//input[@name='role' and @value='assesses']" to "assesses"
    And I press "Apply to selected questions"
    Then I should see "Enter an assessed weight before applying an Assesses mapping"

  Scenario: Staff without the mapping capability get a read-only view
    Given I log in as "readonly"
    When I am on the "MBA614" course question mapping page
    Then I should see "Question outcome mappings"
    When I click on "Final exam" "link"
    Then I should see "Question 1"
    And I should see "You do not have permission to map questions in this course"
    And "Apply to selected questions" "button" should not exist

  Scenario: The effective date of a quiz's approved mappings is corrected as a set
    Given question "Question 1" has an approved "assesses" mapping to outcome "CLO1"
    And question "Question 2" has an approved "assesses" mapping to outcome "CLO1"
    And I log in as "admin"
    And I am on the "MBA614" course question mapping page for quiz "Final exam"
    Then I should see "2 approved mapping(s) on 2 question(s) currently take effect from"
    When I click on "Correct effective date…" "link"
    Then I should see "Correct effective date: Final exam"
    And I should see "2 approved mapping(s) on 2 question(s) in this quiz currently take effect between"
    # A start that moves nothing is refused before the service is asked.
    When I set the field "Correction reason" to "Exam went live before it was mapped"
    And I set the field "effectivefrom[year]" to "2030"
    And I press "Correct effective date"
    Then I should see "Choose a date earlier than"
    When I set the field "effectivefrom[year]" to "2024"
    And I press "Correct effective date"
    Then I should see "2 mapping(s) now take effect from"
    And the approved mappings of question "Question 1" take effect in year "2024"
    And the approved mappings of question "Question 2" take effect in year "2024"

  Scenario: A quiz with no approved mappings offers no effective-date correction
    Given I log in as "admin"
    And I am on the "MBA614" course question mapping page for quiz "Final exam"
    Then "Correct effective date…" "link" should not exist

  Scenario: The current mappings of selected questions are replaced as a set
    Given question "Question 1" has an approved "assesses" mapping to outcome "CLO1"
    And question "Question 2" has an approved "assesses" mapping to outcome "CLO1"
    And I log in as "admin"
    And I am on the "MBA614" course question mapping page for quiz "Final exam"
    When I select question "Question 1" for outcome mapping
    And I select question "Question 2" for outcome mapping
    And I select outcome "CLO2" for question mapping
    And I set the field with xpath "//input[@name='role' and @value='assesses']" to "assesses"
    And I set the field "Assessed weight" to "1.0000000000"
    And I set the field "Replace the selected questions’ current mappings" to "1"
    And I press "Apply to selected questions"
    Then I should see "Enter a reason before replacing existing mappings"
    When I select question "Question 1" for outcome mapping
    And I select question "Question 2" for outcome mapping
    And I select outcome "CLO2" for question mapping
    And I set the field with xpath "//input[@name='role' and @value='assesses']" to "assesses"
    And I set the field "Assessed weight" to "1.0000000000"
    And I set the field "Replace the selected questions’ current mappings" to "1"
    And I set the field "Reason for ending the current mappings" to "The exam now assesses CLO2"
    And I press "Apply to selected questions"
    Then I should see "2 mapping(s) ended as of"
    And I should see "2 mapping(s) created as Assesses"
    And I should see "QB-BEHAT.CLO2"
    And the approved mappings of question "Question 1" to outcome "CLO1" have an end date
    And the approved mappings of question "Question 2" to outcome "CLO1" have an end date
