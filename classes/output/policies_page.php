<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Outcome policies page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\service\policy_service;
use local_outcomemap\local\workflow;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * Present outcome policies as the decisions they settle, not as a list of rows.
 *
 * A policy list answers "what has been configured". The question an administrator
 * actually has is "which rule is in force here, and where is one missing" — and
 * that is invisible in a flat table, because a scope with no policy of its own
 * has no row at all. Grouping by decision makes the absent rule as visible as the
 * present one, and each group states the precedence its own type resolves by.
 */
final class policies_page implements renderable, templatable {
    /**
     * Group the decisions and their scopes.
     */
    public const VIEW_DECISION = 'decision';

    /**
     * Group the scopes and the decisions each settles.
     */
    public const VIEW_SCOPE = 'scope';

    /**
     * The scope chain each policy type is actually resolved through.
     *
     * Accreditation is resolved by suppression_service, which walks program then
     * institution. Everything else is resolved by policy_service, which walks
     * assessment, course instance, catalog course, then institution and never
     * consults a program. Each group states its own chain, because which scopes
     * can settle a decision differs by decision.
     */
    private const RESOLUTION = [
        policy_service::TYPE_ACCREDITATION => [
            policy_service::SCOPE_PROGRAM,
            policy_service::SCOPE_INSTITUTION,
        ],
    ];

    /**
     * @var \stdClass[] Every policy version with decoded config and bands.
     */
    private array $policies;

    /**
     * @var string The grouping being rendered.
     */
    private string $view;

    /**
     * @var array<string, array<int, string>> Scope labels keyed by scope type and id.
     */
    private array $scopelabels = [];

    /**
     * @var \stdClass[] Catalog courses keyed by id.
     */
    private array $courses;

    /**
     * @var int Reference time for effective-range comparisons.
     */
    private int $now;

    /**
     * Load every policy once.
     *
     * @param string $view Grouping to render.
     * @param int|null $now Reference time, for deterministic tests.
     */
    public function __construct(string $view = self::VIEW_DECISION, ?int $now = null) {
        global $DB;
        $this->view = $view === self::VIEW_SCOPE ? self::VIEW_SCOPE : self::VIEW_DECISION;
        $this->policies = policy_service::list_all();
        $this->now = $now ?? time();
        $this->courses = $DB->get_records('local_outcomemap_course', null, 'code ASC');
        $this->scopelabels = $this->scope_labels();
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output Output.
     */
    public function export_for_template(renderer_base $output): array {
        $baseurl = new moodle_url('/local/outcomemap/policies.php');
        $isscope = $this->view === self::VIEW_SCOPE;
        $gaps = $this->undefaulted();

        return [
            'groups' => $isscope ? $this->by_scope($baseurl) : $this->by_decision($baseurl),
            'haspolicies' => $this->policies !== [],
            'isscope' => $isscope,
            'viewtabs' => [
                [
                    'label' => get_string('policies_bydecision', 'local_outcomemap'),
                    'active' => !$isscope,
                    'url' => (new moodle_url($baseurl, ['view' => self::VIEW_DECISION]))->out(false),
                ],
                [
                    'label' => get_string('policies_byscope', 'local_outcomemap'),
                    'active' => $isscope,
                    'url' => (new moodle_url($baseurl, ['view' => self::VIEW_SCOPE]))->out(false),
                ],
            ],
            'addurl' => (new moodle_url($baseurl, ['action' => 'add']))->out(false),
            'hasgaps' => $gaps !== [],
            'gapcount' => count($gaps),
            'gapline' => get_string(
                count($gaps) === 1 ? 'policies_gap_one' : 'policies_gap',
                'local_outcomemap',
                implode(', ', $gaps)
            ),
            'statsline' => $this->statsline(),
            'helpline' => get_string('policies_precedence_help', 'local_outcomemap'),
            'introline' => get_string(
                workflow::requires_independent_approval() ? 'policies_intro' : 'policies_intro_finalization',
                'local_outcomemap'
            ),
        ];
    }

    /**
     * Name the decisions that have no approved institution-wide default.
     *
     * @return string[] Decision titles.
     */
    private function undefaulted(): array {
        $missing = [];
        foreach (policy_service::TYPES as $type) {
            if ($this->institution_default($type) === null) {
                $missing[] = get_string('policytype_' . $type, 'local_outcomemap');
            }
        }
        return $missing;
    }

    /**
     * Return the approved institution-wide policy of one type, if there is one.
     *
     * @param string $type Policy type.
     * @return \stdClass|null
     */
    private function institution_default(string $type): ?\stdClass {
        foreach ($this->policies as $policy) {
            if (
                $policy->policytype === $type
                    && $policy->scopetype === policy_service::SCOPE_INSTITUTION
                    && $this->in_force($policy)
            ) {
                return $policy;
            }
        }
        return null;
    }

    /**
     * Whether a policy version is approved and inside its effective range.
     *
     * @param \stdClass $policy Policy version.
     * @return bool
     */
    private function in_force(\stdClass $policy): bool {
        return $policy->status === workflow::APPROVED
            && (int) $policy->effectivefrom <= $this->now
            && ($policy->effectiveto === null || (int) $policy->effectiveto > $this->now);
    }

    /**
     * Summarise the policy estate.
     *
     * @return string
     */
    private function statsline(): string {
        $inforce = 0;
        $drafts = 0;
        foreach ($this->policies as $policy) {
            if ($this->in_force($policy)) {
                $inforce++;
            }
            if ($policy->status === workflow::DRAFT) {
                $drafts++;
            }
        }
        return get_string('policies_statsline', 'local_outcomemap', (object) [
            'versions' => count($this->policies),
            'inforce' => $inforce,
            'draft' => $drafts,
            'decisions' => count(policy_service::TYPES),
        ]);
    }

    /**
     * Group the policies under the decision each one settles.
     *
     * @param moodle_url $baseurl Policies page URL.
     * @return array[] Decision groups.
     */
    private function by_decision(moodle_url $baseurl): array {
        $groups = [];
        foreach (policy_service::TYPES as $type) {
            $rows = [];
            foreach ($this->sorted_for($type) as $policy) {
                $rows[] = $this->row($policy, $baseurl, $this->scope_badge($policy));
            }
            $default = $this->institution_default($type);
            $uncovered = $this->uncovered_courses($type);
            $groups[] = [
                'title' => get_string('policytype_' . $type, 'local_outcomemap'),
                'question' => get_string('policies_question_' . $type, 'local_outcomemap'),
                'badge' => $default === null
                    ? get_string('policies_nodefault', 'local_outcomemap')
                    : get_string('policies_hasdefault', 'local_outcomemap'),
                'badgewarn' => $default === null,
                'rows' => $rows,
                'hasrows' => $rows !== [],
                'emptyline' => get_string('policies_nopolicies', 'local_outcomemap'),
                'chainline' => get_string(
                    'policies_chain',
                    'local_outcomemap',
                    implode(' → ', array_map(
                        fn($scope) => get_string('policyscope_' . $scope, 'local_outcomemap'),
                        self::RESOLUTION[$type] ?? policy_service::SCOPE_PRECEDENCE
                    ))
                ),
                'hasuncovered' => $uncovered !== [],
                'uncoveredline' => get_string(
                    count($uncovered) === 1 ? 'policies_uncovered_one' : 'policies_uncovered',
                    'local_outcomemap',
                    implode(', ', $uncovered)
                ),
                'searchtext' => \core_text::strtolower(
                    get_string('policytype_' . $type, 'local_outcomemap')
                ),
            ];
        }
        return $groups;
    }

    /**
     * Group the policies under the scope they are attached to.
     *
     * @param moodle_url $baseurl Policies page URL.
     * @return array[] Scope groups.
     */
    private function by_scope(moodle_url $baseurl): array {
        $order = array_flip(array_merge(
            [policy_service::SCOPE_INSTITUTION, policy_service::SCOPE_PROGRAM],
            policy_service::SCOPE_PRECEDENCE
        ));
        $buckets = [];
        foreach ($this->policies as $policy) {
            $key = $policy->scopetype . ':' . (int) $policy->scopeid;
            $buckets[$key]['scopetype'] = $policy->scopetype;
            $buckets[$key]['label'] = $this->scope_name($policy);
            $buckets[$key]['policies'][] = $policy;
        }
        uasort($buckets, function ($a, $b) use ($order) {
            $delta = ($order[$a['scopetype']] ?? 99) <=> ($order[$b['scopetype']] ?? 99);
            return $delta !== 0 ? $delta : strnatcasecmp($a['label'], $b['label']);
        });

        $groups = [];
        foreach ($buckets as $bucket) {
            $rows = [];
            $settled = [];
            foreach ($bucket['policies'] as $policy) {
                $rows[] = $this->row($policy, $baseurl, [
                    'kind' => get_string('policytype_' . $policy->policytype, 'local_outcomemap'),
                    'name' => '',
                    'kindclass' => 'decision',
                ]);
                if ($this->in_force($policy)) {
                    $settled[$policy->policytype] = true;
                }
            }
            $missing = [];
            foreach (policy_service::TYPES as $type) {
                if (!isset($settled[$type])) {
                    $missing[] = get_string('policytype_' . $type, 'local_outcomemap');
                }
            }
            $groups[] = [
                'title' => $bucket['label'],
                'question' => get_string('policyscopedesc_' . $bucket['scopetype'], 'local_outcomemap'),
                'badge' => $missing === []
                    ? get_string('policies_coversall', 'local_outcomemap', count(policy_service::TYPES))
                    : get_string('policies_coverssome', 'local_outcomemap', (object) [
                        'settled' => count(policy_service::TYPES) - count($missing),
                        'total' => count(policy_service::TYPES),
                    ]),
                'badgewarn' => $missing !== [],
                'rows' => $rows,
                'hasrows' => $rows !== [],
                'hasuncovered' => $missing !== [],
                'uncoveredline' => get_string(
                    'policies_scopemissing',
                    'local_outcomemap',
                    implode(', ', $missing)
                ),
                'searchtext' => \core_text::strtolower($bucket['label']),
            ];
        }
        return $groups;
    }

    /**
     * Order one type's policies by the precedence it resolves through.
     *
     * @param string $type Policy type.
     * @return \stdClass[]
     */
    private function sorted_for(string $type): array {
        $chain = self::RESOLUTION[$type] ?? policy_service::SCOPE_PRECEDENCE;
        $order = array_flip($chain);
        $rows = array_values(array_filter(
            $this->policies,
            static fn($policy) => $policy->policytype === $type
        ));
        usort($rows, function ($a, $b) use ($order) {
            $delta = ($order[$a->scopetype] ?? 99) <=> ($order[$b->scopetype] ?? 99);
            if ($delta !== 0) {
                return $delta;
            }
            $delta = strnatcasecmp($this->scope_name($a), $this->scope_name($b));
            return $delta !== 0 ? $delta : (int) $b->version <=> (int) $a->version;
        });
        return $rows;
    }

    /**
     * Build one policy row.
     *
     * @param \stdClass $policy Policy version.
     * @param moodle_url $baseurl Policies page URL.
     * @param array $badge Leading badge with kind, name, and kindclass.
     * @return array Template row context.
     */
    private function row(\stdClass $policy, moodle_url $baseurl, array $badge): array {
        $id = (int) $policy->id;
        $row = $badge + [
            'settings' => $this->settings($policy),
            'meta' => get_string('policies_meta', 'local_outcomemap', (object) [
                'version' => (int) $policy->version,
                'from' => userdate(
                    (int) $policy->effectivefrom,
                    get_string('strftimedate', 'core_langconfig')
                ),
            ]),
            'statuslabel' => workflow::status_label($policy->status),
            'statusclass' => $this->status_class($policy),
            'viewurl' => (new moodle_url($baseurl, ['action' => 'view', 'id' => $id]))->out(false),
            'candraft' => false,
            'canversion' => false,
            'searchtext' => '',
        ];
        $row['searchtext'] = \core_text::strtolower(implode(' ', array_merge([
            $policy->name,
            $badge['kind'],
            $badge['name'],
            $row['statuslabel'],
        ], array_column($row['settings'], 'v'))));
        if ($policy->status === workflow::DRAFT) {
            $row['candraft'] = true;
            $row['editurl'] = (new moodle_url($baseurl, ['action' => 'edit', 'id' => $id]))->out(false);
            $row['submitlabel'] = workflow::submit_action_label();
            $row['submiturl'] = (new moodle_url($baseurl, [
                'action' => 'submit',
                'id' => $id,
                'sesskey' => sesskey(),
            ]))->out(false);
            $row['deleteurl'] = (new moodle_url($baseurl, ['action' => 'delete', 'id' => $id]))->out(false);
        } else if ($policy->status === workflow::APPROVED) {
            $row['canversion'] = true;
            $row['versionurl'] = (new moodle_url($baseurl, [
                'action' => 'newversion',
                'id' => $id,
            ]))->out(false);
            if (
                $policy->policytype === policy_service::TYPE_RELEASE
                    && ($policy->config['mode'] ?? null) === policy_service::RELEASE_MANUAL
                    && $policy->manualreleasedat === null
            ) {
                $row['canrelease'] = true;
                $row['releaseurl'] = (new moodle_url($baseurl, [
                    'action' => 'release',
                    'id' => $id,
                ]))->out(false);
            }
        }
        return $row;
    }

    /**
     * Return the presentation class for a policy version's state.
     *
     * @param \stdClass $policy Policy version.
     * @return string
     */
    private function status_class(\stdClass $policy): string {
        if ($policy->status === workflow::APPROVED) {
            return $this->in_force($policy) ? 'active' : 'ended';
        }
        return match ($policy->status) {
            workflow::NEEDS_REVIEW => 'review',
            workflow::DRAFT => 'draft',
            default => 'retired',
        };
    }

    /**
     * Turn typed configuration into labelled settings.
     *
     * @param \stdClass $policy Policy with decoded configuration and bands.
     * @return array[] Key and value pairs.
     */
    private function settings(\stdClass $policy): array {
        $config = $policy->config;
        $pair = static fn(string $key, string $value): array => ['k' => $key, 'v' => $value];
        if ($policy->policytype === policy_service::TYPE_ATTEMPT_SELECTION) {
            return [$pair(
                get_string('policies_setting_attempt', 'local_outcomemap'),
                get_string('attemptmethod_' . ($config['method'] ?? ''), 'local_outcomemap')
            )];
        }
        if ($policy->policytype === policy_service::TYPE_RELEASE) {
            $mode = $config['mode'] ?? '';
            $settings = [$pair(
                get_string('policies_setting_release', 'local_outcomemap'),
                get_string('releasemode_' . $mode, 'local_outcomemap')
            )];
            if ($mode === policy_service::RELEASE_SCHEDULED && !empty($config['releaseat'])) {
                $settings[] = $pair(
                    get_string('policies_setting_releaseat', 'local_outcomemap'),
                    userdate((int) $config['releaseat'])
                );
            } else if ($mode === policy_service::RELEASE_MANUAL) {
                $settings[] = $pair(
                    get_string('policies_setting_released', 'local_outcomemap'),
                    $policy->manualreleasedat === null
                        ? get_string('manualrelease_pending', 'local_outcomemap')
                        : userdate($policy->manualreleasedat)
                );
            }
            return $settings;
        }
        if ($policy->policytype === policy_service::TYPE_ACCREDITATION) {
            return [
                $pair(
                    get_string('policies_setting_criterion', 'local_outcomemap'),
                    ($config['achievementminpercent'] ?? '') . '%'
                ),
                $pair(
                    get_string('policies_setting_benchmark', 'local_outcomemap'),
                    ($config['benchmarkpercent'] ?? '') . '%'
                ),
                $pair(
                    get_string('policies_setting_suppression', 'local_outcomemap'),
                    (string) ($config['mincohortsize'] ?? '')
                ),
                $pair(
                    get_string('policies_setting_population', 'local_outcomemap'),
                    get_string('population_' . ($config['populationsource'] ?? ''), 'local_outcomemap')
                ),
                $pair(
                    get_string('policies_setting_retention', 'local_outcomemap'),
                    get_string('retention_' . ($config['retentionbasis'] ?? ''), 'local_outcomemap')
                ),
            ];
        }
        $settings = [
            $pair(
                get_string('policies_setting_minitems', 'local_outcomemap'),
                (string) ($config['minitems'] ?? 1)
            ),
            $pair(
                get_string('policies_setting_decimals', 'local_outcomemap'),
                (string) ($config['displayscale'] ?? 1)
            ),
            $pair(
                get_string('policies_setting_manual', 'local_outcomemap'),
                empty($config['requiremanualgrading']) ? get_string('no') : get_string('yes')
            ),
            $pair(
                get_string('policies_setting_bands', 'local_outcomemap'),
                (string) count($policy->bands)
            ),
        ];
        if (isset($config['minweightedpossible'])) {
            array_splice($settings, 1, 0, [$pair(
                get_string('policies_setting_minpossible', 'local_outcomemap'),
                (string) $config['minweightedpossible']
            )]);
        }
        return $settings;
    }

    /**
     * Build the leading scope badge for a decision-grouped row.
     *
     * @param \stdClass $policy Policy version.
     * @return array Kind, name, and class.
     */
    private function scope_badge(\stdClass $policy): array {
        return [
            'kind' => get_string('policyscope_' . $policy->scopetype, 'local_outcomemap'),
            'name' => $policy->scopetype === policy_service::SCOPE_INSTITUTION
                ? '' : $this->scope_name($policy),
            'kindclass' => str_replace('_', '-', $policy->scopetype),
        ];
    }

    /**
     * Name the scope a policy is attached to.
     *
     * @param \stdClass $policy Policy version.
     * @return string
     */
    private function scope_name(\stdClass $policy): string {
        if ($policy->scopetype === policy_service::SCOPE_INSTITUTION) {
            return get_string('policyscope_institution', 'local_outcomemap');
        }
        return $this->scopelabels[$policy->scopetype][(int) $policy->scopeid]
            ?? get_string('unknownscope', 'local_outcomemap', (int) $policy->scopeid);
    }

    /**
     * Name the catalog courses no in-force policy of one type reaches.
     *
     * Only the chain the type actually resolves through is considered, so a
     * program-scoped calculation policy does not make a course look covered.
     *
     * @param string $type Policy type.
     * @return string[] Catalog course codes.
     */
    private function uncovered_courses(string $type): array {
        if ($this->institution_default($type) !== null || $this->courses === []) {
            return [];
        }
        $chain = self::RESOLUTION[$type] ?? policy_service::SCOPE_PRECEDENCE;
        if (!in_array(policy_service::SCOPE_CATALOG_COURSE, $chain, true)) {
            // The type never resolves per catalog course, so the only thing that
            // could cover one is the institution default reported above.
            return array_values(array_map(static fn($course) => $course->code, $this->courses));
        }
        $covered = [];
        foreach ($this->policies as $policy) {
            if (
                $policy->policytype === $type
                    && $policy->scopetype === policy_service::SCOPE_CATALOG_COURSE
                    && $this->in_force($policy)
            ) {
                $covered[(int) $policy->scopeid] = true;
            }
        }
        $uncovered = [];
        foreach ($this->courses as $course) {
            if (!isset($covered[(int) $course->id])) {
                $uncovered[] = $course->code;
            }
        }
        return $uncovered;
    }

    /**
     * Load display labels for every scope a policy can name.
     *
     * @return array<string, array<int, string>>
     */
    private function scope_labels(): array {
        global $DB;
        $labels = [
            policy_service::SCOPE_PROGRAM => [],
            policy_service::SCOPE_CATALOG_COURSE => [],
            policy_service::SCOPE_COURSE_INSTANCE => [],
            policy_service::SCOPE_ASSESSMENT => [],
        ];
        foreach ($DB->get_records('local_outcomemap_program', null, 'code') as $program) {
            $labels[policy_service::SCOPE_PROGRAM][(int) $program->id] = $program->code;
        }
        foreach ($this->courses as $course) {
            $labels[policy_service::SCOPE_CATALOG_COURSE][(int) $course->id] = $course->code;
        }
        $instances = $DB->get_records_sql(
            "SELECT ci.id, cc.code, ci.periodcode
               FROM {local_outcomemap_cinst} ci
               JOIN {local_outcomemap_course} cc ON cc.id = ci.courseid"
        );
        foreach ($instances as $instance) {
            $labels[policy_service::SCOPE_COURSE_INSTANCE][(int) $instance->id] =
                $instance->code . ' / ' . $instance->periodcode;
        }
        // Assessment scopes are only labelled when one is actually in use, so an
        // unused site does not pay for a module join it will not display.
        $cmids = [];
        foreach ($this->policies as $policy) {
            if ($policy->scopetype === policy_service::SCOPE_ASSESSMENT && $policy->scopeid !== null) {
                $cmids[(int) $policy->scopeid] = true;
            }
        }
        if ($cmids !== []) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($cmids), SQL_PARAMS_NAMED);
            $modules = $DB->get_records_sql(
                "SELECT cm.id, c.shortname
                   FROM {course_modules} cm
                   JOIN {course} c ON c.id = cm.course
                  WHERE cm.id $insql",
                $params
            );
            foreach ($modules as $module) {
                $labels[policy_service::SCOPE_ASSESSMENT][(int) $module->id] =
                    $module->shortname . ' [cmid ' . (int) $module->id . ']';
            }
        }
        return $labels;
    }
}
