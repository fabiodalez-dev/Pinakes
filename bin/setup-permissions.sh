#!/bin/bash

# Pinakes - Setup Permissions Script
# Sets correct filesystem permissions for web server writable directories
# Run this after cloning from GitHub

set -e

echo "╔════════════════════════════════════════╗"
echo "║   Pinakes - Setup Permissions         ║"
echo "╚════════════════════════════════════════╝"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_ROOT"

echo "ℹ Setting up permissions for Pinakes..."
echo ""

# Function to set directory permissions
set_perms() {
    local dir=$1
    local perms=$2

    if [ -d "$dir" ]; then
        chmod "$perms" "$dir"
        echo -e "${GREEN}✓${NC} $dir → $perms"
    else
        # Create directory if it doesn't exist
        mkdir -p "$dir"
        chmod "$perms" "$dir"
        echo -e "${YELLOW}✓${NC} $dir → created with $perms"
    fi
}

# Writable directories (need 777 or 775)
echo "Setting writable directories (755 → 777)..."
set_perms "uploads" "777"
set_perms "backups" "777"
set_perms "storage" "777"
set_perms "storage/logs" "777"
set_perms "storage/tmp" "777"
set_perms "storage/uploads" "777"
set_perms "storage/backups" "777"
set_perms "public/uploads" "777"

echo ""
echo "Setting execute bit on scripts..."
chmod +x bin/*.sh 2>/dev/null && echo -e "${GREEN}✓${NC} bin/*.sh → executable" || true

echo ""
echo "Creating .gitkeep files to preserve directory structure..."

# Create .gitkeep in empty directories
for dir in uploads backups storage/logs storage/tmp storage/uploads storage/backups public/uploads; do
    if [ ! -f "$dir/.gitkeep" ]; then
        touch "$dir/.gitkeep"
        echo -e "${GREEN}✓${NC} $dir/.gitkeep created"
    fi
done

echo ""
echo -e "${GREEN}✅ Permissions setup completed!${NC}"
echo ""
echo "Directory permissions:"
ls -la uploads backups storage public/uploads 2>/dev/null | grep "^d" | awk '{print "  " $1 " " $9}'
echo ""
echo "You can now proceed with installation:"
echo "  1. Configure .env (copy from .env.example)"
echo "  2. Open http://localhost:8000 in browser"
echo "  3. Follow the installer wizard"
echo ""
