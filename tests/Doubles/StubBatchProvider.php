<?php
/**
 * A bulk provider that answers from a script instead of a network.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests\Doubles;

use WooProductCategorizerAi\Provider\BatchProviderInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Extends the live double so a run can be pointed at one object and exercise
 * either path, and so a test can assert that the bulk path made no live calls.
 */
class StubBatchProvider extends StubProvider implements BatchProviderInterface {

	/**
	 * Every set of requests submit_batch() was handed.
	 *
	 * @var array
	 */
	public $submissions = array();

	/**
	 * States poll_batch() should report, in order. The last one repeats.
	 *
	 * @var array
	 */
	public $states = array();

	/**
	 * Results fetch_batch_results() should return, keyed by custom id.
	 *
	 * @var array
	 */
	public $results = array();

	/**
	 * What submit_batch() should return instead of an id, if anything.
	 *
	 * @var \WP_Error|null
	 */
	public $submit_error = null;

	/**
	 * Whether cancel_batch() was called, and with what.
	 *
	 * @var array
	 */
	public $cancelled = array();

	/**
	 * The stable identifier this provider is stored under.
	 *
	 * @return string
	 */
	public static function id() {
		return 'stubbatch';
	}

	/**
	 * Script the states a poll should report, in order.
	 *
	 * @param array $states One or more state arrays or shorthand strings.
	 * @return StubBatchProvider This double, for chaining.
	 */
	public function will_report( array $states ) {
		foreach ( $states as $state ) {
			$this->states[] = is_array( $state ) ? $state : array(
				'state'     => $state,
				'raw'       => $state,
				'total'     => 0,
				'completed' => 0,
				'failed'    => 0,
			);
		}

		return $this;
	}

	/**
	 * Script the answer for one request of a batch.
	 *
	 * @param string $custom_id Which request.
	 * @param array  $payload   The decoded payload it should carry.
	 * @return StubBatchProvider This double, for chaining.
	 */
	public function will_return( $custom_id, array $payload ) {
		$this->results[ $custom_id ] = array(
			'payload' => $payload,
			'usage'   => array(
				'input_tokens'     => 100,
				'output_tokens'    => 20,
				'reasoning_tokens' => 5,
				'cached_tokens'    => 0,
			),
		);

		return $this;
	}

	/**
	 * Script a per-request failure inside an otherwise fine batch.
	 *
	 * @param string $custom_id Which request.
	 * @return StubBatchProvider This double, for chaining.
	 */
	public function will_fail_request( $custom_id ) {
		$this->results[ $custom_id ] = new WP_Error( 'wpcai_batch_request_error', 'stubbed', array( 'disposition' => 'fail' ) );

		return $this;
	}

	/**
	 * Hand over every request at once.
	 *
	 * @param array $requests Custom id => request.
	 * @return string|WP_Error
	 */
	public function submit_batch( array $requests ) {
		$this->submissions[] = $requests;

		return null === $this->submit_error ? 'batch_stub_1' : $this->submit_error;
	}

	/**
	 * Report the next scripted state, repeating the last one forever.
	 *
	 * @param string $batch_id Batch to ask about.
	 * @return array|WP_Error
	 */
	public function poll_batch( $batch_id ) {
		if ( empty( $this->states ) ) {
			return array(
				'state'     => 'done',
				'raw'       => 'completed',
				'total'     => 0,
				'completed' => 0,
				'failed'    => 0,
			);
		}

		return count( $this->states ) > 1 ? array_shift( $this->states ) : $this->states[0];
	}

	/**
	 * Hand back the scripted results.
	 *
	 * @param string $batch_id Batch to collect.
	 * @return array|WP_Error
	 */
	public function fetch_batch_results( $batch_id ) {
		return $this->results;
	}

	/**
	 * Record that a cancel was asked for.
	 *
	 * @param string $batch_id Batch to stop.
	 * @return true|WP_Error
	 */
	public function cancel_batch( $batch_id ) {
		$this->cancelled[] = $batch_id;

		return true;
	}
}
