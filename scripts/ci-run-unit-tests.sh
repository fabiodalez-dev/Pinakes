#!/usr/bin/env bash
# Run every standalone PHP unit test and reject silent skips in strict CI mode.

set -uo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" || exit 2

PHP_BIN="${PHP_BIN:-php}"
STRICT="${CI_STRICT_TESTS:-0}"
# Purge dispatch is an intentional production side effect. Never let a unit
# test contact APP_CANONICAL_URL, even when a developer's local DB has enabled
# LiteSpeed caching.
export PINAKES_DISABLE_CLI_PURGE=1
tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT

shopt -s nullglob
tests=(tests/*.unit.php)
if (( ${#tests[@]} == 0 )); then
  echo "✗ No tests/*.unit.php files found"
  exit 1
fi

passed=0
failed=0
skipped=0

for test_file in "${tests[@]}"; do
  output_file="${tmp_dir}/$(basename "${test_file}").log"
  echo "── ${test_file}"

  if "${PHP_BIN}" "${test_file}" >"${output_file}" 2>&1; then
    if grep -qE '^SKIP:' "${output_file}"; then
      skipped=$((skipped + 1))
      cat "${output_file}"
      if [[ "${STRICT}" == "1" ]]; then
        echo "✗ A skipped test is a CI failure (CI_STRICT_TESTS=1)"
        failed=$((failed + 1))
      else
        echo "⚠ Test skipped"
      fi
    else
      cat "${output_file}"
      passed=$((passed + 1))
    fi
  else
    cat "${output_file}"
    echo "✗ Test process failed"
    failed=$((failed + 1))
  fi
done

echo
echo "Standalone PHP tests: ${passed} passed, ${skipped} skipped, ${failed} failed (${#tests[@]} total)"
(( failed == 0 ))
