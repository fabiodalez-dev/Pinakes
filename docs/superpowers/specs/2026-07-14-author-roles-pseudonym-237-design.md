# Issue #237 — Author roles (illustrator/translator/curator/colorist) + pseudonym search & display

**Status:** approved design · **Date:** 2026-07-14 · **Issue:** #237 (@ctariel)

## Problem

Two independent gaps reported by @ctariel:

1. **Contributor roles.** For comics, an *illustrator* (and sometimes a *colorist*)
   is distinct from the author. Today the book form has `illustratore`,
   `traduttore`, `curatore` as **free-text `<input>`** columns on `libri`, while
   the *author* is a Choices.js picker backed by the `autori` entity. So the
   illustrator gets no autocomplete, is not an entity, and never appears in the
   authors list. There is no *colorist* at all.
2. **Pseudonym.** The `autori` table has `nome` (real name) + `pseudonimo`. In the
   book form the author autocomplete (`SearchController::authors`) searches only
   `nome`, so an author cannot be found by pseudonym. On the book, only the real
   `nome` is ever displayed, never the pseudonym.

## Key facts (verified in code)

- `libri_autori.ruolo` is already `enum('principale','co-autore','traduttore','illustratore','curatore')` — the role model exists but is **unused**: `BookRepository::syncAuthors()` deletes all rows and re-inserts every author as `ruolo='principale'`.
- Free-text roles live in `libri.illustratore/traduttore/curatore` (`AuthorNormalizer::normalize`), written by the form AND by ISBN scraping / CSV import.
- `AuthorRepository::getBooksByAuthorId()` joins `libri_autori` with **no ruolo filter** → once contributor rows exist, an author page lists those books automatically.
- Book-form autocomplete: `/api/search/autori` → `SearchController::authors` (searches `nome` only, returns `nome AS label`).

## Decisions (confirmed with maintainer)

- **Approach A** — contributor roles become first-class `autori` entities via `libri_autori.ruolo` (not just autocomplete on free-text).
- **Migrate** existing free-text `illustratore/traduttore/curatore` into entities on upgrade.
- **Add `colorista`** to the enum.
- **Pseudonym display** = `"Pseudonimo (Nome vero)"` when a pseudonym is set, else `Nome`.
- Keep the free-text columns (do **not** drop them) as a rollback/safety net; the form stops writing them.
- Four **parallel Choices.js selects** in the form (mirroring the existing authors one), not a single "contributor + role" grid.

## Design

### Component 1 — Centralized author display name (Part 2b + reused by Part 1)

New `App\Support\AuthorName`:
- `AuthorName::display(array $author): string` — PHP-side: `pseudonimo !== '' ? "$pseudonimo ($nome)" : $nome`. Used for Choices.js chip labels and initial-authors labels.
- `AuthorName::displaySql(string $alias = 'a'): string` — returns the SQL expression
  `CASE WHEN {a}.pseudonimo IS NOT NULL AND {a}.pseudonimo <> '' THEN CONCAT({a}.pseudonimo,' (',{a}.nome,')') ELSE {a}.nome END`
  for use inside `SELECT` / `GROUP_CONCAT`.

Applied at the book-display surfaces (not every `a.nome` in the codebase — only where an author is shown *for a book*): `BookRepository` (list + detail `GROUP_CONCAT`), `DashboardStats`, frontend `book-detail.php`, admin `scheda_libro.php`. The author's own management pages continue to show `nome`/`pseudonimo` as their own columns (unchanged).

### Component 2 — Pseudonym-aware search (Part 2a)

`SearchController::authors`: change each per-word condition from `nome LIKE ?` to
`(nome LIKE ? OR pseudonimo LIKE ?)` (bind two params/word); the label returned uses
`AuthorName::displaySql()`. Unchanged: word-AND semantics, ordering.

### Component 3 — Schema migration (SQL) + guarded data backfill (PHP)

The free-text→entity conversion is row logic (split, find-or-create, insert) that
pure SQL cannot perform safely. Schema and data work therefore remain separate,
but every migration runner completes both before reporting a successful upgrade:

1. **Schema (`migrate_<release>.sql`).** The enum extension plus the idempotent
   `libri_autori_import_sources` provenance table used by authoritative importers.
   The role ALTER remains idempotent
   (safe to re-apply): `ALTER TABLE libri_autori MODIFY ruolo enum('principale','co-autore','traduttore','illustratore','curatore','colorista') …`. Runs during the upgrade via the normal SQL migration runner. File version **≤** release version (CLAUDE.md rule).

2. **Data backfill.** `App\Support\ContributorBackfill::run(mysqli $db): bool` is
   invoked synchronously by `Updater::runMigrations()` (including the Docker
   runner) and by `scripts/manual-upgrade.php`. `MaintenanceService::runAll()`
   remains a recovery path for interrupted/legacy upgrades.
   Gated by a `system_settings('migrations','contributors_backfilled')` marker so it
   runs **exactly once** and is idempotent. For each `role ∈ {illustratore, traduttore,
   curatore}` and each book with a non-empty `libri.<role>`: split on unambiguous
   list separators (`;`, `|`, `&`, ` e `, ` and `); split commas only when every
   fragment already looks like a complete multi-word name, preserving SBN forms
   such as `Cognome, Nome`; normalize each name, `AuthorRepository` find-or-create by `nome`,
   persist the role association and importer provenance. The marker + primary
   keys make a re-run a no-op. Free-text columns are retained.

The upgrade is not stamped complete if the backfill fails, eliminating the period
where migrated installations had the new UI but no entity links. New ingestion
also records ownership, so a later reimport can replace its stale links without
deleting manual contributors.

### Component 4 — Form (`app/Views/libri/partials/book_form.php`)

Replace the three free-text inputs with four Choices.js entity selects
(`illustratori_select[]`, `traduttori_select[]`, `curatori_select[]`,
`coloristi_select[]`), each a clone of the authors picker (same `/api/search/autori`,
same create-on-Enter, initial values from the book's `libri_autori` rows by role,
labels via `AuthorName::display`). Factor the authors Choices.js init into a small
reusable initializer invoked once per role to avoid five copy-pasted blocks.

### Component 5 — Save (`BookRepository`)

Generalize `syncAuthors()` → `syncContributors(int $bookId, array $rolesToIds)`.
The insert/verify/delete-stale sequence is transactional (or savepoint-backed when
the caller already owns a transaction). It deliberately avoids `INSERT IGNORE`:
an invalid FK or unsupported enum rolls the whole sync back before any old link is
dropped. Only roles actually supplied are pruned. The creator picker is authoritative
for both `principale` and `co-autore`, preserving an existing co-author role instead
of duplicating it as principal. The controller maps
`autori_select` → `principale`, `illustratori_select` → `illustratore`, etc.
CSV and LibraryThing use `syncImportedLegacyValues()`: their links are tracked by
source and replaced on reimport, while untracked manual links remain untouched.
An explicit admin-form save releases importer provenance for the supplied roles,
so contributors deliberately retained by a librarian become manual and cannot be
removed by a later import.
Stop writing `libri.illustratore/traduttore/curatore` from the form save.

### Component 6 — Display (book detail, admin + frontend)

Show contributors grouped by role — **Autore, Illustratore, Traduttore, Curatore,
Colorista** — each linking to the author entity, names via `AuthorName::display`.
Roles with no contributors are hidden. `getBookAuthors`-style fetch returns rows with
`ruolo` so the view can group.

### Component 7 — i18n

Role labels (`Illustratore`, `Traduttore`, `Curatore`, `Colorista`, and any new UI
strings) added to **all four** locales (`it_IT`, `en_US`, `de_DE`, `fr_FR`) in the
same commit (i18n-blocking rule), parity verified.

## Testing

- **Migration unit test** (`tests/migration-<version>.unit.php`): seed OLD schema
  (enum without `colorista`, books with free-text illustrator incl. a comma list),
  run the real `migrate_<release>.sql` ALTER **and** `ContributorBackfill::run()`,
  assert: enum extended; `libri_autori` rows created with correct `ruolo`; comma list
  split into N authors; the marker is set; **idempotent** on second run (no duplicates,
  no re-split, backfill no-ops).
- **Real upgrade regression** (`scripts/reinstall-test.sh` Test B): install 0.7.35,
  upgrade via admin UI and verify that enum, provenance table and migrated
  `libri_autori` rows all exist before the migration runner returns success.
- **E2E** (Playwright): create a book, add an illustrator via the new picker (existing
  + brand-new), save, verify the `libri_autori` row + the illustrator appearing on the
  book detail and on that author's page; pseudonym: create an author with a pseudonym,
  find them in the book form by pseudonym, verify the book shows `"Pseudonimo (Nome)"`.
- PHPStan L5 full-tree = 0; locale parity.

## Out of scope

- Dropping the free-text columns (kept as safety net; a later cleanup release may remove them).
- The unused `co-autore` enum value (left as-is).
- Reworking the authors management page display (it already searches pseudonym; its own columns are unchanged).

## Rollout

Ships in the next release (bump from 0.7.35). Full `updater.md` gate: migration test
+ reinstall-test A/B + create-release.sh. Reply drafted for @ctariel on #237, posted at release.
