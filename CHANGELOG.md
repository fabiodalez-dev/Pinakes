# Changelog

Full version-by-version history for Pinakes. The README shows only the latest release; everything older lives here.

## [0.7.61-rc.3]

Release candidate for 0.7.61 — see the [0.7.61] notes below. Cut for
verification on the reference install before the stable release.

## [0.7.61]

Physical-copy management from the book summary, with the whole holding and
circulation lifecycle made atomic and derived from the copies.

### Features

- The book page (`/admin/books/{id}`) now shows a **Copie Fisiche** section for
  every book — even one with no copies — with an "Aggiungi copia" modal, per-copy
  status editing, and per-copy delete. Copy status covers the physical states
  (available, maintenance, under restoration, in transfer, lost, damaged); an
  out-of-circulation copy lowers the derived total on its own.
- A book can be created with zero physical copies and have them added later from
  the summary. On the edit form the copy count is read-only and delegates to the
  per-copy management, so availability is always derived from the copies.

### Fixes

- Book creation is now atomic: the book row and its initial copies are committed
  together, so a copy-creation failure can no longer leave an orphan book with no
  holdings. The bulk `increase-copies` endpoint is likewise transactional,
  allocates collision-free inventory codes, promotes the wait-list, and validates
  its input.
- Adding an available copy repairs blocked reservations and promotes the next
  wait-list entry into a physical-copy-linked loan, mirroring the loan engine.
- Copies under restoration or in transfer can now be deleted from the UI; the
  loan/reservation system keeps exclusive ownership of the `prestato`/`prenotato`
  states.
- Legacy reservations with a missing or past start date are no longer promoted
  into back-dated loans.
- Admin copy routes stay fixed English literals (not routed through the i18n
  system), inventory-code allocation escapes LIKE metacharacters, and the copy
  note is sanitised and length-capped like the inventory number.
- All new copy-management strings are translated across the five locales.

### Upgrade notes

- **Legacy availability is migrated automatically.** Books that predate copy
  tracking (only the old counters, no per-copy rows) are backfilled into real
  copies *before* availability is recalculated, so availability carries over
  from the old counter model to the new copy-derived one without being zeroed.
  Active loans and reservations are preserved, and copies already marked
  lost/damaged/maintenance/under-restoration/in-transfer are left untouched.
- A book whose only record of unavailability was the legacy counter (marked
  unavailable with no active loan) becomes available again after the upgrade:
  the new model derives availability from physical copies, and a physically
  missing book with no loan leaves no machine-readable trace to preserve.
  Re-mark those copies from the book page after upgrading.
- If you upgraded through an intermediate version that had already zeroed a
  legacy book's counters before this release, the backfill cannot reconstruct
  the lost count. Restore `libri.copie_totali` from the automatic pre-upgrade
  backup under `storage/backups/`, then re-run the availability recalculation
  from Maintenance, or re-add the copies from the book page.

## [0.7.60]

Maintenance release: UPC barcode support, a read-only availability field, and a
PHP 8.5 scraping fix, bundled with CI hardening.

### Features

- The barcode field accepts a 12-digit **UPC-A** (board games and other
  non-book items), canonicalised to its 13-digit GTIN so it validates, stores,
  searches and de-duplicates exactly like an EAN-13. CSV and TSV import both go
  through the same path. The field is relabelled EAN → EAN/UPC across all five
  locales.

### Fixes

- ISBN scraping no longer fails with "Risposta non valida dal servizio ISBN." on
  PHP 8.5: the deprecated `curl_close()` calls that leaked a notice into the JSON
  response body were removed.
- The book editor's availability field is now read-only, matching the fact that
  it is a value derived from the physical copies; editing it was a silent no-op.
- The floating scroll-to-top button no longer overlaps the Save button at the
  bottom of the book form.

### Internal

- The GitHub Actions security audit no longer breaks on upstream tag drift, and
  the NCIP CheckOut regression test provisions its own available copy so it is
  deterministic under sharded runs.

## [0.7.59]

Consolidation release: the complete integration of PRs #335, #337, #339 and
#340, hardened together with the fixes found during their combined review and a
security pass. Validated through three release candidates.

### Fixes

- Loan and reservation state, availability and date handling now use the same
  guarded production paths across the web UI, mobile API and background jobs.
- Orphan plugin hooks are disabled reversibly and the regression test creates
  its own foreign-key-safe fixture, including on a completely fresh database.
- Framework-generated error responses receive the same nonce-based Content
  Security Policy as normal pages.
- Review findings covering migration selection, fixture cleanup, SweetAlert
  confirmation, localization parity, generated assets and accessibility have
  regression coverage.

### Release verification

- The exact distributable is installed and exercised through the full E2E and
  four-shard browser regression suites.
- Chromium, Firefox and WebKit run accessibility/runtime checks; OWASP ZAP
  blocks medium/high passive-scan findings.
- PHP 8.2–8.5, MySQL 8.0/8.4 and MariaDB 10.11/11.4 are verified, together with
  fresh installs in all five bundled locales and the complete upgrade chain.
- Static analysis, dependency/secret/vulnerability scans, reproducible archive
  checks, SPDX SBOM and provenance are mandatory release gates.

---

## [0.7.53]

A performance release: the whole application answers faster, with a dramatically lighter database footprint per request.

### Improvements

- **Time-to-first-byte cut across every page** — the per-request bootstrap no longer opens a second MySQL connection for settings, re-scans bundled plugins, re-reads plugin hooks one query per plugin, or re-fetches the active theme and language list on every hit. All of it is served from a short-lived cache (APCu when available, files otherwise) that every admin write invalidates immediately. A warm home-page request dropped from ~90 database queries to under 20.
- **The home page renders instantly** — the hero counters and the "latest books" grid are now rendered server-side from a cached dataset instead of arriving via JavaScript after two extra API round-trips, so the page is complete on first paint with no spinners. The hero background image is preloaded with high priority (it is the page's LCP element).
- **Catalogue search and filters are much faster** — the total-count scan, the availability aggregate and the six sidebar-facet aggregations are cached per filter set for two minutes; book, loan and CMS changes clear them instantly.
- **Two plugins no longer rewrite their hook rows on every request** — Digital Library and GoodLib re-registered their hooks (nine `INSERT`s) on every single page view; they now self-heal only when the rows are actually missing.

### Database Changes

- New composite indexes `idx_libri_deleted_created` and `idx_libri_genere_deleted_created` on `libri` — the "newest first" sorts used by the home page and the catalogue no longer filesort the whole table (migration `migrate_0.7.53.sql`, applied automatically by the updater).

### Upgrade Notes

- Back up your database before updating (the in-app updater does this automatically).
- If your host offers APCu, enabling it gives the new caches their fastest backend; without it they transparently use `storage/cache` files.

---

## What's New in v0.7.52

A mobile alignment fix for the book page.

### Fixes
- **The book info and share cards line up with the rest of the sheet on mobile** — their contents were inset by the cards' own horizontal padding, sitting further right than the title, the description and the section headings. On phones that padding is dropped so the metadata rows and the social buttons sit flush at the same edge as everything else.

### Database Changes
- None — a view and the compiled layout CSS only.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.51

A public-catalogue and interface-polish release.

### Improvements
- **Catalogue search no longer flickers stale results** ([#317](https://github.com/fabiodalez-dev/Pinakes/pull/317)) — changing a filter or typing quickly cancels the previous in-flight request, so an earlier, slower response can't overwrite the newest results. Clicking a page number also scrolls back to the top of the book list, on mobile and desktop.

### Fixes
- **Chip and button contents are vertically centred** ([#316](https://github.com/fabiodalez-dev/Pinakes/issues/316)) — icon-and-label pills across the admin and public interface, and the keyword chips on a book page, no longer leave extra space below their contents.
- **The book info-card header lines up with its rows on mobile** — the "Informazioni Libro" label is inset with padding instead of a margin, so it stays aligned with the metadata rows regardless of stylesheet order.
- **The "Curatore" book role reads "Editor" in English** (completes [#315](https://github.com/fabiodalez-dev/Pinakes/pull/315)) — the volume-editor relator is now labelled Editor, distinct from Publisher; the unrelated staff-role example stays "curator".

### Database Changes
- None — views, translations and compiled assets only.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.50

A circulation-correctness and update-operability release.

### Improvements
- **Interlibrary (NCIP) rejections are classified precisely** — CheckOut, Renew and RequestItem map permanent refusals (unknown item, duplicate request, ineligible patron, loan/renewal limit reached, no free copy, non-renewable loan) to stable NCIP ProblemTypes instead of a generic retryable failure, so a partner system stops retrying a request that can never succeed. Only genuine transient errors stay `temporary-processing-failure`, and a concurrently cancelled loan no longer reports a false check-in.
- **Availability and notifications share the canonical circulation services** — reserved / on-loan / available state and the "has a free copy" checks are computed the same way everywhere (`CapacityService`), keeping the public catalogue, notifications and the NCIP server consistent.

### Fixes
- **Book descriptions no longer gain blank lines between paragraphs** ([#313](https://github.com/fabiodalez-dev/Pinakes/issues/313)) — editor HTML is rendered as authored instead of having a stray `<br>` inserted between every `<p>`; plain-text descriptions still keep their line breaks.
- **An interrupted in-app update can no longer lock the site out of recovery** — the maintenance page keeps the login and update routes reachable so an administrator can always sign in and finish or abort the update; a concurrent update rejected at the lock never tears down the active one; and the maintenance flag is always lifted when the request ends. The 503 maintenance page was restyled to the Pinakes identity and now shows the operator's configured name and logo.

### Database Changes
- None — application code, views and compiled assets only.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.49

A mobile-layout maintenance release for the redesigned public catalogue.

### Fixes
- **Catalogue pagination is horizontal, centred and wrapping on phones** instead of becoming a vertical stack after the Bootstrap removal ([#310](https://github.com/fabiodalez-dev/Pinakes/pull/310)).
- **Book-detail actions and information sections fit narrow screens** — source links stack at full width, content padding is corrected, and headers align with metadata rows.
- **Related books show one complete cover plus a preview of the next**, restoring the intended mobile scroll affordance.
- **Mobile browser chrome follows the active theme**, and breadcrumb separators now work consistently throughout the public frontend.

### Database Changes
- None — views and compiled layout CSS only.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.48

A search-relevance release shared by the web catalogue and companion app.

### Improvements
- **Weighted catalogue ranking** ([#309](https://github.com/fabiodalez-dev/Pinakes/pull/309)) — exact identifiers and title matches rank above author, subtitle, publisher, keywords and description matches across header autocomplete, AJAX search and the Mobile API.
- **Stable pagination and wildcard handling** — public search caps oversized queries/results, meaningless wildcard-only searches fall back to title order, and mobile browsing retains full cursor pagination.
- **Consistent mobile results** — the Mobile API uses the same normalized, entity-decoded relevance model as the web catalogue.

### Database Changes
- None — ranking is generated from existing catalogue fields.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.47

A broad interface redesign plus circulation and cataloguing corrections.

### Improvements
- **Editorial-minimal frontend and account redesign** — public, authentication, patron and admin surfaces share a cleaner typography, spacing and action system, with self-hosted fonts and rebuilt assets.
- **Configurable frontend layout variants** — theme settings can select the shipped public-layout treatment without custom CSS; the obsolete Bootstrap CSS dependency and override layer were removed.
- **Plugin actions match native book actions** — Digital Library, GoodLib and other hook-injected buttons/links follow the same layout and responsive rules as core actions.
- **Grouped authoring fields** ([#308](https://github.com/fabiodalez-dev/Pinakes/pull/308)) — contributor inputs on the book form are organized consistently; CSV import also accepts the added column alias from [#305](https://github.com/fabiodalez-dev/Pinakes/pull/305).

### Fixes
- **Overdue loans still prove reading eligibility** — `in_ritardo` is included wherever reviews and “has this book” checks evaluate current/previous borrowing, including Mobile API and NCIP mirrors.
- **Frontend and plugin responsive polish** — catalog facets, archives, Book Club, digital-content actions and email layouts were reconciled with the new design system.

### Database Changes
- None — this release changes application code, views, locale catalogues and compiled assets.

### Upgrade Notes
- Rebuild custom frontend integrations that depended directly on Bootstrap classes; Bootstrap is no longer shipped by the frontend bundle.
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.46

A circulation-policy and catalogue-count correctness release.

### Features
- **Optional automatic loan approval** ([#301](https://github.com/fabiodalez-dev/Pinakes/issues/301)) — administrators can opt in under loan settings. Eligible immediate patron requests are promoted through the same lock-safe approval pipeline used by staff, receive a physical copy and enter `da_ritirare`; failures remain pending for manual review.
- **Mobile API advertises and reports approval behavior** — health/OpenAPI responses expose whether approval is required, and request results return the actual pending or ready-for-pickup state.

### Fixes
- **Author, publisher and genre counts exclude soft-deleted books** ([#306](https://github.com/fabiodalez-dev/Pinakes/issues/306)), including list filters and bulk exports.

### Database Changes
- `migrate_0.7.46.sql` — adds the idempotent `loans.auto_approve_requests` setting, defaulting to off and preserving an existing operator choice.

### Upgrade Notes
- Automatic approval remains disabled after upgrade until an administrator explicitly enables it.
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.45

A catalogue-consistency and scraper-maintenance release.

### Improvements
- **Catalogue availability facets now reflect real circulation state** — reserved, on-loan, available and non-circulating records are shown and counted separately; records without physical copies remain visible under the complete catalogue without being mislabelled as loans ([#303](https://github.com/fabiodalez-dev/Pinakes/issues/303)).
- **Catalogue card rows stay aligned** — a card with a subtitle no longer pushes its author, publisher and "Details" out of line with neighbouring cards; the subtitle space is reserved per row only where a row actually needs it, and the layout re-aligns after AJAX filtering, on resize, and once web fonts settle ([#298](https://github.com/fabiodalez-dev/Pinakes/issues/298)).
- **Language completion statistics are derived from the Italian source catalogue** — every locale (list and edit views alike) now uses the same live denominator, so translation percentages cannot drift from the shipped keys.
- **Open Library plugin 1.0.4** — replaces the retired third-party Goodreads-cover service with a direct, timeout-bounded lookup of the public Goodreads ISBN page. Because Goodreads sits behind Cloudflare (which serves an anti-bot page to PHP's HTTP client but not to the system `curl`), the cover lookup shells out to the `curl` binary when the host allows process execution, and degrades gracefully to no cover where it doesn't. Only HTTPS cover URLs from exact Goodreads/Amazon CDN domains or their subdomains are accepted; its manifest requires Pinakes 0.7.16+ and PHP 8.2+.
- **Email templates: `{{pickup_deadline}}` now resolves**, and every placeholder token carries a localised description tooltip in the template editor ([#304](https://github.com/fabiodalez-dev/Pinakes/issues/304)).

### Database Changes
- None — catalogue availability is derived from existing circulation state and language statistics are computed from the shipped locale files.

### Upgrade Notes
- The bundled Open Library plugin is replaced atomically by the updater and its database metadata advances from 1.0.1 to 1.0.2.
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.44

A bug-fix release for email-template links.

### Fixes
- **Email template links no longer double-prefixed with `/admin`** ([#299](https://github.com/fabiodalez-dev/Pinakes/issues/299)) — the WYSIWYG editor's `convert_urls` resolved every link against the admin page, so a placeholder like `{{login_url}}` was saved as `https://host/admin/{{login_url}}` and the sent email link came out double-prefixed. `convert_urls` is now off on every editor, so URLs and placeholders are stored exactly as written. Templates already saved corrupted are repaired automatically the next time the settings page is opened (and emails render correctly even before that).

### Database Changes
- None — the repair of already-corrupted templates runs in PHP on settings open (portable across MySQL 5.7+/MariaDB); no schema or migration.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.43

A bug-fix release that completes the #292 hero-upload fix and applies the admin custom CSS on admin pages.

### Fixes
- **Hero background upload now saves and displays** ([#292](https://github.com/fabiodalez-dev/Pinakes/issues/292)) — completing the CSP fix from 0.7.42. Two further bugs were behind "the image doesn't show after upload": the file was written under `/uploads/assets` but stored with an `/assets` path that 404s, so a successfully-uploaded image never rendered; and a photo larger than the server's `upload_max_filesize` was dropped silently as a fake "saved". The stored path now matches the served path, oversized uploads show a clear error naming the `php.ini` keys to raise, and every image uploader (hero, event, book cover, author photo, site logo) now shows a preview of the picked file before saving.
- **Admin custom CSS now applies on admin pages** ([#291](https://github.com/fabiodalez-dev/Pinakes/issues/291)) — the configured custom header CSS was emitted only on the public frontend, so a rule meant to (for example) hide unused fields on the book form had no effect. It now applies to the admin interface too.

### Database Changes
- None — this release changes views, a controller and client-side assets only; no schema or translation-count changes.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.42

A security-hardening and bug-fix release. It closes a set of access-control,
SSRF, open-redirect and data-exposure issues found in a full security audit,
and fixes three OPAC/CMS regressions.

### Security
- **Access control on the internal APIs** — `GET /api/editori` and `GET /api/libri` were reachable without a session and returned every column, exposing publisher PII (email, phone, tax code, referent contacts) and internal book fields (purchase price, private notes, inventory numbers). They now require the admin session middleware, matching how they are already documented.
- **Borrower privacy on the book page** — a non-admin patron could open `/admin/books/{id}` and read every borrower's name, email and loan history. Borrower identity is now gated to admin/staff only (the reservations block already was).
- **Privilege boundary on updates** — a `staff` user could upload and install an update package, or trigger a full update/downgrade, over the application files (a route to code execution). Those actions now require an explicit `admin` role, like the backup/restore actions.
- **Public search API** no longer leaks the current borrower's name/email; it returns availability and due dates only.
- **Session-role enforcement** — admin routes now re-validate the user against the database each request, so demoting or suspending a user takes effect immediately instead of at session expiry.
- **Secrets** — the settings page no longer shows API keys or the reCAPTCHA secret to the `staff` role; API keys are accepted only via the `X-API-Key` / `Authorization: Bearer` header (never a `?api_key=` query string, which leaks into access logs).
- **Hardening** — fixed an open redirect after login and on the language switch (`/\` backslash bypass), a path-traversal on language-file upload, SSRF in the image proxy (redirect + DNS-rebinding) and the installer DB test, plaintext SMTP password storage in the installer, CSV/formula injection in two exports, reset-link Host-header poisoning, a login timing side channel, and rate-limit bypass via forged forwarding headers.

### Fixes
- **Book subtitle now shows on the public book page** ([#293](https://github.com/fabiodalez-dev/Pinakes/issues/293)) — the `sottotitolo` was never rendered in the OPAC; it now appears under the title.
- **Hero background image upload under a strict CSP** ([#292](https://github.com/fabiodalez-dev/Pinakes/issues/292)) — the uploader fetched a `blob:` URL that a strict `connect-src` policy blocks, so the image never reached the form. It now uses the file object directly and works regardless of the site's CSP.
- **Related-books count on high-resolution screens** — the related strip packed 6+ cards on high-res laptops; it now caps at 4 and adapts down to 3/2/1 as the viewport narrows, with the mobile swipe carousel unchanged.

### Database Changes
- `migrate_0.7.42.sql` — bumps the `da_DK` translation key count (6607 → 6611) for installs already on 0.7.41, after this release added 4 UI strings. Idempotent.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.41

A maintenance release bundling seven merged pull requests: a new interface
language, bulk loan extension, mobile catalogue improvements, a responsive
book-detail fix, and a batch of reviewed bug fixes.

### Features
- **Danish language (da_DK)** ([#279](https://github.com/fabiodalez-dev/Pinakes/issues/279)) — Danish is now a fully selectable interface language, contributed by [@HansUwe52](https://github.com/HansUwe52). Ships the complete UI catalogue (`locale/da_DK.json`, full key parity with the Italian source), route slugs (`locale/routes_da_DK.json`), and the fresh-install seed in Danish (`installer/database/data_da_DK.sql`: 181 genres, 22 email templates, CMS pages, settings). `da_DK` is wired into the installer language step, the email/route fallback chains, and the bundled-locale updater path. Emails fall back to English when a Danish template is missing.
- **Bulk loan extension** ([#281](https://github.com/fabiodalez-dev/Pinakes/issues/281)) — the admin loans list gains a select-all-extendable checkbox column and a bulk "extend by N days" action. Each extension runs from `max(today, current due date)`, is capacity-checked against other loans and reservations, and the whole batch rolls back atomically if any single loan would conflict. Capped at 500 loans per batch to bound the transaction.
- **Mobile catalogue language filter** ([#282](https://github.com/fabiodalez-dev/Pinakes/issues/282)) — the mobile API exposes `GET /catalog/languages` (real catalogue language values + counts) and accepts a free-text `language` filter on `/catalog/search`, matched case- and whitespace-insensitively. The mobile author sort now matches the web catalogue (principal-author surname, authorless titles last in both directions).

### Fixes
- **Danish installer seed selection** ([#279](https://github.com/fabiodalez-dev/Pinakes/issues/279)) — the installer's locale maps only knew `it/en/de/fr`, so a Danish install silently seeded the Italian catalogue (genres + emails). All four maps in `Installer.php`, plus `PrivateModeMiddleware` and the `Updater` bundled-locale list, now include `da_DK`. Restored dropped content in the Danish `loan_pickup_ready` email and added the missing `fr_FR` entry to the `I18n` fallback map.
- **Book Club external books** ([#138](https://github.com/fabiodalez-dev/Pinakes/issues/138)) — "Add to catalogue" no longer fails when a club's external book shares an ISBN already in the catalogue; acquisition reconciles against the existing record instead of hitting the unique constraint.
- **Home "Available" count and responsive related books** ([#288](https://github.com/fabiodalez-dev/Pinakes/pull/288)) — the available-copies count on the home page is now gated behind `?with_stats=1` so the live catalogue search no longer pays a discarded aggregate on every keystroke; on narrow phones the related-books strip becomes a horizontal scroll-snap carousel (previously only one card was visible), with a `<noscript>` fallback that shows all cards stacked.
- **Loan-list column sorting** ([#281](https://github.com/fabiodalez-dev/Pinakes/issues/281)) — clicking a column header on the loans table sorted the wrong column after the bulk-select checkbox was added; the server column map is realigned. Editing an unrelated loan field no longer re-arms already-sent due-date reminder emails.
- **PHP 8.5 compatibility** ([#289](https://github.com/fabiodalez-dev/Pinakes/pull/289)) — removed a `ReflectionProperty::setAccessible()` call that is a no-op since PHP 8.1 and emits a deprecation notice on PHP 8.5.

### Internal
- **Genre resolution keys renamed** ([#286](https://github.com/fabiodalez-dev/Pinakes/issues/286), [#290](https://github.com/fabiodalez-dev/Pinakes/pull/290)) — the computed per-request genre keys were renamed to a clearer `resolved_*` convention (behaviour-preserving; no schema or API change).

### Database Changes
- `migrate_0.7.41.sql` — registers the `da_DK` row in the `languages` table on existing installs (idempotent upsert), so Danish appears in the language UI after upgrading.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.40

### Features
- **Mobile API — registration-fields discovery** ([#255](https://github.com/fabiodalez-dev/Pinakes/issues/255)) — a public `GET /api/v1/auth/registration-fields` endpoint that advertises the signup form contract: `registration_enabled`, the always-required core fields (nome/email/password), the config-driven built-in toggles (cognome/telefono/indirizzo), and the admin-defined custom fields. Lets the companion app render the registration form dynamically per instance. Mobile API plugin `1.3.0 → 1.4.0`.

### Fixes
- **Related-books section responsive layout** ([#278](https://github.com/fabiodalez-dev/Pinakes/pull/278)) — the "Potrebbero interessarti" covers no longer balloon on large screens: the card is capped to a book-sane width, columns scale 1 → 2 → 3 with the viewport, and the row is grouped/centred on ultra-wide displays.

### Tests
- New reusable regression coverage: `mobile-api.spec.js` behavioural tests for the registration-fields endpoint (#277), `related-books-responsive-278.spec.js` (#278), and `accordion-book-sections-274.spec.js` for the collapsible REICAT/SBN + MAG book-form sections (#274).

### Database Changes
None — no migration in this release.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.39

### Features
- **Collapsible cataloguing sections on the book form** ([#274](https://github.com/fabiodalez-dev/Pinakes/issues/274)) — the plugin-injected **REICAT/SBN** (z39-server) and **MAG digital-copies** (oai-pmh-server) panels are now accordions: collapsed by default on a new book, and they auto-open when the record already carries that data (or on import). The REICAT/SBN section can also be removed entirely by deactivating the z39-server plugin.
- **Custom CSS now reaches the auth pages** ([#262](https://github.com/fabiodalez-dev/Pinakes/issues/262)) — `Settings → Advanced → Custom CSS` applies to login, register, forgot- and reset-password (e.g. to hide registration fields), emitted through the same render-time sanitizer the public frontend already uses.
- **Role-specific contributor help text** ([#237](https://github.com/fabiodalez-dev/Pinakes/issues/237)) — the Illustrator / Translator / Curator / Colorist pickers each show their own hint instead of a generic "author" one, translated across all four locales.

### Security
- **guzzlehttp/guzzle → 7.15.1** — fixes five advisories published against `<7.15.1` (incl. `CVE-2026-59883`): cookie disclosure/injection via IP-address domains, host-only cookie scope not preserved, URI fragments leaking in redirect `Referer` headers, unbounded response cookies (DoS), and `Proxy-Authorization` headers leaking to origin servers.

### Database Changes
None — no migration in this release.

### Upgrade Notes
- Back up your database before updating (the in-app updater does this automatically).

---

## What's New in v0.7.38

### Features
- **Race-proof author / contributor / publisher autocomplete** on the book form ([#272](https://github.com/fabiodalez-dev/Pinakes/pull/272)) — server-side-search pickers no longer hide results for partial queries, and the search input stacks below the selected chips.

### Security & Fixes
- Custom-CSS `</style>` breakout hardening and a cross-cutting XSS review ([#266](https://github.com/fabiodalez-dev/Pinakes/pull/266), [#267](https://github.com/fabiodalez-dev/Pinakes/pull/267)).
- Book-club advisory lock and rating-migration cleanup ([#271](https://github.com/fabiodalez-dev/Pinakes/pull/271)); locale action strings ([#268](https://github.com/fabiodalez-dev/Pinakes/pull/268), [#270](https://github.com/fabiodalez-dev/Pinakes/pull/270)).

---

## What's New in v0.7.37

This release bundles three feature tracks — contributor roles (#237), loan due-date & email (#238), and configurable registration (#255) — plus security and packaging hardening.

- **Illustrators, translators, curators and colorists are now real authors ([#237](https://github.com/fabiodalez-dev/Pinakes/issues/237)).**
  For comics and illustrated books, contributors other than the main author used
  to be plain free-text fields — no autocomplete, and they never showed up as
  authors. Now each role (illustrator, translator, curator, and the new
  **colorist**) is a proper author entity with the same search-as-you-type picker
  as the author field, so it can be reused, found by pseudonym, and appears on the
  contributor's author page and books. Existing free-text values are converted to
  entities automatically on the first run after upgrading — nothing to redo.
- **Find and show authors by pseudonym (#237).** The author picker now matches on
  the pen name as well as the real name (so typing "Leo" finds them), and books
  display the pseudonym as *"Pseudonym (Real name)"* instead of only the real name.
- **Loan due-date visibility ([#238](https://github.com/fabiodalez-dev/Pinakes/discussions/238)).**
  The Loans list gains a **Due date** column and highlights due-today/overdue
  loans in red — the same rule the Physical Copies table already used — and the
  dashboard's Active Loans list matches it. "Today" is computed in the
  application timezone, not the browser's.
- **Due-today / overdue notifications (#238).** A loan due *today* — previously
  skipped by an off-by-one in the warning query — now triggers the in-app notice
  (*"scade oggi"*), the warning email, and a mobile push.
- **Send test email + robust SMTP (#238).** Settings → Email gains a **Send test
  email** button that reports the *specific* SMTP error. Both the `smtp` and
  `phpmailer` drivers now go through PHPMailer, which verifies the
  handshake/auth/recipient — fixing a latent bug where the hand-rolled SMTP
  client reported success even when the message was rejected ("settings look fine
  but no mail arrives").
- **Configurable registration fields ([#255](https://github.com/fabiodalez-dev/Pinakes/issues/255)).**
  Small communities can no longer be forced to collect personal data they don't
  need: surname, phone and address each get an admin toggle (Settings →
  Registration) deciding whether they are required at self-registration.
  Defaults keep today's behaviour, so nothing changes until you opt out.
- **Custom registration fields (#255).** Administrators can define their own
  fields (text, textarea, email, URL, number, checkbox — required or optional):
  they appear on the registration form and in the user profile, and are shown
  on the admin user detail. Ideal for community handles such as a Telegram
  username. The bundled Mobile API exposes the requirements and definitions in
  `/api/v1/health`, accepts the values during app registration/profile editing,
  and includes them in `/api/v1/me`. New migration `migrate_0.7.37-rc.1.sql` adds the two supporting tables
  (`registrazione_campi`, `utenti_campi_valori`); it is idempotent and runs
  automatically on upgrade.
- **Hardened social links.** Social profile URLs (footer + Schema.org structured
  data) now pass through a strict `http(s)` sanitizer, so a malformed or
  `javascript:` value stored for any social renders no link at all.
- **Clearer Docker "not writable" message.** The in-app updater no longer
  presumes "the Docker image" when app files aren't writable — it states the real
  condition and covers both the official read-only image (`fabiodalez/pinakes`)
  and a community image whose code volume happens to be read-only.
## What's New in v0.7.35

- **Docker-aware in-app updater.** On the official Docker image the app files are
  baked in and owned by the image, so the "Updates" button couldn't overwrite
  them and failed with a raw list of unwritable paths. The updater now detects a
  container and explains the right path instead: update by moving the container
  to the new image (`docker compose pull && docker compose up -d`) — your data in
  the database and the `storage`/`uploads` volumes stays safe. The in-app button
  remains for classic/shared-hosting installs where the web-server user owns the
  files.

## What's New in v0.7.34

More user-reported fixes on labels and physical copies, plus a hardening pass
over the loan/reservation lifecycle.

### Physical-copy numbering ([#238](https://github.com/fabiodalez-dev/Pinakes/discussions/238))

- **Consistent, collision-free inventory codes.** Changing a book's copy count
  used to produce inconsistent suffixes (`LIB-3` + `LIB-3-C2`), trim the wrong
  end when reducing, and eventually crash with `Duplicate entry '…-C2'`. Codes
  are now a uniform `-C{N}` on every copy, reductions trim from the end (never a
  loaned/reserved copy), and additions gap-fill the lowest free index with a
  uniqueness check — so a duplicate can no longer occur. Existing codes are left
  untouched (printed labels stay valid).

### Label content scaling ([#238](https://github.com/fabiodalez-dev/Pinakes/discussions/238))

- **Content now scales to fill the label.** Text and barcode grow with the label
  size instead of staying a fixed size amid growing margins; each line auto-fits
  its width, the barcode fills the width, and the whole layout is centered — for
  both portrait and landscape.
- **Configurable inner padding (mm)** in Settings → Labels, applied on every
  side, to fine-tune the inset for any label stock.

### Loans & reservations — reliability

- Overbooking auditor now detects periods that involve overdue loans; the
  expired-reservations cron follows the canonical lock order (no deadlock window
  vs maintenance); capacity counts legacy reservations correctly; the
  "Returned" action shows only for `in_corso`/`in_ritardo` across every admin
  surface; and the maintenance recalc no longer double-runs.

### Book Club follow-up ([#138](https://github.com/fabiodalez-dev/Pinakes/discussions/138))

- The admin club page links straight to meeting management: a **Manage
  meetings** link and a per-meeting **Edit** link (for scheduled meetings) that
  deep-links to the meeting's card.

## What's New in v0.7.33

Built from real user feedback on labels, language management and the Book Club
([#238](https://github.com/fabiodalez-dev/Pinakes/discussions/238),
[#138](https://github.com/fabiodalez-dev/Pinakes/discussions/138)).

### Labels ([#238](https://github.com/fabiodalez-dev/Pinakes/discussions/238))

- **Print a single copy's label**: each row in a book's physical-copies table
  now has its own print action (`?copy_id=` on the existing label endpoint).
- **Custom label size** (width × height) alongside the presets — e.g. Dymo
  89×41mm — plus **per-field content checkboxes** (app name, title, subtitle,
  author+publisher, Dewey) so a label carries only what you need.
- The publisher is no longer clipped off landscape labels (the fixed 30-char
  pre-truncation is gone; truncation is width-based only).

### Language management ([#238](https://github.com/fabiodalez-dev/Pinakes/discussions/238))

- **Custom locales survive updates**: a user-installed translation (e.g.
  `nb_NO`) is no longer deleted by the updater's cleanup pass.
- **"Download JSON" exports every current key**, with an empty string for
  anything untranslated — so after an update you can spot and fill exactly the
  new keys. Stats now count against the current key set.

### Book Club follow-up ([#138](https://github.com/fabiodalez-dev/Pinakes/discussions/138))

- Richer "Next meeting" card (book, end time, agenda, members-only join link),
  clearer external-proposal heading, a "Proposed by" dropdown for managers,
  a "Remove" action for club books, and a PDF export of the reading list by
  workflow state.
- Admins **and the club's owner/moderators** are notified (in-app bell + email,
  de-duplicated) on join requests, new proposals and new meetings — via the
  standard notification pipeline, now guarded by the SMTP circuit-breaker so a
  down mail server can't stall user actions.

### Review hardening

- The meeting join link is members-only on every surface (it briefly leaked to
  non-members on the next-meeting card during 0.7.33's development — caught and
  fixed pre-release).
- A manually-typed translator/illustrator value always wins over scraped data
  (including the literal `"0"`), label content checkboxes persist even when the
  size field is invalid, and `notifyAdmins()` honours its never-throws contract.

No schema migration; the book-club plugin bumps to 1.4.1 (its boot self-heal
re-runs `ensureSchema()` when needed).

---

## What's New in v0.7.32

A hotfix for two bugs reported right after 0.7.31, and the systemic cause behind
the recurring "a plugin's new table is missing after an upgrade" failures.

### Plugin schema self-heals on upgrade (book-club 500 — [#138](https://github.com/fabiodalez-dev/Pinakes/discussions/138))

- Upgrading with a plugin **already active** could leave one of its new tables
  uncreated (`bookclub_external_books doesn't exist` → every Book Club page
  500'd). The cause was generic: the plugin version was marked done in the DB
  independently of its `ensureSchema()` actually running, and the "same version"
  path then skipped `ensureSchema()` forever.
- Fixed at the source: on boot the plugin manager now **re-runs `ensureSchema()`
  whenever a plugin's declared tables are missing** — a cheap read-only check
  that runs the schema DDL only when a table is actually absent, so healthy
  installs pay nothing. A broken install heals itself on the very next page
  load. One plugin's activation failure also no longer blocks the others.
- No manual step: just upgrade and open the affected page once.

### Loan list no longer leaks its script (Create Loan — [#238](https://github.com/fabiodalez-dev/Pinakes/discussions/238))

- After creating a loan, the loans list could show a block of raw JavaScript as
  page text (an inline `<script>` was closed early by a comment). Fixed.

### Release safety

- A mandatory schema gate (`scripts/verify-schema.sh`) now runs the migration
  tests plus a per-plugin check that every plugin's declared tables match what
  it creates, and a reproduction of the upgrade-while-active bug — so this class
  of regression is caught before a release, not by a user after one.

No schema migration in this release; the fix is runtime self-heal.

---

## What's New in v0.7.31

A feature + hardening release built from real user reports on the catalogue,
loan and book-club workflows, plus a faster catalogue search.

### Faster catalogue search (denormalized FULLTEXT)

- The catalogue, autocomplete and preview searches now match on a single
  denormalized `libri.search_index` FULLTEXT column that folds title, subtitle,
  author names, publisher names, ISBN/EAN, keywords and the plain description —
  replacing a long `OR`-of-`LIKE` chain plus a per-row author subquery. Subtitles
  now appear in the results ([#238](https://github.com/fabiodalez-dev/Pinakes/discussions/238)).

### Per-copy tracking + camera scanner in the loan workflow

- Books can carry **per-copy inventory labels**; loans and returns can be done
  **by copy code**, with an in-browser **barcode scanner** ([#243](https://github.com/fabiodalez-dev/Pinakes/pull/243)).
- The camera scanner was blocked by a `Permissions-Policy: camera=()` response
  header; it is now allowed same-origin (`camera=(self)`) so `getUserMedia`
  works ([#238](https://github.com/fabiodalez-dev/Pinakes/discussions/238)).
- On the **create-loan** form, entering or scanning a copy inventory code now
  **identifies the book automatically** (and pins the exact copy), so the
  operator no longer has to also search the title and can't pick the wrong book.
  Subtitles show in the book search, and the **loan details** page + **receipt
  PDF** now include the physical copy inventory code and the subtitle.

### Book Club: books not (yet) in the catalogue

- Members can propose and vote on books that aren't in the library, and a
  librarian can **acquire an external book into the catalogue** in one step
  (creating the `libri` record, authors, publisher and a physical copy)
  ([#138](https://github.com/fabiodalez-dev/Pinakes/issues/138)).

### Settings that actually take effect + email i18n

- A sweep of the settings pages: previously-orphaned toggles now have a real
  effect, per-locale email templates are honoured across it/en/de/fr, and the
  cookie banner + email-verification / admin-state flows were hardened against
  an auth-bypass edge case.

### Frontend theming + accessibility + Remember me

- Hardcoded colours across the public frontend are now driven by CSS theme
  variables, with AA-contrast and 44px mobile tap targets.
- The **"Remember me"** checkbox on login is honoured again (a field-name
  mismatch had silently ignored it).

### Migration `migrate_0.7.31.sql`

Idempotent, guarded via `information_schema` probes:

- adds `libri.search_index` (MEDIUMTEXT) + `ft_libri_search_index` FULLTEXT and
  backfills every non-deleted row;
- re-adds the LibraryThing `review`/`rating`/`comment`/`private_comment` columns
  (+ `idx_lt_rating` / `chk_lt_rating`) on installs that first updated after
  0.4.7 and so had skipped them;
- backfills `oai_deleted_records.created_at` on installs created before that
  column was added to `schema.sql`, so every install converges to the canonical
  schema.

---

## What's New in v0.7.28

Follows up 0.7.27's permissions helper after a real Docker upgrade report ([#205](https://github.com/fabiodalez-dev/Pinakes/issues/205)).

### Safer, Docker-aware permissions helper (`bin/setup-permissions.sh`)

- The helper is now **"grant, never reset"**: it only *adds* the ownership and read/write bits that are missing. It no longer resets modes, no longer switches the group unless you pass `--group`, and never strips `.env`'s existing readers. (0.7.27's version could do all three, which locked PHP out of a Docker bind-mount install and produced a 500 — this cannot.)
- New **`--from-container <name>`** for Docker installs: the script reads the real uid/gid PHP runs as *inside* the container (`docker exec … id`) and chowns the host files to that numeric id — the only thing that maps correctly across a bind-mount. So the helper now works for normal hosts, NAS, cPanel **and** Docker.

No schema migration. Operational change only; upgrading from 0.7.27 only replaces the bundled helper script.

---

## What's New in v0.7.27

A follow-up to the 0.7.26 updater hardening, from a real upgrade report ([#205](https://github.com/fabiodalez-dev/Pinakes/issues/205)).

### Clearer "not writable" reporting in the updater

- The update panel's **system-requirements** list used to label each writable-permission check with only the folder's base name, so an install whose root directory is called `html` (common on NAS/QNAP web roots) showed a cryptic **"Write: html — Not writable"** with no way to tell which directory was meant. Each check now shows **what it is plus the full path** — e.g. `Write — Installation root (/share/…/html)`, `Write — Storage directory (…/storage)` — so the operator knows exactly which directory to make writable by the web-server user. New i18n keys added to all four locales.

### One-shot permissions fixer (`bin/setup-permissions.sh`)

- A CLI script that fixes **all** filesystem permissions the app and the in-app updater need, in a single run — the reliable cure for "Update failed: Unable to create directory / Not writable" on shared hosting and NAS devices. It **auto-detects the web-server (PHP) user**, hands it ownership of the **whole install tree** (the updater must create/overwrite files, so owning the root — not just a few sub-folders — is what actually matters), then applies safe modes: no `chmod 777`, executables preserved, `.env` kept non-world-readable.
- **Dry-run by default** (shows exactly what it would do); `--apply` to run; `--user` / `--group` / `--root` overrides for any host. QNAP example: `sudo bin/setup-permissions.sh --apply --user httpdusr`.

No schema migration. Both changes are operational; upgrading from 0.7.26 changes nothing but the requirements labels and adds the helper script.

---

## What's New in v0.7.26

Book reviews reach the mobile app, the loan/reservation system gets a full review pass, plus an email-template migration.

### Book reviews in the Android app ([#209](https://github.com/fabiodalez-dev/Pinakes/pull/209))

- **The mobile API now serves book reviews** (stars + text): `GET/PUT/DELETE /api/v1/catalog/books/{id}/reviews` and `GET /api/v1/me/reviews`, gated by a per-instance `reviews` feature flag (off in catalogue mode).
- **Only borrowers can review.** Writing a review requires a past or present loan of that title (`403 not_eligible` otherwise); `PUT` is an idempotent upsert (one review per user + book).
- **Moderation stays authoritative.** A new or edited review returns to *pending*; aggregates and other users' reviews count approved reviews only, while the author always sees their own. The bundled `mobile-api` plugin moves to `1.1.0`. No schema migration — the `recensioni` table has shipped since the first release.

### Loan & reservation system review ([#207](https://github.com/fabiodalez-dev/Pinakes/pull/207), #205)

- A full review pass over the loan/reservation flow: 26 findings fixed (availability recalculation, reservation-queue edge cases, return-to-repair handling) plus updater hardening (a preflight writability dry-run that aborts before touching any file, and self-healing of owned permissions).

### UI

- **Sidebar** ([#208](https://github.com/fabiodalez-dev/Pinakes/pull/208)): the logo is stacked above the title for a cleaner header.

**Migration** (`migrate_0.7.26.sql`): seeds the email templates introduced by the loan review (including the new `reservation_cancelled` template) for **all shipped locales**, adds the `loans.max_loan_duration_days` setting, and fixes a single-brace placeholder in the `loan_overdue_admin` subject. Fully idempotent — `INSERT IGNORE` never overwrites admin-customised rows, existing settings are kept, and legacy-schema installs are upgraded in place via information_schema-gated guards.

---

## What's New in v0.7.25

Six fixes integrated from the open pull requests, plus a schema migration.

### Book form field types + copy repair ([#203](https://github.com/fabiodalez-dev/Pinakes/pull/203))

- **Acquisition type is now free text.** `tipo_acquisizione` was an `enum('acquisto','donazione')`, so anything typed outside those two (e.g. "Deposito legale", "Scambio") was silently reset to the default on save. The column is now `VARCHAR(50)` and stores what the form accepts; existing values are preserved.
- **Copies can go into repair on return.** When returning a loan you can mark the copy as *in maintenance* or *in restoration*: the loan closes as returned, the copy is held out of circulation until an operator restores it, and the borrower still receives the return notification.
- **New derived availability state.** A book whose copies are all out of circulation is now labelled **non disponibile** by the availability engine instead of showing a stale flag — localised everywhere (catalogue, book/author/publisher pages) with its own badge colour.

### Reservations, scraping & i18n

- **Waitlist promotion** ([#199](https://github.com/fabiodalez-dev/Pinakes/pull/199), #157): when reservations fully subscribe a title's copies, the head of the waitlist is promoted correctly as copies free up.
- **Scraped translator/illustrator no longer overwrite manual entries** ([#200](https://github.com/fabiodalez-dev/Pinakes/pull/200)): a submit carrying both a typed and a scraped value keeps the librarian's typed value.
- **Publication date is free text** ([#202](https://github.com/fabiodalez-dev/Pinakes/pull/202), #201): the field no longer claims a fixed "Italian format"; it accepts any text, in all four locales.

### Mobile & API

- **Loan date picker on mobile** ([#198](https://github.com/fabiodalez-dev/Pinakes/pull/198)): the loan calendar forces flatpickr's own picker on phones instead of the native control.
- **Self-hosted Swagger UI** ([#197](https://github.com/fabiodalez-dev/Pinakes/pull/197)): the API docs page (`/api/v1/docs`) serves its assets locally, with no CDN dependency.

**Migration** (`migrate_0.7.25-rc.1.sql`): widens `tipo_acquisizione` to `VARCHAR(50)` and adds `non_disponibile` to the `stato` enum — idempotent, existing data preserved. The **mobile API contract is unchanged** (availability is a computed four-value state from copy counts, never the raw `stato`), so **the Android app needs no update**.

## What's New in v0.7.24

### Backend UI homogenisation ([#196](https://github.com/fabiodalez-dev/Pinakes/pull/196))

A pass over the admin interface so every page looks and behaves consistently.

- **One accent colour everywhere.** Plugin pages used a mix of blue, purple and indigo; they are now all the same blue as the core admin chrome — settings pages (Mobile API, Discogs, GoodLib) and the feature pages of Archives, NCIP and Digital Library. Tailwind now also scans plugin views, so plugin classes are compiled reliably instead of depending on overlap with app views.
- **Readable settings on mobile.** Below 640px the settings pages (app and plugin) go flat — no card boxes, a single side gutter, no doubled padding — so content is wide and legible. Desktop is unchanged. The two odd-one-out section headers in the Advanced tab (Sitemap, Public API) were aligned to the same heading style as every other section.
- **All toggles are now identical.** The oversized OFF/ON switch on the API setting, the events visibility toggle and the bulk-enrich switch were unified to the standard grey→dark switch used across the app. A new end-to-end test turns every toggle on to guard against regressions.
- **Clearer sitemap field.** The settings page now makes it obvious which value to submit to Google Search Console (the public URL, with a Copy button); the filesystem path is labelled as server-only.
- **Mobile sidebar no longer scrolls the page behind it.** Opening the mobile menu now locks the page underneath the overlay (it previously kept scrolling on iOS).

This is a **code-only release — no new migration**. The Mobile API plugin moves to **1.0.2**; no other bundled plugin changed.

## What's New in v0.7.23

### Mobile API ⇄ Android app coherence ([#194](https://github.com/fabiodalez-dev/Pinakes/pull/194))

This release closes the gaps between the bundled **Mobile API** plugin and the companion Android app so the two actually agree on every contract.

- **The "mobile access disabled" dead end is gone.** The Mobile API access flag lives in the plugin's own settings, but there was no way to reach them from the admin UI — so an upgraded site that activated the plugin still answered the app with *"mobile access is disabled on this library."* **Admin → Plugins** now renders a generic **Settings** action for any plugin that ships a settings view, and posts back to the plugin that owns it. Open **Mobile API → Settings** and flip the access toggle.
- **Loan vs. reservation now matches the website.** A request with no date (or today's date) on an available copy is booked as an **immediate loan**, exactly like the web form; a future date becomes a **reservation**. The app and the website no longer disagree about what "borrow now" does.
- **Availability calendar normalised.** Per-day availability is exposed with a consistent `free` / `partial` / `full` state so the app's date-picker colours match the real stock.
- **OpenAPI contract realigned** to the controllers it documents — including the `/messages` request schema, which now reflects the field the endpoint actually reads (`messaggio`, with the documented fallbacks) instead of a stale `subject`/`body` shape.

On the app side (the separate [Pinakes Android](https://github.com/fabiodalez-dev/Pinakes-Android) repo, [#5](https://github.com/fabiodalez-dev/Pinakes-Android/pull/5)) this ships the in-app **registration** and **password-recovery** screens, feature-flag gating that follows the instance's catalogue-only mode, and a round of security hardening (duplicate-submit guard on registration, no account-enumeration on password recovery, password-confirmation forwarded end to end).

### Security — Slim CVE-2026-48157 ([#192](https://github.com/fabiodalez-dev/Pinakes/pull/192))

Bumped `slim/slim` 4.15.1 → 4.15.2 to clear **CVE-2026-48157**, a reflected XSS affecting Slim ≤ 4.15.1. Dependency-only bump within the existing major version — no application code changes.

This is a **code-only release — no new migration**. The Mobile API plugin moves to **1.0.1**; no other bundled plugin changed.

## What's New in v0.7.22

### Series / universe / cycle autocomplete on the book form ([#179](https://github.com/fabiodalez-dev/Pinakes/issues/179))

The cycle, universe, group and series fields on the book form are now **Choices.js autocompletes**, the same shape as the author and publisher pickers. Type a couple of letters and Pinakes suggests existing values instead of making you retype them — so a single different character no longer silently spawns a duplicate universe. Brand-new values can still be created (type and confirm). On an **upgrade**, the series you already use are suggested immediately (they are backfilled into the `collane` table); universes, groups and cycles are new dimensions and populate as you organise the hierarchy — there is nothing to import for them because the data never existed on older installs. No new migration: this is a code-only release.

### Fixes

- **Enter committed the wrong series value** ([#179](https://github.com/fabiodalez-dev/Pinakes/issues/179) review): when a suggestion was highlighted but you had typed something different, pressing Enter could commit the highlighted suggestion instead of your text — the same class of bug as the author field (#74). The series fields now use the same highlighted-vs-typed guard, covered by a dedicated regression test.
- **View escaping** aligned to `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` on the new series fields, per project convention.
- **Test hardening** for the series autocomplete (real Choices.js widget interaction + complete credential skip-guards).

## What's New in v0.7.21

### Mobile API + companion Android app ([#177](https://github.com/fabiodalez-dev/Pinakes/pull/177))

Pinakes now ships a versioned REST API for mobile clients. A bundled **Mobile API** plugin exposes `/api/v1` — instance discovery (`/health`), email/password login with bearer tokens, catalog browse &amp; search, real availability, loans, reservations, wishlist, push notifications, and digital-asset streaming for ebooks/audiobooks. It is server-agnostic and adapts to each instance's settings (language, catalogue-only mode, push availability). On a **fresh install the plugin is active out of the box**, so `/api/v1` works immediately; on an **upgrade** it ships disabled — enable it from **Admin → Plugins → Mobile API** — to avoid changing behaviour on existing sites. Either way, review mobile app access in its settings.

A free, native **[Pinakes Android app](https://github.com/fabiodalez-dev/Pinakes-Android)** (Kotlin / Jetpack Compose, Material 3) consumes this API: any library can point it at its own instance URL and hand it to members. Browse the catalog, check availability, borrow &amp; reserve, read ebooks / listen to audiobooks, and manage loans from the phone. The bearer token is stored in `EncryptedSharedPreferences`; cleartext HTTP is permitted only for loopback and the emulator. A prebuilt debug APK is published on the [app's Releases page](https://github.com/fabiodalez-dev/Pinakes-Android/releases).

### Upgrade upload limits — clear error instead of a misleading CSRF page

When an upload (e.g. the ~30&nbsp;MB admin-UI upgrade ZIP) exceeds the server's `post_max_size`, PHP discards the request body — CSRF token included — before the app runs. Instead of the confusing "security check failed" page, Pinakes now returns a clear **413** that points at the real cause and how to raise `post_max_size` / `upload_max_filesize` (note: `php_value` in `.htaccess` only applies under mod_php — on php-fpm/CGI hosts the host's PHP config must be raised).

### Security — dependency CVE fixes

Bumped `guzzlehttp/guzzle` to 7.12.1 and `guzzlehttp/psr7` to 2.12.1 to clear three medium-severity advisories disclosed 2026-06-18: CVE-2026-55767 (dot-only cookie domains matching all hosts) and CVE-2026-55568 (silent HTTPS-proxy downgrade to cleartext) in guzzle, and CVE-2026-55766 (CRLF injection in HTTP start-line serialization) in psr7. No application code changes — patch/minor bumps within the existing major versions.

## What's New in v0.7.20.2

Internal release — **test coverage only, no functional change to the application**.

- **Regression suite for the cover fix ([#173](https://github.com/fabiodalez-dev/Pinakes/issues/173))** — a 5-test end-to-end suite ([#176](https://github.com/fabiodalez-dev/Pinakes/pull/176)) that locks external-cover download &amp; save (OpenLibrary → Internet Archive redirect) and the SSRF boundary on both the save path and the `/api/cover/download` endpoint, so the #173 fix can't silently regress again. Tests are excluded from the release ZIP (`tests/ export-ignore`), so this package is byte-identical to 0.7.20.1 apart from the version marker — no need to update unless you track the latest version.

## What's New in v0.7.20.1

Maintenance release — two fixes, no schema change.

### Book covers from OpenLibrary now save ([#173](https://github.com/fabiodalez-dev/Pinakes/issues/173))

OpenLibrary serves its cover images via a redirect from `covers.openlibrary.org` to the Internet Archive, which the cover downloader's strict host check blocked — so the cover previewed on the edit form but was dropped on save. Cover fetching was reworked to accept any public source while staying **SSRF-safe**: redirects are followed one hop at a time, every hop's host is resolved to a verified **public** IP and the connection is **pinned** to it (no DNS-rebind), and IPv4-mapped / NAT64 addresses are rejected. OpenLibrary (and any CDN-hosted) covers now download and are stored as a local file.

### Hardened updater — fail-closed patches ([#174](https://github.com/fabiodalez-dev/Pinakes/pull/174))

Follow-ups to the in-app updater: a present-but-broken pre-update patch now aborts the update (fail-closed) instead of silently proceeding un-patched; a post-install patch error is surfaced as a warning instead of being hidden; and the releases API fetch no longer follows redirects with the auth token. Internal-only robustness — no action needed on upgrade.

---

## What's New in v0.7.20

### Author profiles — photo & sources ([#163](https://github.com/fabiodalez-dev/Pinakes/issues/163) / [#170](https://github.com/fabiodalez-dev/Pinakes/pull/170))

Authors can now carry a **photo** (uploaded with a live preview, or an external URL) and a list of **relevant source/website links**. The author and publisher admin detail pages were restyled to match the book page: a full-width identity card, a readable book catalog that gets its own full-width row, and the same buttons/chrome across all three entity pages.

### Loans & reservations — canonical state model ([#171](https://github.com/fabiodalez-dev/Pinakes/pull/171))

The loan/reservation engine was unified around a single occupancy model, fixing **10 state bugs**: returned copies reliably become lendable again, multi-copy availability is computed correctly, the reservation waitlist occupies its promised period without false positives/negatives, and admin/dashboard/calendar displays stay consistent. A new `restituito_in_ritardo` flag records late returns.

### Security — hardened in-app updater ([#172](https://github.com/fabiodalez-dev/Pinakes/pull/172))

Update packages are now verified against GitHub's server-side **sha256 digest** (delivered over TLS) with a constant-time compare — a present-but-unverifiable asset blocks the update instead of being silently skipped. The GitHub bearer token is scoped to the API host and never follows a redirect, and pre/post-update patches go through the same digest verification. A post-install patch that can't be verified is now a non-fatal warning (the core update is already applied) rather than a misleading failure.

### Security — session & login

Five pre-existing session pitfalls that could log an admin out unexpectedly were closed: **logout is now a CSRF-protected POST** (a stray link can no longer log you out), the "remember me" auto-login no longer bounces concurrent tabs to the login screen, a CSRF token mismatch on a still-valid session shows a clear *"reload and resubmit"* page instead of a misleading *"session expired"*, and `storage/sessions` is created automatically so sessions never fall back to a `/tmp` that shared hosts purge early.

### Migration & tooling

- **`migrate_0.7.20.sql`** — author photo/link columns, the `restituito_in_ritardo` flag, and a one-time loan-state cleanup. Idempotent; applies on upgrade from any older stable (and from the `0.7.20-rc.1` prerelease).
- CodeQL analysis is now scoped to application source (vendored bundles and test fixtures excluded), so the security dashboard reflects only our code.
- Verified end-to-end: fresh-install and the real admin-UI upgrade path (`reinstall-test.sh` Test A + B) both pass, full lifecycle suite green, PHPStan level 5 clean.

---

## What's New in v0.7.19

### Faceted catalog filters ([#169](https://github.com/fabiodalez-dev/Pinakes/pull/169))

The public catalog (`/catalogo`) filters were reworked to be clear and noise-free. Choosing a value **collapses** that facet to a removable pill and re-scopes the others; options that would return zero results disappear, single-value facets are suppressed, and the year range clamps to the data's real bounds. A new **Author** facet joins genres, publishers, media type and availability, long lists scroll internally with subtle borders, and everything is **theme-aware** (driven by the app's CSS variables).

### Complete backup & restore ([#162](https://github.com/fabiodalez-dev/Pinakes/issues/162) / [#167](https://github.com/fabiodalez-dev/Pinakes/pull/167))

A full backup system from **Admin → Updates/Maintenance**: it archives the database **and** the uploaded files, with a hash-verified, streaming restore (fail-loud staging/promotion, 4xx restore errors, admin-only download/delete). Restores replace the database content with the archive's — only restore trusted archives.

### Scanner & cover fixes ([#164](https://github.com/fabiodalez-dev/Pinakes/issues/164) / [#165](https://github.com/fabiodalez-dev/Pinakes/issues/165))

The ISBN scanner now commits on **Enter** even when a partial prefix matches an existing entry, and book-cover replacement is a single step (no dead links left when an external cover can't be downloaded).

### Install & operations robustness

- **cPanel install fix:** when the document root is the project root (a very common shared-hosting layout), the installer now self-heals the root `.htaccess` so routing and all assets work even though cPanel's File Manager hides the dotfile during extraction. No manual step required.
- **`/chi-siamo` (and localized CMS pages)** resolve reliably: the CMS page lookup tolerates a row seeded with a different-locale slug.
- **Login rate limit** relaxed from 5 to **15 attempts / 5 min** — far fewer accidental lockouts during setup, still bounded against brute force.

### UI polish

The book page "Cerca su" external-search block moved to its own row (no longer crammed in with the action buttons), genre breadcrumb separators are vertically aligned, and the related-book availability/eBook badges no longer overlap. Bundled plugin **goodlib** bumped to 1.0.1 (ships to new installs and is overwritten on upgrade).

### Testing

A 32-point regression suite covers the scanner/cover and backup/restore work ([#168](https://github.com/fabiodalez-dev/Pinakes/pull/168)), plus 26 new tests for the install/CMS/rate-limit/UI fixes. The full lifecycle suite (135) is green and the real admin-UI upgrade path (`reinstall-test.sh` Test B) passes; PHPStan level 5 clean. No new migration in this release (schema baseline stays at `migrate_0.7.17.sql`).

---

## What's New in v0.7.18

### Configurable loan & reservation system (#157)

The loan lifecycle is now fully admin-configurable from **Settings → Loans**: default loan duration, maximum active loans per user (`0` = unlimited), maximum renewals, and the pickup window for approved loans. A unified, multi-copy **occupancy model** governs availability — a copy is occupied by an active loan (`in_corso` / `in_ritardo` / `da_ritirare` / `prenotato`) or by a pending request that already holds a copy, while a *bare* pending request (no copy assigned yet) does not block other users until an admin approves it and assigns a copy. Returning a copy automatically reassigns it to the next waiting reservation in the queue, deferred email notifications are flushed only **after** the transaction commits (each send isolated so one failure can't drop the others), and maintenance automations handle pickup expiry and scheduled-reservation conversion. Database changes ship in **`migrate_0.7.17.sql`** (loan settings + reworked overlap triggers, applied through a DELIMITER-aware updater step).

### Private mode — restrict the site to registered users (#158)

A new **Settings → Advanced → Private mode** switch makes the entire public site (home, catalog, book pages) require login. It is **off by default**. When enabled, unauthenticated API calls get a JSON `401`, private uploads are withheld, but public assets (book covers, branding) stay reachable, and the API-key-gated `/api/public/*` routes keep responding through their own `ApiKeyMiddleware` instead of being pre-empted by a session `401`.

### English admin routes (#145)

All `/admin/*` paths are now English literals (`/admin/books`, `/admin/loans`, `/admin/reservations`, `/admin/users`, `/admin/publishers`, `/admin/genres`, …) instead of Italian. Old Italian admin URLs keep working through legacy redirects (`301` for `GET`, `308` for `POST` so form submissions preserve their body and CSRF), so existing bookmarks and integrations don't break. Admin routes are deliberately **not** part of the i18n route system — they are fixed English paths.

### Testing

Validated end to end on the merged `main`: the full lifecycle suite (135 passing), the dedicated loan / reservation / overlap suites (35 + 26 + 21), and a new private-mode suite (10), all green, with PHPStan level 5 clean.

---

## What's New in v0.7.16

### Multi-publisher, hardened end to end (#143)

Books can have more than one publisher (the `libri_editori` junction, introduced in 0.7.15). This release closes every gap in that model: publisher **filters, counts, exports, the public publisher archive, the catalog facet, search, the admin API and bulk operations** now all match a book whether the publisher is its primary one (`libri.editore_id`) or a secondary one in the junction. Merging two publishers re-points the junction onto the survivor **before** the cascade, so no association is lost; the publisher-delete guard counts secondary links too; and CSV / LibraryThing import and bulk-enrichment now keep the junction in sync so interop exporters (OAI-PMH, BIBFRAME) never lose a publisher.

Every new junction query is **guarded for pre-migration installs** — on a database that predates the junction table the queries gracefully fall back to the primary publisher instead of erroring.

### PHP 8.2 is now the floor

The installer and the in-app updater now require **PHP 8.2+**, matching `composer.json` (`^8.2`) and the generated `platform_check.php`. Previously an 8.1 host could pass preflight and then die at the Composer bootstrap.

### Other fixes

- **Multi-character book-case codes** (#153): the legacy single-letter UNIQUE constraint on `scaffali.lettera` is dropped, so codes like `L1`, `L2` no longer collide.
- **Edit form**: the "Import from ISBN" field is pre-filled with the book's ISBN/EAN when editing.
- A reconciliation migration heals any `libri_editori` drift left by imports written before the sync landed.

### Testing

The comprehensive E2E suite grew to **132 tests**, adding a 20-test **Archives** phase (ISAD(G) CRUD, hierarchy, SQL seeding, authority records, and the JSON/XML APIs — RiC-O JSON-LD, IIIF, OAI-PMH, SRU, MARCXML/Dublin Core/EAD3/METS) and a 9-test **multi-publisher / multi-author** phase. Validated with a fresh-install + real-upgrade regression.

---

## What's New in v0.7.14

### Installer fix: wizard no longer wedges at step 6 (Configurazione Email)

Hot-fix for an install-blocking bug discovered immediately after v0.7.13: every new install on a host **without a TLD** (`localhost`, an IP literal, or any intranet hostname such as `pinakes-vm`) got stuck at step 6 of the install wizard. The default `From Email` was derived from `$_SERVER['HTTP_HOST']` and accepted when the host passed `FILTER_VALIDATE_DOMAIN` — which `localhost` does. The same value was then re-validated at submit time with `FILTER_VALIDATE_EMAIL`, which is stricter (requires a TLD), so `no-reply@localhost` was silently rejected. The form posted, the controller flagged the validation failure, the same page re-rendered, and the install never progressed.

`installer/steps/step6.php` now validates the host the same way `FILTER_VALIDATE_EMAIL` will: it only adopts the live `HTTP_HOST` if `no-reply@{host}` itself passes `FILTER_VALIDATE_EMAIL`. Otherwise the default falls back to `example.com` (RFC 2606 reserved, always a syntactically valid placeholder). The user can still override the field manually.

Verified end-to-end with a fresh install from the v0.7.14 ZIP on localhost: the wizard now advances past step 6 with the default value untouched and reaches step 7 (Installazione Completata) cleanly.

No schema migrations. No code changes outside the installer. Existing 0.7.13 installs are unaffected and don't need to re-install.

---

## What's New in v0.7.13

### Performance: HTTP compression + long-term cache for static assets

Apache (`public/.htaccess`) and the nginx example (`.nginx.conf.example`) now ship a `# === Pinakes performance block ===` that turns on gzip/brotli compression and applies `Cache-Control: public, max-age=31536000, immutable` to versioned CSS/JS/font assets. Every directive is gated by `<IfModule …>` (Apache) or feature-tested (nginx) so the file stays valid on hosts where the optional modules aren't loaded. Measured locally on the home page: `vendor.bundle.js` 3.5 MB → ~800 KB gzip, `main.css` 192 KB → 30 KB gzip (−84%), HTML home 471 KB → 91 KB (−81%). Asset URLs are already version-busted with `?v=X.Y.Z`, so the 1-year `immutable` lifetime is safe — every release rotates the URL automatically.

For nginx specifically, the `location ^~ /uploads/` block now adds a `Cache-Control: public, max-age=2592000` header (cover images, uploaded media) — without this explicit add_header, the prefix-priority of `^~ /uploads/` would short-circuit the regex location that previously set caching for static files, leaving uploads served with no cache headers at all. Apache wasn't affected because `mod_headers` applies `FilesMatch` globally.

Existing installations upgrading via the in-admin updater pick this up through `post-install-patch.php`: an idempotent search/replace injects the same performance block into the live `.htaccess` for every install on `0.4.0`–`0.7.12`. The patch is gated by `<IfModule>` and uses a stable 4-line anchor (`RewriteRule ^ index.php [QSA,L]` … `# Security Headers`) verified to exist unchanged from v0.4.9.9 through v0.7.12.

### Bulk "Scarica copertine" self-heals missing covers ([visible bug](https://github.com/fabiodalez-dev/Pinakes/pull/144))

`LibriController::fetchCover()` and `syncCovers()` used to trust `libri.copertina_url` alone when deciding whether a book already had a cover, returning `reason: already_has_cover` even when the file behind that URL had been deleted on disk (a common state after manual cleanups, partial backup restores, or failed downloads). The bulk "Scarica copertine" action would then report `Completato. Già presenti: 1` and the book stayed permanently uncovered.

Both methods now resolve the path with `realpath()` against `getCoversUploadPath()` and require the resolved file to live inside the covers directory (`str_starts_with($resolved, $baseDir . DIRECTORY_SEPARATOR)`) — a defence-in-depth tightening compared to the existing delete path. If the file is missing or unreachable, the controller logs a warning (`cover_url in DB but file missing/unreachable on disk, re-fetching`), re-runs the scrape, downloads a fresh cover, and updates the DB. Idempotent on subsequent calls.

### Minor UI fixes

- `.search-book-year` in the hero search dropdown is now explicitly left-aligned, matching the sibling `.search-book-author` line.
- `.description-content .prose` in the book-detail page gets `max-w-none` so the description fills its column instead of being capped at Tailwind Typography's default 65ch (already constrained by the page grid).

### Notes

- No schema migrations — drop-in upgrade from `v0.7.12`.
- No new bundled plugins, no breaking changes.
- The companion `post-install-patch.php` is attached to the GitHub release and applied automatically by the in-admin updater; nothing for end users to do manually.

---

## What's New in v0.7.12

### Archives: RiC-CM Phases 5 & 6 — admin UI + OAI-PMH `ric-o` ([#122](https://github.com/fabiodalez-dev/Pinakes/issues/122))

v0.7.12 closes the six-phase RiC-CM roadmap. Phases 1-4 (shipped progressively in 0.7.7 → 0.7.10) modelled the four RiC-CM entity types (Record/RecordSet, Agent, Activity, Place) and the polymorphic relations graph. Phases 5 and 6 expose them to curators and to harvesters.

**Phase 5 — native admin UI for activities, places and relations.**

- `GET/POST /admin/archives/activities` + `/new` + `/{id}` + `/{id}/edit` + `/{id}/delete` — CRUD over ISDF activities (Function/Activity/Transaction/Task/Mandate). Hierarchical parent/child with cycle detection on the application layer (the `parent_id` FK uses `ON DELETE SET NULL`, which is incompatible with a MySQL `CHECK` constraint, so the cycle guard is enforced in PHP before INSERT/UPDATE).
- `GET/POST /admin/archives/places` + `/new` + `/{id}` + `/{id}/edit` + `/{id}/delete` — CRUD over places (country/region/province/municipality/locality/building/room/geographic_feature/other) with optional latitude/longitude and GeoNames / Wikidata / Getty TGN identifiers for Linked Data linkage.
- `POST /admin/archives/relations/attach` + `POST /admin/archives/relations/{id}/detach` — manage the polymorphic relations graph from the unit/agent/activity/place detail pages.
- `GET /api/archives/entities?type=&q=` — typeahead backend for Choices.js-style autocomplete in the relation forms. Returns the four entity types validated against the ENUM definitions of `archive_relations.source_type` / `target_type`.

The chrome mirrors the existing books/archives admin views (Tailwind `p-6 max-w-4xl mx-auto`, `bg-white shadow rounded-lg p-6 space-y-5` form containers, `form-label` field labels, breadcrumb navigation, indigo-600 primary actions, red-50/red-700 destructive buttons). All 60+ user-facing strings are Italian-source `__()` wrappers with full translations added to `locale/en_US.json`, `locale/fr_FR.json`, `locale/de_DE.json`.

**Phase 6 — OAI-PMH `metadataPrefix=ric-o`.**

- The `oai-pmh-server` plugin now exposes `ric-o` (canonical RDF/XML serialisation of the same RiC-O graph emitted on `/archives/{id}/ric.json`) as a metadataPrefix for the `archives` set. `ListMetadataFormats` advertises it conditionally — only when the `archives` plugin is active AND the `archival_units` table exists.
- `GetRecord?identifier=oai:…:archival_unit:{id}&metadataPrefix=ric-o` serialises one archival unit as `<rdf:RDF>` with `ric:RecordSet` / `ric:Record` root, `rdfs:label` carrying `xml:lang`, `ric:DateRange` with `xsd:gYear` typed literals, embedded `ric:Relation` subjects for agent links, and `rdf:resource` references for parent/children. `ListRecords?set=archives&metadataPrefix=ric-o` streams the whole archival graph.
- Symmetric validation: `metadataPrefix=ric-o` on `set=books` or on a book identifier returns `cannotDisseminateFormat`; `metadataPrefix=oai_dc` keeps working on both sets unchanged.
- Re-uses `RicJsonLdBuilder::serializeToRdfXml()` (new in this release) which translates the JSON-LD compact document to canonical RDF/XML — `@id`→`rdf:about`/`rdf:resource`, `@type`→tag name (CURIE expanded against the document `@context`), language tags via `xml:lang`, typed literals via `rdf:datatype`, nested blank nodes for inline objects. 159/159 unit assertions passing on the round-trip.

The full RiC-CM journey: v0.7.7 read-only JSON-LD → v0.7.8 agents → v0.7.9 activities → v0.7.10 places + polymorphic relations → v0.7.12 admin UI + OAI-PMH RDF/XML. The application's `version.json` bumps from 0.7.10 to 0.7.12 once, at the end of the chain.

**Cleanup — dead schema column dropped (review F015).** The `archive_activities.place_id` column was introduced in 0.7.9 as a placeholder reserved for the Phase 4 `archive_places` FK, but Phase 4 (0.7.10) chose the polymorphic `archive_relations` graph instead and no application code ever read or wrote the column. Migration `migrate_0.7.12.sql` drops it with `ALTER TABLE archive_activities DROP COLUMN place_id;` so the schema reflects what the code actually uses.

---

## What's New in v0.7.10

### Archives: RiC-CM Phase 4 — Places + polymorphic Relations graph ([#122](https://github.com/fabiodalez-dev/Pinakes/issues/122))

Fourth phase of the RiC-CM roadmap. With Phases 1-3 we modelled three of the five RiC-CM entity types (Record/RecordSet, Agent, Activity). Phase 4 introduces the fourth — **Place** — and the **generic polymorphic Relations** backbone that lets any pair of entities carry a typed RiC-O predicate. The model is now complete on the entity side.

- **New table `archive_places`** — first-class Place entity (RiC-CM §3.5). `name` + `place_type` ENUM (country / region / province / municipality / locality / building / room / geographic_feature / other), self-referential `parent_id` for the place hierarchy (Catania → Sicilia → Italia), optional `latitude` / `longitude` for map display, optional `geonames_id` / `wikidata_id` / `tgn_id` for external Linked Data identifiers, optional `date_start` / `date_end` for historical places (e.g. "Regno delle Due Sicilie", 1816-1861). Full-text index on `name + description`.
- **New table `archive_relations`** — **polymorphic** N:M relations between any two RiC-CM entities. Both endpoints (`source_type`+`source_id` and `target_type`+`target_id`) reference one of four entity types: `archival_unit`, `authority_record`, `archive_activity`, `archive_place`. The `ric_predicate` column is VARCHAR so RiC-O's open vocabulary can grow without migrations. Common predicates: `ric:isOrWasLocatedAt`, `ric:isOrWasResidentAt`, `ric:isOrWasPerformedAt`, `ric:isOrWasIncludedIn`. Each row carries optional `qualifier`, `certainty` (certain/probable/uncertain), `date_start`/`date_end` for temporal validity, `source_ref` for the documentary citation, and `created_by` to track curatorial provenance.
- **Why polymorphic, not 16 specialised link tables** — RiC-O has dozens of inter-entity predicates. One link table per (source, target, predicate) triple would explode the schema and add a migration on every new predicate. Polymorphic source/target keeps the schema compact; the application-layer validator (`validateRelationEndpoints`) checks both endpoints exist and are not soft-deleted before INSERT.
- **Two new public endpoints**:
  - `GET /archives/places/{id}/ric.json` — RiC-O JSON-LD for one place. Emits `ric:Place`, `ric:CoordinateLocation` from lat/lng, `owl:sameAs` to GeoNames / Wikidata / Getty TGN, `ric:isOrWasIncludedIn` to the parent place, and `ric:isAssociatedWithDate` for historical date ranges.
  - `GET /archives/places/ric.json` — synthetic `ric:RecordSet` listing every top-level place (those with `parent_id IS NULL`), suitable for harvesting alongside the existing collection / agents / activities endpoints.
- **`RicJsonLdBuilder::buildRelationNode()`** — new method that renders any `archive_relations` row as a `ric:Relation` JSON-LD node with deterministic `@id` (`/archives/relations/{row.id}`), `ric:relationHasSource` and `ric:relationHasTarget` resolved via the central `iriForEntity()` switch. Returns `null` on malformed input — no exception — so callers can drop bad rows from the output without crashing the whole response.
- **`validateRelationEndpoints(sourceType, sourceId, targetType, targetId)`** — application-layer integrity check used by the admin form before inserting into `archive_relations`. Verifies both endpoints exist and are not soft-deleted; the polymorphic column shape makes a SQL FK impossible.
- **Migration `migrate_0.7.10.sql`** — idempotent. `archive_places.parent_id` self-cycle guards live in the application layer (MySQL forbids CHECK on a column that's part of an `ON DELETE SET NULL` FK action, same constraint encountered in Phase 3).

## What's New in v0.7.9

### Archives: RiC-CM Phase 3 — Activities as first-class entities ([#122](https://github.com/fabiodalez-dev/Pinakes/issues/122))

Third milestone of the RiC-CM roadmap. Introduces the ISDF-aligned **Activity** entity — any human or organisational activity that produced, used, or managed archival material. Phase 1 + Phase 2 already gave us records, record sets, and agents; Phase 3 closes the "what happened" side of the RiC-CM triangle.

- **New table `archive_activities`** — first-class Activity entity. Columns: `title`, `description`, `activity_type` (`function` / `activity` / `transaction` / `task` / `mandate` per ISDF terminology), self-referential `parent_id` (so a function can contain activities, an activity can contain transactions), `date_start` / `date_end` / `is_ongoing`, optional `agent_id` FK to `authority_records` (the agent that performed the activity), `place_id` reserved for Phase 4, `source_ref` for the legal/normative citation (e.g. "RD 9 ottobre 1861 n. 250"), full-text index on title + description.
- **New table `archive_unit_activities`** — M:N link between archival units and activities. The `ric_predicate` column captures the semantics of each link as a RiC-O predicate: `ric:resultsOrResultedFrom` (the unit was produced by the activity, default), `ric:isOrWasUsedBy` (the unit was used during the activity), `ric:isSubjectOf` (the activity is about this unit). Column is VARCHAR so new predicates can be added without a migration.
- **Two new public endpoints**:
  - `GET /archives/activities/{id}/ric.json` — RiC-O JSON-LD for one activity, with `ric:Activity` type, `ric:isOrWasPerformedBy` → agent, `ric:hasOrHadPartOf` → parent activity, `ric:isAssociatedWithDate` as `ric:DateRange` (`xsd:date`), and `ric:isOrWasRelatedTo` listing every unit the activity produced / used.
  - `GET /archives/activities/ric.json` — synthetic `ric:RecordSet` listing every top-level activity (those with `parent_id IS NULL`), suitable for ICA / Europeana harvesting alongside the existing collection.ric.json and agents endpoints.
- **`/archives/{id}/ric.json` now embeds activity links** — `RicJsonLdBuilder::buildUnit()` accepts a new `$activities` parameter so the unit-side serialisation lists every activity it's connected to. The relation IRI is shared between the unit side and the activity side (`/archives/unit-activity-relations/{unitId}-{activityId}-{predicate-slug}`) so a graph-merge consumer collapses both emissions into a single RDF node.
- **Migration `migrate_0.7.9.sql`** — idempotent. The CHECK constraint guarding `parent_id <> id` is intentionally absent because MySQL rejects CHECK on a column that's part of a FK referential action (`ON DELETE SET NULL`); the application-layer cycle guard in `activityWouldCreateCycle()` provides the equivalent protection.

## What's New in v0.7.8

### Archives: RiC-CM Phase 2 — Agents as first-class entities ([#122](https://github.com/fabiodalez-dev/Pinakes/issues/122))

Phase 2 of the 6-phase RiC-CM roadmap. Phase 1 (v0.7.7) was schema-free; this is the first migration in the chain that touches the DB.

- **`authority_records` extended** — four new columns:
  - `ric_type` (`ENUM('Person','CorporateBody','Family','Position','Group')`) — RiC-CM canonical type, broader than the legacy ISAAR `type` enum. The migration backfills it from existing `type` values; `Position` and `Group` are RiC-CM-only types ISAAR doesn't model.
  - `birth_date` / `death_date` — structured begin/end-of-existence dates (`xsd:date`). The RiC-O JSON-LD output now emits `ric:beginningDate` and `ric:endDate` as typed literals instead of the free-text `dates_of_existence` blob (which is preserved for back-compat and surfaces as `ric:descriptiveNote` on pre-Phase-2 rows).
  - `place_of_origin` — birthplace / founding place. Phase 4 will swap this literal for a FK to a dedicated `archive_places` table.
- **New table `archive_agent_identifiers`** — multi-scheme identifier ledger for archive authorities (VIAF, ISNI, Wikidata, GND, BNF, LCNAF, Getty ULAN, ARK, local). Each row carries scheme + value + optional precomputed URI + an `is_preferred` flag. `collectSameAsForAuthority` now merges these into `owl:sameAs` alongside the existing `viaf-authority` plugin's data; rows without a precomputed URI are synthesised from the scheme's canonical prefix (e.g. `viaf:29539` → `https://viaf.org/viaf/29539`).
- **New table `archive_agent_relations`** — Agent ↔ Agent edges typed with a RiC-O predicate (`ric:isParentOf`, `ric:isMemberOf`, `ric:isSuccessorOf`, `ric:isMarriedTo`, ...). Captures organisational hierarchies, corporate successions, and family ties that ISAAR's flat table cannot express. Each row becomes a `ric:Relation` node in the RiC-O JSON-LD output with a deterministic `@id` of the form `{base}/archives/agent-relations/{agentId}-{relatedId}-{predicate-slug}`. The schema rejects self-loops via a `CHECK` constraint (MySQL 8.0.16+).
- **Migration `migrate_0.7.8.sql`** — fully idempotent (INFORMATION_SCHEMA guards on every ALTER, `CREATE TABLE IF NOT EXISTS` on every CREATE). Re-running the migration is safe; the ric_type backfill UPDATE narrows on rows still at the default value so curator overrides survive.

## What's New in v0.7.7

### Regression hotfix for author autocomplete ([#74](https://github.com/fabiodalez-dev/Pinakes/issues/74))

- **Issue #74 regression fix** — typing a new author name in the book form and pressing Enter was once again selecting the first highlighted dropdown match (e.g. typing "Norbert Wex" picked the existing "Norbert Bauer") instead of creating the new author. The original fix in v0.4.9.4 monkey-patched `authorsChoice._onEnterKey` on the Choices.js instance; a later "cleaner" refactor (commit `e976cb1e`) replaced it with a capture-phase keydown listener, which Choices.js v11 silently bypasses via `stopImmediatePropagation()` on its own pre-registered capture-phase handler. Restored the monkey-patch with a defensive capture-phase fallback for any future Choices.js version that removes `_onEnterKey`. The override is per-instance, so publisher / genre / etc. Choices instances on the same page keep stock behaviour.

This is a patch-only release. No schema migrations, no plugin changes,
no config changes required. Drop-in upgrade from v0.7.6.

---

## What's New in v0.7.6

### French locale (fr_FR) and BNF scraping

- **Full French translation** — 4,145 translated keys (100% coverage). Select `fr_FR` during the installation wizard to run Pinakes in French; existing installations can switch the default locale from Settings → Localisation.
- **BNF SRU scraping** — the Z39 Server plugin now connects to the Bibliothèque nationale de France SRU endpoint and maps UNIMARC fields to Pinakes metadata (title, authors, publisher, ISBN, Dewey, subjects). Enable the Z39 Server plugin and add `sru.bnf.fr` as a source to start importing French bibliographic records.
- **Migration hardening** — `migrate_0.7.5.sql` now uses `ON DUPLICATE KEY UPDATE` instead of `INSERT IGNORE`, ensuring `fr_FR` is correctly re-activated on upgrades where the language row already existed with `is_active=0`. `Language::setDefault()` now forces `is_active=1` on the target language to prevent an inconsistent state where the default locale is invisible to the resolution chain.
- **Dev-schema guard** — `migrate_0.7.0.sql` detects installations where `author_authority_alternates` was created with the legacy column name `source_code` and automatically drops and recreates the table, preventing a fatal `ADD KEY` error during upgrade.

### Archives: IIIF Presentation 3.0 and AtoM alignment ([#123](https://github.com/fabiodalez-dev/Pinakes/issues/123), [#121](https://github.com/fabiodalez-dev/Pinakes/issues/121))

- **IIIF Presentation 3.0 manifests** — `GET /archives/{id}/manifest.json` returns a standards-compliant IIIF 3.0 manifest for each archival unit, exposing attached digitised documents as `Canvas` items with painting `Annotation`s. Works out of the box with Universal Viewer, Mirador, and other IIIF viewers.
- **AtoM ISAD(G) area labels** — the Archives admin UI and public display now use canonical ISAD(G) area names (`Identity area`, `Context area`, `Content and structure area`, `Conditions of access and use area`, `Allied materials area`, `Notes area`) so records are immediately recognisable to users coming from AtoM or other archival systems.
- **Multi-document uploads** — archival units now support multiple attached digitised files (PDF, JPEG, TIFF). Each file is stored with its original name, MIME type, and display order.

### Security fixes

- **Open-redirect via Host spoofing** — the OpenURL resolver built redirect URLs directly from `$request->getUri()->getHost()`, bypassing the `APP_TRUSTED_HOSTS` guard in `HtmlHelper::getBaseUrl()`. A crafted `Host:` header could redirect users to an attacker-controlled domain. Fixed to use `absoluteUrl()`.
- **CQL injection in SRU client** — search terms containing `"` or `\` were embedded in CQL quoted-term syntax without escaping, producing malformed queries sent to external SRU endpoints (BNF, SUDOC). Fixed with proper backslash escaping per the CQL specification.

### Compatibility fixes

- **Windows updater** ([#130](https://github.com/fabiodalez-dev/Pinakes/issues/130)) — path separators are now normalised to forward slashes before version-file lookups; backslash paths on Windows caused the updater to silently fail.
- **German routes** — added the missing `bibframe.book` route key to `routes_de_DE.json`, bringing German routing to parity with Italian, English, and French.

---

## What's New in v0.7.4

> Releases v0.6.x through v0.7.4 focused on library interoperability and archive search. All changes are listed below newest-first.

### Archive search bar — admin + public (v0.7.4)

The **Archives** plugin now ships a full search interface on both the admin and the public catalog.

**Admin (`/admin/archives?q=…&level=…`)**
- Free-text search hits `reference_code` (LIKE, for short codes like `IT-MI-001`), `constructed_title`/`formal_title` (LIKE), and `scope_content`/`archival_history` (MySQL FULLTEXT — two-pass query, deduplicated).
- Level filter (`fonds` / `series` / `file` / `item`) narrows by archival hierarchy without a separate page.
- Search mode renders a flat list instead of the tree indent, making all matched nodes equally scannable regardless of depth.
- Result counter (`N risultati per "query" · livello: series`) and input persistence (query + selected level remain filled after submission).
- "Azzera" reset link returns to the full hierarchical tree.

**Public (`/archivio?q=…&level=…&date_from=…&date_to=…`)**
- Same text + level filters plus a **date range** filter: `date_from` matches units whose `date_end ≥ year`; `date_to` matches units whose `date_start ≤ year`; both can be combined for an overlap query.
- In search mode results include all hierarchy levels (series, files, items), not just root fonds — so a reference-code search for `IT-MI-ARC-001/2` finds the exact fascicolo.
- Theme-aware CSS (`.archive-search-form`) reads `--primary-color` / `--archives-color-primary` so the form inherits whatever palette the admin chose in Settings → Appearance.
- × reset button clears all filters back to the root catalog.

**Bug fixes included**
- `reference_code` was previously not searchable at all — the old endpoint used only FULLTEXT, which skips tokens shorter than `ft_min_word_len` (3); the new two-pass strategy uses LIKE first.
- Level filter was silently ignored due to a PHP associative-array bug (`in_array` was checking integer values `[1,2,3,4]` instead of string keys `['fonds','series',…]`); corrected to `isset(self::LEVELS[$level])`.

**E2E coverage**: `tests/archives-search.spec.js` — 25 serial tests covering admin search (15) and public search (10), run with `/tmp/run-e2e.sh tests/archives-search.spec.js --config=tests/playwright.config.js --workers=1`.

### Interoperability stack — OAI-PMH, NCIP, BIBFRAME, ResourceSync, OpenURL, VIAF (v0.7.x)

Pinakes v0.7.x introduced a full library-interoperability layer, delivered as opt-in plugins that activate without touching the core schema.

**OAI-PMH 2.0 data provider** (`/archives/oai`)
- Exposes archival units as OAI-PMH records. Supports `Identify`, `ListMetadataFormats` (`oai_dc`, `marc21`), `ListSets` (one set per ISAD level), `ListRecords`, `GetRecord`, and resumption-token-based pagination.
- Dublin Core crosswalk from ISAD fields (title, description, date, identifier, type); MARCXML crosswalk from the same ABA field mapping used by the SRU endpoint.
- Selective harvesting by set (`level:fonds`, `level:series`, …) and by `from`/`until` date range (uses `updated_at`).

**NCIP 2.02 server**
- Implements the NISO Circulation Interchange Protocol: `LookupUser`, `LookupItem`, `CheckOutItem`, `CheckInItem`, `RenewItem`, `RequestItem`, `CancelRequestItem`.
- Partner library management UI at `/admin/plugins/ncip-server/partners` and `/admin/plugins/ncip-server/transactions` — register external systems with shared secret, set borrowing quotas.
- Maps Pinakes loan/reservation/user records onto NCIP data elements; returns structured NCIP XML responses.

**BIBFRAME 2.0 linked-data export**
- `GET /api/bibframe/book/{id}` — emits JSON-LD `bf:Work` + `bf:Instance` for books.
- `GET /api/bibframe/book/{id}/work` — `bf:Work` only.
- `GET /api/bibframe/book/{id}/instance` — `bf:Instance` only.
- Includes `bf:title`, `bf:contribution` (authors as `bf:Agent`), `bf:subject` (keywords), `bf:genreForm`, `bf:classification` (Dewey), `bf:language`, `bf:identifiedBy` (ISBN-13, EAN), and persistent `/id/work/{id}` + `/id/instance/{id}` URIs.

**ResourceSync**
- `GET /.well-known/resourcesync` — W3C ResourceSync source description.
- `GET /resync/capabilitylist.xml` — capability list linking to resource list and change list.
- `GET /resync/resourcelist.xml` — enumeration of all book and archive URLs with `md:hash` (MD5) and `md:lastmod`.
- `GET /resync/changelist.xml` — incremental change log (created/updated/deleted) since a given `from` date.

**OpenURL / COinS**
- OpenURL 1.0 resolver at `/openurl` — parses `ctx_ver=Z39.88-2004` + `rft.*` parameters, resolves to full-text link, catalog record, or ILL form.
- COinS `<span class="Z3988">` auto-embedded in public book detail pages for Zotero/Mendeley browser extensions.

**VIAF auto-linking**
- Scheduled task checks unlinked `authority_records` against the VIAF SRU endpoint; fills `viaf_id` for exact-name matches.
- Admin UI at `/admin/archives/authorities` shows VIAF reconciliation status per record and allows manual override.

**Documentation**: full technical guides (IT + EN) published at <https://fabiodalez-dev.github.io/Pinakes/> — one page per protocol.

### Membership consistency hardening + performance indexes (v0.5.9.6)

- `libri_collane` now enforces a CHECK constraint (`chk_lc_principale_consistency`) so a row can never have `tipo_appartenenza='principale'` together with `is_principale=0` (or vice versa). Pre-fix the column defaults silently allowed that contradictory state.
- The column default for `is_principale` was aligned to `1` to match the `'principale'` default of `tipo_appartenenza`, removing the foot-gun for any future plugin/CSV/scraper that omits the flag.
- Existing rows are realigned in-place by an idempotent migration; no data loss, no manual steps required.
- Six performance indexes backfilled for existing installations via `migrate_0.5.9.6.sql`: `idx_origine` and `idx_libro_utente` on `prestiti`; `idx_tipo_utente` on `utenti`; `idx_stato_libro`, `idx_queue_position` on `prenotazioni`. Fresh installs already had these via `schema.sql`; upgrades from any prior version now receive them automatically.

### Series groups and cycles (v0.5.9.5)

- Collane now support an optional umbrella group for related spin-offs, universes, or franchises, so separate series like `Fairy Tail`, `Fairy Tail: 100 Year Quest`, and `Fairy Tail: Happy` can remain distinct while sharing one parent group.
- Collane also support an optional cycle/season label plus numeric ordering, matching LibraryThing-style series such as `The Worlds of Aldebaran` with `Cycle 1`, `Cycle 2`, and later arcs.
- Book create/edit forms can set group, cycle/season, cycle order, series name, and number in series in one flow; the Collane admin page exposes the same metadata and shows related series in the same group.

### Archives plugin (ISAD(G) / ISAAR(CPF))

New bundled plugin for archival material alongside the bibliographic
catalog — hierarchical descriptions (Fondo → Series → File → Item) per
[ISAD(G)](https://www.ica.org/en/isadg-general-international-standard-archival-description-second-edition),
authority records per
[ISAAR(CPF)](https://www.ica.org/en/isaar-cpf-international-standard-archival-authority-record-corporate-bodies-persons-and-families-2nd).

**Archival descriptions**

- Three tables (`archival_units`, `authority_records`, `archival_unit_authority`)
  with self-referencing tree, FK guards, MARC-like field crosswalk inspired
  by the ABA format (Arbejderbevægelsens Bibliotek og Arkiv).
- Admin CRUD at `/admin/archives`, public frontend at `/archivio` (card grid
  + detail pages styled to match the book detail, SEO slug URLs, JSON-LD
  `ArchiveComponent` schema, breadcrumb chain).
- Per-unit cover image + document uploads (PDF/ePub/MP3/video) with finfo
  MIME detection and path-prefix unlink guard.

**Authority records (ISAAR(CPF))**

- Full CRUD for persons / corporate bodies / families with M:N linkage
  to both `archival_units` and `libri.autori` (unified authority file
  for the whole catalog, not per-module).
- JS type-ahead picker for attaching an existing authority to an
  archival unit (admin form) — no manual ID entry.
- Unified cross-entity search: a single query returns hits across
  `libri` + `archival_units` + `authority_records` with the correct
  provenance label in the results.

**Photographic items**

- Dedicated `specific_material` ENUM on `archival_units` covering the
  full ABA billedmarc / MARC21 008-pos-33 catalogue (`hb`/`hp`/`hm`/`hd`/`hk`/
  `bf`/`hf`/`lm`/`lf`/`vm`/`bm`/`le`/`zz`…) so a photograph, postcard,
  drawing, map, or audio-visual item gets classified correctly rather
  than flattened to "item".

**MARCXML I/O + SRU**

- MARCXML import + export, round-trip-stable (identity test: export →
  import → re-export yields byte-identical output), validated against
  the MARC21 Slim XSD on both sides.
- SRU 1.2 endpoint for archival records so external discovery systems
  (OPACs, union catalogues, Z39.50/SRU gateways) can query the archive
  alongside the book catalogue.

**Packaging**

- Plugin ships **inactive** (`metadata.optional: true`). Activate in
  Admin → Plugins to create the schema.
- i18n: IT/EN/DE (~40 new keys). Tracks
  [#103](https://github.com/fabiodalez-dev/Pinakes/issues/103).

### Discogs catalog number (Cat#) support

`DiscogsPlugin::validateBarcode` now accepts Catalog Numbers
(`CDP 7912682`, `SRX-6272`, `DGC-24425-2`) alongside EAN-13/UPC-A.
`ScrapeController::byIsbn` preserves the raw identifier through the
`scrape.isbn.validate` hook chain so plugins can match non-numeric
inputs. Valid ISBN-10 codes ending in `X` (`080442957X`) are explicitly
vetoed from Cat# classification to avoid music-metadata merges into book
records (MOD-11 checksum in `DiscogsPlugin::isIsbn10`, 7 regression
asserts in `tests/discogs-catno.unit.php`). Closes
[#101](https://github.com/fabiodalez-dev/Pinakes/issues/101).

### Remember-me preserves user locale

Users whose `utenti.locale` differs from the install default
(a `de_DE` user on an `it_IT` install) now see their locale restored
after auto-login. Fix is in installer seed + a backfill migration:
`installer/database/data_{it_IT,en_US}.sql` seed all three shipped
locales, `migrate_0.5.9.1.sql` adds the missing row on existing
installs. Closes [#108](https://github.com/fabiodalez-dev/Pinakes/issues/108).

### Migration

`migrate_0.5.9.sql` creates archival plugin tables + indexes.
`migrate_0.5.9.1.sql` seeds missing locales. Both idempotent via
`INFORMATION_SCHEMA` guards and `INSERT IGNORE`.

### Release-pipeline hardening (v0.5.9.2 → v0.5.9.4)

The 0.5.9.x series took four hotfix iterations because a forgotten
GitHub Actions workflow (`release.yml`) was racing
`scripts/create-release.sh` and overwriting the published ZIP with a
stale build that only contained 5 of 10 bundled plugins. The rogue
workflow is now disabled. The release builders derive the required plugin set
from `BundledPlugins::LIST` instead of maintaining another list, and
`scripts/create-release.sh` verifies the shipped ZIP via the GitHub API
(uploader identity, SHA, and every expected plugin, polled for 90s) so no
third-party overwrite can slip through unnoticed. Full post-mortem in
`updater.md`.

---

## Previous Releases

<details>
<summary><strong>v0.5.4</strong> - Discogs Plugin + Media Type + Plugin Manager Hardening</summary>

### Discogs music scraper plugin (#87)

- **New `tipo_media` ENUM** (`libro/disco/audiolibro/dvd/altro`) on `libri` with composite index `(deleted_at, tipo_media)`
- **Heuristic backfill** from `formato` using anchored LIKE patterns (avoids `%cd%` matching CD-ROM, `%lp%` matching "help")
- **Discogs + MusicBrainz + CoverArtArchive + Deezer** chain with 4 hooks (incl. `scrape.isbn.validate` for UPC-12/13)
- **Barcode → ISBN guard** in `ScrapeController::normalizeIsbnFields` — skips normalization when no format/tipo_media signal to avoid the EAN-in-`isbn13` regression
- **PluginManager** migrated from `error_log` → `SecureLogger` (31 call sites)

### Post-release hotfixes (rolled into v0.5.4)

- `autoRegisterBundledPlugins` INSERT had 14 columns / 13 values after CodeRabbit round 11 — fresh installs crashed with "Column count doesn't match value count" (fixed in `c9bd82c`)
- Same method's `bind_param('ssssssssissss')` had positions 8+9 swapped — `path='discogs'` was cast to int `0`, orphan-detection then deleted the rows (fixed in `fb1e881`)

</details>

<details>
<summary><strong>v0.5.3</strong> - Cross-Version Consistency Fixes (v0.4.9.9–v0.5.2)</summary>

- **`descrizione_plain` propagated** — Catalog FULLTEXT search and admin grid now use `COALESCE(NULLIF(descrizione_plain, ''), descrizione)` for LIKE conditions, completing the HTML-free search feature from v0.4.9.9
- **ISSN in Schema.org & API** — `issn` property now emitted in Book JSON-LD and returned by the public API (`/api/books`)
- **CollaneController atomicity** — `rename()` aborts on `prepare()` failure instead of committing partial state
- **LibraryThing import aligned** — `descrizione_plain` (with `html_entity_decode` + spacing), ISSN normalization, `AuthorNormalizer` on traduttore, soft-delete guards on all UPDATE queries, and `descrizione_plain` column conditional (safe on pre-0.4.9.9 databases)
- **Secondary Author Roles** — LT import now routes translators to `traduttore` field based on `Secondary Author Roles`

</details>

<details>
<summary><strong>v0.5.2</strong> - Name Normalization (#93)</summary>

### Name Normalization for Translators, Illustrators, Curators (#93)

- **`AuthorNormalizer`** applied to translator, illustrator, and curator on create, update, and scraping
- **Client-side normalization** — "Surname, Name" → "Name Surname" for translator/illustrator in book form
- **Shared `normalizeAuthorName()`** JS helper across authors, translator, illustrator

</details>

<details>
<summary><strong>v0.5.1</strong> - ISSN, Series Management, Multi-Volume Works (#75)</summary>

### ISSN, Series Management, Multi-Volume Works (#75)

**ISSN Field:**
- **New ISSN field** on book form with XXXX-XXXX validation (server-side + client-side)
- **Displayed on frontend** book detail and in public API responses
- **Schema.org** `issn` property emitted in JSON-LD

**Series (Collane) Management:**
- **Admin page** `/admin/collane` — List all series with book counts, create, rename, merge, delete
- **Series detail** page — Description editor, book list with volume numbers, autocomplete merge
- **Bulk assign** — Select multiple books and assign to a series from the book list
- **Search autocomplete** — Series name suggestions in merge and bulk assign dialogs
- **Empty series preserved** — Series with no books still appear in the admin list
- **Frontend "Same series"** section — Book detail shows other books in the same series

**Multi-Volume Works:**
- **`volumi` table** — Links parent works to individual volumes with volume numbers
- **Admin UI** — Add/remove volumes via search modal, volume table on book detail
- **Parent work badge** — "This book is volume X of Work Y" badge with link
- **Cycle prevention** — Full ancestor-chain walk prevents circular relationships
- **Create from collana** — One-click creation of parent work from a series page

**Import Improvements:**
- **LibraryThing Series parsing** — Splits `"Series Name ; Number"` into separate collana + numero_serie
- **Scraping series split** — Same parsing for ISBN scraping results
- **CSV/TSV import** — `collana` field already supported with multilingual aliases

**Bug Fixes & Improvements:**
- **ISSN validation** — Explicit error message instead of silent discard
- **Transactions** — Delete, rename, merge collane wrapped in DB transactions
- **Error handling** — execute() results checked in all AJAX endpoints
- **Soft-delete guards** — addVolume rejects deleted books, updateOptionals includes guard
- **Migration resilience** — `hasCollaneTable()` guard for partial migration scenarios
- **Non-numeric volume sorting** — Special volumes sort after numbered ones
- **Unified search fix** — Add-volume modal correctly parses flat array response

</details>

<details>
<summary><strong>v0.5.0</strong> - SEO & LLM Readiness, Schema.org Enrichment, Curator Field</summary>

### SEO & LLM Readiness, Schema.org Enrichment, Curator Field

- **Hreflang alternate tags** on all frontend pages
- **RSS 2.0 feed** at `/feed.xml`
- **Dynamic `/llms.txt`** endpoint (admin-toggleable)
- **Schema.org enrichment** — Book `sameAs`, all author roles, `bookEdition`, conditional `Offer`
- **New `curatore` field** — Database, form, admin detail, Schema.org `editor`
- **CSV column shift fix (#83)**, admin genre display fix (#90)

</details>

<details>
<summary><strong>v0.4.9.9</strong> - Social Sharing, Genre Navigation, Search Improvements</summary>

### Social Sharing, Genre Navigation, Inline PDF Viewer & Search

- **7 sharing providers** — Facebook, X, WhatsApp, Telegram, LinkedIn, Reddit, Pinterest + Email, Copy Link, Web Share API
- **Genre breadcrumb navigation** — Clickable genre hierarchy links that filter by category
- **Inline PDF viewer** — Browser-native `<iframe>` PDF viewer (Digital Library plugin v1.3.0)
- **Description-inclusive search** — New `descrizione_plain` column for HTML-free search
- **RSS icon in footer** — SVG feed icon next to "Powered by Pinakes"
- **Auto-hook registration** — Plugin hooks auto-registered on page load

</details>

<details>
<summary><strong>v0.4.9.8</strong> - Security, Database Integrity & Code Quality</summary>

### Security & Database Integrity

- **SMTP password encryption** — AES-256-CBC at rest using `APP_KEY`
- **isbn10/ean UNIQUE indexes** — Blank values normalized to NULL, duplicates resolved
- **prestiti FK fix** — Foreign key corrected to reference `utenti(id)`
- **Email notification test suite** — 16 Playwright E2E tests covering all email types

</details>

---
