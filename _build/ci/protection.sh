#!/usr/bin/env bash
#
# Check the parts of the spam protection that depend on its settings, which
# the caller sets first (_build/ci/setting.php):
#
#   too-fast    fetchit.protection.min_time 30: an immediate submission is
#               refused, with a token to try again
#   rate-limit  fetchit.protection.rate_limit 2: the third submission of the
#               form from this address is refused
#
# Usage: protection.sh <base url> <fixtures json from fixtures.php> <check>

set -euo pipefail

base="${1:?base URL is required}"
fixtures="${2:?fixtures JSON is required}"
what="${3:?too-fast or rate-limit}"
# shellcheck source=_build/ci/lib.sh
. "$(dirname "$0")/lib.sh"

custom="$(jq -r '.custom' <<< "$fixtures")"

case "$what" in
    too-fast)
        echo "# A form sent right after the page loaded (id $custom)"
        action="$(open_page "$custom")"
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "it is refused as too fast" json '.success == false and (.message | test("too fast"))' "$response"
        check "the refusal brings a token to try again" test -s "$jar.token"
        ;;
    rate-limit)
        echo "# Three submissions of one form (id $custom)"
        action="$(open_page "$custom")"
        for attempt in 1 2; do
            response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
            check "submission $attempt passes" json '.success == true' "$response"
        done
        response="$(submit "$action" -F email=ann@example.com -F "pageId=$custom")"
        check "submission 3 is refused" json '.success == false and (.message | test("Too many"))' "$response"
        ;;
    *)
        echo "Unknown check: $what" >&2
        exit 1
        ;;
esac

finish
