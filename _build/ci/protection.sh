#!/usr/bin/env bash
#
# Check the parts of the spam protection that depend on its settings, which
# the caller sets first (_build/ci/setting.php):
#
#   too-fast    fetchit.protection.min_time 4: a form sent 2 s after the page
#               loaded is refused, and the next token keeps the time of the
#               page, so sending it again 2.5 s later passes; a token timed
#               from the refusal would still be too fast then (the times are
#               whole seconds, hence the margins). A form sent without
#               JavaScript that fast shows the refusal and keeps the values.
#   rate-limit  fetchit.protection.rate_limit 2: the third submission of the
#               form from this address is refused.
#   pow         fetchit.protection.pow 12: the page asks for the proof of
#               work, a form sent without it or with a wrong one is refused,
#               and one with a solution for its token passes.
#   captcha     fetchit.captcha turnstile with the test keys of Cloudflare
#               (the secret that always passes): the page links the script of
#               Turnstile, a form without an answer is refused, and one with
#               the test answer passes. Needs access to Cloudflare.
#
# Refusals are recognised by the X-FetchIt-Refused header, whatever the
# language of the site.
#
# Usage: protection.sh <base url> <fixtures json from fixtures.php> <check>

set -euo pipefail

base="${1:?base URL is required}"
fixtures="${2:?fixtures JSON is required}"
what="${3:?too-fast, rate-limit, pow or captcha}"
# shellcheck source=_build/ci/lib.sh
. "$(dirname "$0")/lib.sh"

custom="$(jq -r '.custom' <<< "$fixtures")"
formit="$(jq -r '.formit // empty' <<< "$fixtures")"

case "$what" in
    too-fast)
        echo "# A form sent 2 s after the page loaded (id $custom)"
        action="$(open_page "$custom")"
        sleep 2
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "it is refused as too fast" test "$(header x-fetchit-refused)" = too_fast
        check "the refusal brings a token to send again" test -n "$(header x-fetchit-token)"
        sleep 2.5
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "sent again 4.5 s after the page loaded, it passes" json '.success == true' "$response"

        if [ -n "$formit" ]; then
            echo "# The same without JavaScript (id $formit)"
            open_page "$formit" > /dev/null
            curl -fsS -c "$jar" -b "$jar" -o "$jar.html" -F "fetchit_token=$(cat "$jar.token")" \
                -F name=Ann -F email=ann@example.com "$base/index.php?id=$formit" || : > "$jar.html"
            check "the page shows the refusal" grep -q 'data-validation-error style="display: ">[^<]*too fast' "$jar.html"
            check "the page keeps the values sent" grep -q 'name="name" value="Ann"' "$jar.html"
        fi
        ;;
    rate-limit)
        echo "# Three submissions of one form (id $custom)"
        action="$(open_page "$custom")"
        for attempt in 1 2; do
            response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
            check "submission $attempt passes" json '.success == true' "$response"
        done
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "submission 3 is refused" json '.success == false' "$response"
        check "... for the rate limit" test "$(header x-fetchit-refused)" = rate
        ;;
    pow)
        echo "# A form that asks for a proof of work (id $custom)"
        action="$(open_page "$custom")"
        check "the page asks for 12 bits" grep -q '"pow":12' "$jar.html"
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "sent without a solution, it is refused" test "$(header x-fetchit-refused)" = pow
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom" -F fetchit_pow=1)"
        check "sent with a wrong solution, it is refused" test "$(header x-fetchit-refused)" = pow
        solution="$(solve_pow "$(cat "$jar.token")" 12)"
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom" -F "fetchit_pow=$solution")"
        check "sent with a solution for its token, it passes" json '.success == true' "$response"
        ;;
    captcha)
        echo "# A form behind Turnstile (id $custom)"
        action="$(open_page "$custom")"
        check "the page links the script of Turnstile" grep -q 'challenges\.cloudflare\.com/turnstile/v0/api\.js' "$jar.html"
        check "the page passes the site key" grep -q '"captcha":{"provider":"turnstile","siteKey":"1x00000000000000000000AA"}' "$jar.html"
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "sent without an answer, it is refused" test "$(header x-fetchit-refused)" = captcha
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom" -F cf-turnstile-response=XXXX.DUMMY.TOKEN.XXXX)"
        check "sent with the test answer, it passes" json '.success == true' "$response"
        ;;
    *)
        echo "Unknown check: $what" >&2
        exit 1
        ;;
esac

finish
