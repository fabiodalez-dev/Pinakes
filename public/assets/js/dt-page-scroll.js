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
 *
 * A list with a pager above and one below the table gets two navigation
 * landmarks that DataTables names "pagination" alike, so a screen reader
 * listing landmarks cannot tell them apart. Each one is renamed after where
 * it sits, in the user's language. The <nav> is built once at init and only
 * its buttons are redrawn, so the name set here stays.
 *
 * When the window goes back up, the focus goes with it. DataTables gives the
 * focus back to the button that was used, in the pager below, which the
 * scroll has just taken off screen: a keyboard user would see the first rows
 * while the next Tab or Enter acted down there. The same button in the pager
 * above gets the focus instead. Where that one cannot act (Next or Last on
 * the last page), the current page number gets it. No focus moves after a
 * mouse click in a browser that does not focus buttons on click (Safari).
 */
(function () {
    'use strict';

    function start($) {
        if (!$ || !$.fn || window.__pkDtPageScroll) {
            return;
        }
        window.__pkDtPageScroll = true;

        var t = typeof window.__ === 'function' ? window.__ : function (s) { return s; };

        function labelPagers(table) {
            var container = table.closest('.dt-container, .dataTables_wrapper');
            if (!container) {
                return;
            }
            var navs = container.querySelectorAll('.dt-paging nav');
            if (navs.length < 2) {
                return;
            }
            Array.prototype.forEach.call(navs, function (nav) {
                var above = nav.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING;
                nav.setAttribute('aria-label', above ? t('Paginazione sopra la tabella') : t('Paginazione sotto la tabella'));
            });
        }

        // The data-dt-idx ("next", "last", a page number…) of the pager button
        // that holds the focus, when that pager sits below this table; else null.
        function focusedButtonBelow(table) {
            var el = document.activeElement;
            var nav = el && el.closest ? el.closest('.dt-paging nav') : null;
            if (!nav || !el.hasAttribute('data-dt-idx')
                || nav.closest('.dt-container, .dataTables_wrapper') !== table.closest('.dt-container, .dataTables_wrapper')
                || !(table.compareDocumentPosition(nav) & Node.DOCUMENT_POSITION_FOLLOWING)) {
                return null;
            }
            return el.getAttribute('data-dt-idx');
        }

        // Puts the focus on the button of the pager above the table that does
        // what the button `idx` below did, or on the current page number when
        // that one is now disabled. Without moving the window: it is scrolling.
        function focusPagerAbove(table, idx) {
            var container = table.closest('.dt-container, .dataTables_wrapper');
            var navs = container ? container.querySelectorAll('.dt-paging nav') : [];
            var nav = Array.prototype.filter.call(navs, function (n) {
                return n.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING;
            })[0];
            if (!nav) {
                return;
            }
            var same = nav.querySelector('[data-dt-idx="' + idx + '"]');
            var target = same && same.getAttribute('aria-disabled') !== 'true'
                ? same
                : nav.querySelector('[aria-current="page"]');
            if (target) {
                target.focus({ preventScroll: true });
            }
        }

        function wire(table) {
            if (!table || table.__pkPageScroll) {
                return;
            }
            table.__pkPageScroll = true;
            labelPagers(table);
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
                // Each page change replaces the decision of the one before:
                // a change from the pager above, made while an earlier change
                // from below still waits for its data, must not scroll.
                $(table).off('.pkPageScroll');
                if (table.getBoundingClientRect().top >= 0) {
                    return;
                }
                // A page request that fails never draws: drop the scroll
                // then, or the next unrelated redraw (search, sort) would
                // run it.
                $(table).one('xhr.dt.pkPageScroll', function (ev, settings, json) {
                    if (json === null) {
                        $(table).off('.pkPageScroll');
                    }
                });
                $(table).one('draw.dt.pkPageScroll', function () {
                    $(table).off('.pkPageScroll');
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
                    // Read after the redraw: DataTables has put the focus back
                    // on the pager below only if it was still there. Focus the
                    // user moved elsewhere while the page loaded stays put.
                    var usedBelow = focusedButtonBelow(table);
                    if (usedBelow !== null) {
                        focusPagerAbove(table, usedBelow);
                    }
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
