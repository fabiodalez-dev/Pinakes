#!/usr/bin/env bash
# Browser assertions must not hide a PHP process that died on another request.
set -euo pipefail

if (( $# == 0 )); then
  echo 'Usage: ci-check-php-errors.sh <php-error-log> [...]' >&2
  exit 2
fi

failed=0
for log_file in "$@"; do
  if [[ ! -r "$log_file" ]]; then
    echo "Cannot inspect PHP error log: ${log_file}" >&2
    failed=1
    continue
  fi
  if LC_ALL=C grep -nE 'PHP (Fatal|Parse) error:|Allowed memory size of [0-9]+ bytes exhausted|Segmentation fault' "$log_file"; then
    echo "PHP fatal error detected in ${log_file}" >&2
    failed=1
  fi
done

if (( failed != 0 )); then
  exit 1
fi
echo 'No PHP fatal errors in the inspected server logs.'
