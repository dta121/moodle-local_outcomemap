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

namespace local_outcomemap;

use local_outcomemap\form\framework_form;
use local_outcomemap\local\service\catalog_course_service;
use local_outcomemap\local\service\framework_service;
use local_outcomemap\local\service\program_service;

/**
 * Tests that a framework's owner is chosen rather than typed as an internal id.
 *
 * A framework owner used to be a free-text integer box labelled only "Owner", so
 * typing the program's code — or any id that did not happen to exist — was cleaned
 * to 0 and rejected deep inside the service with nothing on screen to say what a
 * valid value looked like.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_outcomemap\form\framework_form
 */
final class framework_form_test extends \advanced_testcase {
    /**
     * @var int Program that may own a framework.
     */
    private int $programid;

    /**
     * @var int Catalog course that may own a framework.
     */
    private int $courseid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->programid = program_service::create([
            'code' => 'MEI',
            'name' => "Master's in Entrepreneurship & Innovation",
            'programtype' => program_service::TYPE_GRADUATE,
        ]);
        $this->courseid = catalog_course_service::create([
            'code' => 'MEI601',
            'name' => 'Financial Management',
        ]);
    }

    /**
     * Submit the form and return the data it produces.
     *
     * @param array $submitted Simulated submission.
     * @return \stdClass|null
     */
    private function submit(array $submitted): ?\stdClass {
        framework_form::mock_submit($submitted);
        $form = new framework_form(new \moodle_url('/local/outcomemap/frameworks.php'));
        return $form->get_data();
    }

    /**
     * * A program owner picked from the list becomes the owner id the service takes.
     */
    public function test_program_picker_becomes_the_owner_id(): void {
        $data = $this->submit([
            'id' => 0,
            'code' => 'MEI-PLO',
            'name' => 'MEI program learning outcomes',
            'description' => '',
            'ownertype' => framework_service::OWNER_PROGRAM,
            'ownerprogramid' => $this->programid,
            'ownercourseid' => 0,
        ]);

        $this->assertNotNull($data, 'A complete submission must validate.');
        $this->assertSame($this->programid, (int) $data->ownerid);
        $this->assertObjectNotHasProperty(
            'ownerprogramid',
            $data,
            'The service takes one owner id, so the pickers must not reach it.'
        );

        // The whole point: what the form yields is accepted by the service.
        $frameworkid = framework_service::create((array) $data);
        $this->assertGreaterThan(0, $frameworkid);
    }

    /**
     * * A catalog-course owner is read from its own picker, not the program one.
     */
    public function test_course_picker_becomes_the_owner_id(): void {
        $data = $this->submit([
            'id' => 0,
            'code' => 'MEI601-CLO',
            'name' => 'MEI601 course learning outcomes',
            'description' => '',
            'ownertype' => framework_service::OWNER_COURSE,
            'ownerprogramid' => $this->programid,
            'ownercourseid' => $this->courseid,
        ]);

        $this->assertNotNull($data);
        $this->assertSame(
            $this->courseid,
            (int) $data->ownerid,
            'A stale value in the other picker must be ignored.'
        );
        $this->assertGreaterThan(0, framework_service::create((array) $data));
    }

    /**
     * * An institution framework has no owner at all.
     */
    public function test_institution_owner_is_null(): void {
        $data = $this->submit([
            'id' => 0,
            'code' => 'INST-PLO',
            'name' => 'Institutional outcomes',
            'description' => '',
            'ownertype' => framework_service::OWNER_INSTITUTION,
            'ownerprogramid' => $this->programid,
            'ownercourseid' => $this->courseid,
        ]);

        $this->assertNotNull($data);
        $this->assertNull(
            $data->ownerid,
            'An institution framework must not inherit whatever the pickers held.'
        );
        $this->assertGreaterThan(0, framework_service::create((array) $data));
    }

    /**
     * * Choosing no owner is caught by the form rather than by the service.
     */
    public function test_missing_owner_is_a_form_error(): void {
        $this->assertNull($this->submit([
            'id' => 0,
            'code' => 'MEI-PLO',
            'name' => 'MEI program learning outcomes',
            'description' => '',
            'ownertype' => framework_service::OWNER_PROGRAM,
            'ownerprogramid' => 0,
            'ownercourseid' => 0,
        ]), 'A program framework with no program chosen must not validate.');
    }

    /**
     * Describe an owner the way the frameworks page resolves one from its link.
     *
     * @param string $ownertype Framework owner type.
     * @param int $ownerid Program or catalog course id.
     * @param string $code Owner code.
     * @param string $name Owner name.
     * @return \stdClass
     */
    private function owner(string $ownertype, int $ownerid, string $code, string $name): \stdClass {
        return (object) [
            'ownertype' => $ownertype,
            'ownerid' => $ownerid,
            'code' => $code,
            'name' => $name,
        ];
    }

    /**
     * Read the values a form was seeded with.
     *
     * @param framework_form $form Form to inspect.
     * @return array Exported values.
     */
    private function defaults(framework_form $form): array {
        // The moodleform class keeps its quickform private, and there is no public accessor
        // for the defaults it was seeded with.
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        return $property->getValue($form)->exportValues();
    }

    /**
     * * An owner that arrived with the link is not asked for again.
     */
    public function test_owner_from_the_link_replaces_the_pickers(): void {
        framework_form::mock_submit([
            'id' => 0,
            'code' => 'MEI-PLO',
            'name' => 'MEI program learning outcomes',
            'description' => '',
            'ownertype' => framework_service::OWNER_PROGRAM,
            'ownerid' => $this->programid,
        ]);
        $form = new framework_form(new \moodle_url('/local/outcomemap/frameworks.php'), [
            'owner' => $this->owner(
                framework_service::OWNER_PROGRAM,
                $this->programid,
                'MEI',
                "Master's in Entrepreneurship & Innovation"
            ),
        ]);
        $data = $form->get_data();

        $this->assertNotNull($data, 'Nothing is missing: the owner came with the link.');
        $this->assertSame($this->programid, (int) $data->ownerid);
        $this->assertSame(framework_service::OWNER_PROGRAM, $data->ownertype);
        $this->assertObjectNotHasProperty(
            'ownerprogramid',
            $data,
            'There is no picker to collapse when the owner is already settled.'
        );
        $this->assertGreaterThan(0, framework_service::create((array) $data));
    }

    /**
     * * The code and name a program framework conventionally gets are filled in.
     */
    public function test_program_owner_suggests_the_plo_convention(): void {
        $form = new framework_form(new \moodle_url('/local/outcomemap/frameworks.php'), [
            'owner' => $this->owner(
                framework_service::OWNER_PROGRAM,
                $this->programid,
                'MEI',
                "Master's in Entrepreneurship & Innovation"
            ),
        ]);
        $defaults = $this->defaults($form);

        $this->assertSame(
            'MEI-PLO',
            $defaults['code'],
            'The code convention is the owner code and the kind of outcomes held.'
        );
        $this->assertSame('MEI program learning outcomes', $defaults['name']);
        $this->assertSame(framework_service::OWNER_PROGRAM, $defaults['ownertype']);
        $this->assertEquals($this->programid, $defaults['ownerid']);
    }

    /**
     * * A catalog course gets the course-level convention, not the program one.
     */
    public function test_course_owner_suggests_the_clo_convention(): void {
        $form = new framework_form(new \moodle_url('/local/outcomemap/frameworks.php'), [
            'owner' => $this->owner(
                framework_service::OWNER_COURSE,
                $this->courseid,
                'MEI601',
                'Financial Management'
            ),
        ]);
        $defaults = $this->defaults($form);

        $this->assertSame('MEI601-CLO', $defaults['code']);
        $this->assertSame('MEI601 course learning outcomes', $defaults['name']);
        $this->assertSame(framework_service::OWNER_COURSE, $defaults['ownertype']);
        $this->assertEquals($this->courseid, $defaults['ownerid']);
    }

    /**
     * * An owner type that owns no record of its own keeps the full form.
     */
    public function test_institution_context_falls_back_to_the_pickers(): void {
        framework_form::mock_submit([
            'id' => 0,
            'code' => 'INST-ILO',
            'name' => 'Institutional outcomes',
            'description' => '',
            'ownertype' => framework_service::OWNER_INSTITUTION,
            'ownerprogramid' => 0,
            'ownercourseid' => 0,
        ]);
        $form = new framework_form(new \moodle_url('/local/outcomemap/frameworks.php'), [
            'owner' => $this->owner(framework_service::OWNER_INSTITUTION, 0, 'INST', 'Institution'),
        ]);
        $data = $form->get_data();

        $this->assertNotNull($data, 'The pickers must still be there to be submitted.');
        $this->assertNull(
            $data->ownerid,
            'An institution framework has no owner record for a link to have named.'
        );
    }

    /**
     * * Editing an existing framework preselects the owner it already has.
     */
    public function test_editing_preselects_the_stored_owner(): void {
        $frameworkid = framework_service::create([
            'code' => 'MEI-PLO',
            'name' => 'MEI program learning outcomes',
            'ownertype' => framework_service::OWNER_PROGRAM,
            'ownerid' => $this->programid,
        ]);
        global $DB;
        $existing = $DB->get_record('local_outcomemap_fw', ['id' => $frameworkid], '*', MUST_EXIST);

        $form = new framework_form(new \moodle_url('/local/outcomemap/frameworks.php'));
        $form->set_data($existing);

        $defaults = $this->defaults($form);
        $this->assertEquals(
            $this->programid,
            $defaults['ownerprogramid'],
            'The stored owner must come back selected in its picker.'
        );
    }
}
