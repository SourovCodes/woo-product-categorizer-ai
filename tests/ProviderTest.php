<?php
/**
 * The OpenAI provider's wire handling.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Provider\OpenAiProvider;
use WooProductCategorizerAi\Provider\Providers;
use WP_Error;
use WP_UnitTestCase;

/**
 * Every test here pins down a failure mode that reaches the client looking like
 * something else. The response shapes are the ones the live API actually returns.
 */
class ProviderTest extends WP_UnitTestCase {

	/**
	 * Bodies to hand back, in order.
	 *
	 * @var array
	 */
	protected $responses = array();

	/**
	 * Every request the filter intercepted.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Set up the fixture.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->responses = array();
		$this->requests  = array();

		// Retries would otherwise put real seconds on the clock.
		add_filter( 'woo_product_categorizer_ai_retry_delay', '__return_zero' );

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		remove_filter( 'woo_product_categorizer_ai_retry_delay', '__return_zero' );

		delete_option( Settings::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Stand in for the HTTP layer.
	 *
	 * @param mixed  $preempt Whatever a previous filter decided.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array The scripted response.
	 */
	public function intercept( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
			'body' => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
		);

		if ( empty( $this->responses ) ) {
			return array(
				'response' => array( 'code' => 500 ),
				'body'     => '{}',
				'headers'  => array(),
			);
		}

		return array_shift( $this->responses );
	}

	/**
	 * Script one response.
	 *
	 * @param int   $code    HTTP status.
	 * @param mixed $body    Body, encoded if it is not already a string.
	 * @param array $headers Response headers.
	 * @return void
	 */
	protected function will_respond( $code, $body, array $headers = array() ) {
		$this->responses[] = array(
			'response' => array( 'code' => $code ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'headers'  => $headers,
		);
	}

	/**
	 * A completed response, wrapped the way the Responses API wraps one.
	 *
	 * The reasoning item comes first deliberately: that is what the live API emits
	 * whenever effort is above minimal, and taking output[0] would find it instead
	 * of the answer.
	 *
	 * @param array $payload The JSON the model returned.
	 * @return array A response body.
	 */
	protected function completed( array $payload ) {
		return array(
			'status' => 'completed',
			'output' => array(
				array(
					'type'    => 'reasoning',
					'summary' => array(),
				),
				array(
					'type'    => 'message',
					'content' => array(
						array(
							'type' => 'output_text',
							'text' => wp_json_encode( $payload ),
						),
					),
				),
			),
			'usage'  => array(
				'input_tokens'          => 3164,
				'output_tokens'         => 218,
				'input_tokens_details'  => array( 'cached_tokens' => 2816 ),
				'output_tokens_details' => array( 'reasoning_tokens' => 93 ),
			),
		);
	}

	/**
	 * Build a provider with a key in place.
	 *
	 * @return OpenAiProvider
	 */
	protected function provider() {
		return new OpenAiProvider(
			array_merge(
				Settings::default_settings(),
				array(
					'provider' => 'openai',
					'api_keys' => array( 'openai' => 'sk-test' ),
					'models'   => array( 'openai' => 'gpt-5.4-mini' ),
				)
			)
		);
	}

	/**
	 * A minimal well-formed request.
	 *
	 * @return array
	 */
	protected function request() {
		return array(
			'instructions' => 'TAXONOMY: 1 Spielwaren',
			'input'        => '{"products":[]}',
			'schema'       => array( 'type' => 'object' ),
			'schema_name'  => 'category_assignments',
			'effort'       => 'low',
			'max_tokens'   => 4000,
			'cache_key'    => 'wpcai-assign-123',
		);
	}

	/**
	 * The happy path finds the message among the output items and decodes it.
	 *
	 * @return void
	 */
	public function test_a_completed_response_is_decoded() {
		$this->will_respond( 200, $this->completed( array( 'ok' => true ) ) );

		$result = $this->provider()->complete( $this->request() );

		$this->assertNotWPError( $result );
		$this->assertSame( array( 'ok' => true ), $result['payload'] );
	}

	/**
	 * Usage has to include the two nested counters, because the cached share is how
	 * the screen shows that prompt caching is working at all.
	 *
	 * @return void
	 */
	public function test_usage_includes_the_nested_counters() {
		$this->will_respond( 200, $this->completed( array( 'ok' => true ) ) );

		$usage = $this->provider()->complete( $this->request() )['usage'];

		$this->assertSame( 3164, $usage['input_tokens'] );
		$this->assertSame( 218, $usage['output_tokens'] );
		$this->assertSame( 93, $usage['reasoning_tokens'] );
		$this->assertSame( 2816, $usage['cached_tokens'] );
	}

	/**
	 * The cheapest possible guard against silently losing prompt caching, which is
	 * invisible in the result and only shows up as a bill.
	 *
	 * @return void
	 */
	public function test_the_request_body_carries_what_caching_and_strict_mode_need() {
		$this->will_respond( 200, $this->completed( array( 'ok' => true ) ) );

		$this->provider()->complete( $this->request() );

		$body = $this->requests[0]['body'];

		$this->assertSame( 'TAXONOMY: 1 Spielwaren', $body['instructions'] );
		$this->assertSame( 'wpcai-assign-123', $body['prompt_cache_key'] );
		$this->assertTrue( $body['text']['format']['strict'] );
		$this->assertSame( 'json_schema', $body['text']['format']['type'] );
		$this->assertSame( 'low', $body['reasoning']['effort'] );
		$this->assertSame( 'gpt-5.4-mini', $body['model'] );
		$this->assertSame( 'Bearer sk-test', $this->requests[0]['args']['headers']['Authorization'] );
	}

	/**
	 * The one header that says which site the traffic came from. Without it a shop
	 * asking the provider about its own rate limits has nothing to identify itself
	 * by, and the omission is invisible until that conversation happens.
	 *
	 * @return void
	 */
	public function test_every_request_identifies_the_plugin_and_the_site() {
		$this->will_respond( 200, $this->completed( array( 'ok' => true ) ) );

		$this->provider()->complete( $this->request() );

		$agent = $this->requests[0]['args']['headers']['User-Agent'];

		$this->assertStringStartsWith( 'WooProductCategorizerAi/' . WPCAI_VERSION, $agent );
		$this->assertStringContainsString( home_url( '/' ), $agent );
	}

	/**
	 * A truncated answer arrives as HTTP 200. Every status check passes, so without
	 * an explicit test on the status field the code walks an output array that has
	 * no message in it.
	 *
	 * @return void
	 */
	public function test_an_incomplete_response_is_an_error_not_an_empty_payload() {
		$body = array(
			'status'             => 'incomplete',
			'incomplete_details' => array( 'reason' => 'max_output_tokens' ),
			'output'             => array(),
			'usage'              => array( 'input_tokens' => 10 ),
		);

		// Three attempts, all truncated.
		$this->will_respond( 200, $body );
		$this->will_respond( 200, $body );
		$this->will_respond( 200, $body );

		$result = $this->provider()->complete( $this->request() );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_incomplete_response', $result->get_error_code() );
		$this->assertSame( 'max_output_tokens', OpenAiProvider::detail( $result, 'reason' ) );
	}

	/**
	 * Re-sending an identical request that ran out of room is guaranteed to run out
	 * of room again, having charged for every reasoning token on the way.
	 *
	 * @return void
	 */
	public function test_a_truncated_request_is_retried_with_more_room() {
		$truncated = array(
			'status'             => 'incomplete',
			'incomplete_details' => array( 'reason' => 'max_output_tokens' ),
			'output'             => array(),
		);

		$this->will_respond( 200, $truncated );
		$this->will_respond( 200, $this->completed( array( 'ok' => true ) ) );

		$result = $this->provider()->complete( $this->request() );

		$this->assertNotWPError( $result );
		$this->assertCount( 2, $this->requests );

		$first  = $this->requests[0]['body']['max_output_tokens'];
		$second = $this->requests[1]['body']['max_output_tokens'];

		$this->assertSame( 4000, $first );
		$this->assertGreaterThan( $first, $second );
	}

	/**
	 * The growth is bounded, so a pathological answer cannot spend without limit.
	 *
	 * @return void
	 */
	public function test_the_output_cap_never_grows_past_its_ceiling() {
		$truncated = array(
			'status'             => 'incomplete',
			'incomplete_details' => array( 'reason' => 'max_output_tokens' ),
			'output'             => array(),
		);

		$this->will_respond( 200, $truncated );
		$this->will_respond( 200, $truncated );
		$this->will_respond( 200, $truncated );

		$this->provider()->complete( $this->request() );

		foreach ( $this->requests as $request ) {
			$this->assertLessThanOrEqual( 4000 * OpenAiProvider::MAX_GROWTH, $request['body']['max_output_tokens'] );
		}
	}

	/**
	 * A rejected key will not fix itself, so it must not be retried.
	 *
	 * @return void
	 */
	public function test_an_auth_failure_fails_rather_than_retries() {
		$this->will_respond(
			401,
			array(
				'error' => array(
					'message' => 'Incorrect API key provided.',
					'code'    => 'invalid_api_key',
				),
			)
		);

		$result = $this->provider()->complete( $this->request() );

		$this->assertWPError( $result );
		$this->assertSame( 'fail', OpenAiProvider::detail( $result, 'disposition' ) );
		$this->assertSame( 'Incorrect API key provided.', $result->get_error_message() );
		$this->assertCount( 1, $this->requests, 'A 401 must not be retried.' );
	}

	/**
	 * Rate limiting is temporary, so it is retried, and the delay the server asked
	 * for is carried through for the caller to honour.
	 *
	 * @return void
	 */
	public function test_a_rate_limit_is_retried_and_carries_its_retry_after() {
		$this->will_respond( 429, array( 'error' => array( 'message' => 'Rate limit reached.' ) ), array( 'retry-after' => '7' ) );
		$this->will_respond( 429, array( 'error' => array( 'message' => 'Rate limit reached.' ) ), array( 'retry-after' => '7' ) );
		$this->will_respond( 429, array( 'error' => array( 'message' => 'Rate limit reached.' ) ), array( 'retry-after' => '7' ) );

		$result = $this->provider()->complete( $this->request() );

		$this->assertWPError( $result );
		$this->assertSame( 'retry', OpenAiProvider::detail( $result, 'disposition' ) );
		$this->assertSame( '7', OpenAiProvider::detail( $result, 'retry_after' ) );
		$this->assertCount( OpenAiProvider::MAX_ATTEMPTS, $this->requests );
	}

	/**
	 * An absurd Retry-After must not hold an Action Scheduler action open past its
	 * own timeout, which would fail the batch for an unrelated reason.
	 *
	 * @return void
	 */
	public function test_an_absurd_retry_after_is_clamped() {
		$this->will_respond( 429, array( 'error' => array( 'message' => 'Slow down.' ) ), array( 'retry-after' => '99999' ) );
		$this->will_respond( 200, $this->completed( array( 'ok' => true ) ) );

		$this->provider()->complete( $this->request() );

		$this->assertCount( 2, $this->requests );
	}

	/**
	 * A body that is not JSON at all — an HTML error page from a proxy, say.
	 *
	 * @return void
	 */
	public function test_a_body_that_is_not_json_is_an_error() {
		$this->will_respond( 200, '<html>gateway timeout</html>' );
		$this->will_respond( 200, '<html>gateway timeout</html>' );
		$this->will_respond( 200, '<html>gateway timeout</html>' );

		$result = $this->provider()->complete( $this->request() );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_invalid_json', $result->get_error_code() );
	}

	/**
	 * A well-formed envelope whose answer text is not JSON.
	 *
	 * @return void
	 */
	public function test_an_answer_that_is_not_json_is_an_error() {
		$body = array(
			'status' => 'completed',
			'output' => array(
				array(
					'type'    => 'message',
					'content' => array( array( 'text' => 'I could not do that.' ) ),
				),
			),
		);

		$this->will_respond( 200, $body );
		$this->will_respond( 200, $body );
		$this->will_respond( 200, $body );

		$result = $this->provider()->complete( $this->request() );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_invalid_payload', $result->get_error_code() );
	}

	/**
	 * A completed response with no message item at all.
	 *
	 * @return void
	 */
	public function test_a_response_with_no_message_is_an_error() {
		$this->will_respond(
			200,
			array(
				'status' => 'completed',
				'output' => array( array( 'type' => 'reasoning' ) ),
			)
		);

		$result = $this->provider()->complete( $this->request() );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_no_message', $result->get_error_code() );
	}

	/**
	 * No key is a configuration problem, and must be reported without spending a
	 * request to discover it.
	 *
	 * @return void
	 */
	public function test_a_missing_key_fails_before_any_request() {
		$provider = new OpenAiProvider( Settings::default_settings() );

		$result = $provider->complete( $this->request() );

		$this->assertWPError( $result );
		$this->assertSame( 'wpcai_not_configured', $result->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * The picker must not offer a speech or image model for a categorisation job.
	 *
	 * @return void
	 */
	public function test_the_model_list_excludes_everything_that_cannot_answer_a_prompt() {
		$ids = array(
			'gpt-5.4-mini',
			'gpt-5.4-nano',
			'o4-mini',
			'gpt-4o-mini-tts',
			'gpt-realtime',
			'gpt-image-1',
			'whisper-1',
			'text-embedding-3-small',
			'omni-moderation-latest',
			'gpt-5.1-codex',
			'sora-2',
			'babbage-002',
			'gpt-4o-search-preview',
		);

		$this->will_respond(
			200,
			array(
				'data' => array_map(
					static function ( $id ) {
						return array( 'id' => $id );
					},
					$ids
				),
			)
		);

		$models = $this->provider()->list_models();

		$this->assertNotWPError( $models );

		$offered = array_merge( $models['recommended'], $models['other'] );

		$this->assertContains( 'gpt-5.4-mini', $offered );
		$this->assertContains( 'o4-mini', $offered );

		foreach ( array( 'gpt-4o-mini-tts', 'gpt-realtime', 'gpt-image-1', 'whisper-1', 'text-embedding-3-small', 'omni-moderation-latest', 'gpt-5.1-codex', 'sora-2', 'babbage-002', 'gpt-4o-search-preview' ) as $unusable ) {
			$this->assertNotContains( $unusable, $offered, $unusable . ' cannot answer a text prompt.' );
		}
	}

	/**
	 * Recommending a model the account cannot reach would be recommending a failure.
	 *
	 * @return void
	 */
	public function test_only_models_the_account_has_are_recommended() {
		$this->will_respond(
			200,
			array(
				'data' => array(
					array( 'id' => 'gpt-5.4-mini' ),
					array( 'id' => 'gpt-4.1-mini' ),
				),
			)
		);

		$models = $this->provider()->list_models();

		$this->assertSame( array( 'gpt-5.4-mini' ), $models['recommended'] );
		$this->assertContains( 'gpt-4.1-mini', $models['other'] );
		$this->assertNotContains( 'gpt-5.4-mini', $models['other'], 'A recommended model must not also appear under Other.' );
	}

	/**
	 * An empty stored model is a real choice meaning "whatever is recommended".
	 *
	 * @return void
	 */
	public function test_an_empty_stored_model_resolves_to_the_recommendation() {
		$settings = array_merge(
			Settings::default_settings(),
			array(
				'provider' => 'openai',
				'models'   => array( 'openai' => '' ),
			)
		);

		$this->assertSame( OpenAiProvider::recommended_model(), Providers::model( $settings ) );
	}

	/**
	 * A stored model is used as given, without being checked against a list that
	 * would be out of date the week after it was written.
	 *
	 * @return void
	 */
	public function test_a_stored_model_is_used_as_given() {
		$settings = array_merge(
			Settings::default_settings(),
			array(
				'provider' => 'openai',
				'models'   => array( 'openai' => 'some-unreleased-model' ),
			)
		);

		$this->assertSame( 'some-unreleased-model', Providers::model( $settings ) );
	}

	/**
	 * WP_Error::get_error_data() takes an error code, not a data key. Passing a key
	 * to it returns nothing, and every retry silently becomes a failure.
	 *
	 * @return void
	 */
	public function test_detail_reads_a_data_key_and_falls_back_cleanly() {
		$error = new WP_Error( 'some_code', 'message', array( 'disposition' => 'retry' ) );

		$this->assertSame( 'retry', OpenAiProvider::detail( $error, 'disposition' ) );
		$this->assertSame( 'fail', OpenAiProvider::detail( $error, 'missing', 'fail' ) );
		$this->assertSame( 'fail', OpenAiProvider::detail( 'not an error', 'disposition', 'fail' ) );
	}
}
