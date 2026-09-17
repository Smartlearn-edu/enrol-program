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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace enrol_programs\output\catalogue;

use enrol_programs\local\allocation;
use enrol_programs\local\program;
use enrol_programs\local\content\item,
    enrol_programs\local\content\top,
    enrol_programs\local\content\set,
    enrol_programs\local\content\course;
use stdClass, moodle_url, tabobject;

/**
 * Program catalogue renderer.
 *
 * @package    enrol_programs
 * @copyright  2022 Open LMS (https://www.openlms.net/)
 * @author     Petr Skoda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    public function render_program(\stdClass $program): string {
        global $CFG, $DB;

        $strnotset = get_string('notset', 'enrol_programs');

        $context = \context::instance_by_id($program->contextid);
        $fullname = format_string($program->fullname);
        $programicon = $this->output->pix_icon('program', '', 'enrol_programs');

        $description = file_rewrite_pluginfile_urls($program->description, 'pluginfile.php', $context->id, 'enrol_programs', 'description', $program->id);
        $description = format_text($description, $program->descriptionformat, ['context' => $context]);

        $tagsdiv = '';
        if ($CFG->usetags) {
            $tags = \core_tag_tag::get_item_tags('enrol_programs', 'program', $program->id);
            if ($tags) {
                $tagsdiv = $this->output->tag_list($tags, '', 'program-tags');
            }
        }

        $programimage = '';
        $presentation = (array)json_decode($program->presentationjson);
        if (!empty($presentation['image'])) {
            $imageurl = \moodle_url::make_file_url("$CFG->wwwroot/pluginfile.php",
                '/' . $context->id . '/enrol_programs/image/' . $program->id . '/'. $presentation['image'], false);
            $programimage = '<div class="float-right programimage">' . \html_writer::img($imageurl, '') . '</div>';
        }

        $result = '';
        $result .= <<<EOT
<div class="programbox clearfix" data-programid="$program->id">
  $programimage
  <div class="info">
  <div class="info">
    <h2 class="programname">{$programicon}{$fullname}</h2>
  </div>$tagsdiv
  <div class="content">
    <div class="summary">$description</div>
  </div>
</div>
EOT;

        // Display custom fields.
        $cfhandler = \enrol_programs\customfield\program_handler::create();
        $cfoutput = '';
        foreach ($cfhandler->get_instance_data($program->id) as $cfdata) {
            if (!$cfhandler->can_view($cfdata->get_field(), $program->id)) {
                continue;
            }
            $cfvalue = $cfdata->export_value();
            if ($cfvalue === null || $cfvalue === '') {
                continue;
            }
            $cfoutput .= '<dt class="col-3">' . s($cfdata->get_field()->get('name')) . ':</dt>';
            $cfoutput .= '<dd class="col-9">' . $cfvalue . '</dd>';
        }
        if ($cfoutput !== '') {
            $result .= '<dl class="row">' . $cfoutput . '</dl>';
        }

        $top = program::load_content($program->id);
        $totalcredits = $top->get_total_available_credits();
        $totalpoints = $top->get_total_available_points();

        $result .= '<dl class="row">';
        $result .= '<dt class="col-3">' . get_string('programstatus', 'enrol_programs') . ':</dt><dd class="col-9">'
            . get_string('errornoallocation', 'enrol_programs') . '</dd>';
        $result .= '<dt class="col-3">' . get_string('allocationstart', 'enrol_programs') . ':</dt><dd class="col-9">'
            . (isset($program->timeallocationstart) ? userdate($program->timeallocationstart) : $strnotset) . '</dd>';
        $result .= '<dt class="col-3">' . get_string('allocationend', 'enrol_programs') . ':</dt><dd class="col-9">'
            . (isset($program->timeallocationend) ? userdate($program->timeallocationend) : $strnotset) . '</dd>';
        if ($totalcredits > 0) {
            $formattedcredits = rtrim(rtrim(number_format($totalcredits, 2), '0'), '.');
            $credittitle = \enrol_programs\local\trophy_bridge::get_type_title('credithours');
            $creditlabel = \enrol_programs\local\trophy_bridge::get_type_label('credithours', (float)$totalcredits);
            $result .= '<dt class="col-3">' . $credittitle . ':</dt><dd class="col-9">'
                . \html_writer::span($formattedcredits . ' ' . $creditlabel, 'badge badge-info bg-info text-white') . '</dd>';
        }
        if ($totalpoints > 0) {
            $pointstitle = \enrol_programs\local\trophy_bridge::get_type_title('points');
            if (\enrol_programs\local\trophy_bridge::has_custom_name('points')) {
                $pointslabel = \enrol_programs\local\trophy_bridge::get_type_label('points', (float)$totalpoints);
                $pointstext = $totalpoints . ' ' . $pointslabel;
            } else {
                $pointstext = get_string('points_badge', 'enrol_programs', $totalpoints);
            }
            $result .= '<dt class="col-3">' . $pointstitle . ':</dt><dd class="col-9">'
                . \html_writer::span($pointstext, 'badge badge-warning bg-warning text-dark') . '</dd>';
        }
        $result .= '</dl>';

        $actions = [];
        /** @var \enrol_programs\local\source\base[] $sourceclasses */ // Type hack.
        $sourceclasses = allocation::get_source_classes();
        foreach ($sourceclasses as $type => $classname) {
            $source = $DB->get_record('enrol_programs_sources', ['programid' => $program->id, 'type' => $type]);
            if (!$source) {
                continue;
            }
            $actions = array_merge($actions, $classname::get_catalogue_actions($program, $source));
        }

        if ($actions) {
            $result .= '<div class="buttons mb-5">';
            $result .= implode(' ', $actions);
            $result .= '</div>';
        }

        $result .= $this->output->heading(get_string('tabcontent', 'enrol_programs'), 3);

        $result .= $this->render_program_content($program, $top);

        return $result;
    }

    protected function render_program_content(stdClass $program, ?top $top = null): string {
        global $DB;

        if ($top === null) {
            $top = program::load_content($program->id);
        }

        $hasrewards = \enrol_programs\local\trophy_bridge::program_has_rewards($top);

        $rows = [];
        $renderercolumns = function(item $item, $itemdepth) use (&$renderercolumns, &$rows, &$DB, $hasrewards): void {
            $fullname = $item->get_fullname();
            $id = $item->get_id();
            $padding = str_repeat('&nbsp;', $itemdepth * 6);

            $completiontype = '';
            if ($item instanceof set) {
                $completiontype = $item->get_sequencetype_info();
            }

            if ($item instanceof course) {
                $courseid = $item->get_courseid();
                $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
                if ($coursecontext) {
                    $canaccesscourse = false;
                    if (has_capability('moodle/course:view', $coursecontext)) {
                        $canaccesscourse = true;
                    } else {
                        $course = get_course($courseid);
                        if ($course && can_access_course($course, null, '', true)) {
                            $canaccesscourse = true;
                        }
                    }
                    if ($canaccesscourse) {
                        $detailurl = new \moodle_url('/course/view.php', ['id' => $courseid]);
                        $fullname = \html_writer::link($detailurl, $fullname);
                    }
                }
            }

            $rewardsinfo = '';
            if ($item instanceof top) {
                $itemname = $this->output->pix_icon('itemtop', get_string('program', 'enrol_programs'), 'enrol_programs') . '&nbsp;' . $fullname;
            } else if ($item instanceof course) {
                if ($hasrewards) {
                    $rewardsinfo = \enrol_programs\local\trophy_bridge::render_reward_badges($item->get_rewards());
                }
                $itemname = $padding . $this->output->pix_icon('itemcourse', get_string('course'), 'enrol_programs') . $fullname;
            } else {
                $itemname = $padding . $this->output->pix_icon('itemset', get_string('set', 'enrol_programs'), 'enrol_programs') . $fullname;
            }

            if ($hasrewards) {
                $row = [$itemname, $rewardsinfo, $completiontype];
            } else {
                $row = [$itemname, $completiontype];
            }

            $rows[] = $row;

            foreach ($item->get_children() as $child) {
                $renderercolumns($child, $itemdepth + 1);
            }
        };
        $renderercolumns($top, 0);

        $table = new \html_table();
        $table->head = [get_string('item', 'enrol_programs')];
        if ($hasrewards) {
            $table->head[] = get_string('rewardscolumn', 'enrol_programs');
        }
        $table->head[] = get_string('sequencetype', 'enrol_programs');
        $table->id = 'program_content';
        $table->attributes['class'] = 'admintable generaltable';
        $table->data = $rows;

        return \html_writer::table($table);
    }
}
