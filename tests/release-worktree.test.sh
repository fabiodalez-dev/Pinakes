#!/usr/bin/env bash
# Exercise the real packager with Git's worktree metadata file instead of a .git directory.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/pinakes-release-worktree.XXXXXX")"
qa_worktree="$test_root/source"
cleanup() {
  if [ -d "$qa_worktree" ]; then
    git -C "$repo_root" worktree remove --force "$qa_worktree" >/dev/null 2>&1 || true
  fi
  rm -rf -- "$test_root"
}
trap cleanup EXIT HUP INT TERM

git -C "$repo_root" worktree add --quiet --detach "$qa_worktree" HEAD
test -f "$qa_worktree/.git"
# Also exercise the working copy of the fix before it has been committed.
cp "$repo_root/bin/build-release.sh" "$qa_worktree/bin/build-release.sh"
cp "$repo_root/.rsync-filter" "$qa_worktree/.rsync-filter"
cp "$repo_root/.distignore" "$qa_worktree/.distignore"

if ! (cd "$qa_worktree" && bash bin/build-release.sh --skip-build --output "$test_root/output") >"$test_root/build.log" 2>&1; then
  tail -40 "$test_root/build.log" >&2
  exit 1
fi
version="$(jq -r .version "$qa_worktree/version.json")"
archive="$test_root/output/pinakes-v${version}.zip"
zipinfo -1 "$archive" >"$test_root/entries"
if grep -Eq '^pinakes-v[^/]+/\.git(/|$)' "$test_root/entries"; then
  echo 'FAIL: Git worktree metadata leaked into the release ZIP' >&2
  exit 1
fi
bash "$qa_worktree/scripts/ci-verify-release.sh" "$archive"
echo 'PASS: the real worktree release ZIP excludes Git metadata and passes the archive audit'
