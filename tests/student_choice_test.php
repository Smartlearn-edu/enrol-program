<?php
// This file is part of Moodle - https://moodle.org/
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

namespace enrol_programs;

use enrol_programs\local\program;
use enrol_programs\local\allocation;
use enrol_programs\local\content\top;
use enrol_programs\local\content\set;
use enrol_programs\local\content\course;

/**
 * Student choice (electives) tests.
 *
 * @group      openlms
 * @package    enrol_programs
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \enrol_programs\local\content\set
 * @covers \enrol_programs\local\allocation
 */
final class student_choice_test extends \advanced_testcase {
    public function setUp(): void {
        $this->resetAfterTest();
    }

    public function test_student_choice_flow() {
        global $DB;

        /** @var \enrol_programs_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('enrol_programs');

        $user1 = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $course3 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $course4 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $course5 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $course6 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);

        $context1 = \context_course::instance($course1->id);
        $context2 = \context_course::instance($course2->id);
        $context3 = \context_course::instance($course3->id);
        $context4 = \context_course::instance($course4->id);
        $context5 = \context_course::instance($course5->id);
        $context6 = \context_course::instance($course6->id);

        $program1 = $generator->create_program(['sources' => ['manual' => []]]);
        $source1 = $DB->get_record('enrol_programs_sources', ['programid' => $program1->id, 'type' => 'manual'], '*', MUST_EXIST);

        // Build structure: Top (allinorder) -> Stage 1 (mandatory course 1) -> Stage 2 (student choice 2 of 4: courses 2, 3, 4, 5).
        $top = program::load_content($program1->id);
        $top->update_set($top, '', set::SEQUENCE_TYPE_ALLINORDER);

        $stage1 = $top->append_set($top, 'Stage 1', set::SEQUENCE_TYPE_ALLINANYORDER);
        $item1 = $top->append_course($stage1, $course1->id);

        $stage2 = $top->append_set($top, 'Stage 2 Electives', set::SEQUENCE_TYPE_STUDENTCHOICE, 2);
        $item2 = $top->append_course($stage2, $course2->id);
        $item3 = $top->append_course($stage2, $course3->id);
        $item4 = $top->append_course($stage2, $course4->id);
        $item5 = $top->append_course($stage2, $course5->id);

        $this->assertSame(set::SEQUENCE_TYPE_STUDENTCHOICE, $stage2->get_sequencetype());
        $this->assertSame(2, $stage2->get_minprerequisites());

        // Allocate user to program.
        \enrol_programs\local\source\manual::allocate_users($program1->id, $source1->id, [$user1->id]);
        $allocation = $DB->get_record('enrol_programs_allocations', ['programid' => $program1->id, 'userid' => $user1->id], '*', MUST_EXIST);

        // Stage 1 course 1 is active.
        $this->assertTrue(is_enrolled($context1, $user1, '', true));

        // Stage 2 is locked (Stage 1 not complete yet).
        $this->assertFalse(allocation::is_set_unlocked_for_selection($allocation->id, $stage2->get_id()));
        $this->assertFalse(is_enrolled($context2, $user1, '', true));
        $this->assertFalse(is_enrolled($context3, $user1, '', true));
        $this->assertFalse(is_enrolled($context4, $user1, '', true));
        $this->assertFalse(is_enrolled($context5, $user1, '', true));

        // Complete Stage 1 course 1.
        $ccompletion = new \completion_completion(['course' => $course1->id, 'userid' => $user1->id]);
        $ccompletion->mark_complete();
        allocation::fix_user_enrolments($program1->id, $user1->id);

        // Stage 2 is now unlocked for selection!
        $this->assertTrue(allocation::is_set_unlocked_for_selection($allocation->id, $stage2->get_id()));

        // But student has not selected courses yet, so stage 2 courses must STILL be suspended.
        $this->assertFalse(is_enrolled($context2, $user1, '', true));
        $this->assertFalse(is_enrolled($context3, $user1, '', true));
        $this->assertFalse(is_enrolled($context4, $user1, '', true));
        $this->assertFalse(is_enrolled($context5, $user1, '', true));

        // User selects course 2 and course 4 (2 courses out of 4).
        allocation::save_user_selections($allocation->id, $stage2->get_id(), [$item2->get_id(), $item4->get_id()], $user1->id);

        $selections = allocation::get_user_selections($allocation->id, $stage2->get_id());
        $this->assertCount(2, $selections);
        $this->assertArrayHasKey($item2->get_id(), $selections);
        $this->assertArrayHasKey($item4->get_id(), $selections);

        // Now course 2 and course 4 must be ACTIVE!
        $this->assertTrue(is_enrolled($context2, $user1, '', true));
        $this->assertTrue(is_enrolled($context4, $user1, '', true));

        // Course 3 and course 5 must remain SUSPENDED!
        $this->assertFalse(is_enrolled($context3, $user1, '', true));
        $this->assertFalse(is_enrolled($context5, $user1, '', true));

        // Complete chosen course 2.
        $ccompletion = new \completion_completion(['course' => $course2->id, 'userid' => $user1->id]);
        $ccompletion->mark_complete();
        allocation::fix_user_enrolments($program1->id, $user1->id);

        // Stage 2 not complete yet (requires 2 courses).
        $this->assertFalse($DB->record_exists('enrol_programs_completions', ['itemid' => $stage2->get_id(), 'allocationid' => $allocation->id]));

        // Complete chosen course 4.
        $ccompletion = new \completion_completion(['course' => $course4->id, 'userid' => $user1->id]);
        $ccompletion->mark_complete();
        allocation::fix_user_enrolments($program1->id, $user1->id);

        // Stage 2 is completed!
        $this->assertTrue($DB->record_exists('enrol_programs_completions', ['itemid' => $stage2->get_id(), 'allocationid' => $allocation->id]));

        // Program is completed!
        $this->assertTrue($DB->record_exists('enrol_programs_completions', ['itemid' => $top->get_id(), 'allocationid' => $allocation->id]));
    }
}
