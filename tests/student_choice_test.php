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

        // Stage 2 is now locked after selection.
        $this->assertFalse(allocation::is_set_unlocked_for_selection($allocation->id, $stage2->get_id()));

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

        // Test reset by admin:
        allocation::reset_user_selections($allocation->id, $stage2->get_id());
        $this->assertEmpty(allocation::get_user_selections($allocation->id, $stage2->get_id()));
        $this->assertTrue(allocation::is_set_unlocked_for_selection($allocation->id, $stage2->get_id()));
        // After reset, course 2 and 4 must be suspended again!
        $this->assertFalse(is_enrolled($context2, $user1, '', true));
        $this->assertFalse(is_enrolled($context4, $user1, '', true));

        // User now selects course 2 and course 3.
        allocation::save_user_selections($allocation->id, $stage2->get_id(), [$item2->get_id(), $item3->get_id()], $user1->id);
        $this->assertTrue(is_enrolled($context2, $user1, '', true));
        $this->assertTrue(is_enrolled($context3, $user1, '', true));
        $this->assertFalse(is_enrolled($context4, $user1, '', true));
        $this->assertFalse(is_enrolled($context5, $user1, '', true));

        // Simulate completing UNSELECTED course 5: must NOT count towards stage 2!
        $ccompletion5 = new \completion_completion(['course' => $course5->id, 'userid' => $user1->id]);
        $ccompletion5->mark_complete();
        allocation::fix_user_enrolments($program1->id, $user1->id);
        $this->assertFalse($DB->record_exists('enrol_programs_completions', ['itemid' => $stage2->get_id(), 'allocationid' => $allocation->id]));

        // Complete chosen course 2.
        $ccompletion = new \completion_completion(['course' => $course2->id, 'userid' => $user1->id]);
        $ccompletion->mark_complete();
        allocation::fix_user_enrolments($program1->id, $user1->id);

        // Stage 2 not complete yet (requires 2 chosen courses, only course 2 is chosen & complete; course 5 was not chosen).
        $this->assertFalse($DB->record_exists('enrol_programs_completions', ['itemid' => $stage2->get_id(), 'allocationid' => $allocation->id]));

        // Complete chosen course 3.
        $ccompletion = new \completion_completion(['course' => $course3->id, 'userid' => $user1->id]);
        $ccompletion->mark_complete();
        allocation::fix_user_enrolments($program1->id, $user1->id);

        // Stage 2 is completed!
        $this->assertTrue($DB->record_exists('enrol_programs_completions', ['itemid' => $stage2->get_id(), 'allocationid' => $allocation->id]));

        // Program is completed!
        $this->assertTrue($DB->record_exists('enrol_programs_completions', ['itemid' => $top->get_id(), 'allocationid' => $allocation->id]));

        // Verify cleanup on deallocation.
        $this->assertTrue($DB->record_exists('enrol_programs_selections', ['allocationid' => $allocation->id]));
        \enrol_programs\local\source\manual::deallocate_user($program1, $source1, $allocation);
        $this->assertFalse($DB->record_exists('enrol_programs_selections', ['allocationid' => $allocation->id]));
    }

    /**
     * Test credit-based set completion rule.
     */
    public function test_credit_based_set_completion() {
        global $DB;

        /** @var \enrol_programs_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('enrol_programs');

        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $course3 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);

        $program = $generator->create_program(['sources' => ['manual' => []]]);
        $source = $DB->get_record('enrol_programs_sources', ['programid' => $program->id, 'type' => 'manual'], '*', MUST_EXIST);

        $top = program::load_content($program->id);
        $top->update_set($top, '', set::SEQUENCE_TYPE_ALLINORDER);

        // Stage 1: Credit-based set requiring 6.0 credit hours.
        $stage1 = $top->append_set(
            $top,
            'Academic Major Electives',
            set::SEQUENCE_TYPE_ALLINANYORDER,
            1,
            set::COMPLETION_RULE_CREDITS,
            6.0
        );

        // Course 1: 3.0 credits, Course 2: 4.0 credits, Course 3: 2.0 credits.
        $top->append_course($stage1, $course1->id, 3.0);
        $top->append_course($stage1, $course2->id, 4.0);
        $top->append_course($stage1, $course3->id, 2.0);

        $this->assertEquals(9.0, $stage1->get_total_available_credits());
        $this->assertEquals(set::COMPLETION_RULE_CREDITS, $stage1->get_completionrule());
        $this->assertEquals(6.0, $stage1->get_mincredits());

        // Allocate user.
        \enrol_programs\local\source\manual::allocate_users($program->id, $source->id, [$user->id]);
        $allocation = $DB->get_record('enrol_programs_allocations', ['programid' => $program->id, 'userid' => $user->id], '*', MUST_EXIST);

        // Complete Course 1 (3.0 credits earned, required 6.0).
        $ccompletion1 = new \completion_completion(['course' => $course1->id, 'userid' => $user->id]);
        $ccompletion1->mark_complete();
        allocation::fix_user_enrolments($program->id, $user->id);

        // Set should NOT be marked completed yet (3.0 < 6.0).
        $this->assertFalse($DB->record_exists('enrol_programs_completions', ['itemid' => $stage1->get_id(), 'allocationid' => $allocation->id]));

        // Complete Course 2 (4.0 credits earned, total 7.0 >= 6.0).
        $ccompletion2 = new \completion_completion(['course' => $course2->id, 'userid' => $user->id]);
        $ccompletion2->mark_complete();
        allocation::fix_user_enrolments($program->id, $user->id);

        // Set is now completed!
        $this->assertTrue($DB->record_exists('enrol_programs_completions', ['itemid' => $stage1->get_id(), 'allocationid' => $allocation->id]));
        // Program top is also completed!
        $this->assertTrue($DB->record_exists('enrol_programs_completions', ['itemid' => $top->get_id(), 'allocationid' => $allocation->id]));
    }

    /**
     * Test points-based set completion rule.
     */
    public function test_points_based_set_completion() {
        global $DB;

        /** @var \enrol_programs_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('enrol_programs');

        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);

        $program = $generator->create_program(['sources' => ['manual' => []]]);
        $source = $DB->get_record('enrol_programs_sources', ['programid' => $program->id, 'type' => 'manual'], '*', MUST_EXIST);

        $top = program::load_content($program->id);
        $top->update_set($top, '', set::SEQUENCE_TYPE_ALLINANYORDER);

        // Stage requiring 100 points.
        $stage1 = $top->append_set(
            $top,
            'Gamified Challenges',
            set::SEQUENCE_TYPE_ALLINANYORDER,
            1,
            set::COMPLETION_RULE_POINTS,
            0.0,
            100
        );

        // Course 1: 40 pts, Course 2: 70 pts.
        $top->append_course($stage1, $course1->id, 0.0, 40);
        $top->append_course($stage1, $course2->id, 0.0, 70);

        $this->assertEquals(110, $stage1->get_total_available_points());

        \enrol_programs\local\source\manual::allocate_users($program->id, $source->id, [$user->id]);
        $allocation = $DB->get_record('enrol_programs_allocations', ['programid' => $program->id, 'userid' => $user->id], '*', MUST_EXIST);

        // Complete Course 1 (40 points < 100).
        $cc1 = new \completion_completion(['course' => $course1->id, 'userid' => $user->id]);
        $cc1->mark_complete();
        allocation::fix_user_enrolments($program->id, $user->id);

        $this->assertFalse($DB->record_exists('enrol_programs_completions', ['itemid' => $stage1->get_id(), 'allocationid' => $allocation->id]));

        // Complete Course 2 (40 + 70 = 110 points >= 100).
        $cc2 = new \completion_completion(['course' => $course2->id, 'userid' => $user->id]);
        $cc2->mark_complete();
        allocation::fix_user_enrolments($program->id, $user->id);

        $this->assertTrue($DB->record_exists('enrol_programs_completions', ['itemid' => $stage1->get_id(), 'allocationid' => $allocation->id]));
    }

    /**
     * Test student choice combined with credits requirement.
     */
    public function test_student_choice_with_credits() {
        global $DB;

        /** @var \enrol_programs_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('enrol_programs');

        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $course3 = $this->getDataGenerator()->create_course(['enablecompletion' => true]);

        $context1 = \context_course::instance($course1->id);
        $context2 = \context_course::instance($course2->id);
        $context3 = \context_course::instance($course3->id);

        $program = $generator->create_program(['sources' => ['manual' => []]]);
        $source = $DB->get_record('enrol_programs_sources', ['programid' => $program->id, 'type' => 'manual'], '*', MUST_EXIST);

        $top = program::load_content($program->id);
        $top->update_set($top, '', set::SEQUENCE_TYPE_ALLINORDER);

        // Student choice set with credit requirement (min 6.0 credits).
        $stage1 = $top->append_set(
            $top,
            'Elective Tracks',
            set::SEQUENCE_TYPE_STUDENTCHOICE,
            1,
            set::COMPLETION_RULE_CREDITS,
            6.0
        );

        $item1 = $top->append_course($stage1, $course1->id, 3.0);
        $item2 = $top->append_course($stage1, $course2->id, 4.0);
        $item3 = $top->append_course($stage1, $course3->id, 2.0);

        \enrol_programs\local\source\manual::allocate_users($program->id, $source->id, [$user->id]);
        $allocation = $DB->get_record('enrol_programs_allocations', ['programid' => $program->id, 'userid' => $user->id], '*', MUST_EXIST);

        // Unlocked for selection.
        $this->assertTrue(allocation::is_set_unlocked_for_selection($allocation->id, $stage1->get_id()));

        // User selects course 1 and course 2 (3.0 + 4.0 = 7.0 credits >= 6.0).
        allocation::save_user_selections($allocation->id, $stage1->get_id(), [$item1->get_id(), $item2->get_id()], $user->id);

        // Enrolment checks:
        $this->assertTrue(is_enrolled($context1, $user, '', true));
        $this->assertTrue(is_enrolled($context2, $user, '', true));
        $this->assertFalse(is_enrolled($context3, $user, '', true));

        // Complete course 1: 3.0 credits.
        $cc1 = new \completion_completion(['course' => $course1->id, 'userid' => $user->id]);
        $cc1->mark_complete();
        allocation::fix_user_enrolments($program->id, $user->id);
        $this->assertFalse($DB->record_exists('enrol_programs_completions', ['itemid' => $stage1->get_id(), 'allocationid' => $allocation->id]));

        // Complete course 2: +4.0 credits = 7.0 credits >= 6.0.
        $cc2 = new \completion_completion(['course' => $course2->id, 'userid' => $user->id]);
        $cc2->mark_complete();
        allocation::fix_user_enrolments($program->id, $user->id);
        $this->assertTrue($DB->record_exists('enrol_programs_completions', ['itemid' => $stage1->get_id(), 'allocationid' => $allocation->id]));
    }

    /**
     * Test trophy bridge reward badge rendering.
     */
    public function test_trophy_bridge_standalone_badges() {
        // Test empty rewards.
        $emptybadges = \enrol_programs\local\trophy_bridge::render_reward_badges([]);
        $this->assertEmpty($emptybadges);

        // Test credits badge.
        $creditbadge = \enrol_programs\local\trophy_bridge::render_reward_badges(['credithours' => 4.5]);
        $this->assertStringContainsString('4.5 Credits', $creditbadge);
        $this->assertStringContainsString('badge-info', $creditbadge);

        // Test points badge.
        $pointbadge = \enrol_programs\local\trophy_bridge::render_reward_badges(['points' => 150]);
        $this->assertStringContainsString('150 pts', $pointbadge);
        $this->assertStringContainsString('badge-warning', $pointbadge);

        // Test medal badge.
        $medalbadge = \enrol_programs\local\trophy_bridge::render_reward_badges([
            'medaltype' => 'trophy',
            'medalreward' => 1,
        ]);
        $this->assertStringContainsString('Trophy', $medalbadge);
        $this->assertStringContainsString('badge-success', $medalbadge);

        // Test combined.
        $combined = \enrol_programs\local\trophy_bridge::render_reward_badges([
            'credithours' => 3.0,
            'points' => 50,
            'medaltype' => 'gold',
            'medalreward' => 2,
        ]);
        $this->assertStringContainsString('3 Credits', $combined);
        $this->assertStringContainsString('50 pts', $combined);

        // Test custom currency names (e.g. ECT for academic credit hours).
        set_config('customname_credithours', 'ECT', 'enrol_trophy');
        set_config('customname_credithours_plural', 'ECT', 'enrol_trophy');
        $customcreditbadge = \enrol_programs\local\trophy_bridge::render_reward_badges(['credithours' => 4.0]);
        $this->assertStringContainsString('4 ECT', $customcreditbadge);
        $this->assertStringContainsString('title="ECT"', $customcreditbadge);

        // Test custom points name.
        set_config('customname_points', 'XP', 'enrol_trophy');
        set_config('customname_points_plural', 'XP', 'enrol_trophy');
        $custompointbadge = \enrol_programs\local\trophy_bridge::render_reward_badges(['points' => 200]);
        $this->assertStringContainsString('200 XP', $custompointbadge);

        // Test custom named medal prize.
        $custommedalbadge = \enrol_programs\local\trophy_bridge::render_reward_badges([
            'medaltype' => 'custom',
            'medalreward' => 1,
            'medalcustomname' => 'Master Diploma',
        ]);
        $this->assertStringContainsString('Master Diploma', $custommedalbadge);

        // Clean up configs.
        unset_config('customname_credithours', 'enrol_trophy');
        unset_config('customname_credithours_plural', 'enrol_trophy');
        unset_config('customname_points', 'enrol_trophy');
        unset_config('customname_points_plural', 'enrol_trophy');
    }
}
