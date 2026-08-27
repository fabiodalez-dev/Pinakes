/**
 * CSRF Helper - Automatic CSRF token injection for fetch requests
 *
 * This helper function automatically adds the CSRF token to all fetch requests.
 * It reads the token from the <meta name="csrf-token"> tag in the page.
 *
 * Lazy CSRF (issue #387 step 6): sessionless anonymous pages are rendered
 * with an EMPTY csrf-token meta (no session, cacheable HTML). When a
 * state-changing request is made from such a page, the helper first fetches
 * a token from the same-origin GET /csrf-token endpoint — which lazily opens
 * the session — and only then performs the request. Pages rendered with an
 * active session keep the exact previous behavior (token read from the meta).
 *
 * Usage:
 *   csrfFetch('/api/endpoint', { method: 'POST', body: JSON.stringify(data) })
 *
 * This is a drop-in replacement for fetch() that automatically includes CSRF
 * protection and always returns a Promise<Response>.
 */

(function() {
  'use strict';

  function metaEl() {
    return document.querySelector('meta[name="csrf-token"]');
  }

  function doFetch(url, options, token) {
    // If no token found, log warning but proceed with request
    if (!token) {
      console.warn('CSRF token not found in page. Request may fail if CSRF protection is enabled.');
    }

    // Merge default headers with user-provided headers
    const headers = {
      ...options.headers,
    };

    // Add CSRF token header if token exists
    if (token) {
      headers['X-CSRF-Token'] = token;
    }

    // Add Content-Type for JSON requests if not already set
    if (options.body && typeof options.body === 'string' && !headers['Content-Type']) {
      headers['Content-Type'] = 'application/json';
    }

    // Create merged options with headers
    const mergedOptions = {
      ...options,
      headers,
    };

    // Make the fetch request
    return fetch(url, mergedOptions);
  }

  /**
   * Fetch wrapper that automatically includes CSRF token
   * @param {string} url - The URL to fetch
   * @param {object} options - Fetch options (method, body, headers, etc.)
   * @returns {Promise<Response>} - The fetch response
   */
  window.csrfFetch = function(url, options = {}) {
    const meta = metaEl();
    const token = meta ? meta.getAttribute('content') : null;

    const method = String(options.method || 'GET').toUpperCase();
    const isStateChanging = ['GET', 'HEAD', 'OPTIONS'].indexOf(method) === -1;

    // Lazy mint: no token in the markup and the request needs one.
    if (!token && isStateChanging) {
      const base = (typeof window.BASE_PATH === 'string') ? window.BASE_PATH : '';
      return fetch(base + '/csrf-token', { credentials: 'same-origin', cache: 'no-store' })
        .then(function(res) { return res.ok ? res.json() : null; })
        .then(function(data) {
          const fresh = (data && typeof data.token === 'string' && data.token !== '') ? data.token : null;
          if (fresh && meta) {
            // Cache for subsequent calls on this page.
            meta.setAttribute('content', fresh);
          }
          return doFetch(url, options, fresh);
        })
        .catch(function() {
          // Endpoint unreachable: proceed without a token — the server-side
          // CSRF validation stays authoritative and will reject if required.
          return doFetch(url, options, null);
        });
    }

    return doFetch(url, options, token);
  };
})();

// CSRF Helper loaded (silent - log removed for production)
// Uncomment for debugging:
// console.log('CSRF Helper loaded. Use csrfFetch() for automatic CSRF protection.');
