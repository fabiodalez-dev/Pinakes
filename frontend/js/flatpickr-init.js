/**
 * Flatpickr Auto-initialization for Pinakes
 * Automatically converts all input[type="date"] to flatpickr instances
 */

(function() {
  'use strict';

  /**
   * Initialize flatpickr on date inputs
   */
  function initFlatpickr() {
    if (!window.flatpickr) {
      console.warn('Flatpickr not loaded. Skipping date picker initialization.');
      return;
    }

    // Find all date inputs that haven't been initialized yet
    const dateInputs = document.querySelectorAll('input[type="date"]:not(.flatpickr-input)');

    dateInputs.forEach(input => {
      // Get existing value and attributes
      const existingValue = input.value;
      const minDate = input.getAttribute('min') || null;
      const maxDate = input.getAttribute('max') || null;
      const disabled = input.hasAttribute('disabled');
      const readonly = input.hasAttribute('readonly');

      // Flatpickr configuration
      const config = {
        dateFormat: 'Y-m-d',           // ISO format for backend
        altInput: true,                // Show formatted date to user
        altFormat: 'd/m/Y',            // Italian format: day/month/year
        allowInput: !readonly,         // Allow manual input if not readonly
        clickOpens: !disabled,         // Allow opening if not disabled
        defaultDate: existingValue || null,
        minDate: minDate,
        maxDate: maxDate,
        disableMobile: false,          // Use flatpickr on mobile too
        // locale already set to Italian in vendor.js
      };

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
