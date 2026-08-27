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
    --json headRefOid,mergeStateStatus,mergeable,reviewDecision)"
  pr_head="$(jq -r '.headRefOid' <<<"$pr_state")"
  if [[ "$pr_head" != "$GITHUB_SHA" ]]; then
    echo "Prerelease PR #${pr_number} moved from tagged commit ${GITHUB_SHA} to ${pr_head}" >&2
    exit 1
  fi

  # Audit every visible check, not only required checks, before interpreting
  # mergeStateStatus. The tag-triggered Verified Release check is attached to
  # the same commit as the PR head. GitHub may report that circular in-progress
  # check as either UNSTABLE or BLOCKED; the latter was observed in production
  # even though the PR was CLEAN immediately before the tag was pushed.
  check_attempts="${RELEASE_CHECK_MAX_ATTEMPTS:-90}"
  check_interval="${RELEASE_CHECK_POLL_INTERVAL_SECONDS:-10}"
  if ! [[ "$check_attempts" =~ ^[1-9][0-9]*$ && "$check_interval" =~ ^[0-9]+$ ]]; then
    echo "Invalid release-check polling configuration" >&2
    exit 1
  fi

  # A tag push can temporarily attach fresh checks to the same commit as the
  # already-green PR. Wait for those checks instead of racing them. Terminal
  # failures still fail immediately, and the bounded wait preserves fail-closed
  # behaviour when a check remains queued or in progress indefinitely.
  for ((attempt = 1; attempt <= check_attempts; attempt++)); do
    all_checks_json="$(gh pr checks "$pr_number" --repo "$GITHUB_REPOSITORY" \
      --json name,state,bucket,workflow || true)"
    if ! jq -e 'type == "array" and length > 0' >/dev/null <<<"$all_checks_json"; then
      echo "Prerelease PR #${pr_number} has no readable check result" >&2
      exit 1
    fi

    terminal_non_self="$(jq -r --arg self_workflow "Verified Release" \
      '.[] | select(.workflow != $self_workflow) | select(.bucket != "pass" and .bucket != "pending") | "\(.workflow // "external") / \(.name): \(.state)"' \
      <<<"$all_checks_json")"
    if [[ -n "$terminal_non_self" ]]; then
      echo "Prerelease PR #${pr_number} has non-passing checks:" >&2
      printf '%s\n' "$terminal_non_self" >&2
      exit 1
    fi

    pending_non_self="$(jq -r --arg self_workflow "Verified Release" \
      '.[] | select(.workflow != $self_workflow and .bucket == "pending") | "\(.workflow // "external") / \(.name): \(.state)"' \
      <<<"$all_checks_json")"
    if [[ -z "$pending_non_self" ]]; then
      break
    fi
    if ((attempt == check_attempts)); then
      echo "Prerelease PR #${pr_number} still has pending checks after ${check_attempts} attempts:" >&2
      printf '%s\n' "$pending_non_self" >&2
      exit 1
    fi
    echo "Waiting for tag-triggered checks on prerelease PR #${pr_number} (${attempt}/${check_attempts}):" >&2
    printf '%s\n' "$pending_non_self" >&2
    sleep "$check_interval"
  done

  # Refresh mergeability after the bounded wait. The head must remain pinned to
  # the tagged SHA throughout the policy decision.
  pr_state="$(gh pr view "$pr_number" --repo "$GITHUB_REPOSITORY" \
    --json headRefOid,mergeStateStatus,mergeable,reviewDecision)"
  pr_head="$(jq -r '.headRefOid' <<<"$pr_state")"
  merge_state="$(jq -r '.mergeStateStatus' <<<"$pr_state")"
  mergeable="$(jq -r '.mergeable' <<<"$pr_state")"
  review_decision="$(jq -r '.reviewDecision // ""' <<<"$pr_state")"
  if [[ "$pr_head" != "$GITHUB_SHA" ]]; then
    echo "Prerelease PR #${pr_number} moved while tag-triggered checks were settling" >&2
    exit 1
  fi
  non_self_failing="$(jq -r --arg self_workflow "Verified Release" \
    '.[] | select(.workflow != $self_workflow) | select(.bucket != "pass") | "\(.workflow // "external") / \(.name): \(.state)"' \
    <<<"$all_checks_json")"
  self_is_pending_or_failed="$(jq -r --arg self_workflow "Verified Release" \
    'any(.[]; .workflow == $self_workflow and .bucket != "pass")' <<<"$all_checks_json")"

  case "$merge_state" in
    CLEAN|UNSTABLE)
      ;;
    BLOCKED)
      # Accept BLOCKED only when it is demonstrably the release workflow's own
      # circular check: GitHub still considers the PR mergeable, no review is
      # missing/rejected, every other visible check passed, and this workflow
      # has a pending/failed check attached to the head. A genuinely blocked PR
      # (conflict, required review, policy or any other check) remains fatal.
      if [[ "$mergeable" != "MERGEABLE" \
        || "$review_decision" == "REVIEW_REQUIRED" \
        || "$review_decision" == "CHANGES_REQUESTED" \
        || "$self_is_pending_or_failed" != "true" \
        || -n "$non_self_failing" ]]; then
        echo "Prerelease PR #${pr_number} is genuinely blocked (mergeable=${mergeable}, reviewDecision=${review_decision:-none})" >&2
        if [[ -n "$non_self_failing" ]]; then
          printf '%s\n' "$non_self_failing" >&2
        fi
        exit 1
      fi
      ;;
    *)
      echo "Prerelease PR #${pr_number} is not merge-ready (mergeStateStatus=${merge_state})" >&2
      exit 1
      ;;
  esac

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

  echo "Verified prerelease ${TAG_NAME}: PR #${pr_number} (${pr_branch}) is merge-ready (mergeStateStatus=${merge_state}) and every required check passed"
else
  echo "Unsupported release version '${version}': use X.Y.Z or X.Y.Z-(alpha|beta|rc).N" >&2
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Release verification modified the checked-out source tree" >&2
  exit 1
fi
