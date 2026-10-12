#!/usr/bin/env bash
# Builds dist/order-flow-for-woocommerce-<version>.zip with shipping files only.
# vendor/ ships in the zip but holds only the runtime dependencies (dompdf and friends),
# installed fresh inside the stage copy.
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="order-flow-for-woocommerce"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SLUG.php" | head -n 1 | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "Cannot read version from $SLUG.php" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG" dist

rsync -a --exclude-from=.distignore --exclude='/vendor' ./ "$STAGE/$SLUG/"

cp composer.json composer.lock "$STAGE/$SLUG/"
( cd "$STAGE/$SLUG" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet )
rm -f "$STAGE/$SLUG/composer.json" "$STAGE/$SLUG/composer.lock"
find "$STAGE/$SLUG/vendor" -name '.git*' -prune -exec rm -rf {} + 2>/dev/null || true

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
