#!/usr/bin/env bash
#
# Download a MODX release and unpack it into a directory.
# Usage: modx-download.sh <version, e.g. 2.8.6-pl> <target dir> [zip cache]

set -euo pipefail

version="${1:?MODX version is required}"
target="${2:?target directory is required}"
zip="${3:-/tmp/modx-$version.zip}"

if [ ! -f "$zip" ]; then
    # modx.com refuses the default curl user agent.
    curl -fsSL --retry 3 \
        -A "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36" \
        -o "$zip" "https://modx.com/download/direct/modx-$version.zip"
fi

tmp="$(mktemp -d)"
unzip -q "$zip" -d "$tmp"
mkdir -p "$target"
cp -a "$tmp/modx-$version/." "$target/"
rm -rf "$tmp"
