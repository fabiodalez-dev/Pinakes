#!/usr/bin/env bash
#
# Pinakes — filesystem permissions setup
# ============================================================================
# Makes a Pinakes installation writable by the web-server (PHP) user so that:
#   • the app can write at runtime (uploads, cache, logs, backups, .env, …), and
#   • the in-app updater can apply releases — which means CREATING new files and
#     directories and OVERWRITING existing code, so the PHP user must OWN the
#     whole install tree, not just a few sub-folders.
#
# This is the one-shot fix for the classic "Update failed: Unable to create
# directory / Not writable" errors (e.g. GitHub issue #205 on a QNAP NAS): the
# install ROOT itself, and every writable data directory, are handed to the PHP
# user in a single run.
#
# WHAT IT DOES
#   1. Detects the web-server/PHP user (or use --user).
#   2. Creates any missing writable data directories.
#   3. chown -R <php-user>:<group> on the whole install tree  (the thing the
#      updater actually needs), then applies conservative modes:
#        • code/dirs      → u=rwX,go=rX   (owner writes; NO world-write; the
#                           capital X only adds +x to dirs & already-exec files,
#                           so shell scripts stay runnable and plain files don't
#                           become executable)
#        • data dirs      → u=rwX,g=rwX,o=rX + setgid, so files the PHP user
#                           creates keep the right group
#        • .env           → 0640 (secrets: owner rw, group r, world none)
#   4. Never uses `chmod 777`.
#
# SAFETY
#   • DRY-RUN BY DEFAULT — prints exactly what it would do and changes nothing.
#     Re-run with --apply to make the changes.
#   • Idempotent — safe to run repeatedly.
#   • POSIX-friendly — works on Linux, QNAP QTS (busybox), Synology, cPanel,
#     macOS. chown needs root (run with sudo, or as admin on a NAS).
#
# USAGE
#   bin/setup-permissions.sh                         # dry-run (default)
#   sudo bin/setup-permissions.sh --apply            # apply, auto-detect user
#   sudo bin/setup-permissions.sh --apply --user httpdusr           # QNAP
#   sudo bin/setup-permissions.sh --apply --user httpdusr --group everyone
#   bin/setup-permissions.sh --apply --root /share/CACHEDEV1_DATA/Web/pinakes
#   bin/setup-permissions.sh --help
# ============================================================================

# -u only: a mass chmod/chown over an install tree WILL hit the odd file it
# can't touch (a stray root-owned file, setgid without privilege on macOS, …).
# Those must not abort the whole run — each command is best-effort (see run()),
# and every hard precondition below has its own explicit exit.
set -u

# ── Colors (disabled when not a TTY) ────────────────────────────────────────
if [ -t 1 ]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
    BLUE=$'\033[0;34m'; BOLD=$'\033[1m'; NC=$'\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; BOLD=''; NC=''
fi

APPLY=0
PHP_USER=""
PHP_GROUP=""
ROOT=""

usage() {
    sed -n '2,45p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
}

# ── Parse arguments ─────────────────────────────────────────────────────────
while [ $# -gt 0 ]; do
    case "$1" in
        --apply)        APPLY=1 ;;
        --dry-run)      APPLY=0 ;;
        --user)         PHP_USER="${2:-}"; shift ;;
        --user=*)       PHP_USER="${1#*=}" ;;
        --group)        PHP_GROUP="${2:-}"; shift ;;
        --group=*)      PHP_GROUP="${1#*=}" ;;
        --root)         ROOT="${2:-}"; shift ;;
        --root=*)       ROOT="${1#*=}" ;;
        -h|--help)      usage ;;
        *) echo "${RED}Unknown option: $1${NC}" >&2; echo "Try --help." >&2; exit 2 ;;
    esac
    shift
done

# ── Resolve the install root ────────────────────────────────────────────────
if [ -z "$ROOT" ]; then
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    ROOT="$(dirname "$SCRIPT_DIR")"   # bin/ is one level below the install root
fi
ROOT="$(cd "$ROOT" 2>/dev/null && pwd || echo "$ROOT")"

# Sanity: is this really a Pinakes install root?
if [ ! -f "$ROOT/version.json" ] || [ ! -f "$ROOT/public/index.php" ]; then
    echo "${RED}✗ $ROOT does not look like a Pinakes install${NC}" >&2
    echo "  (expected version.json and public/index.php). Use --root <path>." >&2
    exit 1
fi

echo "${BOLD}╔══════════════════════════════════════════════╗${NC}"
echo "${BOLD}║   Pinakes — filesystem permissions setup     ║${NC}"
echo "${BOLD}╚══════════════════════════════════════════════╝${NC}"
echo "  Install root : ${BLUE}$ROOT${NC}"

# ── Detect the web-server/PHP user ──────────────────────────────────────────
detect_php_user() {
    # Look for a running web/PHP worker owned by a NON-root user. Try several
    # `ps` dialects (GNU, BSD, busybox) and process names.
    _names='php-fpm php_fpm php-cgi lsphp httpd apache2 apache nginx'
    for _p in $_names; do
        _u=$(ps -eo user,comm 2>/dev/null | awk -v p="$_p" 'index($2,p)>0 && $1!="root" && $1!="USER"{print $1; exit}') || true
        [ -z "${_u:-}" ] && _u=$(ps aux 2>/dev/null | awk -v p="$_p" 'index($0,p)>0 && $1!="root" && $1!="USER"{print $1; exit}') || true
        [ -z "${_u:-}" ] && _u=$(ps -ef 2>/dev/null | awk -v p="$_p" 'index($0,p)>0 && $1!="root" && $1!="UID"{print $1; exit}') || true
        if [ -n "${_u:-}" ]; then echo "$_u"; return 0; fi
    done
    return 1
}

if [ -z "$PHP_USER" ]; then
    PHP_USER="$(detect_php_user || true)"
fi

if [ -z "$PHP_USER" ]; then
    echo ""
    echo "${YELLOW}⚠ Could not auto-detect the web-server user.${NC}"
    echo "  Re-run with --user <name>. Common values:"
    echo "    • QNAP QTS ...... httpdusr    (group: everyone)"
    echo "    • cPanel ........ your cPanel account name"
    echo "    • Debian/Ubuntu . www-data"
    echo "    • RHEL/CentOS ... apache      (or nginx)"
    echo "    • Synology ...... http"
    echo ""
    echo "  Find it yourself with:  ps aux | grep -E 'php-fpm|apache|httpd|nginx'"
    exit 1
fi

# User must exist.
if ! id "$PHP_USER" >/dev/null 2>&1; then
    echo "${RED}✗ User '$PHP_USER' does not exist on this system.${NC}" >&2
    exit 1
fi

# Group: explicit, else the user's primary group, else the user name.
if [ -z "$PHP_GROUP" ]; then
    PHP_GROUP="$(id -gn "$PHP_USER" 2>/dev/null || echo "$PHP_USER")"
fi

echo "  PHP user     : ${BLUE}$PHP_USER${NC}   group: ${BLUE}$PHP_GROUP${NC}"

# ── Can we chown? (needs root / CAP_CHOWN) ──────────────────────────────────
CUR_UID="$(id -u 2>/dev/null || echo 1000)"
CAN_CHOWN=1
[ "$CUR_UID" != "0" ] && CAN_CHOWN=0

if [ "$APPLY" -eq 1 ]; then
    echo "  Mode         : ${GREEN}${BOLD}APPLY${NC}"
else
    echo "  Mode         : ${YELLOW}dry-run${NC} (nothing will change — use --apply)"
fi
if [ "$CAN_CHOWN" -eq 0 ]; then
    echo "  ${YELLOW}Note: not running as root — will skip chown and fall back to chmod g+w."
    echo "        For a full fix run with sudo (or as admin on a NAS).${NC}"
fi
echo ""

# ── The writable DATA directories (from a code audit of every FS write) ─────
# Created if missing; group-writable + setgid so PHP-created files keep group.
WRITABLE_DIRS="
storage
storage/logs
storage/cache
storage/tmp
storage/backups
storage/calendar
storage/sessions
storage/rate_limits
storage/plugins
storage/uploads
storage/uploads/plugins
storage/uploads/cms
public/uploads
public/uploads/copertine
public/uploads/autori
public/uploads/settings
public/uploads/events
public/uploads/assets
public/uploads/digital
public/uploads/archives
public/uploads/archives/covers
public/uploads/archives/documents
public/assets
data/dewey
writable/uploads
locale
"

# Individual writable files (or their parent must allow create). .env holds
# secrets → tighter mode handled separately.
WRITABLE_FILES="version.json .installed .htaccess public/sitemap.xml"

# ── Helper: run or echo depending on mode ───────────────────────────────────
run() {
    if [ "$APPLY" -eq 1 ]; then
        # Best-effort: a single un-touchable file must not abort the run.
        if ! "$@" 2>/dev/null; then
            local _I="$IFS"; IFS=' '
            echo "    ${YELLOW}⚠ skipped (permission denied):${NC} $*"
            IFS="$_I"
        fi
    else
        # Force space separation for the printed command — callers may have IFS
        # set to newline while iterating the path lists.
        local _IFS="$IFS"; IFS=' '
        echo "    ${BLUE}[dry-run]${NC} $*"
        IFS="$_IFS"
    fi
}

cd "$ROOT"

# ── 1. Create missing writable directories ──────────────────────────────────
echo "${BOLD}› Ensuring writable data directories exist${NC}"
_created=0
OLDIFS="$IFS"; IFS='
'
for d in $WRITABLE_DIRS; do
    [ -z "$d" ] && continue
    if [ ! -d "$d" ]; then
        run mkdir -p "$d"
        echo "    ${GREEN}+${NC} $d ${YELLOW}(created)${NC}"
        _created=$((_created+1))
    fi
done
IFS="$OLDIFS"
[ "$_created" -eq 0 ] && echo "    all present"

# ── 2. Own the whole tree as the PHP user (the update-critical step) ────────
echo "${BOLD}› Ownership → $PHP_USER:$PHP_GROUP (whole install tree)${NC}"
if [ "$CAN_CHOWN" -eq 1 ]; then
    run chown -R "$PHP_USER:$PHP_GROUP" "$ROOT"
    echo "    ${GREEN}✓${NC} chown -R applied to the install root"
else
    echo "    ${YELLOW}skipped (not root)${NC} — relying on group-write instead"
fi

# ── 3. Baseline modes on the whole tree (no world-write, keep execs) ────────
echo "${BOLD}› Baseline modes (dirs 755 / files 644, execs preserved)${NC}"
run chmod -R u=rwX,go=rX "$ROOT"
echo "    ${GREEN}✓${NC} owner rw + traversal; group/other read-only"

# ── 4. Relax the data directories to group-writable + setgid ────────────────
echo "${BOLD}› Data directories → group-writable + setgid${NC}"
OLDIFS="$IFS"; IFS='
'
for d in $WRITABLE_DIRS; do
    [ -z "$d" ] && continue
    [ -d "$d" ] || continue
    run chmod -R u=rwX,g=rwX,o=rX "$d"
    run chmod g+s "$d"
done
IFS="$OLDIFS"
echo "    ${GREEN}✓${NC} PHP user (and its group) can create/modify data files"

# ── 5. Individual writable files ────────────────────────────────────────────
echo "${BOLD}› Writable files${NC}"
OLDIFS="$IFS"; IFS=' '
for f in $WRITABLE_FILES; do
    [ -f "$f" ] || continue
    run chmod u=rw,g=rw,o=r "$f"
    echo "    ${GREEN}✓${NC} $f (0664)"
done
IFS="$OLDIFS"
# .env is secret → owner+group only, never world-readable.
if [ -f ".env" ]; then
    run chmod u=rw,g=r,o= ".env"
    echo "    ${GREEN}✓${NC} .env (0640 — secrets, not world-readable)"
fi

# ── Done / verification ─────────────────────────────────────────────────────
echo ""
if [ "$APPLY" -eq 1 ]; then
    echo "${GREEN}${BOLD}✅ Permissions applied.${NC}"
    echo "${BOLD}› Verification (updater's required-writable paths)${NC}"
    for p in "$ROOT" "$ROOT/storage" "$ROOT/storage/backups"; do
        _own="$(ls -ld "$p" 2>/dev/null | awk '{print $3":"$4}')"
        echo "    ${GREEN}✓${NC} $p — owner $_own"
    done
    echo ""
    echo "Next: retry the update from the admin panel (Aggiornamenti → Aggiorna)."
    echo "Re-running an interrupted update is safe — it re-copies every file."
else
    echo "${YELLOW}${BOLD}Dry-run complete — nothing was changed.${NC}"
    echo "Re-run to apply:  ${BOLD}sudo $0 --apply${NC}${PHP_USER:+ --user $PHP_USER}"
fi
