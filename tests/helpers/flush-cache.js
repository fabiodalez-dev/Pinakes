// @ts-check
/**
 * E2E page-cache flush helper (follow-up to the PR #389 page cache).
 *
 * The app caches the public home page, the bounded (filter-less) catalog
 * listing and the book-detail DTO/reviews (QueryCache namespaces home_/
 * catalog_/book_detail_/book_reviews_), invalidated through ContentCache
 * generation bumps on every APP write path. Specs that mutate the DB
 * DIRECTLY (dbQuery/dbExec with INSERT/UPDATE/DELETE) bypass the app code,
 * so nothing bumps the generations and a subsequent frontend assertion can
 * read a stale cached page. Call `await flushCache()` AFTER such a direct
 * write and BEFORE the frontend navigation/assertion — it replicates the
 * invalidation a real edit performs.
 *
 * Server side: GET /_e2e/flush-cache (app/Routes/web.php) calls
 * QueryCache::flush() inside the app process — required because with the
 * APCu backend the cache lives in the web server's shared memory, which no
 * CLI/Node process can reach. The route only exists when the app
 * environment sets PINAKES_E2E_CACHE_FLUSH=1 (Apache `SetEnv`, next to the
 * other PINAKES_E2E_* flags); otherwise it 404s and is inert in production.
 *
 * Mutations performed through the app UI/API invalidate themselves — do NOT
 * add flush calls for those. Catalog pages with any search/genre/publisher/
 * author filter are never cached, so assertions behind such filters do not
 * need a flush either.
 */
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

// GitHub Actions (and most CI) set CI=true. In CI a flush that never happened
// silently corrupts every subsequent assertion after a direct-DB write, so it
// must FAIL the run loudly. Locally the endpoint may legitimately be absent
// (no PINAKES_E2E_CACHE_FLUSH / no APCu): keep the tolerant one-shot warning so
// a dev without the flag configured still gets a usable run plus a hint.
const IS_CI = !!process.env.CI;

let warned = false;

/** Flush the app's page cache from inside the app process. */
async function flushCache() {
  let res;
  try {
    res = await fetch(`${BASE}/_e2e/flush-cache`, { redirect: 'manual' });
    // Drain the body even on non-2xx so Undici can release the socket back to
    // the pool instead of leaving it half-read across the many call sites.
    await res.arrayBuffer().catch(() => {});
  } catch (err) {
    const msg = `[flush-cache] request to ${BASE}/_e2e/flush-cache failed: ${err && err.message}`;
    if (IS_CI) {
      throw new Error(msg);
    }
    if (!warned) {
      warned = true;
      console.warn(msg);
    }
    return;
  }

  if (!res.ok) {
    // The route returns 404 when PINAKES_E2E_CACHE_FLUSH is unset and 500 when
    // QueryCache::flush() reported a real delete failure — both mean the page
    // cache was NOT reliably cleared and a stale read is possible.
    const msg =
      `[flush-cache] GET /_e2e/flush-cache returned ${res.status} — ` +
      'ensure "SetEnv PINAKES_E2E_CACHE_FLUSH 1" is set in the E2E Apache ' +
      'vhost so direct-DB test writes can invalidate the page cache.';
    if (IS_CI) {
      throw new Error(msg);
    }
    if (!warned) {
      warned = true;
      console.warn(msg);
    }
  }
}

module.exports = { flushCache };
