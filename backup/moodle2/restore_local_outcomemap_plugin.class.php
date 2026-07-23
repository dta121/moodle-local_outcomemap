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

use local_outcomemap\local\backup\mapping_restorer;

/**
 * Course-content restore support for local_outcomemap.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_outcomemap_plugin extends restore_local_plugin {
    /**
     * Returns course-level restore paths.
     *
     * @return restore_path_element[] Restore paths.
     */
    protected function define_course_plugin_structure(): array {
        return [
            new restore_path_element(
                $this->get_namefor('course_instance'),
                $this->get_pathfor('/course_instances/course_instance')
            ),
            new restore_path_element(
                $this->get_namefor('external_remediation'),
                $this->get_pathfor('/external_remediations/external_remediation')
            ),
        ];
    }

    /**
     * Returns section-level restore paths.
     *
     * @return restore_path_element[] Restore paths.
     */
    protected function define_section_plugin_structure(): array {
        return [
            new restore_path_element(
                $this->get_namefor('section_mapping'),
                $this->get_pathfor('/section_mappings/section_mapping')
            ),
            new restore_path_element(
                $this->get_namefor('section_remediation'),
                $this->get_pathfor('/section_remediations/section_remediation')
            ),
        ];
    }

    /**
     * Returns module-level restore paths.
     *
     * @return restore_path_element[] Restore paths.
     */
    protected function define_module_plugin_structure(): array {
        return [
            new restore_path_element(
                $this->get_namefor('module_mapping'),
                $this->get_pathfor('/module_mappings/module_mapping')
            ),
            new restore_path_element(
                $this->get_namefor('module_remediation'),
                $this->get_pathfor('/module_remediations/module_remediation')
            ),
        ];
    }

    /**
     * Returns question-level restore paths.
     *
     * @return restore_path_element[] Restore paths.
     */
    protected function define_question_plugin_structure(): array {
        return [
            new restore_path_element(
                $this->get_namefor('question_mapping'),
                $this->get_pathfor('/question_mappings/question_mapping')
            ),
        ];
    }

    /**
     * Restores one question-version mapping onto the restored or matched question.
     *
     * @param array|stdClass $data Backup data.
     */
    public function process_local_outcomemap_question_mapping($data): void {
        global $DB;
        $data = (object) $data;
        $newquestionid = (int) $this->get_new_parentid('question');
        if (!$newquestionid) {
            return;
        }
        $questionversionid = $DB->get_field('question_versions', 'id', ['questionid' => $newquestionid]);
        if (!$questionversionid) {
            return;
        }
        $newid = mapping_restorer::restore_question_mapping($data, (int) $questionversionid);
        if ($newid) {
            $this->set_mapping('local_outcomemap_question_mapping', $data->id, $newid);
        }
    }

    /**
     * Restores one course-instance association and exposes its new local ID.
     *
     * @param array|stdClass $data Backup data.
     */
    public function process_local_outcomemap_course_instance($data): void {
        $data = (object) $data;
        $newid = mapping_restorer::restore_course_instance($data, $this->get_task()->get_courseid());
        if ($newid) {
            $this->set_mapping('local_outcomemap_cinst', $data->id, $newid);
        }
    }

    /**
     * Restores one external remediation recommendation.
     *
     * @param array|stdClass $data Backup data.
     */
    public function process_local_outcomemap_external_remediation($data): void {
        $data = (object) $data;
        $cinstid = $this->course_instance_id($data);
        if ($cinstid) {
            $newid = mapping_restorer::restore_remediation('external_url', $data, $cinstid);
            if ($newid) {
                $this->set_mapping('local_outcomemap_external_remediation', $data->id, $newid);
            }
        }
    }

    /**
     * Restores one section mapping using the remapped section ID.
     *
     * @param array|stdClass $data Backup data.
     */
    public function process_local_outcomemap_section_mapping($data): void {
        $data = (object) $data;
        $cinstid = $this->course_instance_id($data);
        if ($cinstid) {
            $newid = mapping_restorer::restore_content_mapping(
                'course_section',
                $data,
                $cinstid,
                $this->get_task()->get_sectionid()
            );
            if ($newid) {
                $this->set_mapping('local_outcomemap_section_mapping', $data->id, $newid);
            }
        }
    }

    /**
     * Restores one section-target remediation recommendation.
     *
     * @param array|stdClass $data Backup data.
     */
    public function process_local_outcomemap_section_remediation($data): void {
        $data = (object) $data;
        $cinstid = $this->course_instance_id($data);
        if ($cinstid) {
            $newid = mapping_restorer::restore_remediation(
                'course_section',
                $data,
                $cinstid,
                $this->get_task()->get_sectionid()
            );
            if ($newid) {
                $this->set_mapping('local_outcomemap_section_remediation', $data->id, $newid);
            }
        }
    }

    /**
     * Restores one module mapping using the remapped course-module ID.
     *
     * @param array|stdClass $data Backup data.
     */
    public function process_local_outcomemap_module_mapping($data): void {
        $data = (object) $data;
        $cinstid = $this->course_instance_id($data);
        if ($cinstid) {
            $newid = mapping_restorer::restore_content_mapping(
                'course_module',
                $data,
                $cinstid,
                $this->get_task()->get_moduleid()
            );
            if ($newid) {
                $this->set_mapping('local_outcomemap_module_mapping', $data->id, $newid);
            }
        }
    }

    /**
     * Restores one module-target remediation recommendation.
     *
     * @param array|stdClass $data Backup data.
     */
    public function process_local_outcomemap_module_remediation($data): void {
        $data = (object) $data;
        $cinstid = $this->course_instance_id($data);
        if ($cinstid) {
            $newid = mapping_restorer::restore_remediation(
                'course_module',
                $data,
                $cinstid,
                $this->get_task()->get_moduleid()
            );
            if ($newid) {
                $this->set_mapping('local_outcomemap_module_remediation', $data->id, $newid);
            }
        }
    }

    /**
     * Resolves or lazily restores a course instance for partial backups.
     *
     * @param stdClass $data Backup data.
     * @return int|null Course-instance ID, or null when it cannot be restored.
     */
    private function course_instance_id(object $data): ?int {
        $id = $this->get_mappingid('local_outcomemap_cinst', $data->cinstid, 0);
        if ($id) {
            return (int) $id;
        }
        $id = mapping_restorer::restore_course_instance($data, $this->get_task()->get_courseid());
        if ($id) {
            $this->set_mapping('local_outcomemap_cinst', $data->cinstid, $id);
            return $id;
        }
        return null;
    }
}
