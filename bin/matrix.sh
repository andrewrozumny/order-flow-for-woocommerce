#!/usr/bin/env bash
# Compatibility matrix: WordPress x PHP x WooCommerce x HPOS, run in throwaway containers, one at a time.
#
#   bash bin/matrix.sh                                    whole matrix, writes docs/COMPAT.md
#   bash bin/matrix.sh --wp=7.1 --php=8.4 --woo=current --hpos=on     one row, report to stdout only
#
# Filters (all optional, combinable):
#   --wp=6.6|6.8|6.9|7.0|7.1     WordPress branch
#   --php=8.1|8.2|8.3|8.4|8.5    PHP version
#   --woo=min|current|<x.y.z>    WooCommerce release (min = latest 9.0.x, current = newest that installs on that WP and PHP)
#   --hpos=on|off                HPOS state
#   --only=matrix|php85|multisite  one section of the plan
#   --write-doc                  write docs/COMPAT.md even for a filtered run
#   --report-only                regenerate docs/COMPAT.md from the results already in the cache
#   --keep-going / default       a failed row never stops the run
#
# Needs: Docker, the lab network "wplab_default" with MySQL host "db" (container wplab-db-1), python3, rsync, curl.
# Work area (WordPress cores, WooCommerce zips, sites, logs, results): $HOME/.cache/order-flow-matrix (override with MATRIX_CACHE).
# Exit code is non-zero when any row that ran failed.
set -uo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
CACHE="${MATRIX_CACHE:-$HOME/.cache/order-flow-matrix}"
LAB_ROOT="${MATRIX_LAB_ROOT:-$(cd "$REPO/../../../.." && pwd)}"
WPCLI="${MATRIX_WPCLI:-$LAB_ROOT/site/.bin/wp-cli.phar}"
NETWORK="${MATRIX_NETWORK:-wplab_default}"
DB_CONTAINER="${MATRIX_DB_CONTAINER:-wplab-db-1}"
TIMEOUT="${MATRIX_GROUP_TIMEOUT:-2400}"
RESOLVE="python3 $REPO/bin/matrix-resolve.py"
SLUG="order-flow-for-woocommerce"

F_WP=""; F_PHP=""; F_WOO=""; F_HPOS=""; F_ONLY=""; WRITE_DOC=0; REPORT_ONLY=0
for arg in "$@"; do
	case "$arg" in
		--wp=*) F_WP="${arg#*=}" ;;
		--php=*) F_PHP="${arg#*=}" ;;
		--woo=*) F_WOO="${arg#*=}" ;;
		--hpos=*) F_HPOS="${arg#*=}" ;;
		--only=*) F_ONLY="${arg#*=}" ;;
		--write-doc) WRITE_DOC=1 ;;
		--report-only) REPORT_ONLY=1 ;;
		--keep-going) ;;
		-h|--help) sed -n '2,20p' "$0"; exit 0 ;;
		*) echo "unknown argument: $arg" >&2; exit 64 ;;
	esac
done
FILTERED=0
[ -n "$F_WP$F_PHP$F_WOO$F_HPOS$F_ONLY" ] && FILTERED=1

say() { echo "[$(date +%H:%M:%S)] $*"; }
mkdir -p "$CACHE/results" "$CACHE/logs" "$CACHE/core" "$CACHE/woo" "$CACHE/sites"

# WordPress cores and WooCommerce releases already downloaded by another run are copied, never re-downloaded.
SEED_CACHE="${MATRIX_SEED_CACHE:-$HOME/.cache/store-audit-matrix}"
if [ -d "$SEED_CACHE" ] && [ "$SEED_CACHE" != "$CACHE" ]; then
	for kind in core woo; do
		for f in "$SEED_CACHE/$kind"/*.zip; do
			[ -f "$f" ] && [ ! -e "$CACHE/$kind/$(basename "$f")" ] && cp "$f" "$CACHE/$kind/"
		done
	done
fi

VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$REPO/$SLUG.php" | head -n 1 | tr -d '[:space:]')"

write_report() {
	if [ "$FILTERED" = 0 ] || [ "$WRITE_DOC" = 1 ] || [ "$REPORT_ONLY" = 1 ]; then
		python3 "$REPO/bin/matrix-report.py" "$CACHE" "$REPO/docs/COMPAT.md" "$VERSION"
		local rc=$?
		say "wrote docs/COMPAT.md"
		return $rc
	fi
	python3 "$REPO/bin/matrix-report.py" "$CACHE" /dev/null "$VERSION" --stdout
}

if [ "$REPORT_ONLY" = 1 ]; then
	write_report; exit $?
fi

# ---- single-run lock ----------------------------------------------------------------------
LOCK="$CACHE/.lock"
if ! mkdir "$LOCK" 2>/dev/null; then
	old="$(cat "$LOCK/pid" 2>/dev/null || true)"
	if [ -n "$old" ] && kill -0 "$old" 2>/dev/null; then
		echo "another matrix run is active (pid $old)" >&2; exit 75
	fi
	rm -rf "$LOCK"; mkdir "$LOCK"
fi
echo $$ > "$LOCK/pid"
CURRENT_CONTAINER=""
cleanup() {
	[ -n "$CURRENT_CONTAINER" ] && docker rm -f "$CURRENT_CONTAINER" >/dev/null 2>&1
	rm -rf "$LOCK"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

# ---- preflight ----------------------------------------------------------------------------
command -v docker >/dev/null || { echo "docker is required" >&2; exit 69; }
docker network inspect "$NETWORK" >/dev/null 2>&1 || { echo "docker network $NETWORK not found (start the lab: warden env up)" >&2; exit 69; }
docker exec "$DB_CONTAINER" true 2>/dev/null || { echo "database container $DB_CONTAINER is not running" >&2; exit 69; }
[ -f "$WPCLI" ] || { echo "wp-cli phar not found at $WPCLI" >&2; exit 69; }

# ---- plugin zip ---------------------------------------------------------------------------
say "building the plugin zip"
( cd "$REPO" && bash bin/build-zip.sh ) > "$CACHE/logs/build-zip.log" 2>&1 || { cat "$CACHE/logs/build-zip.log" >&2; echo "zip build failed" >&2; exit 1; }
BUILT="$REPO/dist/$SLUG-$VERSION.zip"
[ -f "$BUILT" ] || { echo "zip missing: $BUILT" >&2; exit 1; }
# Test a snapshot, so a rebuild of dist/ during a long run cannot change what is being tested.
ZIP="$CACHE/plugin-under-test.zip"
cp -f "$BUILT" "$ZIP"
ZIP_SHA="$(shasum -a 256 "$ZIP" | cut -c1-12)"
echo "$ZIP_SHA" > "$CACHE/zip-sha.txt"
if unzip -l "$ZIP" | awk '{print $4}' | grep -qE "^$SLUG/(bin|docs|tests|vendor|dist)/"; then
	echo "FAIL: the zip contains dev folders (bin/docs/tests/vendor/dist); check .distignore" >&2; exit 1
fi
say "zip snapshot $ZIP_SHA ($(du -h "$ZIP" | cut -f1)), dev folders excluded"

# ---- plan ---------------------------------------------------------------------------------
BRANCHES=(6.6 6.8 6.9 7.0 7.1)
PHPS=(8.1 8.2 8.3 8.4)
$RESOLVE wp "$CACHE" "${BRANCHES[@]}" > "$CACHE/wp-versions.txt" || { echo "cannot resolve WordPress versions" >&2; exit 1; }
wpv() { awk -v b="$1" '$1 == b { print $2 }' "$CACHE/wp-versions.txt"; }
say "WordPress: $(awk '{ printf "%s ", $2 }' "$CACHE/wp-versions.txt")"

# plan line: kind|wp_branch|php|woo_kind|multisite|plugin_check
PLAN=()
for b in "${BRANCHES[@]}"; do
	for p in "${PHPS[@]}"; do
		for wk in min current; do
			pc=0; [ "$b" = 7.1 ] && [ "$p" = 8.4 ] && [ "$wk" = current ] && pc=1
			PLAN+=("matrix|$b|$p|$wk|0|$pc")
		done
	done
done
for wk in min current; do PLAN+=("php85|7.1|8.5|$wk|0|0"); done
PLAN+=("multisite|7.1|8.4|current|1|0")

selected=()
for line in "${PLAN[@]}"; do
	IFS='|' read -r kind b p wk ms pc <<< "$line"
	[ -n "$F_ONLY" ] && [ "$F_ONLY" != "$kind" ] && continue
	[ -n "$F_WP" ] && [ "$F_WP" != "$b" ] && [ "$F_WP" != "$(wpv "$b")" ] && continue
	[ -n "$F_PHP" ] && [ "$F_PHP" != "$p" ] && continue
	if [ -n "$F_WOO" ]; then
		case "$F_WOO" in min|current) [ "$F_WOO" != "$wk" ] && continue ;; esac
	fi
	selected+=("$line")
done
if [ "$FILTERED" = 1 ] && [ -n "$F_WOO" ] && [ "$F_WOO" != min ] && [ "$F_WOO" != current ] && [ ${#selected[@]} -gt 0 ]; then
	# An explicit WooCommerce version replaces the "current" column of the selected rows.
	new=(); seen=""
	for line in "${selected[@]}"; do
		IFS='|' read -r kind b p wk ms pc <<< "$line"
		key="$kind|$b|$p"
		[[ "$seen" == *"[$key]"* ]] && continue
		seen="$seen[$key]"
		new+=("$kind|$b|$p|$F_WOO|$ms|$pc")
	done
	selected=("${new[@]}")
fi
if [ ${#selected[@]} -eq 0 ]; then echo "nothing selected by the filters" >&2; exit 64; fi
HPOS_LIST="on off"; [ -n "$F_HPOS" ] && HPOS_LIST="$F_HPOS"
say "${#selected[@]} group(s) selected, HPOS: $HPOS_LIST"

[ "$FILTERED" = 0 ] && rm -rf "$CACHE/results" && mkdir -p "$CACHE/results"

# ---- docker helper ------------------------------------------------------------------------
# run_container <name> <image> <logfile> -- <extra docker args...>
run_container() {
	local name="$1" image="$2" logfile="$3"; shift 3
	docker rm -f "$name" >/dev/null 2>&1 || true
	CURRENT_CONTAINER="$name"
	docker run --rm --name "$name" --network "$NETWORK" --user "$(id -u):$(id -g)" --memory 3g \
		--entrypoint bash \
		-v "$CACHE:/cache" -v "$REPO:/repo:ro" -v "$ZIP:/plugin.zip:ro" -v "$WPCLI:/wp-cli.phar:ro" \
		"$@" "$image" /repo/bin/matrix-cell.sh > "$logfile" 2>&1 &
	local pid=$! start=$SECONDS
	while kill -0 "$pid" 2>/dev/null; do
		if [ $((SECONDS - start)) -gt "$TIMEOUT" ]; then
			echo "TIMEOUT after ${TIMEOUT}s" >> "$logfile"
			docker rm -f "$name" >/dev/null 2>&1
			break
		fi
		sleep 5
	done
	wait "$pid" 2>/dev/null
	local rc=$?
	CURRENT_CONTAINER=""
	return $rc
}

drop_db() { docker exec "$DB_CONTAINER" mysql -uroot -pwordpress -e "DROP DATABASE IF EXISTS \`$1\`" 2>/dev/null; }
make_db() { docker exec "$DB_CONTAINER" mysql -uroot -pwordpress -e "CREATE DATABASE \`$1\` DEFAULT CHARACTER SET utf8mb4" 2>/dev/null; }

# ---- unit tests, once per PHP version ------------------------------------------------------
say "syncing sources for the unit tests"
mkdir -p "$CACHE/unit-src"
rsync -a --delete --exclude .git --exclude node_modules --exclude dist --exclude incoming --exclude .phpunit.result.cache "$REPO/" "$CACHE/unit-src/"
UNIT_DONE=""
for line in "${selected[@]}"; do
	IFS='|' read -r kind b p wk ms pc <<< "$line"
	case " $UNIT_DONE " in *" $p "*) continue ;; esac
	UNIT_DONE="$UNIT_DONE $p"
	say "unit tests on PHP $p"
	run_container "of-matrix-unit-php$p" "wardenenv/php-fpm:$p" "$CACHE/logs/unit-php$p.container.log" \
		-v "$CACHE/unit-src:/unit-src" -e MATRIX_MODE=unit -e "MATRIX_PHP=$p"
	say "  unit PHP $p: $(sed -n 's/^status=//p' "$CACHE/results/unit-php$p.kv" 2>/dev/null) $(sed -n 's/^summary=//p' "$CACHE/results/unit-php$p.kv" 2>/dev/null)"
done

# ---- groups -------------------------------------------------------------------------------
n=0
for line in "${selected[@]}"; do
	n=$((n + 1))
	IFS='|' read -r kind b p wk ms pc <<< "$line"
	wp="$(wpv "$b")"
	case "$wk" in
		min) read -r woo woozip <<< "$($RESOLVE woo-min "$CACHE" 9.0)"; note="" ;;
		current) read -r woo woozip note <<< "$($RESOLVE woo-current "$CACHE" "$wp" "$p.0" 11)" ;;
		*) read -r woo woozip <<< "$($RESOLVE woo-ver "$CACHE" "$wk")"; note="" ;;
	esac
	[ -n "${woozip:-}" ] || { echo "cannot resolve WooCommerce for $line" >&2; continue; }
	$RESOLVE core "$CACHE" "$wp" >/dev/null
	wkl="$wk"; case "$wk" in min|current) ;; *) wkl="custom" ;; esac
	gid="wp$b-php$p-woo$wkl"; [ "$ms" = 1 ] && gid="$gid-multisite"; [ "$ms" = 2 ] && gid="$gid-multisite-persite"
	db="of_matrix_$(echo "$gid" | tr -c 'a-zA-Z0-9\n' '_')"
	say "[$n/${#selected[@]}] $gid: WP $wp, PHP $p, WooCommerce $woo"
	mkdir -p "$CACHE/results"
	{
		echo "kind=$kind"; echo "wp=$wp"; echo "php=$p"; echo "woo_kind=$wkl"; echo "woo=$woo"
		echo "woo_note=${note:-}"; echo "multisite=$ms"
	} > "$CACHE/results/$gid.meta"
	drop_db "$db"; make_db "$db" || { echo "cannot create database $db" >&2; continue; }
	rm -rf "$CACHE/sites/$gid"
	start=$SECONDS
	run_container "of-matrix-$gid" "wardenenv/php-fpm:$p" "$CACHE/logs/$gid.container.log" \
		-v "$woozip:/woo.zip:ro" -v "$CACHE/unit-src:/unit-src:ro" \
		-e MATRIX_MODE=group -e "MATRIX_GROUP=$gid" -e "MATRIX_DB=$db" -e "MATRIX_WP=$wp" -e "MATRIX_PHP=$p" \
		-e "MATRIX_WOO=$woo" -e "MATRIX_HPOS=$HPOS_LIST" -e "MATRIX_MULTISITE=$ms" -e "MATRIX_PLUGIN_CHECK=$pc"
	drop_db "$db"
	rm -rf "$CACHE/sites/$gid"
	for h in $HPOS_LIST; do
		st="$(sed -n 's/^status=//p' "$CACHE/results/$gid/$h.kv" 2>/dev/null)"
		if [ -z "$st" ]; then
			mkdir -p "$CACHE/results/$gid"
			printf 'hpos_req=%s\nstatus=FAIL\nfail_reasons=container ended without a result (see logs/%s.container.log)\n' "$h" "$gid" > "$CACHE/results/$gid/$h.kv"
			st=FAIL
		fi
		say "  HPOS $h: $st ($((SECONDS - start))s total for the group)"
	done
done

say "rendering the report"
write_report
rc=$?
if [ "$rc" -ne 0 ]; then say "RESULT: at least one row FAILED"; else say "RESULT: all rows passed"; fi
exit "$rc"
