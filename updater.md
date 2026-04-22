# Pinakes Updater System - Technical Documentation

This document explains how the Pinakes auto-updater works, its requirements, and troubleshooting steps.

---

## 🚨 ABSOLUTE RULE — ALWAYS VERIFY THE UPLOADED ZIP

**SEMPRE SEMPRE SEMPRE** verify that the ZIP that is actually on GitHub
matches the ZIP that was produced locally. "The upload succeeded" is
**not** enough — `gh release upload` has been observed producing a
remote asset whose SHA256 differs from the local file, silently. This
bit us on v0.5.9.2 (2026-04-22):

- Local `pinakes-v0.5.9.2.zip` = 26,724,173 bytes, SHA256 `1356b354…`, 10 plugin folders.
- Remote `pinakes-v0.5.9.2.zip` = 24,760,516 bytes, SHA256 `2d49dcfb…`, 5 plugin folders.
- Three separate hotfix releases (0.5.9, 0.5.9.1, 0.5.9.2) shipped with
  the same bug reported by HansUwe52 because nobody verified the remote
  artifact — we trusted the local verification step 5.5 and assumed the
  upload preserved the file.

**The rule:**

After every `gh release upload`, before announcing the release or
telling a user to upgrade:

```bash
curl -sL -o /tmp/verify.zip \
  "https://github.com/fabiodalez-dev/Pinakes/releases/download/v${VERSION}/pinakes-v${VERSION}.zip"

LOCAL_SHA=$(shasum -a 256 "pinakes-v${VERSION}.zip" | awk '{print $1}')
REMOTE_SHA=$(shasum -a 256 /tmp/verify.zip | awk '{print $1}')

[ "$LOCAL_SHA" = "$REMOTE_SHA" ] || {
  echo "❌ REMOTE ZIP DOES NOT MATCH LOCAL ZIP — ABORT"
  echo "   local:  $LOCAL_SHA"
  echo "   remote: $REMOTE_SHA"
  exit 1
}

# Also re-run the plugin.json sanity check on the remote artifact
unzip -l /tmp/verify.zip | grep -cE "storage/plugins/[^/]+/plugin\.json$"
# Must equal the expected bundled-plugin count (currently 10)
```

`scripts/create-release.sh` now performs this check automatically (step
9.5). Do not delete or short-circuit that step. If the check fails,
delete the release (`gh release delete v${VERSION} --yes`), investigate
why the upload diverged, and re-run — never ship a "probably fine"
release.

**Testimonial (HansUwe52, 2026-04-22)**:
> "porco dio mi stai facendo fare una figura di merda, hai verificato lo
> zip prima di caricarlo?"

The answer has to be "yes, the one on GitHub" — not "yes, the one I
created locally" — and the only way to answer correctly is to download
the remote artifact and hash it.

---

## ⚠️ CRITICAL: Creating a Release (READ THIS FIRST!)

**NEVER CREATE RELEASES MANUALLY! ALWAYS USE THE AUTOMATED SCRIPT!**

### The ONLY Correct Way to Create a Release

```bash
# 1. Bump version and commit
vim version.json  # Change version to 0.4.X
git add version.json storage/plugins/*/plugin.json
git commit -m "chore: bump version to 0.4.X"
git push origin main

# 2. Run the automated script
./scripts/create-release.sh 0.4.X
```

**That's it!** The script handles everything automatically.

### What the Script Does

The script (`scripts/create-release.sh`) performs these steps **automatically**:

1. ✅ Verifies you're on main branch with clean working directory
2. ✅ Checks version.json matches the release version
3. ✅ **Installs production dependencies** (`composer install --no-dev`)
4. ✅ **Removes PHPStan and dev packages** (prevents autoloader errors)
5. ✅ Creates ZIP with `git archive`
5.5. ✅ **Verifies ZIP contents** (critical files, bundled plugins, no scraping-pro, no PHPStan)
6. ✅ Generates SHA256 checksum
7. ✅ Creates GitHub release
8. ✅ Uploads ZIP and checksum files
9. ✅ Verifies release has all required assets
10. ✅ Restores dev dependencies for local development

### ⚠️ MANDATORY: Update README.md before every release

**Every release MUST include, in the SAME commit as the `version.json` bump:**

1. **Bump the version badge** at the top of `README.md`
   ```markdown
   [![Version](https://img.shields.io/badge/version-X.Y.Z-0ea5e9?style=for-the-badge)](version.json)
   ```
   The `X.Y.Z` in the badge URL MUST match `version.json` exactly. No
   trailing `-dev`, no `v` prefix, no whitespace.

2. **Add a new `## What's New in vX.Y.Z` section** directly below the
   badge block (keep older sections below it — newest first, never
   replace them). The section MUST describe:
   - New features with their tracking issue (`[#NNN](…/issues/NNN)`)
   - Bug fixes with their closing-issue reference
   - Any new migration file name and what it does
   - Any new bundled plugin (and whether it defaults to active/inactive)
   - Breaking changes, config migrations, or manual post-install steps

3. **Update the bundled plugin list** in `updater.md` §Plugin Compatibility
   if plugins were added, removed, or renamed. Keep `BundledPlugins::LIST`,
   `create-release.sh`, `create-release-local.sh`, and the table in this
   document synchronised.

**Why this is a hard rule:**

- The README version badge is the first thing users see on GitHub — a
  badge stuck at the previous version makes the project look abandoned
  and causes confusion about what the latest stable is.
- The "What's New" section is the **primary release-notes surface** for
  users who don't read GitHub Releases pages. Upgrade anxiety is real:
  someone on shared hosting needs to know *exactly* what changed before
  clicking "Aggiorna Ora" in the admin panel.
- GitHub Releases auto-notes (`--generate-notes`) are a commit log, not
  a release summary. The README section is hand-written and reader-first.
- A release with a stale badge/changelog will fail review and has to be
  re-cut — cheaper to do it right the first time.

**Pre-release README checklist** (run before `create-release.sh`):

```bash
# 1. Badge version matches version.json
grep -q "version-$(jq -r .version version.json)-" README.md || \
  { echo "❌ README badge out of sync"; exit 1; }

# 2. There is a "What's New in vX.Y.Z" section for this version
grep -q "## What's New in v$(jq -r .version version.json)" README.md || \
  { echo "❌ Missing 'What's New' section for this version"; exit 1; }
```

**Claude: follow this exactly. If you are asked to cut a release and the
README doesn't have the badge bump AND the What's New section, STOP and
add them — in the same commit as the version bump — before running
`create-release.sh`. Do not ask for confirmation; this is not optional.**

### Why Manual Creation Always Fails

| Problem | Manual | Automated Script |
|---------|--------|------------------|
| Forgot to upload ZIP | ✗ "File troppo piccolo" | ✓ Always uploads |
| **Vendor has dev deps** | ✗ **PHPStan breaks prod** | ✓ **Removes dev packages** |
| Wrong autoloader | ✗ References missing files | ✓ Production autoloader |
| Forgot checksum | ✗ No verification | ✓ Always generates |
| Wrong version.json | ✗ Easy to forget | ✓ Verifies first |

### The Critical Vendor/ Problem (THIS KEEPS HAPPENING!)

**ROOT CAUSE:** vendor/ is tracked by git. When you run `git archive`, it takes vendor/ from the REPOSITORY, NOT from your working directory!

**The Problem Chain:**
```
1. Developer runs: composer install (includes PHPStan for dev)
2. vendor/composer/autoload_real.php references PHPStan
3. Developer commits vendor/composer to git
4. Later: composer install --no-dev (removes PHPStan locally)
5. BUT: git archive uses OLD vendor/ from repo (still has PHPStan refs)
6. Users download ZIP, extract, get: "Failed opening phpstan/bootstrap.php"
```

**Why This Error Keeps Happening:**

| What You Think | What Actually Happens |
|----------------|----------------------|
| "I ran composer install --no-dev" | ✓ Your local vendor/ is clean |
| "I created the ZIP with git archive" | ✗ git archive uses REPO files, not local |
| "The ZIP should be clean" | ✗ ZIP has OLD vendor/ with PHPStan refs |

**The Solution:**

```bash
# After composer install --no-dev, you MUST commit vendor/composer:
composer install --no-dev --optimize-autoloader
git add vendor/composer/
git commit -m "chore: update vendor/composer with production autoloader"
git push
# NOW git archive will use the clean vendor/
git archive --format=zip --prefix=pinakes-v0.4.X/ -o pinakes-v0.4.X.zip HEAD
```

**The script now does this automatically** (Step 4.5) so it NEVER happens again.

**Testing a Release:**

```bash
# Download and test BEFORE announcing:
curl -L -o test.zip https://github.com/.../pinakes-v0.4.X.zip
unzip -q test.zip
grep -c "phpstan" pinakes-v0.4.X/vendor/composer/autoload_real.php
# Should output: 0 (no matches)
```

**If grep finds "phpstan":** The release is BROKEN. Delete it and recreate with the script.

### Manual Steps (Emergency Only)

**Only use if script fails:**

```bash
# 1. Install production deps (CRITICAL!)
composer install --no-dev --optimize-autoloader

# 2. Verify PHPStan removed
test ! -d vendor/phpstan && echo "✓ PHPStan removed"

# 3. COMMIT vendor/composer (CRITICAL! git archive uses repo, not local files)
git add vendor/composer/
git commit -m "chore: update vendor/composer with production autoloader (no PHPStan)"
git push origin main

# 4. Create ZIP
git archive --format=zip --prefix=pinakes-v0.4.X/ -o pinakes-v0.4.X.zip HEAD

# 5. TEST the ZIP (CRITICAL!)
unzip -q pinakes-v0.4.X.zip -d /tmp/test-release
grep "phpstan" /tmp/test-release/pinakes-v0.4.X/vendor/composer/autoload_real.php && \
  echo "❌ BROKEN! PHPStan found!" || echo "✓ Clean!"

# 6. Checksum
shasum -a 256 pinakes-v0.4.X.zip > pinakes-v0.4.X.zip.sha256

# 7. Create & upload
gh release create v0.4.X --title "Pinakes v0.4.X" --generate-notes --latest
gh release upload v0.4.X pinakes-v0.4.X.zip pinakes-v0.4.X.zip.sha256 --clobber

# 8. Restore dev deps
composer install
```

### What the Script Verifies (Step 5.5 — Automated ZIP Check)

After creating the ZIP, the script extracts it to a temp directory and verifies:

| Check | What it catches |
|-------|-----------------|
| `tinymce/models/dom/model.min.js` exists | TinyMCE "Failed to load model: dom" error |
| `tinymce/tinymce.min.js` exists | TinyMCE not loading at all |
| `tinymce/themes/silver/theme.min.js` exists | TinyMCE blank editor |
| `tinymce/skins/ui/oxide/skin.min.css` exists | TinyMCE unstyled editor |
| `tinymce/icons/default/icons.min.js` exists | TinyMCE missing toolbar icons |
| `public/index.php` exists | App doesn't boot |
| `app/Support/Updater.php` exists | Updater itself missing |
| `version.json` exists and matches | Wrong version deployed |
| `vendor/composer/autoload_real.php` has 0 phpstan refs | Fatal error on production |
| Bundled plugins (`plugin.json` per each) | Existing installations keep old plugin code |
| `scraping-pro` NOT in ZIP | Premium plugin leaked into free release |

If **any** check fails, the script deletes the ZIP and aborts before uploading.

### Files Excluded from Release ZIP

The `.gitattributes` file controls what `git archive` excludes via `export-ignore`:

| Excluded | Reason |
|----------|--------|
| `frontend/` | Webpack source (input.css, vendor.js). Compiled assets are in `public/assets/` |
| `docs/` | Developer documentation, not needed in production |
| `tests/`, `test/` | Test suites (Playwright, PHPUnit) |
| `.github/` | GitHub Actions, issue templates |
| `node_modules/` | npm packages (only needed for building frontend) |
| `.claude/`, `.gemini/`, `.qoder/`, `.cursor/`, `.vscode/`, `.idea/` | IDE/AI tool configs |
| `internal/` | Internal dev tools |
| `*.log` | Log files |
| `.DS_Store` | macOS metadata |

**Expected release ZIP size: ~25-35MB** (vendor ~15MB compressed, public/assets ~8MB, app ~2MB, rest ~2MB).

If a release ZIP exceeds 50MB, something is wrong — likely dev files or old releases leaked in.

**IMPORTANT: If creating a test package manually (rsync-based instead of `git archive`), you MUST manually exclude all the above.** `git archive` respects `.gitattributes` automatically, but rsync/cp do not.

### Lessons Learned (Don't Repeat These Mistakes!)

**v0.4.8 Release - The PHPStan Disaster:**

1. **Mistake:** Created release with `gh release create` but forgot to upload ZIP
   - **Result:** "File troppo piccolo" error for all users
   - **Lesson:** ALWAYS use the script, it uploads automatically

2. **Mistake:** Uploaded ZIP but forgot to run `composer install --no-dev` first
   - **Result:** Fatal error "phpstan/bootstrap.php not found" on all production sites
   - **Lesson:** Script now runs composer install --no-dev automatically

3. **Mistake:** Ran `composer install --no-dev` but didn't commit vendor/composer
   - **Result:** git archive used OLD vendor/ with PHPStan refs
   - **Lesson:** Script now commits vendor/composer automatically (Step 4.5)

**v0.4.8.3 Release - The TinyMCE model.min.js + Manual ZIP Disaster:**

4. **Mistake:** `git archive` produced a ZIP missing `models/dom/model.min.js`
   - **Result:** TinyMCE error "Failed to load model: dom from url models/dom/model.min.js"
   - **Lesson:** Script now verifies ZIP contents before uploading (Step 5.5)

5. **Mistake:** After discovering the missing model, manually recreated the ZIP with `git archive HEAD` from the **dev autoloader commit** instead of using the script
   - **Root cause:** The HEAD commit at that point was `ceb9aac` (restore vendor/composer with dev autoloader). The manually created ZIP contained `vendor/composer/autoload_static.php` with phpstan references, but no `vendor/phpstan/` directory → **fatal error 500 on all updated installations**
   - **Result:** Every installation that updated to v0.4.8.3 got a white screen (500 error). The release had to be deleted and recreated as v0.4.8.4.
   - **Lesson:** **NEVER manually recreate a ZIP with `git archive`.** ALWAYS delete the broken release and re-run `./scripts/create-release.sh`. The script switches to production autoloader, commits it, THEN runs `git archive`, THEN verifies the ZIP. Doing any of these steps by hand WILL break something.

**v0.4.9.9 Release - The Silent Migration Skip:**

6. **Mistake:** Release version was `0.4.9.9` but included `migrate_0.5.0.sql`
   - **Root cause:** `version_compare('0.5.0', '0.4.9.9', '<=')` returns `FALSE` in PHP — the updater silently skips any migration with a version higher than the target
   - **Result:** Social sharing settings and plugin_hooks unique index were never applied on upgrade. No error, no warning — just missing functionality
   - **Lesson:** **EVERY migration file version MUST be ≤ the release version.** If you have multiple migrations for one release, merge them into one file named after the release version. `create-release.sh` should verify this automatically (TODO).

**v0.5.4 Release (review follow-up) — The PluginManager Column-Count Disaster:**

7. **Mistake:** Commit `fc399cb` (CodeRabbit round 11) rewrote the INSERT VALUES
   list in `PluginManager::autoRegisterBundledPlugins()` and silently dropped
   one placeholder — 14 columns (`..., metadata, installed_at`) but only 12
   `?` + `NOW()` = 13 values. `bind_param` kept its correct 13-arg signature,
   so the mismatch lived entirely in the SQL string.
   - **Result:** `Fatal error: Uncaught mysqli_sql_exception: Column count
     doesn't match value count at row 1` at `PluginManager.php:132` on EVERY
     fresh install and on EVERY request after deploy (the exception bubbles
     through `loadActivePlugins()` into `public/index.php:345`, white-screen).
   - **Root cause of the gap:** PHPStan level 5 is static — it never executes
     the query, so the mismatch passes analysis. `full-test.spec.js` Phase 1
     (installer) is **skipped automatically when the app is already installed**
     (`appReady` cascade). On a dev machine that's been installed once, the
     `autoRegisterBundledPlugins` INSERT path is NEVER exercised by the suite.
   - **How it reached production:** the ZIP built from this HEAD passed every
     `create-release.sh` verification (critical files present, bundled plugins
     present, version matches, autoloader clean) because those checks are
     file-presence only, not runtime behaviour. The bug surfaced only when
     a user uploaded the ZIP to a fresh install on cPanel.
   - **Emergency remote hotfix:**
     ```bash
     ssh <host> "sed -i 's|VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())|VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())|' \
       /path/to/app/Support/PluginManager.php"
     ```
     A sed-level patch is safe here because the bind list, the column list,
     and the PHP variables already line up — only the placeholder count was
     off. Always take a `.bak-<tag>` backup before sed-in-place on production.
   - **Lesson:** **`create-release.sh` MUST include a runtime smoke step.**
     The ZIP verification catches missing files; it does NOT catch SQL errors,
     migration-order bugs, or any other runtime mismatch. Before publishing a
     release, run a scripted fresh-install end-to-end:
     1. Spin up an empty DB schema
     2. Remove `.env` and `.installed`
     3. Upload the ZIP to a scratch environment
     4. Hit `/installer/` and complete all 8 steps programmatically
     5. Request `/admin/plugins` — any fatal bubbles up here first
     6. Verify `plugins` table has all 6 bundled rows
     This catches exactly the class of bug that bit v0.5.4. Until the script
     does this automatically, **run it manually for every release**.
   - **Test gap to close:** `full-test.spec.js` should have a Phase 0 that
     forcibly uninstalls (remove `.env`+`.installed`, truncate `plugins` table)
     before Phase 1 runs, OR a separate `fresh-install.spec.js` that runs
     only when `E2E_FRESH_INSTALL=1` is set. Without this, the installer path
     regresses silently on every CodeRabbit round.

**v0.5.4 Release (hotfix round 2) — The bind_param Type-Order Swap:**

8. **Mistake:** `PluginManager::autoRegisterBundledPlugins()` bind_param type
   string was `'ssssssssissss'` — position 8 typed as `s` (should be `i` for
   `is_active`), position 9 typed as `i` (should be `s` for `path`). The SQL
   column order and the bound variable order were both correct, but the type
   string silently coerced every `path` string through `(int)` cast.
   - **How it manifested:** `(int)"discogs" === 0` and `(int)"goodlib" === 0`
     because neither string starts with digits. Both plugins were INSERTed
     with `path='0'`. The orphan-plugin detection in the SAME request then
     looked for `storage/plugins/0`, found nothing, and DELETEd the rows.
     Every fresh install ended with 5 bundled plugins instead of 7.
   - **Why 5 plugins survived (not all):** `installer/classes/Installer.php:1347`
     registers 5 plugins (open-library, z39-server, api-book-scraper,
     digital-library, dewey-editor) via **PDO** during the install flow, with
     a separate INSERT that names parameters (`:path`) so typing cannot go
     wrong. The two plugins that depend exclusively on
     `autoRegisterBundledPlugins` (discogs, goodlib) were the only victims.
   - **Why it hid from CI:** (a) the buggy code path runs only on fresh
     install + new bundled plugins, (b) `full-test.spec.js` Phase 1 installer
     is skipped when the dev machine is already installed, (c) PHPStan level
     5 does not check bind_param type strings against bound variable types
     (nothing in the language flags the swap), (d) the orphan detection wipes
     the bad rows before any subsequent test can observe them.
   - **How it was caught:** ran `smoke-install.spec.js` against a fresh DB in
     `test-upgrade/` on port 8082. Suite passed 13/13 but `SELECT * FROM
     plugins` showed 5 rows instead of 7 — and the app log contained
     `Orphan plugin detected: 'discogs' - folder missing at .../storage/plugins/0`
     with the tell-tale `/0` path.
   - **Emergency remote hotfix:**
     ```bash
     ssh <host> "sed -i \"s|'ssssssssissss'|'sssssssisssss'|\" \
       /path/to/app/Support/PluginManager.php"
     ```
   - **Lesson:** **`mysqli::bind_param` type mismatches are silent.** PHP does
     best-effort cast: `(int)"discogs" = 0`, `(float)"1.0.0" = 1.0`, etc. No
     warning, no exception. The only way to catch this is runtime observation.
     Write a **DB-level regression assertion**: after every fresh-install
     smoke, `SELECT name, path FROM plugins` and assert that every row has
     `path == name`. Any row with `path='0'` or `path != name` means a type
     swap somewhere in the chain.
   - **Structural takeaway:** prepared statements where the SQL and the bind
     list have matching position-order can still go out of sync if the type
     string is hand-written. Always review type strings char-by-char against
     the column definitions when adding/reordering columns — and prefer
     named-parameter PDO (which makes the class of bug impossible) for new
     code if the framework permits.

**v0.5.4 Release (hotfix round 3) — The `export-ignore` Installer Assets Kill:**

9. **Mistake:** `.gitattributes` line 5 said `public/installer/assets export-ignore`.
   `git archive` excluded `installer.js` + `style.css` from every ZIP built after
   that line was added.
   - **Result:** On fresh install, step 2 "Test Connessione" did literally
     nothing when clicked — the browser loaded `installer.js` URL, the PHP
     built-in server fell through to the Slim catch-all router, which served
     the HTML installer page instead of the JavaScript file. The admin saw a
     working-looking form but the AJAX test-connection function was never
     defined. No error visible unless the browser console was open.
   - **Why it hid:** v0.5.3 was built before the export-ignore was added.
     Local dev always has the file in the working directory (PHP dev server
     serves it from disk, not from `git archive` output). The
     `create-release.sh` Step 5.5 verified critical files but
     `public/installer/assets/installer.js` was NOT in the checklist.
   - **Fix:** removed the `export-ignore` line from `.gitattributes` and
     added `public/installer/assets/installer.js` to the critical-files
     checklist in `create-release.sh`.
   - **Lesson:** **NEVER `export-ignore` directories inside `public/`** —
     they are user-facing at runtime. The export-ignore mechanism is for
     dev-only directories (`tests/`, `docs/`, `frontend/`, `.github/`). A
     quick grep for `public/` in `.gitattributes` should be part of the
     pre-release checklist.

**v0.5.4 Release (hotfix round 4) — The `public/installer/assets` Symlink Kill:**

10. **Mistake:** Commit `a2b995b` (2026-01-21) added `public/installer/assets`
    as a **symlink** to `../../installer/assets` instead of duplicating the two
    asset files. Every fresh install worked (ZipArchive extracts the symlink
    as a regular 22-byte file containing the target path — browsers fetching
    `/installer/assets/installer.js` hit 404 but the installer was already
    done). Every **manual upgrade** on an install that had the directory
    materialized crashed at `Updater.php:2440`:
    ```
    Warning: copy(): The second argument to copy() function cannot be a directory
    {"success":false,"error":"Errore nella copia del file: public/installer/assets"}
    ```
    - **Root cause chain:** `git archive` serialises symlinks as regular files
      whose content is the target path. PHP `ZipArchive::extractTo()` does NOT
      recreate symlinks — it writes the target path as a plain file. On the
      first install nobody notices because the file just sits there. But on
      servers where something later materialised `public/installer/assets/` as
      a real directory (shared-hosting quirks, manual unpacks, migration from
      an earlier ZIP that shipped it as a directory), the next update iterates
      the new ZIP, finds the 22-byte file, and tries
      `copy($tiny_file, $existing_dir)` — PHP refuses and `Updater::installUpdate()`
      aborts with rollback.
    - **Why the local reinstall test didn't catch it:** `scripts/reinstall-test.sh`
      overlays files via `rsync -a`, not via `Updater::copyDirectory()`. rsync
      handles the scenario by emitting `rsync: public/installer/assets:
      unlinkat: Directory not empty` and continues. This warning was initially
      dismissed as a macOS `.DS_Store` quirk (Learning L12 in
      `reinstall_test.md`) — it was in fact the exact same bug rsync was
      tolerating. **Meta-lesson: never dismiss a warning from an alternative
      tool without reasoning about what the production tool would do with
      the same input.**
    - **Emergency remote hotfix:** not applicable here — the bug is in the
      ZIP contents. The fix is to rebuild the ZIP from a commit that replaces
      the symlink with a real directory. Emergency workaround if stuck on an
      old ZIP: SSH in, `rm -rf public/installer/assets` before triggering the
      update (makes Updater create the tiny file instead of trying to copy
      over a dir — installer UI breaks but app update completes; then restore
      `public/installer/assets/` by copying `installer/assets/*` into it).
    - **Permanent fix (commit `7f6c6a1`):** replace the symlink with a real
      `public/installer/assets/` directory containing duplicated
      `installer.js` and `style.css`. 16 KB of duplication is trivial and
      sidesteps every ZipArchive-symlink surprise.
    - **Guard rail added to `create-release.sh` + `create-release-local.sh`:**
      the ZIP-verification step now scans for files ≤ 256 bytes whose entire
      content is a path (`^\.{0,2}(/[a-zA-Z0-9_.-]+)+$` with no newlines) and
      aborts the release if any are found, with a clear message pointing at
      the symlink that needs to be replaced.
    - **Test gap to close:** the local reinstall test should exercise the
      actual PHP `Updater::copyDirectory()` path (either through
      `manual-upgrade.php` headless, or via a one-shot
      `php -r 'require "..."; (new Updater)->performUpdate(...)'`) instead of
      relying on rsync. Tracked in `reinstall_test.md` TODO.
    - **Rule of thumb:** **no symlinks in a git repo that will be shipped via
      `git archive`.** Either use a real directory, or have your release
      pipeline materialise the symlink at archive-time (add a "symlink-expand"
      step before `git archive`). Symlinks + ZipArchive + Updater.copyDirectory
      is a three-way incompatibility that will always eventually bite.

**Why These Mistakes Keep Happening:**
- Manual processes have many failure points
- `git archive` behavior is non-obvious (uses repo, not local files)
- `git archive` can silently drop files without any error
- `version_compare()` behavior is non-obvious with multi-segment versions
- Static analysis (PHPStan) does not execute SQL — column/value mismatches pass
- Static analysis does not check mysqli bind_param type strings — silent cast
- `.gitattributes` export-ignore silently drops runtime-critical assets from ZIPs
- E2E suites that skip the installer phase never exercise bundled-plugin registration
- Orphan-plugin detection self-hides type-swap bugs by wiping the bad rows
- Easy to forget steps under time pressure
- Each mistake breaks production for ALL users

**The Solution:**
- **NEVER create releases manually**
- **ALWAYS use `./scripts/create-release.sh`**
- **The script now self-verifies** — it extracts the ZIP and checks critical files before uploading

**If You're Reading This Because a Release Failed:**
1. Delete the broken release: `gh release delete v0.4.X --yes`
2. Run: `./scripts/create-release.sh 0.4.X`
3. The script verifies automatically. If it passes, the release is good.
4. If the script itself fails at Step 5.5, investigate which files are missing and why `git archive` excluded them.

---

## Overview

The updater system allows administrators to update Pinakes directly from the admin panel (Admin > Updates). It downloads the latest release from GitHub, extracts it, runs database migrations, and replaces application files.

---

## How It Works

### Update Flow

1. **Check for updates** - Queries GitHub API for latest release
2. **Pre-flight checks** - Verifies permissions, disk space, PHP extensions
3. **Enable maintenance mode** - Creates `storage/.maintenance` file
4. **Download release** - Downloads ZIP from GitHub (cURL preferred, fallback to file_get_contents)
5. **Extract to temp** - Extracts to `storage/tmp/pinakes_update_*`
6. **Backup critical files** - Backs up `.env`, `config.local.php`, `version.json`
7. **Copy new files** - Overwrites application files (skips user data)
8. **Run migrations** - Executes SQL files from `installer/database/migrations/`
9. **Cleanup** - Removes temp files
10. **Disable maintenance mode** - Removes `.maintenance` file

### Key Files

| File | Purpose |
|------|---------|
| `app/Support/Updater.php` | Core updater logic |
| `app/Controllers/UpdateController.php` | Admin UI endpoints |
| `app/Views/admin/updates.php` | Admin UI view |
| `storage/.maintenance` | Maintenance mode flag |
| `storage/tmp/` | Temporary extraction directory |
| `storage/backups/` | Pre-update backups |
| `storage/logs/app.log` | Update logs |

---

## Directory Structure & Permissions

### Required Directories

```
storage/
├── tmp/           # Temporary files during update (775)
├── backups/       # Pre-update backups (775)
├── logs/          # Application logs (775)
├── cache/         # Cache files (775)
└── plugins/       # Plugin storage (775)
```

### Permission Requirements

| Directory | Permission | Owner |
|-----------|------------|-------|
| `storage/` | 775 | www-data (or web server user) |
| `storage/tmp/` | 775 | www-data |
| `storage/backups/` | 775 | www-data |
| `storage/logs/` | 775 | www-data |
| `app/` | 775 | www-data |
| `public/` | 775 | www-data |
| `config/` | 775 | www-data |

### Setting Permissions

```bash
# Linux/cPanel
chmod -R 775 storage app public config
chown -R www-data:www-data storage app public config

# Or if using cPanel/shared hosting
chmod -R 775 storage app public config
```

---

## PHP Requirements

### Required Extensions

| Extension | Purpose |
|-----------|---------|
| `ZipArchive` | Extract downloaded ZIP files |
| `curl` (recommended) | Download files reliably |
| `openssl` | HTTPS connections |

### PHP Settings

```ini
; Recommended php.ini settings for updates
memory_limit = 512M          ; Large ZIP extraction
max_execution_time = 600     ; 10 minutes timeout
allow_url_fopen = On         ; Fallback download method
```

---

## Critical: Temp Directory

### The Problem (v0.4.1 - v0.4.3)

Old versions used `sys_get_temp_dir()` which returns `/tmp` on most systems. On shared hosting, this directory often has:
- Cross-user restrictions
- Automatic cleanup (cron jobs)
- Permission issues
- Open_basedir restrictions

### The Solution (v0.4.4+)

The updater now ALWAYS uses `storage/tmp/` which:
- Is within the application directory
- Has correct permissions
- Is not affected by hosting restrictions
- Is automatically cleaned up by the updater

### Temporary Directory Structure

```
storage/tmp/
├── pinakes_update_abc123/     # Extraction directory (auto-deleted)
│   ├── update.zip             # Downloaded release
│   └── pinakes-vX.X.X/        # Extracted content
└── pinakes_app_backup_*/      # App backup (auto-deleted after 1 hour)
```

---

## Download Mechanism

### Primary: cURL

```php
$ch = curl_init($downloadUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_USERAGENT => 'Pinakes-Updater/1.0',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_BUFFERSIZE => 1024 * 1024,  // 1MB buffer
]);
```

### Fallback: file_get_contents

If cURL fails or is not available:

```php
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => ['User-Agent: Pinakes-Updater/1.0'],
        'timeout' => 300,
        'follow_location' => true
    ]
]);
$content = file_get_contents($downloadUrl, false, $context);
```

---

## ZIP Extraction

### Retry Mechanism

The updater attempts extraction up to 3 times:

```php
$maxRetries = 3;
for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) === true) {
        if ($zip->extractTo($extractPath)) {
            // Success
            break;
        }
    }
    // Increase memory and retry
    $currentLimit = ini_get('memory_limit');
    ini_set('memory_limit', (int)$currentLimit * 2 . 'M');
    sleep(2);
}
```

### Memory Auto-Increase

On extraction failure, the updater automatically doubles the memory limit and retries.

---

## Database Migrations

### Migration Files

Located in `installer/database/migrations/`:

```
migrate_0.4.0.sql    # Base schema setup
migrate_0.4.2.sql    # Calendar and holidays
migrate_0.4.3.sql    # Prestiti enhancements
migrate_0.4.4.sql    # System settings and email templates
migrate_0.4.5.sql    # Pickup confirmation workflow
migrate_0.4.6.sql    # LibraryThing missing fields (dewey_wording, barcode, entry_date)
migrate_0.4.7.sql    # LibraryThing comprehensive migration (25+ fields, indexes, constraints)
migrate_0.4.8.1.sql  # Import logs tracking system (import_logs table + composite index)
migrate_0.4.8.2.sql  # Illustratore field, lingua expansion, language normalization, anno_pubblicazione signed
migrate_0.4.9.9.sql  # descrizione_plain column, social sharing settings, plugin_hooks unique index
migrate_0.5.0.sql    # curatore column, llms_txt_enabled setting, issn column, RSS feed settings
migrate_0.5.1.sql    # volumi table, collane table, idx_collana index
```

See `installer/database/migrations/README.md` for detailed migration documentation.

### Execution Order

Migrations are executed in version order, only for versions newer than the current version **and less than or equal to the target version**.

The updater logic is:

```php
if (version_compare($migrationVersion, $fromVersion, '>') &&
    version_compare($migrationVersion, $toVersion, '<=')) {
    // Execute this migration
}
```

### ⚠️ CRITICAL: Migration Version Must Be ≤ Release Version

**NEVER name a migration file with a version HIGHER than the release version!**

PHP `version_compare()` determines whether a migration runs. If the migration version exceeds the release version, it will be **silently skipped** — no error, no warning, just missing data.

```
Release version: 0.4.9.9
migrate_0.5.0.sql  → version_compare('0.5.0', '0.4.9.9', '<=') = FALSE → SKIPPED!
migrate_0.4.9.9.sql → version_compare('0.4.9.9', '0.4.9.9', '<=') = TRUE → EXECUTED ✓
```

**Why this is non-obvious:** `0.5.0` looks "close" to `0.4.9.9`, but PHP compares segment-by-segment: `0=0`, then `5>4` → done. The extra `.9.9` segments don't matter.

**Rule:** Before creating a release, verify that ALL migration files have a version ≤ the release version in `version.json`. If you need multiple migrations for the same release, merge them into one file or use the release version as the filename.

**Checklist item for `create-release.sh`:** The script should verify that no `migrate_*.sql` file has a version greater than the release version. (TODO: automate this check.)

### SQL Parsers Overview

Pinakes uses **3 different SQL parsers** depending on the context. Each has different capabilities:

| Parser | Used by | File(s) | Quote escaping | Semicolon handling |
|--------|---------|---------|---------------|-----------------|
| `splitSqlStatements()` | Updater (migrations) | `migrate_*.sql` | `''` only | Tracks string state |
| Line-based | Installer (schema) | `schema.sql` | N/A | Line-ending `;` only |
| Split on `;\n` | Installer (data) | `data_*.sql` | Both `''` and `\'` via PDO | Single-line convention |

**Primary method:** Both installer and updater try MySQL CLI first, which handles everything natively. The PDO fallbacks above are used on hosting where CLI is disabled.

### Migration SQL Format Requirements (migrate_*.sql)

**v0.4.5+:** The updater uses `splitSqlStatements()` which correctly handles semicolons inside quoted strings (e.g., CSS inline styles).

1. **Escape single quotes** as `''` (two single quotes) — **MANDATORY for migrations**:
   ```sql
   'dall''amministratore'   -- Correct (handled by splitSqlStatements)
   'dall\'amministratore'   -- WRONG: splitSqlStatements does NOT handle \'
   'dall'amministratore'    -- WRONG: syntax error
   ```

   > **Warning:** `\'` (backslash escape) is NOT supported by `splitSqlStatements()`. The parser only recognizes `''` as escaped quotes. Using `\'` in migrations will cause incorrect statement splitting.

2. **Semicolons inside string values are supported** (v0.4.5+):
   ```sql
   -- WORKS: CSS inline styles with semicolons in migrations
   INSERT INTO `email_templates` VALUES ('test', '<div style="padding: 20px; margin: 10px;">content</div>');
   ```

3. **Comment lines** starting with `--` are filtered out before execution

4. **PREPARE/EXECUTE with nested quotes** — for idempotent migrations (v0.4.7+):
   ```sql
   -- The inner string uses '' for escaped quotes, parser handles correctly
   SET @sql = IF(@exists = 0, 'CREATE TABLE t (
       col ENUM(''a'', ''b'')
   )', 'SELECT 1');
   ```

5. **NEVER use backslash escapes (`\\`) inside PREPARE strings** — `splitSqlStatements()` only tracks `''` pairs for quote state. A `\\` inside a quoted string (e.g. a REGEXP pattern like `''^[89]\\.'') confuses the parser's state machine, causing it to split mid-statement. This was discovered during v0.4.9.9 upgrade testing.

   ```sql
   -- WRONG: backslash in PREPARE string breaks splitSqlStatements()
   SET @sql = IF(@check, 'SELECT @ver REGEXP ''^[89]\\.''', 'SELECT 1');

   -- CORRECT: move complex logic to PHP (BookRepository, Updater, etc.)
   -- Keep migrations simple: DDL only, let application code handle data transforms
   ```

   > **Rule of thumb:** If your migration needs REGEXP, REPLACE(), stored procedures, or any complex string manipulation — do it in PHP instead. Migrations should only handle schema changes (ADD COLUMN, CREATE TABLE, ALTER TABLE). Data backfill/transforms belong in application code.

6. **Avoid COMMENT clauses with escaped quotes in PREPARE strings** — deeply nested quoting like `COMMENT ''description with ''''quotes''''` is fragile across parsers. Omit COMMENT in migrations; document columns in the migration file's SQL comments instead.

### Data File Format Requirements (data_*.sql)

The installer's data import uses split on `;\n` (semicolon + newline) as PDO fallback.

1. **Every INSERT must be on a single line** — this is critical:
   ```sql
   -- CORRECT: entire INSERT on one line (even if very long)
   INSERT INTO `table` VALUES (1,'key','<div style="padding: 20px; margin: 10px;">HTML</div>');

   -- WRONG: multi-line INSERT with CSS semicolons will break the parser
   INSERT INTO `table` VALUES (1,'key',
   '<div style="padding: 20px; margin: 10px;">HTML</div>');
   ```

2. **Both `''` and `\'` work** for escaping quotes (PDO handles both):
   ```sql
   'dell''arte'       -- Works (SQL standard)
   'dell\'arte'       -- Works (MySQL-specific)
   ```

3. **HTML templates** may contain CSS inline styles with `;` — safe as long as rule #1 is followed (single-line INSERTs)

### Schema File Format (schema.sql)

The installer's schema import splits when a line ends with `;`.

1. **CREATE TABLE statements** must end with `);` at end of line
2. **No semicolons inside COMMENT or ENUM values** (would break the line-based parser)

### Idempotent Migrations

#### Legacy Approach (v0.4.0 - v0.4.6)

The updater ignores these MySQL errors to allow re-running migrations:

| Error Code | Description |
|------------|-------------|
| 1060 | Duplicate column name |
| 1061 | Duplicate key name |
| 1050 | Table already exists |
| 1091 | Can't DROP (doesn't exist) |
| 1068 | Multiple primary key |
| 1022 | Duplicate key entry |
| 1826 | Duplicate FK constraint |
| 1146 | Table doesn't exist |

This allows migrations to be run multiple times without failing.

#### Modern Approach (v0.4.7+)

New migrations use **prepared statements with INFORMATION_SCHEMA checks** for true idempotency:

```sql
-- Check if column exists before adding
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'libri'
                   AND COLUMN_NAME = 'review');
SET @sql = IF(@col_exists = 0,
              'ALTER TABLE `libri` ADD COLUMN `review` TEXT NULL',
              'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

**Advantages:**
- No MySQL errors generated (cleaner logs)
- More explicit and predictable behavior
- Works identically on all MySQL/MariaDB versions
- Can be run unlimited times without side effects

See `installer/database/migrations/README.md` for comprehensive examples and best practices.

---

## Pre-flight Checks

Before starting an update, the system verifies:

1. **Directory permissions** - `storage/tmp`, `storage/backups` writable
2. **Disk space** - Minimum 200MB free
3. **PHP extensions** - ZipArchive available
4. **Download capability** - cURL or allow_url_fopen enabled

```php
// Disk space check
$freeSpace = disk_free_space($rootPath);
if ($freeSpace < 200 * 1024 * 1024) {
    throw new RuntimeException('Insufficient disk space');
}
```

---

## Maintenance Mode

### Enabling

```php
file_put_contents('storage/.maintenance', json_encode([
    'time' => time(),
    'retry' => 300,
    'message' => 'System update in progress'
]));
```

### Auto-Expiry

Maintenance mode automatically expires after 30 minutes if not removed (failsafe for crashed updates).

### Checking

```php
if (file_exists('storage/.maintenance')) {
    $data = json_decode(file_get_contents('storage/.maintenance'), true);
    if (time() - $data['time'] > 1800) {
        // Auto-remove expired maintenance
        unlink('storage/.maintenance');
    }
}
```

---

## Skipped Files During Update

These files/directories are preserved during updates:

```php
$preservePaths = [
    '.env',
    'storage/uploads',
    'storage/plugins',   // ← preserved by main copy, but bundled plugins updated separately
    'storage/backups',
    'storage/cache',
    'storage/logs',
    'storage/calendar',
    'storage/tmp',
    'public/uploads',
    'public/.htaccess',
    'public/robots.txt',
    'public/favicon.ico',
    'public/sitemap.xml',
    'CLAUDE.md',
];
```

### Bundled Plugin Updates

Although `storage/plugins` is in `preservePaths` (to protect user-installed plugins and their data),
**bundled plugins are updated separately** after the main file copy. The updater explicitly names
which plugins to update:

```php
// app/Support/BundledPlugins.php — single source of truth
public const LIST = [
    'api-book-scraper',
    'deezer',
    'dewey-editor',
    'digital-library',
    'discogs',
    'goodlib',
    'musicbrainz',
    'open-library',
    'z39-server',
];
```

**Key points:**
- Only plugins in `BundledPlugins::LIST` are overwritten during updates
- `scraping-pro` is a **premium add-on** — it is NEVER overwritten by updates, NEVER shipped in the GitHub release ZIP (distributed separately as `scraping-pro-vX.Y.Z.zip`), and is gitignored
- User-installed third-party plugins are left untouched
- The list in `app/Support/BundledPlugins.php` is the **single source of truth**. `Updater.php`, `PluginManager.php`, `create-release.sh` and `create-release-local.sh` all reference it (or duplicate it verbatim). When adding/removing a bundled plugin, update **all four** places.

---

## Logging

### Log Location

`storage/logs/app.log`

### Log Format

```json
{"timestamp":"2026-01-12 12:00:00","level":"INFO","message":"[Updater] Download started","context":{"url":"https://...","method":"curl"}}
```

### View Logs

Admin panel: `/admin/updates/logs`

Or via command line:
```bash
tail -100 storage/logs/app.log | grep Updater
```

---

## Troubleshooting

### Update Fails During Download

**Symptoms:** "Download failed" error

**Solutions:**
1. Check if cURL extension is enabled
2. Verify `allow_url_fopen = On` in php.ini
3. Check if GitHub is accessible from server
4. Verify SSL certificates are valid

### Update Fails During Extraction

**Symptoms:** "Extraction failed" or ZIP errors

**Solutions:**
1. Verify ZipArchive extension is installed
2. Check available disk space (need 200MB+)
3. Check memory_limit (need 256MB+)
4. Verify `storage/tmp` is writable

### Permission Denied Errors

**Solutions:**
```bash
chmod -R 775 storage app public config
chown -R www-data:www-data storage  # Linux
```

### Update Stuck in Maintenance Mode

**Solutions:**
1. Delete `storage/.maintenance` manually
2. Check `storage/logs/app.log` for errors

---

## Manual Update (Emergency)

For users on v0.4.1-0.4.3 with broken updater:

### Option 1: FTP Upload

1. Download latest release from GitHub
2. Extract `test-updater/app/` folder
3. Upload via FTP, overwriting existing files
4. Retry update from admin panel

### Option 2: Emergency Script

1. Upload `test-updater/manual-update.php` to site root
2. Access via browser: `https://yoursite.com/manual-update.php`
3. Delete the script after update completes

### Option 3: `manual-upgrade.php` (v0.4.9.8+)

Standalone single-file upgrade script (`scripts/manual-upgrade.php`) for users who cannot use the admin auto-updater (e.g., restricted hosting, no outbound HTTP).

**Usage:**
1. Copy `scripts/manual-upgrade.php` to `public/` directory
2. Access via browser: `https://yoursite.com/manual-upgrade.php`
3. Enter the upgrade password (set via `UPGRADE_PASSWORD` constant in the script)
4. Upload the release ZIP file
5. Delete the script after upgrade completes

**How it works:**
- Password-protected with CSRF token validation
- Creates a mysqldump backup before applying changes
- Extracts ZIP and copies files, respecting `preservePaths` (same list as Updater.php)
- Runs pending database migrations via `splitSqlStatements()`

**⚠️ IMPORTANT: Bundled plugins are NOT updated by `manual-upgrade.php`**

Unlike `Updater.php` which has `updateBundledPlugins()`, `manual-upgrade.php` preserves the entire `storage/plugins/` directory without updating bundled plugins. This means:
- New bundled plugin features won't be available after a manual upgrade
- New hooks registered by updated plugins won't activate
- To update bundled plugins manually, extract `storage/plugins/<plugin-name>/` from the release ZIP and copy over the existing plugin directory

This is a known gap between the two upgrade paths. The auto-updater (`Updater.php`) always updates bundled plugins; the manual script does not.

**PHP built-in server caveats:**
- The script must be in `public/` — the built-in server routes everything through Slim's router otherwise
- PHP CLI doesn't read `.user.ini`, so for large ZIPs use: `php -d upload_max_filesize=512M -d post_max_size=512M -S localhost:8082 -t public`
- `mysqldump` may fail with exit code 2 if the DB user lacks FLUSH privilege — the backup still works but with a warning

---

## Security Considerations

1. **Maintenance mode** - Prevents access during update
2. **Backup creation** - Critical files backed up before overwrite
3. **Checksum verification** - SHA256 checksums provided for releases
4. **HTTPS only** - All downloads over HTTPS
5. **Admin-only access** - Update functions require admin authentication

---

## Plugin Compatibility

### Updating Plugin Compatibility for New Releases

When releasing a new version of Pinakes, **always update the plugin compatibility** in all bundled plugins.

#### Plugin Compatibility Fields

Each plugin has a `plugin.json` file with these version fields:

```json
{
  "requires_app": "0.4.0",      // Minimum Pinakes version required
  "max_app_version": "1.0.0"   // Maximum compatible Pinakes version
}
```

#### Checklist for New Releases

1. **Update all plugin.json files** in `storage/plugins/*/plugin.json`:
   - Set `max_app_version` to the new release version

2. **Recreate plugin ZIP files**:
   ```bash
   cd /path/to/pinakes

   # Recreate all plugin ZIPs
   for plugin in api-book-scraper dewey-editor digital-library open-library scraping-pro z39-server; do
     version=$(jq -r '.version' "storage/plugins/$plugin/plugin.json")
     rm -f "${plugin}-v${version}.zip"
     cd storage/plugins && zip -r "../../${plugin}-v${version}.zip" "$plugin/" && cd ../..
   done

   # Also update installer plugin
   rm -f installer/plugins/dewey-editor.zip
   cd storage/plugins && zip -r ../../installer/plugins/dewey-editor.zip dewey-editor/ && cd ../..
   ```

3. **Current bundled plugins (shipped in release ZIP)**:
   | Plugin | Version | Notes |
   |--------|---------|-------|
   | api-book-scraper | 1.1.1 | Custom API scraper |
   | deezer | 1.0.0 | Cover art + tracklist fallback for music media |
   | dewey-editor | 1.0.1 | Dewey classification tree editor |
   | digital-library | 1.3.0 | ePub/PDF viewer + metadata |
   | discogs | 1.1.0 | Music metadata scraper (CD / vinyl / cassette) |
   | goodlib | 1.0.0 | Custom-domain book scraper |
   | musicbrainz | 1.0.0 | MusicBrainz + Cover Art Archive chain |
   | open-library | 1.0.1 | Open Library ISBN scraper |
   | z39-server | 1.2.3 | Z39.50 / SRU Nordic sources |

   **Premium — NOT in release ZIP, distributed separately:**
   | Plugin | Version | File |
   |--------|---------|------|
   | scraping-pro | 1.4.2 | `scraping-pro-v1.4.2.zip` (+ `.sha256`) |

#### Compatibility Check System

The update system checks plugin compatibility before updates:

- **Compatible**: `requires_app <= target_version <= max_app_version`
- **Incompatible**: Plugin warns user before update
- **Unknown**: Plugin without `max_app_version` shows warning

Users see compatibility warnings in the admin update panel before proceeding.

---

## Pre-Update Patch System

The updater includes a **pre-update patch system** that allows fixing Updater bugs remotely without requiring users to manually update files.

### How It Works

Before starting the main update process, the updater checks for a `pre-update-patch.php` file in the GitHub release:

1. **Download patch file** from `https://github.com/.../releases/download/vX.X.X/pre-update-patch.php`
2. **Verify checksum** using `pre-update-patch.php.sha256`
3. **Check target versions** - only apply if current version is in the patch's target list
4. **Apply patches** - string replacements in specified files
5. **Continue with update** - the patched Updater then runs normally

### Patch File Format

```php
<?php
// pre-update-patch.php
return [
    'version' => '1.0.0',
    'target_versions' => ['0.4.3', '0.4.4', '0.4.5'],  // Only apply to these versions
    'patches' => [
        [
            'file' => 'app/Support/Updater.php',
            'search' => "explode(';', \$sql)",
            'replace' => "\$this->splitSqlStatements(\$sql)",
            'description' => 'Fix SQL parser for CSS semicolons'
        ],
        // Add more patches as needed
    ]
];
```

### Creating a Patch for a Release

1. **Create `pre-update-patch.php`** with the patch definition
2. **Generate checksum**:
   ```bash
   shasum -a 256 pre-update-patch.php > pre-update-patch.php.sha256
   ```
3. **Upload both files** to the GitHub release as assets

### Graceful Fallback

If no patch file exists (404), the updater continues normally. This means:
- **Patch files are optional** - only add them when needed
- **No patch = normal update** - the system works identically to before
- **Failed patches don't block updates** - errors are logged but update continues

### Security

- **Checksum verification** - SHA256 hash must match
- **Path validation** - patches can only modify files within the application root
- **No arbitrary code** - patches use string replacement, not eval()
- **Target version filtering** - patches only apply to specific versions

### Logging

All pre-update patch operations are logged:
```
[Updater DEBUG] [INFO] === PRE-UPDATE PATCH CHECK ===
[Updater DEBUG] [INFO] Nessun pre-update-patch disponibile (OK, normale)
```

Or when a patch is applied:
```
[Updater DEBUG] [INFO] Pre-update-patch scaricato
[Updater DEBUG] [INFO] Checksum verificato
[Updater DEBUG] [INFO] Patch applicata {"file":"app/Support/Updater.php","description":"Fix SQL parser"}
```

---

## Post-Install Patch System

The updater also includes a **post-install patch system** that runs after the update is successfully installed. This is useful for:

- Applying hotfixes to the newly installed code
- Cleaning up obsolete files from previous versions
- Running database queries for data migrations

### How It Works

After the update is installed and migrations run, the updater checks for a `post-install-patch.php` file:

1. **Download patch file** from `https://github.com/.../releases/download/vX.X.X/post-install-patch.php`
2. **Verify checksum** using `post-install-patch.php.sha256`
3. **Check target versions** - only apply if *source* version (before update) is in the patch's target list
4. **Apply patches** - file patches, cleanup, and SQL queries
5. **Complete update** - finalize the update process

### Patch File Format

```php
<?php
// post-install-patch.php
return [
    'version' => '1.0.0',
    'target_versions' => ['0.4.3', '0.4.4', '0.4.5'],  // Source versions to patch
    'patches' => [
        [
            'file' => 'app/Controllers/MyController.php',
            'search' => 'old_function()',
            'replace' => 'new_function()',
            'description' => 'Rename deprecated function'
        ]
    ],
    'cleanup' => [
        'storage/cache/old_file.php',       // Delete obsolete files
        'app/Support/DeprecatedClass.php',
    ],
    'sql' => [
        "UPDATE impostazioni SET valore = 'new' WHERE chiave = 'old_key'",
        "DELETE FROM cache_table WHERE created_at < NOW() - INTERVAL 30 DAY"
    ]
];
```

### Supported Operations

| Operation | Description | Safety Checks |
|-----------|-------------|---------------|
| `patches` | String replacements in files | Path validation, file must exist |
| `cleanup` | Delete obsolete files | Protected files list, path validation |
| `sql` | Execute SQL queries | Dangerous pattern blocking |

### Protected Files

These files cannot be deleted via cleanup:

- `.env`
- `version.json`
- `public/index.php`
- `composer.json`

### Blocked SQL Patterns

These SQL patterns are blocked for safety:

- `DROP DATABASE`
- `DROP TABLE` (without IF EXISTS)
- `TRUNCATE TABLE` on core tables
- `DELETE FROM` without WHERE clause

### Creating a Post-Install Patch

1. **Create `post-install-patch.php`** with the patch definition
2. **Generate checksum**:
   ```bash
   shasum -a 256 post-install-patch.php > post-install-patch.php.sha256
   ```
3. **Upload both files** to the GitHub release as assets

### When to Use Each Patch Type

| Scenario | Use |
|----------|-----|
| Fix bug in Updater itself | Pre-update patch |
| Fix bug in newly installed code | Post-install patch |
| Delete obsolete files from old version | Post-install cleanup |
| Migrate data after schema change | Post-install SQL |
| Add missing config before update | Pre-update patch |

### Graceful Fallback

Same as pre-update patches:
- **Patch files are optional** - only add them when needed
- **No patch = normal update** - 404 is handled gracefully
- **Failed patches don't block updates** - errors are logged but update completes

### Logging

```
[Updater DEBUG] [INFO] >>> STEP 4: Post-install patch check <<<
[Updater DEBUG] [INFO] Nessun post-install-patch disponibile (OK, normale)
```

Or when a patch is applied:
```
[Updater DEBUG] [INFO] Post-install-patch scaricato
[Updater DEBUG] [INFO] Checksum verificato
[Updater DEBUG] [INFO] Post-install patch applicato {"patches":1,"cleanup":2,"sql":1}
```

---

## Version History

| Version | Changes |
|---------|---------|
| 0.5.5 | No new migrations (code-only upgrade from 0.5.4). Features: **Bulk ISBN Enrichment** (`/admin/libri/bulk-enrich`) — manual batch (20 books/click, rate-limited 1 req/2min) and cron-driven background enrichment (`scripts/bulk-enrich-cron.php` with atomic `flock(LOCK_EX\|LOCK_NB)` + non-zero exit on fatal). New bundled plugins `deezer` and `musicbrainz` (both added to `BundledPlugins::LIST` + `create-release(-local).sh`). 16 CodeRabbit Major fixes (commit `77fba6b`): `BulkEnrichController::start` logs via `SecureLogger` + generic 500 instead of leaking raw exception, `BulkEnrichController::toggle` uses `FILTER_VALIDATE_BOOL`, `BulkEnrichmentService::setEnabled` returns bool, `enrichBook` checks `UPDATE` execute() result, `ScrapeController::normalizeIsbnFields` distinguishes validated ISBN (via `IsbnFormatter::isValid`) from barcode-only so book lookups don't skip backfill, `NULLIF(TRIM(col), '')` on isbn13/isbn10/ean to ignore empty strings, back-pressure rate-limit on bulk-enrich/start, accessible `aria-label`+`aria-labelledby` on toggle switch. **CRITICAL FIX** (commit `7f6c6a1`): `public/installer/assets` symlink → real directory. `git archive` serialises symlinks as 22-byte files containing the target path; PHP `ZipArchive` extracts as regular file; `Updater::copyDirectory()` then crashes with `copy(): second argument cannot be a directory` on every manual upgrade where the dir had been materialized (bit `bibliodoc.fabiodalez.it` in production). Permanent fix: duplicate `installer/assets/{installer.js,style.css}` into `public/installer/assets/` (16 KB). **Release-script guard:** `create-release(-local).sh` verification step now scans ZIP metadata via `zipinfo` and aborts if any symlink entry would ship — prevents regressions of this class. **Reinstall regression test** (`scripts/reinstall-test.sh` + `tests/manual-upgrade-real.spec.js`): exercises the full admin-UI upgrade flow (Uppy upload → click "Avvia" → `Updater::performUpdateFromFile`) with no rsync shortcuts. Runs full Playwright suite both on fresh install and on upgraded install. i18n: 168 new EN + DE translations (all `_()`/`__()` strings in the branch are now fully localised). |
| 0.5.4 | Migrations: `migrate_0.5.4.sql` — add `tipo_media` ENUM column (libro/disco/audiolibro/dvd/altro) with idempotent guards, composite index `(deleted_at, tipo_media)`, heuristic backfill from `formato` using anchored LIKE patterns to avoid false positives (`%cd%` matches CD-ROM → stay libro, `%lp%` matches "help" → stay libro). Features: Discogs music scraper plugin (Discogs + MusicBrainz + CoverArtArchive + Deezer, 4 hooks including scrape.isbn.validate for UPC-12/13), barcode→ISBN guard in ScrapeController::normalizeIsbnFields (skip when no format/tipo_media signal, avoid EAN-in-isbn13 regression), logApiFailure helper (severity by HTTP code), numero_inventario no longer pre-filled from Discogs catalog (Cat# stays in note_varie), PluginManager migrated from error_log to SecureLogger (31 sites). **POST-RELEASE HOTFIXES (2):** (a) `autoRegisterBundledPlugins` INSERT had 14 columns / 13 values after CodeRabbit round 11 — fresh installs crashed with "Column count doesn't match value count" (fixed in c9bd82c, Lesson #7). (b) Same method's `bind_param('ssssssssissss')` had positions 8 and 9 swapped — `is_active` typed as `s`, `path` as `i`, causing `path='discogs'` to cast to int 0, orphan detection then deleted the rows (fixed in fb1e881, Lesson #8). |
| 0.5.3 | No migrations. Fix: descrizione_plain propagated to catalog search and admin grid (COALESCE+NULLIF). ISSN added to Schema.org JSON-LD and public API. CollaneController::rename() atomicity fix. LibraryThing import: descrizione_plain (html_entity_decode), ISSN normalization, AuthorNormalizer on traduttore, soft-delete guards, descrizione_plain conditional. Secondary Author Roles routed to traduttore. |
| 0.5.2 | No migrations. Fix: AuthorNormalizer applied to translator, illustrator, curator on create/update/scraping. Client-side "Surname, Name" → "Name Surname" normalization for translator/illustrator in book form. Shared normalizeAuthorName() JS helper. |
| 0.5.1 | Migrations: `volumi` table (multi-volume works), `collane` table (series metadata), `idx_collana` index. Features: ISSN field with validation, series management admin page (CRUD, merge, bulk assign, autocomplete), multi-volume works (add/remove volumes, cycle prevention, parent work creation), LibraryThing/scraping series parsing, frontend "same series" section. Bug fixes: ISSN explicit validation error, transactions on collane operations, soft-delete guards, hasCollaneTable() resilience, non-numeric volume sorting, unified search response parsing. |
| 0.5.0 | Migrations: `curatore` column, `llms_txt_enabled` setting, `issn` column, RSS feed settings. Features: Hreflang alternate tags, RSS 2.0 feed, dynamic /llms.txt, Schema.org enrichment (sameAs, all author roles, bookEdition, conditional Offer, event location), curator field, host header validation. Bug fixes: CSV column shift (#83), admin genre display (#90), co-autore sort. |
| 0.4.9.9 | Migrations: `descrizione_plain` column for HTML-free search (strip_tags backfill via PHP), social sharing default settings. Features: Configurable social share buttons on book detail (Facebook, X, WhatsApp, Telegram, LinkedIn, Reddit, Pinterest, Email, Copy Link, Web Share API) with Admin Settings > Sharing tab and live preview. Genre breadcrumb navigation on catalog/detail pages. Genre filter by ID fix (500 error). Digital Library plugin v1.3.0: inline PDF viewer (iframe-based, zero deps), ePub download fix. |
| 0.4.9.7 | Re-release of 0.4.9.6 to ensure bundled plugin updates propagate to installations that updated from pre-0.4.9.6 (older Updater lacked updateBundledPlugins) |
| 0.4.9.6 | Comprehensive codebase review: URL scheme validation, proxy-aware HTTPS in installer, bcrypt 72-byte limit, atomic RateLimiter with flock, guarded recalculateBookAvailability/RELEASE_LOCK calls, DashboardStats cache failure throw, language-switcher logging, config charset in SET NAMES |
| 0.4.9.4 | Audiobook MP3 player, Z39.50/SRU Nordic sources, global keyboard shortcuts, scroll-to-top, rate-limit bypass fix, German installer support |
| 0.4.9.2 | Genre management (edit/merge/rearrange), book list filters (series, genre), German locale support |
| 0.4.9 | Full subfolder installation support, configurable homepage sort, comprehensive security hardening (177 files), route translation |
| 0.4.8.4 | Re-release of 0.4.8.3 with correct production autoloader and verified ZIP (0.4.8.3 was broken — dev autoloader with phpstan refs shipped by mistake); Added: ZIP verification step (5.5) in create-release.sh |
| 0.4.8.3 | **BROKEN RELEASE — DO NOT USE.** Fixed: Replace all hardcoded URLs with RouteTranslator, add TinyMCE `model: 'dom'` to all 6 editors, fix email notification URLs, add date validation in reservations, add `attivo=1` filter to loan pickup query |
| 0.4.8.2 | Migration: Add illustratore field, expand lingua to varchar(255), add illustratore to libri_autori enum, normalize language names to native, anno_pubblicazione signed (BCE support); Fixed: installer session persistence, CSV import duplicate inventory numbers |
| 0.4.8.1 | Migration: Import logs tracking system (import_logs table, composite index); Fixed: schema.sql aligned with all migrations |
| 0.4.7 | Migration: Comprehensive LibraryThing schema (25+ fields, indexes, JSON visibility control) |
| 0.4.6 | Migration: LibraryThing missing fields; Added: Pre-update and post-install patch systems |
| 0.4.5 | Migration: Pickup confirmation workflow with email templates |
| 0.4.4 | Migration: System settings; Fixed: Always use storage/tmp, cURL download, retry mechanism |
| 0.4.3 | Migration: Prestiti enhancements; Added: Log viewer endpoint |
| 0.4.2 | Migration: Calendar and holidays; Added: Verbose logging |
| 0.4.1 | Bug: Uses sys_get_temp_dir() (fails on shared hosting) |
