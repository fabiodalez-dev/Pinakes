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

# ZAP's PII rule (10062) flags any Luhn-valid digit window, and every page
# embeds per-session random hex tokens (the CSRF meta tag): an all-digit
# window inside one occasionally forms a plausible "card number" that exists
# only in the response ZAP happened to receive (observed: 6736206100179663,
# classified as Maestro, inside a CSRF token on /editore/E2E%20Editore).
# Genuine PII is persistent — it is stored data, so it renders again on a
# fresh anonymous fetch. Verify each still-blocking 10062 instance against
# the live target: only when the exact digits are absent from BOTH of two
# fresh fetches is the instance ephemeral session noise. Any fetch error or
# re-appearance keeps the alert blocking, and non-10062 alerts are never
# re-checked (fail-secure).
is_ephemeral_pii_instance() {
    local uri=$1 evidence=$2 body
    for _ in 1 2; do
        if ! body=$(curl -fsS --max-time 30 "$uri" 2>/dev/null); then
            return 1
        fi
        if grep -Fq -- "$evidence" <<<"$body"; then
            return 1
        fi
    done
    return 0
}

blocking=$(jq -r 'length' "$BLOCKING_FILE")
if [[ "$blocking" -gt 0 ]]; then
    VERIFIED_FILE=$(mktemp "${TMPDIR:-/tmp}/pinakes-zap-verified.XXXXXX")
    trap 'rm -f "$BLOCKING_FILE" "$VERIFIED_FILE"' EXIT
    printf '[]' > "$VERIFIED_FILE"
    for ((alert_index = 0; alert_index < blocking; alert_index++)); do
        alert=$(jq -c --argjson i "$alert_index" '.[$i]' "$BLOCKING_FILE")
        plugin=$(jq -r '(.pluginid // "") | tostring' <<<"$alert")
        total_instances=$(jq -r '(.instances // []) | length' <<<"$alert")
        # Only anonymous GETs of the scanned origin with digit-only evidence
        # qualify for re-verification; anything else stays blocking as-is.
        candidate_instances=$(jq -r '
            [ .instances[]?
              | select(
                  ((.method // "") | ascii_upcase) == "GET"
                  and (.param // "x") == ""
                  and (.attack // "x") == ""
                  and ((.uri // "") | test("^http://localhost:8081(?:[/?#]|$)"))
                  and ((.evidence // "") | test("^[0-9]{13,19}$"))
                )
            ] | length' <<<"$alert")
        keep=1
        if [[ "$plugin" == "10062" && "$total_instances" -gt 0 && "$candidate_instances" -eq "$total_instances" ]]; then
            keep=0
            while IFS=$'\t' read -r uri evidence; do
                if ! is_ephemeral_pii_instance "$uri" "$evidence"; then
                    keep=1
                    break
                fi
                echo "ignoring ephemeral PII Disclosure instance: evidence $evidence is absent from fresh fetches of $uri (session-token artifact, not stored data)"
            done < <(jq -r '.instances[] | [(.uri // ""), (.evidence // "")] | @tsv' <<<"$alert")
        fi
        if [[ "$keep" -eq 1 ]]; then
            jq -c --argjson alert "$alert" '. + [$alert]' "$VERIFIED_FILE" > "$VERIFIED_FILE.tmp"
            mv "$VERIFIED_FILE.tmp" "$VERIFIED_FILE"
        fi
    done
    mv "$VERIFIED_FILE" "$BLOCKING_FILE"
    trap 'rm -f "$BLOCKING_FILE"' EXIT
    blocking=$(jq -r 'length' "$BLOCKING_FILE")
fi

if [[ "$blocking" -gt 0 ]]; then
    jq -r '.[] | "[" + (.riskdesc // "") + "] " + (.alert // "") + ": " + (.desc // "")' "$BLOCKING_FILE"
    exit 1
fi

echo "ZAP found no blocking medium/high passive-scan alerts (allowlisted: known catalogue identifiers on bibliographic pages and shipped public ISBN examples on the target origin)"
