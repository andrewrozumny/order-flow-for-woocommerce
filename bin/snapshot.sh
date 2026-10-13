#!/usr/bin/env bash
# Behaviour snapshots: record what a build of the plugin does, then prove later that a refactor did not change it.
#
#   bash bin/snapshot.sh record [ref]   install a git ref (default v0.1.0) in the clone, snapshot it into tests/Snapshots/<ref>/
#   bash bin/snapshot.sh compare [ref]  install the working tree, snapshot it, diff against tests/Snapshots/<ref>/ (default v0.1.0)
#   bash bin/snapshot.sh twice [ref]    snapshot one build twice in a row and diff the runs (determinism check)
#
# SNAPSHOT_BUILD=<git ref> makes `compare` install that ref instead of the working tree (used to check a reference against itself).
# SNAPSHOT_MODES="hpos-on" limits the run to one store layout.
#
# Each snapshot runs with HPOS on and with HPOS off. It drives the plugin over HTTP in the throwaway clone of
# bin/destructive.sh (statuses, transitions, emails, PDFs, configuration export, order screen markup) and
# normalises dates, ids, nonces and order keys. `compare` exits 1 when anything differs.
set -uo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
CACHE="${DESTRUCTIVE_CACHE:-$HOME/.cache/order-flow-destructive}"
NAME="of-destr-1"
MODES="${SNAPSHOT_MODES:-hpos-on hpos-off}"
HEAVY="${HEAVY_SH:-$HOME/.cache/order-flow-hardening/heavy.sh}"

say() { echo "[$(date +%H:%M:%S)] $*"; }

ensure_clone() {
	if [ "$(docker inspect -f '{{.State.Running}}' "$NAME" 2>/dev/null)" != "true" ] || [ ! -d "$CACHE/site/wp-content" ]; then
		say "building the clone"
		bash "$REPO/bin/destructive.sh" up || exit 1
	fi
	bash "$REPO/bin/destructive.sh" prepare || exit 1
}

install_build() {
	say "installing $1 in the clone"
	bash "$REPO/bin/destructive.sh" sync "$1" || exit 1
	bash "$REPO/bin/destructive.sh" server
}

snapshot_into() {
	local out="$1" mode
	for mode in $MODES; do
		say "snapshot $mode"
		docker exec -e HOME=/tmp/home "$NAME" bash -c "mkdir -p /cache/tmp && cd /repo/tests/Snapshots && node snap.mjs /cache/snap-out/run-$mode $mode" || return 1
		rm -rf "$out/$mode"
		mkdir -p "$out"
		cp -R "$CACHE/snap-out/run-$mode" "$out/$mode"
	done
}

cmd="${1:-}"
ref="${2:-v0.1.0}"
case "$cmd" in
	record)
		ensure_clone
		install_build "$ref"
		snapshot_into "$REPO/tests/Snapshots/$ref" || exit 1
		say "reference written to tests/Snapshots/$ref"
		;;
	compare)
		ensure_clone
		install_build "${SNAPSHOT_BUILD:-tree}"
		rm -rf "$CACHE/snap-out/current"
		snapshot_into "$CACHE/snap-out/current" || exit 1
		status=0
		for mode in $MODES; do
			if diff -ru "$REPO/tests/Snapshots/$ref/$mode" "$CACHE/snap-out/current/$mode" > "$CACHE/snap-out/diff-$mode.txt"; then
				say "$mode: identical to $ref"
			else
				say "$mode: DIFFERS from $ref ($(grep -c '^[-+][^-+]' "$CACHE/snap-out/diff-$mode.txt") changed lines), see $CACHE/snap-out/diff-$mode.txt"
				head -n 60 "$CACHE/snap-out/diff-$mode.txt"
				status=1
			fi
		done
		exit "$status"
		;;
	twice)
		ensure_clone
		install_build "$ref"
		rm -rf "$CACHE/snap-out/a" "$CACHE/snap-out/b"
		snapshot_into "$CACHE/snap-out/a" || exit 1
		snapshot_into "$CACHE/snap-out/b" || exit 1
		diff -ru "$CACHE/snap-out/a" "$CACHE/snap-out/b" && say "two runs of $ref are identical"
		;;
	*)
		sed -n '2,14p' "$0"
		exit 64
		;;
esac
