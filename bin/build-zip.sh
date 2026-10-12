#!/usr/bin/env bash
# Builds dist/order-flow-for-woocommerce-<version>.zip with shipping files only.
# vendor-prefixed/ ships in the zip (dompdf and friends under the OrderFlowWC\Vendor\ namespace); vendor/ does not.
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="order-flow-for-woocommerce"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SLUG.php" | head -n 1 | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "Cannot read version from $SLUG.php" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG" dist

# The runtime libraries come from the locked versions installed in vendor/. Strauss copies them under the
# OrderFlowWC\Vendor\ namespace into vendor-prefixed/ (bin/prefix.sh); only vendor-prefixed/ ships, vendor/ does not.
if composer install --dry-run --no-scripts --no-interaction 2>&1 | grep -q 'Package operations'; then
	echo "vendor/ does not match composer.lock. Run composer install first." >&2
	exit 1
fi
bash bin/prefix.sh

rsync -a --exclude-from=.distignore --exclude='/vendor' ./ "$STAGE/$SLUG/"
# composer.json ships (WordPress.org asks for it) without the development parts; the lock file does not ship.
php -r '$c = json_decode( (string) file_get_contents( "composer.json" ), true ); unset( $c["require-dev"], $c["autoload-dev"], $c["scripts"], $c["config"]["allow-plugins"] ); file_put_contents( $argv[1], json_encode( $c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );' "$STAGE/$SLUG/composer.json"
# Documentation, tests and tooling of the libraries are not needed at runtime. Licenses stay. The DejaVu fonts
# stay, including Serif and Mono: a theme override of the PDF template may name any of them.
V="$STAGE/$SLUG/vendor-prefixed"
find "$V" -name '.git*' -prune -exec rm -rf {} + 2>/dev/null || true
find "$V" -type f \( -name '*.md' -o -name 'phpunit.xml*' -o -name 'mustRead.html' \) ! -iname 'LICENSE*' -delete
find "$V" -type d \( -name bin -o -name tests -o -name test -o -name docs -o -name examples \) -prune -exec rm -rf {} + 2>/dev/null || true

ZIP="$PWD/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr "$ZIP" "$SLUG" )

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1))"
unzip -l "$ZIP" | tail -n 5

fail=0
while IFS= read -r -d '' f; do
	php -l "$f" > /dev/null || { echo "Syntax error: $f" >&2; fail=1; }
done < <(find "$STAGE/$SLUG" -name '*.php' -print0)
[ "$fail" -eq 0 ] || exit 1
echo "php -l OK"
