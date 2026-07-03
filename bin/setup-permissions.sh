#!/usr/bin/env bash
#
# Pinakes — filesystem permissions setup
# ============================================================================
# Grants the web-server (PHP) user the access it needs so the app runs and the
# in-app updater can apply releases. Works for ANY install layout — a normal
# host (Apache/nginx + PHP), shared hosting (cPanel), a NAS, or a Docker
# bind-mount where PHP runs inside the container.
#
# DESIGN: GRANT, NEVER RESET.
#   The script only ADDS the ownership and read/write bits that are missing.
#   It never strips existing access, never changes a group you didn't ask it
#   to change, and never touches `.env`'s existing readers. (An earlier version
#   reset modes and switched the group, which locked PHP out of a Docker
#   install — see issue #205. This one cannot do that.)
#
# CHOOSING THE OWNER
#   • Normal / NAS / cPanel:  the PHP user owns the files on the host.
#       --user www-data           (or httpdusr, apache, your cPanel account…)
#     Omit --user and it is auto-detected from the running web/PHP process.
#     Omit --group and the EXISTING group is preserved (not changed).
#   • Docker bind-mount: PHP runs INSIDE the container with its own uid/gid,
#     which is what must own the files on the host. Let the script read it
#     straight from the container:
#       --from-container pinakes         (your container name from `docker ps`)
#     It runs `docker exec … id` and chowns to that NUMERIC uid:gid, which is
#     the only thing that maps correctly across a bind-mount.
#
# SAFETY
#   • DRY-RUN BY DEFAULT — prints what it would do; --apply to make changes.
#   • Idempotent; best-effort (one un-touchable file won't abort the run).
#   • chown needs root (sudo / NAS admin). Without it, only chmod runs.
#
# USAGE
#   bin/setup-permissions.sh                              # dry-run, auto-detect
#   sudo bin/setup-permissions.sh --apply                 # normal host
#   sudo bin/setup-permissions.sh --apply --user www-data
#   sudo bin/setup-permissions.sh --apply --from-container pinakes   # Docker
#   sudo bin/setup-permissions.sh --apply --root /path/to/install --user httpdusr
#   bin/setup-permissions.sh --help
# ============================================================================

set -u

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
FROM_CONTAINER=""
GROUP_EXPLICIT=0

usage() { sed -n '2,45p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

while [ $# -gt 0 ]; do
    case "$1" in
        --apply)            APPLY=1 ;;
        --dry-run)          APPLY=0 ;;
        --user)             PHP_USER="${2:-}"; shift ;;
        --user=*)           PHP_USER="${1#*=}" ;;
        --group)            PHP_GROUP="${2:-}"; GROUP_EXPLICIT=1; shift ;;
        --group=*)          PHP_GROUP="${1#*=}"; GROUP_EXPLICIT=1 ;;
        --root)             ROOT="${2:-}"; shift ;;
        --root=*)           ROOT="${1#*=}" ;;
        --from-container)   FROM_CONTAINER="${2:-}"; shift ;;
        --from-container=*) FROM_CONTAINER="${1#*=}" ;;
        -h|--help)          usage ;;
        *) echo "${RED}Unknown option: $1${NC}" >&2; echo "Try --help." >&2; exit 2 ;;
    esac
    shift
done

# ── Resolve the install root ────────────────────────────────────────────────
if [ -z "$ROOT" ]; then
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    ROOT="$(dirname "$SCRIPT_DIR")"
fi
ROOT="$(cd "$ROOT" 2>/dev/null && pwd || echo "$ROOT")"

if [ ! -f "$ROOT/version.json" ] || [ ! -f "$ROOT/public/index.php" ]; then
    echo "${RED}✗ $ROOT does not look like a Pinakes install${NC}" >&2
    echo "  (expected version.json and public/index.php). Use --root <path>." >&2
    exit 1
fi

echo "${BOLD}╔══════════════════════════════════════════════╗${NC}"
echo "${BOLD}║   Pinakes — filesystem permissions setup     ║${NC}"
echo "${BOLD}╚══════════════════════════════════════════════╝${NC}"
echo "  Install root : ${BLUE}$ROOT${NC}"

# ── Determine the owner spec ────────────────────────────────────────────────
# OWNER_SPEC is what we hand to chown. GROUP_KNOWN=1 means we have a group we're
# allowed to grant group-write to; otherwise we preserve whatever group exists.
OWNER_SPEC=""
GROUP_KNOWN=0

if [ -n "$FROM_CONTAINER" ]; then
    # Docker: find the user the web/PHP WORKERS run as inside the container —
    # NOT `id`, which returns root (the exec/master user). With php:*-apache
    # (mod_php) the master runs as root but the workers drop to www-data, and
    # it's the workers that read/write the files. Detect the non-root worker
    # from the process list, then resolve its numeric uid:gid (the only thing
    # correct across a bind-mount). Fall back to APACHE_RUN_USER, then to `id`.
    if ! command -v docker >/dev/null 2>&1; then
        echo "${RED}✗ --from-container given but 'docker' is not on PATH.${NC}" >&2; exit 1
    fi
    _wu="$(docker exec "$FROM_CONTAINER" sh -c 'ps -eo user,comm 2>/dev/null | awk "\$1!=\"root\" && \$1!=\"USER\" && (\$2 ~ /apache|httpd|php|nginx/){print \$1; exit}"' 2>/dev/null || true)"
    [ -z "$_wu" ] && _wu="$(docker exec "$FROM_CONTAINER" sh -c 'printf %s "${APACHE_RUN_USER:-}"' 2>/dev/null || true)"
    if [ -n "$_wu" ]; then
        _uid="$(docker exec "$FROM_CONTAINER" id -u "$_wu" 2>/dev/null || true)"
        _gid="$(docker exec "$FROM_CONTAINER" id -g "$_wu" 2>/dev/null || true)"
        _src="worker '$_wu'"
    else
        _uid="$(docker exec "$FROM_CONTAINER" id -u 2>/dev/null || true)"
        _gid="$(docker exec "$FROM_CONTAINER" id -g 2>/dev/null || true)"
        _src="exec user"
    fi
    if [ -z "$_uid" ] || [ -z "$_gid" ]; then
        echo "${RED}✗ Could not read the uid/gid from container '$FROM_CONTAINER'.${NC}" >&2
        echo "  Check the name with 'docker ps' and that the container is running." >&2
        exit 1
    fi
    OWNER_SPEC="$_uid:$_gid"
    GROUP_KNOWN=1
    echo "  Container    : ${BLUE}$FROM_CONTAINER${NC} → PHP workers run as uid:gid ${BLUE}$_uid:$_gid${NC} (${_src})"
    if [ "$_uid" = "0" ]; then
        echo "  ${YELLOW}⚠ That resolved to root (0). If the app still can't write afterwards, the"
        echo "     workers likely run as a non-root user this couldn't detect — run"
        echo "     'docker exec $FROM_CONTAINER ps -eo user,comm' and pass it via --user/--group.${NC}"
    fi
else
    # Host install: detect or accept the PHP user.
    if [ -z "$PHP_USER" ]; then
        _names='php-fpm php_fpm php-cgi lsphp httpd apache2 apache nginx'
        for _p in $_names; do
            _u=$(ps -eo user,comm 2>/dev/null | awk -v p="$_p" 'index($2,p)>0 && $1!="root" && $1!="USER"{print $1; exit}') || true
            [ -z "${_u:-}" ] && _u=$(ps aux 2>/dev/null | awk -v p="$_p" 'index($0,p)>0 && $1!="root" && $1!="USER"{print $1; exit}') || true
            [ -z "${_u:-}" ] && _u=$(ps -ef 2>/dev/null | awk -v p="$_p" 'index($0,p)>0 && $1!="root" && $1!="UID"{print $1; exit}') || true
            [ -n "${_u:-}" ] && { PHP_USER="$_u"; break; }
        done
    fi
    if [ -z "$PHP_USER" ]; then
        echo ""
        echo "${YELLOW}⚠ Could not auto-detect the web-server user.${NC}"
        echo "  Re-run with --user <name> (QNAP: httpdusr · Debian: www-data ·"
        echo "  RHEL: apache · cPanel: your account) — or, if this is a Docker"
        echo "  install, with ${BOLD}--from-container <name>${NC}."
        echo "  Find it: ps aux | grep -E 'php-fpm|apache|httpd|nginx'"
        exit 1
    fi
    if ! id "$PHP_USER" >/dev/null 2>&1; then
        echo "${RED}✗ User '$PHP_USER' does not exist on this host.${NC}" >&2
        echo "  If PHP runs inside a container, use --from-container <name> instead." >&2
        exit 1
    fi
    if [ "$GROUP_EXPLICIT" -eq 1 ]; then
        OWNER_SPEC="$PHP_USER:$PHP_GROUP"; GROUP_KNOWN=1
    else
        # Preserve the existing group — do NOT switch it (that's what broke #205).
        OWNER_SPEC="$PHP_USER"; GROUP_KNOWN=0
    fi
    echo "  PHP user     : ${BLUE}$PHP_USER${NC}$([ "$GROUP_KNOWN" -eq 1 ] && echo "   group: ${BLUE}$PHP_GROUP${NC}" || echo "   group: ${BLUE}(preserved)${NC}")"
fi

CUR_UID="$(id -u 2>/dev/null || echo 1000)"
CAN_CHOWN=1; [ "$CUR_UID" != "0" ] && CAN_CHOWN=0

if [ "$APPLY" -eq 1 ]; then echo "  Mode         : ${GREEN}${BOLD}APPLY${NC}"
else echo "  Mode         : ${YELLOW}dry-run${NC} (nothing will change — use --apply)"; fi
[ "$CAN_CHOWN" -eq 0 ] && echo "  ${YELLOW}Note: not root — chown is skipped; only chmod (grant) runs. For a full fix use sudo / NAS admin.${NC}"
echo ""

# ── Writable data directories (audited from every runtime FS write) ─────────
WRITABLE_DIRS="
storage storage/logs storage/cache storage/tmp storage/backups
storage/calendar storage/sessions storage/rate_limits storage/plugins
storage/uploads storage/uploads/plugins storage/uploads/cms
public/uploads public/uploads/copertine public/uploads/autori
public/uploads/settings public/uploads/events public/uploads/assets
public/uploads/digital public/uploads/archives public/uploads/archives/covers
public/uploads/archives/documents public/assets data/dewey writable/uploads locale
"
WRITABLE_FILES="version.json .installed .htaccess public/sitemap.xml"

run() {
    if [ "$APPLY" -eq 1 ]; then
        if ! "$@" 2>/dev/null; then
            local _I="$IFS"; IFS=' '; echo "    ${YELLOW}⚠ skipped (permission denied):${NC} $*"; IFS="$_I"
        fi
    else
        local _I="$IFS"; IFS=' '; echo "    ${BLUE}[dry-run]${NC} $*"; IFS="$_I"
    fi
}

cd "$ROOT"

# ── 1. Create missing writable directories ──────────────────────────────────
echo "${BOLD}› Ensuring writable data directories exist${NC}"
_created=0; for d in $WRITABLE_DIRS; do
    [ -z "$d" ] && continue
    if [ ! -d "$d" ]; then run mkdir -p "$d"; echo "    ${GREEN}+${NC} $d ${YELLOW}(created)${NC}"; _created=$((_created+1)); fi
done
[ "$_created" -eq 0 ] && echo "    all present"

# ── 2. Ownership → the PHP user (group preserved unless you named one) ───────
echo "${BOLD}› Ownership → $OWNER_SPEC$([ "$GROUP_KNOWN" -eq 0 ] && echo " (group preserved)")${NC}"
if [ "$CAN_CHOWN" -eq 1 ]; then
    run chown -R "$OWNER_SPEC" "$ROOT"
    echo "    ${GREEN}✓${NC} chown -R applied"
else
    echo "    ${YELLOW}skipped (not root)${NC}"
fi

# ── 3. GRANT read+traverse to the owner across the tree (never strips) ──────
# u+rX: owner can read every file and enter every dir. Capital X only adds +x
# to directories and already-executable files, so scripts stay runnable and
# plain files don't become executable. No g/o bits are touched → nothing that
# currently works loses access.
echo "${BOLD}› Grant owner read/traverse (additive, strips nothing)${NC}"
run chmod -R u+rX "$ROOT"
echo "    ${GREEN}✓${NC} owner can read + traverse the whole tree"

# ── 4. GRANT write on the data dirs to owner+group (+ setgid) ───────────────
echo "${BOLD}› Grant write on data directories (owner+group, +setgid)${NC}"
for d in $WRITABLE_DIRS; do
    [ -z "$d" ] && continue; [ -d "$d" ] || continue
    run chmod -R ug+rwX "$d"
    run chmod g+s "$d"
done
echo "    ${GREEN}✓${NC} the PHP user (via owner or its group) can create/modify data"

# ── 5. Writable files ───────────────────────────────────────────────────────
echo "${BOLD}› Grant write on runtime files${NC}"
for f in $WRITABLE_FILES; do
    [ -f "$f" ] || continue; run chmod ug+rw "$f"; echo "    ${GREEN}✓${NC} $f"
done
# .env: make sure owner (and group) can read+write it. Do NOT strip existing
# readers — locking it to 0640 once locked PHP out of a Docker install (#205).
# Instead, warn if it's world-readable so the operator can tighten it safely.
if [ -f ".env" ]; then
    run chmod ug+rw ".env"
    echo "    ${GREEN}✓${NC} .env readable/writable by owner+group"
    if [ -r ".env" ] && ls -l ".env" 2>/dev/null | cut -c8 | grep -q 'r'; then
        echo "    ${YELLOW}note: .env is world-readable. Once the app works, you can tighten it with:"
        echo "          chmod o-rwx .env   (only if the PHP user is the owner or in its group)${NC}"
    fi
fi

# ── Done ────────────────────────────────────────────────────────────────────
echo ""
if [ "$APPLY" -eq 1 ]; then
    echo "${GREEN}${BOLD}✅ Permissions granted.${NC}"
    echo "${BOLD}› Ownership of the updater's required-writable paths${NC}"
    for p in "$ROOT" "$ROOT/storage" "$ROOT/storage/tmp" "$ROOT/storage/backups"; do
        [ -e "$p" ] && echo "    $(ls -ld "$p" 2>/dev/null | awk '{print $1, $3":"$4}')  $p"
    done
    echo ""
    echo "Next: retry the update from the admin panel. Re-running an interrupted"
    echo "update is safe — the updater re-copies every file."
else
    echo "${YELLOW}${BOLD}Dry-run complete — nothing was changed.${NC}"
    if [ -n "$FROM_CONTAINER" ]; then
        echo "Apply:  ${BOLD}sudo $0 --apply --from-container $FROM_CONTAINER${NC}"
    else
        echo "Apply:  ${BOLD}sudo $0 --apply${PHP_USER:+ --user $PHP_USER}${NC}"
    fi
fi
