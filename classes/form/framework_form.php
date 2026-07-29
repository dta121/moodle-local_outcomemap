<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\form;

use local_outcomemap\local\service\framework_service;

/** Framework editor. */
final class framework_form extends \moodleform {
    /**
     * Code suffix suggested per owner type.
     *
     * These are identifiers rather than wording: the suffix is the convention the
     * outcome hierarchy reads to tell a course-level set from a unit-level one, so
     * it must not vary by language.
     */
    private const CODE_SUFFIX = [
        framework_service::OWNER_PROGRAM => '-PLO',
        framework_service::OWNER_COURSE => '-CLO',
    ];

    public function definition(): void {
        $mform = $this->_form;
        $fixedowner = $this->fixedowner();
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('text', 'code', get_string('code', 'local_outcomemap'), ['maxlength' => 100]);
        $mform->setType('code', PARAM_TEXT);
        $mform->addRule('code', null, 'required');
        // The code prefixes every outcome label inside the framework and freezes on
        // approval, which is a lot to know about a box that only said "Code".
        $mform->addHelpButton('code', 'frameworkcode', 'local_outcomemap');
        $mform->addElement('text', 'name', get_string('name', 'local_outcomemap'), ['maxlength' => 255, 'size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');
        $mform->addElement('textarea', 'description', get_string('description', 'local_outcomemap'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        if ($fixedowner !== null) {
            // The owner arrived with the link, so it is shown rather than asked for,
            // and the code and name it implies are filled in ready to be accepted.
            $mform->addElement('hidden', 'ownertype', $fixedowner->ownertype);
            $mform->setType('ownertype', PARAM_ALPHAEXT);
            $mform->addElement('hidden', 'ownerid', $fixedowner->ownerid);
            $mform->setType('ownerid', PARAM_INT);
            // A static element is rendered as it stands, so the code is escaped here;
            // the pickers get theirs escaped for them as option labels.
            $mform->addElement('static', 'ownerfixed', get_string('owner', 'local_outcomemap'),
                s($fixedowner->code) . ' — ' . format_string($fixedowner->name));
            $mform->addElement('static', 'ownerfixednote', '',
                get_string('frameworkownerfixed_' . $fixedowner->ownertype, 'local_outcomemap'));
            $mform->setDefault('code',
                $fixedowner->code . (self::CODE_SUFFIX[$fixedowner->ownertype] ?? ''));
            $mform->setDefault('name', get_string('frameworkdefaultname_' . $fixedowner->ownertype,
                'local_outcomemap', $fixedowner->code));
            $this->add_action_buttons();
            return;
        }

        $mform->addElement('select', 'ownertype', get_string('ownertype', 'local_outcomemap'), [
            framework_service::OWNER_INSTITUTION => get_string('owner_institution', 'local_outcomemap'),
            framework_service::OWNER_PROGRAM => get_string('owner_program', 'local_outcomemap'),
            framework_service::OWNER_COURSE => get_string('owner_catalog_course', 'local_outcomemap'),
        ]);

        // The owner is one of the governed records that already exist, so it is
        // picked rather than typed as an internal id. A typed id meant anything
        // non-numeric silently became 0 and the save failed inside the service
        // with nothing on screen to say what a valid value would have looked
        // like. One picker per owner type, because the two draw from different
        // tables and only the one the owner type selects is ever submitted.
        $mform->addElement('autocomplete', 'ownerprogramid', get_string('owner', 'local_outcomemap'),
            $this->owners('local_outcomemap_program'));
        $mform->setType('ownerprogramid', PARAM_INT);
        $mform->hideIf('ownerprogramid', 'ownertype', 'neq', framework_service::OWNER_PROGRAM);
        $mform->addElement('autocomplete', 'ownercourseid', get_string('owner', 'local_outcomemap'),
            $this->owners('local_outcomemap_course'));
        $mform->setType('ownercourseid', PARAM_INT);
        $mform->hideIf('ownercourseid', 'ownertype', 'neq', framework_service::OWNER_COURSE);

        if (!empty($this->_customdata['identitylocked'])) {
            // The framework is approved, so its code and owner are settled; the
            // service ignores them for an approved framework in any case.
            $mform->addElement('static', 'identitynote', '',
                get_string('hier_frameworkidentitylocked', 'local_outcomemap'));
            $mform->hardFreeze(['code', 'ownertype', 'ownerprogramid', 'ownercourseid']);
        }
        $this->add_action_buttons();
    }

    /**
     * Return the owner the form was opened for, if it was opened for one.
     *
     * @return \stdClass|null Owner with its ownertype, ownerid, code, and name.
     */
    private function fixedowner(): ?\stdClass {
        $owner = $this->_customdata['owner'] ?? null;
        // An institution framework owns no record a link could have named, so only the
        // two owner types with one are honoured.
        return $owner instanceof \stdClass
            && isset($owner->ownertype, self::CODE_SUFFIX[$owner->ownertype]) ? $owner : null;
    }

    /**
     * List the governed records of one table as owner options.
     *
     * The code alone is what the framework code is built from, but it is the name
     * that tells two similarly-coded records apart, so both are shown.
     *
     * @param string $table Program or catalog course table.
     * @return string[] Option labels keyed by record id, with a leading placeholder.
     */
    private function owners(string $table): array {
        global $DB;
        $options = ['' => get_string('owner_choose', 'local_outcomemap')];
        foreach ($DB->get_records($table, null, 'code ASC', 'id, code, name') as $record) {
            $options[(int) $record->id] = $record->code . ' — ' . $record->name;
        }
        return $options;
    }

    /**
     * Require an owner whenever the owner type has one.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if ($this->fixedowner() !== null) {
            // There is no picker to be empty: the owner came with the link.
            return $errors;
        }
        $required = [
            framework_service::OWNER_PROGRAM => 'ownerprogramid',
            framework_service::OWNER_COURSE => 'ownercourseid',
        ];
        $element = $required[$data['ownertype'] ?? ''] ?? null;
        if ($element !== null && empty($data[$element])) {
            $errors[$element] = get_string('owner_required', 'local_outcomemap');
        }
        return $errors;
    }

    /**
     * Collapse the two owner pickers back into the single owner the service takes.
     *
     * @return \stdClass|null Submitted data, or null when the form was not submitted.
     */
    public function get_data() {
        $data = parent::get_data();
        if ($data === null) {
            return null;
        }
        if ($this->fixedowner() !== null) {
            // The owner id is already the one the service takes.
            $data->ownerid = (int) $data->ownerid;
            return $data;
        }
        if ($data->ownertype === framework_service::OWNER_PROGRAM) {
            $data->ownerid = (int) $data->ownerprogramid;
        } else if ($data->ownertype === framework_service::OWNER_COURSE) {
            $data->ownerid = (int) $data->ownercourseid;
        } else {
            $data->ownerid = null;
        }
        unset($data->ownerprogramid, $data->ownercourseid);
        return $data;
    }

    /**
     * Expand a stored owner onto whichever picker its owner type uses.
     *
     * @param mixed $data Existing framework record or defaults.
     * @return void
     */
    public function set_data($data): void {
        $data = (object) (array) $data;
        $ownerid = isset($data->ownerid) ? (int) $data->ownerid : 0;
        if ($ownerid > 0 && isset($data->ownertype)) {
            if ($data->ownertype === framework_service::OWNER_PROGRAM) {
                $data->ownerprogramid = $ownerid;
            } else if ($data->ownertype === framework_service::OWNER_COURSE) {
                $data->ownercourseid = $ownerid;
            }
        }
        parent::set_data($data);
    }
}
