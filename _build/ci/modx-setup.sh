#!/usr/bin/env bash
#
# Run the MODX command-line installer in an unpacked MODX directory.
#
# Environment: MODX_ROOT, MODX_HTTP_HOST, DB_HOST, DB_NAME, DB_USER,
# DB_PASSWORD, MODX_ADMIN_USER, MODX_ADMIN_PASSWORD.

set -euo pipefail

root="${MODX_ROOT:?MODX_ROOT is not set}"
config="$(mktemp)"

cat > "$config" <<XML
<modx>
    <database_type>mysql</database_type>
    <database_server>${DB_HOST:-127.0.0.1}</database_server>
    <database>${DB_NAME:-modx}</database>
    <database_user>${DB_USER:-root}</database_user>
    <database_password>${DB_PASSWORD:-root}</database_password>
    <database_connection_charset>utf8mb4</database_connection_charset>
    <database_charset>utf8mb4</database_charset>
    <database_collation>utf8mb4_general_ci</database_collation>
    <table_prefix>modx_</table_prefix>
    <https_port>443</https_port>
    <http_host>${MODX_HTTP_HOST:-localhost}</http_host>
    <cache_disabled>0</cache_disabled>
    <inplace>1</inplace>
    <unpacked>0</unpacked>
    <language>en</language>
    <cmsadmin>${MODX_ADMIN_USER:-admin}</cmsadmin>
    <cmspassword>${MODX_ADMIN_PASSWORD:-$(openssl rand -hex 16)}</cmspassword>
    <cmsadminemail>admin@example.com</cmsadminemail>
    <core_path>${root}/core/</core_path>
    <context_mgr_path>${root}/manager/</context_mgr_path>
    <context_mgr_url>/manager/</context_mgr_url>
    <context_connectors_path>${root}/connectors/</context_connectors_path>
    <context_connectors_url>/connectors/</context_connectors_url>
    <context_web_path>${root}/</context_web_path>
    <context_web_url>/</context_web_url>
    <remove_setup_directory>1</remove_setup_directory>
</modx>
XML

(cd "$root" && php setup/index.php --installmode=new --config="$config")
rm -f "$config"

# The installer reports some failures with a zero exit code.
test -f "$root/core/config/config.inc.php"
