#!/usr/bin/env bash
#
# Check a MODX site with FetchIt installed over HTTP: the page renders the form
# and the script, and action.php answers submissions.
#
# Usage: smoke.sh <base url> <fixtures json from fixtures.php>
#
# The spam protection has to let these checks through: set
# fetchit.protection.min_time and fetchit.protection.rate_limit to 0 first,
# as CI does (the time and the limit are checked by protection.sh). Every
# submission carries the token of the form, the next one coming from the
# answer.
#
# REQUIRE_FORMIT=1 / REQUIRE_PDOTOOLS=1 fail the run when FormIt / pdoTools
# did not get installed. EXPECT_PACKAGE=<signature> checks that it is the
# newest FetchIt package installed.
#
# Every submission carries a fetchit_probe cookie. Whether cookies reach
# $_REQUEST depends on request_order, so the server should run with
# request_order=GPC for the cookie check to mean anything.

set -euo pipefail

base="${1:?base URL is required}"
fixtures="${2:?fixtures JSON is required}"
# shellcheck source=_build/ci/lib.sh
. "$(dirname "$0")/lib.sh"

modx="$(jq -r '.modx' <<< "$fixtures")"
custom="$(jq -r '.custom' <<< "$fixtures")"
api="$(jq -r '.api' <<< "$fixtures")"
probe="$(jq -r '.probe' <<< "$fixtures")"
formit="$(jq -r '.formit // empty' <<< "$fixtures")"
pdotools="$(jq -r '.pdotools // empty' <<< "$fixtures")"

echo "# Page with its own handler (id $custom)"
action="$(open_page "$custom")"
check "the form gets a data-fetchit key" test -n "$action"
check "the script is linked in <head>" grep -q 'components/fetchit/js/fetchit\.js?v=' "$jar.html"
check "the form is initialised" grep -q "FetchItClass = FetchIt; .*FetchItClass.create({\"action\":\"$action\"" "$jar.html"

status="$(curl -s -o /dev/null -w '%{http_code}' "$base/assets/components/fetchit/action.php" || true)"
check "GET action.php redirects" test "$status" = 302

response="$(submit "$action" -F name=Ann -F email= -F "pageId=$custom")"
check "an invalid submission fails" json '.success == false' "$response"
check "the field error comes back" json '.data.email == "Email is required"' "$response"
check "the error of an array field comes back" json '.data.topics == "Pick a topic"' "$response"

response="$(submit "$action" -F name=Ann -F email=ann@example.com -F "pageId=$custom")"
check "a valid submission succeeds" json '.success == true and .message == "Thanks, ann@example.com"' "$response"
check "cookies do not reach the form fields" json '.data.cookies == []' "$response"

printf 'hello' > "$jar.upload"
response="$(submit "$action" -F email=ann@example.com -F "topics[]=news" -F "topics[]=events" \
    -F "attachment=@$jar.upload;filename=hello.txt" -F "pageId=$custom")"
check "array fields arrive as arrays" json '.data.topics == ["news", "events"]' "$response"
check "an uploaded file reaches the snippet" json '.data.file == "hello.txt:5"' "$response"

for asset in lib/notyf.min.js lib/notyf.min.css js/fetchit.min.js; do
    status="$(curl -s -o /dev/null -w '%{http_code}' "$base/assets/components/fetchit/$asset" || true)"
    check "the package ships $asset" test "$status" = 200
done

response="$(submit 0123456789abcdef0123456789abcdef -F email=ann@example.com)"
check "an unknown action is refused" json '.success == false' "$response"

response="$(curl -fsS -H "Accept: application/json" -F email=a "$base/assets/components/fetchit/action.php" || true)"
check "a request without the action header is refused" json '.success == false' "$response"

echo "# The installed package"
check "the snippet and plugin in the database are FetchIt 4" json '.installed.elements == true' "$fixtures"
if [ -n "${EXPECT_PACKAGE:-}" ]; then
    check "$EXPECT_PACKAGE is the newest installed package" json ".installed.package == \"$EXPECT_PACKAGE\"" "$fixtures"
fi

echo "# What bootstrap.php set up on MODX $modx (id $probe)"
expected="container=0 namespaced=0"
[ "$modx" = 3 ] && expected="container=1 namespaced=1"
open_page "$probe" > /dev/null
check "the page sees $expected" grep -q "<p id=\"probe\">$expected</p>" "$jar.html"

echo "# The FetchIt API of custom snippets on MODX $modx (id $api)"
action="$(open_page "$api")"
response="$(submit "$action" -F email=ann@example.com -F "pageId=$api")"
expected="1.x"
[ "$modx" = 3 ] && expected="3.x"
check "a snippet gets FetchIt as in FetchIt $expected" json ".success == true and .message == \"API $expected\" and .data.class == true" "$response"
check "1.x, 3.x and FetchIt::service() give one instance" json '.data.same == true' "$response"
check "the 3.x action property methods work" json '.data.props == true' "$response"

if [ -n "$pdotools" ]; then
    echo "# pdoTools: Fenom in an @FILE chunk (id $pdotools)"
    action="$(open_page "$pdotools")"
    check "the Fenom chunk is rendered" grep -q '<h2 class="fenom">Fenom works</h2>' "$jar.html"
    check "the @FILE form gets a data-fetchit key" test -n "$action"
    response="$(submit "$action" -F email=ann@example.com -F "pageId=$pdotools")"
    check "the @FILE form is sent" json '.success == true' "$response"
elif [ "${REQUIRE_PDOTOOLS:-}" = 1 ]; then
    fail "pdoTools is installed"
fi

echo "# Spam protection (id $custom)"
action="$(open_page "$custom")"
trap="$(trap_name)"
check "the form carries a token" test -s "$jar.token"
check "the form carries the trap" test -n "$trap"
check "the trap has no name autofill knows" lacks 'name="fetchit_website"' "$jar.html"
used="$(cat "$jar.token")"
response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
check "a token passes once" json '.success == true' "$response"
check "the service fields do not reach the snippet or \$_POST" json '.data.service == []' "$response"
check "the answer brings the next token" test "$(cat "$jar.token")" != "$used"
printf '%s' "$used" > "$jar.token"
response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
check "a used token is refused" json '.success == false' "$response"
check "... as a stale token" test "$(header x-fetchit-refused)" = token
check "... with a new token to send again" test -n "$(header x-fetchit-token)"
: > "$jar.token"
response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
check "a submission without a token is refused" json '.success == false' "$response"
check "... and gets no token" test -z "$(header x-fetchit-token)"
printf '%s' "${used%.*}.$(printf '0%.0s' $(seq 1 64))" > "$jar.token"
response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
check "a forged signature is refused and gets no token" test -z "$(header x-fetchit-token)"
action="$(open_page "$custom")"
response="$(submit "$action" -F email=ann@example.com -F "$trap=http://spam.example" -F "pageId=$custom")"
check "the trap gets a success that does not reach the snippet" json '.success == true and (.message | startswith("Thanks") | not)' "$response"
response="$(submit "$action" -F email=blocked@example.com -F "pageId=$custom")"
check "a plugin on OnFetchItBeforeProcess refuses" json '.success == false and .message == "Blocked by a plugin"' "$response"
check "... as a plugin" test "$(header x-fetchit-refused)" = plugin

if [ -n "$formit" ]; then
    echo "# Page processed by FormIt (id $formit)"
    action="$(open_page "$formit")"
    check "the FormIt form gets a data-fetchit key" test -n "$action"
    # FormIt 5.2+ links its own AJAX script, whose action.php would process
    # the form without the protection; FetchIt turns it off.
    check "the AJAX script of FormIt is not linked" lacks 'formit/js/web/formit\.js\|Object\.assign(FormIt' "$jar.html"

    response="$(submit "$action" -F name=Ann -F email=not-an-email -F "pageId=$formit")"
    check "FormIt rejects an invalid email" json '.success == false and (.data.email | length > 0)' "$response"

    response="$(submit "$action" -F name=Ann -F email=ann@example.com -F "pageId=$formit")"
    check "FormIt accepts a valid email" json '.success == true and .message == "Sent"' "$response"

    echo "# A form sent without JavaScript (id $formit)"
    open_page "$formit" > /dev/null
    curl -fsS -c "$jar" -b "$jar" -o "$jar.html" -F name=Ann -F email=not-an-email "$base/index.php?id=$formit" || : > "$jar.html"
    check "the page renders after a POST without a token" grep -q 'data-fetchit="' "$jar.html"
    check "a POST without a token does not reach FormIt" lacks 'data-error="email">[^<]' "$jar.html"
    curl -fsS -c "$jar" -b "$jar" -o "$jar.html" -F "fetchit_token=$(cat "$jar.token")" \
        -F name=Ann -F email=not-an-email "$base/index.php?id=$formit" || : > "$jar.html"
    check "a POST with the token of the form reaches FormIt" grep -q 'data-error="email">[^<]' "$jar.html"
elif [ "${REQUIRE_FORMIT:-}" = 1 ]; then
    fail "FormIt is installed together with FetchIt"
else
    echo "# FormIt is not installed, its checks are skipped"
fi

finish
