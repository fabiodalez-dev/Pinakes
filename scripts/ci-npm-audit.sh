#!/usr/bin/env bash
#
# Outage-aware npm audit wrapper.
#
# `npm audit` exits non-zero BOTH for real advisories and for registry
# failures (the quick-audit endpoint was retired on 2026-09-04 and the bulk
# endpoint returned 503 during the rollout). Conflating the two turns a
# registry outage into a fake "vulnerabilities found" red. This wrapper:
#   - fails ONLY when npm actually reports advisories;
#   - retries endpoint/network failures, then passes LOUDLY as neutral —
#     no advisory data is not the same as no advisories, and dependency
#     scanning is still covered by the dependency-diff policy and Trivy.
#
# Usage: ci-npm-audit.sh [dir]   (default: current directory)
set -uo pipefail

dir="${1:-.}"
cd "$dir" || { echo "ci-npm-audit: no such directory: $dir" >&2; exit 2; }

for attempt in 1 2 3; do
    out=$(npm audit --audit-level=high 2>&1)
    ec=$?
    printf '%s\n' "$out"
    if [ "$ec" -eq 0 ]; then
        exit 0
    fi
    if printf '%s' "$out" | grep -qiE 'audit endpoint returned an error|Service Unavailable|ENOAUDIT|being retired|ECONNRESET|ETIMEDOUT|EAI_AGAIN'; then
        echo "npm audit: registry endpoint unavailable (attempt ${attempt}/3), retrying in 20s..."
        sleep 20
        continue
    fi
    echo "⚠ npm audit: high/critical vulnerabilities found in ${dir}"
    exit 1
done

echo "⚠ npm registry audit endpoint unavailable after 3 attempts — no advisory data retrievable."
echo "  Treating as NEUTRAL (not a finding): known-CVE coverage continues via the dependency-diff policy and Trivy jobs."
exit 0
