#!/usr/bin/env bash
#
# Regenerate the translation catalogue and everything compiled from it.
#
#   1. languages/woo-product-categorizer-ai.pot  — extracted from the source.
#   2. languages/*.po                     — merged with the new POT, keeping existing
#                                           translations and marking changed ones fuzzy.
#   3. languages/*.mo, *.l10n.php         — compiled; these are what WordPress loads.
#
# Run this after adding or changing any translatable string, then translate whatever
# `bin/check-translations.sh` reports as missing.
#
# Everything goes through wp-cli's i18n commands, which are pure PHP: no gettext
# toolchain is required, on a developer machine or on a runner.
#
# Usage: ./bin/make-translations.sh
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LANG_DIR="$PLUGIN_DIR/languages"
DOMAIN="woo-product-categorizer-ai"
POT="$LANG_DIR/$DOMAIN.pot"

die() {
	echo "make-translations: $1" >&2
	exit 1
}

command -v wp >/dev/null 2>&1 || die "wp-cli is required but was not found on PATH."

# wp-cli's i18n commands need no WordPress install and no database, so this runs
# directly rather than through bin/wp.
echo "Extracting $POT…"
wp i18n make-pot "$PLUGIN_DIR" "$POT" \
	--slug="$DOMAIN" \
	--domain="$DOMAIN" \
	--package-name="Woo Product Categorizer AI" \
	--exclude=vendor,tests,bin,dist,node_modules \
	--quiet

echo "Merging the translations…"
wp i18n update-po "$POT" "$LANG_DIR" --quiet

# update-po leaves .po~ backups behind.
find "$LANG_DIR" -name '*.po~' -delete

echo "Compiling…"
wp i18n make-mo "$LANG_DIR" "$LANG_DIR" --quiet
# The .l10n.php files are what WordPress 6.5 and newer load in preference to the .mo;
# the .mo stays for anything reading the catalogue directly.
wp i18n make-php "$LANG_DIR" "$LANG_DIR" --quiet

echo "Done. Check the result with ./bin/check-translations.sh."
