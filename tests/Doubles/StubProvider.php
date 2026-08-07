<?php
/**
 * A provider that answers from a script instead of a network.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests\Doubles;

use WooProductCategorizerAi\Provider\ProviderInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Returns canned answers and records every request it was handed.
 *
 * The requests are what most of the suite actually asserts on: that a batch of
 * skipped products made no call at all, that the instructions string is identical
 * across batches, that the schema carried the enum. Those are invisible in the
 * result and are exactly the things that break quietly.
 */
class StubProvider implements ProviderInterface {

	/**
	 * Answers to return, in order. Each is an array or a WP_Error.
	 *
	 * @var array
	 */
	public $answers = array();

	/**
	 * Every request complete() was called with.
	 *
	 * @var array
	 */
	public $requests = array();

	/**
	 * What test_connection() should return.
	 *
	 * @var true|WP_Error
	 */
	public $connection = true;

	/**
	 * What list_models() should return.
	 *
	 * @var array|WP_Error
	 */
	public $models = array(
		'recommended' => array( 'gpt-5.4-mini' ),
		'other'       => array(),
	);

	/**
	 * Construct the double.
	 *
	 * @param array $answers Answers to hand back, in order.
	 */
	public function __construct( array $answers = array() ) {
		$this->answers = $answers;
	}

	/**
	 * Queue one more answer.
	 *
	 * @param array $payload The decoded payload to return.
	 * @param array $usage   Optional usage counters.
	 * @return StubProvider This double, for chaining.
	 */
	public function will_answer( array $payload, array $usage = array() ) {
		$this->answers[] = array(
			'payload' => $payload,
			'usage'   => wp_parse_args(
				$usage,
				array(
					'input_tokens'     => 100,
					'output_tokens'    => 20,
					'reasoning_tokens' => 5,
					'cached_tokens'    => 80,
				)
			),
		);

		return $this;
	}

	/**
	 * Queue one more failure.
	 *
	 * @param string $code        Error code.
	 * @param string $disposition Either retry or fail.
	 * @return StubProvider This double, for chaining.
	 */
	public function will_fail( $code = 'wpcai_api_error', $disposition = 'fail' ) {
		$this->answers[] = new WP_Error( $code, 'stubbed failure', array( 'disposition' => $disposition ) );

		return $this;
	}

	/**
	 * How many times complete() was called.
	 *
	 * @return int
	 */
	public function call_count() {
		return count( $this->requests );
	}

	/**
	 * The stable identifier this provider is stored under.
	 *
	 * @return string
	 */
	public static function id() {
		return 'stub';
	}

	/**
	 * The provider's name.
	 *
	 * @return string
	 */
	public static function label() {
		return 'Stub';
	}

	/**
	 * The model to use when none has been chosen.
	 *
	 * @return string
	 */
	public static function recommended_model() {
		return 'stub-model';
	}

	/**
	 * The models worth putting in front of someone choosing.
	 *
	 * @return array
	 */
	public static function curated_models() {
		return array( 'stub-model' => 'Stub model' );
	}

	/**
	 * List the models this account can use.
	 *
	 * @return array|WP_Error
	 */
	public function list_models() {
		return $this->models;
	}

	/**
	 * Check that the credentials work.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		return $this->connection;
	}

	/**
	 * Hand back the next scripted answer.
	 *
	 * Running out of answers is a test bug, not a provider failure, so it is loud:
	 * a run that made one more call than the test expected would otherwise pass by
	 * silently receiving an error it happens to tolerate.
	 *
	 * @param array $request The request, recorded for later assertions.
	 * @return array|WP_Error
	 */
	public function complete( array $request ) {
		$this->requests[] = $request;

		if ( empty( $this->answers ) ) {
			return new WP_Error(
				'wpcai_stub_exhausted',
				'The stub provider ran out of scripted answers.',
				array( 'disposition' => 'fail' )
			);
		}

		return array_shift( $this->answers );
	}
}
