# shellcheck shell=bash
# Helpers of smoke.sh and protection.sh: set base, then source this file.
# The pages are opened and the forms sent with one cookie jar, and the
# protection token of the form is kept in $jar.token between requests.

# shellcheck disable=SC2154 # base is set by the script that sources this
jar="$(mktemp)"
trap 'rm -f "$jar" "$jar.html" "$jar.upload" "$jar.token" "$jar.headers"' EXIT
failures=0

pass() { echo "ok   - $*"; }
fail() { echo "FAIL - $*"; failures=$((failures + 1)); }

check() {
    local description="$1"; shift
    if "$@"; then pass "$description"; else fail "$description"; fi
}

# Saves a page to $jar.html and the protection token of its first form to
# $jar.token, and prints the action key of the form, keeping the session
# cookie. Prints nothing when the page does not load or has no form.
open_page() {
    : > "$jar.token"
    if ! curl -fsS -c "$jar" -b "$jar" -o "$jar.html" "$base/index.php?id=$1"; then
        : > "$jar.html"
        return 0
    fi
    { grep -o 'name="fetchit_token" value="[^"]*"' "$jar.html" || true; } | head -n1 | cut -d'"' -f4 > "$jar.token"
    grep -o 'data-fetchit="[0-9a-f]\{32\}"' "$jar.html" | head -n1 | cut -d'"' -f2 || true
}

# Prints the answer of action.php, or nothing when the request fails. Sends
# the token from $jar.token and keeps the next one from the answer there.
submit() {
    local action="$1"; shift
    local token=()
    if [ -s "$jar.token" ]; then
        token=(-F "fetchit_token=$(cat "$jar.token")")
    fi
    : > "$jar.headers"
    curl -fsS -c "$jar" -b "$jar" -b "fetchit_probe=1" -D "$jar.headers" \
        -H "Accept: application/json" \
        -H "X-FetchIt-Action: $action" \
        "${token[@]}" "$@" "$base/assets/components/fetchit/action.php" || true
    local next
    next="$({ grep -i '^x-fetchit-token:' "$jar.headers" || true; } | cut -d' ' -f2 | tr -d '\r')"
    if [ -n "$next" ]; then
        printf '%s' "$next" > "$jar.token"
    fi
}

# Succeeds when a file does not match a pattern.
lacks() { ! grep -q "$1" "$2"; }

# Checks an answer with jq; shows the start of the answer when it fails.
json() {
    if jq -e "$1" > /dev/null 2>&1 <<< "$2"; then
        return 0
    fi
    echo "       answer: $(printf '%s' "${2:-<empty>}" | head -c 300)"
    return 1
}

# Prints the summary and exits non-zero when a check failed.
finish() {
    if [ "$failures" -gt 0 ]; then
        echo "$failures check(s) failed"
        exit 1
    fi
    echo "All checks passed"
}
