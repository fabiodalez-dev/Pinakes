/**
 * Flatpickr Auto-initialization for Pinakes
 * Automatically converts all input[type="date"] to flatpickr instances
 * Respects app locale for date formatting and labels
 */

(function() {
  'use strict';

  /**
   * Detect current app locale from HTML lang attribute or meta tag
   * @returns {string} Locale code ('it' or 'en')
   */
  function detectAppLocale() {
    // Locales registered in vendor.js window.flatpickrLocales. English is
    // flatpickr's built-in default. Previously only 'it'/'en' were recognized
    // and everything else fell through to Italian (#281): a German/French/
    // Danish install got an Italian calendar. Now every shipped UI language is
    // mapped, and the fallback is the neutral English rather than Italian.
    const SUPPORTED = ['it', 'en', 'de', 'fr', 'da'];
    const pick = (raw) => {
      const p = (raw || '').slice(0, 2).toLowerCase();
      return SUPPORTED.indexOf(p) !== -1 ? p : null;
    };
    const metaLocale = document.querySelector('meta[name="locale"]');
    return pick(document.documentElement.lang)
      || pick(metaLocale ? metaLocale.getAttribute('content') : '')
      || pick(document.body && document.body.dataset ? document.body.dataset.locale : '')
      || 'en';
  }

  /**
   * Initialize flatpickr on date inputs
   */
  function initFlatpickr() {
    if (!window.flatpickr) {
      console.warn('Flatpickr not loaded. Skipping date picker initialization.');
      return;
    }

    // Find all date inputs that haven't been initialized yet
    // Skip inputs with data-no-flatpickr attribute
    const dateInputs = document.querySelectorAll('input[type="date"]:not(.flatpickr-input):not([data-no-flatpickr])');

    // Detect current locale
    const appLocale = detectAppLocale();
    // Day-first date display for every European UI language we ship; only
    // English uses the month-first m/d/Y convention.
    const dayFirst = appLocale !== 'en';

    // Get locale object from global window.flatpickrLocales
    const localeObj = window.flatpickrLocales ? window.flatpickrLocales[appLocale] : null;

    dateInputs.forEach(input => {
      // Get existing value and attributes
      const existingValue = input.value;
      const minDate = input.getAttribute('min') || null;
      const maxDate = input.getAttribute('max') || null;
      const disabled = input.hasAttribute('disabled');
      const readonly = input.hasAttribute('readonly');

      // Flatpickr configuration with locale-aware formatting
      const config = {
        dateFormat: 'Y-m-d',           // ISO format for backend
        altInput: true,                // Show formatted date to user
        altFormat: dayFirst ? 'd/m/Y' : 'm/d/Y',  // Locale-aware format
        allowInput: !readonly,         // Allow manual input if not readonly
        clickOpens: !disabled,         // Allow opening if not disabled
        defaultDate: existingValue || null,
        minDate: minDate,
        maxDate: maxDate,
        disableMobile: false,          // Use flatpickr on mobile too
      };

      // Apply locale if available
      if (localeObj) {
        config.locale = localeObj;
      }

      // Initialize flatpickr
      const fp = window.flatpickr(input, config);

      // Handle disabled state
      if (disabled) {
        fp._input.disabled = true;
        if (fp.altInput) {
          fp.altInput.disabled = true;
        }
      }

      // Handle readonly state
      if (readonly) {
        if (fp.altInput) {
          fp.altInput.readOnly = true;
        }
      }
    });
  }

  /**
   * Initialize on DOM ready
   */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFlatpickr);
  } else {
    // DOM is already ready
    initFlatpickr();
  }

  /**
   * Re-initialize on dynamic content changes (e.g., modals, AJAX)
   */
  let initPending = false;
  const observer = new MutationObserver(function(mutations) {
    // Skip if already scheduled
    if (initPending) return;

    // Check if any mutation is outside flatpickr calendars
    const hasRelevantMutation = mutations.some(function(mutation) {
      // Skip mutations inside flatpickr calendar elements
      if (mutation.target.closest && mutation.target.closest('.flatpickr-calendar')) {
        return false;
      }
      // Only care about added nodes that might contain date inputs
      return Array.from(mutation.addedNodes).some(function(node) {
        if (node.nodeType !== Node.ELEMENT_NODE) return false;
        // Skip flatpickr elements
        if (node.classList && node.classList.contains('flatpickr-calendar')) return false;
        // Check if node contains date inputs
        return node.querySelector && node.querySelector('input[type="date"]:not(.flatpickr-input)');
      });
    });

    if (hasRelevantMutation) {
      initPending = true;
      setTimeout(function() {
        initPending = false;
        initFlatpickr();
      }, 100);
    }
  });

  // Observe the entire document for changes
  if (document.body) {
    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  // Make initialization function globally available
  window.initFlatpickr = initFlatpickr;

})();
