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

namespace enrol_programs\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/externallib.php");

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

/**
 * External function to get filtered catalogue programs for AJAX.
 *
 * @package    enrol_programs
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_catalogue_programs extends external_api {
    /**
     * Describes the external function arguments.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'searchtext' => new external_value(PARAM_RAW, 'Search keyword', VALUE_DEFAULT, ''),
            'tag' => new external_value(PARAM_TAG, 'Tag filter', VALUE_DEFAULT, ''),
            'customfields' => new external_value(PARAM_RAW, 'JSON encoded custom field filters', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Page number', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Number of programs per page', VALUE_DEFAULT, 10),
        ]);
    }

    /**
     * Executes the external function.
     *
     * @param string $searchtext
     * @param string $tag
     * @param string $customfields
     * @param int $page
     * @param int $perpage
     * @return array
     */
    public static function execute(
        string $searchtext = '',
        string $tag = '',
        string $customfields = '',
        int $page = 0,
        int $perpage = 10
    ): array {
        global $PAGE, $OUTPUT;

        $params = self::validate_parameters(self::execute_parameters(), [
            'searchtext' => $searchtext,
            'tag' => $tag,
            'customfields' => $customfields,
            'page' => $page,
            'perpage' => $perpage,
        ]);

        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        require_capability('enrol/programs:viewcatalogue', $syscontext);

        if (!$PAGE->has_set_url()) {
            $PAGE->set_url(new \moodle_url('/enrol/programs/catalogue/index.php'));
        }

        $request = [
            'searchtext' => $params['searchtext'],
            'tag' => $params['tag'],
            'page' => $params['page'],
            'perpage' => $params['perpage'],
        ];
        if (!empty($params['customfields'])) {
            $request['customfields'] = $params['customfields'];
        }

        $catalogue = new \enrol_programs\local\catalogue($request);
        $totalcount = $catalogue->count_programs();
        $programs = $catalogue->get_programs();
        $carddata = $catalogue->get_programs_card_data($programs);

        // Render cards HTML.
        $cardshtml = '';
        if (!empty($carddata)) {
            $cardshtml = $OUTPUT->render_from_template('enrol_programs/program_card', ['items' => $carddata]);
        }

        // Render pagination HTML if needed.
        $paginationhtml = '';
        if ($totalcount > $params['perpage']) {
            $paginationhtml = $OUTPUT->paging_bar($totalcount, $params['page'], $params['perpage'], $catalogue->get_current_url());
        }

        return [
            'totalcount' => $totalcount,
            'showing_str' => get_string('showingprograms', 'enrol_programs', $totalcount),
            'cardshtml' => $cardshtml,
            'paginationhtml' => $paginationhtml,
            'hasprograms' => !empty($carddata),
        ];
    }

    /**
     * Describes the external function return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'totalcount' => new external_value(PARAM_INT, 'Total count of matching programs'),
            'showing_str' => new external_value(PARAM_RAW, 'Localized showing count string'),
            'cardshtml' => new external_value(PARAM_RAW, 'Pre-rendered cards HTML'),
            'paginationhtml' => new external_value(PARAM_RAW, 'Pre-rendered pagination HTML'),
            'hasprograms' => new external_value(PARAM_BOOL, 'Whether there are matching programs'),
        ]);
    }
}
