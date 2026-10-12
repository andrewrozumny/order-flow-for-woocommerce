#!/usr/bin/env bash
# Integration test: runs tests/integration/run.php through WP-CLI in the lab site.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# The plugin lives in <site>/wp-content/plugins/<slug>. A dev site may ship a ./wp wrapper next to <site>.
SITE_ROOT="${SITE_ROOT:-$(cd "$PLUGIN_DIR/../../.." && pwd)}"
if [ -z "${WP_CLI:-}" ]; then
	if [ -x "$SITE_ROOT/../wp" ]; then WP_CLI="$SITE_ROOT/../wp"; else WP_CLI="wp --path=$SITE_ROOT"; fi
fi
DEBUG_LOG="${SITE_ROOT}/wp-content/debug.log"
PLUGIN_PATH_MARK="plugins/order-flow-for-woocommerce/"
# Path of the script relative to the site root (the wrapper runs inside the container).
SCRIPT="wp-content/plugins/order-flow-for-woocommerce/tests/integration/run.php"

before=0
[ -f "$DEBUG_LOG" ] && before=$(wc -c < "$DEBUG_LOG" | tr -d ' ')

out_file="$(mktemp)"
trap 'rm -f "$out_file"' EXIT

status=0
$WP_CLI eval-file "$SCRIPT" > "$out_file" 2>&1 || status=$?
cat "$out_file"

if [ "$status" -ne 0 ] || grep -q '^FAIL' "$out_file"; then
	echo "FAIL: integration script reported failures (exit $status)" >&2
	exit 1
fi
if ! grep -q '^PASS' "$out_file"; then
	echo "FAIL: integration script printed no PASS lines" >&2
	exit 1
fi

if [ -f "$DEBUG_LOG" ]; then
	new_lines="$(tail -c +"$((before + 1))" "$DEBUG_LOG" | grep -E 'PHP (Fatal|Warning)' | grep -F "$PLUGIN_PATH_MARK" || true)"
	if [ -n "$new_lines" ]; then
		echo "FAIL: PHP Fatal/Warning from this plugin in debug.log:" >&2
		echo "$new_lines" >&2
		exit 1
	fi
fi
echo "Integration: OK"
