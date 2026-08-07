<?php
/**
 * The contract a provider satisfies when it can answer in bulk, offline.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately separate from ProviderInterface rather than bolted onto it.
 *
 * A bulk endpoint is not something every vendor has, and the ones that do have it
 * do not agree on how it works. Folding these four methods into the main interface
 * would oblige a provider with no such endpoint to stub them and fail at runtime;
 * a separate interface lets the plugin ask `instanceof` and simply not offer the
 * mode when the answer is no.
 *
 * The trade being made here is latency for money and robustness: results arrive
 * within hours rather than seconds, at half the price, with no rate limits to back
 * off from and one request to supervise instead of a chain of a hundred and
 * seventy-six.
 */
interface BatchProviderInterface {

	/**
	 * Hand over every request at once.
	 *
	 * @param array $requests Custom id => a request in the shape ProviderInterface::complete() takes.
	 * @return string|\WP_Error An identifier to poll with, or an error.
	 */
	public function submit_batch( array $requests );

	/**
	 * Ask how a submitted batch is getting on.
	 *
	 * The returned state is normalised to the plugin's own vocabulary rather than
	 * the vendor's, so the screen and the job chain never learn a provider's
	 * status names:
	 *
	 * - `pending`   — accepted, not finished. Keep waiting.
	 * - `done`      — results are ready to collect.
	 * - `failed`    — it will not produce results. Give up.
	 * - `cancelled` — somebody stopped it.
	 *
	 * @param string $batch_id Identifier from submit_batch().
	 * @return array|\WP_Error State, plus total/completed/failed counts.
	 */
	public function poll_batch( $batch_id );

	/**
	 * Collect the finished results.
	 *
	 * @param string $batch_id Identifier from submit_batch().
	 * @return array|\WP_Error Custom id => payload and usage, or a per-request error.
	 */
	public function fetch_batch_results( $batch_id );

	/**
	 * Stop a batch that has not finished.
	 *
	 * @param string $batch_id Identifier from submit_batch().
	 * @return true|\WP_Error
	 */
	public function cancel_batch( $batch_id );
}
