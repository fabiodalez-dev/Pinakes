#!/usr/bin/env bash
# Isolated regression tests for scripts/ci-verify-release-source.sh.

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
policy_script="$repo_root/scripts/ci-verify-release-source.sh"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/pinakes-release-policy.XXXXXX")"
trap 'rm -rf -- "$test_root"' EXIT HUP INT TERM

mkdir -p "$test_root/repo/scripts" "$test_root/bin"
cp "$policy_script" "$test_root/repo/scripts/ci-verify-release-source.sh"

cat >"$test_root/bin/git" <<'FAKEGIT'
#!/usr/bin/env bash
case "${1:-}" in
  fetch) exit 0 ;;
  merge-base) [[ "${FAKE_MAIN_ANCESTOR:-yes}" == yes ]] ;;
  status) exit 0 ;;
  *) echo "unexpected git invocation: $*" >&2; exit 90 ;;
esac
FAKEGIT

cat >"$test_root/bin/gh" <<'FAKEGH'
#!/usr/bin/env bash
if [[ "${1:-}" == api ]]; then
  case "${FAKE_PR_KIND:-valid}" in
    none) printf '[[]]\n' ;;
    draft) printf '[[{"number":341,"draft":true,"base":{"ref":"main"},"head":{"sha":"sha-rc","ref":"release/0.7.59-rc.1","repo":{"full_name":"fabiodalez-dev/Pinakes"}}}]]\n' ;;
    external) printf '[[{"number":341,"draft":false,"base":{"ref":"main"},"head":{"sha":"sha-rc","ref":"release/0.7.59-rc.1","repo":{"full_name":"fork/Pinakes"}}}]]\n' ;;
    wrong_branch) printf '[[{"number":341,"draft":false,"base":{"ref":"main"},"head":{"sha":"sha-rc","ref":"feature/not-a-release","repo":{"full_name":"fabiodalez-dev/Pinakes"}}}]]\n' ;;
    *) printf '[[{"number":341,"draft":false,"base":{"ref":"main"},"head":{"sha":"sha-rc","ref":"release/0.7.59-rc.1","repo":{"full_name":"fabiodalez-dev/Pinakes"}}}]]\n' ;;
  esac
elif [[ "${1:-} ${2:-}" == "pr view" ]]; then
  if [[ "$*" == *"headRefOid,mergeStateStatus"* ]]; then
    printf '{"headRefOid":"%s","mergeStateStatus":"%s"}\n' \
      "${FAKE_PR_HEAD:-sha-rc}" "${FAKE_MERGE_STATE:-CLEAN}"
  else
    printf '%s\n' "${FAKE_FINAL_PR_HEAD:-${FAKE_PR_HEAD:-sha-rc}}"
  fi
elif [[ "${1:-} ${2:-}" == "pr checks" ]]; then
  case "${FAKE_CHECKS:-pass}" in
    pending)
      printf '[{"name":"CodeRabbit","state":"SUCCESS","bucket":"pass"},{"name":"Full E2E","state":"IN_PROGRESS","bucket":"pending"}]\n'
      exit 8
      ;;
    missing_coderabbit) printf '[{"name":"Full E2E","state":"SUCCESS","bucket":"pass"}]\n' ;;
    empty) printf '[]\n' ;;
    *) printf '[{"name":"CodeRabbit","state":"SUCCESS","bucket":"pass"},{"name":"Full E2E","state":"SUCCESS","bucket":"pass"}]\n' ;;
  esac
else
  echo "unexpected gh invocation: $*" >&2
  exit 91
fi
FAKEGH
chmod +x "$test_root/bin/git" "$test_root/bin/gh"

passed=0
failed=0

run_case() {
  local name="$1" expected="$2" version="$3" sha="$4"
  shift 4
  printf '{"version":"%s"}\n' "$version" >"$test_root/repo/version.json"
  if (cd "$test_root/repo" && env \
      PATH="$test_root/bin:$PATH" \
      TAG_NAME="v${version}" \
      GITHUB_SHA="$sha" \
      GITHUB_REPOSITORY="fabiodalez-dev/Pinakes" \
      GH_TOKEN="test-token" \
      "$@" \
      bash scripts/ci-verify-release-source.sh >/dev/null 2>&1); then
    actual=pass
  else
    actual=fail
  fi
  if [[ "$actual" == "$expected" ]]; then
    printf 'PASS: %s\n' "$name"
    passed=$((passed + 1))
  else
    printf 'FAIL: %s (expected %s, got %s)\n' "$name" "$expected" "$actual" >&2
    failed=$((failed + 1))
  fi
}

run_case "stable commit on main" pass "0.7.59" "sha-stable" FAKE_MAIN_ANCESTOR=yes
run_case "stable commit outside main" fail "0.7.59" "sha-stable" FAKE_MAIN_ANCESTOR=no
run_case "tag and version must match" fail "0.7.59" "sha-stable" TAG_NAME=v0.7.60
run_case "RC from clean release PR" pass "0.7.59-rc.3" "sha-rc"
run_case "alpha from clean release PR" pass "0.7.59-alpha.1" "sha-rc"
run_case "beta from clean release PR" pass "0.7.59-beta.2" "sha-rc"
run_case "RC without matching PR" fail "0.7.59-rc.3" "sha-rc" FAKE_PR_KIND=none
run_case "RC tag must point to exact PR head" fail "0.7.59-rc.3" "different-sha"
run_case "RC rejected if PR API head moved" fail "0.7.59-rc.3" "sha-rc" FAKE_PR_HEAD=new-sha
run_case "RC rejected if PR moves during check audit" fail "0.7.59-rc.3" "sha-rc" FAKE_FINAL_PR_HEAD=new-sha
run_case "RC from draft PR" fail "0.7.59-rc.3" "sha-rc" FAKE_PR_KIND=draft
run_case "RC from fork" fail "0.7.59-rc.3" "sha-rc" FAKE_PR_KIND=external
run_case "RC from non-release branch" fail "0.7.59-rc.3" "sha-rc" FAKE_PR_KIND=wrong_branch
run_case "RC from blocked PR" fail "0.7.59-rc.3" "sha-rc" FAKE_MERGE_STATE=BLOCKED
run_case "RC with pending required check" fail "0.7.59-rc.3" "sha-rc" FAKE_CHECKS=pending
run_case "RC without required CodeRabbit" fail "0.7.59-rc.3" "sha-rc" FAKE_CHECKS=missing_coderabbit
run_case "RC without required checks" fail "0.7.59-rc.3" "sha-rc" FAKE_CHECKS=empty
run_case "unsupported prerelease channel" fail "0.7.59-preview.1" "sha-rc"

printf '%s passed, %s failed\n' "$passed" "$failed"
[[ "$failed" -eq 0 ]]
