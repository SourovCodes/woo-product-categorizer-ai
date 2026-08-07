<?php
/**
 * Provider registry.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Provider;

use WooProductCategorizerAi\Admin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that knows which providers exist.
 *
 * Adding a second backend is a new class implementing ProviderInterface plus one
 * line in classes(). Nothing in Taxonomy\ or Categorize\ changes, and the settings
 * already store its key and model in their own slots.
 */
class Providers {

	/**
	 * Every provider the plugin ships, keyed by id.
	 *
	 * @return array Provider id => class name.
	 */
	public static function classes() {
		/**
		 * Filters the available providers.
		 *
		 * Each class must implement ProviderInterface. A class that does not is
		 * dropped rather than fatalling the settings screen.
		 *
		 * @since 0.1.0
		 *
		 * @param array $classes Provider id => class name.
		 */
		$classes = (array) apply_filters(
			'woo_product_categorizer_ai_providers',
			array(
				OpenAiProvider::id() => OpenAiProvider::class,
			)
		);

		return array_filter(
			$classes,
			static function ( $class_name ) {
				return is_string( $class_name ) && is_subclass_of( $class_name, ProviderInterface::class );
			}
		);
	}

	/**
	 * The providers offered in the settings dropdown.
	 *
	 * @return array Provider id => label.
	 */
	public static function all() {
		$labels = array();

		foreach ( self::classes() as $id => $class_name ) {
			$labels[ $id ] = $class_name::label();
		}

		return $labels;
	}

	/**
	 * The provider a fresh install starts on.
	 *
	 * @return string
	 */
	public static function default_id() {
		return OpenAiProvider::id();
	}

	/**
	 * Build the configured provider.
	 *
	 * @param array|null $settings Settings to use, or null to read the stored ones.
	 * @return ProviderInterface|WP_Error The provider, or an error when none is configured.
	 */
	public static function get( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : Settings::get_settings();
		$classes  = self::classes();
		$id       = isset( $settings['provider'] ) ? (string) $settings['provider'] : '';

		if ( ! isset( $classes[ $id ] ) ) {
			return new WP_Error(
				'wpcai_unknown_provider',
				__( 'No AI provider has been configured.', 'woo-product-categorizer-ai' ),
				array( 'disposition' => 'fail' )
			);
		}

		return new $classes[ $id ]( $settings );
	}

	/**
	 * The model the configured provider should be asked with.
	 *
	 * An empty stored value is a legitimate choice meaning "whatever this provider
	 * recommends", so it resolves here rather than being treated as unconfigured.
	 *
	 * @param array|null $settings Settings to use, or null to read the stored ones.
	 * @return string Model id, or an empty string when the provider is unknown.
	 */
	public static function model( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : Settings::get_settings();
		$classes  = self::classes();
		$id       = isset( $settings['provider'] ) ? (string) $settings['provider'] : '';

		if ( ! isset( $classes[ $id ] ) ) {
			return '';
		}

		$stored = isset( $settings['models'][ $id ] ) ? trim( (string) $settings['models'][ $id ] ) : '';

		return '' === $stored ? $classes[ $id ]::recommended_model() : $stored;
	}
}
