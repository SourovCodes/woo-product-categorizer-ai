<?php
/**
 * Preconditions every job has to satisfy before it runs.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Jobs;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Provider\Providers;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a job is allowed to start.
 *
 * A job that runs unconfigured does not fail cleanly. An assignment run with no
 * taxonomy would walk 4,386 products asking a model to choose from an empty list,
 * charging for every request to be told "none of these fit". Checking first turns
 * all of that into one clear refusal on the settings screen.
 *
 * The gates run cheapest first, so a misconfiguration is reported without spending
 * a request to discover it.
 */
class Preflight {

	/**
	 * Transient recording that the credentials were last seen working.
	 */
	const CONNECTION_CACHE = 'wpcai_connection_verified';

	/**
	 * How long a verified connection is trusted for.
	 *
	 * Only success is cached. A failure is never remembered, so correcting a
	 * rejected key takes effect on the very next attempt rather than after a wait.
	 */
	const CONNECTION_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Check every precondition for a job.
	 *
	 * @param string     $job      Job key: taxonomy, assign or revert.
	 * @param array|null $settings Optional settings override, mainly for tests.
	 * @param mixed      $provider Optional provider override, mainly for tests.
	 * @return true|WP_Error True when the job may run.
	 */
	public static function check( $job, $settings = null, $provider = null ) {
		$settings = is_array( $settings ) ? $settings : Settings::get_settings();

		/*
		 * A revert reads nothing but its own ledger, so it needs neither a provider
		 * nor a taxonomy — and gating it on either would mean a run could not be
		 * undone after the key that made it was removed.
		 */
		if ( 'revert' === $job ) {
			return self::revertable();
		}

		$configured = self::credentials( $settings );

		if ( is_wp_error( $configured ) ) {
			return $configured;
		}

		if ( 'assign' === $job ) {
			$taxonomy = self::taxonomy();

			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}
		}

		return self::connection( $settings, $provider );
	}

	/**
	 * Whether a provider and a key have both been configured.
	 *
	 * @param array $settings Plugin settings.
	 * @return true|WP_Error True when the plugin has something to ask.
	 */
	public static function credentials( array $settings ) {
		$provider = Providers::get( $settings );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$id  = isset( $settings['provider'] ) ? (string) $settings['provider'] : '';
		$key = isset( $settings['api_keys'][ $id ] ) ? trim( (string) $settings['api_keys'][ $id ] ) : '';

		if ( '' === $key ) {
			return new WP_Error(
				'wpcai_not_configured',
				__( 'No API key has been saved. Add one on the settings screen before running a job.', 'woo-product-categorizer-ai' )
			);
		}

		return true;
	}

	/**
	 * Whether there is a real category tree to assign products into.
	 *
	 * The default "Uncategorized" term does not count. A shop that has only that has
	 * no taxonomy as far as this plugin is concerned, which is exactly the state the
	 * whole propose-and-create flow exists to get out of.
	 *
	 * @return true|WP_Error True when at least one real term exists.
	 */
	public static function taxonomy() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'exclude'    => array( self::default_category_id() ),
				'number'     => 1,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return new WP_Error(
				'wpcai_no_taxonomy',
				__( 'There are no product categories to sort products into yet. Propose a category tree and create it first.', 'woo-product-categorizer-ai' )
			);
		}

		return true;
	}

	/**
	 * Whether there is a run to undo.
	 *
	 * @return true|WP_Error True when the last run left something revertable.
	 */
	public static function revertable() {
		$last = get_option( 'woo_product_categorizer_ai_last_apply', array() );

		if ( ! is_array( $last ) || empty( $last['run'] ) ) {
			return new WP_Error(
				'wpcai_nothing_to_revert',
				__( 'There is no completed run to undo.', 'woo-product-categorizer-ai' )
			);
		}

		return true;
	}

	/**
	 * Whether the stored credentials actually authenticate.
	 *
	 * @param array $settings Plugin settings.
	 * @param mixed $provider Optional provider override, mainly for tests.
	 * @return true|WP_Error True when the provider answered.
	 */
	public static function connection( array $settings, $provider = null ) {
		if ( get_transient( self::CONNECTION_CACHE ) ) {
			return true;
		}

		if ( null === $provider ) {
			$provider = Providers::get( $settings );
		}

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$result = $provider->test_connection();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		set_transient( self::CONNECTION_CACHE, 1, self::CONNECTION_TTL );

		return true;
	}

	/**
	 * The term id WooCommerce files uncategorised products under.
	 *
	 * @return int Term id, or 0 when the option is unset.
	 */
	public static function default_category_id() {
		return (int) get_option( 'default_product_cat', 0 );
	}

	/**
	 * Forget that the connection was verified.
	 *
	 * Hooked to the settings being saved: a new key, or a switched provider, makes
	 * the cached verdict a statement about credentials that are no longer in use.
	 *
	 * @return void
	 */
	public static function forget_connection() {
		delete_transient( self::CONNECTION_CACHE );
	}
}
