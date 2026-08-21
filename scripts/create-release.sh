#!/usr/bin/env bash
# Create a Pinakes release by delegating all artifact production and publishing
# to .github/workflows/release.yml. This script only performs local/source
# preflight checks, pushes the release tag, waits for the workflow, and verifies
# the published release contract.

set -euo pipefail

readonly REPOSITORY="fabiodalez-dev/Pinakes"
readonly RELEASE_WORKFLOW="release.yml"
readonly GITHUB_API_VERSION="2026-03-10"
readonly WORKFLOW_DISCOVERY_ATTEMPTS=24
readonly WORKFLOW_DISCOVERY_INTERVAL=5

usage() {
    cat <<'USAGE'
Usage: ./scripts/create-release.sh X.Y.Z [--yes]
       ./scripts/create-release.sh X.Y.Z-rc.N [--yes]

The script does not build or upload release assets locally. It validates the
source, creates and pushes an annotated tag, waits for the Verified Release
workflow, and verifies the assets published by that workflow.
USAGE
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

info() {
    printf '==> %s\n' "$*"
}

if [[ $# -lt 1 || $# -gt 2 ]]; then
    usage
    exit 1
fi

VERSION="$1"
ASSUME_YES=false
if [[ $# -eq 2 ]]; then
    [[ "$2" == "--yes" ]] || fail "unknown option '$2'"
    ASSUME_YES=true
fi

# Keep this grammar identical to scripts/ci-verify-release-source.sh. A tag
# accepted here but rejected in CI would leave an unusable remote release tag.
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$ ]]; then
    fail "invalid version '$VERSION'; expected X.Y.Z or X.Y.Z-(alpha|beta|rc).N"
fi

readonly TAG_NAME="v${VERSION}"
IS_PRERELEASE=false
if [[ "$VERSION" == *-* ]]; then
    IS_PRERELEASE=true
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

info "Checking release prerequisites"
for command_name in git gh jq composer npm; do
    command -v "$command_name" >/dev/null 2>&1 \
        || fail "required command not found: $command_name"
done
gh auth status >/dev/null 2>&1 || fail "GitHub CLI is not authenticated; run 'gh auth login'"

resolved_repository="$(gh repo view --json nameWithOwner --jq '.nameWithOwner')"
resolved_repository_lc="$(printf '%s' "$resolved_repository" | tr '[:upper:]' '[:lower:]')"
expected_repository_lc="$(printf '%s' "$REPOSITORY" | tr '[:upper:]' '[:lower:]')"
[[ "$resolved_repository_lc" == "$expected_repository_lc" ]] \
    || fail "current repository is '$resolved_repository', expected '$REPOSITORY'"

# GitHub's API digest changes whenever a mutable asset is replaced, so digest
# verification alone cannot pin what users download later. Refuse to create the
# tag unless future releases in this repository are locked at publication time.
if ! immutable_policy="$(gh api \
    -H "X-GitHub-Api-Version: ${GITHUB_API_VERSION}" \
    "repos/${REPOSITORY}/immutable-releases" 2>/dev/null)"; then
    fail "cannot verify release immutability; authenticate with repository administration read access"
fi
if ! jq -e '.enabled == true' >/dev/null <<<"$immutable_policy"; then
    fail "immutable releases are disabled for ${REPOSITORY}; enable release immutability before creating ${TAG_NAME}"
fi
info "Release immutability is enabled"

# Draft releases do not lock their tags yet. Keep version tags non-movable from
# creation onward so the verified commit cannot be swapped between build and
# publication. The workflow still rechecks the resolved SHA at every boundary.
# --slurp + first: con `set -o pipefail`, chiudere la pipe con `head -n 1`
# mentre gh sta ancora paginando termina gh con SIGPIPE (exit 141) e lo script
# muore senza il messaggio di errore previsto. `gh api` rende inoltre `--slurp`
# e `--jq` mutuamente esclusivi, quindi il filtro deve essere un jq esterno che
# consuma per intero il documento paginato.
release_tag_ruleset_id="$(gh api "repos/${REPOSITORY}/rulesets?targets=tag&per_page=100" --paginate --slurp \
    | jq -r '[.[][] | select(.name == "Protect immutable release tags") | .id] | first // empty')"
[[ -n "$release_tag_ruleset_id" ]] \
    || fail "active release-tag protection ruleset is missing"
release_tag_ruleset="$(gh api "repos/${REPOSITORY}/rulesets/${release_tag_ruleset_id}")"
if ! jq -e '
    .target == "tag"
    and .enforcement == "active"
    and (.conditions.ref_name.include | index("refs/tags/v*") != null)
    and (.conditions.ref_name.exclude | length == 0)
    and ([.rules[].type] | index("deletion") != null and index("non_fast_forward") != null)
    and ((.bypass_actors // []) | length == 0)
' >/dev/null <<<"$release_tag_ruleset"; then
    fail "release-tag ruleset must block deletion/non-fast-forward updates for refs/tags/v* without bypasses"
fi
info "Release tags are protected from deletion and movement"

[[ -z "$(git status --porcelain)" ]] \
    || fail "working tree is not clean; commit or stash every tracked and untracked change"

head_sha="$(git rev-parse --verify HEAD)"
branch="$(git branch --show-current)"
[[ -n "$branch" ]] || fail "detached HEAD is not a valid release source"

current_version="$(jq -er '.version | select(type == "string" and length > 0)' version.json)"
[[ "$current_version" == "$VERSION" ]] \
    || fail "version.json contains '$current_version', expected '$VERSION'"
grep -Fqx "## [${VERSION}]" CHANGELOG.md \
    || fail "CHANGELOG.md has no exact '## [${VERSION}]' release section"

if git show-ref --verify --quiet "refs/tags/${TAG_NAME}"; then
    fail "local tag ${TAG_NAME} already exists"
fi
if [[ -n "$(git ls-remote --tags origin "refs/tags/${TAG_NAME}")" ]]; then
    fail "remote tag ${TAG_NAME} already exists"
fi
existing_release_id="$(gh api "repos/${REPOSITORY}/releases?per_page=100" --paginate --slurp \
    | jq -r "[.[][] | select(.tag_name == \"${TAG_NAME}\") | .id] | first // empty")"
[[ -z "$existing_release_id" ]] || fail "GitHub release ${TAG_NAME} already exists"

# The workflow repeats this source gate after the tag push. Running the exact
# same policy before the irreversible operation prevents avoidable orphan tags.
if [[ "$IS_PRERELEASE" == false ]]; then
    [[ "$branch" == "main" ]] || fail "stable releases must be created from main (current: $branch)"
    git fetch --no-tags origin main
    origin_main_sha="$(git rev-parse --verify refs/remotes/origin/main)"
    [[ "$head_sha" == "$origin_main_sha" ]] \
        || fail "local main ($head_sha) is not exactly origin/main ($origin_main_sha)"
fi

release_token="$(gh auth token)"
TAG_NAME="$TAG_NAME" \
GITHUB_SHA="$head_sha" \
GITHUB_REPOSITORY="$REPOSITORY" \
GH_TOKEN="$release_token" \
    bash scripts/ci-verify-release-source.sh
unset release_token

info "Running local release-policy and schema preflight"
composer validate --strict
npm run test:ci-policy
CI_STRICT_TESTS=1 bash scripts/verify-schema.sh

[[ "$(git rev-parse --verify HEAD)" == "$head_sha" ]] \
    || fail "HEAD changed during preflight"
[[ -z "$(git status --porcelain)" ]] \
    || fail "preflight modified the working tree"

if [[ "$ASSUME_YES" == false ]]; then
    printf 'Push annotated tag %s at %s and start the release workflow? [y/N] ' \
        "$TAG_NAME" "$head_sha"
    read -r answer
    [[ "$answer" == "y" || "$answer" == "Y" ]] || fail "release cancelled"
fi

# A failed release may leave an Actions run behind after its tag/draft is
# deliberately cleaned up. Snapshot the current workflow high-water mark so a
# retry can never attach to that stale run while the new push is still being
# indexed by GitHub.
workflow_run_floor="$(gh run list \
    --repo "$REPOSITORY" \
    --workflow "$RELEASE_WORKFLOW" \
    --event push \
    --limit 100 \
    --json databaseId \
    --jq '[.[].databaseId] | max // 0')"
[[ "$workflow_run_floor" =~ ^[0-9]+$ ]] \
    || fail "could not snapshot the existing release workflow run ids"

info "Creating and pushing ${TAG_NAME}"
git tag -a "$TAG_NAME" "$head_sha" -m "Pinakes ${TAG_NAME}"
if ! git push origin "refs/tags/${TAG_NAME}:refs/tags/${TAG_NAME}"; then
    fail "tag push failed; inspect refs/tags/${TAG_NAME} locally and remotely before retrying"
fi

info "Waiting for the Verified Release workflow to start"
run_id=""
run_url=""
for ((attempt = 1; attempt <= WORKFLOW_DISCOVERY_ATTEMPTS; attempt++)); do
    runs_json="$(gh run list \
        --repo "$REPOSITORY" \
        --workflow "$RELEASE_WORKFLOW" \
        --event push \
        --commit "$head_sha" \
        --limit 20 \
        --json databaseId,headBranch,headSha,url)"
    run_id="$(jq -r --arg tag "$TAG_NAME" --arg sha "$head_sha" --argjson floor "$workflow_run_floor" \
        '[.[] | select(.headBranch == $tag and .headSha == $sha and .databaseId > $floor)]
         | sort_by(.databaseId) | last | .databaseId // empty' \
        <<<"$runs_json")"
    run_url="$(jq -r --arg tag "$TAG_NAME" --arg sha "$head_sha" --argjson floor "$workflow_run_floor" \
        '[.[] | select(.headBranch == $tag and .headSha == $sha and .databaseId > $floor)]
         | sort_by(.databaseId) | last | .url // empty' \
        <<<"$runs_json")"
    if [[ -n "$run_id" ]]; then
        break
    fi
    printf '  workflow not visible yet (%d/%d)\n' "$attempt" "$WORKFLOW_DISCOVERY_ATTEMPTS"
    sleep "$WORKFLOW_DISCOVERY_INTERVAL"
done

[[ -n "$run_id" ]] || fail "no ${RELEASE_WORKFLOW} run appeared for ${TAG_NAME}; remote tag was not removed"

info "Following workflow run ${run_id}: ${run_url}"
if ! gh run watch "$run_id" --repo "$REPOSITORY" --exit-status --interval 15; then
    gh run view "$run_id" --repo "$REPOSITORY" --log-failed || true
    fail "release workflow failed; ${TAG_NAME} remains tagged but no release was accepted"
fi

remote_tag_commit="$(gh api "repos/${REPOSITORY}/commits/${TAG_NAME}" --jq '.sha')"
[[ "$remote_tag_commit" == "$head_sha" ]] \
    || fail "remote ${TAG_NAME} moved from ${head_sha} to ${remote_tag_commit} during release"

info "Verifying the published release and its workflow-owned assets"
release_json=""
for attempt in 1 2 3 4 5 6 7 8 9 10 11 12; do
    release_json="$(gh api "repos/${REPOSITORY}/releases/tags/${TAG_NAME}")"
    zip_digest="$(jq -r --arg name "pinakes-v${VERSION}.zip" \
        '.assets[] | select(.name == $name) | .digest // empty' <<<"$release_json")"
    [[ "$zip_digest" =~ ^sha256:[0-9a-fA-F]{64}$ ]] && break
    printf '  ZIP digest not ready yet (%d/12)\n' "$attempt"
    sleep 10
done

[[ "$(jq -r '.draft' <<<"$release_json")" == "false" ]] \
    || fail "${TAG_NAME} is still a draft"
[[ "$(jq -r '.immutable' <<<"$release_json")" == "true" ]] \
    || fail "${TAG_NAME} was published without immutable-release protection"
expected_prerelease="$IS_PRERELEASE"
[[ "$(jq -r '.prerelease' <<<"$release_json")" == "$expected_prerelease" ]] \
    || fail "${TAG_NAME} prerelease flag does not match version policy"

expected_assets="$(printf '%s\n' \
    "RELEASE_NOTES-v${VERSION}.md" \
    "pinakes-v${VERSION}.zip" \
    "pinakes-v${VERSION}.zip.sha256" \
    "pinakes.spdx.json")"
actual_assets="$(jq -r '.assets[].name' <<<"$release_json" | LC_ALL=C sort)"
[[ "$actual_assets" == "$expected_assets" ]] || {
    printf 'Expected assets:\n%s\nActual assets:\n%s\n' "$expected_assets" "$actual_assets" >&2
    fail "published asset set is incomplete or unexpected"
}

if ! jq -e '
    (.assets | length == 4)
    and all(.assets[];
        .state == "uploaded"
        and .size > 0
        and (.digest | test("^sha256:[0-9a-fA-F]{64}$"))
        and .uploader.login == "github-actions[bot]")
' >/dev/null <<<"$release_json"; then
    fail "every asset must be non-empty, uploaded by github-actions[bot], and expose a SHA-256 digest"
fi

zip_asset_id="$(jq -r --arg name "pinakes-v${VERSION}.zip" \
    '.assets[] | select(.name == $name) | .id' <<<"$release_json")"
checksum_asset_id="$(jq -r --arg name "pinakes-v${VERSION}.zip.sha256" \
    '.assets[] | select(.name == $name) | .id' <<<"$release_json")"
zip_digest="$(jq -r --arg name "pinakes-v${VERSION}.zip" \
    '.assets[] | select(.name == $name) | .digest' <<<"$release_json")"
checksum_body="$(gh api -H 'Accept: application/octet-stream' \
    "repos/${REPOSITORY}/releases/assets/${checksum_asset_id}")"
checksum_sha="$(awk 'NR == 1 { print $1 }' <<<"$checksum_body")"
[[ "$checksum_sha" =~ ^[0-9a-fA-F]{64}$ ]] \
    || fail "checksum asset does not contain a valid SHA-256"
[[ "${zip_digest#sha256:}" == "$checksum_sha" ]] \
    || fail "ZIP API digest and published checksum disagree"
[[ -n "$zip_asset_id" ]] || fail "ZIP asset id is missing"

tagged_commit="$(git rev-list -n 1 "$TAG_NAME")"
[[ "$tagged_commit" == "$head_sha" ]] || fail "local release tag no longer resolves to the preflighted commit"
[[ -z "$(git status --porcelain)" ]] || fail "release orchestration modified the working tree"

release_url="$(jq -r '.html_url' <<<"$release_json")"
printf '\nRelease %s is published and verified: %s\n' "$TAG_NAME" "$release_url"
printf 'Workflow: %s\n' "$run_url"
