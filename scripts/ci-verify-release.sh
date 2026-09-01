#!/usr/bin/env bash

# Validate the exact ZIP distributed to users. This deliberately checks the
# archive, not only the source tree, so filtering/packaging regressions cannot
# bypass CI.

set -euo pipefail

archive="${1:-}"
if [ -z "$archive" ] || [ ! -f "$archive" ]; then
  echo "usage: $0 releases/pinakes-vX.Y.Z.zip" >&2
  exit 2
fi

archive="$(cd "$(dirname "$archive")" && pwd)/$(basename "$archive")"
work_dir="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/pinakes-release.XXXXXX")"
cleanup() { rm -rf -- "$work_dir"; }
trap cleanup EXIT HUP INT TERM

echo "Checking ZIP integrity and path safety"
unzip -tq "$archive"
entries=()
while IFS= read -r entry; do
  entries+=("$entry")
done < <(zipinfo -1 "$archive")
[ "${#entries[@]}" -gt 0 ] || { echo "release archive is empty" >&2; exit 1; }

printf '%s\n' "${entries[@]}" | LC_ALL=C sort | uniq -d > "$work_dir/duplicates"
if [ -s "$work_dir/duplicates" ]; then
  echo "duplicate ZIP entries:" >&2
  cat "$work_dir/duplicates" >&2
  exit 1
fi

for entry in "${entries[@]}"; do
  case "$entry" in
    /*|*\\*|../*|*/../*|*/..)
      echo "unsafe ZIP path: $entry" >&2
      exit 1
      ;;
  esac
done

root="${entries[0]%%/*}"
case "$root" in
  pinakes-v*) ;;
  *) echo "unexpected archive root: $root" >&2; exit 1 ;;
esac
if printf '%s\n' "${entries[@]}" | grep -Ev "^${root}(/|$)" | grep -q .; then
  echo "archive contains files outside $root/" >&2
  exit 1
fi

if zipinfo -l "$archive" | awk '$1 ~ /^l/ { found=1 } END { exit !found }'; then
  echo "release archive contains symbolic links" >&2
  exit 1
fi

unzip -q "$archive" -d "$work_dir/extracted"
package_dir="$work_dir/extracted/$root"
[ -d "$package_dir" ] || { echo "missing extracted package root" >&2; exit 1; }

echo "Checking checksums, permissions and forbidden release material"
checksum_file="${archive}.sha256"
if [ -f "$checksum_file" ]; then
  (cd "$(dirname "$archive")" && sha256sum -c "$(basename "$checksum_file")")
fi

if find "$package_dir" -type f \( -perm -0002 -o -perm -4000 -o -perm -2000 \) -print -quit | grep -q .; then
  echo "release contains world-writable or set-id files" >&2
  exit 1
fi
if find -L "$package_dir" -type l -print -quit | grep -q .; then
  echo "release contains broken symbolic links" >&2
  exit 1
fi

for forbidden in .env .git .github tests node_modules phpstan.neon package.json package-lock.json; do
  [ ! -e "$package_dir/$forbidden" ] || { echo "forbidden release path: $forbidden" >&2; exit 1; }
done
if find "$package_dir" -type f \( -name '*.pem' -o -name '*.key' -o -name 'id_rsa' -o -name '.npmrc' \) -print -quit | grep -q .; then
  echo "release contains a private-key or registry-credential file" >&2
  exit 1
fi
# storage/sessions must be a REAL directory holding a REAL .gitkeep. Reject
# symlinks first: -d/-f follow them, so a symlinked directory or placeholder
# could point outside the tree and slip session data past the scan.
if [ -L "$package_dir/storage" ] || [ -L "$package_dir/storage/sessions" ] || [ ! -d "$package_dir/storage/sessions" ]; then
  echo "release storage or storage/sessions is not a real directory" >&2
  exit 1
fi
if [ -L "$package_dir/storage/sessions/.gitkeep" ] || [ ! -f "$package_dir/storage/sessions/.gitkeep" ]; then
  echo "release missing storage/sessions/.gitkeep (or it is a symlink)" >&2
  exit 1
fi
# Fail closed: a find error must never be read as "no session data" and let an
# unverified archive pass. Capture the scan so a non-zero find status aborts.
if ! session_scan="$(find "$package_dir/storage/sessions" -mindepth 1 ! -path "$package_dir/storage/sessions/.gitkeep" -print -quit 2>/dev/null)"; then
  echo "could not scan storage/sessions for leaked session data" >&2
  exit 1
fi
if [ -n "$session_scan" ]; then
  echo "release contains runtime session data" >&2
  exit 1
fi

echo "Checking release runtime and metadata"
version="$(php -r 'echo json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)["version"];' "$package_dir/version.json")"
[ "$root" = "pinakes-v${version}" ] || { echo "archive/version.json mismatch: $root vs $version" >&2; exit 1; }

(cd "$package_dir" && composer validate --strict --no-check-publish)
(cd "$package_dir" && composer check-platform-reqs --no-dev)
php -r 'require $argv[1]; echo "autoload OK\n";' "$package_dir/vendor/autoload.php"

find "$package_dir/app" "$package_dir/installer" "$package_dir/storage/plugins" \
  -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

# Compiled assets plus the historical critical-file list the release contract
# requires in every ZIP (vendored TinyMCE/Swagger payloads are the ones a
# broken `git archive`/checkout most easily drops — .coderabbit.yaml documents
# public/assets/tinymce/models/dom/model.min.js as the canary).
for asset in \
  public/assets/main.css \
  public/assets/main.bundle.js \
  public/assets/vendor.bundle.js \
  public/assets/tinymce/tinymce.min.js \
  public/assets/tinymce/models/dom/model.min.js \
  public/assets/tinymce/themes/silver/theme.min.js \
  public/assets/tinymce/skins/ui/oxide/skin.min.css \
  public/assets/tinymce/icons/default/icons.min.js \
  public/assets/swagger-ui/swagger-ui-bundle.js \
  public/assets/swagger-ui/swagger-ui.css \
  public/index.php \
  app/Support/Updater.php \
  vendor/composer/autoload_real.php; do
  [ -s "$package_dir/$asset" ] || { echo "missing or empty critical release file: $asset" >&2; exit 1; }
done

echo "Release archive verified: pinakes-v${version} (${#entries[@]} entries)"
