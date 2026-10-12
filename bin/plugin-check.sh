#!/usr/bin/env bash
# Runs WordPress Plugin Check on the shipping part of the plugin only.
set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# The plugin lives in <site>/wp-content/plugins/<slug>. A dev site may ship a ./wp wrapper next to <site>.
SITE_ROOT="${SITE_ROOT:-$(cd "$PLUGIN_DIR/../../.." && pwd)}"
if [ -z "${WP_CLI:-}" ]; then
	if [ -x "$SITE_ROOT/../wp" ]; then WP_CLI="$SITE_ROOT/../wp"; else WP_CLI="wp --path=$SITE_ROOT"; fi
fi
SLUG="order-flow-for-woocommerce"

# Dev-only paths that are listed in .distignore and do not ship.
EXCLUDE_DIRS="vendor,tests,bin,docs,assets-src,.wordpress-org,dist,node_modules,.git,.github"
EXCLUDE_FILES="composer.json,composer.lock,phpcs.xml.dist,phpstan.neon.dist,phpunit.xml.dist,.distignore,.gitignore,.gitattributes,.editorconfig,README.md,CHANGELOG.md,.phpunit.result.cache"

out_file="$(mktemp)"
trap 'rm -f "$out_file"' EXIT

$WP_CLI plugin check "$SLUG" \
	--exclude-directories="$EXCLUDE_DIRS" \
	--exclude-files="$EXCLUDE_FILES" \
	--format=csv --fields=line,column,type,code,message,file > "$out_file" 2> "${out_file}.err"
status=$?
cat "${out_file}.err" >&2
rm -f "${out_file}.err"

cat "$out_file"

if [ "$status" -ne 0 ]; then
	echo "FAIL: plugin check command failed (exit $status)" >&2
	exit 1
fi
errors="$(grep -c ',ERROR,' "$out_file" || true)"
warnings="$(grep -c ',WARNING,' "$out_file" || true)"
echo "Plugin check: ${errors} errors, ${warnings} warnings"
[ "$errors" -eq 0 ]
