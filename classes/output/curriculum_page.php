<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Combined curriculum page model.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_outcomemap\output;

use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\course_instance_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\program_course_service;
use local_outcomemap\local\service\program_service;
use local_outcomemap\local\workflow;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * Present programs, their catalog courses, and each course's delivery in one page.
 *
 * Programs, catalog courses, and course instances used to be three separate lists,
 * which meant the one question the curriculum actually raises — what does this
 * program teach, and is it being delivered — could only be answered by opening all
 * three and joining them by hand. Reading down one program now answers it: the
 * courses it contains, whether each governs any outcomes, and the Moodle shells
 * delivering each one.
 */
final class curriculum_page implements renderable, templatable {
    /** @var \stdClass[] Programs with governed course and outcome counts. */
    private array $programs;

    /** @var \stdClass[] Catalog courses with their summary counts, keyed by id. */
    private array $courses;

    /** @var \stdClass[][] Non-retired memberships grouped by program id. */
    private array $byprogram = [];

    /** @var \stdClass[][] Non-retired memberships grouped by catalog course id. */
    private array $bycourse = [];

    /** @var \stdClass[][] Associations grouped by catalog course id. */
    private array $instances = [];

    /** @var int The program being read. */
    private int $programid;

    /** @var int Reference time for delivery-window comparisons. */
    private int $now;

    /**
     * Load the whole curriculum once.
     *
     * @param int $programid Program to open, or 0 for the first one.
     * @param int|null $now Reference time, for deterministic tests.
     */
    public function __construct(int $programid = 0, ?int $now = null) {
        $this->programs = program_service::list_with_summary();
        $this->courses = catalog_course_service::list_with_summary();
        $this->now = $now ?? time();
        foreach (program_course_service::list_all() as $membership) {
            if ($membership->status === workflow::RETIRED) {
                continue;
            }
            $this->byprogram[(int) $membership->programid][] = $membership;
            $this->bycourse[(int) $membership->courseid][] = $membership;
        }
        foreach (course_instance_service::list_with_summary() as $instance) {
            $this->instances[(int) $instance->courseid][] = $instance;
        }
        // An unknown or unspecified program falls back to the first one so the page
        // always has something to show rather than an empty right-hand column.
        $this->programid = isset($this->programs[$programid])
            ? $programid
            : (int) (array_key_first($this->programs) ?? 0);
    }

    /** Export the template context. */
    public function export_for_template(renderer_base $output): array {
        $context = \context_system::instance();
        $canmanageprograms = has_capability('local/outcomemap:manageprograms', $context);
        $canmanagecourses = has_capability('local/outcomemap:managecatalogcourses', $context);
        $canmanageframeworks = has_capability('local/outcomemap:manageframeworks', $context);
        $programsurl = new moodle_url('/local/outcomemap/programs.php');
        $catalogurl = new moodle_url('/local/outcomemap/catalogcourses.php');

        return [
            'sidebar' => $this->sidebar(),
            'hasprograms' => $this->programs !== [],
            'canmanageprograms' => $canmanageprograms,
            'canmanagecourses' => $canmanagecourses,
            'addprogramurl' => (new moodle_url($programsurl, ['action' => 'add']))->out(false),
            'addcourseurl' => (new moodle_url($catalogurl, ['action' => 'add']))->out(false),
            'addinstanceurl' => $this->addinstanceurl(),
            'statsline' => $this->statsline(),
        ] + $this->selected($canmanageprograms, $canmanagecourses, $canmanageframeworks,
            $programsurl, $catalogurl);
    }

    /**
     * Build the program navigator, grouped by program type.
     *
     * @return array[] Type groups, each with its program rows.
     */
    private function sidebar(): array {
        $meta = [
            program_service::TYPE_GRADUATE => 'programs_group_graduate',
            program_service::TYPE_UNDERGRADUATE => 'programs_group_undergraduate',
            program_service::TYPE_SPECIALIZATION => 'programs_group_specialization',
        ];
        $baseurl = new moodle_url('/local/outcomemap/curriculum.php');
        $groups = [];
        foreach ($meta as $type => $stringid) {
            $rows = [];
            foreach ($this->programs as $program) {
                if ($program->programtype !== $type) {
                    continue;
                }
                $id = (int) $program->id;
                $count = count($this->byprogram[$id] ?? []);
                $rows[] = [
                    'code' => $program->code,
                    'name' => format_string($program->name),
                    'coursecount' => $count,
                    'selected' => $id === $this->programid,
                    'url' => (new moodle_url($baseurl, ['program' => $id]))->out(false),
                ];
            }
            if ($rows === []) {
                continue;
            }
            $groups[] = [
                'typeclass' => $type,
                'label' => get_string($stringid, 'local_outcomemap'),
                'rows' => $rows,
            ];
        }
        return $groups;
    }

    /**
     * Summarise the whole curriculum for the page header.
     *
     * @return string
     */
    private function statsline(): string {
        $attached = 0;
        foreach ($this->courses as $course) {
            if (($this->bycourse[(int) $course->id] ?? []) !== []) {
                $attached++;
            }
        }
        return get_string(
            count($this->programs) === 1 ? 'curriculum_statsline_one' : 'curriculum_statsline',
            'local_outcomemap',
            (object) [
                'programs' => count($this->programs),
                'courses' => count($this->courses),
                'attached' => $attached,
                'orphans' => count($this->courses) - $attached,
            ]
        );
    }

    /**
     * Build the selected program's panel.
     *
     * @param bool $canmanageprograms Whether the reader may act on programs.
     * @param bool $canmanagecourses Whether the reader may act on catalog courses.
     * @param bool $canmanageframeworks Whether the reader may create frameworks.
     * @param moodle_url $programsurl Programs page URL.
     * @param moodle_url $catalogurl Catalog courses page URL.
     * @return array Template context for the right-hand column.
     */
    private function selected(bool $canmanageprograms, bool $canmanagecourses,
            bool $canmanageframeworks, moodle_url $programsurl, moodle_url $catalogurl): array {
        $program = $this->programs[$this->programid] ?? null;
        if ($program === null) {
            return ['hasselection' => false];
        }
        $memberships = $this->byprogram[$this->programid] ?? [];
        $courses = [];
        $unconfirmed = 0;
        $withoutoutcomes = 0;
        foreach ($memberships as $membership) {
            $course = $this->courses[(int) $membership->courseid] ?? null;
            if ($course === null) {
                continue;
            }
            $card = $this->course($course, $membership, $canmanagecourses, $canmanageprograms,
                $canmanageframeworks, $catalogurl);
            $unconfirmed += $card['unconfirmedcount'];
            if (!$card['hasoutcomes']) {
                $withoutoutcomes++;
            }
            $courses[] = $card;
        }
        usort($courses, static fn($a, $b) => strnatcasecmp($a['code'], $b['code']));

        $attachable = [];
        foreach ($this->courses as $course) {
            $inprogram = false;
            foreach ($this->bycourse[(int) $course->id] ?? [] as $membership) {
                if ((int) $membership->programid === $this->programid) {
                    $inprogram = true;
                    break;
                }
            }
            if ($inprogram) {
                continue;
            }
            $others = array_column($this->bycourse[(int) $course->id] ?? [], 'programcode');
            $attachable[] = [
                'code' => $course->code,
                'name' => format_string($course->name),
                'inline' => $others === []
                    ? get_string('catalogcourses_noprogram_chip', 'local_outcomemap')
                    : get_string('curriculum_alreadyin', 'local_outcomemap', implode(', ', $others)),
                'url' => (new moodle_url($catalogurl, [
                    'action' => 'addmembership',
                    'programid' => $this->programid,
                    'courseid' => (int) $course->id,
                ]))->out(false),
            ];
        }

        return [
            'hasselection' => true,
            'code' => $program->code,
            'name' => format_string($program->name),
            'typeline' => get_string('curriculum_typeline_' . $program->programtype, 'local_outcomemap'),
            'typeclass' => $program->programtype,
            'statuslabel' => workflow::status_label($program->status),
            'statusclass' => $this->statusclass($program->status),
            'facts' => $this->facts($program, count($courses), $unconfirmed, $withoutoutcomes),
            'canedit' => $canmanageprograms && $program->status === workflow::DRAFT,
            'editurl' => (new moodle_url($programsurl, [
                'action' => 'edit',
                'id' => $this->programid,
            ]))->out(false),
            'courses' => $courses,
            'hascourses' => $courses !== [],
            'coursehint' => $courses !== [] ? get_string('curriculum_coursehint', 'local_outcomemap') : '',
            'attachable' => $attachable,
            'hasattachable' => $attachable !== [],
            'addmembershipurl' => (new moodle_url($catalogurl, [
                'action' => 'addmembership',
                'programid' => $this->programid,
            ]))->out(false),
        ] + $this->outcomeslink(framework_service::OWNER_PROGRAM, $this->programid,
            (int) $program->frameworkcount, $canmanageframeworks);
    }

    /**
     * Build the link that associates a Moodle course, coming back here afterwards.
     *
     * The reader is in the middle of one program, and saving used to leave them on the
     * course-instances list with no way back to where they had got to. Both the course
     * being associated and the program to return to travel with the link.
     *
     * @param int $courseid Catalog course to preselect, or 0 for none.
     * @return string
     */
    private function addinstanceurl(int $courseid = 0): string {
        $params = ['action' => 'add'];
        if ($courseid > 0) {
            $params['courseid'] = $courseid;
        }
        if ($this->programid > 0) {
            $params['returnprogram'] = $this->programid;
        }
        return (new moodle_url('/local/outcomemap/courseinstances.php', $params))->out(false);
    }

    /**
     * Build the outcomes action for one framework owner.
     *
     * An owner with no framework has nowhere to put an outcome, so what the reader
     * needs there is the framework, not a hierarchy that cannot yet list anything of
     * theirs. The owner travels with the link, which is what spares the reader from
     * re-stating on the form the program or course they opened it from.
     *
     * @param string $ownertype Framework owner type.
     * @param int $ownerid Program or catalog course id.
     * @param int $frameworkcount Non-retired frameworks the owner already has.
     * @param bool $canmanageframeworks Whether the reader may create one.
     * @return array Template context for the action link.
     */
    private function outcomeslink(string $ownertype, int $ownerid, int $frameworkcount,
            bool $canmanageframeworks): array {
        $frameworksurl = new moodle_url('/local/outcomemap/frameworks.php');
        $iscourse = $ownertype === framework_service::OWNER_COURSE;
        if ($frameworkcount === 0 && $canmanageframeworks) {
            return [
                'outcomesurl' => (new moodle_url($frameworksurl, [
                    'action' => 'addframework',
                    'ownertype' => $ownertype,
                    'ownerid' => $ownerid,
                ]))->out(false),
                'outcomeslabel' => get_string('curriculum_addframework', 'local_outcomemap'),
            ];
        }
        // The two hierarchy views are different lenses, and a catalog-course framework
        // is only ever listed under its course, so each owner is sent to its own.
        return [
            'outcomesurl' => (new moodle_url($frameworksurl, [
                'view' => $iscourse ? 'course' : 'program',
            ]))->out(false),
            'outcomeslabel' => get_string(
                $iscourse ? 'curriculum_courseoutcomes' : 'curriculum_programoutcomes',
                'local_outcomemap'
            ),
        ];
    }

    /**
     * Build the four headline facts about the selected program.
     *
     * @param \stdClass $program Program with its summary counts.
     * @param int $coursecount Attached course count.
     * @param int $unconfirmed Course instances not yet confirmed.
     * @param int $withoutoutcomes Attached courses governing no outcome.
     * @return array[] Fact tiles.
     */
    private function facts(\stdClass $program, int $coursecount, int $unconfirmed,
            int $withoutoutcomes): array {
        $attention = [];
        if ($unconfirmed > 0) {
            $attention[] = get_string(
                $unconfirmed === 1 ? 'curriculum_attention_draft_one' : 'curriculum_attention_draft',
                'local_outcomemap',
                $unconfirmed
            );
        }
        if ($withoutoutcomes > 0) {
            $attention[] = get_string(
                $withoutoutcomes === 1 ? 'curriculum_attention_noout_one' : 'curriculum_attention_noout',
                'local_outcomemap',
                $withoutoutcomes
            );
        }
        return [
            [
                'label' => get_string('curriculum_fact_credential', 'local_outcomemap'),
                'value' => get_string('credential_' . $program->credential, 'local_outcomemap'),
                'warn' => false,
            ],
            [
                'label' => get_string('curriculum_fact_courses', 'local_outcomemap'),
                'value' => (string) $coursecount,
                'warn' => $coursecount === 0,
            ],
            [
                'label' => get_string('curriculum_fact_outcomes', 'local_outcomemap'),
                'value' => (int) $program->outcomecount === 0
                    ? get_string('curriculum_fact_nooutcomes', 'local_outcomemap')
                    : (string) (int) $program->outcomecount,
                'warn' => (int) $program->outcomecount === 0,
            ],
            [
                'label' => get_string('curriculum_fact_attention', 'local_outcomemap'),
                'value' => $attention === []
                    ? get_string('curriculum_attention_none', 'local_outcomemap')
                    : implode(' · ', $attention),
                'warn' => $attention !== [],
            ],
        ];
    }

    /**
     * Build one course card with its delivery rows.
     *
     * @param \stdClass $course Catalog course with its summary counts.
     * @param \stdClass $membership The membership tying it to this program.
     * @param bool $canmanagecourses Whether the reader may act on associations.
     * @param bool $canmanageprograms Whether the reader may act on memberships.
     * @param bool $canmanageframeworks Whether the reader may create frameworks.
     * @param moodle_url $catalogurl Catalog courses page URL.
     * @return array Template card context.
     */
    private function course(\stdClass $course, \stdClass $membership, bool $canmanagecourses,
            bool $canmanageprograms, bool $canmanageframeworks, moodle_url $catalogurl): array {
        $courseid = (int) $course->id;
        $instanceurl = new moodle_url('/local/outcomemap/courseinstances.php');
        $hasoutcomes = (int) $course->courseoutcomecount + (int) $course->unitoutcomecount > 0;
        $rows = [];
        $unconfirmed = 0;
        $active = 0;
        foreach ($this->instances[$courseid] ?? [] as $instance) {
            $phase = instance_state::phase($instance, $this->now);
            if ($phase === instance_state::PHASE_DRAFT) {
                $unconfirmed++;
            }
            if ($phase === instance_state::PHASE_ACTIVE) {
                $active++;
            }
            $moodlecourseid = (int) $instance->moodlecourseid;
            $row = [
                'periodcode' => $instance->periodcode,
                'window' => instance_state::window($instance),
                'moodlename' => format_string($instance->moodlename),
                'moodleurl' => (new moodle_url('/course/view.php', ['id' => $moodlecourseid]))->out(false),
                'enrolled' => instance_state::enrolled($instance),
                'statelabel' => instance_state::label($instance, $phase),
                'stateclass' => instance_state::cssclass($instance, $phase),
                'cansubmit' => false,
            ];
            if ($phase !== instance_state::PHASE_DRAFT) {
                $row['coverageurl'] = (new moodle_url('/local/outcomemap/coverage.php', [
                    'courseid' => $moodlecourseid,
                ]))->out(false);
                $row['mappingurl'] = (new moodle_url('/local/outcomemap/contentmapping.php', [
                    'courseid' => $moodlecourseid,
                ]))->out(false);
            }
            if ($canmanagecourses && $instance->status === workflow::DRAFT) {
                $row['cansubmit'] = true;
                $row['submitlabel'] = workflow::submit_action_label();
                $row['submiturl'] = (new moodle_url($instanceurl, [
                    'action' => 'submit',
                    'id' => (int) $instance->id,
                    'sesskey' => sesskey(),
                ]))->out(false);
            }
            $rows[] = $row;
        }

        $others = [];
        foreach ($this->bycourse[$courseid] ?? [] as $other) {
            if ((int) $other->programid !== $this->programid) {
                $others[] = $other->programcode;
            }
        }
        $total = count($rows);
        $card = [
            'id' => $courseid,
            'code' => $course->code,
            'name' => format_string($course->name),
            'meta' => $hasoutcomes
                ? get_string('catalogcourses_meta', 'local_outcomemap', (object) [
                    'courseoutcomes' => (int) $course->courseoutcomecount,
                    'unitoutcomes' => (int) $course->unitoutcomecount,
                ])
                : get_string('catalogcourses_meta_noframework', 'local_outcomemap'),
            'hasoutcomes' => $hasoutcomes,
            'unconfirmedcount' => $unconfirmed,
            'deliveryline' => $total === 0
                ? get_string('curriculum_nodelivery', 'local_outcomemap')
                : get_string($total === 1 ? 'instances_count_one' : 'instances_count',
                    'local_outcomemap', (object) ['total' => $total, 'active' => $active]),
            'statelabel' => get_string($active > 0 ? 'curriculum_indelivery' : 'curriculum_notdelivered',
                'local_outcomemap'),
            'stateclass' => $active > 0 ? 'active' : 'ended',
            'shared' => $others !== [],
            'sharedlabel' => get_string('curriculum_alsoin', 'local_outcomemap', implode(', ', $others)),
            'instances' => $rows,
            'hasinstances' => $rows !== [],
            'membershipstatus' => workflow::status_label($membership->status),
            'membershipstatusclass' => $this->statusclass($membership->status),
            'membershipeffective' => $this->effective($membership),
            'cansubmitmembership' => false,
            'canmovemembership' => false,
            'addinstanceurl' => $this->addinstanceurl($courseid),
            'instancesurl' => (new moodle_url($instanceurl, ['catalog' => $course->code]))->out(false),
            'canedit' => $canmanagecourses && $course->status === workflow::DRAFT,
            'editurl' => (new moodle_url($catalogurl, [
                'action' => 'edit',
                'id' => $courseid,
            ]))->out(false),
        ] + $this->outcomeslink(framework_service::OWNER_COURSE, $courseid,
            (int) $course->frameworkcount, $canmanageframeworks);
        if ($canmanageprograms && $membership->status === workflow::DRAFT) {
            $card['cansubmitmembership'] = true;
            $card['membershipsubmitlabel'] = workflow::submit_action_label();
            $card['membershipsubmiturl'] = (new moodle_url($catalogurl, [
                'action' => 'submit',
                'type' => 'membership',
                'id' => (int) $membership->id,
                'sesskey' => sesskey(),
            ]))->out(false);
        }
        // Attaching a course to the wrong program is an easy mistake and used to be
        // a one-way one, so each membership carries its own correction: move it to
        // the program it belongs in, or take it out of this one altogether.
        if ($canmanageprograms) {
            $card['canmovemembership'] = true;
            $card['membershipmovelabel'] = get_string('membershipmove', 'local_outcomemap');
            $card['membershipmoveurl'] = (new moodle_url($catalogurl, [
                'action' => 'movemembership',
                'id' => (int) $membership->id,
            ]))->out(false);
            $card['membershipremovelabel'] = get_string(
                $membership->status === workflow::APPROVED
                    ? 'membershipretireaction'
                    : 'membershipremoveaction',
                'local_outcomemap'
            );
            $card['membershipremoveurl'] = (new moodle_url($catalogurl, [
                'action' => 'removemembership',
                'id' => (int) $membership->id,
                'sesskey' => sesskey(),
            ]))->out(false);
        }
        return $card;
    }

    /**
     * Describe the effective range of a membership.
     *
     * @param \stdClass $membership Membership record.
     * @return string
     */
    private function effective(\stdClass $membership): string {
        $format = get_string('strftimedate', 'core_langconfig');
        $from = userdate((int) $membership->effectivefrom, $format);
        if ($membership->effectiveto === null) {
            return get_string('catalogcourses_effective_open', 'local_outcomemap', $from);
        }
        return get_string('catalogcourses_effective', 'local_outcomemap', (object) [
            'from' => $from,
            'to' => userdate((int) $membership->effectiveto, $format),
        ]);
    }

    /**
     * Map a governed status onto its presentation class.
     *
     * @param string $status Canonical workflow status.
     * @return string CSS state suffix.
     */
    private function statusclass(string $status): string {
        $classes = [
            workflow::APPROVED => 'approved',
            workflow::NEEDS_REVIEW => 'review',
            workflow::DRAFT => 'draft',
            workflow::RETIRED => 'retired',
        ];
        return $classes[$status] ?? 'retired';
    }
}
