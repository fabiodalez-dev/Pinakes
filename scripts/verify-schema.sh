#!/usr/bin/env bash
#
# verify-schema.sh — MANDATORY schema/migration gate for every release.
#
# Runs the behavioural migration + plugin-schema test suite against the DB in
# .env (or E2E_DB_* env). It proves, before a release ships:
#   • migration-0.7.31.unit.php  — the release migration adds/backfills exactly
#     the columns/indexes it claims, over OLD-schema sandboxes, idempotently.
#   • plugin-schema-guard.unit.php — every bundled plugin's expectedTables() is
#     an EXACT subset of what its ensureSchema() creates (no missing table left
#     un-healed, no stale entry that would make the boot self-heal thrash), and
#     ensureSchema is idempotent.
#   • plugin-schema-selfheal.unit.php — an already-active plugin whose version
#     is at the target but a table is missing (a partial/aborted upgrade — the
#     Uwe #138 permanent-500 bug) SELF-HEALS on the next boot sync. This test
#     FAILS on the pre-fix code on purpose: a schema test that can only pass is
#     worthless.
#
# Exit non-zero if ANY of them fails. Wired into scripts/reinstall-test.sh and
# meant to be run by hand before scripts/create-release.sh.
#
# Usage:
#   bash scripts/verify-schema.sh
#   (reads DB creds from .env; override with E2E_DB_USER / E2E_DB_PASS /
#    E2E_DB_NAME / E2E_DB_SOCKET or E2E_DB_HOST+E2E_DB_PORT)

set -uo pipefail
cd "$(dirname "$0")/.."

PHP="${PHP_BIN:-php}"
TESTS=(
  tests/migration-0.7.31.unit.php
  tests/plugin-schema-guard.unit.php
  tests/plugin-schema-selfheal.unit.php
)

echo "════════════════════════════════════════════════════════════"
echo " Schema / migration verification gate"
echo "════════════════════════════════════════════════════════════"

fail=0
for t in "${TESTS[@]}"; do
  if [[ ! -f "$t" ]]; then
    echo "✗ MISSING test file: $t"
    fail=1
    continue
  fi
  echo ""
  echo "▶ $t"
  if out="$("$PHP" "$t" 2>&1)"; then
    # A test may SKIP cleanly (no DB) — treat that as non-fatal but visible.
    if grep -q "^SKIP:" <<<"$out"; then
      echo "  ⚠ SKIPPED: $(grep '^SKIP:' <<<"$out" | head -1)"
    else
      echo "  ✓ $(grep -E '^ALL [0-9]+ PASS' <<<"$out" | tail -1)"
    fi
  else
    echo "$out" | tail -20
    echo "  ✗ FAILED: $t"
    fail=1
  fi
done

echo ""
echo "════════════════════════════════════════════════════════════"
if [[ $fail -eq 0 ]]; then
  echo " ✅ SCHEMA GATE PASSED"
  echo "════════════════════════════════════════════════════════════"
  exit 0
else
  echo " ❌ SCHEMA GATE FAILED — do NOT release"
  echo "════════════════════════════════════════════════════════════"
  exit 1
fi
