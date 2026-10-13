#!/usr/bin/env bash
# Destructive testing cell: a throwaway WordPress + WooCommerce clone in one container with its own database.
#
#   bash bin/destructive.sh up              build the clone (database of_destr_1, files in $HOME/.cache/order-flow-destructive)
#   bash bin/destructive.sh run [groups]    run every scenario against the clone (starts it when needed)
#   bash bin/destructive.sh sync [ref]      install the plugin into the clone: the working tree, or a git ref such as v0.1.0
#   DESTRUCTIVE_BUILD=v0.1.0 ...            run against a git ref instead of the working tree
#   bash bin/destructive.sh down            remove the container, the database and the site files
#
# Needs: Docker, composer on the host, the lab network "wplab_default" with MySQL host "db" (container wplab-db-1).
# At most one container (of-destr-1) exists at a time. The lab site itself is never touched.
set -uo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
CACHE="${DESTRUCTIVE_CACHE:-$HOME/.cache/order-flow-destructive}"
LAB_ROOT="${DESTRUCTIVE_LAB_ROOT:-$(cd "$REPO/../../../.." && pwd)}"
WPCLI="${DESTRUCTIVE_WPCLI:-$LAB_ROOT/site/.bin/wp-cli.phar}"
NETWORK="${DESTRUCTIVE_NETWORK:-wplab_default}"
DB_CONTAINER="${DESTRUCTIVE_DB_CONTAINER:-wplab-db-1}"
IMAGE="${DESTRUCTIVE_IMAGE:-wardenenv/php-fpm:8.4}"
NAME="of-destr-1"
DB="of_destr_1"
SLUG="order-flow-for-woocommerce"
WP_VERSION="${DESTRUCTIVE_WP:-7.1.3}"
WOO_VERSION="${DESTRUCTIVE_WOO:-11.2.1}"
HEAVY="${HEAVY_SH:-$HOME/.cache/order-flow-hardening/heavy.sh}"

say() { echo "[$(date +%H:%M:%S)] $*"; }
dx() { docker exec -e HOME=/tmp/home "$NAME" "$@"; }
wpc() { dx php -d memory_limit=1G -d display_errors=stderr /wp-cli.phar --path=/cache/site "$@"; }

# Runs a heavy command under the shared lock, unless this script already runs under it.
heavy() {
	if [ -n "${OF_IN_HEAVY:-}" ] || grep -qsE "destructive.sh|snapshot.sh" "$HOME/.cache/order-flow-hardening/heavy.lock/owner"; then
		"$@"
	else
		"$HEAVY" "$@"
	fi
}

# Looks for a release zip in this cache, then in the Store Audit matrix cache, then downloads it.
fetch_zip() {
	local kind="$1" version="$2" file url dest
	if [ "$kind" = core ]; then
		file="wordpress-$version.zip"
		url="https://wordpress.org/$file"
	else
		file="woocommerce.$version.zip"
		url="https://downloads.wordpress.org/plugin/$file"
	fi
	dest="$CACHE/$kind/$file"
	mkdir -p "$CACHE/$kind"
	if [ ! -f "$dest" ]; then
		if [ -f "$HOME/.cache/store-audit-matrix/$kind/$file" ]; then
			cp "$HOME/.cache/store-audit-matrix/$kind/$file" "$dest"
		else
			curl -fsSL -o "$dest.part" "$url" && mv "$dest.part" "$dest" || { rm -f "$dest.part"; echo "cannot get $url" >&2; return 1; }
		fi
	fi
	echo "$dest"
}

need_env() {
	command -v docker >/dev/null || { echo "docker is required" >&2; exit 69; }
	docker network inspect "$NETWORK" >/dev/null 2>&1 || { echo "docker network $NETWORK not found (start the lab: warden env up)" >&2; exit 69; }
	docker exec "$DB_CONTAINER" true 2>/dev/null || { echo "database container $DB_CONTAINER is not running" >&2; exit 69; }
}

running() { [ "$(docker inspect -f '{{.State.Running}}' "$NAME" 2>/dev/null)" = "true" ]; }

start_container() {
	docker rm -f "$NAME" >/dev/null 2>&1 || true
	docker run -d --name "$NAME" --network "$NETWORK" --user "$(id -u):$(id -g)" --memory 3g --entrypoint bash \
		-e HOME=/tmp/home \
		-v "$CACHE:/cache" -v "$REPO:/repo:ro" -v "$WPCLI:/wp-cli.phar:ro" \
		"$IMAGE" -c 'mkdir -p /tmp/home; exec tail -f /dev/null' >/dev/null
}

# Runtime dependencies (dompdf and friends) for a composer.json + composer.lock pair, cached by content hash.
vendor_for() {
	local dir="$1" key target tmp
	key="$(cat "$dir/composer.json" "$dir/composer.lock" | shasum | cut -c1-16)"
	target="$CACHE/vendor/$key"
	if [ ! -d "$target/vendor" ]; then
		tmp="$(mktemp -d)"
		cp "$dir/composer.json" "$dir/composer.lock" "$tmp/"
		( cd "$tmp" && heavy composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet ) || { rm -rf "$tmp"; return 1; }
		find "$tmp/vendor" -name '.git*' -prune -exec rm -rf {} + 2>/dev/null || true
		mkdir -p "$target" && mv "$tmp/vendor" "$target/vendor"
		rm -rf "$tmp"
	fi
	echo "$target/vendor"
}

# Builds the shipping file set of $1 (a git ref, or "tree" for the working tree) in $CACHE/stage/$SLUG.
stage_plugin() {
	local ref="${1:-tree}" stage="$CACHE/stage/$SLUG" vendor zip version
	rm -rf "$CACHE/stage"
	mkdir -p "$stage"
	if [ "$ref" = tree ]; then
		# The working tree is staged exactly as shipped: through the repository's own build script.
		version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$REPO/$SLUG.php" | head -n 1 | tr -d '[:space:]')"
		( cd "$REPO" && heavy bash bin/build-zip.sh >/dev/null ) || { echo "bin/build-zip.sh failed" >&2; return 1; }
		zip="$REPO/dist/$SLUG-$version.zip"
		unzip -q "$zip" -d "$CACHE/stage" || return 1
	else
		# export-ignore in .gitattributes already drops the development files; composer files are read from the tag.
		( cd "$REPO" && git archive "$ref" ) | tar -x -C "$stage"
		( cd "$REPO" && git show "$ref:composer.json" ) > "$stage/composer.json"
		( cd "$REPO" && git show "$ref:composer.lock" ) > "$stage/composer.lock"
		vendor="$(vendor_for "$stage")" || return 1
		rsync -a "$vendor/" "$stage/vendor/"
		rm -f "$stage/composer.lock"
	fi
	echo "$ref" > "$CACHE/stage.ref"
}

sync_plugin() {
	local ref="${1:-tree}"
	stage_plugin "$ref" || { echo "could not stage $ref" >&2; return 1; }
	dx bash -c "mkdir -p /cache/site/wp-content/plugins/$SLUG && rsync -a --delete /cache/stage/$SLUG/ /cache/site/wp-content/plugins/$SLUG/ && mkdir -p /cache/site/wp-content/mu-plugins && cp /repo/tests/Destructive/mu/*.php /cache/site/wp-content/mu-plugins/"
}

start_server() {
	dx bash -c 'pgrep -f "[p]hp -d memory_limit.*-S 0.0.0.0:8080" >/dev/null' && return 0
	docker exec -d -e HOME=/tmp/home -e PHP_CLI_SERVER_WORKERS=8 "$NAME" \
		php -d memory_limit="${SERVER_MEMORY:-256M}" -d max_execution_time="${SERVER_TIME:-30}" -d display_errors=0 -d error_log=/cache/site/wp-content/php-error.log -S 0.0.0.0:8080 -t /cache/site
	for _ in $(seq 1 20); do
		dx curl -s -o /dev/null http://localhost:8080/ && return 0
		sleep 1
	done
	echo "server did not start" >&2
	return 1
}

stop_server() {
	local pid
	pid="$(dx bash -c 'pgrep -f "[p]hp -d memory_limit.*-S 0.0.0.0:8080" | head -1' 2>/dev/null || true)"
	[ -n "$pid" ] && dx kill "$pid" >/dev/null 2>&1
	return 0
}

db_exec() { docker exec "$DB_CONTAINER" mysql -uroot -pwordpress -e "$1" 2>/dev/null; }

cmd_up() {
	need_env
	local wpzip woozip
	wpzip="$(fetch_zip core "$WP_VERSION")" || exit 69
	woozip="$(fetch_zip woo "$WOO_VERSION")" || exit 69
	mkdir -p "$CACHE"
	say "database $DB and site files"
	db_exec "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` DEFAULT CHARACTER SET utf8mb4"
	rm -rf "$CACHE/site" "$CACHE/site.tmp"
	mkdir -p "$CACHE/site.tmp"
	unzip -q "$wpzip" -d "$CACHE/site.tmp" && mv "$CACHE/site.tmp/wordpress" "$CACHE/site" && rmdir "$CACHE/site.tmp"
	start_container
	wpc config create --dbname="$DB" --dbuser=root --dbpass=wordpress --dbhost=db --skip-check >/dev/null || exit 1
	wpc config set WP_DEBUG true --raw --type=constant >/dev/null
	wpc config set WP_DEBUG_LOG true --raw --type=constant >/dev/null
	wpc config set WP_DEBUG_DISPLAY false --raw --type=constant >/dev/null
	wpc config set FS_METHOD direct --type=constant >/dev/null
	wpc config set WP_HOME http://localhost:8080 --type=constant >/dev/null
	wpc config set WP_SITEURL http://localhost:8080 --type=constant >/dev/null
	wpc core install --url=http://localhost:8080 --title="OF Destructive" --admin_user=of-destr-admin --admin_password="Destr-Pass-123!" --admin_email=admin@example.test --skip-email >/dev/null || exit 1
	say "WooCommerce $WOO_VERSION"
	dx cp "/cache/woo/$(basename "$woozip")" /tmp/woo.zip
	wpc plugin install /tmp/woo.zip --activate >/dev/null || exit 1
	wpc option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null
	wpc option update woocommerce_task_list_hidden yes >/dev/null
	wpc option update woocommerce_default_country "US:CA" >/dev/null
	wpc option update woocommerce_currency USD >/dev/null
	wpc option update blogname "OF Destructive" >/dev/null
	wpc rewrite structure '/%postname%/' >/dev/null
	sync_plugin tree || exit 1
	wpc plugin activate "$SLUG" >/dev/null || exit 1
	wpc user create of-destr-sub sub@example.test --role=subscriber --user_pass="Destr-Pass-123!" >/dev/null
	wpc user create of-destr-shopmgr shop@example.test --role=shop_manager --user_pass="Destr-Pass-123!" >/dev/null
	wpc user create of-destr-custa cust-a@example.test --role=customer --user_pass="Destr-Pass-123!" >/dev/null
	wpc user create of-destr-custb cust-b@example.test --role=customer --user_pass="Destr-Pass-123!" >/dev/null
	wpc user create of-destr-editor editor@example.test --role=editor --user_pass="Destr-Pass-123!" >/dev/null
	say "clone ready"
}

cmd_down() {
	stop_server 2>/dev/null || true
	docker rm -f "$NAME" >/dev/null 2>&1 || true
	db_exec "DROP DATABASE IF EXISTS \`$DB\`"
	rm -rf "$CACHE/site" "$CACHE/site.tmp" "$CACHE/tmp" "$CACHE/stage" "$CACHE/snap-out"
	say "removed container $NAME, database $DB and the site files (zips and vendor cache stay in $CACHE)"
}

# The old release (git tag v0.1.0) and the current working tree as installable zips, for the upgrade scenario.
prepare_zips() {
	mkdir -p "$CACHE/old" "$CACHE/new"
	if [ ! -f "$CACHE/old/$SLUG-0.1.0.zip" ]; then
		stage_plugin v0.1.0 || return 1
		( cd "$CACHE/stage" && zip -qr "$CACHE/old/$SLUG-0.1.0.zip" "$SLUG" )
	fi
	rm -f "$CACHE/new/$SLUG-current.zip"
	if stage_plugin tree; then
		( cd "$CACHE/stage" && zip -qr "$CACHE/new/$SLUG-current.zip" "$SLUG" )
	else
		say "the working tree could not be built; scenarios that need the current zip will be skipped"
	fi
}

# Other plugins used by the dompdf conflict scenario.
prepare_extras() {
	mkdir -p "$CACHE/extras"
	fetch_zip woo 9.0.4 >/dev/null || true
	fetch_zip woo 8.9.3 >/dev/null || true
	local f="$CACHE/extras/woocommerce-pdf-invoices-packing-slips.zip"
	if [ ! -f "$f" ]; then
		curl -fsSL -o "$f.part" "https://downloads.wordpress.org/plugin/woocommerce-pdf-invoices-packing-slips.latest-stable.zip" && mv "$f.part" "$f" || { rm -f "$f.part"; say "could not download the PDF invoices plugin; scenario 6 will be skipped"; }
	fi
	if [ ! -d "$CACHE/tools/vendor/smalot" ]; then
		mkdir -p "$CACHE/tools"
		( cd "$CACHE/tools" && printf '{"require":{"smalot/pdfparser":"^2.0"},"config":{"platform":{"php":"8.1.0"}}}' > composer.json && heavy composer install --no-interaction --no-progress --quiet )
	fi
}

cmd_run() {
	need_env
	if ! running || [ ! -d "$CACHE/site/wp-content" ]; then cmd_up || exit 1; fi
	prepare_zips || exit 1
	prepare_extras
	sync_plugin "${DESTRUCTIVE_BUILD:-tree}" || exit 1
	start_server || exit 1
	dx env ONLY="${ONLY:-}" HPOS="${HPOS:-on}" bash -c 'cd /repo/tests/Destructive && node run.mjs "$@"' _ "$@"
}

case "${1:-}" in
	up) cmd_up ;;
	server) running && start_server ;;
	restart) running && { stop_server; sleep 1; start_server; } ;;
	down) cmd_down ;;
	sync) running || { echo "clone is not running" >&2; exit 1; }; sync_plugin "${2:-tree}" ;;
	prepare) prepare_zips && prepare_extras ;;
	run) shift; cmd_run "$@" ;;
	*) sed -n '2,10p' "$0"; exit 64 ;;
esac
