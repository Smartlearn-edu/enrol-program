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

namespace enrol_programs\local;

use html_writer;
use stdClass;

/**
 * Pluggable bridge class for integrating with enrol_trophy and managing academic credits/points.
 *
 * @package    enrol_programs
 * @copyright  2026 SmartLearn Education <https://smartlearn.education>
 * @author     Mohammad Nabil
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trophy_bridge {
    /** @var bool|null Cached status whether enrol_trophy is present */
    private static ?bool $trophyactive = null;

    /**
     * Check if enrol_trophy plugin is installed and active in this Moodle site.
     *
     * @return bool
     */
    public static function is_trophy_active(): bool {
        global $DB;
        if (self::$trophyactive !== null) {
            return self::$trophyactive;
        }

        try {
            $dbman = $DB->get_manager();
            self::$trophyactive = $dbman->table_exists('enrol_trophy_courses');
        } catch (\Throwable $e) {
            self::$trophyactive = false;
        }

        return self::$trophyactive;
    }

    /**
     * Reset cached status (useful for unit tests).
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$trophyactive = null;
    }

    /**
     * Get course reward configuration from enrol_trophy or return defaults.
     *
     * @param int $courseid
     * @return stdClass Object with properties: credithours, points, medaltype, medalreward
     */
    public static function get_course_rewards(int $courseid): stdClass {
        global $DB;

        $rewards = new stdClass();
        $rewards->credithours = 0.0;
        $rewards->points = 0;
        $rewards->medaltype = '';
        $rewards->medalreward = 0;
        $rewards->medalcustomname = '';

        if (!self::is_trophy_active()) {
            return $rewards;
        }

        try {
            $rec = $DB->get_record('enrol_trophy_courses', ['courseid' => $courseid]);
            if ($rec) {
                if (!empty($rec->givecredithours) && !empty($rec->credithoursreward)) {
                    $rewards->credithours = (float)$rec->credithoursreward;
                }
                if (!empty($rec->givepoints)) {
                    $rewards->points = !empty($rec->pointreward) ? (int)$rec->pointreward : (int)($rec->creditreward ?? 0);
                } else if (!empty($rec->creditreward)) {
                    $rewards->points = (int)$rec->creditreward;
                }
                if (!empty($rec->givemedal) && !empty($rec->medalreward)) {
                    $rewards->medaltype = $rec->medaltype ?? 'trophy';
                    $rewards->medalreward = (int)$rec->medalreward;
                    $rewards->medalcustomname = $rec->medalcustomname ?? '';
                }
            }
        } catch (\Throwable $e) {
            // Silently fall back to empty rewards.
        }

        return $rewards;
    }

    /**
     * Get fallback emoji or icon for medal/currency type.
     *
     * @param string $type
     * @param string $size
     * @return string
     */
    public static function get_type_icon_html(string $type, string $size = '16px'): string {
        if (function_exists('enrol_trophy_render_currency_icon')) {
            return enrol_trophy_render_currency_icon($type, $size);
        }

        $emojis = [
            'trophy'      => '🏆',
            'medal'       => '🥇',
            'merit'       => '🎖️',
            'pass'        => '🎫',
            'star'        => '⭐',
            'gem'         => '💎',
            'coin'        => '🪙',
            'points'      => '🪙',
            'credithours' => '🎓',
        ];

        return $emojis[$type] ?? '🏅';
    }

    /**
     * Get currency unit label (honoring enrol_trophy admin custom singular/plural names).
     *
     * @param string $type Currency type ('points', 'credithours', preset medal name, or 'custom').
     * @param float $amount Amount (to determine singular vs plural).
     * @param string $customname Custom name if type is 'custom'.
     * @return string Unit label.
     */
    public static function get_type_label(string $type, float $amount = 1.0, string $customname = ''): string {
        if (function_exists('enrol_trophy_get_currency_label')) {
            return enrol_trophy_get_currency_label($type, $amount, $customname);
        }

        if (self::is_trophy_active()) {
            if ($type === 'credithours') {
                $single = get_config('enrol_trophy', 'customname_credithours');
                $plural = get_config('enrol_trophy', 'customname_credithours_plural');
                if (!empty($plural) && (float)$amount != 1.0) {
                    return $plural;
                } else if (!empty($single)) {
                    return $single;
                } else if (!empty($plural)) {
                    return $plural;
                }
            } else if ($type === 'points') {
                $single = get_config('enrol_trophy', 'customname_points');
                $plural = get_config('enrol_trophy', 'customname_points_plural');
                if (!empty($plural) && (int)$amount !== 1) {
                    return $plural;
                } else if (!empty($single)) {
                    return $single;
                } else if (!empty($plural)) {
                    return $plural;
                }
            } else if ($type === 'custom' && !empty($customname)) {
                return $customname;
            } else {
                $single = get_config('enrol_trophy', 'customname_' . $type);
                $plural = get_config('enrol_trophy', 'customname_' . $type . '_plural');
                if (!empty($plural) && (int)$amount !== 1) {
                    return $plural;
                } else if (!empty($single)) {
                    return $single;
                } else if (!empty($plural)) {
                    return $plural;
                }
            }
        }

        // Fallback to enrol_programs localized strings.
        if ($type === 'credithours') {
            if ((float)$amount == 1.0) {
                return get_string_manager()->string_exists('credithour_singular', 'enrol_programs')
                    ? get_string('credithour_singular', 'enrol_programs')
                    : 'Credit';
            }
            return get_string_manager()->string_exists('credithours_plural', 'enrol_programs')
                ? get_string('credithours_plural', 'enrol_programs')
                : 'Credits';
        }

        if ($type === 'points') {
            return 'pts';
        }

        if ($type === 'custom' && !empty($customname)) {
            return $customname;
        }

        $strkey = 'medaltype_' . $type;
        return get_string_manager()->string_exists($strkey, 'enrol_programs')
            ? get_string($strkey, 'enrol_programs')
            : ucfirst($type);
    }

    /**
     * Check if a custom name is configured in enrol_trophy for a currency type.
     *
     * @param string $type
     * @param string $customname
     * @return bool
     */
    public static function has_custom_name(string $type, string $customname = ''): bool {
        if ($type === 'custom' && !empty($customname)) {
            return true;
        }
        if (self::is_trophy_active()) {
            $single = get_config('enrol_trophy', 'customname_' . $type);
            $plural = get_config('enrol_trophy', 'customname_' . $type . '_plural');
            return !empty($single) || !empty($plural);
        }
        return false;
    }

    /**
     * Get descriptive title/heading for a currency type.
     *
     * @param string $type
     * @param string $customname
     * @return string
     */
    public static function get_type_title(string $type, string $customname = ''): string {
        if (self::has_custom_name($type, $customname)) {
            return self::get_type_label($type, 2.0, $customname);
        }

        if ($type === 'credithours') {
            return get_string('credithours', 'enrol_programs');
        }
        if ($type === 'points') {
            return get_string('points', 'enrol_programs');
        }
        if ($type === 'custom' && !empty($customname)) {
            return $customname;
        }

        $strkey = 'medaltype_' . $type;
        return get_string_manager()->string_exists($strkey, 'enrol_programs')
            ? get_string($strkey, 'enrol_programs')
            : ucfirst($type);
    }

    /**
     * Render visual badge HTML for a course's rewards.
     *
     * @param stdClass|array|null $rewards Object containing credithours, points, medaltype, medalreward
     * @param string $size CSS size for icons
     * @return string HTML containing badge spans
     */
    public static function render_reward_badges($rewards, string $size = '16px'): string {
        if (is_array($rewards)) {
            $rewards = (object)$rewards;
        } else if (!($rewards instanceof stdClass)) {
            return '';
        }

        $html = '';

        if (!empty($rewards->credithours) && $rewards->credithours > 0) {
            $formattedcredits = rtrim(rtrim(number_format($rewards->credithours, 2), '0'), '.');
            $icon = self::get_type_icon_html('credithours', $size);
            $label = self::get_type_label('credithours', (float)$rewards->credithours);
            $text = $formattedcredits . ' ' . $label;
            $title = self::get_type_title('credithours');
            $html .= html_writer::span($icon . ' ' . $text, 'badge badge-info bg-info text-white mr-1 p-1', [
                'title' => $title,
            ]);
        }

        if (!empty($rewards->points) && $rewards->points > 0) {
            $icon = self::get_type_icon_html('points', $size);
            if (self::has_custom_name('points')) {
                $label = self::get_type_label('points', (float)$rewards->points);
                $text = $rewards->points . ' ' . $label;
            } else {
                $text = get_string('points_badge', 'enrol_programs', $rewards->points);
            }
            $title = self::get_type_title('points');
            $html .= html_writer::span($icon . ' ' . $text, 'badge badge-warning bg-warning text-dark mr-1 p-1', [
                'title' => $title,
            ]);
        }

        if (!empty($rewards->medalreward) && !empty($rewards->medaltype)) {
            $icon = self::get_type_icon_html($rewards->medaltype, $size);
            $customname = $rewards->medalcustomname ?? '';
            $medalname = self::get_type_label($rewards->medaltype, (float)$rewards->medalreward, $customname);
            $text = ($rewards->medalreward > 1) ? "{$rewards->medalreward}x {$medalname}" : $medalname;
            $html .= html_writer::span($icon . ' ' . $text, 'badge badge-success bg-success text-white mr-1 p-1', [
                'title' => $medalname,
            ]);
        }

        return $html;
    }

    /**
     * Check if a program content tree contains any credit hours, points, or trophy rewards.
     *
     * @param \enrol_programs\local\content\item $top
     * @return bool
     */
    public static function program_has_rewards(\enrol_programs\local\content\item $top): bool {
        if ($top instanceof \enrol_programs\local\content\course) {
            $rewards = $top->get_rewards();
            if (!empty($rewards->credithours) || !empty($rewards->points) || !empty($rewards->medaltype)) {
                return true;
            }
        } else if ($top instanceof \enrol_programs\local\content\set) {
            if ($top->get_mincredits() > 0 || $top->get_minpoints() > 0) {
                return true;
            }
        }

        foreach ($top->get_children() as $child) {
            if (self::program_has_rewards($child)) {
                return true;
            }
        }

        return false;
    }
}
