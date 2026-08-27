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

  let lazyTokenPromise = null;
  let memoryToken = null;

  function metaEl() {
    return document.querySelector('meta[name="csrf-token"]');
  }

  function isSameOrigin(url) {
    try {
      return new URL(url, window.location.href).origin === window.location.origin;
    } catch (error) {
      return false;
    }
  }

  function doFetch(url, options, token, warnIfMissing = true) {
    // If no token found, log warning but proceed with request
    if (!token && warnIfMissing) {
      console.warn('CSRF token not found in page. Request may fail if CSRF protection is enabled.');
    }

    // Headers accepts objects, arrays and existing Headers instances without
    // silently discarding values from the latter.
    const headers = new Headers(options.headers || {});

    // Add CSRF token header if token exists
    if (token) {
      headers.set('X-CSRF-Token', token);
    }

    // Add Content-Type for JSON requests if not already set
    if (options.body && typeof options.body === 'string' && !headers.has('Content-Type')) {
      headers.set('Content-Type', 'application/json');
    }

    // Create merged options with headers
    const mergedOptions = {
      ...options,
      headers,
    };

    // Make the fetch request
    return fetch(url, mergedOptions);
  }

  function lazyToken() {
    if (lazyTokenPromise) {
      return lazyTokenPromise;
    }

    const base = (typeof window.BASE_PATH === 'string') ? window.BASE_PATH.replace(/\/$/, '') : '';
    lazyTokenPromise = fetch(base + '/csrf-token', {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    })
      .then(function(res) {
        if (!res.ok) {
          throw new Error('Unable to mint CSRF token (HTTP ' + res.status + ')');
        }
        return res.json();
      })
      .then(function(data) {
        if (!data || typeof data.token !== 'string' || data.token === '') {
          throw new Error('Invalid CSRF token response');
        }
        memoryToken = data.token;
        const meta = metaEl();
        if (meta) {
          meta.setAttribute('content', memoryToken);
        }
        return memoryToken;
      })
      .finally(function() {
        // Share only the in-flight operation. The token itself lives in the
        // meta element/memory cache; failures may be retried by a later action.
        lazyTokenPromise = null;
      });

    return lazyTokenPromise;
  }

  /**
   * Fetch wrapper that automatically includes CSRF token
   * @param {string} url - The URL to fetch
   * @param {object} options - Fetch options (method, body, headers, etc.)
   * @returns {Promise<Response>} - The fetch response
   */
  window.csrfFetch = function(url, options = {}) {
    const meta = metaEl();
    const token = (meta ? meta.getAttribute('content') : null) || memoryToken;

    const method = String(options.method || 'GET').toUpperCase();
    const isStateChanging = ['GET', 'HEAD', 'OPTIONS'].indexOf(method) === -1;
    const sameOrigin = isSameOrigin(url);

    // Never disclose the application's CSRF token to another origin. Callers
    // may still use csrfFetch as a normal fetch wrapper for such URLs.
    if (!sameOrigin) {
      return doFetch(url, options, null, false);
    }

    // Lazy mint: no token in the markup and the request needs one.
    if (!token && isStateChanging) {
      return lazyToken()
        .then(function(fresh) { return doFetch(url, options, fresh); })
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
