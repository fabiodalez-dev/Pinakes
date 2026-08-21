#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 4 ]]; then
    echo "Usage: $0 REPORT_JSON CATALOGUE_IDENTIFIERS_JSON PUBLIC_CATALOGUE_URLS_JSON PUBLIC_EXAMPLE_IDENTIFIERS_JSON" >&2
    exit 2
fi

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
REPORT_FILE=$1
IDENTIFIERS_FILE=$2
PUBLIC_URLS_FILE=$3
PUBLIC_EXAMPLES_FILE=$4
BLOCKING_FILE=$(mktemp "${TMPDIR:-/tmp}/pinakes-zap-blocking.XXXXXX")
trap 'rm -f "$BLOCKING_FILE"' EXIT

test -s "$REPORT_FILE" || { echo "ZAP JSON report is missing" >&2; exit 1; }
test -s "$IDENTIFIERS_FILE" || { echo "Catalogue identifier context is missing" >&2; exit 1; }
test -s "$PUBLIC_URLS_FILE" || { echo "Public URL context is missing" >&2; exit 1; }
test -s "$PUBLIC_EXAMPLES_FILE" || { echo "Public example identifier context is missing" >&2; exit 1; }

# Slurp deliberately: the jq policy rejects zero or multiple JSON documents.
# Materialize its single validated array before counting so an upstream jq
# error cannot be swallowed by a pipeline or a non-numeric shell comparison.
jq -s \
    --slurpfile catalogue_identifiers "$IDENTIFIERS_FILE" \
    --slurpfile public_catalogue_urls "$PUBLIC_URLS_FILE" \
    --slurpfile public_example_identifiers "$PUBLIC_EXAMPLES_FILE" \
    -f "$ROOT_DIR/scripts/ci-zap-blocking-alerts.jq" \
    "$REPORT_FILE" > "$BLOCKING_FILE"
jq -e 'type == "array"' "$BLOCKING_FILE" >/dev/null

blocking=$(jq -r 'length' "$BLOCKING_FILE")
if [[ "$blocking" -gt 0 ]]; then
    jq -r '.[] | "[" + (.riskdesc // "") + "] " + (.alert // "") + ": " + (.desc // "")' "$BLOCKING_FILE"
    exit 1
fi

echo "ZAP found no blocking medium/high passive-scan alerts (allowlisted: known catalogue identifiers on bibliographic pages and shipped public ISBN examples on the target origin)"
