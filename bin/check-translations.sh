#!/usr/bin/env bash
#
# Check that languages/woo-product-categorizer-ai.pot still describes the source.
#
# Only the set of translatable strings is compared, not the file itself: the POT also
# records the file and line each string came from, and failing a build because someone
# moved a function twenty lines down would teach everyone to ignore this check. Adding,
# changing or removing a string is what has to be caught, because a string missing from
# the POT is a string nobody can translate.
#
# Whether those strings are actually *translated* is asserted by tests/I18nTest.php,
# which runs inside WordPress and can read the compiled catalogues.
#
# Usage: ./bin/check-translations.sh
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOMAIN="woo-product-categorizer-ai"
POT="$PLUGIN_DIR/languages/$DOMAIN.pot"

die() {
	echo "check-translations: $1" >&2
	exit 1
}

command -v wp >/dev/null 2>&1 || die "wp-cli is required but was not found on PATH."
[ -f "$POT" ] || die "$POT is missing. Run ./bin/make-translations.sh."

WORK="$(mktemp -d "${TMPDIR:-/tmp}/wpcai-i18n.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

wp i18n make-pot "$PLUGIN_DIR" "$WORK/$DOMAIN.pot" \
	--slug="$DOMAIN" \
	--domain="$DOMAIN" \
	--package-name="Woo Product Categorizer AI" \
	--exclude=vendor,tests,bin,dist,node_modules \
	--quiet

COMPARE="$WORK/compare.php"
cat > "$COMPARE" <<'PHP'
<?php
/**
 * Compare the translatable strings in two PO/POT files.
 */

/**
 * Read every msgid and msgid_plural from a PO/POT file.
 *
 * @param string $file Path.
 * @return array
 */
function wpcai_msgids( $file ) {
	$found   = array();
	$current = null;
	$buffer  = '';

	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( 0 === strpos( $line, 'msgid "' ) || 0 === strpos( $line, 'msgid_plural "' ) ) {
			if ( null !== $current ) {
				$found[] = $buffer;
			}

			$current = true;
			$buffer  = substr( $line, strpos( $line, '"' ) + 1, -1 );
			continue;
		}

		if ( null !== $current && 0 === strpos( $line, '"' ) ) {
			$buffer .= substr( $line, 1, -1 );
			continue;
		}

		if ( null !== $current ) {
			$found[] = $buffer;
			$current = null;
			$buffer  = '';
		}
	}

	if ( null !== $current ) {
		$found[] = $buffer;
	}

	// The header entry has an empty msgid.
	$found = array_filter( $found, 'strlen' );
	$found = array_unique( $found );
	sort( $found );

	return $found;
}

$committed = wpcai_msgids( $argv[1] );
$extracted = wpcai_msgids( $argv[2] );

$added   = array_diff( $extracted, $committed );
$removed = array_diff( $committed, $extracted );

foreach ( $added as $one ) {
	echo '  + ' . $one . PHP_EOL;
}

foreach ( $removed as $one ) {
	echo '  - ' . $one . PHP_EOL;
}

exit( $added || $removed ? 1 : 0 );
PHP

if php "$COMPARE" "$POT" "$WORK/$DOMAIN.pot"; then
	echo "check-translations: the catalogue is up to date."
else
	echo "" >&2
	echo "check-translations: the strings above differ between the source and $POT." >&2
	echo "                    Run ./bin/make-translations.sh and translate what is new." >&2
	exit 1
fi
