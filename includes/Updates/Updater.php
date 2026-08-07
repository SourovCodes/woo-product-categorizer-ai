<?php
/**
 * Plugin updates served from the GitHub releases.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Offers this plugin's GitHub releases to WordPress as ordinary plugin updates.
 *
 * The plugin is not in the WordPress.org directory, so nothing tells WordPress that
 * a newer version exists. This fills that gap through the `Update URI` header and
 * the `update_plugins_{$hostname}` filter core added for exactly this case: core
 * asks us once per update check, we answer with the release metadata, and core does
 * the rest — the update row, the "update now" link, the bulk updater and the
 * automatic updater all work unchanged.
 *
 * Two details are what make the auto-update toggle appear rather than the greyed-out
 * "Auto-updates are not available for this plugin":
 *
 * - **An answer is returned even when the installed version is current.** Core files
 *   an up-to-date answer under `no_update` in the `update_plugins` transient, and
 *   the plugins screen decides a plugin supports updates by looking for it in either
 *   `response` or `no_update`. Answering only when an update exists would hide the
 *   toggle on every site that is already up to date — which is nearly all of them.
 * - **`package` is the built release asset**, so core can install it unattended.
 *   Without a package WordPress shows the new version but says an automatic update
 *   is unavailable.
 *
 * A check that fails — GitHub unreachable, no release carrying a manifest — returns
 * nothing at all, which reads on the plugins screen as "updates not available". That
 * is the honest answer: we could not ask. Any auto-update choice the site made is
 * kept in core's option and takes effect again as soon as a check succeeds.
 */
class Updater {

	/**
	 * The plugin's directory name, and the slug WordPress keys the update on.
	 */
	const SLUG = 'woo-product-categorizer-ai';

	/**
	 * The repository the releases are published from.
	 */
	const REPOSITORY = 'https://github.com/SourovCodes/woo-product-categorizer-ai';

	/**
	 * Where the release metadata is read from.
	 *
	 * A file published beside the zip on every release rather than a call to
	 * api.github.com. The API would work, but it is rate limited to 60 requests an
	 * hour per IP address for anonymous callers — shared hosting puts hundreds of
	 * sites behind one address — and it does not carry the plugin's requirements, so
	 * the version floors that stop an update installing onto a host too old for it
	 * would have to be guessed. This URL is a plain redirect to the newest
	 * non-prerelease asset of that name, under no such limit.
	 */
	const MANIFEST_URL = self::REPOSITORY . '/releases/latest/download/update.json';

	/**
	 * Prefix every release asset URL has to start with.
	 *
	 * The manifest names the file WordPress will download, unpack and run. It arrives
	 * from GitHub over TLS, but a package pointing anywhere else is not something to
	 * take on trust, so one is discarded rather than installed.
	 */
	const PACKAGE_PREFIX = self::REPOSITORY . '/releases/download/';

	/**
	 * Site transient the fetched manifest is cached in.
	 */
	const CACHE_KEY = 'wpcai_update_manifest';

	/**
	 * Site transient core keeps the result of a plugin update check in.
	 *
	 * Named here because both halves of a manual re-check have to be cleared, and
	 * because the answer this plugin reports has to be the one core will act on.
	 */
	const CORE_CACHE_KEY = 'update_plugins';

	/**
	 * How long a successful lookup is reused for, in seconds.
	 *
	 * Core checks for plugin updates twice a day, and "Check again" clears this cache
	 * along with core's own, so a shorter window would only add requests.
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * How long a failed lookup is remembered for, in seconds.
	 *
	 * Short enough that a transient outage clears on its own, long enough that an
	 * unreachable host is not retried on every admin page load.
	 */
	const FAILURE_TTL = HOUR_IN_SECONDS;

	/**
	 * Request timeout in seconds.
	 *
	 * The update check runs inside an admin request, so this is deliberately far
	 * tighter than the AI provider client's.
	 */
	const REQUEST_TIMEOUT = 10;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );

		// "Check again" deletes core's transient. Ours has to go with it, or the button
		// would report whatever the last lookup found for up to six hours.
		add_action( 'delete_site_transient_update_plugins', array( $this, 'flush' ) );
	}

	/**
	 * Answer core's update check for this plugin.
	 *
	 * The filter is keyed on the host in the `Update URI` header, so every plugin
	 * released from GitHub shares it. Anything that is not this plugin is passed
	 * through untouched.
	 *
	 * @param array|false $update      Update offer assembled so far.
	 * @param array       $plugin_data Headers of the plugin being checked.
	 * @param string      $plugin_file Plugin file, relative to the plugins directory.
	 * @return array|false The release metadata, or the incoming value.
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}

		$manifest = $this->manifest();

		if ( null === $manifest ) {
			return $update;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $manifest['version'],
			'url'          => $manifest['url'],
			'package'      => $manifest['package'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
			'tested'       => $manifest['tested'],
		);
	}

	/**
	 * Fill in the "View version details" screen.
	 *
	 * Core links the update row at the plugin information modal, which otherwise asks
	 * WordPress.org about a plugin it has never heard of and shows an error.
	 *
	 * @param object|array|false $result Result the API would return.
	 * @param string             $action Requested API action.
	 * @param object             $args   Request arguments.
	 * @return object|array|false Plugin information, or the incoming value.
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$manifest = $this->manifest();

		if ( null === $manifest ) {
			return $result;
		}

		$information = array(
			'name'          => $manifest['name'],
			'slug'          => self::SLUG,
			'version'       => $manifest['version'],
			'author'        => $manifest['author'],
			'homepage'      => $manifest['url'],
			'requires'      => $manifest['requires'],
			'requires_php'  => $manifest['requires_php'],
			'tested'        => $manifest['tested'],
			'last_updated'  => $manifest['last_updated'],
			'download_link' => $manifest['package'],
			'sections'      => array(
				'description' => wpautop( esc_html( $manifest['description'] ) ),
				'changelog'   => wpautop(
					sprintf(
						/* translators: %s: link to the plugin's releases on GitHub. */
						esc_html__( 'What changed in each version is recorded with the release it shipped in: %s', 'woo-product-categorizer-ai' ),
						sprintf(
							'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
							esc_url( self::REPOSITORY . '/releases' ),
							esc_html__( 'release notes on GitHub', 'woo-product-categorizer-ai' )
						)
					)
				),
			),
		);

		return (object) $information;
	}

	/**
	 * Forget the cached manifest.
	 *
	 * @return void
	 */
	public function flush() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Ask again now, ignoring every cache in the way.
	 *
	 * There are two of them, and clearing one is not enough. Ours holds the manifest
	 * for six hours; core's holds the whole update check for twelve outside the
	 * plugins and updates screens, and wp_update_plugins() returns without asking
	 * anybody while it is still warm. A release published an hour ago is invisible
	 * until both are gone, which is exactly what someone pressing "Check for updates"
	 * is trying to find out.
	 *
	 * The check runs through core rather than reading the manifest directly, so what
	 * comes back is what the plugins screen will act on rather than a second opinion
	 * that could disagree with it.
	 *
	 * @return array Status array, as described on status().
	 */
	public function refresh() {
		$this->flush();
		delete_site_transient( self::CORE_CACHE_KEY );

		wp_update_plugins();

		return self::status();
	}

	/**
	 * What the last update check concluded about this plugin.
	 *
	 * Read out of core's transient, so this costs nothing and never goes near the
	 * network: it reports what has already been found rather than looking again.
	 *
	 * "unknown" covers both "nobody has checked yet" and "the check failed", because
	 * core records neither — a failed check simply leaves the plugin in neither
	 * bucket. They are the same answer to the reader anyway: we do not know.
	 *
	 * @return array {
	 *     @type string $state   One of "available", "current" or "unknown".
	 *     @type string $version Version the check found, empty when unknown.
	 * }
	 */
	public static function status() {
		$updates  = get_site_transient( self::CORE_CACHE_KEY );
		$basename = self::basename();

		if ( isset( $updates->response[ $basename ]->new_version ) ) {
			return array(
				'state'   => 'available',
				'version' => (string) $updates->response[ $basename ]->new_version,
			);
		}

		if ( isset( $updates->no_update[ $basename ]->new_version ) ) {
			return array(
				'state'   => 'current',
				'version' => (string) $updates->no_update[ $basename ]->new_version,
			);
		}

		return array(
			'state'   => 'unknown',
			'version' => '',
		);
	}

	/**
	 * The plugin file as WordPress keys it, for example
	 * "woo-product-categorizer-ai/woo-product-categorizer-ai.php".
	 *
	 * @return string
	 */
	public static function basename() {
		return plugin_basename( WPCAI_PLUGIN_FILE );
	}

	/**
	 * Read the release manifest, going to GitHub only when the cache is cold.
	 *
	 * A failed lookup is cached too, as an empty array, so an unreachable host costs
	 * one request an hour rather than one per admin page load.
	 *
	 * @return array|null Normalised manifest, or null when none could be read.
	 */
	private function manifest() {
		$cached = get_site_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return empty( $cached ) ? null : $cached;
		}

		$manifest = $this->normalise( $this->fetch() );

		set_site_transient(
			self::CACHE_KEY,
			null === $manifest ? array() : $manifest,
			null === $manifest ? self::FAILURE_TTL : self::CACHE_TTL
		);

		return $manifest;
	}

	/**
	 * Fetch the manifest published with the newest release.
	 *
	 * @return array|null Decoded manifest, or null on any failure.
	 */
	private function fetch() {
		/**
		 * Filters where the release manifest is read from.
		 *
		 * @since 0.7.0
		 *
		 * @param string $url Manifest URL.
		 */
		$url = apply_filters( 'woo_product_categorizer_ai_update_manifest_url', self::MANIFEST_URL );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'user-agent' => 'WooProductCategorizerAiPro/' . WPCAI_VERSION . '; ' . home_url( '/' ),
				'headers'    => array( 'accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Reduce a fetched manifest to the fields this plugin will act on.
	 *
	 * Everything here arrives from the network, so nothing is passed through as it
	 * came: the version has to look like a version, the package has to be an asset of
	 * this repository, and the rest is stripped of markup. Note that
	 * sanitize_text_field() is not used on the prose — it eats percent-encoded octets
	 * — and that a missing field is left empty rather than guessed at.
	 *
	 * @param array|null $manifest Decoded manifest.
	 * @return array|null Normalised manifest, or null when it is unusable.
	 */
	private function normalise( $manifest ) {
		if ( ! is_array( $manifest ) ) {
			return null;
		}

		$version = isset( $manifest['version'] ) ? ltrim( trim( (string) $manifest['version'] ), 'vV' ) : '';

		// A version core cannot compare would make every check report an update.
		if ( ! preg_match( '/^\d+(\.\d+)*([.\-+][0-9A-Za-z.\-]+)?$/', $version ) ) {
			return null;
		}

		$package = isset( $manifest['package'] ) ? esc_url_raw( (string) $manifest['package'] ) : '';

		// An asset hosted anywhere but this repository is dropped, not installed. The
		// update is still offered; WordPress then says it cannot install it by itself,
		// which is a great deal better than unpacking a stranger's zip over the plugin.
		if ( ! str_starts_with( $package, self::PACKAGE_PREFIX ) ) {
			$package = '';
		}

		$text = static function ( $key ) use ( $manifest ) {
			return isset( $manifest[ $key ] ) ? wp_strip_all_tags( (string) $manifest[ $key ] ) : '';
		};

		$url = isset( $manifest['url'] ) ? esc_url_raw( (string) $manifest['url'] ) : '';

		return array(
			'version'      => $version,
			'package'      => $package,
			'url'          => str_starts_with( $url, self::REPOSITORY ) ? $url : self::REPOSITORY,
			'name'         => '' !== $text( 'name' ) ? $text( 'name' ) : 'Woo Product Categorizer AI',
			'description'  => $text( 'description' ),
			'author'       => $text( 'author' ),
			'requires'     => $text( 'requires' ),
			'requires_php' => $text( 'requires_php' ),
			'tested'       => $text( 'tested' ),
			'last_updated' => $text( 'last_updated' ),
		);
	}
}
