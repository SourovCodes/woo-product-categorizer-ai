<?php
/**
 * PHPUnit bootstrap.
 *
 * Uses the wp-phpunit package for the WordPress test library, so no SVN checkout
 * of core is required. Run bin/install-wp-tests.sh once first: it creates the test
 * database and generates tests/wp-tests-config.php.
 *
 * @package WooProductCategorizerAi
 */

$wpcai_plugin_dir = dirname( __DIR__ );
$wpcai_tests_dir  = getenv( 'WP_PHPUNIT__DIR' );

if ( ! $wpcai_tests_dir ) {
	$wpcai_tests_dir = $wpcai_plugin_dir . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $wpcai_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library. Run 'composer install' first." . PHP_EOL;
	exit( 1 );
}

if ( ! file_exists( __DIR__ . '/wp-tests-config.php' ) ) {
	echo "Missing tests/wp-tests-config.php. Run './bin/install-wp-tests.sh' first." . PHP_EOL;
	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require_once $wpcai_plugin_dir . '/vendor/autoload.php';
require_once $wpcai_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce and this plugin into the test site.
 *
 * WooCommerce comes from the WordPress install the tests run against, so the suite
 * exercises the same version the development site runs.
 *
 * @return void
 */
function wpcai_manually_load_plugins() {
	$woocommerce = ABSPATH . 'wp-content/plugins/woocommerce/woocommerce.php';

	if ( ! file_exists( $woocommerce ) ) {
		echo 'WooCommerce was not found at ' . $woocommerce . PHP_EOL;
		exit( 1 );
	}

	require_once $woocommerce;

	/*
	 * Load the plugin the way WordPress loads it: through wp-content/plugins, having
	 * registered the real path behind the symlink first. Requiring the checkout
	 * directly instead leaves plugin_basename() unable to shorten the path to the
	 * plugin slug, and everything keyed on that slug then behaves differently under
	 * test than in production — the WooCommerce compatibility declaration is recorded
	 * under an absolute path, and load_plugin_textdomain() registers a languages
	 * directory that does not exist, so no translation ever loads.
	 */
	$plugin = WP_PLUGIN_DIR . '/woo-product-categorizer-ai/woo-product-categorizer-ai.php';

	if ( ! file_exists( $plugin ) ) {
		echo 'This checkout is not linked into ' . WP_PLUGIN_DIR . '/woo-product-categorizer-ai.' . PHP_EOL;
		echo 'Link it there and try again.' . PHP_EOL;
		exit( 1 );
	}

	wp_register_plugin_realpath( $plugin );

	require_once $plugin;
}
tests_add_filter( 'muplugins_loaded', 'wpcai_manually_load_plugins' );

/**
 * Install the WooCommerce database tables and roles before the tests run.
 *
 * @return void
 */
function wpcai_install_woocommerce() {
	if ( ! class_exists( 'WC_Install' ) ) {
		return;
	}

	// Suppress the "installed" notices WC_Install emits while creating tables.
	$_SERVER['REQUEST_URI'] = '/';
	WC_Install::install();

	// WC_Install adds roles, so the global has to be rebuilt for them to be visible.
	$GLOBALS['wp_roles'] = null;
	wp_roles();
}
tests_add_filter( 'setup_theme', 'wpcai_install_woocommerce' );

require $wpcai_tests_dir . '/includes/bootstrap.php';
