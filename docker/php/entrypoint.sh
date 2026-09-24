#!/bin/bash
set -euo pipefail

MODX_ROOT=/var/www/html
export MODX_ROOT

# Give the site to www-data, but never touch the component directories
# mounted from the repository: they belong to the host user.
own_site() {
    find "${MODX_ROOT}" -xdev -path "${MODX_ROOT}/*/components/fetchit" -prune -o -exec chown www-data:www-data {} +
}

# Run Apache and MODX as HOST_UID:HOST_GID, which should be the owner of the
# repository, so the site can write into it (_packages/) and the files stay
# editable on the host.
if [ -n "${HOST_UID:-}" ] && [ "$(id -u www-data)" != "${HOST_UID}" ]; then
    echo "[fetchit] www-data becomes ${HOST_UID}:${HOST_GID:-$HOST_UID}"
    groupmod -o -g "${HOST_GID:-$HOST_UID}" www-data
    usermod -o -u "${HOST_UID}" www-data
    own_site
fi

if [ ! -f "${MODX_ROOT}/index.php" ]; then
    echo "[fetchit] Copying MODX into the site volume"
    cp -a /usr/src/modx/. "${MODX_ROOT}/"
    [ -f "${MODX_ROOT}/ht.access" ] && cp "${MODX_ROOT}/ht.access" "${MODX_ROOT}/.htaccess"
fi

if [ ! -f "${MODX_ROOT}/core/config/config.inc.php" ]; then
    echo "[fetchit] Waiting for MySQL at ${DB_HOST}"
    ready=0
    for _ in $(seq 1 60); do
        # shellcheck disable=SC2016 # PHP code, not shell
        if php -r 'try { new PDO("mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; then
            ready=1
            break
        fi
        sleep 2
    done
    if [ "$ready" != 1 ]; then
        echo "[fetchit] MySQL at ${DB_HOST} is not reachable after 120 s" >&2
        exit 1
    fi

    echo "[fetchit] Installing MODX (PHP $(php -r 'echo PHP_VERSION;'))"
    own_site
    if su -s /bin/bash www-data -p -c /extra/_build/ci/modx-setup.sh; then
        echo "[fetchit] Manager: http://${MODX_HTTP_HOST}/manager/ (${MODX_ADMIN_USER} / ${MODX_ADMIN_PASSWORD})"
    else
        # Without this the next start would take the half-written config for
        # an installed site. Exit, so Docker restarts the container and the
        # setup runs again.
        echo "[fetchit] MODX setup failed, see above" >&2
        rm -f "${MODX_ROOT}/core/config/config.inc.php"
        exit 1
    fi
fi

chown www-data:www-data "${MODX_ROOT}" 2>/dev/null || true
chown -R www-data:www-data "${MODX_ROOT}/core/cache" "${MODX_ROOT}/core/config" "${MODX_ROOT}/core/packages" 2>/dev/null || true

exec "$@"
