<?php
/**
 * Plugin wiring.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Jobs\Scheduler;
use WooProductCategorizerAi\Plugin;
use WP_UnitTestCase;

/**
 * Covers that the plugin loaded, registered its hooks and put its screen where it
 * says it does.
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * The plugin file constants are defined once the main file has run.
	 *
	 * @return void
	 */
	public function test_the_plugin_constants_are_defined() {
		$this->assertTrue( defined( 'WPCAI_VERSION' ) );
		$this->assertTrue( defined( 'WPCAI_PLUGIN_FILE' ) );
		$this->assertTrue( defined( 'WPCAI_MIN_WC_VERSION' ) );
	}

	/**
	 * The version constant and the plugin header must agree, or the updater offers
	 * the wrong thing.
	 *
	 * @return void
	 */
	public function test_the_version_constant_matches_the_plugin_header() {
		$data = get_file_data( WPCAI_PLUGIN_FILE, array( 'Version' => 'Version' ) );

		$this->assertSame( $data['Version'], WPCAI_VERSION );
	}

	/**
	 * WooCommerce is present in the test site, so the plugin should have booted.
	 *
	 * @return void
	 */
	public function test_the_plugin_booted() {
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertNotFalse( has_action( 'init', array( Plugin::instance(), 'load_textdomain' ) ) );
	}

	/**
	 * Calling init() again must not register a second set of hooks. It runs on
	 * plugins_loaded and is public, so a second call is entirely possible.
	 *
	 * @return void
	 */
	public function test_init_is_idempotent() {
		$before = has_filter( 'load_textdomain_mofile', array( Plugin::instance(), 'map_german_locale' ) );

		Plugin::instance()->init();

		$this->assertSame( $before, has_filter( 'load_textdomain_mofile', array( Plugin::instance(), 'map_german_locale' ) ) );
	}

	/**
	 * The Action Scheduler failure hooks are registered outside is_admin(), because
	 * the queue runs on whatever request happens to drive it.
	 *
	 * @return void
	 */
	public function test_the_scheduler_failure_hooks_are_registered() {
		$this->assertNotFalse( has_action( 'action_scheduler_failed_execution' ) );
		$this->assertNotFalse( has_action( 'action_scheduler_unexpected_shutdown' ) );
	}

	/**
	 * Every action the plugin queues must resolve to a job, or a failure cannot be
	 * recorded against anything.
	 *
	 * @return void
	 */
	public function test_every_action_maps_to_a_job() {
		$actions = array(
			Scheduler::ACTION_PROPOSE          => 'taxonomy',
			Scheduler::ACTION_PROPOSE_SAMPLE   => 'taxonomy',
			Scheduler::ACTION_PROPOSE_ASK      => 'taxonomy',
			Scheduler::ACTION_PROPOSE_FINALISE => 'taxonomy',
			Scheduler::ACTION_ASSIGN           => 'assign',
			Scheduler::ACTION_ASSIGN_BATCH     => 'assign',
			Scheduler::ACTION_ASSIGN_FINALISE  => 'assign',
			Scheduler::ACTION_REVERT           => 'revert',
			Scheduler::ACTION_REVERT_BATCH     => 'revert',
			Scheduler::ACTION_REVERT_FINALISE  => 'revert',
		);

		foreach ( $actions as $hook => $job ) {
			$this->assertSame( $job, Scheduler::job_for_action( $hook ), $hook . ' should belong to the ' . $job . ' job' );
		}

		$this->assertSame( '', Scheduler::job_for_action( 'some_other_plugins_hook' ) );
	}

	/**
	 * The screen lives under WooCommerce and is gated on managing it.
	 *
	 * @return void
	 */
	public function test_the_settings_page_is_registered_under_woocommerce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $submenu;

		$submenu = array();

		( new Settings() )->register_menu();

		$this->assertArrayHasKey( 'woocommerce', $submenu );

		$slugs = wp_list_pluck( $submenu['woocommerce'], 2 );

		$this->assertContains( Settings::PAGE_SLUG, $slugs );
	}

	/**
	 * A shop running a German locale the plugin does not ship a catalogue for gets
	 * the closest one it does, rather than an English admin screen.
	 *
	 * @return void
	 */
	public function test_german_locales_fall_back_to_the_shipped_catalogues() {
		$dir    = WPCAI_PLUGIN_DIR . 'languages/';
		$plugin = Plugin::instance();

		// A locale with no catalogue is only remapped when the fallback exists.
		$informal = $dir . Plugin::TEXT_DOMAIN . '-' . Plugin::GERMAN_INFORMAL . '.mo';
		$formal   = $dir . Plugin::TEXT_DOMAIN . '-' . Plugin::GERMAN_FORMAL . '.mo';

		if ( file_exists( $informal ) ) {
			$this->assertSame(
				$informal,
				$plugin->map_german_locale( $dir . Plugin::TEXT_DOMAIN . '-de_CH.mo', Plugin::TEXT_DOMAIN )
			);
		}

		if ( file_exists( $formal ) ) {
			$this->assertSame(
				$formal,
				$plugin->map_german_locale( $dir . Plugin::TEXT_DOMAIN . '-de_CH_formal.mo', Plugin::TEXT_DOMAIN )
			);
		}

		// Another plugin's catalogue is never touched.
		$other = $dir . 'some-other-plugin-de_CH.mo';
		$this->assertSame( $other, $plugin->map_german_locale( $other, 'some-other-plugin' ) );

		// Neither is a locale that has nothing to do with German.
		$french = $dir . Plugin::TEXT_DOMAIN . '-fr_FR.mo';
		$this->assertSame( $french, $plugin->map_german_locale( $french, Plugin::TEXT_DOMAIN ) );
	}
}
