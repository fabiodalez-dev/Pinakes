#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
FILTER_FILE="$ROOT_DIR/scripts/ci-zap-blocking-alerts.jq"
PUBLIC_EXAMPLES_FILTER="$ROOT_DIR/scripts/ci-zap-public-isbn-examples.jq"
CHECK_SCRIPT="$ROOT_DIR/scripts/ci-check-zap-report.sh"
PASS_COUNT=0
TEST_CONTEXT_DIR=$(mktemp -d "${TMPDIR:-/tmp}/pinakes-zap-test.XXXXXX")
IDENTIFIERS_FILE="$TEST_CONTEXT_DIR/identifiers.json"
PUBLIC_URLS_FILE="$TEST_CONTEXT_DIR/public-urls.json"
PUBLIC_EXAMPLES_FILE="$TEST_CONTEXT_DIR/public-examples.json"
REPORT_FILE="$TEST_CONTEXT_DIR/report.json"
trap 'rm -f "$IDENTIFIERS_FILE" "$PUBLIC_URLS_FILE" "$PUBLIC_EXAMPLES_FILE" "$REPORT_FILE"; rmdir "$TEST_CONTEXT_DIR"' EXIT

printf '%s\n' '["5634784354285"]' > "$IDENTIFIERS_FILE"
printf '%s\n' '["http://localhost:8081/jane-austen/pride-and-prejudice/123","http://localhost:8081/fr/jane-austen/orgueil-et-prejuges/123"]' > "$PUBLIC_URLS_FILE"
jq -f "$PUBLIC_EXAMPLES_FILTER" "$ROOT_DIR/locale/it_IT.json" > "$PUBLIC_EXAMPLES_FILE"

expected_public_examples='["9788842935780","9788845292866"]'
if [[ "$(jq -c . "$PUBLIC_EXAMPLES_FILE")" != "$expected_public_examples" ]]; then
    echo 'FAIL: canonical public ISBN examples changed unexpectedly' >&2
    exit 1
fi
PASS_COUNT=$((PASS_COUNT + 1))

extractor_fixture='{
  "es. 9788842935780": "public ISBN example",
  "support": "public card example 4222222222222",
  "tracking": "prefix 978884529286612345",
  "es. 4222222222222": "not an ISBN namespace"
}'
if [[ "$(jq -c -f "$PUBLIC_EXAMPLES_FILTER" <<<"$extractor_fixture")" != '["9788842935780"]' ]]; then
    echo 'FAIL: public example extractor admitted an unrelated number or substring' >&2
    exit 1
fi
PASS_COUNT=$((PASS_COUNT + 1))

invalid_checksum_fixture='{"es. 9788842935781":"invalid checksum"}'
if jq -f "$PUBLIC_EXAMPLES_FILTER" <<<"$invalid_checksum_fixture" >/dev/null 2>&1; then
    echo 'FAIL: public example extractor accepted an invalid ISBN-13 checksum' >&2
    exit 1
fi
PASS_COUNT=$((PASS_COUNT + 1))

make_alert() {
    local uri=$1
    local evidence=${2:-5634784354285}
    local method=${3:-GET}
    local plugin_id=${4:-10062}

    jq -cn \
        --arg uri "$uri" \
        --arg evidence "$evidence" \
        --arg method "$method" \
        --arg plugin_id "$plugin_id" \
        '{riskcode:"3", riskdesc:"High", alert:"fixture", pluginid:$plugin_id, instances:[{uri:$uri, evidence:$evidence, method:$method, param:"", attack:""}]}'
}

make_csp_wildcard_alert() {
    local uri=${1:-http://localhost:8081/}
    local directives=${2:-img-src, frame-src, media-src}
    local policy
    if [[ $# -ge 3 ]]; then
        policy=$3
    else
        policy="default-src 'self'; script-src 'self' 'nonce-test'; style-src 'self' 'nonce-test'; img-src 'self' https:; media-src 'self' https:; frame-src 'self' https:; object-src 'none'; base-uri 'self'; form-action 'self'"
    fi

    jq -cn \
        --arg uri "$uri" \
        --arg directives "$directives" \
        --arg policy "$policy" \
        '{riskcode:"2", riskdesc:"Medium", alert:"CSP: Wildcard Directive", pluginid:"10055", instances:[{uri:$uri, evidence:$policy, method:"GET", param:"Content-Security-Policy", attack:"", otherinfo:("Wildcard directives:\n" + $directives)}]}'
}

blocking_count() {
    local document
    document=$(jq -cn --argjson alert "$1" '{site:[{alerts:[$alert]}]}')
    filter_count "$document"
}

filter_count() {
    jq -c . <<<"$1" \
        | jq -s \
            --slurpfile catalogue_identifiers "$IDENTIFIERS_FILE" \
            --slurpfile public_catalogue_urls "$PUBLIC_URLS_FILE" \
            --slurpfile public_example_identifiers "$PUBLIC_EXAMPLES_FILE" \
            -f "$FILTER_FILE" \
        | jq 'length'
}

assert_allowed() {
    local label=$1
    local alert=$2
    local actual
    actual=$(blocking_count "$alert")
    if [[ "$actual" != "0" ]]; then
        echo "FAIL: expected allowlisted alert: $label" >&2
        exit 1
    fi
    PASS_COUNT=$((PASS_COUNT + 1))
}

assert_blocked() {
    local label=$1
    local alert=$2
    local actual
    actual=$(blocking_count "$alert")
    if [[ "$actual" != "1" ]]; then
        echo "FAIL: expected blocking alert: $label" >&2
        exit 1
    fi
    PASS_COUNT=$((PASS_COUNT + 1))
}

assert_rejected_report() {
    local label=$1
    local document=$2
    if filter_count "$document" >/dev/null 2>&1; then
        echo "FAIL: expected malformed report rejection: $label" >&2
        exit 1
    fi
    PASS_COUNT=$((PASS_COUNT + 1))
}

# Every localized catalogue route is part of the explicit bibliographic
# allowlist, both at the root and behind its locale prefix.
ROUTE_KEYS=(catalog catalog_legacy book book_legacy author publisher genre api_catalog api_book)
routes_matrix=''
for routes_file in "$ROOT_DIR"/locale/routes_*.json; do
    locale=$(basename "$routes_file" | sed -E 's/^routes_([a-z]{2})_[A-Z]{2}\.json$/\1/')
    rows=$(jq -r --arg locale "$locale" \
        '["catalog", "catalog_legacy", "book", "book_legacy", "author", "publisher", "genre", "api_catalog", "api_book"][] as $key | [$locale, $key, .[$key]] | @tsv' \
        "$routes_file")
    routes_matrix+="${routes_matrix:+$'\n'}$rows"
done

expected_routes=$((5 * ${#ROUTE_KEYS[@]}))
actual_routes=$(wc -l <<<"$routes_matrix" | tr -d ' ')
if [[ "$actual_routes" != "$expected_routes" ]]; then
    echo "FAIL: expected $expected_routes localized route rows, got $actual_routes" >&2
    exit 1
fi

while IFS=$'\t' read -r locale key route; do
    case "$key" in
        catalog|catalog_legacy|api_catalog) suffix='?fixture=1' ;;
        book) suffix='/123/example-title' ;;
        book_legacy) suffix='?id=123' ;;
        author|publisher|genre) suffix='/Fixture+Name' ;;
        api_book) suffix='/123/availability' ;;
        *) echo "FAIL: unsupported route key: $key" >&2; exit 1 ;;
    esac
    if [[ "$key" == 'api_book' ]]; then
        # This endpoint emits dates/booleans, not bibliographic identifiers;
        # keep it outside the PII exception even though it is a book API.
        assert_blocked "$locale$route" "$(make_alert "http://localhost:8081$route$suffix")"
        assert_blocked "$locale/$locale$route" "$(make_alert "http://localhost:8081/$locale$route$suffix")"
    else
        assert_allowed "$locale$route" "$(make_alert "http://localhost:8081$route$suffix")"
        assert_allowed "$locale/$locale$route" "$(make_alert "http://localhost:8081/$locale$route$suffix")"
    fi
done <<<"$routes_matrix"

assert_allowed 'BIBFRAME book API' "$(make_alert 'http://localhost:8081/api/bibframe/book/123')"
assert_allowed 'localized BIBFRAME book API' "$(make_alert 'http://localhost:8081/da/api/bibframe/book/123')"
assert_allowed 'BIBFRAME Work API' "$(make_alert 'http://localhost:8081/api/bibframe/book/123/work')"
assert_allowed 'BIBFRAME Instance API' "$(make_alert 'http://localhost:8081/api/bibframe/book/123/instance')"
assert_allowed 'BIBFRAME Instance identifier' "$(make_alert 'http://localhost:8081/id/instance/123')"
assert_allowed 'RDA Manifestation endpoint' "$(make_alert 'http://localhost:8081/libri/123.rda.json')"
assert_allowed 'real Danish publisher finding' "$(make_alert 'http://localhost:8081/da/forlag/CSV+Publisher')"
assert_allowed 'canonical book route' "$(make_alert 'http://localhost:8081/jane-austen/pride-and-prejudice/123')"
assert_allowed 'localized canonical book route' "$(make_alert 'http://localhost:8081/fr/jane-austen/orgueil-et-prejuges/123')"
assert_allowed 'shipped ISBN example on themed 404' "$(make_alert 'http://localhost:8081/en/register' '9788842935780')"
assert_allowed 'second shipped ISBN example on an ordinary page' "$(make_alert 'http://localhost:8081/accedi' '9788845292866')"

assert_blocked '15-digit evidence' "$(make_alert 'http://localhost:8081/da/forlag/Test' '123456789012345')"
assert_blocked '16-digit evidence' "$(make_alert 'http://localhost:8081/da/forlag/Test' '4111111111111111')"
assert_blocked 'POST request' "$(make_alert 'http://localhost:8081/da/forlag/Test' '5634784354285' 'POST')"
assert_blocked 'admin books route' "$(make_alert 'http://localhost:8081/admin/books/123')"
assert_blocked 'admin Italian books route' "$(make_alert 'http://localhost:8081/admin/libri/123')"
assert_blocked 'admin publishers route' "$(make_alert 'http://localhost:8081/admin/editori/123')"
assert_blocked 'near-match route' "$(make_alert 'http://localhost:8081/da/forlaget/Test')"
assert_blocked 'unknown locale' "$(make_alert 'http://localhost:8081/xx/forlag/Test')"
assert_blocked 'nested publisher path' "$(make_alert 'http://localhost:8081/da/forlag/account/payment')"
assert_blocked 'nonexistent API descendant' "$(make_alert 'http://localhost:8081/api/book/42/private-payment-data')"
assert_blocked 'nonexistent catalogue descendant' "$(make_alert 'http://localhost:8081/catalog/not-an-app-route')"
assert_blocked 'external host' "$(make_alert 'https://example.com/da/forlag/Test')"
assert_blocked 'shipped example on external host' "$(make_alert 'https://example.com/en/register' '9788842935780')"
assert_blocked 'reserved admin canonical shape' "$(make_alert 'http://localhost:8081/admin/settings/123')"
assert_blocked 'localized reserved admin canonical shape' "$(make_alert 'http://localhost:8081/da/admin/settings/123')"
assert_blocked 'reserved API canonical shape' "$(make_alert 'http://localhost:8081/api/private/123')"
assert_blocked 'loan details are not a canonical URL' "$(make_alert 'http://localhost:8081/prestiti/dettagli/123')"
assert_blocked 'unregistered canonical-shaped URL' "$(make_alert 'http://localhost:8081/private/payment/123')"
assert_blocked 'unknown identifier on catalogue page' "$(make_alert 'http://localhost:8081/da/forlag/Test' '9781234567897')"
assert_blocked 'unknown 13-digit value on ordinary page' "$(make_alert 'http://localhost:8081/en/register' '9781234567897')"
assert_blocked 'another ZAP rule' "$(make_alert 'http://localhost:8081/da/forlag/Test' '5634784354285' 'GET' '10020')"

assert_allowed 'intended passive CSP wildcard directives' "$(make_csp_wildcard_alert)"
assert_blocked 'CSP wildcard on active script source' "$(make_csp_wildcard_alert \
    'http://localhost:8081/' \
    'img-src, frame-src, media-src' \
    "default-src 'self'; script-src https:; style-src 'self'; img-src 'self' https:; object-src 'none'; base-uri 'self'; form-action 'self'")"
assert_blocked 'CSP wildcard on an unapproved directive' "$(make_csp_wildcard_alert \
    'http://localhost:8081/' \
    'connect-src')"
assert_blocked 'CSP wildcard finding from an external origin' "$(make_csp_wildcard_alert \
    'https://example.com/')"

csp_post_alert=$(make_csp_wildcard_alert)
csp_post_alert=$(jq -c '.instances[0].method = "POST"' <<<"$csp_post_alert")
assert_blocked 'CSP wildcard finding on a non-GET request' "$csp_post_alert"

csp_param_alert=$(make_csp_wildcard_alert)
csp_param_alert=$(jq -c '.instances[0].param = "X-Content-Security-Policy"' <<<"$csp_param_alert")
assert_blocked 'CSP wildcard finding on a different header param' "$csp_param_alert"

csp_no_otherinfo_alert=$(make_csp_wildcard_alert)
csp_no_otherinfo_alert=$(jq -c 'del(.instances[0].otherinfo)' <<<"$csp_no_otherinfo_alert")
assert_blocked 'CSP wildcard finding without the directive list (otherinfo)' "$csp_no_otherinfo_alert"

assert_blocked 'CSP wildcard with a policy missing the strict anchors' "$(make_csp_wildcard_alert \
    'http://localhost:8081/' \
    'img-src, frame-src, media-src' \
    "default-src 'self'; script-src 'self' 'nonce-test'; style-src 'self' 'nonce-test'; img-src 'self' https:; media-src 'self' https:; frame-src 'self' https:; base-uri 'self'; form-action 'self'")"

mixed_alert=$(make_alert 'http://localhost:8081/da/forlag/Test')
mixed_alert=$(jq -c '.instances += [{uri:"http://localhost:8081/admin/settings", evidence:"5634784354285", method:"GET", param:"", attack:""}]' <<<"$mixed_alert")
assert_blocked 'mixed safe and unsafe instances' "$mixed_alert"

empty_alert=$(make_alert 'http://localhost:8081/da/forlag/Test')
empty_alert=$(jq -c '.instances = []' <<<"$empty_alert")
assert_blocked 'empty instances array' "$empty_alert"

param_alert=$(make_alert 'http://localhost:8081/da/forlag/Test')
param_alert=$(jq -c '.instances[0].param = "card"' <<<"$param_alert")
assert_blocked 'parameter evidence' "$param_alert"

attack_alert=$(make_alert 'http://localhost:8081/da/forlag/Test')
attack_alert=$(jq -c '.instances[0].attack = "5634784354285"' <<<"$attack_alert")
assert_blocked 'attack payload evidence' "$attack_alert"

missing_param_alert=$(make_alert 'http://localhost:8081/da/forlag/Test')
missing_param_alert=$(jq -c 'del(.instances[0].param)' <<<"$missing_param_alert")
assert_blocked 'missing parameter field' "$missing_param_alert"

null_param_alert=$(make_alert 'http://localhost:8081/da/forlag/Test')
null_param_alert=$(jq -c '.instances[0].param = null' <<<"$null_param_alert")
assert_blocked 'null parameter field' "$null_param_alert"

missing_attack_alert=$(make_alert 'http://localhost:8081/da/forlag/Test')
missing_attack_alert=$(jq -c 'del(.instances[0].attack)' <<<"$missing_attack_alert")
assert_blocked 'missing attack field' "$missing_attack_alert"

null_attack_alert=$(make_alert 'http://localhost:8081/da/forlag/Test')
null_attack_alert=$(jq -c '.instances[0].attack = null' <<<"$null_attack_alert")
assert_blocked 'null attack field' "$null_attack_alert"

assert_rejected_report 'missing site' '{}'
assert_rejected_report 'empty site array' '{"site":[]}'
assert_rejected_report 'missing alerts array' '{"site":[{}]}'
assert_rejected_report 'alerts has wrong type' '{"site":[{"alerts":null}]}'
assert_rejected_report 'site has wrong type' '{"site":{}}'
assert_rejected_report 'null alert' '{"site":[{"alerts":[null]}]}'
assert_rejected_report 'missing riskcode' '{"site":[{"alerts":[{}]}]}'
assert_rejected_report 'null riskcode' '{"site":[{"alerts":[{"riskcode":null}]}]}'
assert_rejected_report 'non-numeric riskcode' '{"site":[{"alerts":[{"riskcode":"High"}]}]}'
assert_rejected_report 'negative riskcode' '{"site":[{"alerts":[{"riskcode":-1}]}]}'
assert_rejected_report 'fractional riskcode' '{"site":[{"alerts":[{"riskcode":0.5}]}]}'
assert_rejected_report 'zero-padded riskcode' '{"site":[{"alerts":[{"riskcode":"01"}]}]}'
assert_rejected_report 'NaN riskcode' '{"site":[{"alerts":[{"riskcode":NaN}]}]}'
assert_rejected_report 'multiple JSON documents' $'{"site":[{"alerts":[]}]}\n{"site":[{"alerts":[{"riskcode":"3"}]}]}'

# Exercise the exact workflow wrapper, not only the jq policy in isolation.
printf '%s\n' '{"site":[{"alerts":[]}]}' > "$REPORT_FILE"
bash "$CHECK_SCRIPT" "$REPORT_FILE" "$IDENTIFIERS_FILE" "$PUBLIC_URLS_FILE" "$PUBLIC_EXAMPLES_FILE" >/dev/null
PASS_COUNT=$((PASS_COUNT + 1))
printf '%s\n' '{}' > "$REPORT_FILE"
if bash "$CHECK_SCRIPT" "$REPORT_FILE" "$IDENTIFIERS_FILE" "$PUBLIC_URLS_FILE" "$PUBLIC_EXAMPLES_FILE" >/dev/null 2>&1; then
    echo 'FAIL: workflow wrapper accepted an invalid report' >&2
    exit 1
fi
PASS_COUNT=$((PASS_COUNT + 1))
printf '%s\n%s\n' '{"site":[{"alerts":[]}]}' '{"site":[{"alerts":[]}]}' > "$REPORT_FILE"
if bash "$CHECK_SCRIPT" "$REPORT_FILE" "$IDENTIFIERS_FILE" "$PUBLIC_URLS_FILE" "$PUBLIC_EXAMPLES_FILE" >/dev/null 2>&1; then
    echo 'FAIL: workflow wrapper accepted multiple JSON documents' >&2
    exit 1
fi
PASS_COUNT=$((PASS_COUNT + 1))

echo "ZAP PII filter tests passed: $PASS_COUNT"
