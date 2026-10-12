#!/usr/bin/env bash
# Runs every quality gate in order and stops at the first failure.
set -uo pipefail
cd "$(dirname "$0")/.."
export COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-0.1.0}"

gates=(
	"phpcs:composer lint"
	"phpstan:composer analyse"
	"phpunit:composer test"
	"integration:composer test:integration"
	"plugin-check:bash bin/plugin-check.sh"
)

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
