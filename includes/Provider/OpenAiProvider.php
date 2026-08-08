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
class OpenAiProvider implements ProviderInterface, BatchProviderInterface {

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
	 * The endpoint batched requests are executed against.
	 *
	 * The same one the live path uses, which is what lets both modes build their
	 * request bodies with the same code.
	 */
	const BATCH_ENDPOINT = '/v1/responses';

	/**
	 * How long the provider is given to work through a batch.
	 *
	 * The only window OpenAI offers, and also the one the discount is attached to.
	 */
	const BATCH_WINDOW = '24h';

	/**
	 * How long to allow for moving the request or result file.
	 *
	 * A whole catalogue is megabytes in each direction, which is a different kind
	 * of wait from asking a question.
	 */
	const UPLOAD_TIMEOUT = 300;

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

		return $this->payload_from_body( $decoded, $label, $attempt );
	}

	/**
	 * Turn a decoded Responses body into a payload, or say why it cannot be.
	 *
	 * Split out of interpret_response() because the batch endpoint hands back
	 * exactly this same body, one per line of its result file — verified against
	 * the live API. Reusing it means a truncated answer, a missing message and a
	 * malformed payload are all recognised identically whether they arrived over a
	 * live request or hours later in a file.
	 *
	 * @param array  $decoded A decoded Responses API body.
	 * @param string $label   Request label, for logging.
	 * @param int    $attempt Attempt number, for logging.
	 * @return array|WP_Error Payload and usage, or an error carrying a disposition.
	 */
	protected function payload_from_body( array $decoded, $label, $attempt = 1 ) {
		/*
		 * A truncated answer arrives as HTTP 200 with status "incomplete". Every check
		 * before this one passes, and the code below would then look for a message
		 * element that is not there. This has to be tested before the output is
		 * walked, not after.
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
	 * Hand over every request at once.
	 *
	 * Two calls: the requests go up as a JSONL file, then a batch is created
	 * against it. The endpoint is the same /v1/responses the live path uses, so the
	 * request bodies are built by exactly the same code and cannot drift.
	 *
	 * @param array $requests Custom id => a request in complete()'s shape.
	 * @return string|WP_Error The batch id to poll with, or an error.
	 */
	public function submit_batch( array $requests ) {
		$key = $this->get_api_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$model = Providers::model( $this->settings );
		$model = '' === $model ? self::recommended_model() : $model;
		$lines = array();

		foreach ( $requests as $custom_id => $request ) {
			$cap = max( 256, (int) ( isset( $request['max_tokens'] ) ? $request['max_tokens'] : 4000 ) );

			$lines[] = wp_json_encode(
				array(
					'custom_id' => (string) $custom_id,
					'method'    => 'POST',
					'url'       => self::BATCH_ENDPOINT,
					'body'      => $this->build_body( $request, $model, $cap ),
				)
			);
		}

		if ( empty( $lines ) ) {
			return new WP_Error(
				'wpcai_empty_batch',
				__( 'There was nothing to send.', 'woo-product-categorizer-ai' ),
				array( 'disposition' => 'fail' )
			);
		}

		$file_id = $this->upload_batch_file( implode( "\n", $lines ), $key );

		if ( is_wp_error( $file_id ) ) {
			return $file_id;
		}

		$response = wp_remote_post(
			self::API_BASE . '/batches',
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => $this->build_headers( $key ),
				'body'    => wp_json_encode(
					array(
						'input_file_id'     => $file_id,
						'endpoint'          => self::BATCH_ENDPOINT,
						'completion_window' => self::BATCH_WINDOW,
					)
				),
			)
		);

		$decoded = $this->decode_or_error( $response, 'batch create' );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$batch_id = isset( $decoded['id'] ) ? (string) $decoded['id'] : '';

		if ( '' === $batch_id ) {
			return new WP_Error(
				'wpcai_no_batch_id',
				__( 'The AI provider accepted the batch but did not say what it was called.', 'woo-product-categorizer-ai' ),
				array( 'disposition' => 'fail' )
			);
		}

		$this->log( 'info', sprintf( 'Submitted batch %s with %d requests.', $batch_id, count( $lines ) ) );

		return $batch_id;
	}

	/**
	 * Upload the JSONL of requests.
	 *
	 * Built by hand rather than with a library, because this is the one place in
	 * the plugin that needs multipart/form-data and WordPress has no helper for it.
	 * The boundary is random so it cannot occur in the payload, and the body is
	 * assembled with explicit CRLFs — a lone newline here is accepted by some
	 * servers and rejected by others, which makes it exactly the kind of bug that
	 * shows up only in production.
	 *
	 * @param string $jsonl The requests, one JSON object per line.
	 * @param string $key   API key.
	 * @return string|WP_Error The uploaded file's id.
	 */
	protected function upload_batch_file( $jsonl, $key ) {
		$boundary = 'wpcai' . wp_generate_password( 24, false );
		$eol      = "\r\n";

		$body  = '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="purpose"' . $eol . $eol;
		$body .= 'batch' . $eol;
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="file"; filename="requests.jsonl"' . $eol;
		$body .= 'Content-Type: application/jsonl' . $eol . $eol;
		$body .= $jsonl . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		$response = wp_remote_post(
			self::API_BASE . '/files',
			array(
				// Uploading a few megabytes takes longer than asking a question does.
				'timeout' => self::UPLOAD_TIMEOUT,
				'headers' => $this->build_headers( $key, 'multipart/form-data; boundary=' . $boundary ),
				'body'    => $body,
			)
		);

		$decoded = $this->decode_or_error( $response, 'batch upload' );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return isset( $decoded['id'] ) ? (string) $decoded['id'] : new WP_Error(
			'wpcai_no_file_id',
			__( 'The AI provider accepted the upload but did not say what it was called.', 'woo-product-categorizer-ai' ),
			array( 'disposition' => 'fail' )
		);
	}

	/**
	 * Ask how a submitted batch is getting on.
	 *
	 * The vendor's status names are translated into the plugin's four here, so
	 * nothing above this class ever learns what "finalizing" means.
	 *
	 * @param string $batch_id Batch to ask about.
	 * @return array|WP_Error State and counts.
	 */
	public function poll_batch( $batch_id ) {
		$decoded = $this->batch_request( 'GET', '/batches/' . rawurlencode( $batch_id ), null, 'batch poll' );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$status = isset( $decoded['status'] ) ? (string) $decoded['status'] : '';
		$counts = isset( $decoded['request_counts'] ) && is_array( $decoded['request_counts'] )
			? $decoded['request_counts']
			: array();

		$states = array(
			'validating'  => 'pending',
			'in_progress' => 'pending',
			'finalizing'  => 'pending',
			'completed'   => 'done',
			'failed'      => 'failed',
			'expired'     => 'failed',
			'cancelling'  => 'pending',
			'cancelled'   => 'cancelled',
		);

		return array(
			'state'     => isset( $states[ $status ] ) ? $states[ $status ] : 'pending',
			'raw'       => $status,
			'total'     => isset( $counts['total'] ) ? (int) $counts['total'] : 0,
			'completed' => isset( $counts['completed'] ) ? (int) $counts['completed'] : 0,
			'failed'    => isset( $counts['failed'] ) ? (int) $counts['failed'] : 0,
		);
	}

	/**
	 * Collect the finished results.
	 *
	 * Every line carries a body in the same shape a live response has, so each one
	 * goes through payload_from_body() and is judged by exactly the same rules.
	 *
	 * @param string $batch_id Batch to collect.
	 * @return array|WP_Error Custom id => result or per-request error.
	 */
	public function fetch_batch_results( $batch_id ) {
		$batch = $this->batch_request( 'GET', '/batches/' . rawurlencode( $batch_id ), null, 'batch fetch' );

		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		$file_id = isset( $batch['output_file_id'] ) ? (string) $batch['output_file_id'] : '';

		if ( '' === $file_id ) {
			return new WP_Error(
				'wpcai_no_output_file',
				__( 'The batch finished without producing any results.', 'woo-product-categorizer-ai' ),
				array( 'disposition' => 'fail' )
			);
		}

		$key = $this->get_api_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$response = wp_remote_get(
			self::API_BASE . '/files/' . rawurlencode( $file_id ) . '/content',
			array(
				'timeout' => self::UPLOAD_TIMEOUT,
				'headers' => $this->build_headers( $key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wpcai_transport_error', $response->get_error_message(), array( 'disposition' => 'retry' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			return $this->error_from_status( $status, json_decode( wp_remote_retrieve_body( $response ), true ) );
		}

		return $this->parse_batch_results( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Read the JSONL result file into results keyed by custom id.
	 *
	 * @param string $jsonl The downloaded file.
	 * @return array Custom id => payload/usage, or a WP_Error for that one request.
	 */
	protected function parse_batch_results( $jsonl ) {
		$results = array();

		foreach ( preg_split( '/\R/', (string) $jsonl ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$row = json_decode( $line, true );

			if ( ! is_array( $row ) || ! isset( $row['custom_id'] ) ) {
				continue;
			}

			$custom_id = (string) $row['custom_id'];

			// A request the provider itself refused, rather than one it answered badly.
			if ( ! empty( $row['error'] ) ) {
				$message = is_array( $row['error'] ) && isset( $row['error']['message'] )
					? (string) $row['error']['message']
					: __( 'The provider rejected this request.', 'woo-product-categorizer-ai' );

				$results[ $custom_id ] = new WP_Error( 'wpcai_batch_request_error', $message, array( 'disposition' => 'fail' ) );
				continue;
			}

			$code = isset( $row['response']['status_code'] ) ? (int) $row['response']['status_code'] : 0;
			$body = isset( $row['response']['body'] ) && is_array( $row['response']['body'] ) ? $row['response']['body'] : array();

			if ( $code < 200 || $code >= 300 ) {
				$results[ $custom_id ] = $this->error_from_status( $code, $body );
				continue;
			}

			$results[ $custom_id ] = $this->payload_from_body( $body, 'batch:' . $custom_id );
		}

		return $results;
	}

	/**
	 * Stop a batch that has not finished.
	 *
	 * @param string $batch_id Batch to stop.
	 * @return true|WP_Error
	 */
	public function cancel_batch( $batch_id ) {
		$decoded = $this->batch_request( 'POST', '/batches/' . rawurlencode( $batch_id ) . '/cancel', array(), 'batch cancel' );

		return is_wp_error( $decoded ) ? $decoded : true;
	}

	/**
	 * One request against the batch endpoints.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under the API base.
	 * @param array|null $body   Optional JSON body.
	 * @param string     $label  Label, for logging.
	 * @return array|WP_Error Decoded body.
	 */
	protected function batch_request( $method, $path, $body, $label ) {
		$key = $this->get_api_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => $this->build_headers( $key ),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		return $this->decode_or_error( wp_remote_request( self::API_BASE . $path, $args ), $label );
	}

	/**
	 * Decode a response, or turn it into the error it represents.
	 *
	 * @param array|WP_Error $response Raw response.
	 * @param string         $label    Label, for logging.
	 * @return array|WP_Error Decoded body.
	 */
	protected function decode_or_error( $response, $label ) {
		if ( is_wp_error( $response ) ) {
			$this->log( 'error', sprintf( '%s failed: %s', $label, $response->get_error_message() ) );

			return new WP_Error( 'wpcai_transport_error', $response->get_error_message(), array( 'disposition' => 'retry' ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$this->log( 'error', sprintf( '%s failed: HTTP %d.', $label, $status ) );

			return $this->error_from_status( $status, $decoded );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wpcai_invalid_json',
				__( 'The AI provider returned a response that could not be decoded.', 'woo-product-categorizer-ai' ),
				array( 'disposition' => 'retry' )
			);
		}

		return $decoded;
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
	 * @param string $key          API key.
	 * @param string $content_type Media type of the body. The batch upload sends
	 *                             multipart rather than JSON.
	 * @return array Headers.
	 */
	protected function build_headers( $key, $content_type = 'application/json' ) {
		return array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => $content_type,
			'User-Agent'    => self::user_agent(),
		);
	}

	/**
	 * Identify the plugin and the shop to the provider.
	 *
	 * Carried on every request because it is the only thing tying an account's
	 * traffic back to a particular site. A shop asking OpenAI why it was rate
	 * limited, or which of its installations spent a day's budget, has nothing to
	 * point at without it — the key alone is shared by every site it is pasted into.
	 *
	 * The site's own URL is already public and is sent to the provider on no other
	 * basis than this, so it carries no catalogue and no customer data.
	 *
	 * @return string User-Agent value.
	 */
	protected static function user_agent() {
		return 'WooProductCategorizerAi/' . WPCAI_VERSION . '; ' . home_url( '/' );
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
