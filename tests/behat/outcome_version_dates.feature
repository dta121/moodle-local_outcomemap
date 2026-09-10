@local @local_outcomemap
Feature: Administrators correct the effective date of approved outcome versions
  In order to roll up results for assessments sat before the outcomes were recorded
  As authorized governance staff
  I need to move the approved outcome versions of a framework back to the date the curriculum took effect

  Scenario: The effective date of every approved outcome version is corrected as a set
    Given the approved outcomes "CLO1,CLO2" exist
    And I log in as "admin"
    And I navigate to "Learning outcome mapping > Outcomes & alignment" in site administration
    When I click on "Correct effective dates…" "link"
    Then I should see "Correct effective date: All frameworks"
    And I should see "2 approved outcome version(s) currently take effect between"
    # A start that moves nothing is refused before the service is asked.
    When I set the field "Correction reason" to "The curriculum took effect in 2024"
    And I set the field "effectivefrom[year]" to "2030"
    And I press "Correct effective date"
    Then I should see "Choose a date earlier than"
    When I set the field "effectivefrom[year]" to "2024"
    And I press "Correct effective date"
    Then I should see "2 outcome version(s) now take effect from"
    And the approved versions of outcome "CLO1" take effect in year "2024"
    And the approved versions of outcome "CLO2" take effect in year "2024"

  Scenario: A site with no approved outcomes offers nothing to correct
    Given I log in as "admin"
    And I navigate to "Learning outcome mapping > Outcomes & alignment" in site administration
    When I click on "Correct effective dates…" "link"
    Then I should see "There are no approved outcome versions to correct."
