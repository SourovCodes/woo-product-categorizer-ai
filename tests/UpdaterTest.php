<?php
/**
 * Tests for the GitHub release updater.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Updates\Updater;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers how releases are offered to WordPress as plugin updates.
 */
class UpdaterTest extends WP_UnitTestCase {

	/**
	 * Number of HTTP requests the stub has answered.
	 *
	 * @var int
	 */
	private $requests = 0;

	/**
	 * Body the stubbed manifest request returns.
	 *
	 * @var string|WP_Error
	 */
	private $body = '';

	/**
	 * Start every test with a cold cache and the manifest request stubbed.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_site_transient( Updater::CACHE_KEY );
		delete_site_transient( Updater::CORE_CACHE_KEY );

		$this->requests = 0;
		$this->body     = wp_json_encode( $this->manifest() );

		add_filter( 'pre_http_request', array( $this, 'stub_request' ), 10, 3 );
	}

	/**
	 * Leave no cached manifest behind for the next test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'stub_request' ), 10 );
		delete_site_transient( Updater::CACHE_KEY );
		delete_site_transient( Updater::CORE_CACHE_KEY );

		parent::tear_down();
	}

	/**
	 * Answer the manifest request without touching the network.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Requested URL.
	 * @return array|WP_Error|mixed
	 */
	public function stub_request( $preempt, $args, $url ) {
		/*
		 * wp_update_plugins() asks WordPress.org first and abandons the whole check if
		 * that call fails, so the Update URI filter would never be reached. Answering it
		 * with an empty result is what lets refresh() be exercised without the network.
		 */
		if ( str_contains( $url, 'api.wordpress.org/plugins/update-check' ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'plugins'      => array(),
						'translations' => array(),
						'no_update'    => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		if ( Updater::MANIFEST_URL !== $url ) {
			return $preempt;
		}

		++$this->requests;

		if ( is_wp_error( $this->body ) ) {
			return $this->body;
		}

		return array(
			'headers'  => array(),
			'body'     => $this->body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A well-formed manifest, as bin/build-zip.sh writes it.
	 *
	 * @param array $overrides Fields to replace.
	 * @return array
	 */
	private function manifest( array $overrides = array() ) {
		return array_merge(
			array(
				'slug'         => 'woo-product-categorizer-ai',
				'plugin'       => 'woo-product-categorizer-ai/woo-product-categorizer-ai.php',
				'name'         => 'Woo Product Categorizer AI',
				'description'  => 'Synchronises WooCommerce products, orders and customers with the Kontor ERP.',
				'author'       => 'Sourov Biswas',
				'version'      => '9.9.9',
				'requires'     => '7.0',
				'requires_php' => '8.2',
				'url'          => Updater::REPOSITORY . '/releases/tag/v9.9.9',
				'package'      => Updater::PACKAGE_PREFIX . 'v9.9.9/woo-product-categorizer-ai-9.9.9.zip',
				'last_updated' => '2026-08-06 00:00:00',
			),
			$overrides
		);
	}

	/**
	 * Run the update check the way core does, for this plugin.
	 *
	 * @return array|false
	 */
	private function check() {
		return ( new Updater() )->check( false, array(), Updater::basename() );
	}

	/**
	 * The Update URI header is what routes core's check to this plugin.
	 *
	 * Without it core never calls the `update_plugins_github.com` filter at all, and
	 * — just as importantly — WordPress.org is free to answer for a directory plugin
	 * that happens to share this slug.
	 *
	 * @return void
	 */
	public function test_update_uri_header_points_at_the_repository() {
		$headers = get_file_data( WPCAI_PLUGIN_FILE, array( 'update_uri' => 'Update URI' ) );

		$this->assertSame( Updater::REPOSITORY, $headers['update_uri'] );
		$this->assertSame( 'github.com', wp_parse_url( $headers['update_uri'], PHP_URL_HOST ) );
	}

	/**
	 * The updater registers itself even when the plugin's own requirement gates
	 * would have stopped it booting.
	 *
	 * @return void
	 */
	public function test_the_check_is_hooked() {
		$this->assertNotFalse( has_filter( 'update_plugins_github.com' ) );
		$this->assertNotFalse( has_filter( 'plugins_api' ) );
	}

	/**
	 * A newer release is offered, with the built zip as the package.
	 *
	 * @return void
	 */
	public function test_a_newer_release_is_offered() {
		$update = $this->check();

		$this->assertIsArray( $update );
		$this->assertSame( '9.9.9', $update['version'] );
		$this->assertSame( Updater::PACKAGE_PREFIX . 'v9.9.9/woo-product-categorizer-ai-9.9.9.zip', $update['package'] );
		$this->assertSame( 'woo-product-categorizer-ai', $update['slug'] );
		$this->assertSame( '8.2', $update['requires_php'] );
		$this->assertTrue( version_compare( $update['version'], WPCAI_VERSION, '>' ) );
	}

	/**
	 * The installed version being current is still answered.
	 *
	 * This is what makes the auto-update toggle appear: core files an up-to-date
	 * answer under "no_update" in the update_plugins transient, and the plugins
	 * screen decides a plugin supports updates by finding it in "response" or
	 * "no_update". Staying silent here would grey the toggle out on every site that
	 * is already up to date.
	 *
	 * @return void
	 */
	public function test_a_current_version_is_still_answered() {
		$this->body = wp_json_encode( $this->manifest( array( 'version' => WPCAI_VERSION ) ) );

		$update = $this->check();

		$this->assertIsArray( $update );
		$this->assertSame( WPCAI_VERSION, $update['version'] );
		$this->assertFalse( version_compare( $update['version'], WPCAI_VERSION, '>' ) );
	}

	/**
	 * Another plugin released from GitHub is passed through untouched.
	 *
	 * The filter is keyed on the hostname, so it is shared with every other plugin
	 * whose Update URI is a github.com address.
	 *
	 * @return void
	 */
	public function test_another_plugins_check_is_left_alone() {
		$updater = new Updater();

		$this->assertFalse( $updater->check( false, array(), 'someone-else/someone-else.php' ) );
		$this->assertSame( array( 'version' => '1.0' ), $updater->check( array( 'version' => '1.0' ), array(), 'someone-else/someone-else.php' ) );
		$this->assertSame( 0, $this->requests );
	}

	/**
	 * A package hosted anywhere but this repository is discarded.
	 *
	 * The update is still reported — WordPress then says it cannot install it
	 * unattended — rather than unpacking a stranger's zip over the plugin.
	 *
	 * @return void
	 */
	public function test_a_foreign_package_is_dropped() {
		$this->body = wp_json_encode( $this->manifest( array( 'package' => 'https://example.invalid/evil.zip' ) ) );

		$update = $this->check();

		$this->assertIsArray( $update );
		$this->assertSame( '9.9.9', $update['version'] );
		$this->assertSame( '', $update['package'] );
	}

	/**
	 * A details URL outside the repository falls back to the repository.
	 *
	 * @return void
	 */
	public function test_a_foreign_details_url_is_replaced() {
		$this->body = wp_json_encode( $this->manifest( array( 'url' => 'https://example.invalid/' ) ) );

		$update = $this->check();

		$this->assertSame( Updater::REPOSITORY, $update['url'] );
	}

	/**
	 * A version core could not compare is refused outright.
	 *
	 * @param string $version Version string from the manifest.
	 * @return void
	 *
	 * @dataProvider unusable_versions
	 */
	public function test_an_unusable_version_is_refused( $version ) {
		$this->body = wp_json_encode( $this->manifest( array( 'version' => $version ) ) );

		$this->assertFalse( $this->check() );
	}

	/**
	 * Versions that must not be accepted.
	 *
	 * @return array[]
	 */
	public function unusable_versions() {
		return array(
			'empty'    => array( '' ),
			'words'    => array( 'latest' ),
			'markup'   => array( '<script>1.0</script>' ),
			'nonsense' => array( '1.0.0; rm -rf' ),
		);
	}

	/**
	 * A "v" prefix on the version is tolerated.
	 *
	 * The tag carries one and the header does not, and a manifest built by hand from
	 * the tag is the obvious mistake to make.
	 *
	 * @return void
	 */
	public function test_a_tag_style_version_is_normalised() {
		$this->body = wp_json_encode( $this->manifest( array( 'version' => 'v9.9.9' ) ) );

		$this->assertSame( '9.9.9', $this->check()['version'] );
	}

	/**
	 * A successful lookup is fetched once and then served from the cache.
	 *
	 * @return void
	 */
	public function test_a_successful_lookup_is_cached() {
		$this->check();
		$this->check();
		$this->check();

		$this->assertSame( 1, $this->requests );
	}

	/**
	 * An unreachable host is not retried on every request either.
	 *
	 * @return void
	 */
	public function test_a_failed_lookup_is_cached() {
		$this->body = new WP_Error( 'http_request_failed', 'Connection refused' );

		$this->assertFalse( $this->check() );
		$this->assertFalse( $this->check() );
		$this->assertSame( 1, $this->requests );
	}

	/**
	 * "Check again" clears this cache along with core's.
	 *
	 * Core's button deletes the update_plugins transient; without this the plugin
	 * would keep answering from a copy up to six hours old.
	 *
	 * @return void
	 */
	public function test_forcing_a_check_clears_the_cache() {
		$this->check();
		$this->assertSame( 1, $this->requests );

		delete_site_transient( 'update_plugins' );

		$this->check();
		$this->assertSame( 2, $this->requests );
	}

	/**
	 * The details modal is answered for this plugin, with markup stripped from
	 * everything the manifest supplied.
	 *
	 * @return void
	 */
	public function test_the_details_screen_is_answered() {
		$this->body = wp_json_encode( $this->manifest( array( 'name' => 'Woo Kontor <script>alert(1)</script>' ) ) );

		$args   = (object) array( 'slug' => 'woo-product-categorizer-ai' );
		$result = ( new Updater() )->details( false, 'plugin_information', $args );

		$this->assertIsObject( $result );
		$this->assertSame( '9.9.9', $result->version );
		$this->assertStringNotContainsString( '<script>', $result->name );
		$this->assertStringNotContainsString( '<script>', $result->sections['description'] );
		$this->assertArrayHasKey( 'changelog', $result->sections );
	}

	/**
	 * Nothing having checked yet is reported as not knowing.
	 *
	 * Not as "up to date": the settings screen would then tell a site running a
	 * version from last year that it has the newest release.
	 *
	 * @return void
	 */
	public function test_status_is_unknown_before_anything_has_checked() {
		$status = Updater::status();

		$this->assertSame( 'unknown', $status['state'] );
		$this->assertSame( '', $status['version'] );
		$this->assertSame( 0, $this->requests );
	}

	/**
	 * A pending update is read out of core's transient rather than fetched again.
	 *
	 * @return void
	 */
	public function test_status_reports_a_pending_update() {
		$this->seed_core_transient( 'response', '9.9.9' );

		$status = Updater::status();

		$this->assertSame( 'available', $status['state'] );
		$this->assertSame( '9.9.9', $status['version'] );
		$this->assertSame( 0, $this->requests );
	}

	/**
	 * An up-to-date answer is core's "no_update" bucket, and reads as current.
	 *
	 * @return void
	 */
	public function test_status_reports_being_current() {
		$this->seed_core_transient( 'no_update', WPCAI_VERSION );

		$status = Updater::status();

		$this->assertSame( 'current', $status['state'] );
		$this->assertSame( WPCAI_VERSION, $status['version'] );
	}

	/**
	 * Another plugin's entry is not mistaken for this one's.
	 *
	 * @return void
	 */
	public function test_status_ignores_other_plugins() {
		set_site_transient(
			Updater::CORE_CACHE_KEY,
			(object) array(
				'response'  => array( 'woocommerce/woocommerce.php' => (object) array( 'new_version' => '99.0.0' ) ),
				'no_update' => array(),
			)
		);

		$this->assertSame( 'unknown', Updater::status()['state'] );
	}

	/**
	 * Checking on demand discards both caches and asks again.
	 *
	 * This is the whole point of the button: core holds its answer for twelve hours
	 * outside the plugins and updates screens, and this plugin holds the manifest for
	 * six, so a release published in between is invisible until both are cleared.
	 *
	 * @return void
	 */
	public function test_refresh_discards_both_caches_and_asks_again() {
		$updater = new Updater();

		$this->assertSame( 'available', $updater->refresh()['state'] );
		$this->assertSame( 1, $this->requests );

		// Everything is warm now: a second look without refresh() re-reads the answer.
		$this->assertSame( 'available', Updater::status()['state'] );
		$this->assertSame( 1, $this->requests );

		// A newer release turns up on GitHub between the two checks.
		$this->body = wp_json_encode( $this->manifest( array( 'version' => '9.9.10' ) ) );

		$status = $updater->refresh();

		$this->assertSame( 2, $this->requests );
		$this->assertSame( '9.9.10', $status['version'] );
	}

	/**
	 * A check that could not be made says so rather than reporting success.
	 *
	 * @return void
	 */
	public function test_refresh_reports_an_unreachable_host_as_unknown() {
		$this->body = new WP_Error( 'http_request_failed', 'Connection refused' );

		$this->assertSame( 'unknown', ( new Updater() )->refresh()['state'] );
	}

	/**
	 * The settings screen's button has somewhere to post to.
	 *
	 * Settings only registers itself inside the admin, which the suite is not, so the
	 * registration is run here rather than assumed.
	 *
	 * @return void
	 */
	public function test_the_manual_check_is_hooked() {
		( new Settings() )->register();

		$this->assertNotFalse( has_action( 'admin_post_wpcai_check_updates' ) );
	}

	/**
	 * Put an answer in core's transient, as a completed update check would.
	 *
	 * @param string $bucket  Either "response" or "no_update".
	 * @param string $version Version to record against this plugin.
	 * @return void
	 */
	private function seed_core_transient( $bucket, $version ) {
		$updates = array(
			'response'  => array(),
			'no_update' => array(),
		);

		$updates[ $bucket ][ Updater::basename() ] = (object) array(
			'plugin'      => Updater::basename(),
			'slug'        => Updater::SLUG,
			'new_version' => $version,
		);

		set_site_transient( Updater::CORE_CACHE_KEY, (object) $updates );
	}

	/**
	 * Another plugin's details request is passed straight through.
	 *
	 * @return void
	 */
	public function test_another_plugins_details_are_left_alone() {
		$updater = new Updater();

		$this->assertFalse( $updater->details( false, 'plugin_information', (object) array( 'slug' => 'woocommerce' ) ) );
		$this->assertFalse( $updater->details( false, 'query_plugins', (object) array( 'slug' => 'woo-product-categorizer-ai' ) ) );
		$this->assertSame( 0, $this->requests );
	}
}
