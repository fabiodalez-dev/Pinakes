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
# Exit 0 iff every BLOCKING check passes (npm audit + the soft-delete grep are
# advisory, mirroring the CI's continue-on-error / warn-only behaviour).
#
# DB: the standalone unit tests need MySQL. Defaults below match the canonical
# local dev DB (MySQL on the Homebrew socket). Override via the E2E_DB_* env.

set -uo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" || exit 2

export E2E_DB_SOCKET="${E2E_DB_SOCKET:-/opt/homebrew/var/mysql/mysql.sock}"
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3306}"
export DB_USER="${DB_USER:-fabiodal_biblioteca_user}"
export DB_PASSWORD="${DB_PASSWORD:-Zd10)uwziWlK}"
export DB_NAME="${DB_NAME:-fabiodal_biblioteca}"
# The .unit.php tests read DB_USER/DB_PASS/DB_NAME (and E2E_DB_*).
export DB_PASS="${DB_PASS:-$DB_PASSWORD}"

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
if "$PHPSTAN" analyse --no-progress --memory-limit=512M >/tmp/ciq_phpstan.log 2>&1; then
  ok "no errors"
else
  bad "PHPStan errors:"; tail -20 /tmp/ciq_phpstan.log | sed 's/^/    /'
fi

# 2 ── composer audit ─────────────────────────────────────────────────────────
step "composer audit (known CVEs)"
if composer audit --no-dev --abandoned=ignore >/tmp/ciq_composer.log 2>&1; then
  ok "no known vulnerabilities"
else
  bad "composer audit found vulnerabilities:"; tail -15 /tmp/ciq_composer.log | sed 's/^/    /'
fi

# 3 ── npm audit (advisory — CI uses continue-on-error) ──────────────────────
step "npm audit (advisory)"
if [ -f package-lock.json ] && command -v npm >/dev/null; then
  if npm audit --audit-level=high >/tmp/ciq_npm.log 2>&1; then
    ok "no high/critical advisories"
  else
    warn "npm audit reported high/critical advisories (non-blocking, as in CI)"
  fi
else
  warn "skipped (no package-lock.json or npm)"
fi

# 4 ── Translation key parity (en_US ↔ de_DE) + placeholder parity ───────────
step "Translation key + placeholder parity"
tp=0
MISSING=$(comm -23 <(jq -r 'keys[]' locale/en_US.json | sort) <(jq -r 'keys[]' locale/de_DE.json | sort))
EXTRA=$(comm -13 <(jq -r 'keys[]' locale/en_US.json | sort) <(jq -r 'keys[]' locale/de_DE.json | sort))
[ -n "$MISSING" ] && { bad "de_DE.json misses keys present in en_US.json:"; echo "$MISSING" | sed 's/^/      /'; tp=1; }
[ -n "$EXTRA" ]   && { bad "de_DE.json has extra keys not in en_US.json:";   echo "$EXTRA"   | sed 's/^/      /'; tp=1; }
python3 - <<'PYEOF' || tp=1
import json, re, sys
en = json.load(open('locale/en_US.json')); de = json.load(open('locale/de_DE.json')); it = json.load(open('locale/it_IT.json'))
ph = re.compile(r'%(?:\d+\$)?[sd]'); failed = 0
for name, data in [('de_DE', de), ('it_IT', it)]:
    for k in en:
        if k not in data: continue
        if sorted(ph.findall(str(en[k]))) != sorted(ph.findall(str(data[k]))):
            print(f'      placeholder mismatch "{k[:70]}" ({name})'); failed = 1
sys.exit(failed)
PYEOF
[ "$tp" -eq 0 ] && ok "key + placeholder parity confirmed" || FAILED=1

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
[ -n "$VIOL" ] && { bad "dynamically-built Tailwind classes:"; echo "$VIOL" | sed 's/^/      /'; } || ok "no dynamic Tailwind classes"

# 7 ── Plugin ensureSchema() rule ─────────────────────────────────────────────
step "Plugin ensureSchema() rule"
es=0
for file in storage/plugins/*/*.php; do
  [ -f "$file" ] || continue
  grep -q 'CREATE TABLE' "$file" || continue
  name="$(basename "$(dirname "$file")")/$(basename "$file")"
  if ! grep -q 'ensureSchema()' "$file"; then bad "$name: CREATE TABLE without ensureSchema()"; es=1
  elif ! awk '/function onActivate/,/^[[:space:]]*}/' "$file" | grep -q 'ensureSchema'; then bad "$name: ensureSchema() not in onActivate()"; es=1
  elif ! awk '/function onInstall/,/^[[:space:]]*}/' "$file" | grep -q 'ensureSchema'; then bad "$name: ensureSchema() not in onInstall()"; es=1
  fi
done
[ "$es" -eq 0 ] && ok "every table-creating plugin calls ensureSchema() in onActivate()+onInstall()"

# 8 ── Soft-delete guard (advisory — heuristic grep, false positives possible) ─
step "Soft-delete guard (libri queries include deleted_at IS NULL)"
sd=0
for file in $(grep -rliE 'FROM[[:space:]]+`?libri`?[[:space:],)]' app/ 2>/dev/null); do
  grep -qiE 'deleted_at[[:space:]]+IS[[:space:]]+NULL' "$file" || { warn "$file: a FROM libri query without deleted_at IS NULL (verify manually)"; sd=1; }
done
[ "$sd" -eq 0 ] && ok "no libri query missing deleted_at IS NULL"

# 9 ── Autoloader phpstan-free ────────────────────────────────────────────────
step "Autoloader phpstan-free"
if [ -f vendor/composer/autoload_static.php ]; then
  # grep -c prints "0" on no match but exits 1 — the `|| true` keeps that "0"
  # without appending a second one (the old `|| echo 0` produced "0\n0").
  C=$(grep -c "phpstan" vendor/composer/autoload_static.php 2>/dev/null || true)
  [ "${C:-0}" -eq 0 ] && ok "no phpstan references in the autoloader" || bad "autoload_static.php has $C phpstan refs (composer install --no-dev)"
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
step "PHP unit tests (standalone .unit.php)"
ut=0; utc=0; utf=""
for t in tests/*.unit.php; do
  utc=$((utc+1))
  php "$t" >/dev/null 2>&1 || { utf="$utf ${t##*/}"; ut=1; }
done
[ "$ut" -eq 0 ] && ok "$utc/$utc unit tests green" || bad "unit test failures:$utf"

# 12 ── Shell test — permissions ─────────────────────────────────────────────
step "Shell test — setup-permissions"
if [ -f tests/setup-permissions.test.sh ]; then
  bash tests/setup-permissions.test.sh >/tmp/ciq_perm.log 2>&1 && ok "setup-permissions ok" || { bad "setup-permissions failed:"; tail -10 /tmp/ciq_perm.log | sed 's/^/    /'; }
else
  warn "tests/setup-permissions.test.sh absent"
fi

echo
if [ "$FAILED" -eq 0 ]; then
  printf "${G}============================================${N}\n"
  printf "${G}✅ CI QUALITY MIRROR PASSED — safe to push${N}\n"
  printf "${G}============================================${N}\n"
  exit 0
else
  printf "${R}============================================${N}\n"
  printf "${R}❌ CI QUALITY MIRROR FAILED — fix before push${N}\n"
  printf "${R}============================================${N}\n"
  exit 1
fi
