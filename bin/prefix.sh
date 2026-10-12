#!/usr/bin/env bash
# Copies the runtime libraries (dompdf and its dependencies) into vendor-prefixed/ under the
# OrderFlowWC\Vendor\ namespace, so they can never clash with another plugin bundling the same libraries.
#
#   bin/prefix.sh [--if-available] [project-dir]
#
#   project-dir      defaults to the plugin root; it needs composer.json and an installed vendor/
#   --if-available   do nothing, successfully, when Strauss is not installed (composer install --no-dev)
#
# Strauss rewrites namespaces, use statements and the class names that dompdf assembles from strings. One
# string in php-font-lib is missed by Strauss, so it is patched here and the result is verified.
set -euo pipefail

HERE="$(cd "$(dirname "$0")/.." && pwd)"
OPTIONAL=0
if [ "${1:-}" = "--if-available" ]; then
	OPTIONAL=1
	shift
fi
PROJECT="${1:-$HERE}"
STRAUSS="${STRAUSS:-$HERE/vendor/bin/strauss}"
if [ "$OPTIONAL" = 1 ] && [ ! -f "$STRAUSS" ]; then
	echo "Strauss is not installed (development dependency): vendor-prefixed/ was not generated." >&2
	exit 0
fi
[ -f "$STRAUSS" ] || { echo "Strauss not found at $STRAUSS (run composer install)." >&2; exit 1; }

cd "$PROJECT"
rm -rf vendor-prefixed
php "$STRAUSS" --silent=true

FILE="vendor-prefixed/dompdf/php-font-lib/src/FontLib/TrueType/File.php"
python3 - "$FILE" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
old = '"FontLib\\\\$type\\\\TableDirectoryEntry"'
new = '"OrderFlowWC\\\\Vendor\\\\FontLib\\\\$type\\\\TableDirectoryEntry"'
if old in s:
    open(p, 'w').write(s.replace(old, new))
elif new not in s:
    sys.exit('php-font-lib patch: expected string not found')
PY

# getFontType() takes the second segment of the class name ("FontLib\TrueType\File"); the prefix shifts it.
python3 - "$FILE" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
old = '    return $class_parts[1];\n'
new = '    return $class_parts[array_search("FontLib", $class_parts, true) + 1];\n'
if old in s:
    open(p, 'w').write(s.replace(old, new, 1))
elif new not in s:
    sys.exit('php-font-lib getFontType patch: expected code not found')
PY

# dompdf writes parsed font metrics (*.ufm.json) next to the fonts when it can; a development vendor/ may hold such caches.
find vendor-prefixed -name '*.ufm.json' -delete

# Any quoted class name that still starts with an unprefixed library namespace is a miss.
if grep -rnE "[\"'](\\\\\\\\)?(Dompdf|FontLib|Svg|Masterminds|Sabberworm)\\\\\\\\" vendor-prefixed --include='*.php' | grep -v '^vendor-prefixed/composer'; then
	echo "Unprefixed library class name found" >&2
	exit 1
fi

# The plugin has its own small loader (includes/Pdf/Libraries.php) that reads the class map. The Composer
# loader files are not used and are removed so nothing can register them.
rm -f vendor-prefixed/autoload.php
cd vendor-prefixed/composer
rm -f autoload_real.php autoload_static.php ClassLoader.php InstalledVersions.php installed.php installed.json \
	platform_check.php autoload_files.php autoload_namespaces.php autoload_psr4.php
cd ../..
echo "vendor-prefixed ready ($(find vendor-prefixed -name '*.php' | wc -l | tr -d ' ') php files)"
