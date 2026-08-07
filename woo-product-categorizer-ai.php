<?php
/**
 * Plugin Name:          Woo Product Categorizer AI
 * Plugin URI:           https://github.com/SourovCodes/woo-product-categorizer-ai
 * Update URI:           https://github.com/SourovCodes/woo-product-categorizer-ai
 * Description:          Categorises WooCommerce products with an LLM: proposes a category tree, lets you review it, then assigns the whole catalogue.
 * Version:              0.1.0
 * Requires at least:    7.0
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce
 * Author:               Sourov Biswas
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          woo-product-categorizer-ai
 * Domain Path:          /languages
 * WC requires at least: 11.0
 * WC tested up to:      11.0
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi;

defined( 'ABSPATH' ) || exit;

define( 'WPCAI_VERSION', '0.1.0' );
define( 'WPCAI_PLUGIN_FILE', __FILE__ );
define( 'WPCAI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCAI_MIN_WC_VERSION', '11.0' );

/**
 * Load the Composer autoloader.
 *
 * A distributed build always ships vendor/, but a git checkout may not have run
 * `composer install` yet. Fail loudly in the admin rather than fatally.
 *
 * @return bool True when the autoloader was loaded.
 */
function load_autoloader() {
	$autoloader = WPCAI_PLUGIN_DIR . 'vendor/autoload.php';

	if ( ! is_readable( $autoloader ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_autoloader_notice' );
		return false;
	}

	require_once $autoloader;
	return true;
}

/**
 * Show an admin notice explaining that the Composer dependencies are missing.
 *
 * @return void
 */
function render_missing_autoloader_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'Woo Product Categorizer AI is missing its dependencies. Run "composer install" in the plugin directory.',
			'woo-product-categorizer-ai'
		)
	);
}

/**
 * Show an admin notice explaining that WooCommerce is required.
 *
 * @return void
 */
function render_missing_woocommerce_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum supported WooCommerce version. */
				__( 'Woo Product Categorizer AI requires WooCommerce %s or newer to be installed and active.', 'woo-product-categorizer-ai' ),
				WPCAI_MIN_WC_VERSION
			)
		)
	);
}

/**
 * Determine whether a supported version of WooCommerce is active.
 *
 * The "Requires Plugins" header covers WordPress 6.5 and newer, but it does not
 * enforce a minimum WooCommerce version, so check that here too.
 *
 * @return bool True when WooCommerce is active and new enough.
 */
function is_woocommerce_supported() {
	if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
		return false;
	}

	return version_compare( WC_VERSION, WPCAI_MIN_WC_VERSION, '>=' );
}

/**
 * Declare compatibility with WooCommerce feature flags.
 *
 * Unlike the sibling sync plugin, this one has no High-Performance Order Storage
 * gate: it never reads or writes an order, only products and the product category
 * taxonomy, so the order store it runs beside is none of its business. The
 * declarations stay because a plugin that does not declare is listed as
 * incompatible, which would push shop owners away from enabling HPOS for no
 * reason at all.
 *
 * @return void
 */
function declare_woocommerce_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPCAI_PLUGIN_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPCAI_PLUGIN_FILE, true );
}

/**
 * Boot the plugin once all other plugins are loaded.
 *
 * @return void
 */
function bootstrap() {
	if ( ! is_woocommerce_supported() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_woocommerce_notice' );
		return;
	}

	Plugin::instance()->init();
}

if ( load_autoloader() ) {
	/*
	 * Registered ahead of the WooCommerce gate rather than inside bootstrap(). An
	 * update is often the thing that fixes a plugin sitting inert behind that gate,
	 * so a site whose WooCommerce is too old must still be offered the version that
	 * supports it.
	 */
	( new Updates\Updater() )->register();

	add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\declare_woocommerce_compatibility' );
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

	register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
	register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );
}
