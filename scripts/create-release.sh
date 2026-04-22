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
    echo "Usage: ./scripts/create-release.sh 0.4.8"
    exit 1
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
if [ "$BRANCH" != "main" ]; then
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
    "public/index.php"
    "app/Support/Updater.php"
    "version.json"
    "vendor/composer/autoload_real.php"
)

# Bundled plugins that MUST be in the ZIP (scraping-pro is premium, NOT bundled)
BUNDLED_PLUGINS=(
    "api-book-scraper"
    "deezer"
    "dewey-editor"
    "digital-library"
    "discogs"
    "goodlib"
    "musicbrainz"
    "open-library"
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

gh release create "v${VERSION}" \
    --title "Pinakes v${VERSION}" \
    --generate-notes \
    --latest

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ ERROR: GitHub release creation failed${NC}"
    exit 1
fi

echo -e "${GREEN}✓ GitHub release created${NC}"
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
        # Ensure checksum is fresh — the Updater verifies SHA-256 of the patch
        # before executing it, so a stale .sha256 would cause the patch to be
        # silently ignored on user installs.
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
# STEP 9.5: VERIFY THE ACTUAL REMOTE ZIP MATCHES THE LOCAL ZIP
# ============================================================================
# HARD RULE (see updater.md §ABSOLUTE RULE): "upload succeeded" is NOT enough.
# On 2026-04-22 gh release upload produced a truncated remote artifact for
# v0.5.9.2 (24.7 MB remote, 26.7 MB local, 5 plugins vs 10). Nobody noticed
# because the script only verified the local ZIP. Download the remote
# artifact and SHA-compare it, every single time.
echo -e "${YELLOW}[9.5/9] Verifying REMOTE ZIP matches local ZIP...${NC}"

REMOTE_VERIFY_DIR=$(mktemp -d)
REMOTE_ZIP="$REMOTE_VERIFY_DIR/remote.zip"

if ! curl -sSL -o "$REMOTE_ZIP" \
    "https://github.com/fabiodalez-dev/Pinakes/releases/download/v${VERSION}/pinakes-v${VERSION}.zip"; then
    echo -e "${RED}❌ ERROR: Failed to download remote ZIP for verification${NC}"
    rm -rf "$REMOTE_VERIFY_DIR"
    exit 1
fi

LOCAL_SHA=$(shasum -a 256 "$ZIPFILE" | awk '{print $1}')
REMOTE_SHA=$(shasum -a 256 "$REMOTE_ZIP" | awk '{print $1}')

if [ "$LOCAL_SHA" != "$REMOTE_SHA" ]; then
    echo -e "${RED}❌ CRITICAL: REMOTE ZIP DOES NOT MATCH LOCAL ZIP${NC}"
    echo -e "${RED}   local:  $LOCAL_SHA${NC}"
    echo -e "${RED}   remote: $REMOTE_SHA${NC}"
    echo -e "${RED}   local size:  $(wc -c < "$ZIPFILE") bytes${NC}"
    echo -e "${RED}   remote size: $(wc -c < "$REMOTE_ZIP") bytes${NC}"
    echo -e "${RED}This means the upload silently corrupted/truncated the ZIP.${NC}"
    echo -e "${RED}DO NOT ANNOUNCE THIS RELEASE. Delete it and retry:${NC}"
    echo -e "${RED}  gh release delete v${VERSION} --yes${NC}"
    echo -e "${RED}  ./scripts/create-release.sh ${VERSION}${NC}"
    rm -rf "$REMOTE_VERIFY_DIR"
    exit 1
fi

# Also re-run the plugin-count check against the remote ZIP (defence in depth)
REMOTE_PLUGIN_COUNT=$(unzip -l "$REMOTE_ZIP" 2>/dev/null | grep -cE "storage/plugins/[^/]+/plugin\.json$" || true)
LOCAL_PLUGIN_COUNT=$(unzip -l "$ZIPFILE" 2>/dev/null | grep -cE "storage/plugins/[^/]+/plugin\.json$" || true)
if [ "$REMOTE_PLUGIN_COUNT" != "$LOCAL_PLUGIN_COUNT" ] || [ "$REMOTE_PLUGIN_COUNT" -lt 10 ]; then
    echo -e "${RED}❌ CRITICAL: Remote ZIP has $REMOTE_PLUGIN_COUNT plugin.json files, local has $LOCAL_PLUGIN_COUNT, expected ≥ 10${NC}"
    rm -rf "$REMOTE_VERIFY_DIR"
    exit 1
fi

rm -rf "$REMOTE_VERIFY_DIR"
echo -e "${GREEN}✓ Remote ZIP matches local (SHA256 $LOCAL_SHA, $REMOTE_PLUGIN_COUNT plugins)${NC}"
echo ""

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
echo "Next steps:"
echo "1. Edit release notes on GitHub if needed"
echo "2. Test the update from admin panel"
echo "3. Announce the release"
echo ""
