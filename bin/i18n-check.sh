#!/usr/bin/env bash
# Generates the POT file and fails on any i18n warning (text domain, translator comments, placeholders).
set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SITE_ROOT="${SITE_ROOT:-$(cd "$PLUGIN_DIR/../../.." && pwd)}"
if [ -z "${WP_CLI:-}" ]; then
	if [ -x "$SITE_ROOT/../wp" ]; then WP_CLI="$SITE_ROOT/../wp"; else WP_CLI="wp --path=$SITE_ROOT"; fi
fi
SLUG="order-flow-for-woocommerce"

out="$($WP_CLI i18n make-pot "wp-content/plugins/$SLUG" "wp-content/plugins/$SLUG/languages/$SLUG.pot" \
	--slug="$SLUG" --domain="$SLUG" \
	--exclude=vendor,tests,bin,docs,dist,assets-src,node_modules,.wordpress-org 2>&1)"
status=$?
echo "$out"
[ "$status" -eq 0 ] || { echo "FAIL: make-pot exited $status" >&2; exit 1; }
if echo "$out" | grep -qiE '^(Warning|Error)'; then
	echo "FAIL: i18n warnings above" >&2
	exit 1
fi
# Text domain of every simple call, e.g. __( 'Text', 'slug' ). The arguments sit between the first
# quoted string and the closing parenthesis, so a later 'literal' argument is never mistaken for a domain.
# Variables and constants as text domain are rejected by the WordPress.WP.I18n sniff in phpcs.
domains="$(grep -rhoE "(^|[^a-z_])(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\( *'[^']*', *'[a-z0-9-]+' *\)" "$PLUGIN_DIR/includes" "$PLUGIN_DIR/templates" "$PLUGIN_DIR/$SLUG.php" | grep -oE "'[a-z0-9-]+' *\)$" | tr -d "' )" | sort -u)"
if [ "$domains" != "$SLUG" ]; then
	echo "FAIL: unexpected or missing text domains: ${domains:-none found}" >&2
	exit 1
fi
echo "i18n: OK"
