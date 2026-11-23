/*
 * Admin lists (#544): after a page change, bring the top of the table back
 * into view.
 *
 * With 100 rows per page the pager at the bottom sits far below the first
 * row, and DataTables keeps the scroll position, so the next page opened
 * showing its last rows. When the user changed page from below the start of
 * the table, the first row of the new page is scrolled back into view once
 * the page is drawn (server-side tables draw only after their AJAX answer).
 * A page change made from the pager above the table, already in view, leaves
 * the scroll where it is.
 *
 * Covers every DataTable in the admin layout. DataTables 2 does not bubble its
 * "page" event, but it does bubble "init": each table is wired when it
 * initialises (plus any table that already exists when this file runs).
 */
(function () {
    'use strict';

    function start($) {
        if (!$ || !$.fn || window.__pkDtPageScroll) {
            return;
        }
        window.__pkDtPageScroll = true;

        function wire(table) {
            if (!table || table.__pkPageScroll) {
                return;
            }
            table.__pkPageScroll = true;
            $(table).on('page.dt', function (e) {
                if (e.target !== table) {
                    return;
                }
                // Decide now, when the page changes, not after the redraw: the
                // user may scroll while a server-side list waits for its data.
                // The table itself, not its wrapper, is the reference: the
                // pager above sits over the table, so when that pager is in
                // view the table top is too, even if the wrapper's own top
                // edge (padding, page-size row) has left the window.
                if (table.getBoundingClientRect().top >= 0) {
                    return;
                }
                $(table).one('draw.dt', function () {
                    var anchor = table.closest('.dt-container, .dataTables_wrapper') || table;
                    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    // Leave a little room above the table, plus the height of
                    // any header that is pinned to the top of the window
                    // right now (the admin header scrolls away on most widths).
                    var offset = 16;
                    var header = document.querySelector('header.sticky, header.fixed');
                    if (header) {
                        var box = header.getBoundingClientRect();
                        if (box.top <= 0 && box.bottom > 0) {
                            offset += box.bottom;
                        }
                    }
                    var target = anchor.getBoundingClientRect().top + window.pageYOffset - offset;
                    window.scrollTo({ top: Math.max(0, target), behavior: reduce ? 'auto' : 'smooth' });
                });
            });
        }

        $(document).on('init.dt', function (e, settings) {
            wire(settings && settings.nTable);
        });
        if ($.fn.dataTable && typeof $.fn.dataTable.tables === 'function') {
            $.fn.dataTable.tables().forEach(wire);
        }
    }

    if (window.jQuery) {
        start(window.jQuery);
    } else {
        // vendor.bundle.js (jQuery + DataTables) is deferred: wait for it.
        document.addEventListener('DOMContentLoaded', function () {
            start(window.jQuery);
        });
    }
})();
