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
# STEP 3: Install production dependencies (NO DEV PACKAGES!)
# ============================================================================
echo -e "${YELLOW}[3/9] Installing production dependencies (composer install --no-dev)...${NC}"

# This is CRITICAL - removes PHPStan and other dev dependencies
composer install --no-dev --optimize-autoloader --no-interaction

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ ERROR: composer install failed${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Production dependencies installed (dev packages removed)${NC}"
echo ""

# ============================================================================
# STEP 4: Verify PHPStan is NOT present
# ============================================================================
echo -e "${YELLOW}[4/9] Verifying PHPStan is removed...${NC}"

if [ -d "vendor/phpstan" ]; then
    echo -e "${RED}❌ ERROR: vendor/phpstan still exists! composer install --no-dev failed.${NC}"
    exit 1
fi

echo -e "${GREEN}✓ PHPStan removed from vendor/${NC}"
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
echo -e "${GREEN}✓ Release ZIP created: $ZIPFILE ($SIZE)${NC}"
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

gh release upload "v${VERSION}" "$ZIPFILE" "${ZIPFILE}.sha256" --clobber

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
# STEP 10: Restore dev dependencies for local development
# ============================================================================
echo -e "${YELLOW}[FINAL] Restoring dev dependencies for local development...${NC}"

composer install --no-interaction

if [ $? -ne 0 ]; then
    echo -e "${YELLOW}⚠ Warning: Could not restore dev dependencies${NC}"
    echo "Run 'composer install' manually"
else
    echo -e "${GREEN}✓ Dev dependencies restored${NC}"
fi

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
