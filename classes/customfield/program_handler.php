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

namespace enrol_programs\customfield;

use core_customfield\field_controller;
use core_customfield\handler;

/**
 * Custom fields handler for programs.
 *
 * @package    enrol_programs
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class program_handler extends handler {
    /** @var int Field is visible to everyone */
    const VISIBLETOALL = 2;
    /** @var int Field is visible to managers only */
    const VISIBLETOMANAGERS = 1;
    /** @var int Field is not visible */
    const NOTVISIBLE = 0;

    /** @var \context|null Context for creation of new items */
    protected $parentcontext;

    /**
     * Sets parent context for the program.
     *
     * @param \context $context
     * @return void
     */
    public function set_parent_context(\context $context): void {
        $this->parentcontext = $context;
    }

    /**
     * The current user can configure custom fields on this component.
     *
     * @return bool
     */
    public function can_configure(): bool {
        return has_capability('enrol/programs:configurecustomfields', $this->get_configuration_context());
    }

    /**
     * The current user can edit custom fields on the given program.
     *
     * @param field_controller $field
     * @param int $instanceid
     * @return bool
     */
    public function can_edit(field_controller $field, int $instanceid = 0): bool {
        return has_capability('enrol/programs:edit', $this->get_instance_context($instanceid));
    }

    /**
     * The current user can view custom fields on the given program.
     *
     * @param field_controller $field
     * @param int $instanceid
     * @return bool
     */
    public function can_view(field_controller $field, int $instanceid): bool {
        $visibility = $field->get_configdata_property('visibility');
        if ($visibility === null) {
            $visibility = self::VISIBLETOALL;
        }
        if ($visibility == self::NOTVISIBLE) {
            return false;
        } else if ($visibility == self::VISIBLETOMANAGERS) {
            return has_capability('enrol/programs:view', $this->get_instance_context($instanceid));
        }
        return true;
    }

    /**
     * Context that should be used for new categories created by this handler.
     *
     * @return \context
     */
    public function get_configuration_context(): \context {
        return \context_system::instance();
    }

    /**
     * URL for configuration of the fields on this handler.
     *
     * @return \moodle_url
     */
    public function get_configuration_url(): \moodle_url {
        return new \moodle_url('/enrol/programs/management/customfield.php');
    }

    /**
     * Returns the context for the data associated with the given instanceid.
     *
     * @param int $instanceid
     * @return \context
     */
    public function get_instance_context(int $instanceid = 0): \context {
        global $DB;
        if ($instanceid > 0) {
            $contextid = $DB->get_field('enrol_programs_programs', 'contextid', ['id' => $instanceid]);
            if ($contextid) {
                return \context::instance_by_id($contextid);
            }
        }
        if ($this->parentcontext) {
            return $this->parentcontext;
        }
        return \context_system::instance();
    }

    /**
     * Allows to add custom controls to the field configuration form that will be saved in configdata.
     *
     * @param \MoodleQuickForm $mform
     * @return void
     */
    public function config_form_definition(\MoodleQuickForm $mform): void {
        $mform->addElement('header', 'program_handler_header', get_string('customfieldsettings', 'enrol_programs'));
        $mform->setExpanded('program_handler_header', true);

        $visibilityoptions = [
            self::VISIBLETOALL => get_string('customfield_visibletoall', 'enrol_programs'),
            self::VISIBLETOMANAGERS => get_string('customfield_visibletomanagers', 'enrol_programs'),
            self::NOTVISIBLE => get_string('customfield_notvisible', 'enrol_programs'),
        ];
        $mform->addElement('select', 'configdata[visibility]', get_string('customfield_visibility', 'enrol_programs'), $visibilityoptions);
    }
}
