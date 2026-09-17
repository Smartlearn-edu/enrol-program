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
 * Custom fields management page for programs.
 *
 * @package    enrol_programs
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use enrol_programs\customfield\program_handler;
use core_customfield\output\management;

require('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('program_customfield');

$output = $PAGE->get_renderer('core_customfield');
$handler = program_handler::create();
$outputpage = new management($handler);

echo $output->header(),
     $output->heading(get_string('customfields', 'enrol_programs')),
     $output->render($outputpage),
     $output->footer();
