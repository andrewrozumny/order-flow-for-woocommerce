#!/usr/bin/env bash
# Runs every quality gate in order and stops at the first failure.
#
#   bin/gates.sh          fast gates (static analysis, tests, lab integration, Plugin Check, i18n, audit)
#   bin/gates.sh --full   fast gates plus the destructive run, the WordPress x PHP compatibility matrix and the performance run
#
# Heavy gates run one after another on purpose: they share the machine with the lab.
set -uo pipefail
cd "$(dirname "$0")/.."
export COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-0.1.1}"

gates=(
	"composer-validate:composer validate --strict --no-check-publish"
	"composer-audit:composer audit --no-interaction"
	"phpcs:composer lint"
	"phpstan:composer analyse"
	"psalm-taint:composer taint"
	"phpunit:composer test"
	"i18n:composer i18n"
	"integration:composer test:integration"
	"plugin-check:bash bin/plugin-check.sh"
	"build-zip:bash bin/build-zip.sh"
	"plugin-check-zip:bash bin/plugin-check.sh --zip"
)
if [ "${1:-}" = "--full" ]; then
	gates+=(
		"destructive:bash bin/destructive.sh run"
		"matrix:bash bin/matrix.sh"
		"perf:bash bin/perf.sh"
	)
fi

for entry in "${gates[@]}"; do
	name="${entry%%:*}"
	cmd="${entry#*:}"
	echo "=== ${name}: ${cmd}"
	log="$(mktemp)"
	if eval "$cmd" > "$log" 2>&1; then
		tail -n 8 "$log"
		echo "PASS ${name}"
		rm -f "$log"
	else
		tail -n 40 "$log"
		echo "FAIL ${name}"
		rm -f "$log"
		exit 1
	fi
done
echo "ALL GATES PASSED"
