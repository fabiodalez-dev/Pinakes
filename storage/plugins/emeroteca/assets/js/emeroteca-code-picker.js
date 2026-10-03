// Searchable pickers for coded fields (#412): language, country, currency.
//
// A <select data-code-picker> lists "Name (code)" and posts the code; this
// turns it into the same Choices.js search box as the author picker, so the
// cataloguer types "danese" or "dan" and never has to know the code. Without
// JavaScript, or without Choices, the plain <select> still works.
//
// Strings come from the select's data attributes (data-search-placeholder,
// data-no-results), already translated by the view.
(function () {
    'use strict';

    function init(attempts) {
        if (typeof window.Choices !== 'function') {
            // vendor.bundle.js is deferred: wait for it briefly, then give up
            // and leave the native select in place.
            if (attempts > 0) {
                window.setTimeout(function () { init(attempts - 1); }, 200);
            }
            return;
        }
        document.querySelectorAll('select[data-code-picker]').forEach(function (select) {
            if (select.dataset.codePickerReady === '1') {
                return;
            }
            select.dataset.codePickerReady = '1';
            new window.Choices(select, {
                searchEnabled: true,
                shouldSort: false,
                searchResultLimit: -1,
                itemSelectText: '',
                allowHTML: false,
                noResultsText: select.dataset.noResults || '',
                searchPlaceholderValue: select.dataset.searchPlaceholder || '',
                fuseOptions: { threshold: 0.3 }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(20); });
    } else {
        init(20);
    }
})();
