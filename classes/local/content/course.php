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

use enrol_programs\local\trophy_bridge;
use enrol_programs\local\util;

/**
 * Program course item.
 *
 * @package    enrol_programs
 * @copyright  2022 Open LMS (https://www.openlms.net/)
 * @author     Petr Skoda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course extends item {
    /** @var int */
    protected $courseid;

    /** @var ?item Previous item needs to be completed in order to allow course access */
    protected $previous;

    /** @var float Academic credit hours awarded/weighted for this course */
    protected $credithours = 0.0;

    /** @var int Gamification points awarded for this course */
    protected $points = 0;

    /** @var ?string Trophy or medal type (e.g. trophy, medal, merit, pass, star, gem, coin) */
    protected $medaltype = null;

    /** @var int Trophy or medal reward count */
    protected $medalreward = 0;

    public function get_courseid(): int {
        return $this->courseid;
    }

    /**
     * Get academic credit hours for this course.
     *
     * @return float
     */
    public function get_credithours(): float {
        return $this->credithours;
    }

    /**
     * Get gamification points for this course.
     *
     * @return int
     */
    public function get_points(): int {
        return $this->points;
    }

    /**
     * Get trophy or medal type.
     *
     * @return ?string
     */
    public function get_medaltype(): ?string {
        return $this->medaltype;
    }

    /**
     * Get trophy or medal reward amount.
     *
     * @return int
     */
    public function get_medalreward(): int {
        return $this->medalreward;
    }

    /**
     * Return rewards as a structured object.
     *
     * @return \stdClass
     */
    public function get_rewards(): \stdClass {
        $rewards = new \stdClass();
        $rewards->credithours = $this->credithours;
        $rewards->points = $this->points;
        $rewards->medaltype = $this->medaltype ?? '';
        $rewards->medalreward = $this->medalreward;
        return $rewards;
    }

    /**
     * Is this item deletable?
     *
     * @return bool
     */
    public function is_deletable(): bool {
        if (!$this->id) {
            return false;
        }
        return true;
    }

    /**
     * Return item that must be completed before allowing access to this course.
     *
     * @return item|null
     */
    public function get_previous(): ?item {
        return $this->previous;
    }

    /**
     * Set previous item to new value.
     *
     * @param item|null $previous new previous item
     * @return void
     */
    protected function fix_previous(?item $previous): void {
        $this->previous = $previous;
    }

    /**
     * Set academic credit hours.
     *
     * @param float $credits
     * @return void
     */
    public function set_credithours(float $credits): void {
        $this->credithours = $credits;
    }

    /**
     * Set gamification points.
     *
     * @param int $points
     * @return void
     */
    public function set_points(int $points): void {
        $this->points = $points;
    }

    /**
     * Set trophy or medal reward.
     *
     * @param ?string $type
     * @param int $reward
     * @return void
     */
    public function set_medal(?string $type, int $reward = 1): void {
        $this->medaltype = $type;
        $this->medalreward = $reward;
    }

    /**
     * Factory method.
     *
     * @param \stdClass $record
     * @param item|null $previous
     * @param array $unusedrecords
     * @param array $prerequisites
     * @return course
     */
    protected static function init_from_record(\stdClass $record, ?item $previous, array &$unusedrecords, array &$prerequisites): item {
        if ($record->topitem || !$record->courseid) {
            throw new \coding_exception('Invalid course item');
        }
        $item = new course();
        $item->id = $record->id;
        $item->programid = $record->programid;
        $item->courseid = $record->courseid;
        $item->previous = $previous;
        if ($previous) {
            if ($previous->id == $record->id) {
                $item->previous = null;
                $item->problemdetected = true;
            } else if ($record->previtemid != $previous->id) {
                $item->problemdetected = true;
            }
        } else {
            if ($record->previtemid) {
                $item->problemdetected = true;
            }
        }
        $item->fullname = $record->fullname;
        $sequence = (object)json_decode($record->sequencejson ?? '{}');

        if (!empty($sequence->credithours)) {
            $item->credithours = (float)$sequence->credithours;
        }
        if (!empty($sequence->points)) {
            $item->points = (int)$sequence->points;
        }
        if (!empty($sequence->medaltype)) {
            $item->medaltype = $sequence->medaltype;
            $item->medalreward = (int)($sequence->medalreward ?? 1);
        }

        // Auto-detect default rewards from enrol_trophy if not explicitly overridden in item.
        if ($item->credithours == 0.0 && $item->points == 0 && empty($item->medaltype)) {
            $trophyrewards = trophy_bridge::get_course_rewards($item->courseid);
            $item->credithours = $trophyrewards->credithours;
            $item->points = $trophyrewards->points;
            $item->medaltype = $trophyrewards->medaltype ?: null;
            $item->medalreward = $trophyrewards->medalreward;
        }

        if ($record->minprerequisites != 1) {
            $item->problemdetected = true;
        }

        // NOTE: Prerequisites are verified in set that contains this course.

        return $item;
    }

    /**
     * Fix item prerequisites if necessary.
     *
     * @param array $prerequisites
     * @return bool true if fix applied
     */
    protected function fix_prerequisites(array &$prerequisites): bool {
        // Nothing to do, parent is defining the prerequisites.
        return false;
    }

    /**
     * Returns expected item record data.
     *
     * @return array
     */
    protected function get_record(): array {
        global $DB;

        $fullname = $DB->get_field('course', 'fullname', ['id' => $this->courseid]);
        if ($fullname === false) {
            $fullname = $this->fullname;
        }

        $seqdata = [];
        if ($this->credithours > 0) {
            $seqdata['credithours'] = $this->credithours;
        }
        if ($this->points > 0) {
            $seqdata['points'] = $this->points;
        }
        if (!empty($this->medaltype)) {
            $seqdata['medaltype'] = $this->medaltype;
            $seqdata['medalreward'] = $this->medalreward;
        }

        return [
            'id' => (empty($this->id) ? null : (string)$this->id),
            'programid' => (string)$this->programid,
            'topitem' => null,
            'courseid' => (string)$this->courseid,
            'previtemid' => (isset($this->previous) ? (string)$this->previous->id : null),
            'fullname' => $fullname,
            'sequencejson' => util::json_encode($seqdata),
            'minprerequisites' => '1',
        ];
    }
}

