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

use stdClass;
use moodle_url;

/**
 * Program catalogue for learners.
 *
 * @package    enrol_programs
 * @copyright  2022 Open LMS (https://www.openlms.net/)
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @author     Petr Skoda
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catalogue {
    /** @var int page number */
    protected $page = 0;
    /** @var int number of programs per page */
    protected $perpage = 10;
    /** @var ?string search text */
    protected $searchtext = null;
    /** @var ?string tag filter */
    protected $tag = null;
    /** @var array custom fields filters [shortname => [values]] */
    protected $customfields = [];

    /**
     * Creates catalogue instance.
     *
     * @param array $request
     */
    public function __construct(array $request) {
        // NOTE: we do not care about CSRF here, because there are no data modifications in Catalogue,
        // we DO want to allow and encourage bookmarking of catalogue URLs.
        if (isset($request['page'])) {
            $page = clean_param($request['page'], PARAM_INT);
            if ($page > 0) {
                $this->page = $page;
            }
        }
        if (isset($request['perpage'])) {
            $perpage = clean_param($request['perpage'], PARAM_INT);
            if ($perpage > 0) {
                $this->perpage = $perpage;
            }
        }
        if (isset($request['searchtext'])) {
            $searchtext = clean_param($request['searchtext'], PARAM_RAW);
            if (\core_text::strlen($searchtext) > 1) {
                $this->searchtext = $searchtext;
            }
        }
        if (isset($request['tag'])) {
            $tag = clean_param($request['tag'], PARAM_TAG);
            if ($tag !== '') {
                $this->tag = $tag;
            }
        }
        if (!empty($request['customfields'])) {
            $cfs = $request['customfields'];
            if (is_string($cfs)) {
                $cfs = json_decode($cfs, true);
            }
            if (is_array($cfs)) {
                foreach ($cfs as $shortname => $vals) {
                    $cleanshortname = clean_param($shortname, PARAM_ALPHANUMEXT);
                    if ($cleanshortname === '') {
                        continue;
                    }
                    if (!is_array($vals)) {
                        $vals = [$vals];
                    }
                    $cleanvals = [];
                    foreach ($vals as $val) {
                        $cleanval = clean_param($val, PARAM_RAW);
                        if ($cleanval !== '') {
                            $cleanvals[] = $cleanval;
                        }
                    }
                    if (!empty($cleanvals)) {
                        $this->customfields[$cleanshortname] = $cleanvals;
                    }
                }
            }
        }
        foreach ($request as $k => $v) {
            if (strpos($k, 'cf_') === 0) {
                $shortname = clean_param(substr($k, 3), PARAM_ALPHANUMEXT);
                if ($shortname === '') {
                    continue;
                }
                if (!is_array($v)) {
                    $v = [$v];
                }
                foreach ($v as $val) {
                    $cleanval = clean_param($val, PARAM_RAW);
                    if ($cleanval !== '') {
                        $this->customfields[$shortname][] = $cleanval;
                    }
                }
            }
        }
    }

    /**
     * Current catalogue URL.
     *
     * @return moodle_url
     */
    public function get_current_url(): moodle_url {
        $pageparams = [];
        if ($this->page != 0) {
            $pageparams['page'] = $this->page;
        }
        if ($this->perpage != 10) {
            $pageparams['perpage'] = $this->perpage;
        }
        if ($this->searchtext !== null) {
            $pageparams['searchtext'] = $this->searchtext;
        }
        if ($this->tag !== null) {
            $pageparams['tag'] = $this->tag;
        }
        if (!empty($this->customfields)) {
            $pageparams['customfields'] = json_encode($this->customfields);
        }
        return new moodle_url('/enrol/programs/catalogue/index.php', $pageparams);
    }

    /**
     * Are we filtering results?
     *
     * @return bool
     */
    public function is_filtering(): bool {
        if ($this->searchtext !== null || $this->tag !== null || !empty($this->customfields)) {
            return true;
        }
        return false;
    }

    /**
     * Returns page number.
     *
     * @return int
     */
    public function get_page(): int {
        return $this->page;
    }

    /**
     * Returns number of programs per page.
     *
     * @return int
     */
    public function get_perpage(): int {
        return $this->perpage;
    }

    /**
     * Returns search text.
     *
     * @return string|null
     */
    public function get_searchtext(): ?string {
        return $this->searchtext;
    }

    /**
     * Returns active tag filter.
     *
     * @return string|null
     */
    public function get_tag(): ?string {
        return $this->tag;
    }

    /**
     * Returns active custom fields filters.
     *
     * @return array
     */
    public function get_customfields(): array {
        return $this->customfields;
    }

    /**
     * Returns hidden text search params.
     *
     * @return array
     */
    public function get_hidden_search_fields(): array {
        $result = [];
        if ($this->page > 0) {
            $result['page'] = $this->page;
        }
        if ($this->perpage != 10) {
            $result['perpage'] = $this->perpage;
        }
        if ($this->tag !== null) {
            $result['tag'] = $this->tag;
        }
        return $result;
    }

    /**
     * Formats program records into card data for Mustache templates.
     *
     * @param array $programs
     * @return array
     */
    public function get_programs_card_data(array $programs): array {
        global $DB, $USER, $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (empty($programs)) {
            return [];
        }

        $items = [];
        $cfhandler = null;
        if (class_exists('\core_customfield\handler')) {
            try {
                $cfhandler = \core_customfield\handler::get_handler('enrol_programs', 'program');
            } catch (\Exception $e) {
                $cfhandler = null;
            }
        }

        foreach ($programs as $program) {
            $context = \context::instance_by_id($program->contextid);
            $allocation = $DB->get_record('enrol_programs_allocations', [
                'programid' => $program->id,
                'userid' => $USER->id,
                'archived' => 0,
            ]);

            $isallocated = !empty($allocation);
            $allocationcompleted = false;
            $statusbadge = '';

            if ($allocation) {
                $url = new moodle_url('/enrol/programs/my/program.php', ['id' => $program->id]);
                if (!empty($allocation->timecompleted)) {
                    $allocationcompleted = true;
                }
                $statusbadge = allocation::get_completion_status_html($program, $allocation);
                $buttontext = get_string('continueprogram', 'enrol_programs');
                $buttonclass = 'btn-primary';
            } else {
                $url = new moodle_url('/enrol/programs/catalogue/program.php', ['id' => $program->id]);
                $buttontext = get_string('viewprogram', 'enrol_programs');
                $buttonclass = 'btn-outline-primary';
            }

            // Image URL.
            $imageurl = '';
            $presentation = (array)json_decode($program->presentationjson ?? '');
            if (!empty($presentation['image'])) {
                $imageurl = moodle_url::make_file_url(
                    "$CFG->wwwroot/pluginfile.php",
                    '/' . $context->id . '/enrol_programs/image/' . $program->id . '/' . $presentation['image'],
                    false
                )->out(false);
            }

            // Clean description and excerpt for card and popup.
            $rawdesc = file_rewrite_pluginfile_urls(
                $program->description ?? '',
                'pluginfile.php',
                $context->id,
                'enrol_programs',
                'description',
                $program->id
            );
            $formatteddesc = format_text($rawdesc, $program->descriptionformat, ['context' => $context]);
            $plaindesc = trim(html_to_text($formatteddesc, 0, false));
            $popupcontent = \core_text::substr($plaindesc, 0, 300);
            if (\core_text::strlen($plaindesc) > 300) {
                $popupcontent .= '...';
            }

            // Courses included count.
            $itemscount = $DB->count_records_select(
                'enrol_programs_items',
                'programid = :pid AND courseid IS NOT NULL',
                ['pid' => $program->id]
            );

            // Credits and points rewards.
            $totalcredits = 0.0;
            $totalpoints = 0;
            try {
                $top = program::load_content($program->id);
                $totalcredits = $top->get_total_available_credits();
                $totalpoints = $top->get_total_available_points();
            } catch (\Exception $e) {
                // Content might be empty or invalid.
                unset($e);
            }

            $formattedcredits = '';
            if ($totalcredits > 0) {
                $formattedcredits = rtrim(rtrim(number_format($totalcredits, 2), '0'), '.');
            }

            // Custom fields.
            $customfieldbadges = [];
            if ($cfhandler) {
                try {
                    $datas = $cfhandler->get_instance_data($program->id);
                    foreach ($datas as $cfdata) {
                        if ($cfhandler->can_view($cfdata->get_field(), $program->id)) {
                            $cfval = $cfdata->export_value();
                            if ($cfval !== null && $cfval !== '') {
                                $customfieldbadges[] = [
                                    'name' => $cfdata->get_field()->get_formatted_name(),
                                    'value' => $cfval,
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore custom field errors.
                    unset($e);
                }
            }

            // Tags.
            $tags = [];
            if ($CFG->usetags) {
                $itemtags = \core_tag_tag::get_item_tags('enrol_programs', 'program', $program->id);
                if ($itemtags) {
                    foreach ($itemtags as $t) {
                        $tags[] = [
                            'name' => $t->get_display_name(),
                            'rawname' => $t->rawname,
                        ];
                    }
                }
            }

            $creditsstr = '';
            if ($totalcredits > 0) {
                $creditslabel = \enrol_programs\local\trophy_bridge::get_type_label('credithours', (float)$totalcredits);
                $creditsstr = $formattedcredits . ' ' . $creditslabel;
            }
            $pointsstr = '';
            if ($totalpoints > 0) {
                if (\enrol_programs\local\trophy_bridge::has_custom_name('points')) {
                    $pointslabel = \enrol_programs\local\trophy_bridge::get_type_label('points', (float)$totalpoints);
                    $pointsstr = $totalpoints . ' ' . $pointslabel;
                } else {
                    $pointsstr = get_string('points_badge', 'enrol_programs', $totalpoints);
                }
            }
            $coursesstr = get_string('coursesincluded', 'enrol_programs', $itemscount);

            $items[] = [
                'id' => $program->id,
                'name' => format_string($program->fullname),
                'idnumber' => $program->idnumber,
                'url' => $url->out(false),
                'imageurl' => $imageurl,
                'hasimage' => !empty($imageurl),
                'description' => $formatteddesc,
                'popupcontent' => $popupcontent,
                'isallocated' => $isallocated,
                'allocationcompleted' => $allocationcompleted,
                'statusbadge' => $statusbadge,
                'itemscount' => $itemscount,
                'hasitems' => ($itemscount > 0),
                'courses_str' => $coursesstr,
                'totalcredits' => $formattedcredits,
                'hascredits' => ($totalcredits > 0),
                'credits_str' => $creditsstr,
                'totalpoints' => $totalpoints,
                'haspoints' => ($totalpoints > 0),
                'points_str' => $pointsstr,
                'customfields' => $customfieldbadges,
                'hascustomfields' => !empty($customfieldbadges),
                'tags' => $tags,
                'hastags' => !empty($tags),
                'buttontext' => $buttontext,
                'buttonclass' => $buttonclass,
            ];
        }

        return $items;
    }

    /**
     * Returns list of all available tags with counts of visible programs.
     *
     * @return array
     */
    public function get_available_tags(): array {
        global $DB, $USER, $CFG;

        if (!$CFG->usetags) {
            return [];
        }

        $params = ['userid1' => $USER->id, 'userid2' => $USER->id];

        $tenantjoin = "";
        if (tenant::is_active()) {
            $tenantid = \tool_olms_tenant\tenancy::get_tenant_id();
            if ($tenantid) {
                $tenantjoin = "JOIN {context} pc ON pc.id = p.contextid AND (pc.tenantid IS NULL OR pc.tenantid = :tenantid)";
                $params['tenantid'] = $tenantid;
            }
        }

        $sql = "SELECT t.id, t.name, t.rawname, COUNT(DISTINCT p.id) AS programcount
                  FROM {tag} t
                  JOIN {tag_instance} tt ON tt.itemtype = 'program' AND tt.tagid = t.id AND tt.component = 'enrol_programs'
                  JOIN {enrol_programs_programs} p ON p.id = tt.itemid
             LEFT JOIN {enrol_programs_allocations} pa ON pa.programid = p.id AND pa.userid = :userid1 AND pa.archived = 0
                  $tenantjoin
                 WHERE p.archived = 0
                       AND (p.public = 1 OR pa.id IS NOT NULL OR EXISTS (
                            SELECT cm.id
                              FROM {cohort_members} cm
                              JOIN {enrol_programs_cohorts} pc ON pc.cohortid = cm.cohortid
                             WHERE cm.userid = :userid2 AND pc.programid = p.id))
              GROUP BY t.id, t.name, t.rawname
              ORDER BY t.rawname ASC";

        $records = $DB->get_records_sql($sql, $params);
        $tags = [];
        foreach ($records as $record) {
            $tags[] = [
                'id' => $record->id,
                'name' => format_string($record->rawname),
                'rawname' => $record->rawname,
                'count' => (int)$record->programcount,
                'isactive' => ($this->tag !== null && ($this->tag === $record->rawname || $this->tag === $record->name)),
            ];
        }

        return $tags;
    }

    /**
     * Returns custom field filter definitions for the sidebar.
     *
     * @return array
     */
    public function get_customfield_filters(): array {
        $filters = [];

        if (!class_exists('\core_customfield\handler')) {
            return $filters;
        }

        try {
            $handler = \core_customfield\handler::get_handler('enrol_programs', 'program');
            $categories = $handler->get_categories_with_fields();
        } catch (\Exception $e) {
            unset($e);
            return $filters;
        }

        foreach ($categories as $cat) {
            foreach ($cat->get_fields() as $field) {
                $type = $field->get('type');
                $shortname = $field->get('shortname');
                $options = [];

                if ($type === 'select') {
                    if (method_exists($field, 'get_options')) {
                        $fieldoptions = $field->get_options();
                        foreach ($fieldoptions as $optkey => $optval) {
                            $optval = trim($optval);
                            if ($optval !== '') {
                                $selected = false;
                                if (!empty($this->customfields[$shortname])) {
                                    $selected = in_array((string)$optkey, $this->customfields[$shortname])
                                        || in_array($optval, $this->customfields[$shortname]);
                                }
                                $options[] = [
                                    'value' => (string)$optkey,
                                    'label' => format_string($optval),
                                    'selected' => $selected,
                                ];
                            }
                        }
                    }
                } else if ($type === 'checkbox') {
                    $selected = false;
                    if (!empty($this->customfields[$shortname])) {
                        $selected = in_array('1', $this->customfields[$shortname]);
                    }
                    $options[] = [
                        'value' => '1',
                        'label' => get_string('yes'),
                        'selected' => $selected,
                    ];
                }

                if (!empty($options)) {
                    $filters[] = [
                        'shortname' => $shortname,
                        'name' => $field->get_formatted_name(),
                        'options' => $options,
                        'has_selected' => !empty($this->customfields[$shortname]),
                    ];
                }
            }
        }

        return $filters;
    }

    /**
     * Render program listing using modern catalogue Mustache template.
     *
     * @return string
     */
    public function render_programs(): string {
        global $OUTPUT;

        $totalcount = $this->count_programs();
        $programs = $this->get_programs();
        $carddata = $this->get_programs_card_data($programs);

        // Pre-render cards.
        $cardshtml = '';
        if (!empty($carddata)) {
            $cardshtml = $OUTPUT->render_from_template('enrol_programs/program_card', ['items' => $carddata]);
        }

        // Pre-render pagination.
        $paginationhtml = '';
        if ($totalcount > $this->perpage) {
            $paginationhtml = $OUTPUT->paging_bar($totalcount, $this->page, $this->perpage, $this->get_current_url());
        }

        // Tags with program counts.
        $availabletags = $this->get_available_tags();

        // Total count of all active programs (for 'All Programs' badge).
        $allcatalogue = new catalogue([]);
        $allcount = $allcatalogue->count_programs();

        // Custom field filters.
        $cffilters = $this->get_customfield_filters();

        $templatedata = [
            'searchtext' => $this->searchtext ?? '',
            'activetag' => $this->tag ?? '',
            'hastag' => ($this->tag !== null && $this->tag !== ''),
            'allcount' => $allcount,
            'isallactive' => empty($this->tag),
            'tags' => $availabletags,
            'hastags' => !empty($availabletags),
            'customfield_filters' => $cffilters,
            'has_cffilters' => !empty($cffilters),
            'totalcount' => $totalcount,
            'showing_str' => get_string('showingprograms', 'enrol_programs', $totalcount),
            'cardshtml' => $cardshtml,
            'paginationhtml' => $paginationhtml,
            'hasprograms' => !empty($carddata),
            'is_filtering' => $this->is_filtering(),
        ];

        return $OUTPUT->render_from_template('enrol_programs/catalogue', $templatedata);
    }

    /**
     * Returns visible programs.
     *
     * @return array
     */
    public function get_programs(): array {
        global $DB;

        [$sql, $params] = $this->get_programs_sql();
        return $DB->get_records_sql($sql, $params, $this->page * $this->perpage, $this->perpage);
    }

    /**
     * Returns filtered count of programs on all pages.
     *
     * @return int
     */
    public function count_programs(): int {
        global $DB;

        [$sql, $params] = $this->get_programs_sql();

        $sql = util::convert_to_count_sql($sql);

        return $DB->count_records_sql($sql, $params);
    }

    /**
     * Returns SQL to fetch filtered programs.
     *
     * @return array
     */
    protected function get_programs_sql(): array {
        global $DB, $USER;

        $params = ['userid1' => $USER->id, 'userid2' => $USER->id];

        $searchwhere = '';
        if (isset($this->searchtext)) {
            $concat = $DB->sql_concat_join("' '", ['p.fullname', 'p.description', 'p.idnumber']);
            $searchwhere = 'AND ' . $DB->sql_like("($concat)", ':searchtext', false, false);
            $params['searchtext'] = '%' . $DB->sql_like_escape($this->searchtext) . '%';
        }

        $tagwhere = '';
        if ($this->tag !== null && $this->tag !== '') {
            $tagwhere = "AND EXISTS (
                SELECT 1
                  FROM {tag_instance} ti
                  JOIN {tag} t ON t.id = ti.tagid
                 WHERE ti.itemtype = 'program'
                   AND ti.component = 'enrol_programs'
                   AND ti.itemid = p.id
                   AND (t.rawname = :tagname OR t.name = :tagname2)
            )";
            $params['tagname'] = $this->tag;
            $params['tagname2'] = \core_text::strtolower($this->tag);
        }

        $cfwhere = '';
        if (!empty($this->customfields)) {
            $cfidx = 0;
            foreach ($this->customfields as $shortname => $values) {
                if (!is_array($values)) {
                    $values = [$values];
                }
                $values = array_filter(array_map('trim', $values), function ($v) {
                    return $v !== '';
                });
                if (empty($values)) {
                    continue;
                }
                $cfidx++;
                [$insql, $inparams] = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED, 'cfv' . $cfidx . '_');
                $fieldparam = 'cff' . $cfidx;
                $cfwhere .= " AND EXISTS (
                    SELECT 1
                      FROM {customfield_data} cfd{$cfidx}
                      JOIN {customfield_field} cff{$cfidx} ON cff{$cfidx}.id = cfd{$cfidx}.fieldid
                     WHERE cfd{$cfidx}.instanceid = p.id
                       AND cff{$cfidx}.shortname = :{$fieldparam}
                       AND (cfd{$cfidx}.intvalue $insql OR cfd{$cfidx}.charvalue $insql)
                )";
                $params[$fieldparam] = $shortname;
                $params = array_merge($params, $inparams);
            }
        }

        $tenantjoin = "";
        if (tenant::is_active()) {
            $tenantid = \tool_olms_tenant\tenancy::get_tenant_id();
            if ($tenantid) {
                $tenantjoin = "JOIN {context} pc ON pc.id = p.contextid AND (pc.tenantid IS NULL OR pc.tenantid = :tenantid)";
                $params['tenantid'] = $tenantid;
            }
        }

        $sql = "SELECT p.*
                  FROM {enrol_programs_programs} p
             LEFT JOIN {enrol_programs_allocations} pa ON pa.programid = p.id AND pa.userid = :userid1 AND pa.archived = 0
                  $tenantjoin
                 WHERE p.archived = 0 $searchwhere $tagwhere $cfwhere
                       AND (p.public = 1 OR pa.id IS NOT NULL OR EXISTS (
                            SELECT cm.id
                              FROM {cohort_members} cm
                              JOIN {enrol_programs_cohorts} pc ON pc.cohortid = cm.cohortid
                             WHERE cm.userid = :userid2 AND pc.programid = p.id))
              ORDER BY p.fullname ASC";

        return [$sql, $params];
    }

    /**
     * Is program visible for the user?
     *
     * @param stdClass $program
     * @param int|null $userid
     * @return bool
     */
    public static function is_program_visible(stdClass $program, ?int $userid = null): bool {
        global $DB, $USER;

        if (!enrol_is_enabled('programs')) {
            return false;
        }

        if ($userid === null) {
            $userid = $USER->id;
        }

        if ($program->archived) {
            return false;
        }

        if (\enrol_programs\local\tenant::is_active()) {
            if ($userid == $USER->id) {
                $tenantid = \tool_olms_tenant\tenancy::get_tenant_id();
            } else {
                $tenantid = \tool_olms_tenant\tenant_users::get_user_tenant_id($userid);
            }
            if ($tenantid) {
                $programcontext = \context::instance_by_id($program->contextid);
                $programtenantid = \tool_olms_tenant\tenants::get_context_tenant_id($programcontext);
                if ($programtenantid && $programtenantid != $tenantid) {
                    return false;
                }
            }
        }

        if ($program->public) {
            return true;
        }
        if ($DB->record_exists('enrol_programs_allocations', ['programid' => $program->id, 'userid' => $userid, 'archived' => 0])) {
            return true;
        }
        $sql = "SELECT 1
                  FROM {enrol_programs_cohorts} c
                  JOIN {cohort_members} cm ON cm.cohortid = c.cohortid AND cm.userid = :userid
                 WHERE c.programid = :programid";
        $params = ['programid' => $program->id, 'userid' => $userid];
        if ($DB->record_exists_sql($sql, $params)) {
            return true;
        }
        return false;
    }

    /**
     * Returns link to Program catalogue.
     *
     * @return ?moodle_url null if programs disabled or user cannot access catalogue
     */
    public static function get_catalogue_url(): ?moodle_url {
        if (!enrol_is_enabled('programs')) {
            return null;
        }
        if (!isloggedin()) {
            return null;
        }
        if (!has_capability('enrol/programs:viewcatalogue', \context_system::instance())) {
            return null;
        }
        return new moodle_url('/enrol/programs/catalogue/index.php');
    }

    /**
     * Returns list of all tags of programs that user may see or is allocated to.
     *
     * @param ?int $userid
     * @return array [tagid => tagname]
     */
    public function get_used_tags(?int $userid = null): array {
        global $USER, $DB, $CFG;

        if (!$CFG->usetags) {
            return [];
        }

        if ($userid === null) {
            $userid = $USER->id;
        }

        $sql = "SELECT DISTINCT t.id, t.name
                  FROM {tag} t
                  JOIN {tag_instance} tt ON tt.itemtype = 'program' AND tt.tagid = t.id AND tt.component = 'enrol_programs'
                  JOIN {enrol_programs_programs} p ON p.id = tt.itemid
             LEFT JOIN {enrol_programs_allocations} pa ON pa.programid = p.id AND pa.userid = :userid1 AND pa.archived = 0
                 WHERE p.archived = 0
                       AND (p.public = 1 OR pa.id IS NOT NULL OR EXISTS (
                            SELECT cm.id
                              FROM {cohort_members} cm
                              JOIN {enrol_programs_cohorts} pc ON pc.cohortid = cm.cohortid
                             WHERE cm.userid = :userid2 AND pc.programid = p.id))
              ORDER BY t.name ASC";
        $params = ['userid1' => $userid, 'userid2' => $userid];

        $menu = $DB->get_records_sql_menu($sql, $params);
        return array_map('format_string', $menu);
    }

    /**
     * Render programs with a tag that current learner can see.
     *
     * @param int $tagid
     * @param bool $exclusive
     * @param int $limitfrom
     * @param int $limitnum
     * @return array ['content' => string, 'totalcount' => int]
     */
    public static function get_tagged_programs(int $tagid, bool $exclusive, int $limitfrom, int $limitnum): array {
        global $DB, $USER, $OUTPUT;

        $sql = "SELECT p.*
                  FROM {enrol_programs_programs} p
                  JOIN {tag_instance} tt ON tt.itemid = p.id AND tt.itemtype = 'program'
                       AND tt.tagid = :tagid AND tt.component = 'enrol_programs'
             LEFT JOIN {enrol_programs_allocations} pa ON pa.programid = p.id AND pa.userid = :userid1 AND pa.archived = 0
                 WHERE p.archived = 0
                       AND (p.public = 1 OR pa.id IS NOT NULL OR EXISTS (
                             SELECT cm.id
                               FROM {cohort_members} cm
                               JOIN {enrol_programs_cohorts} pc ON pc.cohortid = cm.cohortid
                              WHERE cm.userid = :userid2 AND pc.programid = p.id))
              ORDER BY p.fullname";
        $countsql = util::convert_to_count_sql($sql);
        $params = ['tagid' => $tagid, 'userid1' => $USER->id, 'userid2' => $USER->id];

        $totalcount = $DB->count_records_sql($countsql, $params);
        $programs = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);

        $result = [];
        foreach ($programs as $program) {
            $fullname = format_string($program->fullname);
            $url = new moodle_url('/enrol/programs/catalogue/program.php', ['id' => $program->id]);
            $icon = $OUTPUT->pix_icon('program', '', 'enrol_programs');
            $result[] = '<div class="program-link">' . $icon . \html_writer::link($url, $fullname) . '</div>';
        }

        return ['content' => implode('', $result), 'totalcount' => $totalcount];
    }
}
