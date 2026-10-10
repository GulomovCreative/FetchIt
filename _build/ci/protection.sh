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
#   captcha-refused  the same with the secret that always fails: the answer
#               is refused by Cloudflare itself, not taken for an outage.
#   page-cache  fetchit.page_cache 1, fetchit.protection.min_time 4: a page
#               rendered for one session is sent from another; the token is
#               exchanged for a fresh one (204, not cached, with the proof of
#               work and the fill time), which passes 4.5 s later; a replayed
#               token gets a next one timed now, refused as too fast; a bad
#               token and a GET get none.
#   no-page-cache  the defaults: no exchange, and a page rendered for another
#               session is not found (why fetchit.page_cache exists).
#   db-store    fetchit.protection.store db, fetchit.page_cache 1,
#               fetchit.protection.rate_limit 2: a page rendered for another
#               session passes, its token is refused the second time, and the
#               limit holds after the MODX cache is cleared.
#
# Refusals are recognised by the X-FetchIt-Refused header, whatever the
# language of the site.
#
# Usage: protection.sh <base url> <fixtures json from fixtures.php> <check>

set -euo pipefail

base="${1:?base URL is required}"
fixtures="${2:?fixtures JSON is required}"
what="${3:?too-fast, rate-limit, pow, captcha, captcha-refused, page-cache, no-page-cache or db-store}"
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
        check "the form asks for 12 bits" grep -q '&quot;pow&quot;:12' "$jar.html"
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "sent without a solution, it is refused" test "$(header x-fetchit-refused)" = pow
        check "the refusal tells the script the difficulty" test "$(header x-fetchit-pow)" = 12
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom" -F fetchit_pow=x)"
        check "sent with a wrong solution, it is refused" test "$(header x-fetchit-refused)" = pow
        solution="$(solve_pow "$(cat "$jar.token")" 12)"
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom" -F "fetchit_pow=$solution")"
        check "sent with a solution for its token, it passes" json '.success == true' "$response"
        ;;
    captcha)
        echo "# A form behind Turnstile (id $custom)"
        action="$(open_page "$custom")"
        check "the page links the script of Turnstile" grep -q 'challenges\.cloudflare\.com/turnstile/v0/api\.js' "$jar.html"
        check "the form passes the site key" grep -q '&quot;captcha&quot;:&#123;&quot;provider&quot;:&quot;turnstile&quot;,&quot;siteKey&quot;:&quot;1x00000000000000000000AA&quot;&#125;' "$jar.html"
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "sent without an answer, it is refused" test "$(header x-fetchit-refused)" = captcha
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom" -F cf-turnstile-response=XXXX.DUMMY.TOKEN.XXXX)"
        check "sent with the test answer, it passes" json '.success == true' "$response"
        ;;
    captcha-refused)
        echo "# A form behind Turnstile that refuses every answer (id $custom)"
        action="$(open_page "$custom")"
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom" -F cf-turnstile-response=XXXX.DUMMY.TOKEN.XXXX)"
        check "the answer is refused by the provider" test "$(header x-fetchit-refused)" = captcha
        ;;
    page-cache)
        echo "# A page from a full-page cache (id $custom)"
        action="$(open_page "$custom")"
        page_token="$(cat "$jar.token")"
        forget_session
        exchange "$action"
        check "the exchange answers 204" test "$(status)" = 204
        check "it brings a fresh token" test "$(cat "$jar.token")" != "$page_token"
        check "it tells the proof of work" test -n "$(header x-fetchit-pow)"
        check "it tells the fill time" test "$(header x-fetchit-min-time)" = 4
        check "it is not cached" grep -qi '^cache-control:.*no-store' "$jar.headers"
        sleep 4.5
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "the fresh token passes 4.5 s later, from another session" json '.success == true' "$response"

        echo "# The token of the cached page, used and replayed"
        printf '%s' "$page_token" > "$jar.token"
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "its first use passes" json '.success == true' "$response"
        printf '%s' "$page_token" > "$jar.token"
        submit "$action" -F email=ann@example.com -F "pageId=$custom" > /dev/null
        check "the replay is refused" test "$(header x-fetchit-refused)" = token
        submit "$action" -F email=ann@example.com -F "pageId=$custom" > /dev/null
        check "its next token, sent at once, is too fast" test "$(header x-fetchit-refused)" = too_fast

        echo "# Exchanges that get nothing"
        printf 'garbage' > "$jar.token"
        exchange "$action"
        check "a bad token gets no token" test -z "$(header x-fetchit-token)"
        check "and is refused as a token" test "$(header x-fetchit-refused)" = token
        : > "$jar.headers"
        curl -sS -D "$jar.headers" -o /dev/null -H "X-FetchIt-Action: $action" -H "X-FetchIt-Exchange: 1" \
            "$base/assets/components/fetchit/action.php?fetchit_token=$page_token" || true
        check "a GET gets no token" test -z "$(header x-fetchit-token)"
        ;;
    no-page-cache)
        echo "# Without fetchit.page_cache (id $custom)"
        action="$(open_page "$custom")"
        exchange "$action"
        check "the exchange gives no token" test -z "$(header x-fetchit-token)"
        forget_session
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "a page rendered for another session is not found" json '.success == false' "$response"
        ;;
    db-store)
        echo "# Tokens, counters and forms in the database (id $custom)"
        action="$(open_page "$custom")"
        page_token="$(cat "$jar.token")"
        forget_session
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "a page rendered for another session passes" json '.success == true' "$response"
        printf '%s' "$page_token" > "$jar.token"
        submit "$action" -F email=ann@example.com -F "pageId=$custom" > /dev/null
        check "its token is refused the second time" test "$(header x-fetchit-refused)" = token
        php "$(dirname "$0")/setting.php" fetchit.protection.rate_limit 2 > /dev/null
        submit "$action" -F email=ann@example.com -F "pageId=$custom" > /dev/null
        check "the limit holds after the MODX cache is cleared" test "$(header x-fetchit-refused)" = rate
        ;;
    *)
        echo "Unknown check: $what" >&2
        exit 1
        ;;
esac

finish
