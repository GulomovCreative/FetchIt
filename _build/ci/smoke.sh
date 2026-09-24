#!/usr/bin/env bash
#
# Check a MODX site with FetchIt installed over HTTP: the page renders the form
# and the script, and action.php answers submissions.
#
# Usage: smoke.sh <base url> <fixtures json from fixtures.php>
#
# REQUIRE_FORMIT=1 fails the run when FormIt did not get installed.
#
# Every submission carries a fetchit_probe cookie. Whether cookies reach
# $_REQUEST depends on request_order, so the server should run with
# request_order=GPC for the cookie check to mean anything.

set -euo pipefail

base="${1:?base URL is required}"
fixtures="${2:?fixtures JSON is required}"
jar="$(mktemp)"
failures=0

pass() { echo "ok   - $*"; }
fail() { echo "FAIL - $*"; failures=$((failures + 1)); }

check() {
    local description="$1"; shift
    if "$@"; then pass "$description"; else fail "$description"; fi
}

# Prints the action key of the form on a page, keeping the session cookie.
# Prints nothing when the page does not load or has no form.
open_page() {
    if ! curl -fsS -c "$jar" -b "$jar" -o "$jar.html" "$base/index.php?id=$1"; then
        : > "$jar.html"
        return 0
    fi
    grep -o 'data-fetchit="[0-9a-f]\{32\}"' "$jar.html" | head -n1 | cut -d'"' -f2 || true
}

# Prints the answer of action.php, or nothing when the request fails.
submit() {
    local action="$1"; shift
    curl -fsS -c "$jar" -b "$jar" -b "fetchit_probe=1" \
        -H "Accept: application/json" \
        -H "X-FetchIt-Action: $action" \
        "$@" "$base/assets/components/fetchit/action.php" || true
}

json() { jq -e "$1" > /dev/null 2>&1 <<< "$2"; }

custom="$(jq -r '.custom' <<< "$fixtures")"
formit="$(jq -r '.formit // empty' <<< "$fixtures")"

echo "# Page with its own handler (id $custom)"
action="$(open_page "$custom")"
check "the form gets a data-fetchit key" test -n "$action"
check "the script is linked in <head>" grep -q 'components/fetchit/js/fetchit\.js?v=' "$jar.html"
check "the form is initialised" grep -q "FetchIt.create({\"action\":\"$action\"" "$jar.html"

status="$(curl -s -o /dev/null -w '%{http_code}' "$base/assets/components/fetchit/action.php")"
check "GET action.php redirects" test "$status" = 302

response="$(submit "$action" -F name=Ann -F email= -F "pageId=$custom")"
check "an invalid submission fails" json '.success == false' "$response"
check "the field error comes back" json '.data.email == "Email is required"' "$response"

response="$(submit "$action" -F name=Ann -F email=ann@example.com -F "pageId=$custom")"
check "a valid submission succeeds" json '.success == true and .message == "Thanks, ann@example.com"' "$response"
check "cookies do not reach the form fields" json '.data.cookies == []' "$response"

response="$(submit 0123456789abcdef0123456789abcdef -F email=ann@example.com)"
check "an unknown action is refused" json '.success == false' "$response"

response="$(curl -fsS -H "Accept: application/json" -F email=a "$base/assets/components/fetchit/action.php" || true)"
check "a request without the action header is refused" json '.success == false' "$response"

if [ -n "$formit" ]; then
    echo "# Page processed by FormIt (id $formit)"
    action="$(open_page "$formit")"
    check "the FormIt form gets a data-fetchit key" test -n "$action"

    response="$(submit "$action" -F name=Ann -F email=not-an-email -F "pageId=$formit")"
    check "FormIt rejects an invalid email" json '.success == false and (.data.email | length > 0)' "$response"

    response="$(submit "$action" -F name=Ann -F email=ann@example.com -F "pageId=$formit")"
    check "FormIt accepts a valid email" json '.success == true and .message == "Sent"' "$response"
elif [ "${REQUIRE_FORMIT:-}" = 1 ]; then
    fail "FormIt is installed together with FetchIt"
else
    echo "# FormIt is not installed, its checks are skipped"
fi

rm -f "$jar" "$jar.html"

if [ "$failures" -gt 0 ]; then
    echo "$failures check(s) failed"
    exit 1
fi
echo "All checks passed"
