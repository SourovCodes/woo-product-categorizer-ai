<?php
/**
 * Settings option shape and sanitisation.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Admin\Settings;
use WP_UnitTestCase;

/**
 * Covers the two rules the whole option depends on: an absent field keeps the
 * stored value, and a blank key submission keeps the stored key.
 */
class SettingsTest extends WP_UnitTestCase {

	/**
	 * The object under test.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Set up the fixture.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->settings = new Settings();

		delete_option( Settings::OPTION_KEY );
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Run the sanitiser the way register_setting() would.
	 *
	 * @param array $input Raw submitted settings.
	 * @return array Sanitised settings.
	 */
	protected function sanitize( array $input ) {
		return $this->settings->sanitize( $input );
	}

	/**
	 * An unset option reads back as the defaults.
	 *
	 * @return void
	 */
	public function test_defaults_are_returned_when_nothing_is_stored() {
		$settings = Settings::get_settings();

		$this->assertSame( 'openai', $settings['provider'] );
		$this->assertSame( 3, $settings['max_depth'] );
		$this->assertSame( 'publish', $settings['scope'] );
		$this->assertSame( 25, $settings['batch_size'] );
		$this->assertSame( '', $settings['guidance'] );
		$this->assertSame( array(), $settings['api_keys'] );
	}

	/**
	 * Overriding existing categories is on out of the box, because that is what a
	 * shop with an uncategorised catalogue wants.
	 *
	 * @return void
	 */
	public function test_override_existing_defaults_to_on_and_dry_run_to_off() {
		$settings = Settings::get_settings();

		$this->assertTrue( $settings['override_existing'] );
		$this->assertFalse( $settings['dry_run'] );
	}

	/**
	 * A partially stored option is completed from the defaults rather than read
	 * back with missing keys.
	 *
	 * @return void
	 */
	public function test_a_partial_option_merges_over_the_defaults() {
		update_option( Settings::OPTION_KEY, array( 'max_depth' => 4 ), false );

		$settings = Settings::get_settings();

		$this->assertSame( 4, $settings['max_depth'] );
		$this->assertSame( 25, $settings['batch_size'] );
	}

	/**
	 * A stored value of the wrong type must not reach the array reads elsewhere in
	 * the plugin, or a hand-edited option becomes a fatal.
	 *
	 * @return void
	 */
	public function test_scalar_maps_are_coerced_back_to_arrays() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'api_keys' => 'not-an-array',
				'models'   => 42,
			),
			false
		);

		$settings = Settings::get_settings();

		$this->assertSame( array(), $settings['api_keys'] );
		$this->assertSame( array(), $settings['models'] );
	}

	/**
	 * A field the form did not submit keeps whatever was stored.
	 *
	 * @return void
	 */
	public function test_an_absent_field_keeps_the_stored_value() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'max_depth'  => 4,
				'scope'      => 'publish_draft',
				'batch_size' => 50,
			),
			false
		);

		$sanitised = $this->sanitize( array( 'provider' => 'openai' ) );

		$this->assertSame( 4, $sanitised['max_depth'] );
		$this->assertSame( 'publish_draft', $sanitised['scope'] );
		$this->assertSame( 50, $sanitised['batch_size'] );
	}

	/**
	 * A value outside the offered choices is a tampered or broken submission.
	 *
	 * @return void
	 */
	public function test_a_choice_outside_the_allowed_list_keeps_the_stored_value() {
		update_option( Settings::OPTION_KEY, array( 'max_depth' => 3 ), false );

		$this->assertSame( 3, $this->sanitize( array( 'max_depth' => 1 ) )['max_depth'] );
		$this->assertSame( 3, $this->sanitize( array( 'max_depth' => 9 ) )['max_depth'] );
		$this->assertSame( 3, $this->sanitize( array( 'scope' => 'trash' ) )['max_depth'] );
	}

	/**
	 * Depths arrive from $_POST as strings and must come back as integers, since
	 * every consumer compares them numerically.
	 *
	 * @return void
	 */
	public function test_numeric_choices_are_stored_as_integers() {
		$sanitised = $this->sanitize(
			array(
				'max_depth'  => '4',
				'batch_size' => '50',
			)
		);

		$this->assertSame( 4, $sanitised['max_depth'] );
		$this->assertSame( 50, $sanitised['batch_size'] );
	}

	/**
	 * The screen never renders the key back, so a blank field means "unchanged".
	 *
	 * @return void
	 */
	public function test_a_blank_key_submission_keeps_the_stored_key() {
		update_option(
			Settings::OPTION_KEY,
			array( 'api_keys' => array( 'openai' => 'sk-stored-value' ) ),
			false
		);

		$sanitised = $this->sanitize(
			array(
				'provider' => 'openai',
				'api_key'  => '',
			)
		);

		$this->assertSame( 'sk-stored-value', $sanitised['api_keys']['openai'] );
	}

	/**
	 * Saving one provider's key must not disturb another's, which is the whole
	 * reason the keys are a map.
	 *
	 * @return void
	 */
	public function test_saving_a_key_leaves_other_providers_alone() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'api_keys' => array(
					'openai' => 'sk-openai',
					'other'  => 'k-other',
				),
			),
			false
		);

		$sanitised = $this->sanitize(
			array(
				'provider' => 'openai',
				'api_key'  => 'sk-replacement',
			)
		);

		$this->assertSame( 'sk-replacement', $sanitised['api_keys']['openai'] );
		$this->assertSame( 'k-other', $sanitised['api_keys']['other'] );
	}

	/**
	 * Percent octets must survive. sanitize_text_field() would eat one and mangle a
	 * real key into one that no longer authenticates. Control characters must still
	 * go, because the key is written into a request header.
	 *
	 * The fixture is synthetic: it reproduces the shape of a key without being one.
	 *
	 * @return void
	 */
	public function test_key_sanitisation_keeps_punctuation_and_strips_control_characters() {
		$this->assertSame(
			'sk-Aa%5aß_9.-:x',
			Settings::sanitize_api_key( 'sk-Aa%5aß_9.-:x' )
		);

		$this->assertSame(
			'sk-abcdef',
			Settings::sanitize_api_key( "sk-abc\r\ndef" )
		);
	}

	/**
	 * An empty model is a real choice: it means "whatever this provider
	 * recommends", and must be storable.
	 *
	 * @return void
	 */
	public function test_an_empty_model_is_stored_as_the_provider_default() {
		update_option(
			Settings::OPTION_KEY,
			array( 'models' => array( 'openai' => 'gpt-5.4-mini' ) ),
			false
		);

		$sanitised = $this->sanitize(
			array(
				'provider' => 'openai',
				'model'    => '',
			)
		);

		$this->assertSame( '', $sanitised['models']['openai'] );
	}

	/**
	 * Model ids are validated by shape, not against a curated list, because the
	 * account's catalogue changes faster than this plugin releases.
	 *
	 * @return void
	 */
	public function test_a_wellformed_model_is_accepted_and_a_malformed_one_is_not() {
		update_option(
			Settings::OPTION_KEY,
			array( 'models' => array( 'openai' => 'gpt-5.4-mini' ) ),
			false
		);

		$accepted = $this->sanitize(
			array(
				'provider' => 'openai',
				'model'    => 'some-unreleased-model-2027',
			)
		);
		$this->assertSame( 'some-unreleased-model-2027', $accepted['models']['openai'] );

		$rejected = $this->sanitize(
			array(
				'provider' => 'openai',
				'model'    => 'has spaces and <tags>',
			)
		);
		$this->assertSame( 'gpt-5.4-mini', $rejected['models']['openai'] );
	}

	/**
	 * Guidance is rendered back into its textarea, so a blank submission is an
	 * unambiguous delete rather than an accident.
	 *
	 * @return void
	 */
	public function test_guidance_is_cleared_by_an_explicit_blank_but_kept_when_absent() {
		update_option( Settings::OPTION_KEY, array( 'guidance' => 'Sort by age group.' ), false );

		$this->assertSame( '', $this->sanitize( array( 'guidance' => '' ) )['guidance'] );
		$this->assertSame( 'Sort by age group.', $this->sanitize( array() )['guidance'] );
	}

	/**
	 * The cap is applied with a multibyte-aware truncation, so it can never leave a
	 * broken UTF-8 sequence in something bound for an API.
	 *
	 * @return void
	 */
	public function test_guidance_is_capped_without_breaking_a_character() {
		$guidance = str_repeat( 'ä', Settings::GUIDANCE_MAX + 50 );

		$stored = $this->sanitize( array( 'guidance' => $guidance ) )['guidance'];

		$this->assertSame( Settings::GUIDANCE_MAX, mb_strlen( $stored ) );
		$this->assertSame( $stored, wp_check_invalid_utf8( $stored ) );
	}

	/**
	 * The hidden partner field is what makes turning a defaulted-on setting off
	 * possible at all.
	 *
	 * @return void
	 */
	public function test_a_cleared_checkbox_arriving_as_zero_turns_the_setting_off() {
		update_option( Settings::OPTION_KEY, array( 'override_existing' => true ), false );

		$this->assertFalse( $this->sanitize( array( 'override_existing' => '0' ) )['override_existing'] );
		$this->assertTrue( $this->sanitize( array( 'override_existing' => '1' ) )['override_existing'] );

		// Absent, on the other hand, must not silently turn it off.
		$this->assertTrue( $this->sanitize( array() )['override_existing'] );
	}

	/**
	 * A non-array submission cannot be trusted to have any shape at all.
	 *
	 * @return void
	 */
	public function test_a_nonarray_submission_keeps_everything() {
		update_option( Settings::OPTION_KEY, array( 'max_depth' => 4 ), false );

		$this->assertSame( 4, $this->settings->sanitize( 'nonsense' )['max_depth'] );
	}
}
