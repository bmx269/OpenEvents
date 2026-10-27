#!/usr/bin/env bash
#
# Builds an installable plugin zip in dist/.
#   bin/build-zip.sh             release zip (runtime files only)
#   bin/build-zip.sh playground  also includes dev/sample-data.php for the demo blueprint
set -euo pipefail

MODE="${1:-release}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGE="$ROOT/dist/stage/openevents"

cd "$ROOT"
[ -f build/blocks-manifest.php ] || npm run build

rm -rf "$ROOT/dist/stage" && mkdir -p "$STAGE"
cp -R build inc templates openevents.php uninstall.php readme.txt "$STAGE/"
[ -d languages ] && cp -R languages "$STAGE/"
if [ "$MODE" = "playground" ]; then
	mkdir -p "$STAGE/dev" && cp dev/sample-data.php "$STAGE/dev/"
fi

rm -f "$ROOT/dist/openevents.zip"
(cd "$ROOT/dist/stage" && zip -qr ../openevents.zip openevents)
rm -rf "$ROOT/dist/stage"
echo "dist/openevents.zip ($MODE)"
