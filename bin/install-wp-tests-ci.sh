#!/usr/bin/env bash
#
# Provision the PHPUnit suite against a throwaway WordPress install.
#
# The sibling install-wp-tests.sh resolves everything from a Local by WP Engine site
# and only works on the development machine. This one downloads WordPress and
# WooCommerce itself and talks to MySQL over TCP, which is what CI has. It writes the
# same tests/wp-tests-config.php, so `composer test` afterwards is identical.
#
# Everything is overridable from the environment:
#
#   WP_VERSION   WordPress version, or "latest" (default).
#   WC_VERSION   WooCommerce version, or "latest" (default).
#   WP_ROOT      Where WordPress is unpacked (default /tmp/wordpress).
#   DB_NAME      Test database (default woo_kontor_tests).
#   DB_USER      MySQL user (default root).
#   DB_PASS      MySQL password (default root).
#   DB_HOST      MySQL host (default 127.0.0.1).
#   DB_PORT      MySQL port (default 3306).
#   DB_WAIT      Seconds to wait for MySQL to accept connections (default 60).
#
# Usage: ./bin/install-wp-tests-ci.sh
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

WP_VERSION="${WP_VERSION:-latest}"
WC_VERSION="${WC_VERSION:-latest}"
WP_ROOT="${WP_ROOT:-/tmp/wordpress}"
DB_NAME="${DB_NAME:-woo_kontor_tests}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_WAIT="${DB_WAIT:-60}"

CONFIG="$PLUGIN_DIR/tests/wp-tests-config.php"

die() {
	echo "install-wp-tests-ci: $1" >&2
	exit 1
}

for tool in curl tar unzip php; do
	command -v "$tool" >/dev/null 2>&1 || die "$tool is required but was not found on PATH."
done

# The development config points at the Local site. Clobbering it would silently
# repoint the suite at a downloaded WordPress the next time someone runs the tests.
# -F because WP_ROOT is a path, and a path is not a regular expression: a dot in a
# directory name would otherwise match any character and wave through a config
# pointing at a different install.
if [ -f "$CONFIG" ] && ! grep -qF "define( 'ABSPATH', '$WP_ROOT/' );" "$CONFIG"; then
	die "$CONFIG already exists and points somewhere else. Delete it first if you meant to replace it."
fi

# ---------------------------------------------------------------------------
# WordPress core.
# ---------------------------------------------------------------------------

if [ "latest" = "$WP_VERSION" ]; then
	WP_ARCHIVE_URL="https://wordpress.org/latest.tar.gz"
else
	WP_ARCHIVE_URL="https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
fi

if [ -f "$WP_ROOT/wp-load.php" ]; then
	echo "WordPress already present at $WP_ROOT, reusing it."
else
	echo "Downloading WordPress ($WP_VERSION)…"
	rm -rf "$WP_ROOT"
	mkdir -p "$WP_ROOT"
	# The tarball has a top-level wordpress/ directory; strip it.
	curl -fsSL "$WP_ARCHIVE_URL" | tar --strip-components=1 -xz -C "$WP_ROOT"
fi

[ -f "$WP_ROOT/wp-load.php" ] || die "No WordPress install at $WP_ROOT after download."

# ---------------------------------------------------------------------------
# WooCommerce. The suite loads it from the WordPress install, not from Composer.
# ---------------------------------------------------------------------------

WC_DIR="$WP_ROOT/wp-content/plugins/woocommerce"

if [ "latest" = "$WC_VERSION" ]; then
	WC_ARCHIVE_URL="https://downloads.wordpress.org/plugin/woocommerce.zip"
else
	WC_ARCHIVE_URL="https://downloads.wordpress.org/plugin/woocommerce.$WC_VERSION.zip"
fi

if [ -f "$WC_DIR/woocommerce.php" ]; then
	echo "WooCommerce already present at $WC_DIR, reusing it."
else
	echo "Downloading WooCommerce ($WC_VERSION)…"
	mkdir -p "$WP_ROOT/wp-content/plugins"
	curl -fsSL -o /tmp/woocommerce.zip "$WC_ARCHIVE_URL"
	unzip -qo /tmp/woocommerce.zip -d "$WP_ROOT/wp-content/plugins"
	rm -f /tmp/woocommerce.zip
fi

[ -f "$WC_DIR/woocommerce.php" ] || die "WooCommerce was not installed into $WC_DIR."

# ---------------------------------------------------------------------------
# The plugin itself, linked into the install the way Local links it.
#
# The suite loads the plugin from its checkout, but WooCommerce records feature
# compatibility under plugin_basename(), which only shortens to the plugin slug when
# the file resolves inside WP_PLUGIN_DIR. Without this link the HPOS declaration is
# recorded under an absolute path and the compatibility test fails — in CI only,
# which is the worst place for an environment difference to live.
# ---------------------------------------------------------------------------

PLUGIN_LINK="$WP_ROOT/wp-content/plugins/woo-product-categorizer-ai"

if [ ! -e "$PLUGIN_LINK" ] || [ -L "$PLUGIN_LINK" ]; then
	ln -sfn "$PLUGIN_DIR" "$PLUGIN_LINK"
	echo "Linked $PLUGIN_LINK → $PLUGIN_DIR"
else
	die "$PLUGIN_LINK exists and is not a symlink; refusing to replace it."
fi

# ---------------------------------------------------------------------------
# Database. Created through mysqli rather than the mysql client, which is not
# guaranteed to exist on a runner while PHP with mysqli always is.
# ---------------------------------------------------------------------------

# An explicit template rather than `mktemp -t`, which means different things to GNU
# coreutils and to BSD; the script runs on both. PHP does not need a .php suffix to
# execute a file given on the command line.
CREATE_DB="$(mktemp "${TMPDIR:-/tmp}/wpcai-create-db.XXXXXX")"
trap 'rm -f "$CREATE_DB"' EXIT
cat > "$CREATE_DB" <<'PHP'
<?php
mysqli_report( MYSQLI_REPORT_OFF );

$host    = getenv( 'DB_HOST' );
$port    = (int) getenv( 'DB_PORT' );
$user    = getenv( 'DB_USER' );
$pass    = getenv( 'DB_PASS' );
$name    = getenv( 'DB_NAME' );
$deadline = time() + (int) getenv( 'DB_WAIT' );

while ( true ) {
	$link = @new mysqli( $host, $user, $pass, '', $port );

	if ( ! $link->connect_errno ) {
		break;
	}

	if ( time() >= $deadline ) {
		fwrite( STDERR, 'Could not connect to MySQL: ' . $link->connect_error . PHP_EOL );
		exit( 1 );
	}

	echo 'Waiting for MySQL…' . PHP_EOL;
	sleep( 2 );
}

$sql = sprintf(
	'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
	str_replace( '`', '', $name )
);

if ( ! $link->query( $sql ) ) {
	fwrite( STDERR, 'Could not create the test database: ' . $link->error . PHP_EOL );
	exit( 1 );
}

echo sprintf( 'Test database "%s" is ready.', $name ) . PHP_EOL;
PHP

DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USER="$DB_USER" DB_PASS="$DB_PASS" DB_NAME="$DB_NAME" \
	DB_WAIT="$DB_WAIT" php "$CREATE_DB"
rm -f "$CREATE_DB"

# ---------------------------------------------------------------------------
# Test config.
# ---------------------------------------------------------------------------

echo "Writing $CONFIG…"
cat > "$CONFIG" <<PHP
<?php
/**
 * Generated by bin/install-wp-tests-ci.sh — do not edit or commit.
 *
 * @package WooKontorSync
 */

define( 'ABSPATH', '$WP_ROOT/' );

define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST:$DB_PORT' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'testshop.local' );
define( 'WP_TESTS_EMAIL', 'admin@testshop.local' );
define( 'WP_TESTS_TITLE', 'Woo Kontor Sync Test Suite' );
define( 'WP_PHP_BINARY', 'php' );

define( 'WP_DEBUG', true );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', '$PLUGIN_DIR/vendor/yoast/phpunit-polyfills' );
PHP

echo "Done. Run 'composer test'."
