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
    // Check HTML lang attribute
    const htmlLang = document.documentElement.lang || '';
    if (htmlLang.startsWith('it')) return 'it';
    if (htmlLang.startsWith('en')) return 'en';

    // Check meta tag
    const metaLocale = document.querySelector('meta[name="locale"]');
    if (metaLocale) {
      const locale = metaLocale.getAttribute('content') || '';
      if (locale.startsWith('it')) return 'it';
      if (locale.startsWith('en')) return 'en';
    }

    // Check body data attribute (set by PHP)
    const bodyLocale = document.body?.dataset?.locale || '';
    if (bodyLocale.startsWith('it')) return 'it';
    if (bodyLocale.startsWith('en')) return 'en';

    // Default to Italian
    return 'it';
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
    const isItalian = appLocale === 'it';

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
        altFormat: isItalian ? 'd/m/Y' : 'm/d/Y',  // Locale-aware format
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
  const observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
      if (mutation.addedNodes.length) {
        // Wait a bit for the DOM to settle
        setTimeout(initFlatpickr, 100);
      }
    });
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
