<?php
/**
 * Settings screen.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's stored settings and the screen that edits them.
 */
class Settings {

	/**
	 * Option holding every setting.
	 */
	const OPTION_KEY = 'woo_product_categorizer_ai_settings';

	/**
	 * Settings group the screen posts under.
	 */
	const OPTION_GROUP = 'woo_product_categorizer_ai_settings_group';

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'woo-product-categorizer-ai';

	/**
	 * Capability required to see or change anything here.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Longest guidance the model is sent.
	 *
	 * Generous enough for a real briefing about how the shop thinks about its
	 * catalogue, short enough that it cannot crowd out the sample it is attached to.
	 */
	const GUIDANCE_MAX = 2000;

	/**
	 * The submenu page's hook suffix, captured so assets load on this screen only.
	 *
	 * @var string
	 */
	protected $hook_suffix = '';

	/**
	 * Read the stored settings, with defaults filled in.
	 *
	 * The single read point for the whole plugin: nothing else calls get_option()
	 * on OPTION_KEY, so the default-merging and the shape guarantees below hold
	 * everywhere.
	 *
	 * @return array Complete settings array.
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::default_settings() );

		/*
		 * wp_parse_args() only fills in absent keys, so a stored value of the wrong
		 * type survives it. Both of these are indexed by provider and are read with
		 * array syntax all over the plugin; a scalar left by a hand-edited option or
		 * a botched import would be a fatal rather than a misconfiguration.
		 */
		if ( ! is_array( $settings['api_keys'] ) ) {
			$settings['api_keys'] = array();
		}

		if ( ! is_array( $settings['models'] ) ) {
			$settings['models'] = array();
		}

		return $settings;
	}

	/**
	 * The settings a fresh install starts with.
	 *
	 * @return array Default settings array.
	 */
	public static function default_settings() {
		return array(
			'provider'          => 'openai',

			/*
			 * Keyed by provider id, not a single scalar. With one shared field,
			 * switching provider would either send an OpenAI key to another vendor or
			 * have to wipe the key — destroying configuration as a side effect of
			 * changing a dropdown. One extra array level makes the switch reversible.
			 */
			'api_keys'          => array(),

			// Same reasoning. An empty string means "whatever the provider recommends".
			'models'            => array(),

			'max_depth'         => 3,
			'guidance'          => '',
			'scope'             => 'publish',
			'override_existing' => true,
			'dry_run'           => false,
			'batch_size'        => 25,
		);
	}

	/**
	 * How deep a proposed category tree may go.
	 *
	 * A cap rather than a target: the model is asked for at most this many levels
	 * and routinely returns fewer when the catalogue does not warrant them.
	 *
	 * @return array Depth => label.
	 */
	public static function depths() {
		return array(
			2 => __( '2 levels — top level and one below', 'woo-product-categorizer-ai' ),
			3 => __( '3 levels (recommended)', 'woo-product-categorizer-ai' ),
			4 => __( '4 levels — only for a very large catalogue', 'woo-product-categorizer-ai' ),
		);
	}

	/**
	 * Which products a run considers.
	 *
	 * @return array Scope => label.
	 */
	public static function scopes() {
		return array(
			'publish'       => __( 'Published products only', 'woo-product-categorizer-ai' ),
			'publish_draft' => __( 'Published and draft products', 'woo-product-categorizer-ai' ),
		);
	}

	/**
	 * How many products are sent to the model in one request.
	 *
	 * @return array Size => label.
	 */
	public static function batch_sizes() {
		return array(
			10 => __( '10 products per request', 'woo-product-categorizer-ai' ),
			25 => __( '25 products per request (recommended)', 'woo-product-categorizer-ai' ),
			50 => __( '50 products per request', 'woo-product-categorizer-ai' ),
		);
	}

	/**
	 * The providers the settings screen offers.
	 *
	 * Stubbed here until the provider registry lands; keeping the list in one place
	 * means the sanitiser already rejects anything that is not a real choice.
	 *
	 * @return array Provider id => label.
	 */
	public static function providers() {
		return array(
			'openai' => __( 'OpenAI', 'woo-product-categorizer-ai' ),
		);
	}

	/**
	 * Register the screen's hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the settings group.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::default_settings(),
			)
		);
	}

	/**
	 * Add the settings page under WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Product Categorizer AI', 'woo-product-categorizer-ai' ),
			__( 'Categorizer AI', 'woo-product-categorizer-ai' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Sanitise the submitted settings.
	 *
	 * Two rules govern every field below, both inherited from the sibling sync
	 * plugin because both prevent the same class of accident:
	 *
	 * - An absent field keeps the stored value. A partial submission — a form
	 *   rendered before a setting existed, a browser that dropped a field — must
	 *   never silently reset something that was deliberately configured.
	 * - The API key is never rendered back into the page, so a blank submission
	 *   means "leave it alone" rather than "clear it".
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array Sanitised settings.
	 */
	public function sanitize( $input ) {
		$existing = self::get_settings();

		if ( ! is_array( $input ) ) {
			return $existing;
		}

		$provider = $this->pick_choice( $input, 'provider', self::providers(), $existing );

		return array(
			'provider'          => $provider,
			'api_keys'          => $this->pick_key( $input, $provider, $existing ),
			'models'            => $this->pick_model( $input, $provider, $existing ),
			'max_depth'         => (int) $this->pick_choice( $input, 'max_depth', self::depths(), $existing ),
			'guidance'          => $this->pick_guidance( $input, $existing ),
			'scope'             => $this->pick_choice( $input, 'scope', self::scopes(), $existing ),
			'override_existing' => $this->pick_toggle( $input, 'override_existing', $existing ),
			'dry_run'           => $this->pick_toggle( $input, 'dry_run', $existing ),
			'batch_size'        => (int) $this->pick_choice( $input, 'batch_size', self::batch_sizes(), $existing ),
		);
	}

	/**
	 * Validate a submitted value against a fixed list of choices.
	 *
	 * The generic form of the sibling's pick_interval(). An absent field keeps the
	 * stored value; a value that is not one of the offered choices is a tampered or
	 * broken submission and keeps the stored value too.
	 *
	 * Choices are always a value => label map, so membership is a key lookup. Numeric
	 * choices arrive from $_POST as strings and from array keys as integers, so the
	 * comparison is done on the stringified keys and the caller casts the result.
	 *
	 * @param array  $input    Raw submitted settings.
	 * @param string $key      Setting name.
	 * @param array  $allowed  Allowed choices, keyed by stored value.
	 * @param array  $existing Currently stored settings.
	 * @return string|int The value to store.
	 */
	protected function pick_choice( array $input, $key, array $allowed, array $existing ) {
		if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
			return $existing[ $key ];
		}

		$value = sanitize_text_field( wp_unslash( (string) $input[ $key ] ) );

		foreach ( array_keys( $allowed ) as $choice ) {
			if ( (string) $choice === $value ) {
				return $choice;
			}
		}

		return $existing[ $key ];
	}

	/**
	 * Read a submitted checkbox.
	 *
	 * An absent field keeps the stored value: a partial submission must never
	 * silently turn a setting off. A browser submits nothing at all for a cleared
	 * checkbox, so the form pairs every one with a hidden field carrying zero — that
	 * is what makes "off" a value that arrives rather than a value inferred from
	 * silence. It matters most for override_existing, which defaults to on: without
	 * the hidden partner it could never be turned off at all.
	 *
	 * @param array  $input    Raw submitted settings.
	 * @param string $key      Setting name.
	 * @param array  $existing Currently stored settings.
	 * @return bool The value to store.
	 */
	protected function pick_toggle( array $input, $key, array $existing ) {
		if ( ! isset( $input[ $key ] ) ) {
			return ! empty( $existing[ $key ] );
		}

		return (bool) absint( $input[ $key ] );
	}

	/**
	 * Merge a submitted API key into the stored per-provider map.
	 *
	 * Only ever touches the slot of the provider the form was rendered for. An
	 * absent field leaves the whole map alone; an empty one keeps that provider's
	 * stored key, which is what lets the screen render the field blank rather than
	 * echoing the secret back into the page.
	 *
	 * @param array  $input    Raw submitted settings.
	 * @param string $provider Provider the submission belongs to.
	 * @param array  $existing Currently stored settings.
	 * @return array The api_keys map to store.
	 */
	protected function pick_key( array $input, $provider, array $existing ) {
		$keys = $existing['api_keys'];

		if ( ! isset( $input['api_key'] ) || ! is_scalar( $input['api_key'] ) ) {
			return $keys;
		}

		$submitted = self::sanitize_api_key( wp_unslash( (string) $input['api_key'] ) );

		if ( '' === $submitted ) {
			return $keys;
		}

		$keys[ $provider ] = $submitted;

		return $keys;
	}

	/**
	 * Clean a submitted API key.
	 *
	 * Deliberately not sanitize_text_field(): that decodes and strips percent-encoded
	 * octets, and a key containing "%5a" would be silently mangled into one that no
	 * longer authenticates — a failure that looks like a rejected credential rather
	 * than a corrupted one.
	 *
	 * What must still be removed is control characters: the key goes straight into
	 * the Authorization request header, where a carriage return or newline would
	 * allow header injection.
	 *
	 * @param string $raw Raw submitted key.
	 * @return string Key with control characters removed.
	 */
	public static function sanitize_api_key( $raw ) {
		$key = wp_check_invalid_utf8( (string) $raw );

		// Strip C0 and C1 control characters, including CR and LF.
		$key = preg_replace( '/[\p{Cc}]/u', '', $key );

		return trim( (string) $key );
	}

	/**
	 * Merge a submitted model id into the stored per-provider map.
	 *
	 * Validated by shape rather than against an allowlist. The models an account can
	 * reach are fetched live and change far faster than a release of this plugin
	 * does, so a curated list would reject legitimate choices. A well-formed but
	 * unusable id fails at the provider with a clear message the run records; a
	 * malformed one is a tampered submission and keeps the stored value.
	 *
	 * An explicitly empty value is a real choice and is stored: it means "use
	 * whatever this provider recommends".
	 *
	 * @param array  $input    Raw submitted settings.
	 * @param string $provider Provider the submission belongs to.
	 * @param array  $existing Currently stored settings.
	 * @return array The models map to store.
	 */
	protected function pick_model( array $input, $provider, array $existing ) {
		$models = $existing['models'];

		if ( ! isset( $input['model'] ) || ! is_scalar( $input['model'] ) ) {
			return $models;
		}

		$submitted = trim( sanitize_text_field( wp_unslash( (string) $input['model'] ) ) );

		if ( '' === $submitted ) {
			$models[ $provider ] = '';

			return $models;
		}

		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $submitted ) ) {
			return $models;
		}

		$models[ $provider ] = $submitted;

		return $models;
	}

	/**
	 * Read the submitted guidance.
	 *
	 * Unlike the API key this is rendered back into its textarea, so an explicitly
	 * empty submission unambiguously means "I deleted it" and clears the stored
	 * value. An absent field still keeps it.
	 *
	 * Truncated with mb_substr() rather than substr() so a cap landing mid-character
	 * cannot leave a broken UTF-8 sequence in something that gets sent to an API.
	 *
	 * @param array $input    Raw submitted settings.
	 * @param array $existing Currently stored settings.
	 * @return string The guidance to store.
	 */
	protected function pick_guidance( array $input, array $existing ) {
		if ( ! isset( $input['guidance'] ) || ! is_scalar( $input['guidance'] ) ) {
			return (string) $existing['guidance'];
		}

		$guidance = sanitize_textarea_field( wp_unslash( (string) $input['guidance'] ) );

		return mb_substr( $guidance, 0, self::GUIDANCE_MAX );
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'woo-product-categorizer-ai' ) );
		}

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Product Categorizer AI', 'woo-product-categorizer-ai' ); ?></h1>

			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<h2><?php echo esc_html__( 'Categorisation', 'woo-product-categorizer-ai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wpcai-max-depth"><?php echo esc_html__( 'Category depth', 'woo-product-categorizer-ai' ); ?></label>
						</th>
						<td>
							<select id="wpcai-max-depth" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_depth]">
								<?php foreach ( self::depths() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['max_depth'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'The deepest the proposed tree may go. It is a limit, not a target — a smaller catalogue will come back shallower.', 'woo-product-categorizer-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpcai-guidance"><?php echo esc_html__( 'Your guidance', 'woo-product-categorizer-ai' ); ?></label>
						</th>
						<td>
							<textarea
								id="wpcai-guidance"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[guidance]"
								rows="6"
								class="large-text"
								maxlength="<?php echo esc_attr( self::GUIDANCE_MAX ); ?>"
							><?php echo esc_textarea( $settings['guidance'] ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'How this shop thinks about its catalogue — for example "organise by age group first, then by material", or "keep seasonal products together". Sent with every request, so it shapes both the proposed tree and how products are sorted into it.', 'woo-product-categorizer-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpcai-scope"><?php echo esc_html__( 'Products to categorise', 'woo-product-categorizer-ai' ); ?></label>
						</th>
						<td>
							<select id="wpcai-scope" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[scope]">
								<?php foreach ( self::scopes() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['scope'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Existing categories', 'woo-product-categorizer-ai' ); ?></th>
						<td>
							<?php
							/*
							 * The hidden partner is what makes a cleared checkbox arrive as a
							 * value. Without it the browser submits nothing and pick_toggle()
							 * keeps the stored value, so a setting that defaults to on could
							 * never be turned off.
							 */
							?>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[override_existing]" value="0" />
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[override_existing]"
									value="1"
									<?php checked( $settings['override_existing'] ); ?>
								/>
								<?php echo esc_html__( 'Replace the categories a product already has', 'woo-product-categorizer-ai' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'On by default. Turn this off to leave already-categorised products alone — they are then skipped without being sent to the model at all.', 'woo-product-categorizer-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Dry run', 'woo-product-categorizer-ai' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[dry_run]" value="0" />
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[dry_run]"
									value="1"
									<?php checked( $settings['dry_run'] ); ?>
								/>
								<?php echo esc_html__( 'Report what would happen without changing anything', 'woo-product-categorizer-ai' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'The run asks the model and counts the results exactly as a real run would, but writes nothing. A dry run leaves nothing to revert.', 'woo-product-categorizer-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpcai-batch-size"><?php echo esc_html__( 'Batch size', 'woo-product-categorizer-ai' ); ?></label>
						</th>
						<td>
							<select id="wpcai-batch-size" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[batch_size]">
								<?php foreach ( self::batch_sizes() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['batch_size'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Larger batches make fewer requests but risk the model running out of room to answer, which costs the whole batch. 25 is a good balance.', 'woo-product-categorizer-ai' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
