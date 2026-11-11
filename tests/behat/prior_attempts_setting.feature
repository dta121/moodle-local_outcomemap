@local @local_outcomemap
Feature: Attempts taken before outcomes were mapped count by default
  In order to report attainment for courses that ran before their outcomes were mapped
  As a site administrator
  I need prior attempts to count towards outcomes by default, with the option to turn that off

  Scenario: Counting prior attempts is enabled by default
    Given I log in as "admin"
    When I navigate to "Learning outcome mapping > Settings" in site administration
    Then the field "Count attempts taken before outcomes were mapped" matches value "1"
    And I should see "evaluated against the first approved mappings that came into force afterwards"

  Scenario: An administrator can switch counting prior attempts off
    Given the following config values are set as admin:
      | countpriorattempts | 0 | local_outcomemap |
    And I log in as "admin"
    When I navigate to "Learning outcome mapping > Settings" in site administration
    Then the field "Count attempts taken before outcomes were mapped" matches value "0"
