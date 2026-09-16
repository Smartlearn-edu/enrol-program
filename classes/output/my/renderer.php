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

namespace enrol_programs\output\my;

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
        global $CFG;

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

        return $result;
    }

    public function render_user_allocation(stdClass $program, stdClass $allocation): string {
        $strnotset = get_string('notset', 'enrol_programs');

        $result = '';

        $result .= '<dl class="row">';
        $result .= '<dt class="col-3">' . get_string('programstatus', 'enrol_programs') . ':</dt><dd class="col-9">'
            . allocation::get_completion_status_html($program, $allocation) . '</dd>';
        $result .= '<dt class="col-3">' . get_string('allocationdate', 'enrol_programs') . ':</dt><dd class="col-9">'
            . userdate($allocation->timeallocated) . '</dd>';
        $result .= '<dt class="col-3">' . get_string('programstart', 'enrol_programs') . ':</dt><dd class="col-9">'
            . userdate($allocation->timestart) . '</dd>';
        $result .= '<dt class="col-3">' . get_string('programdue', 'enrol_programs') . ':</dt><dd class="col-9">'
            . (isset($allocation->timedue) ? userdate($allocation->timedue) : $strnotset) . '</dd>';
        $result .= '<dt class="col-3">' . get_string('programend', 'enrol_programs') . ':</dt><dd class="col-9">'
            . (isset($allocation->timeend) ? userdate($allocation->timeend) : $strnotset) . '</dd>';
        $result .= '<dt class="col-3">' . get_string('completiondate', 'enrol_programs') . ':</dt><dd class="col-9">'
            . (isset($allocation->timecompleted) ? userdate($allocation->timecompleted) : $strnotset) . '</dd>';
        $result .= '</dl>';

        return $result;
    }

    public function render_user_progress(stdClass $program, stdClass $allocation): string {
        global $DB, $USER;

        $top = program::load_content($program->id);
        $programcontext = \context::instance_by_id($program->contextid);
        $canedit = has_capability('enrol/programs:edit', $programcontext);

        // Find any student choice sets requiring selection.
        $pendingforms = [];
        $checkpending = function(item $item) use (&$checkpending, &$pendingforms, $allocation): void {
            if ($item instanceof set && $item->get_sequencetype() === set::SEQUENCE_TYPE_STUDENTCHOICE) {
                $selections = allocation::get_user_selections($allocation->id, $item->get_id());
                $unlocked = allocation::is_set_unlocked_for_selection($allocation->id, $item->get_id());
                if ($unlocked && empty($selections)) {
                    $pendingforms[] = $item;
                }
            }
            if ($item instanceof set) {
                foreach ($item->get_children() as $child) {
                    $checkpending($child);
                }
            }
        };
        $checkpending($top);

        $selectionformhtml = '';
        if ($pendingforms) {
            foreach ($pendingforms as $setitem) {
                $minreq = $setitem->get_minprerequisites();
                $mincredits = $setitem->get_mincredits();
                $minpoints = $setitem->get_minpoints();
                $rule = $setitem->get_completionrule();
                $setname = format_string($setitem->get_fullname());
                $formurl = new \moodle_url('/enrol/programs/my/select_courses.php');
                $setid = $setitem->get_id();

                if ($rule === set::COMPLETION_RULE_CREDITS && $mincredits > 0) {
                    $formattedcredits = rtrim(rtrim(number_format($mincredits, 2), '0'), '.');
                    if (\enrol_programs\local\trophy_bridge::has_custom_name('credithours')) {
                        $creditlabel = \enrol_programs\local\trophy_bridge::get_type_label('credithours', (float)$mincredits);
                        $instruction = get_string('selectcredits_unit', 'enrol_programs', (object)['amount' => $formattedcredits, 'unit' => $creditlabel]);
                    } else {
                        $instruction = get_string('selectcredits', 'enrol_programs', $formattedcredits);
                    }
                } else if ($rule === set::COMPLETION_RULE_POINTS && $minpoints > 0) {
                    if (\enrol_programs\local\trophy_bridge::has_custom_name('points')) {
                        $pointslabel = \enrol_programs\local\trophy_bridge::get_type_label('points', (float)$minpoints);
                        $instruction = get_string('selectpoints_unit', 'enrol_programs', (object)['amount' => $minpoints, 'unit' => $pointslabel]);
                    } else {
                        $instruction = get_string('selectpoints', 'enrol_programs', $minpoints);
                    }
                } else {
                    $instruction = get_string('selectncourses', 'enrol_programs', $minreq);
                }

                $selectionformhtml .= '<div class="alert alert-info border p-3 mb-4 rounded" id="student-choice-box-' . $setid . '">';
                $selectionformhtml .= '<h4 class="alert-heading font-weight-bold">' . get_string('selectcourses', 'enrol_programs') . ': ' . $setname . '</h4>';
                $selectionformhtml .= '<p class="mb-2">' . $instruction . '</p>';
                $selectionformhtml .= '<form method="post" action="' . $formurl->out(false) . '" id="student-choice-form-' . $setid . '">';
                $selectionformhtml .= '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
                $selectionformhtml .= '<input type="hidden" name="id" value="' . $program->id . '">';
                $selectionformhtml .= '<input type="hidden" name="setid" value="' . $setid . '">';
                $selectionformhtml .= '<div class="form-group my-3">';

                foreach ($setitem->get_children() as $child) {
                    if ($child instanceof course) {
                        $childname = format_string($child->get_fullname());
                        $cid = $child->get_id();
                        $checkboxid = 'course_choice_' . $cid;
                        $rewards = $child->get_rewards();
                        $badges = \enrol_programs\local\trophy_bridge::render_reward_badges($rewards);
                        $badgeshtml = $badges ? ' ' . $badges : '';
                        $selectionformhtml .= '<div class="custom-control custom-checkbox my-2 d-flex align-items-center">';
                        $selectionformhtml .= '<input type="checkbox" class="custom-control-input student-choice-cb" id="' . $checkboxid . '" name="courses[]" value="' . $cid . '" data-credits="' . $rewards->credithours . '" data-points="' . $rewards->points . '">';
                        $selectionformhtml .= '<label class="custom-control-label font-weight-normal" for="' . $checkboxid . '">' . $childname . $badgeshtml . '</label>';
                        $selectionformhtml .= '</div>';
                    }
                }

                $selectionformhtml .= '</div>';

                // Live dynamic selection summary tracker.
                $selectionformhtml .= '<div class="alert alert-light border d-flex flex-wrap align-items-center justify-content-between p-2 my-2" id="choice-summary-' . $setid . '">';
                $selectionformhtml .= '<div>';
                $selectionformhtml .= '<span class="mr-3 font-weight-bold">Selected: <span class="sel-count-val">0</span> Courses</span>';
                if ($mincredits > 0 || $rule === set::COMPLETION_RULE_CREDITS) {
                    $reqcr = rtrim(rtrim(number_format($mincredits, 2), '0'), '.');
                    $creditlabel = \enrol_programs\local\trophy_bridge::get_type_label('credithours', (float)$mincredits);
                    $icon = \enrol_programs\local\trophy_bridge::get_type_icon_html('credithours', '14px');
                    $selectionformhtml .= '<span class="mr-3 text-primary font-weight-bold">' . $icon . ' <span class="sel-credits-val">0.0</span> / ' . $reqcr . ' ' . $creditlabel . '</span>';
                }
                if ($minpoints > 0 || $rule === set::COMPLETION_RULE_POINTS) {
                    $pointslabel = \enrol_programs\local\trophy_bridge::get_type_label('points', (float)$minpoints);
                    $icon = \enrol_programs\local\trophy_bridge::get_type_icon_html('points', '14px');
                    $selectionformhtml .= '<span class="mr-3 text-warning font-weight-bold">' . $icon . ' <span class="sel-points-val">0</span> / ' . $minpoints . ' ' . $pointslabel . '</span>';
                }
                $selectionformhtml .= '</div>';
                $selectionformhtml .= '</div>';

                $selectionformhtml .= '<button type="submit" class="btn btn-primary mt-2" id="btn-submit-choice-' . $setid . '">' . get_string('confirmselection', 'enrol_programs') . '</button>';
                $selectionformhtml .= '</form>';
                $selectionformhtml .= '</div>';

                // Inline JS to update counters dynamically.
                $selectionformhtml .= '<script>
                (function() {
                    function initCounters() {
                        var box = document.getElementById("student-choice-box-' . $setid . '");
                        if (!box) return;
                        var cbs = box.querySelectorAll(".student-choice-cb");
                        function updateCounters() {
                            var count = 0, credits = 0.0, points = 0;
                            cbs.forEach(function(cb) {
                                if (cb.checked) {
                                    count++;
                                    credits += parseFloat(cb.getAttribute("data-credits") || 0);
                                    points += parseInt(cb.getAttribute("data-points") || 0, 10);
                                }
                            });
                            var cntEl = box.querySelector(".sel-count-val");
                            if (cntEl) cntEl.textContent = count;
                            var crEl = box.querySelector(".sel-credits-val");
                            if (crEl) crEl.textContent = (Math.round(credits * 100) / 100).toFixed(1);
                            var ptEl = box.querySelector(".sel-points-val");
                            if (ptEl) ptEl.textContent = points;
                        }
                        cbs.forEach(function(cb) {
                            cb.addEventListener("change", updateCounters);
                        });
                        updateCounters();
                    }
                    if (document.readyState === "loading") {
                        document.addEventListener("DOMContentLoaded", initCounters);
                    } else {
                        initCounters();
                    }
                })();
                </script>';
            }
        }

        $hasrewards = \enrol_programs\local\trophy_bridge::program_has_rewards($top);

        $rows = [];
        $renderercolumns = function(item $item, $itemdepth, ?set $parent = null) use (&$renderercolumns, &$rows, $allocation, &$DB, $canedit, $program, $hasrewards): void {
            $fullname = $item->get_fullname();
            $padding = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $itemdepth);

            $completiontype = '';
            if ($item instanceof set) {
                $completiontype = $item->get_sequencetype_info();
                if ($item->get_sequencetype() === set::SEQUENCE_TYPE_STUDENTCHOICE) {
                    $selections = allocation::get_user_selections($allocation->id, $item->get_id());
                    $unlocked = allocation::is_set_unlocked_for_selection($allocation->id, $item->get_id());
                    if ($selections) {
                        $completiontype .= ' <span class="badge badge-info">' . get_string('selectedcoursescount', 'enrol_programs', count($selections)) . '</span>';
                        if ($canedit) {
                            $reseturl = new \moodle_url('/enrol/programs/my/select_courses.php', [
                                'id' => $program->id,
                                'setid' => $item->get_id(),
                                'userid' => $allocation->userid,
                                'reset' => 1,
                                'sesskey' => sesskey(),
                            ]);
                            $completiontype .= ' ' . \html_writer::link($reseturl, get_string('resetselection', 'enrol_programs'), ['class' => 'btn btn-sm btn-outline-secondary']);
                        }
                    } else if ($unlocked) {
                        $completiontype .= ' <span class="badge badge-warning">' . get_string('selectionrequired', 'enrol_programs') . '</span>';
                    } else {
                        $completiontype .= ' <span class="badge badge-secondary">' . get_string('locked', 'enrol_programs') . '</span>';
                    }
                }
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
                if ($parent && $parent->get_sequencetype() === set::SEQUENCE_TYPE_STUDENTCHOICE) {
                    $selections = allocation::get_user_selections($allocation->id, $parent->get_id());
                    if ($selections) {
                        if (isset($selections[$item->get_id()])) {
                            $fullname .= ' <span class="badge badge-success">' . get_string('selected', 'enrol_programs') . '</span>';
                        } else {
                            $fullname .= ' <span class="badge badge-light text-muted">' . get_string('notselected', 'enrol_programs') . '</span>';
                        }
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

            $completioninfo = '';
            $completion = $DB->get_record('enrol_programs_completions', ['itemid' => $item->get_id(), 'allocationid' => $allocation->id]);
            if ($completion) {
                $completioninfo = userdate($completion->timecompleted, get_string('strftimedatetimeshort'));
            }

            if ($hasrewards) {
                $rows[] = [$itemname, $rewardsinfo, $completiontype, $completioninfo];
            } else {
                $rows[] = [$itemname, $completiontype, $completioninfo];
            }

            foreach ($item->get_children() as $child) {
                $renderercolumns($child, $itemdepth + 1, ($item instanceof set ? $item : null));
            }
        };
        $renderercolumns($top, 0, null);

        $table = new \html_table();
        $table->head = [get_string('item', 'enrol_programs')];
        if ($hasrewards) {
            $table->head[] = get_string('rewardscolumn', 'enrol_programs');
        }
        $table->head[] = get_string('sequencetype', 'enrol_programs');
        $table->head[] = get_string('completiondate', 'enrol_programs');
        $table->id = 'program_content';
        $table->attributes['class'] = 'admintable generaltable';
        $table->data = $rows;

        $result = $selectionformhtml;
        $result .= $this->output->heading(get_string('tabcontent', 'enrol_programs'), 3);
        $result .= \html_writer::table($table);

        return $result;
    }

    /**
     * Returns body of My programs block.
     *
     * @return string
     */
    public function render_block_content(): string {
        global $DB;

        $allocations = allocation::get_my_allocations();
        if (!$allocations) {
            return '<em>' . get_string('errornomyprograms', 'enrol_programs') . '</em>';
        }

        $programicon = $this->output->pix_icon('program', '', 'enrol_programs');
        $strnotset = get_string('notset', 'enrol_programs');
        $dateformat = get_string('strftimedatetimeshort');

        foreach ($allocations as $allocation) {
            $row = [];

            $program = $DB->get_record('enrol_programs_programs', ['id' => $allocation->programid]);
            $fullname = $programicon . format_string($program->fullname);
            $detailurl = new moodle_url('/enrol/programs/catalogue/program.php', ['id' => $program->id]);
            $fullname = \html_writer::link($detailurl, $fullname);
            $row[] = $fullname;

            $row[] = \enrol_programs\local\allocation::get_completion_status_html($program, $allocation);

            $row[] = userdate($allocation->timestart, $dateformat);

            $row[] = (isset($allocation->timedue) ? userdate($allocation->timedue, $dateformat) : $strnotset);

            $row[] = (isset($allocation->timeend) ? userdate($allocation->timeend, $dateformat) : $strnotset);

            $data[] = $row;
        }

        $table = new \html_table();
        $table->head = [get_string('programname', 'enrol_programs'), get_string('programstatus', 'enrol_programs'),
            get_string('programstart', 'enrol_programs'), get_string('programdue', 'enrol_programs'),
            get_string('programend', 'enrol_programs')];
        $table->attributes['class'] = 'admintable generaltable';
        $table->data = $data;
        return \html_writer::table($table);
    }

    /**
     * Returns footer of My programs block.
     *
     * @return string
     */
    public function render_block_footer(): string {
        $url = \enrol_programs\local\catalogue::get_catalogue_url();
        if ($url) {
            return '<div class="float-right">' . \html_writer::link($url, get_string('catalogue', 'enrol_programs')) . '</div>';
        }
        return '';
    }
}
