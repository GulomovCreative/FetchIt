#!/usr/bin/env bash
#
# Turn Cloudflare Turnstile on with its test keys, whose widget and secret
# always pass (on) or whose secret always fails (fail), or turn the captcha
# off again.
#
# Usage: MODX_CORE_PATH=/path/to/core/ captcha.sh on|fail|off

set -euo pipefail

setting() { php "$(dirname "$0")/setting.php" "$@"; }

case "${1:?on, fail or off}" in
    on)
        setting fetchit.captcha.site_key 1x00000000000000000000AA
        setting fetchit.captcha.secret_key 1x0000000000000000000000000000000AA
        setting fetchit.captcha turnstile
        ;;
    fail)
        setting fetchit.captcha.site_key 1x00000000000000000000AA
        setting fetchit.captcha.secret_key 2x0000000000000000000000000000000AA
        setting fetchit.captcha turnstile
        ;;
    off)
        setting fetchit.captcha ''
        setting fetchit.captcha.site_key ''
        setting fetchit.captcha.secret_key ''
        ;;
    *)
        echo "Usage: captcha.sh on|fail|off" >&2
        exit 1
        ;;
esac
