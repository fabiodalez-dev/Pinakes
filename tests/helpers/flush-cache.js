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

let warned = false;

/** Flush the app's page cache from inside the app process. */
async function flushCache() {
  try {
    const res = await fetch(`${BASE}/_e2e/flush-cache`, { redirect: 'manual' });
    if (!res.ok && !warned) {
      warned = true;
      // Non-fatal: without the flush the run behaves exactly like before this
      // helper existed (assertion may hit a stale cached page). The warning
      // turns that mystery into an actionable config hint.
      console.warn(
        `[flush-cache] GET /_e2e/flush-cache returned ${res.status} — ` +
        'set "SetEnv PINAKES_E2E_CACHE_FLUSH 1" in the E2E Apache vhost so ' +
        'direct-DB test writes can invalidate the page cache.'
      );
    }
  } catch (err) {
    if (!warned) {
      warned = true;
      console.warn(`[flush-cache] request failed: ${err && err.message}`);
    }
  }
}

module.exports = { flushCache };
