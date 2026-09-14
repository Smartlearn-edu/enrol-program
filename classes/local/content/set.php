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

namespace enrol_programs\local\content;

use enrol_programs\local\util;

/**
 * Program course set item.
 *
 * @package    enrol_programs
 * @copyright  2022 Open LMS (https://www.openlms.net/)
 * @author     Petr Skoda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set extends item {
    public const SEQUENCE_TYPE_ALLINANYORDER = 'allinanyorder';
    public const SEQUENCE_TYPE_ALLINORDER = 'allinorder';
    public const SEQUENCE_TYPE_ATLEAST = 'atleast';
    public const SEQUENCE_TYPE_STUDENTCHOICE = 'studentchoice';

    public const COMPLETION_RULE_COURSES = 'courses';
    public const COMPLETION_RULE_CREDITS = 'credits';
    public const COMPLETION_RULE_POINTS = 'points';
    public const COMPLETION_RULE_BOTH_COURSES_CREDITS = 'both_courses_credits';
    public const COMPLETION_RULE_BOTH_COURSES_POINTS = 'both_courses_points';

    /** @var item[] list of children */
    protected $children = [];

    /** @var string one of SEQUENCE_TYPE_ constants */
    protected $sequencetype;

    /** @var int how many prerequisites/children are required to complete this item */
    protected $minprerequisites;

    /** @var string one of COMPLETION_RULE_ constants */
    protected $completionrule = self::COMPLETION_RULE_COURSES;

    /** @var float minimum credit hours required for completion */
    protected $mincredits = 0.0;

    /** @var int minimum points required for completion */
    protected $minpoints = 0;

    /** @var bool whether children must be completed in order */
    public bool $inorder = false;

    /**
     * Returns all known set sequence types.
     * @return array
     */
    public static function get_sequencetype_types(): array {
        $types = [
            self::SEQUENCE_TYPE_ALLINANYORDER,
            self::SEQUENCE_TYPE_ALLINORDER,
            self::SEQUENCE_TYPE_ATLEAST,
            self::SEQUENCE_TYPE_STUDENTCHOICE,
        ];
        $result = [];
        $a = new \stdClass();
        $a->min = 'X';
        $a->total = 'Y';
        foreach ($types as $type) {
            $result[$type] = get_string('sequencetype_' . $type, 'enrol_programs', $a);
        }

        return $result;
    }

    /**
     * Returns all known set completion rule types.
     * @return array
     */
    public static function get_completionrule_types(): array {
        return [
            self::COMPLETION_RULE_COURSES => get_string('completionrule_courses', 'enrol_programs'),
            self::COMPLETION_RULE_CREDITS => get_string('completionrule_credits', 'enrol_programs'),
            self::COMPLETION_RULE_POINTS => get_string('completionrule_points', 'enrol_programs'),
            self::COMPLETION_RULE_BOTH_COURSES_CREDITS => get_string('completionrule_both_courses_credits', 'enrol_programs'),
            self::COMPLETION_RULE_BOTH_COURSES_POINTS => get_string('completionrule_both_courses_points', 'enrol_programs'),
        ];
    }

    /**
     * Returns set sequence type constant.
     *
     * @return string
     */
    public function get_sequencetype(): string {
        return $this->sequencetype;
    }

    /**
     * How many prerequisite items are required?
     *
     * @return int
     */
    public function get_minprerequisites(): int {
        return $this->minprerequisites;
    }

    /**
     * Return completion rule.
     *
     * @return string
     */
    public function get_completionrule(): string {
        return $this->completionrule;
    }

    /**
     * Return minimum credit hours required.
     *
     * @return float
     */
    public function get_mincredits(): float {
        return $this->mincredits;
    }

    /**
     * Return minimum points required.
     *
     * @return int
     */
    public function get_minpoints(): int {
        return $this->minpoints;
    }

    /**
     * Set completion rule and requirements.
     *
     * @param string $rule
     * @param float $mincredits
     * @param int $minpoints
     * @return void
     */
    public function set_completionrule(string $rule, float $mincredits = 0.0, int $minpoints = 0): void {
        $this->completionrule = $rule;
        $this->mincredits = $mincredits;
        $this->minpoints = $minpoints;
    }

    /**
     * Calculate total available credit hours across all child courses in this set.
     *
     * @return float
     */
    public function get_total_available_credits(): float {
        $total = 0.0;
        foreach ($this->children as $child) {
            if ($child instanceof course) {
                $total += $child->get_credithours();
            } else if ($child instanceof set) {
                $total += $child->get_total_available_credits();
            }
        }
        return $total;
    }

    /**
     * Calculate total available points across all child courses in this set.
     *
     * @return int
     */
    public function get_total_available_points(): int {
        $total = 0;
        foreach ($this->children as $child) {
            if ($child instanceof course) {
                $total += $child->get_points();
            } else if ($child instanceof set) {
                $total += $child->get_total_available_points();
            }
        }
        return $total;
    }

    /**
     * Returns human readable set sequence info.
     *
     * @return string
     */
    public function get_sequencetype_info(): string {
        if ($this->completionrule === self::COMPLETION_RULE_CREDITS && $this->mincredits > 0) {
            $formatted = rtrim(rtrim(number_format($this->mincredits, 2), '0'), '.');
            return get_string('sequencetype_credits_info', 'enrol_programs', ['min' => $formatted]);
        } else if ($this->completionrule === self::COMPLETION_RULE_POINTS && $this->minpoints > 0) {
            return get_string('sequencetype_points_info', 'enrol_programs', ['min' => $this->minpoints]);
        } else if ($this->completionrule === self::COMPLETION_RULE_BOTH_COURSES_CREDITS && $this->mincredits > 0) {
            $formatted = rtrim(rtrim(number_format($this->mincredits, 2), '0'), '.');
            $a = new \stdClass();
            $a->min = $this->minprerequisites;
            $a->total = count($this->children);
            $base = get_string('sequencetype_' . $this->sequencetype, 'enrol_programs', $a);
            return $base . ' (' . get_string('sequencetype_credits_info', 'enrol_programs', ['min' => $formatted]) . ')';
        } else if ($this->completionrule === self::COMPLETION_RULE_BOTH_COURSES_POINTS && $this->minpoints > 0) {
            $a = new \stdClass();
            $a->min = $this->minprerequisites;
            $a->total = count($this->children);
            $base = get_string('sequencetype_' . $this->sequencetype, 'enrol_programs', $a);
            return $base . ' (' . get_string('sequencetype_points_info', 'enrol_programs', ['min' => $this->minpoints]) . ')';
        }

        $a = new \stdClass();
        $a->min = $this->minprerequisites;
        $a->total = count($this->children);
        return get_string('sequencetype_' . $this->sequencetype, 'enrol_programs', $a);
    }

    /**
     * Factory method.
     *
     * @param \stdClass $record
     * @param item|null $previous
     * @param array $unusedrecords
     * @param array $prerequisites
     * @return set
     */
    protected static function init_from_record(\stdClass $record, ?item $previous, array &$unusedrecords, array &$prerequisites): item {
        if ($record->courseid) {
            throw new \coding_exception('Invalid set item');
        }
        if ($record->topitem) {
            $item = new top();
        } else {
            $item = new set();
        }
        $item->id = $record->id;
        $item->programid = $record->programid;
        $item->fullname = $record->fullname;

        $sequence = (object)json_decode($record->sequencejson ?? '{}');
        $inorder = ($sequence->type === self::SEQUENCE_TYPE_ALLINORDER);

        if ($sequence->children) {
            foreach ((array)$sequence->children as $childitemid) {
                if (!isset($unusedrecords[$childitemid])) {
                    $item->problemdetected = true;
                    continue;
                }
                $childrecord = $unusedrecords[$childitemid];
                if ($childrecord->id != $childitemid) {
                    throw new \coding_exception('Invalid records parameter supplied');
                }
                if ($childrecord->programid != $item->programid) {
                    throw new \coding_exception('Invalid records contains invalid programid');
                }
                unset($unusedrecords[$childitemid]);
                if ($childrecord->courseid) {
                    $child = course::init_from_record($childrecord, $previous, $unusedrecords, $prerequisites);
                } else {
                    $child = self::init_from_record($childrecord, $previous, $unusedrecords, $prerequisites);
                }
                if ($inorder) {
                    $previous = $child;
                }
                $item->children[] = $child;
            }
        }

        if ($sequence->type === self::SEQUENCE_TYPE_ALLINANYORDER) {
            $item->sequencetype = self::SEQUENCE_TYPE_ALLINANYORDER;
            $item->minprerequisites = count($item->children);
            if (!$item->minprerequisites) {
                $item->minprerequisites = 1;
            }
        } else if ($sequence->type === self::SEQUENCE_TYPE_ATLEAST || $sequence->type === self::SEQUENCE_TYPE_STUDENTCHOICE) {
            $item->sequencetype = $sequence->type;
            if ($record->minprerequisites) {
                $item->minprerequisites = $record->minprerequisites;
            } else {
                $item->minprerequisites = 1;
            }
        } else {
            if ($sequence->type !== self::SEQUENCE_TYPE_ALLINORDER) {
                debugging('Invalid program item sequence type in itemid: ' . $item->id, DEBUG_DEVELOPER);
                $item->problemdetected = true;
            }

            $item->sequencetype = self::SEQUENCE_TYPE_ALLINORDER;
            $item->minprerequisites = count($item->children);
            if (!$item->minprerequisites) {
                $item->minprerequisites = 1;
            }
        }

        if (!empty($sequence->completionrule)) {
            $item->completionrule = $sequence->completionrule;
        }
        if (!empty($sequence->mincredits)) {
            $item->mincredits = (float)$sequence->mincredits;
        }
        if (!empty($sequence->minpoints)) {
            $item->minpoints = (int)$sequence->minpoints;
        }

        if ($item->minprerequisites != $record->minprerequisites) {
            debugging('Invalid minimum prerequisites in itemid: ' . $item->id, DEBUG_DEVELOPER);
            $item->problemdetected = true;
        }

        foreach ($item->children as $child) {
            foreach ($prerequisites as $k => $pre) {
                if ($pre->itemid != $item->id) {
                    continue;
                }
                if ($pre->prerequisiteitemid == $child->id) {
                    unset($prerequisites[$k]);
                    continue 2;
                }
            }
            $item->problemdetected = true;
        }

        return $item;
    }

    /**
     * Set previous item to new value.
     *
     * @param item|null $previous new previous item
     * @return void
     */
    protected function fix_previous(?item $previous): void {
        foreach ($this->children as $child) {
            $inorder = ($this->sequencetype === self::SEQUENCE_TYPE_ALLINORDER);
            $child->fix_previous($previous);
            if ($inorder) {
                $previous = $child;
            }
        }
    }

    /**
     * Fix item prerequisites if necessary.
     *
     * @param array $prerequisites
     * @return bool true if fix applied
     */
    protected function fix_prerequisites(array &$prerequisites): bool {
        global $DB;

        $updated = false;

        foreach ($this->children as $child) {
            foreach ($prerequisites as $k => $pre) {
                if ($pre->itemid != $this->id) {
                    continue;
                }
                if ($pre->prerequisiteitemid == $child->id) {
                    unset($prerequisites[$k]);
                    continue 2;
                }
            }
            $p = ['itemid' => $this->id, 'prerequisiteitemid' => $child->id];
            $DB->insert_record('enrol_programs_prerequisites', (object)$p);
            $updated = true;
        }

        foreach ($this->children as $child) {
            if ($child->fix_prerequisites($prerequisites)) {
                $updated = true;
            }
        }

        return $updated;
    }

    /**
     * Returns set children.
     *
     * @return item[]
     */
    public function get_children(): array {
        return $this->children;
    }

    /**
     * Was there any program detected in this item during loading?
     *
     * @return bool
     */
    public function is_problem_detected(): bool {
        if ($this->problemdetected) {
            return true;
        }
        foreach ($this->children as $child) {
            if ($child->is_problem_detected()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get expected item record data.
     * @return array
     */
    protected function get_record(): array {
        $sequence = [
            'children' => [],
            'type' => $this->sequencetype,
        ];
        if ($this->completionrule !== self::COMPLETION_RULE_COURSES) {
            $sequence['completionrule'] = $this->completionrule;
            if ($this->mincredits > 0) {
                $sequence['mincredits'] = $this->mincredits;
            }
            if ($this->minpoints > 0) {
                $sequence['minpoints'] = $this->minpoints;
            }
        }
        foreach ($this->children as $child) {
            $sequence['children'][] = $child->id;
        }

        return [
            'id' => (empty($this->id) ? null : (string)$this->id),
            'programid' => (string)$this->programid,
            'topitem' => null,
            'courseid' => null,
            'previtemid' => null,
            'fullname' => $this->fullname,
            'sequencejson' => util::json_encode($sequence),
            'minprerequisites' => (string)$this->minprerequisites,
        ];
    }

    /**
     * Add new child to this set.
     *
     * @param item $item
     * @return void
     */
    protected function add_child(item $item): void {
        $this->children[] = $item;
        if ($this->sequencetype !== self::SEQUENCE_TYPE_ATLEAST && $this->sequencetype !== self::SEQUENCE_TYPE_STUDENTCHOICE) {
            $this->minprerequisites = count($this->children);
            if (!$this->minprerequisites) {
                $this->minprerequisites = 1;
            }
        }
    }

    /**
     * Delete child from this set.
     *
     * @param int $itemid
     * @return void
     */
    protected function remove_chid(int $itemid): void {
        foreach ($this->children as $k => $child) {
            if ($child->id == $itemid) {
                unset($this->children[$k]);
                $this->children = array_values($this->children);
                break;
            }
        }
        if ($this->sequencetype !== self::SEQUENCE_TYPE_ATLEAST && $this->sequencetype !== self::SEQUENCE_TYPE_STUDENTCHOICE) {
            $this->minprerequisites = count($this->children);
            if (!$this->minprerequisites) {
                $this->minprerequisites = 1;
            }
        }
    }
}

