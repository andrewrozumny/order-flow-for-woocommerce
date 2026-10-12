#!/usr/bin/env bash
# Runs WordPress Plugin Check with every category (general, plugin_repo, security, performance,
# accessibility), experimental checks and low severity findings switched on.
#
#   bin/plugin-check.sh         check the working tree (shipping part only)
#   bin/plugin-check.sh --zip   check the newest dist/*.zip, unpacked exactly as WordPress.org would receive it
#
# Plugin Check skips vendor/ by itself; vendor-prefixed/ (the bundled libraries) is excluded here, so they are not scanned.
set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# The plugin lives in <site>/wp-content/plugins/<slug>. A dev site may ship a ./wp wrapper next to <site>.
SITE_ROOT="${SITE_ROOT:-$(cd "$PLUGIN_DIR/../../.." && pwd)}"
CONTAINER_ROOT=""
if [ -z "${WP_CLI:-}" ]; then
	if [ -x "$SITE_ROOT/../wp" ]; then
		WP_CLI="$SITE_ROOT/../wp"
		CONTAINER_ROOT="/var/www/html"
	else
		WP_CLI="wp --path=$SITE_ROOT"
	fi
fi
SLUG="order-flow-for-woocommerce"

# Dev-only paths that are listed in .distignore and do not ship.
EXCLUDE_DIRS="vendor,vendor-prefixed,tests,bin,docs,assets-src,.wordpress-org,dist,node_modules,.git,.github"
EXCLUDE_FILES="composer.json,composer.lock,phpcs.xml.dist,phpstan.neon.dist,psalm.xml.dist,phpunit.xml.dist,.distignore,.gitignore,.gitattributes,.editorconfig,README.md,CHANGELOG.md,.phpunit.result.cache"

out_file="$(mktemp)"
zip_dir=""
cleanup() {
	rm -f "$out_file" "${out_file}.err"
	[ -n "$zip_dir" ] && rm -rf "$zip_dir"
}
trap cleanup EXIT

target="$SLUG"
args=(--include-experimental --include-low-severity-errors --include-low-severity-warnings --severity=0)
if [ "${1:-}" = "--zip" ]; then
	zip="$(ls -t "$PLUGIN_DIR"/dist/"$SLUG"-*.zip 2>/dev/null | head -n 1)"
	[ -n "$zip" ] || { echo "FAIL: no zip in dist/, run bin/build-zip.sh first" >&2; exit 1; }
	zip_dir="$SITE_ROOT/wp-content/of-zipcheck"
	rm -rf "$zip_dir"
	mkdir -p "$zip_dir"
	unzip -q "$zip" -d "$zip_dir"
	target="${CONTAINER_ROOT:-$SITE_ROOT}/wp-content/of-zipcheck/$SLUG"
	args+=(--slug="$SLUG")
	echo "Checking $zip"
else
	args+=(--exclude-directories="$EXCLUDE_DIRS" --exclude-files="$EXCLUDE_FILES")
fi

$WP_CLI plugin check "$target" "${args[@]}" \
	--format=csv --fields=line,column,type,code,message,file > "$out_file" 2> "${out_file}.err"
status=$?
cat "${out_file}.err" >&2

cat "$out_file"

if [ "$status" -ne 0 ]; then
	echo "FAIL: plugin check command failed (exit $status)" >&2
	exit 1
fi
errors="$(grep -c ',ERROR,' "$out_file" || true)"
warnings="$(grep -c ',WARNING,' "$out_file" || true)"
echo "Plugin check: ${errors} errors, ${warnings} warnings"
[ "$errors" -eq 0 ] && [ "$warnings" -eq 0 ]
