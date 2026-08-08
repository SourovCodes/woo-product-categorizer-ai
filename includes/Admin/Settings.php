<?php
/**
 * Settings screen.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Admin;

use WooProductCategorizerAi\Categorize\BulkRun;
use WooProductCategorizerAi\Categorize\Revert;
use WooProductCategorizerAi\Jobs\Preflight;
use WooProductCategorizerAi\Jobs\Scheduler;
use WooProductCategorizerAi\Jobs\Status;
use WooProductCategorizerAi\Provider\OpenAiProvider;
use WooProductCategorizerAi\Provider\Providers;
use WooProductCategorizerAi\Updates\Updater;

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

			/*
			 * Live by default. The bulk mode is cheaper and steadier, but it answers in
			 * hours rather than minutes, and a default that leaves someone staring at a
			 * progress bar until tomorrow is the wrong first impression.
			 */
			'execution_mode'    => 'live',

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
	 * How a run gets its answers.
	 *
	 * @return array Mode => label.
	 */
	public static function execution_modes() {
		return array(
			'live' => __( 'Live — results in minutes', 'woo-product-categorizer-ai' ),
			'bulk' => __( 'Bulk — half price, results within 24 hours', 'woo-product-categorizer-ai' ),
		);
	}

	/**
	 * Whether a run should go through the provider's bulk endpoint.
	 *
	 * False when the configured provider has no such endpoint, whatever is stored,
	 * so switching to a provider that cannot do it degrades to a live run rather
	 * than to a job that refuses to start.
	 *
	 * @param array|null $settings Settings to use, or null to read the stored ones.
	 * @return bool
	 */
	public static function uses_bulk_mode( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::get_settings();

		if ( 'bulk' !== ( isset( $settings['execution_mode'] ) ? $settings['execution_mode'] : 'live' ) ) {
			return false;
		}

		return Providers::supports_batch( $settings );
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
	 * @return array Provider id => label.
	 */
	public static function providers() {
		return Providers::all();
	}

	/**
	 * Register the screen's hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpcai_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'wp_ajax_wpcai_fetch_models', array( $this, 'handle_fetch_models' ) );
		add_action( 'wp_ajax_wpcai_job_progress', array( $this, 'handle_job_progress' ) );
		add_action( 'admin_post_wpcai_run_job', array( $this, 'handle_run_job' ) );
		add_action( 'admin_post_wpcai_forget_revert', array( $this, 'handle_forget_revert' ) );
		add_action( 'admin_post_wpcai_check_updates', array( $this, 'handle_check_updates' ) );
		add_action( 'admin_post_wpcai_cancel_batch', array( $this, 'handle_cancel_batch' ) );

		/*
		 * A saved key or a switched provider invalidates whatever the last connection
		 * test concluded. Without this the screen would keep reporting a provider as
		 * reachable on the strength of a key that has since been replaced.
		 */
		add_action( 'update_option_' . self::OPTION_KEY, array( Preflight::class, 'forget_connection' ) );
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
			'execution_mode'    => $this->pick_choice( $input, 'execution_mode', self::execution_modes(), $existing ),
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
	 * Load the screen's own CSS and JS, and nothing anywhere else.
	 *
	 * @param string $hook_suffix The screen being rendered.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wpcai-settings',
			WPCAI_PLUGIN_URL . 'assets/css/settings.css',
			array(),
			WPCAI_VERSION
		);

		wp_enqueue_script(
			'wpcai-settings',
			WPCAI_PLUGIN_URL . 'assets/js/settings.js',
			array(),
			WPCAI_VERSION,
			true
		);

		wp_enqueue_script(
			'wpcai-taxonomy-editor',
			WPCAI_PLUGIN_URL . 'assets/js/taxonomy-editor.js',
			array(),
			WPCAI_VERSION,
			true
		);

		wp_localize_script(
			'wpcai-settings',
			'wpcaiSettings',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),

				/*
				 * A nonce per action rather than one shared across them. They authorise
				 * different things — one spends a request against a submitted key, the
				 * other only reads — and a single nonce would make them interchangeable.
				 */
				'testNonce'        => wp_create_nonce( 'wpcai_test_connection' ),
				'modelsNonce'      => wp_create_nonce( 'wpcai_fetch_models' ),
				'progressNonce'    => wp_create_nonce( 'wpcai_job_progress' ),

				/*
				 * How often a running job is re-read, in milliseconds. One option read
				 * per poll, and only while something is actually running.
				 */
				'progressInterval' => 5000,

				/*
				 * Every string lives here rather than in the JS, so there is no separate
				 * JSON catalogue to build and ship for the script.
				 */
				'testing'          => __( 'Testing the connection…', 'woo-product-categorizer-ai' ),
				'testFailed'       => __( 'The connection test could not be completed.', 'woo-product-categorizer-ai' ),
				'connected'        => __( 'Connected. The key works.', 'woo-product-categorizer-ai' ),
				'fetchingModels'   => __( 'Fetching the models your account can use…', 'woo-product-categorizer-ai' ),
				'modelsFailed'     => __( 'The model list could not be fetched.', 'woo-product-categorizer-ai' ),
				'modelsLoaded'     => __( 'Models loaded. Choose one, then save the settings.', 'woo-product-categorizer-ai' ),
				'recommendedName'  => __( 'Recommended', 'woo-product-categorizer-ai' ),
				'otherName'        => __( 'Other models on your account', 'woo-product-categorizer-ai' ),
				'providerDefault'  => __( '— Use the recommended model —', 'woo-product-categorizer-ai' ),
			)
		);
	}

	/**
	 * Test the provider connection over AJAX.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		check_ajax_referer( 'wpcai_test_connection', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'woo-product-categorizer-ai' ) ), 403 );
		}

		$provider = Providers::get( $this->settings_from_request() );

		if ( is_wp_error( $provider ) ) {
			wp_send_json_error( array( 'message' => $provider->get_error_message() ) );
		}

		$result = $provider->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Connected. The key works.', 'woo-product-categorizer-ai' ) ) );
	}

	/**
	 * Fetch the models the account can use, over AJAX.
	 *
	 * @return void
	 */
	public function handle_fetch_models() {
		check_ajax_referer( 'wpcai_fetch_models', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'woo-product-categorizer-ai' ) ), 403 );
		}

		$provider = Providers::get( $this->settings_from_request() );

		if ( is_wp_error( $provider ) ) {
			wp_send_json_error( array( 'message' => $provider->get_error_message() ) );
		}

		$models = $provider->list_models();

		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ) );
		}

		$curated = $provider::curated_models();
		$labels  = array();

		foreach ( $models['recommended'] as $model_id ) {
			$labels[ $model_id ] = isset( $curated[ $model_id ] ) ? $curated[ $model_id ] : $model_id;
		}

		wp_send_json_success(
			array(
				'recommended' => $labels,
				'other'       => $models['other'],
			)
		);
	}

	/**
	 * Queue a job to run immediately.
	 *
	 * @return void
	 */
	public function handle_run_job() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-product-categorizer-ai' ) );
		}

		$job = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';

		check_admin_referer( 'wpcai_run_job_' . $job );

		$queued = Scheduler::trigger( $job );

		/*
		 * The redirect carries a code, never a message. Putting the text in the URL
		 * would mean the screen echoes back a string that arrived from outside it, and
		 * would make every refusal a translation that happens in the wrong place.
		 */
		$notice = is_wp_error( $queued ) ? $queued->get_error_code() : 'queued';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'wpcai_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Report how the running jobs are getting on, over AJAX.
	 *
	 * @return void
	 */
	public function handle_job_progress() {
		check_ajax_referer( 'wpcai_job_progress', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'woo-product-categorizer-ai' ) ), 403 );
		}

		Scheduler::reap_stranded_runs();

		$jobs = array();

		foreach ( array_keys( Scheduler::get_jobs() ) as $job ) {
			$status = Status::get( $job );

			$jobs[ $job ] = array(
				'state'      => $status['state'],
				'running'    => Status::is_running( $job ),
				'percentage' => Status::percentage( $status ),
				'summary'    => $this->describe_status( $status ),
				'position'   => $this->describe_position( $status ),
			);
		}

		wp_send_json_success( array( 'jobs' => $jobs ) );
	}

	/**
	 * Render the table of jobs, their state and their Run buttons.
	 *
	 * @return void
	 */
	protected function render_jobs_table() {
		/*
		 * Before anything is drawn. A run whose chain died has nothing left to correct
		 * the record, so the screen would otherwise report it as in progress for as
		 * long as the option survives — and disable the button that would start it
		 * again for the first six hours of that.
		 */
		Scheduler::reap_stranded_runs();

		?>
		<h2><?php echo esc_html__( 'Jobs', 'woo-product-categorizer-ai' ); ?></h2>
		<table class="widefat striped wpcai-jobs">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Job', 'woo-product-categorizer-ai' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Last run', 'woo-product-categorizer-ai' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Run', 'woo-product-categorizer-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( Scheduler::get_jobs() as $key => $job ) : ?>
					<?php if ( ! empty( $job['hidden'] ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<?php
					$status  = Status::get( $key );
					$running = Status::is_running( $key );
					$percent = Status::percentage( $status );
					?>
					<tr data-wpcai-job="<?php echo esc_attr( $key ); ?>">
						<td>
							<strong><?php echo esc_html( $job['label'] ); ?></strong>
							<p class="description"><?php echo esc_html( $job['description'] ); ?></p>
						</td>
						<td>
							<p class="wpcai-job-summary"><?php echo esc_html( $this->describe_status( $status ) ); ?></p>
							<?php
							/*
							 * A bar only where there is something to measure against. A run
							 * that cannot report a total gets the position line instead of a
							 * progress element stuck at zero, which reads as broken.
							 */
							?>
							<p class="wpcai-job-position"><?php echo esc_html( $this->describe_position( $status ) ); ?></p>
							<?php $this->render_batch_state( $key ); ?>
							<progress
								class="wpcai-job-progress"
								max="100"
								<?php echo null === $percent ? '' : 'value="' . esc_attr( $percent ) . '"'; ?>
								<?php echo $running ? '' : 'hidden'; ?>
							></progress>
						</td>
						<td>
							<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
								<input type="hidden" name="action" value="wpcai_run_job" />
								<input type="hidden" name="job" value="<?php echo esc_attr( $key ); ?>" />
								<?php wp_nonce_field( 'wpcai_run_job_' . $key ); ?>
								<button type="submit" class="button" <?php disabled( $running ); ?>>
									<?php echo esc_html__( 'Run now', 'woo-product-categorizer-ai' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Offer to undo the last run, when there is one to undo.
	 *
	 * The button is absent rather than disabled when there is nothing to revert, and
	 * it names what it will undo rather than offering an unqualified "undo" — the
	 * difference between a safety net and a second irreversible action.
	 *
	 * @return void
	 */
	protected function render_revert_section() {
		$last = Revert::last_apply();

		if ( empty( $last ) ) {
			return;
		}

		$running = Status::is_running( 'revert' );
		$status  = Status::get( 'revert' );

		?>
		<h2><?php echo esc_html__( 'Undo', 'woo-product-categorizer-ai' ); ?></h2>

		<p class="wpcai-job-summary" data-wpcai-job="revert">
			<?php echo esc_html( 'never' === $status['state'] ? '' : $this->describe_status( $status ) ); ?>
		</p>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wpcai-inline-form">
			<input type="hidden" name="action" value="wpcai_run_job" />
			<input type="hidden" name="job" value="revert" />
			<?php wp_nonce_field( 'wpcai_run_job_revert' ); ?>
			<button
				type="submit"
				class="button"
				<?php disabled( $running ); ?>
				data-wpcai-confirm="<?php echo esc_attr__( 'Put every product this run touched back to the categories it had before? Nothing else changes.', 'woo-product-categorizer-ai' ); ?>"
			>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of products. 2: date the run finished. */
						__( 'Revert the run of %2$s (%1$s products)', 'woo-product-categorizer-ai' ),
						number_format_i18n( (int) $last['products'] ),
						wp_date( get_option( 'date_format' ), (int) $last['finished'] )
					)
				);
				?>
			</button>
		</form>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wpcai-inline-form">
			<input type="hidden" name="action" value="wpcai_forget_revert" />
			<?php wp_nonce_field( 'wpcai_forget_revert' ); ?>
			<button
				type="submit"
				class="button-link"
				data-wpcai-confirm="<?php echo esc_attr__( 'Forget what the categories were before the last run? The categories themselves stay exactly as they are, but the run can no longer be undone.', 'woo-product-categorizer-ai' ); ?>"
			>
				<?php echo esc_html__( 'Forget revert history', 'woo-product-categorizer-ai' ); ?>
			</button>
		</form>

		<p class="description">
			<?php echo esc_html__( 'Each product remembers the categories it had before the last run. Keeping that costs a little database space; forgetting it frees the space and gives up the undo.', 'woo-product-categorizer-ai' ); ?>
		</p>
		<?php
	}

	/**
	 * Drop the stashes without restoring anything.
	 *
	 * @return void
	 */
	public function handle_forget_revert() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-product-categorizer-ai' ) );
		}

		check_admin_referer( 'wpcai_forget_revert' );

		$forgotten = Revert::forget();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'wpcai_notice'  => 'revert_forgotten',
					'wpcai_cleared' => (int) $forgotten,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Ask GitHub for the latest release now, rather than waiting for WordPress.
	 *
	 * @return void
	 */
	public function handle_check_updates() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-product-categorizer-ai' ) );
		}

		check_admin_referer( 'wpcai_check_updates' );

		$status = ( new Updater() )->refresh();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'wpcai_update' => $status['state'],
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Show which version is installed and whether a newer one exists.
	 *
	 * @return void
	 */
	protected function render_updates_section() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$status = Updater::status();
		?>
		<h2><?php echo esc_html__( 'Updates', 'woo-product-categorizer-ai' ); ?></h2>
		<table class="widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Installed version', 'woo-product-categorizer-ai' ); ?></th>
					<td><?php echo esc_html( WPCAI_VERSION ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Latest release', 'woo-product-categorizer-ai' ); ?></th>
					<td>
						<?php if ( 'available' === $status['state'] ) : ?>
							<strong><?php echo esc_html( $status['version'] ); ?></strong>
							&mdash;
							<a href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>">
								<?php echo esc_html__( 'install it from the plugins screen', 'woo-product-categorizer-ai' ); ?>
							</a>
						<?php elseif ( 'current' === $status['state'] ) : ?>
							<?php echo esc_html__( 'This is the newest release.', 'woo-product-categorizer-ai' ); ?>
						<?php else : ?>
							<?php echo esc_html__( 'Not known. Nothing has checked yet, or the last check could not reach GitHub.', 'woo-product-categorizer-ai' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<form class="wpcai-inline-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="wpcai_check_updates" />
			<?php wp_nonce_field( 'wpcai_check_updates' ); ?>
			<button type="submit" class="button"><?php echo esc_html__( 'Check for updates', 'woo-product-categorizer-ai' ); ?></button>
		</form>
		<p class="description">
			<?php echo esc_html__( 'WordPress looks for plugin updates about twice a day and reuses that answer in between, so a release published since the last look does not appear on its own. This discards what was cached and asks again.', 'woo-product-categorizer-ai' ); ?>
		</p>
		<?php
	}

	/**
	 * Report what pressing "Check for updates" found.
	 *
	 * @return void
	 */
	protected function render_update_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only display flag set by our own redirect; the check itself was nonce-checked.
		$state = isset( $_GET['wpcai_update'] ) ? sanitize_key( wp_unslash( $_GET['wpcai_update'] ) ) : '';

		if ( '' === $state ) {
			return;
		}

		if ( 'available' === $state ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: version number of the release that was found. */
						__( 'Version %s is available. Install it from the plugins screen.', 'woo-product-categorizer-ai' ),
						Updater::status()['version']
					)
				)
			);

			return;
		}

		if ( 'current' === $state ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'This is the newest release.', 'woo-product-categorizer-ai' )
			);

			return;
		}

		/*
		 * Anything else is a check that could not be made. WordPress asks its own API
		 * first and abandons the whole check if that fails, so this covers
		 * WordPress.org being unreachable as well as GitHub — and either way the
		 * honest answer is that nobody could be asked, not that the plugin is current.
		 */
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html__( 'The release could not be checked. GitHub or WordPress.org could not be reached; try again in a moment.', 'woo-product-categorizer-ai' )
		);
	}

	/**
	 * Say what a batch waiting at the provider is doing, and offer to stop it.
	 *
	 * A bulk run spends nearly all of its life in one state — waiting — with no
	 * local work to report. Without this the screen would show a job that has been
	 * "running" since yesterday with nothing to say about it.
	 *
	 * @param string $job Job key the row belongs to.
	 * @return void
	 */
	protected function render_batch_state( $job ) {
		if ( 'assign' !== $job ) {
			return;
		}

		$flight = BulkRun::in_flight();

		if ( empty( $flight ) ) {
			return;
		}

		?>
		<p class="description">
			<?php
			echo esc_html(
				$flight['total'] > 0
					? sprintf(
						/* translators: 1: requests finished. 2: requests in total. 3: how long ago the batch was submitted. */
						__( 'Waiting on the provider — %1$s of %2$s requests done, sent %3$s ago. You can close this page; it carries on without you.', 'woo-product-categorizer-ai' ),
						number_format_i18n( (int) $flight['completed'] ),
						number_format_i18n( (int) $flight['total'] ),
						human_time_diff( (int) $flight['submitted'] )
					)
					: sprintf(
						/* translators: %s: how long ago the batch was submitted. */
						__( 'Waiting on the provider to accept the batch, sent %s ago. You can close this page; it carries on without you.', 'woo-product-categorizer-ai' ),
						human_time_diff( (int) $flight['submitted'] )
					)
			);
			?>
		</p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wpcai-inline-form">
			<input type="hidden" name="action" value="wpcai_cancel_batch" />
			<?php wp_nonce_field( 'wpcai_cancel_batch' ); ?>
			<button
				type="submit"
				class="button-link wpcai-danger"
				data-wpcai-confirm="<?php echo esc_attr__( 'Stop this batch? Nothing has been written to your products yet, and nothing will be.', 'woo-product-categorizer-ai' ); ?>"
			>
				<?php echo esc_html__( 'Cancel this batch', 'woo-product-categorizer-ai' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Stop a batch waiting at the provider.
	 *
	 * @return void
	 */
	public function handle_cancel_batch() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-product-categorizer-ai' ) );
		}

		check_admin_referer( 'wpcai_cancel_batch' );

		$cancelled = ( new BulkRun() )->cancel();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'wpcai_notice' => is_wp_error( $cancelled ) ? $cancelled->get_error_code() : 'batch_cancelled',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * A sentence describing how a job last went.
	 *
	 * @param array $status Status array.
	 * @return string
	 */
	protected function describe_status( array $status ) {
		if ( 'never' === $status['state'] ) {
			return __( 'Never run.', 'woo-product-categorizer-ai' );
		}

		/*
		 * The elapsed time is the whole value of this line. A bare "Running now" is
		 * indistinguishable from a run that died an hour ago, and the reaper cannot
		 * help before the timeout — so until then, how long it has been saying this is
		 * the only signal anyone has that something is wrong.
		 */
		if ( 'running' === $status['state'] ) {
			return sprintf(
				/* translators: %s: how long ago the run started, e.g. "3 hours". */
				__( 'Running now, started %s ago.', 'woo-product-categorizer-ai' ),
				human_time_diff( (int) $status['started'] )
			);
		}

		$when = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			(int) $status['finished']
		);

		$prefix = 'success' === $status['state']
			/* translators: %s: date and time the run finished. */
			? sprintf( __( 'Succeeded at %s.', 'woo-product-categorizer-ai' ), $when )
			/* translators: %s: date and time the run failed. */
			: sprintf( __( 'Failed at %s.', 'woo-product-categorizer-ai' ), $when );

		return '' === $status['message'] ? $prefix : $prefix . ' ' . $status['message'];
	}

	/**
	 * A line describing where a running job has got to.
	 *
	 * @param array $status Status array.
	 * @return string
	 */
	protected function describe_position( array $status ) {
		if ( 'running' !== $status['state'] ) {
			return $this->describe_tokens( $status );
		}

		$total = (int) $status['total'];

		if ( $total < 1 ) {
			return __( 'Working…', 'woo-product-categorizer-ai' );
		}

		return sprintf(
			/* translators: 1: records handled so far. 2: records in total. */
			__( '%1$s of %2$s.', 'woo-product-categorizer-ai' ),
			number_format_i18n( (int) $status['processed'] ),
			number_format_i18n( $total )
		);
	}

	/**
	 * What a finished run cost, in tokens.
	 *
	 * Reported in tokens and never in money. Prices move, they differ per model and
	 * per account, and a confidently wrong number about what something cost is worse
	 * than no number at all.
	 *
	 * The cached share is mentioned only when there was one. Prompt caching needs a
	 * shared prefix of at least about a thousand tokens, and a shop with a few dozen
	 * categories does not have one — measured at 557 tokens for a 51-category tree,
	 * which never caches however the run is arranged. Reporting "0% from cache"
	 * there would look like a fault rather than a shop whose prompt is small enough
	 * not to need it.
	 *
	 * @param array $status Status array.
	 * @return string
	 */
	protected function describe_tokens( array $status ) {
		$counts = isset( $status['counts'] ) ? (array) $status['counts'] : array();
		$input  = isset( $counts['input_tokens'] ) ? (int) $counts['input_tokens'] : 0;

		if ( $input < 1 ) {
			return '';
		}

		$output = isset( $counts['output_tokens'] ) ? (int) $counts['output_tokens'] : 0;
		$cached = isset( $counts['cached_tokens'] ) ? (int) $counts['cached_tokens'] : 0;

		$line = sprintf(
			/* translators: 1: input tokens used. 2: output tokens used. */
			__( '%1$s input tokens, %2$s output.', 'woo-product-categorizer-ai' ),
			number_format_i18n( $input ),
			number_format_i18n( $output )
		);

		if ( $cached > 0 ) {
			$line .= ' ' . sprintf(
				/* translators: %d: percentage of input tokens served from the provider's cache. */
				__( '%d%% of the input was served from cache.', 'woo-product-categorizer-ai' ),
				(int) floor( ( $cached / $input ) * 100 )
			);
		}

		return $line;
	}

	/**
	 * Show the outcome of whatever the last Run button did.
	 *
	 * @return void
	 */
	protected function render_queued_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a status code to display, changing nothing.
		$notice = isset( $_GET['wpcai_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpcai_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = $this->notice_messages();

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$good  = in_array( $notice, array( 'queued', 'draft_saved', 'draft_discarded', 'draft_restored', 'terms_created', 'batch_cancelled' ), true );
		$parts = array();

		foreach ( $this->notice_counts() as $argument => $templates ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a count to display, changing nothing.
			$value = isset( $_GET[ $argument ] ) ? absint( wp_unslash( $_GET[ $argument ] ) ) : 0;

			if ( $value > 0 ) {
				$parts[] = sprintf(
					1 === $value ? $templates['one'] : $templates['many'],
					number_format_i18n( $value )
				);
			}
		}

		$message = $messages[ $notice ];

		if ( ! empty( $parts ) ) {
			$message .= ' ' . implode( ', ', $parts ) . '.';
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $good ? 'success' : 'warning' ),
			esc_html( $message )
		);
	}

	/**
	 * Every notice the screen can show, keyed by the code a redirect carries.
	 *
	 * @return array Code => message.
	 */
	protected function notice_messages() {
		return array(
			'queued'                  => __( 'Queued. It will start within a minute, and this page will update as it goes.', 'woo-product-categorizer-ai' ),
			'wpcai_already_running'   => __( 'That job is already running.', 'woo-product-categorizer-ai' ),
			'wpcai_unknown_job'       => __( 'That job does not exist.', 'woo-product-categorizer-ai' ),
			'wpcai_no_scheduler'      => __( 'Action Scheduler is not available, so background jobs cannot run.', 'woo-product-categorizer-ai' ),
			'draft_saved'             => __( 'Draft saved.', 'woo-product-categorizer-ai' ),
			'terms_created'           => __( 'Categories created.', 'woo-product-categorizer-ai' ),
			'draft_discarded'         => __( 'Draft discarded. Nothing in your shop changed.', 'woo-product-categorizer-ai' ),
			'draft_restored'          => __( 'Your edited draft is back.', 'woo-product-categorizer-ai' ),
			'no_draft'                => __( 'There is no draft to edit. Propose a category tree first.', 'woo-product-categorizer-ai' ),
			'no_backup'               => __( 'There is no previous draft to restore.', 'woo-product-categorizer-ai' ),
			'revert_forgotten'        => __( 'Undo history cleared. Your categories are unchanged; the last run can no longer be reverted.', 'woo-product-categorizer-ai' ),
			'wpcai_nothing_to_revert' => __( 'There is no completed run to undo.', 'woo-product-categorizer-ai' ),
			'batch_cancelled'         => __( 'Batch cancelled. Nothing was written to your products.', 'woo-product-categorizer-ai' ),
			'wpcai_nothing_in_flight' => __( 'There is no batch waiting to be cancelled.', 'woo-product-categorizer-ai' ),
			'wpcai_no_batch_support'  => __( 'This provider cannot accept a whole catalogue at once.', 'woo-product-categorizer-ai' ),
			'wpcai_batch_in_flight'   => __( 'A batch for this job is still with the provider. Wait for it, or cancel it first.', 'woo-product-categorizer-ai' ),
		);
	}

	/**
	 * The counts a redirect may carry, and how each reads in a sentence.
	 *
	 * Kept apart from the codes above because these are assembled into one line
	 * rather than chosen between. The numbers come from the query string, so they
	 * are cast to int before they are formatted — the wording is always ours.
	 *
	 * @return array Query argument => singular/plural template.
	 */
	protected function notice_counts() {
		return array(
			'wpcai_renamed'   => array(
				/* translators: %s: number of categories renamed. */
				'one'  => __( '%s renamed', 'woo-product-categorizer-ai' ),
				/* translators: %s: number of categories renamed. */
				'many' => __( '%s renamed', 'woo-product-categorizer-ai' ),
			),
			'wpcai_removed'   => array(
				/* translators: %s: number of categories removed. */
				'one'  => __( '%s removed', 'woo-product-categorizer-ai' ),
				/* translators: %s: number of categories removed. */
				'many' => __( '%s removed', 'woo-product-categorizer-ai' ),
			),
			'wpcai_added'     => array(
				/* translators: %s: number of categories added. */
				'one'  => __( '%s added', 'woo-product-categorizer-ai' ),
				/* translators: %s: number of categories added. */
				'many' => __( '%s added', 'woo-product-categorizer-ai' ),
			),
			'wpcai_rejected'  => array(
				/* translators: %s: number of lines that were too deep. */
				'one'  => __( '%s line was too deep to add', 'woo-product-categorizer-ai' ),
				/* translators: %s: number of lines that were too deep. */
				'many' => __( '%s lines were too deep to add', 'woo-product-categorizer-ai' ),
			),
			'wpcai_created'   => array(
				/* translators: %s: number of categories created. */
				'one'  => __( '%s created', 'woo-product-categorizer-ai' ),
				/* translators: %s: number of categories created. */
				'many' => __( '%s created', 'woo-product-categorizer-ai' ),
			),
			'wpcai_adopted'   => array(
				/* translators: %s: number of existing categories taken over. */
				'one'  => __( '%s already existed and is now managed here', 'woo-product-categorizer-ai' ),
				/* translators: %s: number of existing categories taken over. */
				'many' => __( '%s already existed and are now managed here', 'woo-product-categorizer-ai' ),
			),
			'wpcai_unchanged' => array(
				/* translators: %s: number of categories left untouched. */
				'one'  => __( '%s unchanged', 'woo-product-categorizer-ai' ),
				/* translators: %s: number of categories left untouched. */
				'many' => __( '%s unchanged', 'woo-product-categorizer-ai' ),
			),
			'wpcai_failed'    => array(
				/* translators: %s: number of categories that could not be created. */
				'one'  => __( '%s could not be created', 'woo-product-categorizer-ai' ),
				/* translators: %s: number of categories that could not be created. */
				'many' => __( '%s could not be created', 'woo-product-categorizer-ai' ),
			),
			'wpcai_cleared'   => array(
				/* translators: %s: number of products whose undo history was cleared. */
				'one'  => __( '%s product cleared', 'woo-product-categorizer-ai' ),
				/* translators: %s: number of products whose undo history was cleared. */
				'many' => __( '%s products cleared', 'woo-product-categorizer-ai' ),
			),
		);
	}

	/**
	 * Build the settings a handler should act on.
	 *
	 * Both buttons have to work on a key that has been typed but not yet saved —
	 * otherwise testing a new key means saving it first, and finding out it is wrong
	 * afterwards. A key submitted with the request therefore overrides the stored
	 * one for the duration of that request only; nothing here writes.
	 *
	 * @return array Settings to act on.
	 */
	protected function settings_from_request() {
		$settings = self::get_settings();

		/*
		 * The nonce is verified by the calling handler, which is why the sniff cannot
		 * see it from in here.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$submitted_provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_api_key() is the sanitiser; sanitize_text_field() would corrupt the key.
		$submitted_key = isset( $_POST['api_key'] ) ? self::sanitize_api_key( wp_unslash( $_POST['api_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $submitted_provider && array_key_exists( $submitted_provider, Providers::all() ) ) {
			$settings['provider'] = $submitted_provider;
		}

		if ( '' !== $submitted_key ) {
			$settings['api_keys'][ $settings['provider'] ] = $submitted_key;
		}

		return $settings;
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
		$provider = $settings['provider'];

		$stored_key   = isset( $settings['api_keys'][ $provider ] ) ? (string) $settings['api_keys'][ $provider ] : '';
		$stored_model = isset( $settings['models'][ $provider ] ) ? (string) $settings['models'][ $provider ] : '';

		$classes           = Providers::classes();
		$recommended_model = isset( $classes[ $provider ] )
			? $classes[ $provider ]::recommended_model()
			: OpenAiProvider::recommended_model();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Product Categorizer AI', 'woo-product-categorizer-ai' ); ?></h1>

			<?php $this->render_queued_notice(); ?>
			<?php $this->render_update_notice(); ?>

			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<h2><?php echo esc_html__( 'AI provider', 'woo-product-categorizer-ai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wpcai-provider"><?php echo esc_html__( 'Provider', 'woo-product-categorizer-ai' ); ?></label>
						</th>
						<td>
							<select id="wpcai-provider" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[provider]">
								<?php foreach ( self::providers() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['provider'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Each provider keeps its own key and model, so switching between them does not lose either.', 'woo-product-categorizer-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpcai-api-key"><?php echo esc_html__( 'API key', 'woo-product-categorizer-ai' ); ?></label>
						</th>
						<td>
							<?php
							/*
							 * Rendered empty, always. Echoing a stored secret back into the
							 * page puts it in the DOM, in the browser cache and in any
							 * screenshot of this screen, for no benefit — nobody needs to
							 * read a key they already saved.
							 */
							?>
							<input
								type="password"
								class="regular-text"
								id="wpcai-api-key"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
								value=""
								autocomplete="new-password"
							/>
							<button type="button" class="button" id="wpcai-test-connection">
								<?php echo esc_html__( 'Test connection', 'woo-product-categorizer-ai' ); ?>
							</button>
							<p class="description">
								<?php
								echo esc_html(
									'' === $stored_key
										? __( 'No key stored yet. Sent as a bearer token, and never written to the log.', 'woo-product-categorizer-ai' )
										: __( 'A key is stored. Leave this field blank to keep it.', 'woo-product-categorizer-ai' )
								);
								?>
							</p>
							<p class="description" id="wpcai-connection-result" aria-live="polite"></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpcai-model"><?php echo esc_html__( 'Model', 'woo-product-categorizer-ai' ); ?></label>
						</th>
						<td>
							<select id="wpcai-model" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[model]">
								<option value=""><?php echo esc_html__( '— Use the recommended model —', 'woo-product-categorizer-ai' ); ?></option>
								<?php
								/*
								 * The stored model is rendered as a selected option before
								 * anyone fetches anything, so a saved value survives a reload
								 * without a network call. Fetching only ever adds to this.
								 */
								?>
								<?php if ( '' !== $stored_model ) : ?>
									<option value="<?php echo esc_attr( $stored_model ); ?>" selected>
										<?php echo esc_html( $stored_model ); ?>
									</option>
								<?php endif; ?>
							</select>
							<button type="button" class="button" id="wpcai-fetch-models">
								<?php echo esc_html__( 'Fetch models', 'woo-product-categorizer-ai' ); ?>
							</button>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: the model id used when none is chosen. */
										__( 'Leave this on the default to follow the provider\'s recommendation, currently %s. A mini model is usually right: sorting a product into a fixed list of categories is classification, not reasoning.', 'woo-product-categorizer-ai' ),
										$recommended_model
									)
								);
								?>
							</p>
							<p class="description" id="wpcai-models-result" aria-live="polite"></p>
						</td>
					</tr>
				</table>

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
					<?php if ( Providers::supports_batch( $settings ) ) : ?>
						<tr>
							<th scope="row">
								<label for="wpcai-execution-mode"><?php echo esc_html__( 'How to run it', 'woo-product-categorizer-ai' ); ?></label>
							</th>
							<td>
								<select id="wpcai-execution-mode" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[execution_mode]">
									<?php foreach ( self::execution_modes() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['execution_mode'], $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php echo esc_html__( 'Live sends the products a batch at a time and finishes in minutes. Bulk hands the whole catalogue over in one go: it costs half as much, cannot be rate-limited, and this page keeps you posted while you wait — but the answers may take up to 24 hours. Either way you can leave the page.', 'woo-product-categorizer-ai' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
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

			<?php ( new TaxonomyScreen() )->render(); ?>
			<?php $this->render_jobs_table(); ?>
			<?php $this->render_revert_section(); ?>
			<?php $this->render_updates_section(); ?>
		</div>
		<?php
	}
}
