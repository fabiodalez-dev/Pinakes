#!/bin/bash
set -e

# ============================================================================
# Pinakes Release Creation Script
# ============================================================================
# This script automates the ENTIRE release process to prevent errors.
# NEVER create releases manually - ALWAYS use this script!
#
# Usage: ./scripts/create-release.sh 0.4.8
# ============================================================================

VERSION=$1

if [ -z "$VERSION" ]; then
    echo "❌ ERROR: Version number required"
    echo "Usage: ./scripts/create-release.sh 0.4.8           (stable release)"
    echo "       ./scripts/create-release.sh 0.7.15-rc.1     (release candidate / prerelease)"
    exit 1
fi

# SECURITY: VERSION is interpolated into gh/jq/tag commands below. Whitelist the
# shape (X.Y.Z, optional 4th segment, optional -prerelease tail) so a value with
# shell metacharacters can never reach those commands. Matches the project's
# 3- and 4-segment scheme (e.g. 0.7.20, 0.4.9.9) plus SemVer pre-release ids.
if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?(-[0-9A-Za-z.]+)?$'; then
    echo "❌ ERROR: invalid version '$VERSION'."
    echo "   Expected X.Y.Z, X.Y.Z.W, or X.Y.Z-rc.N (digits, dots, and a -prerelease tail only)."
    exit 1
fi

# Detect a release candidate / prerelease: any SemVer pre-release identifier,
# i.e. a hyphen in the version (0.7.15-rc.1, 0.8.0-beta.2, …). RC packages are
# published as GitHub *prereleases* so the /releases/latest endpoint skips them
# and the in-app updater keeps them hidden unless a developer opts into the RC
# channel via env (UPDATER_ALLOW_PRERELEASE=1 or UPDATER_CHANNEL=rc). See updater.md.
IS_PRERELEASE=false
if [[ "$VERSION" == *-* ]]; then
    IS_PRERELEASE=true
fi

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}Creating Pinakes Release v${VERSION}${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""

# ============================================================================
# STEP 1: Verify we're on main branch and up to date
# ============================================================================
echo -e "${YELLOW}[1/9] Verifying git status...${NC}"

BRANCH=$(git branch --show-current)
if [ "$IS_PRERELEASE" = true ]; then
    # RC/prerelease packages are routinely cut from a feature/release branch,
    # never from main. The tag is created against this branch (see STEP 7), so
    # the branch MUST already be pushed to origin.
    echo -e "${YELLOW}⚠ Prerelease ${VERSION}: branch-must-be-main check relaxed (currently on: $BRANCH).${NC}"
    if [ -z "$(git ls-remote --heads origin "$BRANCH" 2>/dev/null)" ]; then
        echo -e "${RED}❌ ERROR: branch '$BRANCH' is not on origin. Push it first: git push -u origin $BRANCH${NC}"
        exit 1
    fi
elif [ "$BRANCH" != "main" ]; then
    echo -e "${RED}❌ ERROR: Must be on main branch (currently on: $BRANCH)${NC}"
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo -e "${RED}❌ ERROR: Working directory not clean. Commit or stash changes first.${NC}"
    git status --short
    exit 1
fi

echo -e "${GREEN}✓ On main branch, working directory clean${NC}"
echo ""

# ============================================================================
# STEP 2: Verify version.json has been updated
# ============================================================================
echo -e "${YELLOW}[2/9] Checking version.json...${NC}"

CURRENT_VERSION=$(jq -r '.version' version.json)
if [ "$CURRENT_VERSION" != "$VERSION" ]; then
    echo -e "${RED}❌ ERROR: version.json has version $CURRENT_VERSION but you specified $VERSION${NC}"
    echo "Update version.json first and commit it."
    exit 1
fi

echo -e "${GREEN}✓ version.json is correct: $VERSION${NC}"
echo ""

# ============================================================================
# STEP 3: Verify autoloader has NO dev dependencies
# ============================================================================
echo -e "${YELLOW}[3/9] Verifying autoloader is clean (no dev deps)...${NC}"

# PHPStan was removed from composer.json — autoloader should never reference it
if grep -q "phpstan" vendor/composer/autoload_files.php 2>/dev/null; then
    echo -e "${RED}❌ ERROR: vendor/composer still references phpstan!${NC}"
    echo -e "${RED}   Run: composer install --no-dev --optimize-autoloader${NC}"
    exit 1
fi

if grep -q "phpstan" vendor/composer/autoload_static.php 2>/dev/null; then
    echo -e "${RED}❌ ERROR: vendor/composer/autoload_static.php references phpstan!${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Autoloader clean (no PHPStan references)${NC}"
echo ""

# ============================================================================
# STEP 5: Create release ZIP with git archive
# ============================================================================
echo -e "${YELLOW}[5/9] Creating release ZIP...${NC}"

ZIPFILE="pinakes-v${VERSION}.zip"
rm -f "$ZIPFILE" "${ZIPFILE}.sha256"

git archive --format=zip --prefix="pinakes-v${VERSION}/" -o "$ZIPFILE" HEAD

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ ERROR: git archive failed${NC}"
    exit 1
fi

SIZE=$(ls -lh "$ZIPFILE" | awk '{print $5}')
SIZE_BYTES=$(stat -f%z "$ZIPFILE" 2>/dev/null || stat -c%s "$ZIPFILE" 2>/dev/null || echo 0)
MAX_SIZE=$((50 * 1024 * 1024)) # 50MB — normal release is ~25-35MB

if [ "$SIZE_BYTES" -gt "$MAX_SIZE" ]; then
    echo -e "${RED}❌ ERROR: ZIP is suspiciously large ($SIZE). Expected <50MB.${NC}"
    echo -e "${RED}   Likely contains dev files (frontend/, docs/, releases/, etc.)${NC}"
    echo -e "${RED}   Check .gitattributes export-ignore rules.${NC}"
    rm -f "$ZIPFILE"
    exit 1
fi

echo -e "${GREEN}✓ Release ZIP created: $ZIPFILE ($SIZE)${NC}"
echo ""

# ============================================================================
# STEP 5.5: Verify ZIP contents (critical files check)
# ============================================================================
echo -e "${YELLOW}[5.5/9] Verifying ZIP contents...${NC}"

VERIFY_DIR=$(mktemp -d)
unzip -q "$ZIPFILE" -d "$VERIFY_DIR"

# List of critical files that MUST be in the ZIP
CRITICAL_FILES=(
    "public/assets/tinymce/tinymce.min.js"
    "public/assets/tinymce/models/dom/model.min.js"
    "public/assets/tinymce/themes/silver/theme.min.js"
    "public/assets/tinymce/skins/ui/oxide/skin.min.css"
    "public/assets/tinymce/icons/default/icons.min.js"
    "public/assets/swagger-ui/swagger-ui-bundle.js"
    "public/assets/swagger-ui/swagger-ui.css"
    "public/index.php"
    "app/Support/Updater.php"
    "version.json"
    "vendor/composer/autoload_real.php"
)

# Bundled plugins that MUST be in the ZIP (scraping-pro is premium, NOT bundled)
BUNDLED_PLUGINS=(
    "api-book-scraper"
    "archives"
    "bibframe-linked-data"
    "deezer"
    "dewey-editor"
    "digital-library"
    "discogs"
    "frbr-lrm"
    "goodlib"
    "mobile-api"
    "musicbrainz"
    "ncip-server"
    "oai-pmh-server"
    "open-library"
    "openurl-resolver"
    "resource-sync"
    "viaf-authority"
    "z39-server"
)

MISSING=0
for file in "${CRITICAL_FILES[@]}"; do
    FULL_PATH="$VERIFY_DIR/pinakes-v${VERSION}/$file"
    if [ ! -f "$FULL_PATH" ]; then
        echo -e "${RED}  ✗ MISSING: $file${NC}"
        MISSING=$((MISSING + 1))
    fi
done

# Verify bundled plugins are present
for plugin in "${BUNDLED_PLUGINS[@]}"; do
    PLUGIN_JSON="$VERIFY_DIR/pinakes-v${VERSION}/storage/plugins/$plugin/plugin.json"
    if [ ! -f "$PLUGIN_JSON" ]; then
        echo -e "${RED}  ✗ MISSING PLUGIN: storage/plugins/$plugin/plugin.json${NC}"
        MISSING=$((MISSING + 1))
    fi
done

# Verify scraping-pro is NOT in ZIP (premium plugin, not bundled)
if [ -d "$VERIFY_DIR/pinakes-v${VERSION}/storage/plugins/scraping-pro" ]; then
    echo -e "${RED}  ✗ scraping-pro found in ZIP (should NOT be bundled — it's premium)${NC}"
    MISSING=$((MISSING + 1))
fi

# Verify dev-only directories are NOT in ZIP (export-ignore in .gitattributes)
for devdir in frontend docs tests test .github internal; do
    if [ -d "$VERIFY_DIR/pinakes-v${VERSION}/$devdir" ]; then
        echo -e "${RED}  ✗ $devdir/ found in ZIP (should be excluded via .gitattributes export-ignore)${NC}"
        MISSING=$((MISSING + 1))
    fi
done

# Verify no PHPStan in autoloader
PHPSTAN_COUNT=$(grep -c "phpstan" "$VERIFY_DIR/pinakes-v${VERSION}/vendor/composer/autoload_real.php" || true)
if [ "$PHPSTAN_COUNT" -gt 0 ]; then
    echo -e "${RED}  ✗ PHPStan found in autoload_real.php ($PHPSTAN_COUNT references)${NC}"
    MISSING=$((MISSING + 1))
fi

# Detect symlinks in the ZIP via zipinfo metadata (macOS `unzip` would recreate
# them, but PHP ZipArchive on Linux extracts as 22-byte regular files → Updater
# then fails copy(file, existing_dir). Broke v0.5.4 manual upgrade in prod.)
# zipinfo long-format symlink lines look like:
#   lrwxrwxrwx  2.0 unx   22 b- stor ... <path> -> <target>
# We want <path> (the offending repo path the maintainer must fix), which is
# the field immediately before "->" — NOT $NF, which would be the target.
SYMLINKS_IN_ZIP=$(zipinfo "$ZIPFILE" 2>/dev/null \
    | awk '/^l/ { for (i=1; i<=NF; i++) if ($i == "->") { print $(i-1); break } }')
if [ -n "$SYMLINKS_IN_ZIP" ]; then
    echo -e "${RED}  ✗ Symlinks in ZIP — will break Updater.copyDirectory() in production:${NC}"
    echo "$SYMLINKS_IN_ZIP" | sed 's/^/    /'
    echo -e "${RED}    Fix: replace the symlink in the repo with a real directory containing the files.${NC}"
    MISSING=$((MISSING + 1))
fi

# Verify version matches
ZIP_VERSION=$(jq -r '.version' "$VERIFY_DIR/pinakes-v${VERSION}/version.json")
if [ "$ZIP_VERSION" != "$VERSION" ]; then
    echo -e "${RED}  ✗ version.json in ZIP has $ZIP_VERSION (expected $VERSION)${NC}"
    MISSING=$((MISSING + 1))
fi

rm -rf "$VERIFY_DIR"

if [ "$MISSING" -gt 0 ]; then
    echo -e "${RED}❌ ERROR: ZIP verification failed ($MISSING problems). Aborting release.${NC}"
    rm -f "$ZIPFILE"
    exit 1
fi

echo -e "${GREEN}✓ ZIP verified: all critical files present, ${#BUNDLED_PLUGINS[@]} bundled plugins, no PHPStan, version correct${NC}"
echo ""

# ============================================================================
# STEP 6: Generate SHA256 checksum
# ============================================================================
echo -e "${YELLOW}[6/9] Generating SHA256 checksum...${NC}"

shasum -a 256 "$ZIPFILE" > "${ZIPFILE}.sha256"

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ ERROR: checksum generation failed${NC}"
    exit 1
fi

CHECKSUM=$(cat "${ZIPFILE}.sha256" | awk '{print $1}')
echo -e "${GREEN}✓ Checksum: $CHECKSUM${NC}"
echo ""

# ============================================================================
# STEP 7: Create GitHub release
# ============================================================================
echo -e "${YELLOW}[7/9] Creating GitHub release v${VERSION}...${NC}"

# Check if release already exists
if gh release view "v${VERSION}" >/dev/null 2>&1; then
    echo -e "${YELLOW}⚠ Release v${VERSION} already exists. Deleting and recreating...${NC}"
    gh release delete "v${VERSION}" --yes
fi

# SECURITY (draft → upload → verify → publish): create the release as a DRAFT so
# it is never visible/installable while we upload and verify its assets. It is
# published only AFTER the remote ZIP is proven to match the local one (STEP 9.6).
# --target pins the commit the tag will point at when the draft is published.
if [ "$IS_PRERELEASE" = true ]; then
    # --prerelease (NOT --latest): GitHub excludes prereleases from
    # /releases/latest, so the default updater never offers this package.
    gh release create "v${VERSION}" \
        --title "Pinakes v${VERSION} (Release Candidate)" \
        --generate-notes \
        --prerelease \
        --draft \
        --target "$BRANCH"
else
    gh release create "v${VERSION}" \
        --title "Pinakes v${VERSION}" \
        --generate-notes \
        --draft \
        --target "$BRANCH"
fi

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ ERROR: GitHub release creation failed${NC}"
    exit 1
fi

echo -e "${GREEN}✓ GitHub DRAFT release created (published only after verification)${NC}"
echo ""

# ============================================================================
# STEP 8: Upload ZIP and checksum to release
# ============================================================================
echo -e "${YELLOW}[8/9] Uploading files to GitHub release...${NC}"

# Build the asset list — always ZIP + its checksum, plus optional patch files
# (post-install-patch.php / pre-update-patch.php) when present in repo root.
# Those patch files are release-specific hotfixes dropped next to the script
# by the maintainer; they're gitignored so they don't bleed into main.
UPLOAD_ASSETS=("$ZIPFILE" "${ZIPFILE}.sha256")
for PATCH in post-install-patch.php pre-update-patch.php; do
    if [ -f "$PATCH" ]; then
        # Publish a fresh checksum next to the patch. NOTE: the hardened Updater
        # verifies the patch from the GitHub asset *digest* (computed server-side
        # over TLS), NOT this sidecar — the .sha256 fallback was removed. The
        # sidecar is kept only as a convenience for manual verification.
        shasum -a 256 "$PATCH" > "${PATCH}.sha256"
        UPLOAD_ASSETS+=("$PATCH" "${PATCH}.sha256")
        echo -e "${YELLOW}  + Attaching $PATCH (+ checksum)${NC}"
    fi
done

gh release upload "v${VERSION}" "${UPLOAD_ASSETS[@]}" --clobber

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ ERROR: File upload failed${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Files uploaded to release${NC}"
echo ""

# ============================================================================
# STEP 9: Verify release is complete
# ============================================================================
echo -e "${YELLOW}[9/9] Verifying release...${NC}"

ASSETS=$(gh release view "v${VERSION}" --json assets --jq '.assets | length')

if [ "$ASSETS" -lt 2 ]; then
    echo -e "${RED}❌ ERROR: Release has only $ASSETS assets (expected at least 2)${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Release has $ASSETS assets${NC}"
echo ""

# ============================================================================
# STEP 9.4: INTEGRITY-SOURCE GUARD (supports the hardened in-app updater)
# The updater REFUSES to install a package it cannot verify. Since the sidecar
# fallback was removed (see Updater.php — "The '.sha256' sidecar fallback was
# removed"), integrity comes EXCLUSIVELY from the GitHub asset "digest"
# (sha256:...), which GitHub computes server-side over TLS. A release without a
# digest would wedge the upgrade chain for every install, so fail the publish
# here rather than discover it at users' update time.
# ============================================================================
ZIP_ASSET_NAME="pinakes-v${VERSION}.zip"
# Resolve the DRAFT release's numeric id by tag_name. Drafts have no git tag yet,
# so `gh release view <tag>` / releases/tags/<tag> are unreliable for them — list
# releases (which includes drafts) and match tag_name, then read assets via the
# release id. Same draft-safe pattern as STEP 9.5.
GUARD_RELEASE_ID=$(gh api "repos/fabiodalez-dev/Pinakes/releases" --paginate \
    --jq ".[] | select(.tag_name == \"v${VERSION}\") | .id" 2>/dev/null | head -1)
if [ -z "$GUARD_RELEASE_ID" ]; then
    echo -e "${RED}❌ ERROR: could not resolve the draft release id for v${VERSION} (integrity guard).${NC}"
    exit 1
fi

# GitHub computes the asset "digest" ASYNCHRONOUSLY after upload (like size), so
# poll briefly rather than fail on the first miss.
HAS_DIGEST="no"
for attempt in 1 2 3 4 5 6; do
    GUARD_ASSET_META=$(gh api "repos/fabiodalez-dev/Pinakes/releases/${GUARD_RELEASE_ID}/assets" \
        --jq ".[] | select(.name == \"${ZIP_ASSET_NAME}\")" 2>/dev/null || echo "")
    # Match the Updater's runtime contract (isValidSha256): require the FULL
    # "sha256:" + 64 hex chars, not just the prefix — a malformed digest like
    # "sha256:NOTHEX" would pass a prefix check here but be rejected as 'invalid'
    # by the updater at install time.
    HAS_DIGEST=$(printf '%s' "$GUARD_ASSET_META" | jq -r 'if (.digest // "") | test("^sha256:[A-Fa-f0-9]{64}$") then "yes" else "no" end' 2>/dev/null || echo "no")
    [ "$HAS_DIGEST" = "yes" ] && break
    echo -e "${YELLOW}  digest not computed yet (attempt $attempt/6), waiting 10s...${NC}"
    sleep 10
done

if [ "$HAS_DIGEST" != "yes" ]; then
    echo -e "${RED}❌ ERROR: release asset ${ZIP_ASSET_NAME} exposes no API sha256 digest after polling.${NC}"
    echo -e "${RED}   The hardened updater verifies integrity from the digest ONLY (sidecar fallback removed).${NC}"
    echo -e "${RED}   GitHub usually computes the digest within seconds — re-check the asset or re-upload.${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Integrity source present (digest=${HAS_DIGEST}) — hardened updater can verify this package${NC}"
echo ""

# ============================================================================
# STEP 9.5: VERIFY THE ACTUAL REMOTE ZIP MATCHES THE LOCAL ZIP
# ============================================================================
# HARD RULE (see updater.md §ABSOLUTE RULE): "upload succeeded" is NOT enough.
# On 2026-04-22 two separate failure modes corrupted the shipped ZIP:
#   1) gh release upload produced a truncated remote artifact (v0.5.9.2).
#   2) A hidden GitHub Actions workflow (release.yml) rebuilt the ZIP via
#      bin/build-release.sh and overwrote the asset AFTER the upload —
#      verification hitting the CDN saw the cached correct file briefly,
#      then the CDN invalidated and users downloaded the workflow's broken
#      ZIP (v0.5.9.3, reported by HansUwe52).
# Mitigations:
#   - release.yml is now renamed .disabled so it does not race our upload.
#   - This step fetches via the GitHub API (asset ID + octet-stream Accept),
#     bypassing the CDN entirely.
#   - It also polls for up to 90 seconds to catch any asynchronous overwrite
#     from a rogue workflow that might slip in.
#   - Sanity-check: uploader MUST be the current gh user, NOT github-actions[bot].
echo -e "${YELLOW}[9.5/9] Verifying REMOTE ZIP matches local ZIP (via API, not CDN)...${NC}"

REMOTE_VERIFY_DIR=$(mktemp -d)
REMOTE_ZIP="$REMOTE_VERIFY_DIR/remote.zip"

LOCAL_SHA=$(shasum -a 256 "$ZIPFILE" | awk '{print $1}')
LOCAL_PLUGIN_COUNT=$(unzip -l "$ZIPFILE" 2>/dev/null | grep -cE "storage/plugins/[^/]+/plugin\.json$" || true)
GH_USER=$(gh api user --jq .login 2>/dev/null || echo "unknown")

# Resolve the DRAFT release's numeric id by tag_name: drafts have no git tag yet,
# so the tag-based API (releases/tags/v…) does not work — list releases (which
# includes drafts) and match on tag_name. Assets are then read via the release id.
RELEASE_ID=$(gh api "repos/fabiodalez-dev/Pinakes/releases" --paginate \
    --jq ".[] | select(.tag_name == \"v${VERSION}\") | .id" 2>/dev/null | head -1)
if [ -z "$RELEASE_ID" ]; then
    echo -e "${RED}❌ ERROR: could not resolve the draft release id for v${VERSION}${NC}"
    rm -rf "$REMOTE_VERIFY_DIR"
    exit 1
fi

# Poll for up to 90s so a slow/async workflow override would also be caught.
ATTEMPTS=0
MAX_ATTEMPTS=9
MATCH=0
while [ $ATTEMPTS -lt $MAX_ATTEMPTS ]; do
    ATTEMPTS=$((ATTEMPTS + 1))

    # 1. Look up the asset's numeric ID + metadata via the release id (draft-safe).
    ASSET_META=$(gh api "repos/fabiodalez-dev/Pinakes/releases/${RELEASE_ID}/assets" \
        --jq ".[] | select(.name == \"pinakes-v${VERSION}.zip\") | {id, size, uploader: .uploader.login}" 2>/dev/null || echo "")
    if [ -z "$ASSET_META" ]; then
        echo -e "${YELLOW}  attempt $ATTEMPTS/$MAX_ATTEMPTS: asset not listed yet, retrying in 10s${NC}"
        sleep 10
        continue
    fi

    ASSET_ID=$(echo "$ASSET_META" | jq -r '.id')
    REMOTE_SIZE=$(echo "$ASSET_META" | jq -r '.size')
    REMOTE_UPLOADER=$(echo "$ASSET_META" | jq -r '.uploader')

    # 2. Fail loudly if the uploader is a bot — means a workflow hijacked the release
    if [ "$REMOTE_UPLOADER" = "github-actions[bot]" ]; then
        echo -e "${RED}❌ CRITICAL: release asset uploader is github-actions[bot]${NC}"
        echo -e "${RED}   A GitHub Actions workflow overwrote our upload.${NC}"
        echo -e "${RED}   Expected uploader: $GH_USER${NC}"
        echo -e "${RED}   Check for rogue workflows in .github/workflows/${NC}"
        rm -rf "$REMOTE_VERIFY_DIR"
        exit 1
    fi

    # 3. Download via the API (bypasses CDN, always returns current content)
    if ! gh api "repos/fabiodalez-dev/Pinakes/releases/assets/${ASSET_ID}" \
        -H "Accept: application/octet-stream" > "$REMOTE_ZIP" 2>/dev/null; then
        echo -e "${YELLOW}  attempt $ATTEMPTS/$MAX_ATTEMPTS: API download failed, retrying${NC}"
        sleep 10
        continue
    fi

    REMOTE_SHA=$(shasum -a 256 "$REMOTE_ZIP" | awk '{print $1}')
    REMOTE_PLUGIN_COUNT=$(unzip -l "$REMOTE_ZIP" 2>/dev/null | grep -cE "storage/plugins/[^/]+/plugin\.json$" || true)

    if [ "$LOCAL_SHA" = "$REMOTE_SHA" ] && [ "$REMOTE_PLUGIN_COUNT" = "$LOCAL_PLUGIN_COUNT" ] && [ "$REMOTE_PLUGIN_COUNT" -ge 10 ]; then
        MATCH=1
        break
    fi

    echo -e "${YELLOW}  attempt $ATTEMPTS/$MAX_ATTEMPTS: mismatch (sha local=$LOCAL_SHA remote=$REMOTE_SHA, plugins local=$LOCAL_PLUGIN_COUNT remote=$REMOTE_PLUGIN_COUNT), retrying${NC}"
    sleep 10
done

if [ "$MATCH" != "1" ]; then
    echo -e "${RED}❌ CRITICAL: REMOTE ZIP DOES NOT MATCH LOCAL ZIP after ${MAX_ATTEMPTS} attempts${NC}"
    echo -e "${RED}   local:  $LOCAL_SHA ($LOCAL_PLUGIN_COUNT plugins, $(wc -c < "$ZIPFILE") bytes)${NC}"
    echo -e "${RED}   remote: $REMOTE_SHA ($REMOTE_PLUGIN_COUNT plugins, $(wc -c < "$REMOTE_ZIP") bytes, uploader=$REMOTE_UPLOADER)${NC}"
    echo -e "${RED}DO NOT ANNOUNCE THIS RELEASE. Delete it and retry:${NC}"
    echo -e "${RED}  gh release delete v${VERSION} --yes${NC}"
    echo -e "${RED}  ./scripts/create-release.sh ${VERSION}${NC}"
    rm -rf "$REMOTE_VERIFY_DIR"
    exit 1
fi

rm -rf "$REMOTE_VERIFY_DIR"
echo -e "${GREEN}✓ Remote ZIP matches local via API (SHA256 $LOCAL_SHA, $REMOTE_PLUGIN_COUNT plugins, uploader=$REMOTE_UPLOADER)${NC}"
echo ""

# ============================================================================
# STEP 9.6: PUBLISH the verified draft (draft → upload → verify → PUBLISH)
# The release was a hidden draft until now; only an artifact proven to match the
# local ZIP gets published, closing the "published-before-verified" window.
# ============================================================================
echo -e "${YELLOW}[9.6/9] Publishing the verified draft release...${NC}"
if [ "$IS_PRERELEASE" = true ]; then
    gh release edit "v${VERSION}" --draft=false --prerelease
else
    gh release edit "v${VERSION}" --draft=false --latest
fi
if [ $? -ne 0 ]; then
    echo -e "${RED}❌ ERROR: failed to publish the verified draft release${NC}"
    echo -e "${RED}   The release remains a DRAFT (not visible/installable). Publish manually after review:${NC}"
    echo -e "${RED}     gh release edit v${VERSION} --draft=false${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Release published (verified as a draft first)${NC}"
echo ""

# ----------------------------------------------------------------------------
# Trigger the Docker image rebuild (stable releases only, non-fatal).
# Notifies fabiodalez-dev/pinakes-docker so it rebuilds + republishes the
# multi-arch image for this version. The Docker repo also has a daily poller
# as a safety net, so a failure here is never fatal to the release.
# ----------------------------------------------------------------------------
if [ "$IS_PRERELEASE" = false ] && command -v gh >/dev/null 2>&1; then
    echo -e "${YELLOW}Notifying pinakes-docker to rebuild the image…${NC}"
    if gh api -X POST "repos/fabiodalez-dev/pinakes-docker/dispatches" \
        -f "event_type=pinakes_release" \
        -F "client_payload[pinakes_version]=${VERSION}" >/dev/null 2>&1; then
        echo -e "${GREEN}✓ Docker image rebuild triggered for v${VERSION}${NC}"
    else
        echo -e "${YELLOW}⚠ Could not trigger pinakes-docker (non-fatal). Trigger manually:${NC}"
        echo "    gh workflow run build-publish-docker.yml -R fabiodalez-dev/pinakes-docker -f version=${VERSION}"
    fi
    echo ""
fi

# NOTE: GitHub "immutable releases" (assets frozen post-publish) is a repository
# setting (Settings → General → Releases → Require immutable releases). Enable it
# there so a published asset can never be silently overwritten by a later workflow.

# ============================================================================
# STEP 10: Done (no dev restore needed — PHPStan is global, not in vendor)
# ============================================================================
echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}✅ RELEASE v${VERSION} CREATED SUCCESSFULLY!${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo "Release URL: https://github.com/fabiodalez-dev/Pinakes/releases/tag/v${VERSION}"
echo ""
if [ "$IS_PRERELEASE" = true ]; then
    echo -e "${YELLOW}This is a PRERELEASE (Release Candidate).${NC}"
    echo "- It is hidden from the in-app updater by default (GitHub /releases/latest skips it)."
    echo "- To install/test it, enable the RC channel on the target install:"
    echo "      UPDATER_ALLOW_PRERELEASE=1     (or UPDATER_CHANNEL=rc) in .env"
    echo "  then the updater will offer v${VERSION}. Do NOT announce it to end users."
else
    echo "Next steps:"
    echo "1. Edit release notes on GitHub if needed"
    echo "2. Test the update from admin panel"
    echo "3. Announce the release"
fi
echo ""
