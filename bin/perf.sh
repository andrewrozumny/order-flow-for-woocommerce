#!/usr/bin/env bash
# Performance run on a large store: 10 000 products and 50 000 orders (20 000 of them in Order Flow statuses), in one
# throwaway container, at memory_limit=128M and max_execution_time=30. Every step is one request to a PHP web server
# started with those limits (or one WP-CLI process for the background jobs). Writes docs/PERF.md.
#
#   bash bin/perf.sh                       generate the data set (or reuse a matching one) and measure
#   bash bin/perf.sh --reuse               never generate: restore the data set from the cache and measure
#   bash bin/perf.sh --products=1000 --orders=5000     smaller data set (development), does not write docs/PERF.md
#   bash bin/perf.sh --hpos=on|off|both    storage to measure (default both)
#   bash bin/perf.sh --zip=/path/plugin.zip   test this zip instead of building one
#   bash bin/perf.sh --no-explain          skip the EXPLAIN pass (faster)
#   bash bin/perf.sh --write-doc           write docs/PERF.md even for a smaller data set
#
# Needs Docker, the lab network "wplab_default" with MySQL host container "wplab-db-1", python3, rsync.
# Work area: $HOME/.cache/order-flow-perf (override with PERF_CACHE). Databases: of_perf (HPOS on) and
# of_perf_legacy (HPOS off). The main lab site and its database are never touched.
# Exit code: non-zero when any step times out, runs out of memory, errors, takes longer than 30 s or peaks above 128M.
set -uo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
CACHE="${PERF_CACHE:-$HOME/.cache/order-flow-perf}"
MATRIX_CACHE="${MATRIX_CACHE:-$HOME/.cache/order-flow-matrix}"
SEED_CACHE="${MATRIX_SEED_CACHE:-$HOME/.cache/store-audit-matrix}"
LAB_ROOT="${MATRIX_LAB_ROOT:-$(cd "$REPO/../../../.." && pwd)}"
WPCLI="${MATRIX_WPCLI:-$LAB_ROOT/site/.bin/wp-cli.phar}"
NETWORK="${MATRIX_NETWORK:-wplab_default}"
DB_CONTAINER="${MATRIX_DB_CONTAINER:-wplab-db-1}"
SLUG="order-flow-for-woocommerce"
CONTAINER="of-perf-run"
WP_VERSION="${PERF_WP:-7.1.3}"
WOO_VERSION="${PERF_WOO:-11.2.1}"
PHP_VERSION_TAG="${PERF_PHP:-8.4}"
TIMEOUT="${PERF_TIMEOUT:-10800}"

PRODUCTS=10000; ORDERS=50000; REUSE=0; HPOS=both; ZIP_ARG=""; EXPLAIN=1; WRITE_DOC=0
for arg in "$@"; do
	case "$arg" in
		--products=*) PRODUCTS="${arg#*=}" ;;
		--orders=*) ORDERS="${arg#*=}" ;;
		--reuse) REUSE=1 ;;
		--hpos=*) HPOS="${arg#*=}" ;;
		--zip=*) ZIP_ARG="${arg#*=}" ;;
		--no-explain) EXPLAIN=0 ;;
		--write-doc) WRITE_DOC=1 ;;
		-h|--help) sed -n '2,18p' "$0"; exit 0 ;;
		*) echo "unknown argument: $arg" >&2; exit 64 ;;
	esac
done
case "$HPOS" in on|off|both) ;; *) echo "--hpos must be on, off or both" >&2; exit 64 ;; esac

say() { echo "[$(date +%H:%M:%S)] $*"; }
mkdir -p "$CACHE/results" "$CACHE/logs" "$CACHE/dumps" "$CACHE/core" "$CACHE/woo"
FULL=0; [ "$PRODUCTS" -ge 10000 ] && [ "$ORDERS" -ge 50000 ] && FULL=1

# ---- single-run lock ---------------------------------------------------------------------------
LOCK="$CACHE/.lock"
if ! mkdir "$LOCK" 2>/dev/null; then
	old="$(cat "$LOCK/pid" 2>/dev/null || true)"
	if [ -n "$old" ] && kill -0 "$old" 2>/dev/null; then echo "another perf run is active (pid $old)" >&2; exit 75; fi
	rm -rf "$LOCK"; mkdir "$LOCK"
fi
echo $$ > "$LOCK/pid"
cleanup() { docker rm -f "$CONTAINER" >/dev/null 2>&1; rm -rf "$LOCK"; }
trap cleanup EXIT
trap 'exit 130' INT TERM

# ---- preflight ---------------------------------------------------------------------------------
command -v docker >/dev/null || { echo "docker is required" >&2; exit 69; }
docker network inspect "$NETWORK" >/dev/null 2>&1 || { echo "docker network $NETWORK not found (start the lab: warden env up)" >&2; exit 69; }
docker exec "$DB_CONTAINER" true 2>/dev/null || { echo "database container $DB_CONTAINER is not running" >&2; exit 69; }
[ -f "$WPCLI" ] || { echo "wp-cli phar not found at $WPCLI" >&2; exit 69; }

# ---- downloads (WordPress core and WooCommerce are shared with the compatibility matrix cache) --
fetch() { # url dest
	[ -s "$2" ] && return 0
	curl -fsSL --retry 3 -o "$2.part" "$1" && mv "$2.part" "$2"
}
CORE_ZIP="$MATRIX_CACHE/core/wordpress-$WP_VERSION.zip"; [ -s "$CORE_ZIP" ] || CORE_ZIP="$SEED_CACHE/core/wordpress-$WP_VERSION.zip"; [ -s "$CORE_ZIP" ] || CORE_ZIP="$CACHE/core/wordpress-$WP_VERSION.zip"
fetch "https://wordpress.org/wordpress-$WP_VERSION.zip" "$CORE_ZIP" || { echo "cannot get WordPress $WP_VERSION" >&2; exit 69; }
WOO_ZIP="$MATRIX_CACHE/woo/woocommerce.$WOO_VERSION.zip"; [ -s "$WOO_ZIP" ] || WOO_ZIP="$SEED_CACHE/woo/woocommerce.$WOO_VERSION.zip"; [ -s "$WOO_ZIP" ] || WOO_ZIP="$CACHE/woo/woocommerce.$WOO_VERSION.zip"
fetch "https://downloads.wordpress.org/plugin/woocommerce.$WOO_VERSION.zip" "$WOO_ZIP" || { echo "cannot get WooCommerce $WOO_VERSION" >&2; exit 69; }

# ---- plugin zip --------------------------------------------------------------------------------
if [ -n "$ZIP_ARG" ]; then
	cp -f "$ZIP_ARG" "$CACHE/plugin-under-test.zip"
else
	say "building the plugin zip"
	( cd "$REPO" && bash bin/build-zip.sh ) > "$CACHE/logs/build-zip.log" 2>&1 || { cat "$CACHE/logs/build-zip.log" >&2; echo "zip build failed" >&2; exit 1; }
	VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$REPO/$SLUG.php" | head -n 1 | tr -d '[:space:]')"
	cp -f "$REPO/dist/$SLUG-$VERSION.zip" "$CACHE/plugin-under-test.zip"
fi
ZIP="$CACHE/plugin-under-test.zip"
if unzip -l "$ZIP" | awk '{print $4}' | grep -qE "^$SLUG/(bin|docs|tests|vendor|dist)/"; then
	echo "FAIL: the zip contains dev folders (bin/docs/tests/vendor/dist)" >&2; exit 1
fi
say "plugin zip $(shasum -a 256 "$ZIP" | cut -c1-12) ($(du -h "$ZIP" | cut -f1))"

# ---- the run ------------------------------------------------------------------------------------
rm -f "$CACHE"/results/*.json "$CACHE/results/meta.kv"
{
	echo "products=$PRODUCTS"; echo "orders=$ORDERS"; echo "wp=$WP_VERSION"; echo "woo=$WOO_VERSION"; echo "php_tag=$PHP_VERSION_TAG"
	echo "zip_sha=$(shasum -a 256 "$ZIP" | cut -c1-12)"; echo "started=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} > "$CACHE/results/meta.kv"

docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
say "starting the container (products=$PRODUCTS orders=$ORDERS hpos=$HPOS reuse=$REUSE)"
START=$SECONDS
docker run --rm --name "$CONTAINER" --network "$NETWORK" --user "$(id -u):$(id -g)" --memory 3g \
	--entrypoint bash \
	-v "$CACHE:/cache" -v "$REPO:/repo:ro" -v "$ZIP:/plugin.zip:ro" -v "$WPCLI:/wp-cli.phar:ro" \
	-v "$CORE_ZIP:/core.zip:ro" -v "$WOO_ZIP:/woo.zip:ro" \
	-e "PERF_PRODUCTS=$PRODUCTS" -e "PERF_ORDERS=$ORDERS" -e "PERF_REUSE=$REUSE" -e "PERF_HPOS=$HPOS" \
	-e "PERF_EXPLAIN=$EXPLAIN" -e "PERF_WP=$WP_VERSION" -e "PERF_WOO=$WOO_VERSION" \
	"wardenenv/php-fpm:$PHP_VERSION_TAG" /repo/bin/perf-cell.sh 2>&1 | tee "$CACHE/logs/last-run.log" &
PID=$!
while kill -0 "$PID" 2>/dev/null; do
	if [ $((SECONDS - START)) -gt "$TIMEOUT" ]; then
		echo "TIMEOUT after ${TIMEOUT}s" | tee -a "$CACHE/logs/last-run.log"
		docker rm -f "$CONTAINER" >/dev/null 2>&1
		break
	fi
	sleep 5
done
wait "$PID" 2>/dev/null
echo "elapsed=$((SECONDS - START))" >> "$CACHE/results/meta.kv"
say "container finished in $((SECONDS - START)) s"

# ---- report --------------------------------------------------------------------------------------
DOC="$REPO/docs/PERF.md"
[ "$FULL" = 1 ] || [ "$WRITE_DOC" = 1 ] || DOC=/dev/null
python3 "$REPO/bin/perf-report.py" "$CACHE" "$DOC"
rc=$?
if [ "$rc" -eq 0 ]; then say "RESULT: no violations"; else say "RESULT: violations found (exit $rc)"; fi
exit "$rc"
