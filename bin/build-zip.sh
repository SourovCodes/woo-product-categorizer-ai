#!/usr/bin/env bash
#
# Build the distributable plugin zip.
#
# Only the files WordPress actually runs are staged: the main file, includes/,
# assets/, languages/, uninstall.php and a production vendor/. Composer runs inside
# the staging copy rather than in the checkout, so building never disturbs the dev
# dependencies the test suite needs.
#
# Usage: ./bin/build-zip.sh [version]
#
# The version is verified against the plugin header when given; otherwise the header
# supplies it. The result is dist/woo-product-categorizer-ai-<version>.zip plus a .sha256,
# and dist/update.json — the manifest WordPress reads to discover the release.
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="woo-product-categorizer-ai"
DIST_DIR="$PLUGIN_DIR/dist"

die() {
	echo "build-zip: $1" >&2
	exit 1
}

for tool in composer zip; do
	command -v "$tool" >/dev/null 2>&1 || die "$tool is required but was not found on PATH."
done

VERSION="$( "$PLUGIN_DIR/bin/check-version.sh" "${1:-}" )"

# An explicit template rather than `mktemp -t`, which means different things to GNU
# coreutils and to BSD; the script runs on both.
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/wpcai-build.XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT
BUILD="$STAGE/$SLUG"
mkdir -p "$BUILD"

echo "Staging $SLUG $VERSION…"

cp "$PLUGIN_DIR/$SLUG.php" "$BUILD/"
cp "$PLUGIN_DIR/uninstall.php" "$BUILD/"
cp "$PLUGIN_DIR/composer.json" "$PLUGIN_DIR/composer.lock" "$BUILD/"

for dir in includes assets languages; do
	if [ -d "$PLUGIN_DIR/$dir" ]; then
		cp -R "$PLUGIN_DIR/$dir" "$BUILD/$dir"
	fi
done

# Translation sources are not needed at runtime; the compiled .mo files are.
find "$BUILD/languages" -name '*.po' -delete 2>/dev/null || true

echo "Installing production dependencies…"
composer install \
	--working-dir="$BUILD" \
	--no-dev \
	--no-interaction \
	--no-progress \
	--optimize-autoloader \
	--classmap-authoritative

# The manifests are build inputs, not runtime files, and shipping them invites
# someone to run composer install inside a live plugins directory.
rm -f "$BUILD/composer.json" "$BUILD/composer.lock"

find "$BUILD" -name '.DS_Store' -delete
find "$BUILD" -name '*.map' -delete

mkdir -p "$DIST_DIR"
ZIP="$DIST_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP" "$ZIP.sha256"

( cd "$STAGE" && zip -qr "$ZIP" "$SLUG" -x '*.DS_Store' )

if command -v sha256sum >/dev/null 2>&1; then
	( cd "$DIST_DIR" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256" )
else
	( cd "$DIST_DIR" && shasum -a 256 "$(basename "$ZIP")" > "$(basename "$ZIP").sha256" )
fi

# The manifest WordPress reads to discover the release. Published beside the zip under
# a constant name, so https://github.com/<repo>/releases/latest/download/update.json
# always resolves to the newest one; the plugin's updater reads nothing else. The
# package URL is predicted from the version because bin/check-version.sh has already
# established that the tag is v<version>.
MANIFEST="$DIST_DIR/update.json"

SLUG="$SLUG" VERSION="$VERSION" MAIN_FILE="$PLUGIN_DIR/$SLUG.php" php -r '
$source = file_get_contents( getenv( "MAIN_FILE" ) );
$slug    = getenv( "SLUG" );
$version = getenv( "VERSION" );

$header = function ( $name ) use ( $source ) {
	$pattern = "/^[ \t\/*#@]*" . preg_quote( $name, "/" ) . ":(.*)$/mi";
	return preg_match( $pattern, $source, $matches ) ? trim( $matches[1] ) : "";
};

$repository = $header( "Plugin URI" );

echo json_encode(
	array(
		"slug"         => $slug,
		"plugin"       => $slug . "/" . $slug . ".php",
		"name"         => $header( "Plugin Name" ),
		"description"  => $header( "Description" ),
		"author"       => $header( "Author" ),
		"version"      => $version,
		"requires"     => $header( "Requires at least" ),
		"requires_php" => $header( "Requires PHP" ),
		"url"          => $repository . "/releases/tag/v" . $version,
		"package"      => $repository . "/releases/download/v" . $version . "/" . $slug . "-" . $version . ".zip",
		"last_updated" => gmdate( "Y-m-d H:i:s" ),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";
' > "$MANIFEST"

echo "Built $ZIP"
echo "Built $MANIFEST"
