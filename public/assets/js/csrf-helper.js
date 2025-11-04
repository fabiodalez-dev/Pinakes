/**
 * CSRF Helper - Automatic CSRF token injection for fetch requests
 *
 * This helper function automatically adds the CSRF token to all fetch requests.
 * It reads the token from the <meta name="csrf-token"> tag in the page.
 *
 * Usage:
 *   csrfFetch('/api/endpoint', { method: 'POST', body: JSON.stringify(data) })
 *
 * This is a drop-in replacement for fetch() that automatically includes CSRF protection.
 */

/**
 * Fetch wrapper that automatically includes CSRF token
 * @param {string} url - The URL to fetch
 * @param {object} options - Fetch options (method, body, headers, etc.)
 * @returns {Promise<Response>} - The fetch response
 */
window.csrfFetch = function(url, options = {}) {
  // Get CSRF token from meta tag
  const csrfToken = document.querySelector('meta[name="csrf-token"]');
  const token = csrfToken ? csrfToken.getAttribute('content') : null;

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
};

// CSRF Helper loaded (silent - log removed for production)
// Uncomment for debugging:
// console.log('CSRF Helper loaded. Use csrfFetch() for automatic CSRF protection.');
