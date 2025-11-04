#!/bin/bash

# ========================================
# Clean Git History - Remove Sensitive Files
# ========================================
# This script removes sensitive files from git history
# WARNING: This rewrites git history - use with caution!

set -e

echo "╔════════════════════════════════════════╗"
echo "║   Git History Cleanup Script          ║"
echo "╚════════════════════════════════════════╝"
echo ""
echo "⚠️  WARNING: This will rewrite git history!"
echo "   All sensitive files will be removed from ALL commits."
echo ""

# Check if git-filter-repo is installed
if ! command -v git-filter-repo &> /dev/null; then
    echo "❌ git-filter-repo not found"
    echo ""
    echo "Installing git-filter-repo..."
    echo "macOS: brew install git-filter-repo"
    echo "Ubuntu: sudo apt-get install git-filter-repo"
    echo "pip: pip3 install git-filter-repo"
    echo ""
    read -p "Install with pip3 now? (y/n): " install_choice
    if [ "$install_choice" = "y" ]; then
        pip3 install git-filter-repo
    else
        exit 1
    fi
fi

# Create backup
echo ""
echo "📦 Creating backup branch..."
BACKUP_BRANCH="backup-$(date +%Y%m%d-%H%M%S)"
git branch "$BACKUP_BRANCH"
echo "✓ Backup created: $BACKUP_BRANCH"

# List of sensitive file patterns to remove
echo ""
echo "🗑️  Files to remove from history:"
echo "   - .env (all versions)"
echo "   - .env.backup"
echo "   - *.sql (except installer/database/)"
echo "   - backups/*.sql"
echo "   - backup_*.sql"
echo "   - *.zip, *.tar, *.tar.gz"
echo "   - Archive.zip"
echo "   - *.backup, *.bak, *.old"
echo ""

read -p "Proceed with cleanup? (yes/no): " proceed
if [ "$proceed" != "yes" ]; then
    echo "❌ Cleanup cancelled"
    git branch -D "$BACKUP_BRANCH"
    exit 1
fi

# Remove currently tracked sensitive files
echo ""
echo "🧹 Removing tracked sensitive files..."
git rm -rf --cached .env.backup 2>/dev/null || true
echo "✓ Tracked files removed"

# Create paths file for git-filter-repo
PATHS_FILE="/tmp/git-filter-repo-paths.txt"
cat > "$PATHS_FILE" <<'EOF'
# Files to remove from git history
.env
.env.backup
.env.local
.env.*.local
regex:backup_.*\.sql
regex:backups/.*\.sql
regex:.*\.backup$
regex:.*\.bak$
regex:.*\.old$
Archive.zip
old.sql
regex:.*\.zip$
regex:.*\.tar$
regex:.*\.tar\.gz$
regex:.*\.rar$
EOF

echo ""
echo "🔨 Rewriting git history..."
echo "   This may take a few minutes..."

# Run git filter-repo
git filter-repo --invert-paths --paths-from-file "$PATHS_FILE" --force

# Clean up
rm "$PATHS_FILE"

echo ""
echo "✓ Git history cleaned successfully!"
echo ""
echo "📊 Statistics:"
git count-objects -vH

echo ""
echo "═════════════════════════════════════════"
echo "✅ Cleanup Complete!"
echo "═════════════════════════════════════════"
echo ""
echo "Next steps:"
echo "1. Verify the repository is clean:"
echo "   git log --all --pretty=format: --name-only | sort -u | grep -E '\.(env|sql)'"
echo ""
echo "2. If satisfied, delete backup branch:"
echo "   git branch -D $BACKUP_BRANCH"
echo ""
echo "3. Force push to remote (if already pushed):"
echo "   git push origin --force --all"
echo "   git push origin --force --tags"
echo ""
echo "⚠️  WARNING: Force push will rewrite remote history!"
echo "   Only do this if repository is private and you coordinate with team."
echo ""
