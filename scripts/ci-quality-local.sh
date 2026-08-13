#!/usr/bin/env bash
#
# ci-quality-local.sh — run the GitHub "Code Quality" job (.github/workflows/
# ci-quality.yml) locally, so a red CI is caught BEFORE pushing.
#
# reinstall-test.sh + verify-schema.sh cover E2E + schema/plugin gates but NOT
# these static checks, the migration-version guard, or the standalone
# tests/*.unit.php (which include the meta-guard that requires a
# tests/migration-<version>.unit.php for the release migration). Run this before
# every push to a PR branch and before every release.
#
# Usage:  bash scripts/ci-quality-local.sh
# Exit 0 iff every check passes. Dependency audits, schema verification,
# soft-delete regressions and skipped unit tests are all fatal.
#
# DB: the standalone unit tests need MySQL. Credentials come from the gitignored
# .env (same source the .unit.php tests use) — NEVER hardcode a password here.
# Override any of them via the environment before invoking the script.

set -uo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" || exit 2

CIQ_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/pinakes-ci-quality.XXXXXX")" || exit 2
readonly CIQ_TMP_DIR
cleanup_ciq_tmp() { rm -rf -- "$CIQ_TMP_DIR"; }
trap cleanup_ciq_tmp EXIT HUP INT TERM

# Load DB_* from .env (gitignored) unless already set in the environment.
if [ -f .env ]; then
  while IFS='=' read -r k v; do
    v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
    [ -n "${!k:-}" ] || export "$k=$v"
  done < <(grep -E '^(DB_HOST|DB_PORT|DB_USER|DB_PASS|DB_PASSWORD|DB_NAME|DB_SOCKET)=' .env 2>/dev/null)
fi
# Only non-secret fallbacks (socket path/host/port) — no password default.
export E2E_DB_SOCKET="${E2E_DB_SOCKET:-${DB_SOCKET:-/opt/homebrew/var/mysql/mysql.sock}}"
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3306}"
export DB_PASS="${DB_PASS:-${DB_PASSWORD:-}}"

PHPSTAN="${PHPSTAN:-$HOME/.composer/vendor/bin/phpstan}"
[ -x "$PHPSTAN" ] || PHPSTAN="phpstan"

G="\033[0;32m"; R="\033[0;31m"; Y="\033[1;33m"; B="\033[1;34m"; N="\033[0m"
FAILED=0
step() { printf "${B}▶ %s${N}\n" "$1"; }
ok()   { printf "  ${G}✓${N} %s\n" "$1"; }
bad()  { printf "  ${R}✗${N} %s\n" "$1"; FAILED=1; }
warn() { printf "  ${Y}⚠${N} %s\n" "$1"; }

# 1 ── PHPStan (level 5) ──────────────────────────────────────────────────────
step "PHPStan (level 5, full tree)"
if "$PHPSTAN" analyse --no-progress --memory-limit=512M >"$CIQ_TMP_DIR/phpstan.log" 2>&1; then
  ok "no errors"
else
  bad "PHPStan errors:"; tail -20 "$CIQ_TMP_DIR/phpstan.log" | sed 's/^/    /'
fi

# 2 ── composer audit ─────────────────────────────────────────────────────────
step "composer audit (known CVEs)"
if composer audit --no-dev --abandoned=ignore >"$CIQ_TMP_DIR/composer.log" 2>&1; then
  ok "no known vulnerabilities"
else
  bad "composer audit found vulnerabilities:"; tail -15 "$CIQ_TMP_DIR/composer.log" | sed 's/^/    /'
fi

# 3 ── npm audit ─────────────────────────────────────────────────────────────
step "root npm audit (blocking)"
if ! command -v npm >/dev/null; then
  bad "npm is required"
elif [ ! -f package-lock.json ]; then
  bad "package-lock.json is required; root dependency audit was not run"
elif npm audit --audit-level=high >"$CIQ_TMP_DIR/npm-root.log" 2>&1; then
  ok "no high/critical advisories in root dependencies"
else
  bad "root npm audit failed or reported high/critical advisories"
  tail -20 "$CIQ_TMP_DIR/npm-root.log" | sed 's/^/    /'
fi

step "frontend npm audit (blocking)"
if ! command -v npm >/dev/null; then
  bad "npm is required"
elif [ ! -f frontend/package-lock.json ]; then
  bad "frontend/package-lock.json is required; frontend dependency audit was not run"
elif npm --prefix frontend audit --audit-level=high >"$CIQ_TMP_DIR/npm-frontend.log" 2>&1; then
  ok "no high/critical advisories in frontend dependencies"
else
  bad "frontend npm audit failed or reported high/critical advisories"
  tail -20 "$CIQ_TMP_DIR/npm-frontend.log" | sed 's/^/    /'
fi

# 4 ── Translation, placeholder and route parity ─────────────────────────────
step "Translation and route key + placeholder parity"
if python3 scripts/ci-check-locales.py >"$CIQ_TMP_DIR/locales.log" 2>&1; then
  ok "translation keys, placeholders and routes are aligned"
else
  bad "locale parity failed:"; tail -30 "$CIQ_TMP_DIR/locales.log" | sed 's/^/    /'
fi

step "Playwright coverage policy + frontend lint"
if node scripts/ci-playwright-policy.js check >"$CIQ_TMP_DIR/policy.log" 2>&1 \
    && bash tests/release-source-policy.test.sh >"$CIQ_TMP_DIR/release-policy.log" 2>&1 \
    && npm --prefix frontend run lint >"$CIQ_TMP_DIR/frontend.log" 2>&1; then
  ok "browser specs classified; release policy tested; frontend lint clean"
else
  bad "CI policy, release policy or frontend lint failed"
  tail -20 "$CIQ_TMP_DIR/policy.log" "$CIQ_TMP_DIR/release-policy.log" \
    "$CIQ_TMP_DIR/frontend.log" 2>/dev/null | sed 's/^/    /'
fi

# 5 ── Route key integrity ────────────────────────────────────────────────────
step "Route key integrity (route_path() keys exist)"
ROUTE_KEYS=$(python3 -c "import json; [print(k) for k in json.load(open('locale/routes_it_IT.json')).keys()]" 2>/dev/null)
FALLBACK_KEYS=$(php -r "require 'vendor/autoload.php'; echo implode(PHP_EOL, App\\Support\\RouteTranslator::getStaticFallbackKeys());" 2>/dev/null)
ALL_KEYS=$(printf '%s\n%s' "$ROUTE_KEYS" "$FALLBACK_KEYS" | sort -u)
rk=0
while IFS= read -r key; do
  [ -z "$key" ] && continue
  echo "$ALL_KEYS" | grep -qx "$key" || { bad "route_path('$key') missing in routes_it_IT.json / fallbackRoutes"; rk=1; }
done < <(grep -rohE "route_path\('([^']+)'\)" app/Views/ 2>/dev/null | grep -oE "'[^']+'" | tr -d "'" | sort -u)
[ "$rk" -eq 0 ] && ok "all route_path() keys resolve"

# 6 ── Tailwind JIT — no dynamic class construction ───────────────────────────
step "Tailwind JIT — no dynamic class construction"
VIOL=$(grep -rn \
  -e "'bg-'\s*\.\s*\$" -e '"bg-"\s*\.\s*\$' \
  -e "'text-'\s*\.\s*\$" -e '"text-"\s*\.\s*\$' \
  -e "'border-'\s*\.\s*\$" -e '"border-"\s*\.\s*\$' \
  --include="*.php" app/ storage/plugins/ 2>/dev/null || true)
if [ -n "$VIOL" ]; then
  bad "dynamically-built Tailwind classes:"
  printf '      %s\n' "${VIOL//$'\n'/$'\n      '}"
else
  ok "no dynamic Tailwind classes"
fi

# 7 ── Plugin ensureSchema() rule ─────────────────────────────────────────────
step "Plugin ensureSchema() rule"
es=0
for file in storage/plugins/*/*.php; do
  [ -f "$file" ] || continue
  grep -q 'CREATE TABLE' "$file" || continue
  name="$(basename "$(dirname "$file")")/$(basename "$file")"
  if ! grep -q 'ensureSchema()' "$file"; then bad "$name: CREATE TABLE without ensureSchema()"; es=1
  elif ! awk '/function onActivate/,/^    }$/' "$file" | grep -q 'ensureSchema'; then bad "$name: ensureSchema() not in onActivate()"; es=1
  elif ! awk '/function onInstall/,/^    }$/' "$file" | grep -q 'ensureSchema'; then bad "$name: ensureSchema() not in onInstall()"; es=1
  fi
done
[ "$es" -eq 0 ] && ok "every table-creating plugin calls ensureSchema() in onActivate()+onInstall()"

# 8 ── Soft-delete guard ────────────────────────────────────────────────────────
step "Soft-delete guard (each libri query is guarded or statement-exempt)"
if python3 scripts/ci-check-soft-delete.py app storage/plugins installer; then
  ok "every libri query is guarded at statement scope"
else
  bad "one or more libri queries lack a statement-scoped soft-delete policy"
fi

# 9 ── Autoloader phpstan-free ────────────────────────────────────────────────
step "Autoloader phpstan-free"
if [ -f vendor/composer/autoload_static.php ]; then
  # grep -c prints "0" on no match but exits 1 — the `|| true` keeps that "0"
  # without appending a second one (the old `|| echo 0` produced "0\n0").
  C=$(grep -c "phpstan" vendor/composer/autoload_static.php 2>/dev/null || true)
  if [ "${C:-0}" -eq 0 ]; then
    ok "no phpstan references in the autoloader"
  else
    bad "autoload_static.php has $C phpstan refs (composer install --no-dev)"
  fi
else
  warn "vendor/composer/autoload_static.php absent"
fi

# 10 ── Migration version guard (all migrate_*.sql ≤ version.json) ────────────
step "Migration version guard"
TARGET=$(php -r "echo json_decode(file_get_contents('version.json'))->version;")
mg=0
for f in installer/database/migrations/migrate_*.sql; do
  V=$(basename "$f" | sed 's/migrate_//;s/\.sql//')
  php -r "exit(version_compare('$V','$TARGET','<=') ? 0 : 1);" || { bad "$(basename "$f"): $V > $TARGET (updater would skip it)"; mg=1; }
done
[ "$mg" -eq 0 ] && ok "every migrate_*.sql version ≤ $TARGET"

# 11 ── Standalone PHP unit tests (via EXIT CODE, like CI) ────────────────────
step "PHP unit tests (standalone .unit.php, strict no-skip mode)"
if CI_STRICT_TESTS=1 bash scripts/ci-run-unit-tests.sh >"$CIQ_TMP_DIR/units.log" 2>&1; then
  ok "all standalone unit tests passed without skips"
else
  bad "standalone unit test failures/skips:"; tail -30 "$CIQ_TMP_DIR/units.log" | sed 's/^/    /'
fi

# 12 ── Schema/migration behavioral gate ─────────────────────────────────────
step "Schema and migration behavioral gate (strict no-skip mode)"
if CI_STRICT_TESTS=1 bash scripts/verify-schema.sh >"$CIQ_TMP_DIR/schema.log" 2>&1; then
  ok "schema and migration behavior passed without skips"
else
  bad "schema/migration gate failed:"; tail -30 "$CIQ_TMP_DIR/schema.log" | sed 's/^/    /'
fi

# 13 ── Shell test — permissions ─────────────────────────────────────────────
step "Shell test — setup-permissions"
if [ -f tests/setup-permissions.test.sh ]; then
  if bash tests/setup-permissions.test.sh >"$CIQ_TMP_DIR/permissions.log" 2>&1; then
    ok "setup-permissions ok"
  else
    bad "setup-permissions failed:"
    tail -10 "$CIQ_TMP_DIR/permissions.log" | sed 's/^/    /'
  fi
else
  warn "tests/setup-permissions.test.sh absent"
fi

echo
if [ "$FAILED" -eq 0 ]; then
  printf "%s============================================%s\n" "$G" "$N"
  printf "%s✅ CI QUALITY MIRROR PASSED — safe to push%s\n" "$G" "$N"
  printf "%s============================================%s\n" "$G" "$N"
  exit 0
else
  printf "%s============================================%s\n" "$R" "$N"
  printf "%s❌ CI QUALITY MIRROR FAILED — fix before push%s\n" "$R" "$N"
  printf "%s============================================%s\n" "$R" "$N"
  exit 1
fi
