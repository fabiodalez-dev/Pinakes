<p align="center">
  <img src="./public/assets/brand/social.jpg" alt="Pinakes - Library Management System" width="800">
</p>

# Pinakes

> **Open-Source Integrated Library System**
> License: GPL-3  |  Languages: Italian, English, French, German

Pinakes is a self-hosted, full-featured ILS for schools, municipalities, and private collections. It focuses on automation, extensibility, and a usable public catalog without requiring a web team.

📱 **Pinakes ships with a free companion [Android app](https://github.com/fabiodalez-dev/Pinakes-Android).** Enable the bundled Mobile API plugin, point the app at your instance URL, and your members can browse the catalog, check real availability, borrow &amp; reserve, read ebooks / listen to audiobooks, and manage their loans from the phone.

[![Version](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2Ffabiodalez-dev%2FPinakes%2Fmain%2Fversion.json&query=%24.version&label=version&style=for-the-badge&color=0ea5e9)](version.json)
[![Installer Ready](https://img.shields.io/badge/one--click_install-ready-22c55e?style=for-the-badge&logo=azurepipelines&logoColor=white)](installer)
[![License](https://img.shields.io/badge/License-GPL--3.0-orange?style=for-the-badge)](LICENSE)

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
![Slim](https://img.shields.io/badge/slim%20framework-2C3A3A?style=for-the-badge&logo=slim&logoColor=white)
[![MySQL](https://img.shields.io/badge/mysql-4479A1.svg?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![JavaScript](https://img.shields.io/badge/javascript-%23323330.svg?style=for-the-badge&logo=javascript&logoColor=%23F7DF1E)](https://developer.mozilla.org/docs/Web/JavaScript)
![TailwindCSS](https://img.shields.io/badge/tailwindcss-0ea5e9?style=for-the-badge&logo=tailwindcss&logoColor=white)

[![Android app](https://img.shields.io/badge/Android%20app-Pinakes-3DDC84?style=for-the-badge&logo=android&logoColor=white)](https://github.com/fabiodalez-dev/Pinakes-Android)
[![Documentation](https://img.shields.io/badge/Documentazione-Docsify-4285f4?style=for-the-badge&logo=readthedocs&logoColor=white)](https://fabiodalez-dev.github.io/Pinakes/)
[![Buy Me a Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-ffdd00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/fabiodalez)

---

## Requirements

- **PHP 8.2 or higher** — matches `composer.json` (`^8.2`); the installer and the in-app updater enforce this floor.
- **MySQL 5.7+** or **MariaDB 10.3+**
- **PHP extensions**: PDO, PDO MySQL, MySQLi, Mbstring, JSON, GD, Fileinfo, Zip
- **Web server**: Apache (`mod_rewrite` + `.htaccess`) or nginx
- No Composer or Node build tools are needed on the production host — frontend assets are precompiled and `vendor/` ships inside the release ZIP.

---

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
- Book create/edit forms can set group, cycle/season, cycle order, series name, and series number in one flow; the Collane admin page exposes the same metadata and shows related series in the same group.

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
workflow is now disabled, `bin/build-release.sh` enumerates plugins
from the filesystem instead of a hardcoded list, and
`scripts/create-release.sh` verifies the shipped ZIP via the GitHub
API (uploader identity + SHA + plugin count, polled for 90s) so no
third-party overwrite can slip through unnoticed. Full post-mortem
in `updater.md`.

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

## Quick Start

1. **Clone or download** this repository and upload all files to the root directory of your server.
2. **Visit your site's root URL** in the browser — the guided installer starts automatically.
3. **Provide database credentials** (database must be empty).
4. **Select language** (Italian, English, French, or German).
5. **Configure** organization name, logo, and email notifications.
6. **Create admin account** and start cataloging.

**Email configuration**: Supports both PHP `mail()` and SMTP. Required for notifications to work (loan confirmations, due-date reminders, registration approvals, reservation alerts). Can be configured during installation or later from the admin panel.

**Post-install** (optional but recommended):
- Remove/lock the `installer/` directory (button provided on final step)
- Configure SMTP, registration policy, and CMS blocks from **Admin → Settings**
- Schedule cron jobs for automated tasks:
  ```bash
  # Notifications every hour (8 AM - 8 PM)
  0 8-20 * * * /usr/bin/php /path/to/cron/automatic-notifications.php >> /var/log/biblioteca-cron.log 2>&1

  # Full maintenance at 6 AM (handles reservation/pickup expirations)
  0 6 * * * /usr/bin/php /path/to/cron/full-maintenance.php >> /var/log/biblioteca-maintenance.log 2>&1
  ```

All frontend assets are precompiled. Works on shared hosting. No Composer or build tools required on production. All configuration values can be changed later from the admin panel.

---

## Story Behind the Name

**Pinakes** comes from the ancient Greek word *πίνακες* ("tables" or "catalogues"). Callimachus of Cyrene compiled the *Pinakes* around 245 BC for the Library of Alexandria: 120 scrolls that indexed more than 120,000 works with authorship, subject and location. This project borrows that same mission—organising and sharing knowledge with modern tools.

**Full documentation**: [fabiodalez-dev.github.io/Pinakes](https://fabiodalez-dev.github.io/Pinakes/)

---

## What It Does

Pinakes provides cataloging, circulation, a self-service public frontend, and REST APIs out of the box. It ships with precompiled frontend assets and a guided installer so you can deploy quickly on standard LAMP hosting.

---

## Screenshots

<p align="center">
  <img src="./docs/dashboard.png" alt="Admin Dashboard" width="800"><br>
  <em>Admin Dashboard — Loans overview, calendar, and quick stats</em>
</p>

<p align="center">
  <img src="./docs/books.png" alt="Book Catalog Management" width="800"><br>
  <em>Book Management — ISBN auto-fill, multi-copy support, cover retrieval</em>
</p>

<p align="center">
  <img src="./docs/catalog.png" alt="Public Catalog" width="800"><br>
  <em>Public Catalog (OPAC) — Search, filters, and patron self-service</em>
</p>

---

## Core Features

### Automatic Metadata Import
- **ISBN/EAN scraping** from Google Books, Open Library, and pluggable sources
- **Automatic cover retrieval** when available
- **Every field editable manually** — automation never locks you in

### Cataloging
- **Multi-copy support** with independent barcodes and statuses for each physical copy
- **Unified records** for physical books, eBooks, and audiobooks
- **Dewey Decimal Classification** with 1,200+ preset categories (IT/EN), hierarchical browsing, manual entry for custom codes, and auto-population from SBN scraping
- **CSV bulk import** with field mapping, validation, automatic ISBN enrichment, and Dewey classification from scraping
- **LibraryThing TSV import** with flexible column mapping for 29 custom fields and automatic metadata enrichment
- **Import history** — Admin panel with per-import statistics, error reports downloadable as CSV, and log retention management
- **Automatic duplicate detection** by ID, ISBN13, EAN, or title+author (updates existing records without modifying physical copies)
- **Author and publisher management** with dedicated profiles and bibliography views
- **Genre/category system** with custom taxonomies and multi-category assignment
- **Series and collections** tracking with sequential numbering
- **Barcode generation** for physical inventory (Code 128, EAN-13, custom formats)
- **Cover image management** with automatic download, manual upload, and URL import
- **Rich metadata fields** including edition, publication date, language, format, dimensions, weight, page count
- **Keywords and tags** for enhanced searchability and subject indexing
- **Custom notes and annotations** for internal cataloging remarks

### Circulation
- **Full loan workflow**: request, approval, checkout, renewal, return
- **Automatic due-date calculation** with configurable loan periods
- **Configurable renewal rules** (manual or automatic approval)
- **FIFO reservation queues** with availability alerts when items become free
- **Detailed per-user and per-item history** for audit trails

### Catalogue Mode
- **Browse-only option** for libraries that don't need circulation features
- **Configurable during installation** or via Admin → Settings → Advanced
- **Hides all loan-related UI**: request buttons, reservation forms, wishlist
- **Admin sidebar simplified** without loan management menus
- **Perfect for**: digital archives, reference-only collections, museum libraries

### Pickup Confirmation System
- **New `ready_for_pickup` state** — Approved loans enter "Ready for Pickup" before becoming active
- **Two-step workflow** — Admin approves → Patron picks up → Admin confirms pickup
- **Configurable pickup deadline** — Days allowed for pickup (Settings → Loans, default: 3 days)
- **Cancel pickup** — Admin can cancel uncollected loans, freeing copy and advancing reservation queue
- **Automatic queue advancement** — Next patron notified immediately when pickup is cancelled
- **Works without cron** — Real-time queue processing, no maintenance service dependency
- **Visual indicators** — Orange badge for "Ready for Pickup" in all loan views
- **Calendar integration** — `ready_for_pickup` periods shown in orange, block availability for other reservations
- **Origin tracking** — System tracks whether loans originated from reservations or manual creation

### Calendar & ICS Integration
- **Interactive dashboard calendar** (FullCalendar) showing all loans and reservations
- **Color-coded events**: active loans (green), scheduled (blue), overdue (red), pending requests (amber), reservations (purple)
- **Start/end markers** for easy visualization of loan periods
- **Click to view details**: user, book title, dates, and status in modal popup
- **ICS calendar export** for syncing with external calendar apps (Google Calendar, Apple Calendar, Outlook)
- **Automatic ICS generation** via maintenance service or cron job
- **Subscribable calendar URL** that stays updated with latest loans and reservations

### Email Notifications
Automatic emails for:
- New user registration
- Registration approval
- Loan confirmation
- Approaching due dates (configurable days before)
- Overdue reminders
- Item-available notifications for reservations

**WYSIWYG email template editor** with dynamic tags for record, user, and loan data.

### Public Catalog (OPAC)
- **Responsive, multilingual frontend** (Italian, English, French, German)
- **AJAX search** with instant results and relevance ranking
- **AJAX filters**: genre, publisher, availability, publication year, format
- **Patrons can leave reviews and ratings** (configurable)
- **Built-in SEO tooling**: sitemap, clean URLs, Schema.org JSON-LD (Book, BreadcrumbList, Event), hreflang tags, RSS 2.0 feed, `/llms.txt` for AI crawlers
- **Cookie-consent banner** and privacy tools (GDPR-compliant)

### Dewey Decimal Classification
- **1,200+ preset categories** in Italian and English loaded from JSON files
- **Hierarchical browsing** — Navigate from main classes (000-999) to subdivisions (e.g., 599.9 Mammals)
- **Manual entry** — Accept any valid Dewey code, not limited to preset list
- **Format validation** — Real-time validation of code format (XXX.XXXX)
- **Automatic population from SBN** — Dewey codes extracted during ISBN scraping are auto-added to the database
- **Multi-language** — Separate JSON files for IT/EN with full translations
- **Dewey Editor plugin** — Visual tree editor for managing classifications with import/export
- **No database table** — Data loaded from `data/dewey/` JSON files at runtime

### Auto-Updater
- **Built-in update system** — Check, download, and install updates from Admin → Updates
- **Manual ZIP upload** — Upload `.zip` release packages for air-gapped or rate-limited environments
- **Automatic database backup** — Full MySQL dump before every update
- **Safe file updates** — Protected paths (.env, uploads, storage) are never overwritten
- **Database migrations** — Automatic execution of SQL migrations for version jumps
- **Atomic rollback** — Automatic restore on error with pre-update backup
- **Orphan cleanup** — Files removed in new versions are deleted from installation
- **OpCache reset** — Automatic cache invalidation after file updates
- **Security** — CSRF validation, admin-only access, path traversal protection, Zip Slip prevention
- **GitHub API token** — Optional personal access token (Admin → Updates) to raise GitHub API rate limits from 60 to 5,000 req/hr

### Physical Inventory
- **Hierarchical location model**: shelf, aisle, position
- **Automatic position assignment** for new copies
- **Barcode generation** in standard formats
- **Printable PDF labels** in multiple sizes (customizable templates)

### Digital Content
- **eBook distribution** (PDF, ePub) with download tracking
- **Audiobook streaming** (MP3, M4A, OGG) with integrated player
- **Drag-and-drop upload** or external URL linking

### Archival Records — ISAD(G) + ISAAR(CPF)

Shipped as the bundled **Archives** plugin (opt-in; activate from Admin → Plugins). Lets the same Pinakes install manage both a book catalogue *and* a hierarchical archive — fonds, series, files, items — according to the international archival standards used by public archives, historical societies, photographic collections, and academic repositories.

**Hierarchical archival description (ISAD(G) 2nd ed.)**
- Four-level hierarchy: `fonds` → `series` → `file` → `item`. Each row is a standalone ISAD(G) record with `parent_id` chaining up to an arbitrary depth (real archives are usually 2-4 deep).
- Full identity area (3.1): reference code, institution code, formal + constructed title, date range (start/end + predominant dates + significant gaps), extent, language codes.
- Context & content (3.2-3.3): archival history, acquisition source, scope & content, appraisal/destruction schedule, accruals policy, system of arrangement.
- Access & use (3.4): access conditions, reproduction rules, language/script notes, physical characteristics, finding aids.
- Allied materials (3.5): originals/copies location, related units.
- Soft-delete aligned with the library-side `libri` convention (deleted rows vanish from views, still queryable for restore).
- Descendant-cycle guard: an edit that would make a unit its own descendant is rejected with a validation error (walks ancestors up to 100 hops).

**Authority records (ISAAR(CPF))**
- Dedicated table, separate from `autori`, because ISAAR covers persons **and** corporate bodies **and** families — a richer element set than bibliographic authors.
- Identity (5.1): type, authorised form, parallel forms, other forms, identifiers (VIAF / ISNI / ORCID).
- Context (5.2): dates of existence, history, places, legal status, functions/occupations, mandates, internal structure/genealogy, general context, gender.
- M:N linking to archival units with MARC-aligned roles: `creator` / `subject` / `recipient` / `custodian` / `associated`.
- Cross-reconciliation with the library-side `autori` table via `autori_authority_link` — unifies books and archives under a single person/entity in the public search.

**Photographic & audio-visual materials (ABA billedmarc)**
- `specific_material` ENUM with 15 ABA codes: text (bf), photograph (hf), poster (hp), postcard (hm), drawing (hd), map (hk), picture (hb), 3D object/realia (ho), audio recording (lm), motion-picture film (lf), video (vm), microform (bm), electronic/born-digital (le), mixed materials (zz), other.
- Dedicated columns for colour mode (bw / colour / mixed), dimensions, photographer, publisher, collection name, local classification — matching the MARC 300/337/338 content/media/carrier vocabulary.

**Per-document digital assets** (v0.7.6+)
- Each archival unit can hold a cover image plus multiple downloadable digitised files (PDF / ePub / audio / video). Files are stored under `public/storage/archives/{unit_id}/` with original filename, MIME type, and display order. Drag-and-drop upload from the admin form; per-file delete with admin confirmation.

**Multi-format export — MARCXML, EAD3, METS, UNIMARC, Dublin Core**
- **MARCXML** (Library of Congress MARC21 Slim): `GET /admin/archives/{id}/export.xml` and `GET /admin/archives/export.xml?ids=…` emit ABA-crosswalk MARCXML via XMLWriter. Authorities exported as 100/110/600/610/700/710 tags depending on `(type, role)`.
- **EAD3** (archivists.org Encoded Archival Description): `GET /admin/archives/export.ead3` emits a full EAD3 finding aid. Mirrors what AtoM, ArchivesSpace, and the national portals consume.
- **METS** (Library of Congress Metadata Encoding & Transmission): `GET /archives/{id}/mets.xml` packages descriptive metadata + IIIF manifest link + digitised assets into a single METS document for OAI-PMH MAG harvesting (Internet Culturale / ICCU).
- **UNIMARC** (IFLA): exposed via SRU/OAI-PMH for federation with BNF and Italian SBN partners.
- **Dublin Core** (oai_dc): `GET /archives/{id}/dc.xml` and via OAI-PMH below.
- **Import**: `POST /admin/archives/import` parses MARCXML (SimpleXML) with optional XSD validation against the Library of Congress MARC21 Slim v1.1 schema. UPSERT on `(institution_code, reference_code)` — re-importing the same file is idempotent. Dry-run preview available.

**SRU 1.2 read-only endpoint**
- `GET /api/archives/sru` — supports `explain`, `searchRetrieve` (CQL subset: `title`, `reference`, `level`, `anywhere`, joined with `AND`), and `scan` stub. External catalogues (Reindex, Koha, ARKIS, BNF) can federate-search the archive using MARCXML records.

**IIIF Presentation 3.0** ([#123](https://github.com/fabiodalez-dev/Pinakes/issues/123), v0.7.6+)
- `GET /archives/{id}/manifest.json` returns a standards-compliant IIIF Presentation 3.0 manifest for every archival unit, exposing attached digitised documents as `Canvas` items with painting `Annotation`s. Works out of the box with **Universal Viewer**, **Mirador**, and any other IIIF-compatible viewer.
- `GET /archives/collection.json` and `GET /archives/{id}/collection.json` emit IIIF Collection documents for the root fonds list and per-unit sub-collections.
- The manifest's `seeAlso` block points to every alternative serialisation (Dublin Core, EAD3, METS, RiC-O, OAI-PMH record, ARK identifier) so an IIIF-aware client can discover the full graph of metadata representations with no second discovery round-trip.

**RiC-O JSON-LD** (Records in Contexts Ontology, ICA 2023 — [#122](https://github.com/fabiodalez-dev/Pinakes/issues/122), v0.7.7+)
- `GET /archives/{id}/ric.json` and `GET /archives/agents/{id}/ric.json` emit RiC-O JSON-LD for archival units and authority records — the linked-data successor to ISAD(G)/ISAAR. Each role on `archival_unit_authority` maps to a typed predicate (`ric:isCreatorOf`, `ric:isOrWasCustodianOf`, `ric:isSubjectOf`, `ric:isAddresseeOf`, `ric:isAssociatedWith`).
- `GET /archives/collection.ric.json` emits a synthetic `ric:RecordSet` aggregating all top-level fonds, suitable for harvesting by Europeana, ArchivesPortalEurope, and the ICA aggregator.
- `owl:sameAs` URIs are gathered transparently from the `viaf-authority` plugin's authority links and filtered through a strict scheme allow-list (`http(s)`, `urn`, `ark`, `info`, `doi`) before emission.

**AtoM ISAD(G) area labels** ([#121](https://github.com/fabiodalez-dev/Pinakes/issues/121), v0.7.6+)
- The admin UI and public display now use the canonical ISAD(G) area names (`Identity area`, `Context area`, `Content and structure area`, `Conditions of access and use area`, `Allied materials area`, `Notes area`), so records are immediately recognisable to users coming from AtoM or other archival catalogue software.

**ARK persistent identifiers + rightsstatements.org** (v0.7.x)
- Each archival unit can carry an ARK identifier (Archival Resource Key) — emitted as `https://n2t.net/{ark}` in the IIIF `seeAlso` and the RiC-O `rdfs:seeAlso` blocks.
- Rights are expressed via a `rightsstatements.org` URI (e.g., `https://rightsstatements.org/vocab/InC/1.0/`) — mapped to `ric:Rule` + `owl:sameAs` in the RiC-O output.

**Unified cross-entity search**
- `/admin/archives/search` hits three sources in a single query: `archival_units` (FULLTEXT on title + scope + archival_history), `authority_records` (FULLTEXT on authorised_form + history + functions), and `autori` rows reconciled to an authority.

**Search bars (admin + public)**
- **Admin** (`/admin/archives?q=…&level=…`): two-pass query — LIKE on `reference_code` first (short archival codes like `IT-MI-001` are below FULLTEXT's `ft_min_word_len`), then FULLTEXT on title/scope/history. Level filter narrows to one archival tier. Search mode renders results as a flat list (no hierarchy indent). Result counter and input persistence.
- **Public** (`/archivio?q=…&level=…&date_from=…&date_to=…`): same text + level filters plus date-range overlap (`date_from` / `date_to`). In search mode all hierarchy levels are returned (not just root fonds), so a user can search directly for a series or fascicolo by reference code. Theme-aware CSS.

**OAI-PMH 2.0 data provider**
- `GET /oai` (exposed by the OAI-PMH server plugin) advertises archival units alongside book records via `set=archives`: `Identify`, `ListMetadataFormats` (oai_dc + marc21 + ead3 + ric-o for archival_unit), `ListSets` (per ISAD level), `ListRecords`/`GetRecord` with resumption tokens, selective harvesting by set + date range.

**Plugin integration**
- Self-contained at `storage/plugins/archives/`. Wires up through two `plugin_hooks` rows (`app.routes.register`, `admin.menu.render`) on activation; deactivation removes the route + sidebar entry without touching DB data.
- Full i18n (IT/EN/DE) with ICA-ISAD(G) terminology (IT) / ICA (EN) / ICA-Deutsch (DE: Bestand / Signatur / Einzelstück).
- Migration `migrate_0.5.9.sql` is fully idempotent (INFORMATION_SCHEMA guards + conditional ALTERs) — safe to re-run on partial installs, cleanly extends the ENUM on upgrades.

### Plugin System
Extend without modifying core files. Plugins can implement:
- New metadata scrapers (custom APIs, proprietary databases)
- Additional business logic (custom loan rules, notifications)
- Digital-content modules (eBooks, audiobooks, streaming)
- Import/export routines (MARC21, UNIMARC, custom formats)

Plugins support encrypted secrets and isolated configuration. Install via ZIP upload in admin panel.

**Pre-installed plugins** (16 included — every one opt-in via Admin → Plugins; the only one auto-active is Open Library):

*Metadata scraping & enrichment*
- **Open Library** — Metadata scraping from Open Library + Google Books API; the default scraper
- **API Book Scraper** — External ISBN enrichment via configurable REST endpoints
- **Discogs / MusicBrainz / Deezer** — Music scrapers for CDs, LPs, vinyls, cassettes (barcode + title lookup, Cover Art Archive HD jackets, tracklists, label info)
- **GoodLib** — One-click cross-search badges on the book detail page (Anna's Archive, Z-Library, Project Gutenberg)
- **VIAF Authority Control** — Links authors to VIAF/ISNI authority records with confidence scoring + W3C reconciliation API

*Interoperability protocols*
- **Z39 Server** — SRU 1.2 API + Z39.50/SRU client for Italian SBN, French **BNF** (UNIMARC), and any standard library catalogue with Dewey extraction (v0.7.6+)
- **OAI-PMH Server** — OAI-PMH 2.0 data provider for books + archives, harvestable by Internet Culturale (ICCU), Europeana, DPLA. Formats: `oai_dc`, `marc21`, `mods`, `mag` (2.0.1), `unimarc`, `ead3`, `ric-o` (RDF/XML, archival units)
- **NCIP 2.0 Server** — NISO Circulation Interchange Protocol for self-service kiosks, partner ILS, and library networks
- **BIBFRAME 2.0 Linked Data** — Exposes the book catalogue as BIBFRAME 2.0 JSON-LD / Turtle with content negotiation (Library of Congress transition path from MARC)
- **OpenURL Resolver** — Z39.88-2004 resolver + COinS metadata embedded in book pages; works with Zotero, Mendeley, EndNote
- **ResourceSync** — ANSI/NISO Z39.99-2014 dataset synchronisation for harvester partners

*Cataloging & specialised collections*
- **Dewey Editor** — Visual tree editor for Dewey classification data with JSON import/export and auto-population from SBN/SRU scraping
- **Digital Library** — eBook (PDF, ePub) and audiobook (MP3, M4A, OGG) management with HTML5 streaming player
- **Archives** — ISAD(G) hierarchical archival records + ISAAR(CPF) authority records. MARCXML / EAD3 / METS / UNIMARC / Dublin Core export, SRU 1.2 endpoint, OAI-PMH 2.0 data provider, **IIIF Presentation 3.0** manifests (v0.7.6), **RiC-O JSON-LD** export (v0.7.7), AtoM ISAD(G) area labels, ARK persistent identifiers, full-text + reference-code search bar (admin + public with date-range filter), photographic-material support (ABA billedmarc 15 codes), per-document cover + downloadable file uploads, and unified cross-entity search bridging books + archives

### CMS and Customization
- **Homepage editor** with drag-and-drop blocks (hero banner, featured shelves, events, testimonials)
- **Custom pages** (About, Regulations, Events) with WYSIWYG editing
- **10 color themes** with instant switching (Sky Blue, Forest Green, Royal Purple, Sunset Orange, Cherry Red, Ocean Teal, Lavender Dreams, Midnight, Coral Sunset, Golden Hour)
- **Custom theme editor** with live preview for primary, secondary, and CTA colors
- **Logo customization** and branding
- **Centralized media manager** for images and documents
- **Event management** with dates, descriptions, and homepage display

### APIs
- **REST API** for search, availability, cataloging, and statistics
- **SRU 1.2 protocol** at `/api/sru` — standard library interoperability (MARCXML, Dublin Core, MODS export)
- **Z39.50 client** for copy cataloging from external catalogs (Library of Congress, OCLC, national libraries)
- **CSV and Excel export** for reports and backups
- **PDF generation** for labels, receipts, and reports

### User Management
- **Manual approval** of new registrations (optional)
- **Automatic card number assignment** with customizable prefixes
- **Complete per-user history** of loans and reservations
- **Self-service patron portal** with loan renewal, reservation management, and wishlists

---

## Why It Might Be Useful

- **Fast ISBN-driven cataloging** cuts manual entry to seconds per book
- **Usable public catalog** without needing a web developer or custom theme work
- **Extensible via plugins** if you want custom scrapers, integrations, or business logic
- **Self-hosted and GPL-3 licensed** — full control, no vendor lock-in, no recurring fees
- **Works on shared hosting** — no root access, Docker, or build tools required on production

---

## Plugins (Pre-installed)

All plugins are located in `storage/plugins/` and can be managed from **Admin → Plugins**.

### 1. Open Library (`open-library-v1.0.1.zip`)
- **Metadata scraping** from Open Library API
- **Fallback to Google Books** when Open Library lacks data
- **Automatic cover download** with validation
- **Subject mapping** and language normalization
- **Configurable priority** and caching options

### 2. Z39 Server (`z39-server-v1.2.3.zip`)

Implements the **SRU (Search/Retrieve via URL)** protocol, the HTTP-based successor to Z39.50, enabling catalog interoperability with library systems worldwide.

**Server Mode** (expose your catalog):
- **SRU 1.2 API** at `/api/sru` with explain, searchRetrieve, scan operations
- **Export formats**: MARCXML, Dublin Core, MODS, OAI_DC
- **CQL query parser** supporting indexes: `dc.title`, `dc.creator`, `dc.subject`, `bath.isbn`, `dc.publisher`, `dc.date`
- **Rate limiting** (100 req/hour per IP) and comprehensive access logging
- **Optional API key authentication** via `X-API-Key` header
- **Trusted proxy support** for deployments behind load balancers (CIDR notation)

**Client Mode** (import from external catalogs):
- **Copy cataloging** from Z39.50/SRU servers (Library of Congress, OCLC, K10plus, SUDOC, national libraries)
- **SBN Italia client** — Automatic metadata retrieval from Italian national library catalog
- **BNF (Bibliothèque nationale de France) client** (v0.7.6+) — UNIMARC scraping from the BNF SRU endpoint with field mapping to Pinakes metadata (title, authors, publisher, ISBN, Dewey, subjects). Enable Z39 Server and add `sru.bnf.fr` as a source to start importing French bibliographic records.
- **UNIMARC parser** — Handles UNIMARC field codes (200, 210, 215, 700/701/702, 676 for Dewey) in addition to MARC21, so French + Italian + Swiss + Belgian + Quebecois catalogues are all consumable
- **Dewey classification extraction**:
  - SBN: Parses Dewey codes from `classificazioneDewey` field (format: `335.4092 (19.) SISTEMI MARXIANI`)
  - SRU/MARCXML: Extracts from MARC field 082 (Dewey Decimal Classification Number)
  - UNIMARC: Extracts from field 676 (BNF Dewey representation)
  - Dublin Core: Parses from `dc:subject` (DDC scheme) and `dc:coverage` fields
- **Federated search** across multiple configured servers
- **Automatic retry** with exponential backoff (100ms, 200ms, 400ms)
- **TLS certificate validation** for secure connections
- **MARCXML, UNIMARC, and Dublin Core parsing** with author extraction (MARC 100/700, UNIMARC 700/701/702 fields)
- **CQL injection hardening** (v0.7.6+) — search terms containing `"` or `\` are properly escaped per the CQL specification before being embedded into queries sent to external SRU endpoints

**Example queries**:
```bash
# Server info
curl "http://yoursite.com/api/sru?operation=explain"

# Search by author
curl "http://yoursite.com/api/sru?operation=searchRetrieve&query=dc.creator=marx&maximumRecords=10"

# Search by ISBN (Dublin Core format)
curl "http://yoursite.com/api/sru?operation=searchRetrieve&query=bath.isbn=9788842058946&recordSchema=dc"
```

**Use cases**: Union catalogs, interlibrary loan systems, OPAC federation, copy cataloging workflows, automatic Dewey classification.

### 3. API Book Scraper (`api-book-scraper-v1.1.1.zip`)
- **External API integration** for ISBN enrichment
- **Custom endpoint configuration** (URL, headers, auth)
- **Response mapping** to Pinakes schema
- **Retry logic** with exponential backoff
- **Error logging** and debugging tools

### 4. Digital Library (`digital-library-v1.3.0.zip`)
- **eBook support** (PDF, ePub) with download tracking
- **Audiobook streaming** (MP3, M4A, OGG) with HTML5 player
- **Per-item digital asset management** (unlimited files per book)
- **Access control** (public, logged-in users only, specific roles)
- **Usage statistics** and download history

### 5. Dewey Editor (`dewey-editor-v1.0.1.zip`)

Complete Dewey Decimal Classification management system with multilingual support, automatic population, and data exchange capabilities.

**Core Features**:
- **Tree-based visual editor** — Navigate and edit the complete Dewey hierarchy (1,200+ preset entries)
- **Multi-language support** — Separate JSON files for Italian (`dewey_completo_it.json`) and English (`dewey_completo_en.json`) with full translations
- **Inline editing** — Add, modify, or delete categories with instant validation
- **Validation engine** — Checks code format (XXX.XXXX), hierarchy consistency, and duplicate detection

**Data Exchange**:
- **JSON import/export** — Backup and restore classification data for manual editing or exchange with other Pinakes installations
- **Cross-installation sharing** — Export your customized Dewey database and import it into another Pinakes instance
- **Merge capability** — Import external classifications while preserving existing entries

**Automatic Dewey Scraping**:
- **SBN integration** — When scraping book metadata from SBN (Italian National Library), Dewey codes are automatically extracted from the `classificazioneDewey` field
- **SRU/Z39.50 servers** — Dewey codes extracted from MARC field 082 when querying external catalogs (K10plus, SUDOC, Library of Congress, etc.)
- **Auto-population** — New Dewey codes discovered during scraping are automatically added to your JSON database (language-aware: only updates when source language matches app locale)
- **CSV import enrichment** — Books imported via CSV are automatically enriched with Dewey classification through ISBN scraping

**Dewey Code Format**:
- Main classes: `000`-`999` (3 digits)
- Subdivisions: `000.1` to `999.9999` (up to 4 decimal places)
- Examples: `599.9` (Mammiferi/Mammals), `004.6782` (Cloud computing), `641.5945` (Cucina italiana/Italian cuisine)

**Book Form Integration**:
- **Chip-based selection** — Selected Dewey code displays as removable chip with code + name
- **Manual entry** — Accept any valid Dewey code (not limited to predefined list)
- **Hierarchical navigation** — Optional collapsible "Browse categories" for discovering codes
- **Breadcrumb display** — Shows full classification path (e.g., "500 → 590 → 599 → 599.9")
- **Frontend validation** — Real-time format validation before submission

### 6. Archives (`archives`)

ISAD(G) / ISAAR(CPF) hierarchical archival records — see [Archival Records](#archival-records--isadg--isaarcpf) in Core Features for the full feature breakdown (IIIF 3.0 manifests, RiC-O JSON-LD export, MARCXML / EAD3 / METS / UNIMARC / Dublin Core, SRU 1.2, OAI-PMH 2.0, AtoM area labels, ARK identifiers, photographic-material support).

### 7. VIAF Authority Control (`viaf-authority`)

Bibliographic authority control linking authors to the **Virtual International Authority File** (VIAF, OCLC) and **ISNI** (ISO 27729).

- **Authority enrichment** — Adds `viaf_id` / `viaf_uri` / `isni_id` / `isni_uri` columns to the `autori` table; `authority_source` (manual / viaf / isni / sbn / wikidata) records where each binding came from
- **Confidence scoring** — Each authority binding carries an `authority_confidence` (exact / probable / candidate / rejected) so curators can review weak matches
- **VIAF AutoSuggest** — Type-ahead search in the author edit form queries the VIAF AutoSuggest API directly
- **W3C Reconciliation API** — `/api/viaf/reconcile` endpoint compatible with OpenRefine and other reconciliation clients
- **ISNI check-digit validation** — Rejects malformed 16-character ISNI strings before they reach the DB
- **Authority alternates** — `author_authority_alternates` table holds additional identifier candidates (Wikidata, BNF, GND, etc.) for future scheme expansion
- **Used by**: the Archives plugin's RiC-O JSON-LD output pulls `owl:sameAs` URIs from these tables so books and archives surface the same persons under a unified Linked-Data identity

### 8. OAI-PMH Server (`oai-pmh-server`)

OAI-PMH 2.0 data provider exposing the **book catalogue + archives** to national and international harvesters (Internet Culturale / ICCU, Europeana, DPLA).

- **Endpoint**: `GET/POST /oai` — supports all six required verbs (`Identify`, `ListMetadataFormats`, `ListSets`, `ListIdentifiers`, `ListRecords`, `GetRecord`)
- **Formats**: `oai_dc` (Dublin Core), `marc21` (MARCXML), `mods`, `mag` (MAG 2.0.1 — the ICCU national format), `unimarc`, `ric-o` (Records in Contexts Ontology, RDF/XML — archival units only)
- **Sets**: separates books, archives, and per-archival-level subsets (`fonds`, `series`, `file`, `item`) so harvesters can selectively ingest
- **Resumption tokens**: DB-backed (`oai_pmh_resumption_tokens` table) so cursor-paginated `ListRecords` calls survive across requests with a stable token
- **Selective harvesting**: `from` / `until` date filters + `deletedRecord=persistent` so harvesters get tombstones for soft-deleted rows
- **Compliance**: validated against the OAI Validator and the Europeana harvester

### 9. NCIP 2.0 Server (`ncip-server`)

**NISO Circulation Interchange Protocol** 2.0 — enables interlibrary loan exchange, self-service kiosks, and partner-ILS integration.

- **Endpoint**: `POST /ncip` (Content-Type: `application/xml`)
- **Services supported**: `LookupItem`, `LookupUser`, `CheckOutItem`, `CheckInItem`, `RenewItem`, `RequestItem`, `CancelRequestItem`
- **Authentication**: NCIP `InitiationHeader` with `FromAgencyAuthentication` shared-secret model
- **Use cases**: self-service borrowing kiosks (3M / Bibliotheca SelfCheck), library-network reciprocal lending, partner ILS bridging

### 10. BIBFRAME 2.0 Linked Data (`bibframe-linked-data`)

Exposes the book catalogue as **BIBFRAME 2.0** Linked Data per the Library of Congress transition path from MARC.

- **Content negotiation** — `Accept: application/ld+json` returns BIBFRAME JSON-LD; `Accept: text/turtle` returns Turtle
- **Stable resource URIs** — `/bibframe/works/{id}`, `/bibframe/instances/{id}`, `/bibframe/items/{id}`
- **Authority links** — When the `viaf-authority` plugin is also enabled, agents carry `owl:sameAs` to VIAF/ISNI
- **Suitable for**: Linked-Data discovery, library-of-the-future pilots, reconciliation workflows

### 11. OpenURL Resolver (`openurl-resolver`)

**Z39.88-2004 OpenURL** resolver — bridges Pinakes to bibliographic reference managers.

- **Endpoint**: `GET /openurl?rft.isbn=…` accepts standard OpenURL ContextObjects and redirects to the matching book page (or returns 404 if no match)
- **COinS metadata** — Every public book page embeds a `<span class="Z3988" title="ctx_ver=Z39.88-2004…">` so reference managers can capture the citation with one click
- **Compatible with**: Zotero, Mendeley, EndNote, RefWorks, and any OpenURL-aware tool
- **Hardening** (v0.7.6+) — `absoluteUrl()` is used for all redirect URL construction, so host-header spoofing cannot trick the resolver into open-redirecting to an attacker-controlled domain

### 12. ResourceSync (`resource-sync`)

**ANSI/NISO Z39.99-2014 ResourceSync** — large-scale dataset synchronisation for harvester partners.

- **Endpoints**: `/.well-known/resourcesync`, `/resourcesync/capabilitylist.xml`, `/resourcesync/resourcelist.xml`, `/resourcesync/changelist.xml`
- **Use cases**: bulk-mirror Pinakes catalogue to a partner system, periodic differential sync, large-scale Linked-Data harvesting
- **Pairs with**: OAI-PMH (record-level) and SRU (query-time) for a three-layer interop stack

### 13. Music Scraper (`discogs`)

Multi-source music metadata scraping for CDs, LPs, vinyls, cassettes, and other physical music media.

- **Sources**: Discogs (barcode + title lookup), MusicBrainz + Cover Art Archive (fallback by barcode), Deezer (HD jackets)
- **Catalog-number support** — Accepts Cat# identifiers (e.g. `DGG 477 8761`) in addition to barcodes ([#101](https://github.com/fabiodalez-dev/Pinakes/issues/101))
- **Rich metadata**: artist, album, label, year, tracklist with durations, genre, country of pressing
- **Bulk enrichment** — Re-scrape all music records in one click from Admin → Books → Music

### 14. MusicBrainz + Cover Art Archive (`musicbrainz`)

Open-data music metadata source — no API token required.

- **Search by barcode** — Direct MBID lookup via barcode
- **Cover Art Archive** integration for HD album art
- **Tracklist, label, year, country** extraction

### 15. Deezer Music Search (`deezer`)

Lightweight music search backed by the Deezer API — no token required.

- **Search by title/artist** — Best for completing metadata when barcode lookup fails
- **HD covers** — High-resolution album artwork
- **Tracklist with durations** and genre tags

### 16. GoodLib (`goodlib`)

Adds one-click cross-search badges to the public book detail page.

- **Targets**: Anna's Archive, Z-Library, Project Gutenberg
- **Use case**: when a library wants to point patrons at legitimate open-access full-text sources alongside its own catalogue
- **Inspired by**: the GoodLib browser extension
- **Activation**: opt-in — disabled by default since not every library wants to surface third-party shadow-library links

---

## Tech Stack

**Backend**: Slim 4.13, PHP-DI, Slim PSR-7 + CSRF, Monolog 3, PHPMailer 6.10, TCPDF 6.10, Google reCAPTCHA, thepixeldeveloper/sitemap, emleons/sim-rating, vlucas/phpdotenv.

**Frontend**: Webpack 5, Tailwind CSS 3.4.18, Bootstrap 5.3.8, jQuery 3.7.1, DataTables 2.3.x, Chart.js 4.5, SweetAlert2 11, Flatpickr 4.6, Sortable.js 1.15, Choices.js 11, TinyMCE 8, Uppy 4, jsPDF, JSZip, Font Awesome, Inter font (self-hosted).

---

## Deployment

### Apache (Shared Hosting)
Works out of the box. Two `.htaccess` files handle routing:
- **Root `.htaccess`**: Redirects to `/public/` or `/installer/`
- **`public/.htaccess`**: Front controller routing, security headers, CORS

### Nginx (VPS/Dedicated)
Copy `.nginx.conf.example` to your Nginx sites directory:
```bash
sudo cp .nginx.conf.example /etc/nginx/sites-available/pinakes
sudo nano /etc/nginx/sites-available/pinakes  # Edit server_name, root, PHP-FPM
sudo ln -s /etc/nginx/sites-available/pinakes /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

---

## Support & Contact

- **Email**: [pinakes@fabiodalez.it](mailto:pinakes@fabiodalez.it)
- **Issues**: [GitHub Issues](https://github.com/fabiodalez-dev/pinakes/issues)
- **Discussions**: [GitHub Discussions](https://github.com/fabiodalez-dev/pinakes/discussions)

---

## Contributing & License

Contributions, issues, and feature requests are welcome via GitHub pull requests. Pinakes is released under the **GNU General Public License v3.0** (see [LICENSE](LICENSE)).

If Pinakes helps your library, please ⭐ the repository!

---

## Handy Paths for Developers

- `app/Views/libri/partials/book_form.php` – Catalog form logic, ISBN ingestion
- `app/Controllers/PrestitiController.php` – Core lending workflows
- `app/Controllers/LoanApprovalController.php` – Loan approval, pickup confirmation, cancellation
- `app/Controllers/ReservationsController.php` – Queue handling
- `app/Services/ReservationReassignmentService.php` – Queue advancement on returns/cancellations
- `app/Controllers/UserWishlistController.php` – Wishlist UX
- `app/Views/frontend/catalog.php` – Public catalog filters
- `app/Controllers/SeoController.php` – Sitemap + robots.txt
- `app/Controllers/FeedController.php` – RSS 2.0 feed
- `app/Support/HreflangHelper.php` – Hreflang alternate URL generation
- `storage/plugins/` – Plugin directory (all pre-installed plugins)

---

## Community Projects

- **[jbenamy/pinakes-docker](https://github.com/jbenamy/pinakes-docker)** — Community-maintained Docker image. This is an independent project not managed by the Pinakes team — please refer to its own documentation for setup and support.

---

## Support

If you find Pinakes useful, consider supporting the project:

<a href="https://buymeacoffee.com/fabiodalez" target="_blank" rel="noopener noreferrer"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me A Coffee" height="50"></a>
