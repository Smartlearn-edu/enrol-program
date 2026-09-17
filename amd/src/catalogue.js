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
 * Modern AJAX Catalogue for Programs.
 *
 * @module     enrol_programs/catalogue
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/templates', 'core/config'], function($, Ajax, Templates, Config) {
    'use strict';

    var state = {
        searchtext: '',
        tag: '',
        customfields: {},
        page: 0,
        perpage: 10
    };

    /**
     * Check if any filter is currently applied.
     *
     * @return {boolean}
     */
    function isFiltering() {
        return (state.searchtext !== '' || state.tag !== '' || Object.keys(state.customfields).length > 0);
    }

    /**
     * Sync the current filter state to browser URL query string.
     */
    function syncUrl() {
        var url = new URL(window.location.href);
        if (state.searchtext !== '') {
            url.searchParams.set('searchtext', state.searchtext);
        } else {
            url.searchParams.delete('searchtext');
        }
        if (state.tag !== '') {
            url.searchParams.set('tag', state.tag);
        } else {
            url.searchParams.delete('tag');
        }
        if (Object.keys(state.customfields).length > 0) {
            url.searchParams.set('customfields', JSON.stringify(state.customfields));
        } else {
            url.searchParams.delete('customfields');
        }
        if (state.page > 0) {
            url.searchParams.set('page', state.page);
        } else {
            url.searchParams.delete('page');
        }
        window.history.replaceState({}, '', url);
    }

    /**
     * Fetch filtered programs from the backend web service via AJAX.
     */
    function loadData() {
        $('#ep-cards-container').css('opacity', '0.4');

        var request = {
            methodname: 'enrol_programs_get_catalogue_programs',
            args: {
                searchtext: state.searchtext,
                tag: state.tag,
                customfields: JSON.stringify(state.customfields),
                page: state.page,
                perpage: state.perpage
            }
        };

        Ajax.call([request])[0].done(function(response) {
            $('#ep-cards-container').css('opacity', '1');
            $('#ep-showing-count').text(response.showing_str);

            if (response.hasprograms) {
                $('#ep-cards-container').html(response.cardshtml);
                $('#ep-no-results').addClass('d-none');
            } else {
                $('#ep-cards-container').empty();
                $('#ep-no-results').removeClass('d-none');
            }

            $('#ep-pagination-container').html(response.paginationhtml);

            if (isFiltering()) {
                $('#ep-clear-filters').removeClass('d-none');
            } else {
                $('#ep-clear-filters').addClass('d-none');
            }

            syncUrl();
        }).fail(function(ex) {
            $('#ep-cards-container').css('opacity', '1');
            window.console.error('Error loading catalogue programs', ex);
        });
    }

    return {
        /**
         * Initialize the catalogue module and register all event listeners.
         */
        init: function() {
            var searchDebounceTimer;

            // Initialize state from existing inputs/elements on SSR.
            var $searchInput = $('#ep-search-input');
            if ($searchInput.length) {
                state.searchtext = $searchInput.val().trim();
            }

            var $activeTag = $('.ep-tag-btn.active');
            if ($activeTag.length) {
                state.tag = $activeTag.data('tag') || '';
            }

            $('.ep-cf-checkbox:checked').each(function() {
                var field = $(this).data('field');
                var val = $(this).val();
                if (!state.customfields[field]) {
                    state.customfields[field] = [];
                }
                state.customfields[field].push(val);
            });

            // Parse URL parameters if present on load.
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('searchtext')) {
                state.searchtext = urlParams.get('searchtext');
                $searchInput.val(state.searchtext);
            }
            if (urlParams.has('tag')) {
                state.tag = urlParams.get('tag');
                $('.ep-tag-btn').removeClass('active');
                $('.ep-tag-btn[data-tag="' + state.tag + '"]').addClass('active');
            }
            if (urlParams.has('page')) {
                state.page = parseInt(urlParams.get('page'), 10) || 0;
            }
            if (urlParams.has('customfields')) {
                try {
                    var parsed = JSON.parse(urlParams.get('customfields'));
                    if (typeof parsed === 'object') {
                        state.customfields = parsed;
                        $.each(state.customfields, function(field, vals) {
                            if (Array.isArray(vals)) {
                                vals.forEach(function(v) {
                                    $('#cf_' + field + '_' + v).prop('checked', true);
                                });
                            }
                        });
                    }
                } catch (e) {
                    // Ignore JSON parsing errors.
                }
            }

            // Live Search input debounce.
            $searchInput.on('input', function() {
                clearTimeout(searchDebounceTimer);
                searchDebounceTimer = setTimeout(function() {
                    state.searchtext = $searchInput.val().trim();
                    state.page = 0;
                    loadData();
                }, 350);
            });

            // Tag Pill button clicks.
            $(document).on('click', '.ep-tag-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                if ($btn.hasClass('active')) {
                    return;
                }
                $('.ep-tag-btn').removeClass('active');
                $btn.addClass('active');
                state.tag = $btn.data('tag') || '';
                state.page = 0;
                loadData();
            });

            // Custom Field Checkbox changes.
            $(document).on('change', '.ep-cf-checkbox', function() {
                state.customfields = {};
                $('.ep-cf-checkbox:checked').each(function() {
                    var field = $(this).data('field');
                    var val = $(this).val();
                    if (!state.customfields[field]) {
                        state.customfields[field] = [];
                    }
                    state.customfields[field].push(val);
                });
                state.page = 0;
                loadData();
            });

            // Clear All Filters.
            $(document).on('click', '#ep-clear-filters', function(e) {
                e.preventDefault();
                state.searchtext = '';
                state.tag = '';
                state.customfields = {};
                state.page = 0;

                $searchInput.val('');
                $('.ep-tag-btn').removeClass('active');
                $('.ep-tag-btn[data-tag=""]').addClass('active');
                $('.ep-cf-checkbox').prop('checked', false);

                loadData();
            });

            // Pagination interception.
            $(document).on('click', '#ep-pagination-container .page-link', function(e) {
                var href = $(this).attr('href');
                if (!href) {
                    return;
                }
                var url = new URL(href, window.location.origin);
                var pageParam = url.searchParams.get('page');
                if (pageParam !== null) {
                    e.preventDefault();
                    state.page = parseInt(pageParam, 10) || 0;
                    loadData();
                    var $cards = $('#ep-cards-container');
                    if ($cards.length) {
                        $('html, body').animate({
                            scrollTop: $cards.offset().top - 100
                        }, 300);
                    }
                }
            });

            // Hover popup overflow edge detection.
            $(document).on('mouseenter', '.sc-course-card-wrapper', function() {
                var popup = $(this).find('.sc-hover-popup');
                if (popup.length === 0) {
                    return;
                }
                popup.removeClass('sc-popup-flip sc-popup-left');
                var rect = popup[0].getBoundingClientRect();
                var windowWidth = $(window).width();
                if (rect.right + 10 > windowWidth || rect.left - 10 < 0) {
                    popup.addClass('sc-popup-flip sc-popup-left');
                }
            });

            // Mobile filter close/toggle.
            $(document).on('click', '#ep-close-filters-btn', function() {
                $('#ep-filter-sidebar-col').slideToggle(200);
            });
        }
    };
});
