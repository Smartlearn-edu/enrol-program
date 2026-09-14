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

/**
 * Handle student course selection submission for elective sets.
 *
 * @package    enrol_programs
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var moodle_database $DB */
/** @var moodle_page $PAGE */
/** @var stdClass $USER */

use enrol_programs\local\allocation;
use enrol_programs\local\content\set;

require('../../../config.php');

$id = required_param('id', PARAM_INT);
$setid = required_param('setid', PARAM_INT);
$courses = optional_param_array('courses', [], PARAM_INT);
$reset = optional_param('reset', 0, PARAM_BOOL);
$userid = optional_param('userid', 0, PARAM_INT);

require_login();
require_sesskey();

if (!enrol_is_enabled('programs')) {
    redirect(new moodle_url('/'));
}

$program = $DB->get_record('enrol_programs_programs', ['id' => $id], '*', MUST_EXIST);
if ($program->archived) {
    redirect(new moodle_url('/enrol/programs/my/index.php'));
}

$programcontext = context::instance_by_id($program->contextid);

$targetuserid = $USER->id;
if ($userid && $userid != $USER->id) {
    require_capability('enrol/programs:edit', $programcontext);
    $targetuserid = $userid;
}

$allocation = $DB->get_record('enrol_programs_allocations', [
    'programid' => $program->id,
    'userid' => $targetuserid,
], '*', MUST_EXIST);

if ($allocation->archived) {
    redirect(new moodle_url('/enrol/programs/my/program.php', ['id' => $program->id]));
}

$returnurl = new moodle_url('/enrol/programs/my/program.php', ['id' => $program->id]);
if ($targetuserid != $USER->id) {
    $returnurl = new moodle_url('/enrol/programs/management/program_users.php', ['id' => $program->id]);
}

if ($reset) {
    // Only admins can reset selections.
    require_capability('enrol/programs:edit', $programcontext);
    allocation::reset_user_selections($allocation->id, $setid);
    \core\notification::info(get_string('coursesselectionreset', 'enrol_programs'));
    redirect($returnurl);
}

// Check if selection is currently open/unlocked.
if (!allocation::is_set_unlocked_for_selection($allocation->id, $setid)) {
    \core\notification::error(get_string('selectionlocked', 'enrol_programs'));
    redirect($returnurl);
}

$top = \enrol_programs\local\program::load_content($program->id);
$set = $top->find_item($setid);
if (!$set || !($set instanceof set)) {
    \core\notification::error(get_string('errorinvalidcourse', 'enrol_programs'));
    redirect($returnurl);
}

$rule = $set->get_completionrule();
$minrequired = $set->get_minprerequisites();
$mincredits = $set->get_mincredits();
$minpoints = $set->get_minpoints();

// Calculate selected metrics.
$selectedcourses = [];
$selectedcredits = 0.0;
$selectedpoints = 0;

$childmap = [];
foreach ($set->get_children() as $child) {
    if ($child instanceof \enrol_programs\local\content\course) {
        $childmap[$child->get_id()] = $child;
    }
}

foreach ($courses as $cid) {
    if (!isset($childmap[$cid])) {
        \core\notification::error(get_string('errorinvalidcourse', 'enrol_programs'));
        redirect($returnurl);
    }
    $selectedcourses[$cid] = $childmap[$cid];
    $selectedcredits += $childmap[$cid]->get_credithours();
    $selectedpoints += $childmap[$cid]->get_points();
}

$selectedcount = count($selectedcourses);

if ($rule === set::COMPLETION_RULE_CREDITS) {
    if ($selectedcredits < $mincredits) {
        $a = (object)[
            'selected' => rtrim(rtrim(number_format($selectedcredits, 2), '0'), '.'),
            'required' => rtrim(rtrim(number_format($mincredits, 2), '0'), '.'),
        ];
        \core\notification::error(get_string('errorinsufficientcredits', 'enrol_programs', $a));
        redirect($returnurl);
    }
} else if ($rule === set::COMPLETION_RULE_POINTS) {
    if ($selectedpoints < $minpoints) {
        $a = (object)[
            'selected' => $selectedpoints,
            'required' => $minpoints,
        ];
        \core\notification::error(get_string('errorinsufficientpoints', 'enrol_programs', $a));
        redirect($returnurl);
    }
} else if ($rule === set::COMPLETION_RULE_BOTH_COURSES_CREDITS) {
    if ($selectedcount < $minrequired) {
        \core\notification::error(get_string('errorselectexactcount', 'enrol_programs', $minrequired));
        redirect($returnurl);
    }
    if ($selectedcredits < $mincredits) {
        $a = (object)[
            'selected' => rtrim(rtrim(number_format($selectedcredits, 2), '0'), '.'),
            'required' => rtrim(rtrim(number_format($mincredits, 2), '0'), '.'),
        ];
        \core\notification::error(get_string('errorinsufficientcredits', 'enrol_programs', $a));
        redirect($returnurl);
    }
} else if ($rule === set::COMPLETION_RULE_BOTH_COURSES_POINTS) {
    if ($selectedcount < $minrequired) {
        \core\notification::error(get_string('errorselectexactcount', 'enrol_programs', $minrequired));
        redirect($returnurl);
    }
    if ($selectedpoints < $minpoints) {
        $a = (object)[
            'selected' => $selectedpoints,
            'required' => $minpoints,
        ];
        \core\notification::error(get_string('errorinsufficientpoints', 'enrol_programs', $a));
        redirect($returnurl);
    }
} else {
    // Default course count rule.
    if ($selectedcount !== $minrequired) {
        \core\notification::error(get_string('errorselectexactcount', 'enrol_programs', $minrequired));
        redirect($returnurl);
    }
}

try {
    allocation::save_user_selections($allocation->id, $setid, $courses, $USER->id);
    \core\notification::success(get_string('coursesselected', 'enrol_programs'));
} catch (\Exception $e) {
    \core\notification::error($e->getMessage());
}

redirect($returnurl);
