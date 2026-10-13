#!/usr/bin/env bash
# Performance run INSIDE the container. Started by bin/perf.sh; not meant to be run by hand.
# Mounts: /cache (rw), /repo (read-only), /plugin.zip, /wp-cli.phar, /core.zip, /woo.zip.
set -uo pipefail

export HOME=/tmp/home
mkdir -p "$HOME"
CACHE=/cache
SITE="$CACHE/site"
SLUG="order-flow-for-woocommerce"
PRODUCTS="${PERF_PRODUCTS:-10000}"; ORDERS="${PERF_ORDERS:-50000}"
REUSE="${PERF_REUSE:-0}"; HPOS_REQ="${PERF_HPOS:-both}"
SIG="p${PRODUCTS}-o${ORDERS}-wp${PERF_WP}-woo${PERF_WOO}-ofv1"
PORT=8080
ADMIN_PASS="perf-Pass-123!"
SERVER_PID=""

say() { echo "[$(date +%H:%M:%S)] $*"; }
mysqlc() { mysql -h db -uroot -pwordpress "$@" 2>/dev/null; }
wpc() { php -d memory_limit=2G -d display_errors=stderr -d sendmail_path=/bin/true /wp-cli.phar --path="$SITE" "$@"; }
cleanup() { [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null; }
trap cleanup EXIT

# ---- site files (WordPress + WooCommerce), kept in the cache between runs ----------------------------
if [ ! -f "$SITE/.perf-ready-$PERF_WP-$PERF_WOO" ]; then
	say "unpacking WordPress $PERF_WP and WooCommerce $PERF_WOO"
	rm -rf "$SITE" "$SITE.tmp"; mkdir -p "$SITE.tmp"
	unzip -q /core.zip -d "$SITE.tmp" && mv "$SITE.tmp/wordpress" "$SITE" && rmdir "$SITE.tmp" || { say "cannot unpack core"; exit 1; }
	unzip -q /woo.zip -d "$SITE/wp-content/plugins" || { say "cannot unpack WooCommerce"; exit 1; }
	mkdir -p "$SITE/wp-content/mu-plugins"
	touch "$SITE/.perf-ready-$PERF_WP-$PERF_WOO"
fi
cp -f /repo/bin/perf-mu.php "$SITE/wp-content/mu-plugins/of-perf.php"
cat > "$SITE/wp-config.php" <<PHP
<?php
define( 'DB_NAME', getenv( 'OF_PERF_DB' ) ?: 'of_perf' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', 'db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'perf' ); define( 'SECURE_AUTH_KEY', 'perf' ); define( 'LOGGED_IN_KEY', 'perf' ); define( 'NONCE_KEY', 'perf' );
define( 'AUTH_SALT', 'perf' ); define( 'SECURE_AUTH_SALT', 'perf' ); define( 'LOGGED_IN_SALT', 'perf' ); define( 'NONCE_SALT', 'perf' );
\$table_prefix = 'wp_';
define( 'WP_HOME', getenv( 'PERF_HOME' ) ?: 'http://127.0.0.1:$PORT' );
define( 'WP_SITEURL', getenv( 'PERF_HOME' ) ?: 'http://127.0.0.1:$PORT' );
define( 'WP_DEBUG', false );
define( 'DISABLE_WP_CRON', true );
define( 'FS_METHOD', 'direct' );
define( 'WP_AUTO_UPDATE_CORE', false );
// The memory limit under test: WordPress must not raise it.
define( 'WP_MEMORY_LIMIT', '128M' );
define( 'WP_MAX_MEMORY_LIMIT', '128M' );
if ( isset( \$_SERVER['HTTP_X_PERF_SAVEQ'] ) ) { define( 'SAVEQUERIES', true ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP

# ---- one data set per HPOS state ---------------------------------------------------------------------
db_for() { [ "$1" = on ] && echo of_perf || echo of_perf_legacy; }

dataset_ok() { # db
	local v
	v="$(OF_PERF_DB="$1" wpc option get of_perf_dataset 2>/dev/null | tail -1)"
	[ "$v" = "$SIG" ]
}

ensure_dataset() { # hpos
	local hpos="$1" db dump
	db="$(db_for "$hpos")"; dump="$CACHE/dumps/$db-$SIG.sql.gz"
	export OF_PERF_DB="$db"
	if mysqlc -e "USE \`$db\`" && dataset_ok "$db"; then
		say "[$hpos] data set in $db is current ($SIG), reusing it"; return 0
	fi
	if [ -s "$dump" ]; then
		say "[$hpos] restoring $db from $(du -h "$dump" | cut -f1) dump"
		mysqlc -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` DEFAULT CHARACTER SET utf8mb4" || return 1
		zcat "$dump" | mysql -h db -uroot -pwordpress "$db" 2>&1 | head -5
		dataset_ok "$db" && return 0
		say "[$hpos] dump did not restore cleanly"
	fi
	if [ "$REUSE" = 1 ]; then say "[$hpos] --reuse given but no matching data set or dump exists (signature $SIG)"; return 2; fi
	say "[$hpos] generating the data set in $db"
	local t0=$SECONDS
	mysqlc -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` DEFAULT CHARACTER SET utf8mb4" || return 1
	wpc core install --url="http://127.0.0.1:$PORT" --title="OF Perf" --admin_user=admin --admin_password="$ADMIN_PASS" --admin_email=admin@example.test --skip-email >/dev/null || { say "core install failed"; return 1; }
	wpc plugin activate woocommerce >/dev/null || { say "woocommerce activate failed"; return 1; }
	wpc option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null 2>&1
	wpc option update woocommerce_task_list_hidden yes >/dev/null 2>&1
	wpc option update woocommerce_default_country "US:CA" >/dev/null 2>&1
	wpc option update woocommerce_currency USD >/dev/null 2>&1
	wpc option update woocommerce_custom_orders_table_data_sync_enabled no >/dev/null
	wpc option update woocommerce_custom_orders_table_enabled "$([ "$hpos" = on ] && echo yes || echo no)" >/dev/null
	local state
	state="$(wpc eval 'echo \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "on" : "off";' | tail -1)"
	[ "$state" = "$hpos" ] || { say "HPOS state is $state, wanted $hpos"; return 1; }
	PERF_HPOS_MODE="$hpos" PERF_PRODUCTS="$PRODUCTS" PERF_ORDERS="$ORDERS" wpc eval-file /repo/bin/perf-gen.php || { say "data generation failed"; return 1; }
	wpc option update of_perf_dataset "$SIG" >/dev/null
	say "[$hpos] generated in $((SECONDS - t0)) s; dumping"
	mysqldump -h db -uroot -pwordpress --single-transaction --quick --no-tablespaces "$db" 2>/dev/null | gzip -1 > "$dump.part" && mv "$dump.part" "$dump"
	say "[$hpos] dump $(du -h "$dump" | cut -f1)"
	return 0
}

# ---- the measured pass ---------------------------------------------------------------------------------
measure() { # hpos
	local hpos="$1" db
	db="$(db_for "$hpos")"
	export OF_PERF_DB="$db"
	say "[$hpos] installing the plugin from the zip"
	wpc option update woocommerce_custom_orders_table_data_sync_enabled no >/dev/null
	wpc plugin deactivate "$SLUG" >/dev/null 2>&1
	rm -rf "$SITE/wp-content/plugins/$SLUG"
	wpc plugin install /plugin.zip >/dev/null || { say "plugin install failed"; return 1; }
	wpc plugin activate "$SLUG" >/dev/null || { say "plugin activate failed"; return 1; }
	# Back to a known state: configuration, no leftovers of an earlier run, the Order Flow orders in their statuses.
	wpc db query "DELETE FROM wp_options WHERE option_name LIKE 'ofwc\\_%' OR option_name LIKE '\\_transient\\_ofwc%' OR option_name LIKE 'woocommerce\\_ofwc\\_%'" >/dev/null 2>&1
	wpc db query "DELETE FROM wp_actionscheduler_actions WHERE hook LIKE 'ofwc\\_%'" >/dev/null 2>&1
	PERF_ARG=config wpc eval-file /repo/bin/perf-state.php >/dev/null || { say "configuration failed"; return 1; }
	wpc cache flush >/dev/null 2>&1
	mysqlc -e "USE \`$db\`; ANALYZE TABLE wp_posts, wp_postmeta, wp_options, wp_users, wp_usermeta, wp_wc_orders, wp_wc_orders_meta, wp_wc_order_addresses, wp_wc_order_operational_data, wp_woocommerce_order_items, wp_woocommerce_order_itemmeta, wp_wc_product_meta_lookup, wp_term_relationships" >/dev/null

	# The web server runs under the limits that are being tested.
	PHP_CLI_SERVER_WORKERS=4 php -d memory_limit=128M -d max_execution_time=30 -d display_errors=0 -d log_errors=0 -d sendmail_path=/bin/true -S "127.0.0.1:$PORT" -t "$SITE" >"$CACHE/logs/server-$hpos.log" 2>&1 &
	SERVER_PID=$!
	sleep 1
	say "[$hpos] measuring"
	python3 /repo/bin/perf-drive.py "$SITE" "$CACHE/results/$hpos.json" "$hpos" "$db" "${PERF_EXPLAIN:-1}"
	local rc=$?
	kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; SERVER_PID=""
	return $rc
}

mysqlc -e "SELECT 1" >/dev/null || { say "cannot reach the database"; exit 1; }
PASSES=(on off); [ "$HPOS_REQ" != both ] && PASSES=("$HPOS_REQ")
for h in "${PASSES[@]}"; do
	ensure_dataset "$h"; rc=$?
	if [ $rc -ne 0 ]; then echo "{\"hpos\":\"$h\",\"setup_failed\":true}" > "$CACHE/results/$h.json"; continue; fi
	measure "$h"
done
say "cell done"
exit 0
