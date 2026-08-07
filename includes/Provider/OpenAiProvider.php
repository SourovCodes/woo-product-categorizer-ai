<?php
/**
 * OpenAI backend.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Provider;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Jobs\Scheduler;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to OpenAI's Responses API.
 *
 * The only class in the plugin that knows OpenAI's field names, response shape or
 * endpoint paths. Everything above it speaks the neutral vocabulary described on
 * ProviderInterface.
 */
class OpenAiProvider implements ProviderInterface {

	/**
	 * API root. No trailing slash.
	 */
	const API_BASE = 'https://api.openai.com/v1';

	/**
	 * How many times one request is attempted before giving up.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Base for the exponential backoff between attempts, in seconds.
	 */
	const BACKOFF_BASE_SECONDS = 2;

	/**
	 * How long to wait for an answer.
	 *
	 * Generous because it has to be: a tree proposal at medium reasoning effort was
	 * measured at 65 seconds against the real catalogue. The default of 5 would turn
	 * every proposal into a transport error.
	 */
	const REQUEST_TIMEOUT = 120;

	/**
	 * Longest a server-supplied Retry-After is honoured for, in seconds.
	 *
	 * The header is advisory and occasionally enormous. Sleeping for what it asks
	 * would hold an Action Scheduler action open past its own timeout, which fails
	 * the batch for a reason that has nothing to do with the request.
	 */
	const MAX_RETRY_AFTER = 60;

	/**
	 * How much bigger the output cap gets after a truncated answer.
	 */
	const GROWTH_FACTOR = 1.5;

	/**
	 * The most the cap may grow to, as a multiple of what the caller asked for.
	 */
	const MAX_GROWTH = 4;

	/**
	 * HTTP statuses worth trying again.
	 *
	 * @var array
	 */
	protected static $retryable_statuses = array( 408, 409, 429, 500, 502, 503, 504 );

	/**
	 * Model id fragments that never answer a text prompt.
	 *
	 * GET /v1/models returns the account's entire catalogue — speech, images,
	 * embeddings, moderation, realtime sessions — and offering those in a model
	 * picker for a categorisation job is offering a guaranteed failure.
	 *
	 * @var array
	 */
	protected static $excluded_fragments = array(
		'audio',
		'realtime',
		'image',
		'dall-e',
		'tts',
		'whisper',
		'transcribe',
		'embedding',
		'moderation',
		'search',
		'codex',
		'computer-use',
		'sora',
	);

	/**
	 * The settings this instance reads its credentials from.
	 *
	 * Injected rather than read on demand so a run can freeze its configuration and
	 * so the tests can supply their own.
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * Construct the provider.
	 *
	 * @param array|null $settings Settings to use, or null to read the stored ones.
	 */
	public function __construct( $settings = null ) {
		$this->settings = is_array( $settings ) ? $settings : Settings::get_settings();
	}

	/**
	 * The stable identifier this provider is stored under.
	 *
	 * @return string
	 */
	public static function id() {
		return 'openai';
	}

	/**
	 * The provider's name, as shown in the settings dropdown.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'OpenAI', 'woo-product-categorizer-ai' );
	}

	/**
	 * The model to use when none has been chosen.
	 *
	 * A mini model rather than the flagship. Sorting a product into a category from
	 * a fixed list is a classification task, not a reasoning one, and the difference
	 * in judgement between the tiers did not show up in testing while the difference
	 * in price across 4,400 products very much would.
	 *
	 * @return string
	 */
	public static function recommended_model() {
		return 'gpt-5.4-mini';
	}

	/**
	 * The handful of models worth putting in front of someone choosing.
	 *
	 * @return array Model id => label.
	 */
	public static function curated_models() {
		return array(
			'gpt-5.4-mini' => __( 'GPT-5.4 mini — the balance of accuracy and cost (recommended)', 'woo-product-categorizer-ai' ),
			'gpt-5.4-nano' => __( 'GPT-5.4 nano — cheapest and fastest, for a simple catalogue', 'woo-product-categorizer-ai' ),
			'gpt-5.4'      => __( 'GPT-5.4 — for a catalogue the mini model struggles with', 'woo-product-categorizer-ai' ),
			'gpt-5.5'      => __( 'GPT-5.5 — the most capable, and the most expensive', 'woo-product-categorizer-ai' ),
		);
	}

	/**
	 * List the models this account can actually use.
	 *
	 * Split into the curated shortlist the account really has and everything else
	 * that could plausibly answer, so the picker can show two groups rather than one
	 * list of a hundred ids.
	 *
	 * @return array|WP_Error Recommended and other model ids, or an error.
	 */
	public function list_models() {
		$key = $this->get_api_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$response = wp_remote_get(
			self::API_BASE . '/models',
			array(
				'timeout' => 30,
				'headers' => $this->build_headers( $key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wpcai_transport_error',
				$response->get_error_message(),
				array( 'disposition' => 'retry' )
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) ) {
			return $this->error_from_status( $status, $decoded );
		}

		$rows      = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		$usable    = array();
		$available = array();

		foreach ( $rows as $row ) {
			$model_id = isset( $row['id'] ) ? (string) $row['id'] : '';

			if ( '' === $model_id ) {
				continue;
			}

			$available[ $model_id ] = true;

			if ( self::is_text_model( $model_id ) ) {
				$usable[] = $model_id;
			}
		}

		sort( $usable );

		// Only recommend what the account can actually reach.
		$recommended = array_values( array_intersect( array_keys( self::curated_models() ), array_keys( $available ) ) );

		return array(
			'recommended' => $recommended,
			'other'       => array_values( array_diff( $usable, $recommended ) ),
		);
	}

	/**
	 * Whether a model id looks like something that can answer a text prompt.
	 *
	 * Matched on the id because that is all the endpoint returns — there is no
	 * capability flag to read. Erring towards excluding is deliberate: a model
	 * wrongly left out of the list can still be typed in, while one wrongly left in
	 * produces a failed run.
	 *
	 * @param string $model_id Model id from the API.
	 * @return bool True when the model belongs in the picker.
	 */
	protected static function is_text_model( $model_id ) {
		if ( ! preg_match( '/^(gpt-|o\d)/', $model_id ) ) {
			return false;
		}

		foreach ( self::$excluded_fragments as $fragment ) {
			if ( false !== strpos( $model_id, $fragment ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check that the stored credentials work.
	 *
	 * Asks for the model list rather than sending a completion: it is the cheapest
	 * request that still proves the key is accepted, and it costs nothing.
	 *
	 * @return true|WP_Error True when the provider answered, or an error.
	 */
	public function test_connection() {
		$models = $this->list_models();

		return is_wp_error( $models ) ? $models : true;
	}

	/**
	 * Ask for one structured answer.
	 *
	 * @param array $request Neutral request, as described on ProviderInterface.
	 * @return array|WP_Error Payload and usage, or an error carrying a disposition.
	 */
	public function complete( array $request ) {
		$key = $this->get_api_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$model = Providers::model( $this->settings );

		if ( '' === $model ) {
			$model = self::recommended_model();
		}

		$requested_cap = max( 256, (int) ( isset( $request['max_tokens'] ) ? $request['max_tokens'] : 4000 ) );
		$cap           = $requested_cap;
		$ceiling       = $requested_cap * self::MAX_GROWTH;
		$label         = isset( $request['schema_name'] ) ? (string) $request['schema_name'] : 'completion';
		$last_error    = null;

		/*
		 * The retry loop is adapted from the sibling's Api\Client. It stays here rather
		 * than in an abstract base class: two providers is the point at which the
		 * shared shape is actually known, and hoisting it on the strength of one is
		 * guessing. This is the method to extract when a second backend lands.
		 */
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$response = wp_remote_post(
				self::API_BASE . '/responses',
				array(
					'timeout' => self::REQUEST_TIMEOUT,
					'headers' => $this->build_headers( $key ),
					'body'    => wp_json_encode( $this->build_body( $request, $model, $cap ) ),
				)
			);

			$result = $this->interpret_response( $response, $label, $attempt );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_error = $result;

			if ( 'retry' !== self::detail( $result, 'disposition', 'fail' ) || self::MAX_ATTEMPTS === $attempt ) {
				break;
			}

			/*
			 * The one case where a retry legitimately changes the request. Re-sending an
			 * identical prompt that ran out of room to answer is guaranteed to run out
			 * of room again, having charged for every reasoning token on the way. Grow
			 * the ceiling instead, bounded so a pathological answer cannot spend without
			 * limit.
			 */
			if ( 'wpcai_incomplete_response' === $result->get_error_code() ) {
				$cap = min( $ceiling, (int) ceil( $cap * self::GROWTH_FACTOR ) );
			}

			$this->wait_before_retry( $result, $attempt );
		}

		return $last_error;
	}

	/**
	 * Build the Responses API request body.
	 *
	 * @param array  $request Neutral request.
	 * @param string $model   Model to ask.
	 * @param int    $cap     Ceiling on the answer, in tokens.
	 * @return array Request body.
	 */
	protected function build_body( array $request, $model, $cap ) {
		$body = array(
			'model'             => $model,

			/*
			 * The instructions are the cacheable prefix. Callers render them once per
			 * run and hand back the identical string every time, which is what lets the
			 * provider serve most of the prompt from its cache — measured at 2,816 of
			 * 3,164 input tokens on a repeat call. Anything that varies per request
			 * belongs in "input" instead.
			 */
			'instructions'      => (string) $request['instructions'],
			'input'             => (string) $request['input'],
			'reasoning'         => array( 'effort' => isset( $request['effort'] ) ? (string) $request['effort'] : 'low' ),
			'max_output_tokens' => (int) $cap,
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => isset( $request['schema_name'] ) ? (string) $request['schema_name'] : 'result',

					/*
					 * Strict mode is what makes the answer parseable without defensive
					 * decoding: every property must be declared and required, and
					 * additionalProperties must be false. Schema\ builds them that way.
					 */
					'strict' => true,
					'schema' => isset( $request['schema'] ) ? (array) $request['schema'] : array(),
				),
			),
		);

		if ( ! empty( $request['cache_key'] ) ) {
			$body['prompt_cache_key'] = (string) $request['cache_key'];
		}

		return $body;
	}

	/**
	 * Turn a raw HTTP response into a decoded payload or a descriptive error.
	 *
	 * The order of the checks below is not arbitrary. Each one is a failure mode
	 * that reaches this method looking like something else.
	 *
	 * @param array|WP_Error $response Raw response from wp_remote_post().
	 * @param string         $label    Request label, for logging.
	 * @param int            $attempt  Attempt number, for logging.
	 * @return array|WP_Error Payload and usage, or an error carrying a disposition.
	 */
	protected function interpret_response( $response, $label, $attempt ) {
		if ( is_wp_error( $response ) ) {
			$this->log( 'error', sprintf( '%s failed on attempt %d: %s', $label, $attempt, $response->get_error_message() ) );

			return new WP_Error(
				'wpcai_transport_error',
				$response->get_error_message(),
				array( 'disposition' => 'retry' )
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$error = $this->error_from_status( $status, $decoded );

			if ( 429 === $status ) {
				$error->add_data(
					array_merge(
						(array) $error->get_error_data(),
						array( 'retry_after' => self::retry_after( $response ) )
					),
					$error->get_error_code()
				);
			}

			$this->log( 'error', sprintf( '%s failed on attempt %d: HTTP %d.', $label, $attempt, $status ) );

			return $error;
		}

		if ( ! is_array( $decoded ) ) {
			$this->log( 'error', sprintf( '%s returned a body that could not be decoded (HTTP %d).', $label, $status ) );

			return new WP_Error(
				'wpcai_invalid_json',
				__( 'The AI provider returned a response that could not be decoded.', 'woo-product-categorizer-ai' ),
				array( 'disposition' => 'retry' )
			);
		}

		/*
		 * A truncated answer arrives as HTTP 200 with status "incomplete". Every check
		 * above it passes, and the code below would then look for a message element
		 * that is not there. This has to be tested before the output is walked, not
		 * after.
		 */
		if ( 'completed' !== ( isset( $decoded['status'] ) ? (string) $decoded['status'] : '' ) ) {
			$reason = isset( $decoded['incomplete_details']['reason'] )
				? (string) $decoded['incomplete_details']['reason']
				: ( isset( $decoded['status'] ) ? (string) $decoded['status'] : 'unknown' );

			$this->log( 'warning', sprintf( '%s came back incomplete on attempt %d: %s.', $label, $attempt, $reason ) );

			return new WP_Error(
				'wpcai_incomplete_response',
				sprintf(
					/* translators: %s: the reason the provider gave, for example "max_output_tokens". */
					__( 'The AI provider did not finish its answer (%s).', 'woo-product-categorizer-ai' ),
					$reason
				),
				array(
					'disposition' => 'retry',
					'reason'      => $reason,
				)
			);
		}

		$text = self::message_text( $decoded );

		if ( null === $text ) {
			$this->log( 'error', sprintf( '%s returned no message content.', $label ) );

			return new WP_Error(
				'wpcai_no_message',
				__( 'The AI provider returned no answer.', 'woo-product-categorizer-ai' ),
				array( 'disposition' => 'fail' )
			);
		}

		$payload = json_decode( $text, true );

		if ( ! is_array( $payload ) ) {
			$this->log( 'error', sprintf( '%s returned an answer that was not valid JSON.', $label ) );

			return new WP_Error(
				'wpcai_invalid_payload',
				__( 'The AI provider returned an answer that did not match the requested format.', 'woo-product-categorizer-ai' ),
				array( 'disposition' => 'retry' )
			);
		}

		return array(
			'payload' => $payload,
			'usage'   => self::usage( $decoded ),
		);
	}

	/**
	 * Pull the answer text out of a completed response.
	 *
	 * The output is a list of items — reasoning summaries, tool calls, the message —
	 * and only one of them carries the answer. Taking output[0] works right up until
	 * the model emits a reasoning item first, which it does whenever effort is not
	 * minimal.
	 *
	 * @param array $decoded Decoded response body.
	 * @return string|null The answer text, or null when there is none.
	 */
	protected static function message_text( array $decoded ) {
		$output = isset( $decoded['output'] ) && is_array( $decoded['output'] ) ? $decoded['output'] : array();

		foreach ( $output as $item ) {
			if ( ! is_array( $item ) || 'message' !== ( isset( $item['type'] ) ? $item['type'] : '' ) ) {
				continue;
			}

			$content = isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array();

			foreach ( $content as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					return $part['text'];
				}
			}
		}

		return null;
	}

	/**
	 * Read the token counters off a response.
	 *
	 * Every key defaults to zero: they are displayed as running totals, and a run
	 * that reported nothing rather than a partial count would be worse.
	 *
	 * @param array $decoded Decoded response body.
	 * @return array Usage counters.
	 */
	protected static function usage( array $decoded ) {
		$usage = isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ? $decoded['usage'] : array();

		return array(
			'input_tokens'     => isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0,
			'output_tokens'    => isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0,
			'reasoning_tokens' => isset( $usage['output_tokens_details']['reasoning_tokens'] )
				? (int) $usage['output_tokens_details']['reasoning_tokens']
				: 0,
			'cached_tokens'    => isset( $usage['input_tokens_details']['cached_tokens'] )
				? (int) $usage['input_tokens_details']['cached_tokens']
				: 0,
		);
	}

	/**
	 * Build the error for a non-2xx status.
	 *
	 * The disposition is the important part. A 401 or a 404 will not fix itself, and
	 * a run that kept retrying one would burn every batch to say the same thing.
	 *
	 * @param int   $status  HTTP status.
	 * @param mixed $decoded Decoded body, if it decoded at all.
	 * @return WP_Error
	 */
	protected function error_from_status( $status, $decoded ) {
		$message = '';
		$code    = '';

		if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$message = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : '';
			$code    = isset( $decoded['error']['code'] ) ? (string) $decoded['error']['code'] : '';
		}

		if ( '' === $message ) {
			/* translators: %d: HTTP status code returned by the AI provider. */
			$message = sprintf( __( 'The AI provider returned HTTP status %d.', 'woo-product-categorizer-ai' ), $status );
		}

		return new WP_Error(
			'wpcai_api_error',
			$message,
			array(
				'disposition' => in_array( $status, self::$retryable_statuses, true ) ? 'retry' : 'fail',
				'status'      => $status,
				'error_code'  => $code,
			)
		);
	}

	/**
	 * How long the server asked us to wait, in seconds.
	 *
	 * @param array $response Raw response.
	 * @return int Seconds, clamped, or 0 when the header was absent or unusable.
	 */
	protected static function retry_after( $response ) {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );

		if ( is_array( $header ) ) {
			$header = reset( $header );
		}

		$seconds = is_numeric( $header ) ? (int) $header : 0;

		return max( 0, min( self::MAX_RETRY_AFTER, $seconds ) );
	}

	/**
	 * Sleep between attempts.
	 *
	 * @param WP_Error $error   The error that just came back.
	 * @param int      $attempt Attempt number that failed.
	 * @return void
	 */
	protected function wait_before_retry( $error, $attempt ) {
		$requested = (int) self::detail( $error, 'retry_after', '0' );

		/**
		 * Filters the backoff delay between retries.
		 *
		 * Defaults to exponential backoff — 2s, then 4s — unless the provider named a
		 * delay of its own, which is honoured up to MAX_RETRY_AFTER. Kept short
		 * because this runs inside an Action Scheduler action that is holding a
		 * database connection open while it waits.
		 *
		 * @since 0.1.0
		 *
		 * @param int $delay   Delay in seconds.
		 * @param int $attempt Attempt number that just failed.
		 */
		$delay = (int) apply_filters(
			'woo_product_categorizer_ai_retry_delay',
			$requested > 0 ? $requested : self::BACKOFF_BASE_SECONDS ** $attempt,
			$attempt
		);

		if ( $delay > 0 ) {
			sleep( $delay );
		}
	}

	/**
	 * Read one detail off a WP_Error's data array.
	 *
	 * Worth its own helper, and worth this note: WP_Error::get_error_data() takes an
	 * error *code*, not a data key. Passing 'disposition' to it returns the data for
	 * an error code of that name — which is nothing — so every retry silently
	 * becomes a failure. That mistake has been made here before.
	 *
	 * @param mixed  $error    Presumed WP_Error.
	 * @param string $key      Detail to read, such as "disposition" or "status".
	 * @param string $fallback Value to use when the detail is absent.
	 * @return string The detail value.
	 */
	public static function detail( $error, $key, $fallback = '' ) {
		if ( ! is_wp_error( $error ) ) {
			return $fallback;
		}

		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data[ $key ] ) ? (string) $data[ $key ] : $fallback;
	}

	/**
	 * The stored API key for this provider.
	 *
	 * @return string|WP_Error The key, or an error when none is stored.
	 */
	protected function get_api_key() {
		$key = isset( $this->settings['api_keys'][ self::id() ] )
			? trim( (string) $this->settings['api_keys'][ self::id() ] )
			: '';

		if ( '' === $key ) {
			return new WP_Error(
				'wpcai_not_configured',
				__( 'No OpenAI API key has been saved yet.', 'woo-product-categorizer-ai' ),
				array( 'disposition' => 'fail' )
			);
		}

		return $key;
	}

	/**
	 * Build the request headers.
	 *
	 * Never logged, in full or in part: they carry the credential.
	 *
	 * @param string $key API key.
	 * @return array Headers.
	 */
	protected function build_headers( $key ) {
		return array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Write to the plugin's log.
	 *
	 * @param string $level   One of the WC_Log_Levels constants.
	 * @param string $message What happened.
	 * @return void
	 */
	protected function log( $level, $message ) {
		Scheduler::log( $level, $message );
	}
}
