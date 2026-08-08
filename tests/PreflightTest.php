<?php
/**
 * The gate every job passes through before it spends anything.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Jobs\Preflight;
use WooProductCategorizerAi\Tests\Doubles\StubProvider;
use WP_Error;
use WP_UnitTestCase;

/**
 * Preflight is the only thing standing between a misconfiguration and a full run,
 * which on this catalogue is around 176 paid requests. Most of what matters here is
 * the *order* of the gates rather than their verdicts: a refusal that arrives after
 * a connection test has already been paid for is a refusal that arrived too late.
 *
 * The stub is therefore usually primed to fail. A test that gets the expected
 * refusal code back has proved the cheap gate ran first, because reaching the
 * provider at all would have returned the stub's error instead.
 */
class PreflightTest extends WP_UnitTestCase {

	/**
	 * The double standing in for a provider.
	 *
	 * @var StubProvider
	 */
	protected $provider;

	/**
	 * Set up the fixture.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->provider = new StubProvider();

		delete_transient( Preflight::CONNECTION_CACHE );
		delete_option( 'woo_product_categorizer_ai_last_apply' );

		$this->empty_the_taxonomy();
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_transient( Preflight::CONNECTION_CACHE );
		delete_option( 'woo_product_categorizer_ai_last_apply' );
		delete_option( Settings::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Reduce product_cat to a single default term.
	 *
	 * WooCommerce's own installer leaves an "Uncategorized" behind, and asserting
	 * against whatever the test site happens to hold would make these tests pass or
	 * fail on the state of the database rather than on the code. The default is
	 * created here and pointed at explicitly instead.
	 *
	 * @return void
	 */
	protected function empty_the_taxonomy() {
		$existing = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( ! is_wp_error( $existing ) ) {
			foreach ( $existing as $term_id ) {
				wp_delete_term( (int) $term_id, 'product_cat' );
			}
		}

		$default = wp_insert_term( 'Uncategorized', 'product_cat', array( 'slug' => 'uncategorized-wpcai' ) );

		update_option( 'default_product_cat', (int) $default['term_id'] );
	}

	/**
	 * Store settings naming the real provider and a key.
	 *
	 * @param string $key API key to store, or an empty string for none.
	 * @return array The stored settings.
	 */
	protected function configure( $key = 'sk-test' ) {
		$settings = wp_parse_args(
			array(
				'provider' => 'openai',
				'api_keys' => '' === $key ? array() : array( 'openai' => $key ),
			),
			Settings::default_settings()
		);

		update_option( Settings::OPTION_KEY, $settings );

		return $settings;
	}

	/**
	 * Prime the stub to refuse, so that reaching it is visible in the result.
	 *
	 * @return void
	 */
	protected function provider_would_refuse() {
		$this->provider->connection = new WP_Error( 'wpcai_reached_the_provider', 'The provider should not have been asked.' );
	}

	/**
	 * The rule that matters most: a run has to remain undoable after the key that
	 * made it has been removed. Gating a revert on credentials would mean the one
	 * operation that costs nothing is the one a cancelled account cannot perform.
	 *
	 * @return void
	 */
	public function test_a_revert_needs_neither_a_key_nor_a_taxonomy() {
		update_option( 'woo_product_categorizer_ai_last_apply', array( 'run' => 12345 ) );

		$this->provider_would_refuse();

		$this->assertTrue( Preflight::check( 'revert', $this->configure( '' ), $this->provider ) );
	}

	/**
	 * A revert is still refused when there is no run behind it.
	 *
	 * @return void
	 */
	public function test_a_revert_with_nothing_to_undo_is_refused() {
		$result = Preflight::check( 'revert', $this->configure(), $this->provider );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_nothing_to_revert', $result->get_error_code() );
	}

	/**
	 * A stored run with no run id is the shape an interrupted apply leaves behind.
	 *
	 * @return void
	 */
	public function test_a_malformed_apply_record_is_not_revertable() {
		update_option( 'woo_product_categorizer_ai_last_apply', array( 'counts' => array( 'assigned' => 4 ) ) );

		$result = Preflight::revertable();

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_nothing_to_revert', $result->get_error_code() );
	}

	/**
	 * The cheapest gate runs first, so an unconfigured plugin costs no round trip.
	 *
	 * @return void
	 */
	public function test_a_missing_key_is_refused_before_the_provider_is_asked() {
		$this->provider_would_refuse();

		$result = Preflight::check( 'taxonomy', $this->configure( '' ), $this->provider );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_not_configured', $result->get_error_code() );
	}

	/**
	 * A key of nothing but whitespace is not a key. Stored as-is it would reach the
	 * provider as a malformed Authorization header and come back as a rejection,
	 * which reads as "your key is wrong" rather than "you have not set one".
	 *
	 * @return void
	 */
	public function test_a_whitespace_key_counts_as_no_key() {
		$settings = $this->configure( "  \t " );

		$result = Preflight::credentials( $settings );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_not_configured', $result->get_error_code() );
	}

	/**
	 * A provider id nothing answers to is a refusal, not a fatal.
	 *
	 * @return void
	 */
	public function test_an_unknown_provider_is_refused() {
		$settings             = $this->configure();
		$settings['provider'] = 'nonesuch';

		$result = Preflight::credentials( $settings );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_unknown_provider', $result->get_error_code() );
	}

	/**
	 * The state the whole propose-and-create flow exists to get out of. A shop with
	 * only WooCommerce's default term has no taxonomy, and an assignment run there
	 * would ask the model to choose from an empty list 176 times over.
	 *
	 * @return void
	 */
	public function test_the_default_category_alone_is_not_a_taxonomy() {
		$result = Preflight::taxonomy();

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_no_taxonomy', $result->get_error_code() );
	}

	/**
	 * Anything beside the default term counts, however small the tree.
	 *
	 * @return void
	 */
	public function test_one_real_term_is_enough_to_be_a_taxonomy() {
		wp_insert_term( 'Wohnen', 'product_cat' );

		$this->assertTrue( Preflight::taxonomy() );
	}

	/**
	 * The taxonomy gate sits ahead of the connection test, not behind it.
	 *
	 * @return void
	 */
	public function test_an_assignment_without_a_taxonomy_never_reaches_the_provider() {
		$this->provider_would_refuse();

		$result = Preflight::check( 'assign', $this->configure(), $this->provider );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_no_taxonomy', $result->get_error_code() );
	}

	/**
	 * A proposal is what a shop with no categories runs first, so it must not be
	 * gated on having some.
	 *
	 * @return void
	 */
	public function test_a_proposal_does_not_need_an_existing_taxonomy() {
		$this->assertTrue( Preflight::check( 'taxonomy', $this->configure(), $this->provider ) );
	}

	/**
	 * The provider's own refusal reaches the screen intact rather than as a generic
	 * "could not connect", because the two need different things done about them.
	 *
	 * @return void
	 */
	public function test_a_rejected_key_is_reported_as_the_provider_put_it() {
		$this->provider->connection = new WP_Error( 'wpcai_unauthorized', 'The API key was rejected.' );

		$result = Preflight::check( 'taxonomy', $this->configure(), $this->provider );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_unauthorized', $result->get_error_code() );
	}

	/**
	 * Only success is cached. Caching a failure would mean a corrected key sat
	 * unusable for the rest of the TTL, with the screen still reporting the old
	 * rejection — the one moment someone is actively trying to fix the thing.
	 *
	 * @return void
	 */
	public function test_a_rejection_is_never_remembered() {
		$this->provider->connection = new WP_Error( 'wpcai_unauthorized', 'The API key was rejected.' );

		Preflight::connection( $this->configure(), $this->provider );

		$this->assertFalse( get_transient( Preflight::CONNECTION_CACHE ) );
	}

	/**
	 * Success is cached for the TTL, so a run does not pay to re-verify each stage.
	 *
	 * @return void
	 */
	public function test_a_verified_connection_is_not_asked_for_twice() {
		$settings = $this->configure();

		$this->assertTrue( Preflight::connection( $settings, $this->provider ) );

		// The same provider, now refusing. A second ask would surface that refusal.
		$this->provider_would_refuse();

		$this->assertTrue( Preflight::connection( $settings, $this->provider ) );
	}

	/**
	 * Hooked to the settings being saved. Without it the screen would keep reporting
	 * a provider as reachable on the strength of a key that has since been replaced.
	 *
	 * @return void
	 */
	public function test_forgetting_the_connection_makes_the_next_check_ask_again() {
		$settings = $this->configure();

		Preflight::connection( $settings, $this->provider );

		Preflight::forget_connection();

		$this->provider_would_refuse();

		$result = Preflight::connection( $settings, $this->provider );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_reached_the_provider', $result->get_error_code() );
	}
}
