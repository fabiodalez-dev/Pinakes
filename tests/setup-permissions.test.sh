#!/usr/bin/env bash
#
# Test for bin/setup-permissions.sh
# ============================================================================
# Builds a throwaway mock install, runs the permissions script against it, and
# asserts the resulting modes/ownership. Runs WITHOUT root: it chowns to the
# CURRENT user/group (chowning to yourself needs no privilege), so the chown
# path is exercised too. Everything else — dir creation, safe modes, exec
# preservation, .env protection, no-777, idempotency, and the two hard-exit
# guards — is checked directly.
#
# Usage:  tests/setup-permissions.test.sh
# Exit 0 iff every assertion passes.
# ============================================================================

set -u

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCRIPT="$REPO_ROOT/bin/setup-permissions.sh"
PASS=0
FAIL=0

ok()   { echo "  [OK]  $1"; PASS=$((PASS+1)); }
bad()  { echo "  [!!]  $1"; FAIL=$((FAIL+1)); }

# mode(path) → octal perms, portable across GNU (stat -c) and BSD/macOS (stat -f)
mode() { stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1" 2>/dev/null; }

CUR_USER="$(id -un)"
CUR_GROUP="$(id -gn)"

# ── Build a mock install ────────────────────────────────────────────────────
SB="$(mktemp -d 2>/dev/null || echo /tmp/pinakes-perm-$$)"
mkdir -p "$SB/bin" "$SB/public" "$SB/app/Support" "$SB/storage/backups" "$SB/vendor"
cp "$SCRIPT" "$SB/bin/setup-permissions.sh"
chmod +x "$SB/bin/setup-permissions.sh"
echo '{"version":"0.7.27"}'          > "$SB/version.json"
echo '<?php'                          > "$SB/public/index.php"
echo '<?php class Foo {}'             > "$SB/app/Support/Foo.php"
printf '#!/bin/sh\necho hi\n'         > "$SB/bin/tool.sh"; chmod 755 "$SB/bin/tool.sh"
echo 'DB_PASS=secret'                 > "$SB/.env"; chmod 600 "$SB/.env"

echo "── Test: bin/setup-permissions.sh (sandbox: $SB) ──"

# ── 1. Syntax ───────────────────────────────────────────────────────────────
if bash -n "$SCRIPT"; then ok "script parses (bash -n)"; else bad "syntax error"; fi

# ── 2. Guard: rejects a non-install directory ───────────────────────────────
BADDIR="$(mktemp -d 2>/dev/null || echo /tmp/pinakes-nope-$$)"
if bash "$SCRIPT" --root "$BADDIR" >/dev/null 2>&1; then
    bad "should reject a non-install dir (no version.json)"
else
    ok "rejects a non-install directory"
fi
rm -rf "$BADDIR"

# ── 3. Guard: rejects a non-existent user ───────────────────────────────────
if bash "$SCRIPT" --root "$SB" --user __definitely_no_such_user__ >/dev/null 2>&1; then
    bad "should reject a non-existent --user"
else
    ok "rejects a non-existent user"
fi

# ── 4. Dry-run changes nothing ──────────────────────────────────────────────
_env_before="$(mode "$SB/.env")"
bash "$SCRIPT" --root "$SB" --user "$CUR_USER" --group "$CUR_GROUP" >/dev/null 2>&1
if [ ! -d "$SB/storage/logs" ] && [ "$(mode "$SB/.env")" = "$_env_before" ]; then
    ok "dry-run creates nothing / changes nothing"
else
    bad "dry-run modified the filesystem"
fi

# ── 5. Apply ────────────────────────────────────────────────────────────────
bash "$SCRIPT" --apply --root "$SB" --user "$CUR_USER" --group "$CUR_GROUP" >/dev/null 2>&1

# 5a. writable dirs created
_missing=0
for d in storage/logs storage/cache storage/tmp storage/backups public/uploads \
         public/uploads/copertine public/uploads/archives/covers data/dewey \
         writable/uploads locale storage/uploads/plugins; do
    [ -d "$SB/$d" ] || { _missing=1; echo "      missing: $d"; }
done
[ "$_missing" -eq 0 ] && ok "all writable data directories created" || bad "some writable dirs missing"

# 5b. code file → 644, not executable
m="$(mode "$SB/app/Support/Foo.php")"
[ "$m" = "644" ] && ok "code file is 0644 ($m)" || bad "code file mode is $m (want 644)"

# 5c. shell script keeps its execute bit
m="$(mode "$SB/bin/tool.sh")"
case "$m" in 7??|75?|55?) ok "shell script stays executable ($m)";; *) bad "tool.sh lost +x (mode $m)";; esac

# 5d. .env is not group/other writable and not world-readable (0640)
m="$(mode "$SB/.env")"
[ "$m" = "640" ] && ok ".env is 0640 (secret-safe)" || bad ".env mode is $m (want 640)"

# 5e. data dir is group-writable (0775)
m="$(mode "$SB/storage")"
[ "$m" = "775" ] || [ "$m" = "2775" ] && ok "storage is group-writable ($m)" || bad "storage mode is $m (want 775/2775)"

# 5f. NOTHING is world-writable (no 777, no o+w anywhere)
_ww="$(find "$SB" -perm -0002 2>/dev/null | grep -v '/storage/sessions' | head -1)"
[ -z "$_ww" ] && ok "nothing is world-writable (no 777)" || bad "world-writable path found: $_ww"

# 5g. ownership set to the target user (chown path exercised)
_own="$(ls -ld "$SB/storage" | awk '{print $3}')"
[ "$_own" = "$CUR_USER" ] && ok "chown applied (storage owned by $CUR_USER)" || bad "storage owner is $_own (want $CUR_USER)"

# ── 6. Idempotency: a second apply succeeds and keeps the modes ─────────────
bash "$SCRIPT" --apply --root "$SB" --user "$CUR_USER" --group "$CUR_GROUP" >/dev/null 2>&1
rc=$?
m1="$(mode "$SB/app/Support/Foo.php")"; m2="$(mode "$SB/.env")"
if [ "$rc" -eq 0 ] && [ "$m1" = "644" ] && [ "$m2" = "640" ]; then
    ok "idempotent (second apply OK, modes stable)"
else
    bad "second apply changed something (rc=$rc Foo=$m1 env=$m2)"
fi

# ── Cleanup + verdict ───────────────────────────────────────────────────────
rm -rf "$SB"
echo ""
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] && { echo "ALL PASS"; exit 0; } || { echo "FAILURES"; exit 1; }
