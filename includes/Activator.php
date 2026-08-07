<?php
/**
 * Activation routine.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi;

use WooProductCategorizerAi\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 */
final class Activator {

	/**
	 * Prepare the site for the plugin.
	 *
	 * Keep this idempotent: WordPress runs it on every activation, including
	 * reactivation after an update.
	 *
	 * Nothing is rescheduled here, unlike the sibling sync plugin. Every job this
	 * plugin runs is started by hand from the settings screen, so there is no
	 * recurring schedule for a deactivation to have cancelled.
	 *
	 * @return void
	 */
	public static function activate() {
		/*
		 * Seed the settings so the admin screen always has a complete array to read.
		 * Autoload is off because they are only needed on the settings screen and
		 * inside the jobs themselves — never on a front-end request.
		 */
		add_option( Settings::OPTION_KEY, Settings::default_settings(), '', false );

		add_option( 'woo_product_categorizer_ai_version', WPCAI_VERSION, '', false );

		/**
		 * Fires after the plugin has finished its activation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_product_categorizer_ai_activated' );
	}
}
