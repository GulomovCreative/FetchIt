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
#
# Refusals are recognised by the X-FetchIt-Refused header, whatever the
# language of the site.
#
# Usage: protection.sh <base url> <fixtures json from fixtures.php> <check>

set -euo pipefail

base="${1:?base URL is required}"
fixtures="${2:?fixtures JSON is required}"
what="${3:?too-fast or rate-limit}"
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
    *)
        echo "Unknown check: $what" >&2
        exit 1
        ;;
esac

finish
