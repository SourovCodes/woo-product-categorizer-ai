#!/usr/bin/env bash
#
# Check that the plugin version is stated consistently.
#
# The version lives in two places that must move together — the `Version:` header and
# the WPCAI_VERSION constant — and, at release time, in the git tag as well. A build
# whose header disagrees with its constant ships an update WordPress will not offer.
#
# Usage:
#   ./bin/check-version.sh          # header and constant agree
#   ./bin/check-version.sh v0.4.0   # …and both match the tag
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE="$PLUGIN_DIR/woo-product-categorizer-ai.php"
EXPECTED="${1:-}"

die() {
	echo "check-version: $1" >&2
	exit 1
}

[ -f "$MAIN_FILE" ] || die "$MAIN_FILE not found."

HEADER_VERSION="$(sed -n "s/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p" "$MAIN_FILE" | head -n 1)"
CONSTANT_VERSION="$(sed -n "s/^define( 'WPCAI_VERSION', '\([^']*\)' );.*/\1/p" "$MAIN_FILE" | head -n 1)"

[ -n "$HEADER_VERSION" ] || die "No 'Version:' header found in $MAIN_FILE."
[ -n "$CONSTANT_VERSION" ] || die "No WPCAI_VERSION constant found in $MAIN_FILE."

if [ "$HEADER_VERSION" != "$CONSTANT_VERSION" ]; then
	die "Version header ($HEADER_VERSION) and WPCAI_VERSION ($CONSTANT_VERSION) disagree."
fi

if [ -n "$EXPECTED" ]; then
	# Accept both v0.4.0 and 0.4.0, and refs/tags/v0.4.0 from a workflow.
	EXPECTED="${EXPECTED#refs/tags/}"
	EXPECTED="${EXPECTED#v}"

	if [ "$HEADER_VERSION" != "$EXPECTED" ]; then
		die "Plugin version ($HEADER_VERSION) does not match the requested version ($EXPECTED)."
	fi
fi

echo "$HEADER_VERSION"
