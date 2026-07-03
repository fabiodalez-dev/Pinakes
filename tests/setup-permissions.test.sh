#!/usr/bin/env bash
#
# Test for bin/setup-permissions.sh
# ============================================================================
# Builds a throwaway mock install, runs the permissions script against it, and
# asserts the "grant, never reset" contract — including the two regressions
# from issue #205 that an earlier, destructive version caused on a Docker
# install: it must NOT switch the group when none was asked for, and it must
# NOT strip .env's existing readers.
#
# Runs WITHOUT root: chowns to the CURRENT user (needs no privilege), so the
# chown path is exercised. Everything else is checked directly.
#
# Usage:  tests/setup-permissions.test.sh   → exit 0 iff every assertion passes.
# ============================================================================

set -u

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCRIPT="$REPO_ROOT/bin/setup-permissions.sh"
PASS=0; FAIL=0
ok()  { echo "  [OK]  $1"; PASS=$((PASS+1)); }
bad() { echo "  [!!]  $1"; FAIL=$((FAIL+1)); }
mode() { stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1" 2>/dev/null; }
grp()  { ls -ld "$1" 2>/dev/null | awk '{print $4}'; }

CUR_USER="$(id -un)"

# ── Build a mock install ────────────────────────────────────────────────────
SB="$(mktemp -d 2>/dev/null || echo /tmp/pinakes-perm-$$)"
mkdir -p "$SB/bin" "$SB/public" "$SB/app/Support" "$SB/storage/backups" \
         "$SB/storage/tmp" "$SB/vendor"
cp "$SCRIPT" "$SB/bin/setup-permissions.sh"; chmod +x "$SB/bin/setup-permissions.sh"
echo '{"version":"0.7.27"}'      > "$SB/version.json"
echo '<?php'                      > "$SB/public/index.php"
echo '<?php class Foo {}'         > "$SB/app/Support/Foo.php"
printf '#!/bin/sh\necho hi\n'     > "$SB/bin/tool.sh"; chmod 755 "$SB/bin/tool.sh"
# .env world-readable (0644) — the state a working Docker install had before
# the destructive version locked it to 0640 and broke PHP (#205).
echo 'DB_PASS=secret'             > "$SB/.env"; chmod 644 "$SB/.env"
_env_grp_before="$(grp "$SB/.env")"

echo "── Test: bin/setup-permissions.sh (sandbox: $SB) ──"

# ── 1. Syntax ───────────────────────────────────────────────────────────────
bash -n "$SCRIPT" && ok "script parses (bash -n)" || bad "syntax error"

# ── 2. Guards ───────────────────────────────────────────────────────────────
BADDIR="$(mktemp -d 2>/dev/null || echo /tmp/pinakes-nope-$$)"
bash "$SCRIPT" --root "$BADDIR" >/dev/null 2>&1 && bad "should reject non-install dir" || ok "rejects a non-install directory"
rm -rf "$BADDIR"
bash "$SCRIPT" --root "$SB" --user __no_such_user__ >/dev/null 2>&1 && bad "should reject unknown --user" || ok "rejects a non-existent user"

# ── 3. Dry-run changes nothing ──────────────────────────────────────────────
_before="$(mode "$SB/.env")"
bash "$SCRIPT" --root "$SB" --user "$CUR_USER" >/dev/null 2>&1
[ ! -d "$SB/storage/logs" ] && [ "$(mode "$SB/.env")" = "$_before" ] && ok "dry-run changes nothing" || bad "dry-run modified the filesystem"

# ── 4. Apply (no --group → group preserved) ─────────────────────────────────
bash "$SCRIPT" --apply --root "$SB" --user "$CUR_USER" >/dev/null 2>&1

_missing=0
for d in storage/logs storage/cache storage/tmp storage/backups public/uploads \
         public/uploads/archives/covers data/dewey writable/uploads locale \
         storage/uploads/plugins; do
    [ -d "$SB/$d" ] || { _missing=1; echo "      missing: $d"; }
done
[ "$_missing" -eq 0 ] && ok "all writable data directories created" || bad "some writable dirs missing"

# 4a. code file still readable by owner, no +x added
m="$(mode "$SB/app/Support/Foo.php")"
case "$m" in 6??|644|664) ok "code file readable, not executable ($m)";; *) bad "code file mode is $m";; esac

# 4b. shell script keeps +x
m="$(mode "$SB/bin/tool.sh")"; case "$m" in 7??|75?|55?) ok "shell script stays executable ($m)";; *) bad "tool.sh lost +x ($m)";; esac

# 4c. REGRESSION #205: .env's world-read must NOT be stripped
m="$(mode "$SB/.env")"
case "$m" in *4|*5|*6|*7) ok ".env keeps its existing readers ($m, not locked to 640)";; *) bad ".env readers stripped: mode $m (regression #205)";; esac
# and .env must still be world-readable specifically (it was 644 before)
if ls -ld "$SB/.env" | cut -c8 | grep -q 'r'; then ok ".env still world-readable (grant, not reset)"; else bad ".env lost world-read (#205 regression)"; fi

# 4d. REGRESSION #205: group preserved when --group not given
[ "$(grp "$SB/.env")" = "$_env_grp_before" ] && ok "group preserved (not switched)" || bad "group was changed without --group (regression #205)"

# 4e. data dirs group-writable — BOTH tmp and backups (IFS split-bug regression)
for d in storage/tmp storage/backups public/uploads; do
    m="$(mode "$SB/$d")"
    case "$m" in *7?|*6?|2*7?|2*6?) ok "$d group-writable ($m)";; *) bad "$d not group-writable: $m";; esac
done

# 4f. nothing world-writable (no 777)
_ww="$(find "$SB" -perm -0002 2>/dev/null | grep -v '/storage/sessions' | head -1)"
[ -z "$_ww" ] && ok "nothing world-writable (no 777)" || bad "world-writable path: $_ww"

# 4g. chown applied
[ "$(ls -ld "$SB/storage" | awk '{print $3}')" = "$CUR_USER" ] && ok "chown applied (storage owned by $CUR_USER)" || bad "storage owner wrong"

# ── 5. --from-container guard (docker absent → clear error, no crash) ────────
if ! command -v docker >/dev/null 2>&1; then
    bash "$SCRIPT" --root "$SB" --from-container whatever >/dev/null 2>&1 \
        && bad "--from-container should fail when docker is absent" \
        || ok "--from-container errors cleanly without docker"
else
    ok "--from-container guard (docker present — skipped)"
fi

# ── 6. Idempotency ──────────────────────────────────────────────────────────
bash "$SCRIPT" --apply --root "$SB" --user "$CUR_USER" >/dev/null 2>&1; rc=$?
m1="$(mode "$SB/app/Support/Foo.php")"
[ "$rc" -eq 0 ] && ok "idempotent (second apply OK)" || bad "second apply failed (rc=$rc)"

rm -rf "$SB"
echo ""
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] && { echo "ALL PASS"; exit 0; } || { echo "FAILURES"; exit 1; }
