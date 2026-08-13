#!/usr/bin/env bash
# Verify that a release tag comes from an approved source.
#
# Stable versions must already be contained in main. Prereleases may be cut
# before merge, but only from the exact, merge-ready head of an internal
# release/* pull request whose required checks (including CodeRabbit) passed.

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

: "${TAG_NAME:?TAG_NAME is required}"
: "${GITHUB_SHA:?GITHUB_SHA is required}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"

version="$(jq -er '.version | select(type == "string" and length > 0)' version.json)"
if [[ "$TAG_NAME" != "v${version}" ]]; then
  echo "Tag/version mismatch: ${TAG_NAME} != v${version}" >&2
  exit 1
fi

if [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  git fetch --no-tags origin main
  if ! git merge-base --is-ancestor "$GITHUB_SHA" origin/main; then
    echo "Stable release commit is not contained in origin/main" >&2
    exit 1
  fi
  echo "Verified stable release ${TAG_NAME}: commit is contained in origin/main"
elif [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+-(alpha|beta|rc)\.[0-9]+$ ]]; then
  : "${GH_TOKEN:?GH_TOKEN is required for prerelease verification}"

  pulls_json="$(gh api --method GET "repos/${GITHUB_REPOSITORY}/pulls" \
    -f state=open -f base=main -f per_page=100 --paginate --slurp)"
  matching_pulls="$(jq -c \
    --arg sha "$GITHUB_SHA" \
    --arg repo "$GITHUB_REPOSITORY" \
    '[.[][] | select(
      .head.sha == $sha
      and .base.ref == "main"
      and .draft == false
      and .head.repo.full_name == $repo
      and (.head.ref | startswith("release/"))
    )]' <<<"$pulls_json")"

  match_count="$(jq 'length' <<<"$matching_pulls")"
  if [[ "$match_count" != "1" ]]; then
    echo "Prerelease ${TAG_NAME} must point to exactly one open, non-draft, internal release/* PR targeting main; found ${match_count}" >&2
    exit 1
  fi

  pr_number="$(jq -r '.[0].number' <<<"$matching_pulls")"
  pr_branch="$(jq -r '.[0].head.ref' <<<"$matching_pulls")"
  pr_state="$(gh pr view "$pr_number" --repo "$GITHUB_REPOSITORY" \
    --json headRefOid,mergeStateStatus)"
  pr_head="$(jq -r '.headRefOid' <<<"$pr_state")"
  merge_state="$(jq -r '.mergeStateStatus' <<<"$pr_state")"
  if [[ "$pr_head" != "$GITHUB_SHA" ]]; then
    echo "Prerelease PR #${pr_number} moved from tagged commit ${GITHUB_SHA} to ${pr_head}" >&2
    exit 1
  fi
  # Why UNSTABLE is accepted alongside CLEAN: this very script runs inside the
  # tag-triggered "Verified Release" workflow, whose check run attaches to the
  # tagged commit — which IS the PR head. While this job is in progress GitHub
  # reports the PR as UNSTABLE ("mergeable, but a non-required status is
  # pending/failing"), so demanding CLEAN here is circular: the check that must
  # pass keeps the PR from ever being CLEAN. UNSTABLE by definition still means
  # the PR is mergeable (no conflicts, branch protection satisfied); the only
  # thing it relaxes versus CLEAN is non-required statuses — and the loop below
  # closes exactly that gap by independently requiring every branch-protection
  # REQUIRED check to be green, excluding only this release workflow's own
  # check run (tag-triggered, so by construction never a PR-required check).
  # BLOCKED, DIRTY, BEHIND and UNKNOWN remain fatal: merge conflicts, missing
  # approvals or unmet branch protection still veto the prerelease.
  if [[ "$merge_state" != "CLEAN" && "$merge_state" != "UNSTABLE" ]]; then
    echo "Prerelease PR #${pr_number} is not merge-ready (mergeStateStatus=${merge_state})" >&2
    exit 1
  fi

  checks_json="$(gh pr checks "$pr_number" --repo "$GITHUB_REPOSITORY" \
    --required --json name,state,bucket,workflow || true)"
  if ! jq -e 'type == "array" and length > 0' >/dev/null <<<"$checks_json"; then
    echo "Prerelease PR #${pr_number} has no readable required-check result" >&2
    exit 1
  fi

  # Every required check except this release workflow's own run must be in the
  # "pass" bucket (pending/fail/cancel/skipping all veto). The self-exclusion
  # is defense in depth for the circularity above: should someone ever mark
  # the release workflow itself required, the gate must still judge only the
  # OTHER required checks instead of deadlocking on its own in-progress run.
  # The decision is computed from the filtered JSON, not from gh's exit code,
  # because the exit code would also trip on the self check being "pending".
  failing_checks="$(jq -r --arg self_workflow "Verified Release" \
    '.[] | select(.workflow != $self_workflow) | select(.bucket != "pass") | "\(.name): \(.state)"' \
    <<<"$checks_json")"
  if [[ -n "$failing_checks" ]]; then
    echo "Prerelease PR #${pr_number} has required checks that did not pass:" >&2
    printf '%s\n' "$failing_checks" >&2
    exit 1
  fi
  if ! jq -e 'any(.[]; .name == "CodeRabbit" and .bucket == "pass")' >/dev/null <<<"$checks_json"; then
    echo "Prerelease PR #${pr_number} is missing a passing required CodeRabbit check" >&2
    exit 1
  fi

  final_pr_head="$(gh pr view "$pr_number" --repo "$GITHUB_REPOSITORY" \
    --json headRefOid --jq '.headRefOid')"
  if [[ "$final_pr_head" != "$GITHUB_SHA" ]]; then
    echo "Prerelease PR #${pr_number} moved while its release checks were being verified" >&2
    exit 1
  fi

  echo "Verified prerelease ${TAG_NAME}: PR #${pr_number} (${pr_branch}) is CLEAN and every required check passed"
else
  echo "Unsupported release version '${version}': use X.Y.Z or X.Y.Z-(alpha|beta|rc).N" >&2
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Release verification modified the checked-out source tree" >&2
  exit 1
fi
