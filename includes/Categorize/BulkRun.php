<?php
/**
 * The assignment run, done through the provider's bulk endpoint.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Categorize;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Jobs\Preflight;
use WooProductCategorizerAi\Jobs\Scheduler;
use WooProductCategorizerAi\Jobs\Status;
use WooProductCategorizerAi\Provider\BatchProviderInterface;
use WooProductCategorizerAi\Provider\Providers;
use WooProductCategorizerAi\Taxonomy\Creator;
use WooProductCategorizerAi\Taxonomy\Draft;
use WooProductCategorizerAi\Taxonomy\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the whole catalogue over at once and comes back for the answers later.
 *
 * The same work as Assignment, arranged around a provider that answers offline:
 * half the price, no rate limits to back off from, and one thing to supervise
 * instead of a chain of a hundred and seventy-six. What it costs is immediacy —
 * results arrive within hours rather than minutes.
 *
 * The chain has five stages because each one is a different kind of slow, and
 * putting any two of them in the same action would make a retry redo the other:
 *
 *   start → build (chunked) → send → poll (self-chaining) → collect (chunked) → finalise
 */
class BulkRun {

	/**
	 * The job key this reports under. Deliberately the same as the live run's:
	 * they are two ways of doing one thing, and only one may be in flight.
	 */
	const JOB = Assignment::JOB;

	/**
	 * Option holding the batch currently in flight.
	 */
	const OPTION_KEY = 'woo_product_categorizer_ai_batch';

	/**
	 * How many products are described per build action.
	 *
	 * Reading a product costs a handful of queries, so this is sized to keep one
	 * action comfortably short rather than to any provider limit.
	 */
	const BUILD_CHUNK = 500;

	/**
	 * How many finished requests are applied per collect action.
	 */
	const COLLECT_CHUNK = 20;

	/**
	 * How long between asking whether the batch is done.
	 *
	 * The window is 24 hours and small batches finish in a minute, so this is a
	 * compromise: often enough that a quick job is not left sitting, rare enough
	 * that a slow one costs a few hundred requests rather than tens of thousands.
	 */
	const POLL_INTERVAL = 120;

	/**
	 * How long the working set survives.
	 *
	 * Longer than the provider's own window, so a batch that takes the full 24
	 * hours still finds the map it needs to apply its answers.
	 */
	const STATE_TTL = 36 * HOUR_IN_SECONDS;

	/**
	 * The provider to ask, injected for tests.
	 *
	 * @var mixed
	 */
	protected $provider;

	/**
	 * The settings this run reads, injected for tests.
	 *
	 * @var array|null
	 */
	protected $settings;

	/**
	 * Construct the job.
	 *
	 * @param mixed      $provider Optional provider override.
	 * @param array|null $settings Optional settings override.
	 */
	public function __construct( $provider = null, $settings = null ) {
		$this->provider = $provider;
		$this->settings = $settings;
	}

	/**
	 * The settings this run should use.
	 *
	 * @return array
	 */
	protected function settings() {
		return is_array( $this->settings ) ? $this->settings : Settings::get_settings();
	}

	/**
	 * The provider this run should ask.
	 *
	 * @return BatchProviderInterface|WP_Error
	 */
	protected function provider() {
		$provider = null === $this->provider ? Providers::get( $this->settings() ) : $this->provider;

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		if ( ! $provider instanceof BatchProviderInterface ) {
			return new WP_Error(
				'wpcai_no_batch_support',
				__( 'This provider cannot accept a whole catalogue at once. Switch back to sending the products live.', 'woo-product-categorizer-ai' )
			);
		}

		return $provider;
	}

	/**
	 * Whether this action still belongs to a run that is going.
	 *
	 * Stricter than the stale-run fence on its own. A bulk run's chain outlives its
	 * own actions by hours, so an Action Scheduler retry of a stage that already
	 * completed is a real possibility — and a re-run of the final collect would
	 * otherwise find the working set correctly tidied away and report a finished
	 * run as failed.
	 *
	 * @param int $run The run an action is carrying.
	 * @return bool True when there is still work to do for it.
	 */
	protected function is_live( $run ) {
		return Status::is_current_run( self::JOB, $run ) && 'running' === Status::get( self::JOB )['state'];
	}

	/**
	 * What is known about the batch in flight, if any.
	 *
	 * @return array The record, or an empty array.
	 */
	public static function in_flight() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) || empty( $stored['batch_id'] ) ) {
			return array();
		}

		return wp_parse_args(
			$stored,
			array(
				'batch_id'  => '',
				'run'       => 0,
				'submitted' => 0,
				'state'     => 'pending',
				'total'     => 0,
				'completed' => 0,
				'failed'    => 0,
			)
		);
	}

	/**
	 * Start a run.
	 *
	 * @return void
	 */
	public function start() {
		if ( Status::is_running( self::JOB ) ) {
			return;
		}

		$settings = $this->settings();
		$ready    = Preflight::check( self::JOB, $settings, $this->provider );

		if ( is_wp_error( $ready ) ) {
			Status::fail( self::JOB, $ready->get_error_message() );
			return;
		}

		$provider = $this->provider();

		if ( is_wp_error( $provider ) ) {
			Status::fail( self::JOB, $provider->get_error_message() );
			return;
		}

		$leaves = Creator::leaf_map( Draft::get() );

		if ( empty( $leaves ) ) {
			Status::fail( self::JOB, __( 'No categories have been created from the draft yet. Press "Create categories" first.', 'woo-product-categorizer-ai' ) );
			return;
		}

		$run = Status::start( self::JOB );

		Status::measure( self::JOB, Batch::count( $settings['scope'] ) );

		// Frozen for exactly the reasons the live run freezes it, and for longer:
		// this working set has to survive until the provider comes back tomorrow.
		set_transient(
			self::options_key( $run ),
			array(
				'run'          => $run,
				'scope'        => $settings['scope'],
				'override'     => (bool) $settings['override_existing'],
				'dry_run'      => (bool) $settings['dry_run'],
				'batch_size'   => (int) $settings['batch_size'],
				'leaves'       => $leaves,
				'instructions' => Prompt::assignment_instructions( $leaves, Prompt::guidance( $settings ) ),
			),
			self::STATE_TTL
		);

		Scheduler::chain(
			Scheduler::ACTION_BULK_BUILD,
			array(
				'after_id' => 0,
				'chunk'    => 0,
				'run'      => $run,
			)
		);
	}

	/**
	 * Describe the next slice of the catalogue and set it aside for sending.
	 *
	 * @param int $after_id Continue after this product id.
	 * @param int $chunk    Which chunk this is.
	 * @param int $run      The run this action belongs to.
	 * @return void
	 */
	public function build( $after_id, $chunk, $run ) {
		if ( ! $this->is_live( $run ) ) {
			return;
		}

		$options = get_transient( self::options_key( $run ) );

		if ( ! is_array( $options ) ) {
			Status::fail( self::JOB, __( 'This run\'s working set expired before it finished. Start it again.', 'woo-product-categorizer-ai' ) );
			return;
		}

		$ids = Batch::next( $after_id, $options['scope'], self::BUILD_CHUNK );

		if ( empty( $ids ) ) {
			Scheduler::chain(
				Scheduler::ACTION_BULK_SEND,
				array(
					'chunks' => $chunk,
					'run'    => $run,
				)
			);
			return;
		}

		$partition = Batch::partition( $ids, $options['override'] );

		if ( ! empty( $partition['skip'] ) ) {
			Status::progress( self::JOB, array( 'skipped_has_cats' => count( $partition['skip'] ) ) );
		}

		$requests  = array();
		$map       = array();
		$submitted = 0;

		foreach ( array_chunk( $partition['ask'], $options['batch_size'] ) as $index => $slice ) {
			/*
			 * The custom id has to be unique across the whole submission and has to
			 * survive a round trip through the provider, so it carries the chunk and the
			 * slice rather than a bare counter.
			 */
			$custom_id = sprintf( 'c%04d-%04d', $chunk, $index );
			$products  = array();

			foreach ( array_values( $slice ) as $position => $product_id ) {
				$ref         = sprintf( 'p%03d', $position + 1 );
				$description = Prompt::describe( $product_id );

				if ( empty( $description ) ) {
					continue;
				}

				$products[ $ref ]          = $description;
				$map[ $custom_id ][ $ref ] = $product_id;
				++$submitted;
			}

			if ( empty( $products ) ) {
				continue;
			}

			$requests[ $custom_id ] = array(
				'instructions' => $options['instructions'],
				'input'        => Prompt::batch_input( $products ),
				'schema'       => Schema::assignment( array_keys( $options['leaves'] ) ),
				'schema_name'  => 'category_assignments',
				'effort'       => 'low',
				'max_tokens'   => Assignment::MAX_TOKENS,
			);
		}

		/*
		 * Everything this chunk will not be hearing back about is counted off now:
		 * the products skipped as already categorised, and any that could not be
		 * described. Only what actually went into a request is advanced later, when
		 * its answer is applied. Without this the bar measures a total that includes
		 * products nothing will ever advance it past — a successful run over this
		 * catalogue stopped at 88%.
		 */
		$unsent = count( $partition['skip'] ) + ( count( $partition['ask'] ) - $submitted );

		if ( $unsent > 0 ) {
			Status::advance( self::JOB, $unsent );
		}

		/*
		 * One transient per chunk rather than one growing transient. Appending to a
		 * single option would mean unserialising and reserialising a megabyte on every
		 * one of these actions, which is quadratic in the size of the catalogue.
		 */
		set_transient(
			self::chunk_key( $run, $chunk ),
			array(
				'requests' => $requests,
				'map'      => $map,
			),
			self::STATE_TTL
		);

		Scheduler::chain(
			Scheduler::ACTION_BULK_BUILD,
			array(
				'after_id' => max( $ids ),
				'chunk'    => $chunk + 1,
				'run'      => $run,
			)
		);
	}

	/**
	 * Send everything that was built.
	 *
	 * @param int $chunks How many build chunks there were.
	 * @param int $run    The run this action belongs to.
	 * @return void
	 */
	public function send( $chunks, $run ) {
		if ( ! $this->is_live( $run ) ) {
			return;
		}

		$provider = $this->provider();

		if ( is_wp_error( $provider ) ) {
			Status::fail( self::JOB, $provider->get_error_message() );
			return;
		}

		$requests = array();
		$map      = array();

		for ( $chunk = 0; $chunk < (int) $chunks; $chunk++ ) {
			$stored = get_transient( self::chunk_key( $run, $chunk ) );

			if ( ! is_array( $stored ) ) {
				continue;
			}

			$requests += $stored['requests'];
			$map      += $stored['map'];

			delete_transient( self::chunk_key( $run, $chunk ) );
		}

		if ( empty( $requests ) ) {
			$flight = self::in_flight();

			/*
			 * "Nothing to send" normally means everything in scope was skipped, which is
			 * a complete run with no work in it. But it also describes a send that has
			 * already happened — the chunks are consumed as they are read — and
			 * finalising there would close a run whose batch is still at the provider,
			 * stranding it and throwing away answers that have been paid for. Seen for
			 * real when two chains raced each other.
			 */
			if ( ! empty( $flight ) && (int) $flight['run'] === (int) $run ) {
				Scheduler::log( 'warning', 'A second send for this run found nothing left to send; the batch already in flight was left alone.' );
				return;
			}

			Scheduler::chain( Scheduler::ACTION_ASSIGN_FINALISE, array( 'run' => $run ) );
			return;
		}

		// The map is what turns an answer back into a product, so it outlives the send.
		set_transient( self::map_key( $run ), $map, self::STATE_TTL );

		$batch_id = $provider->submit_batch( $requests );

		if ( is_wp_error( $batch_id ) ) {
			Status::fail( self::JOB, $batch_id->get_error_message() );
			return;
		}

		update_option(
			self::OPTION_KEY,
			array(
				'batch_id'  => $batch_id,
				'run'       => $run,
				'submitted' => time(),
				'state'     => 'pending',
				'total'     => count( $requests ),
				'completed' => 0,
				'failed'    => 0,
			),
			false
		);

		Status::progress( self::JOB, array( 'requests' => count( $requests ) ) );
		Scheduler::chain_after( self::POLL_INTERVAL, Scheduler::ACTION_BULK_POLL, array( 'run' => $run ) );
	}

	/**
	 * Ask whether the batch is done, and keep asking until it is.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function poll( $run ) {
		if ( ! $this->is_live( $run ) ) {
			return;
		}

		$flight = self::in_flight();

		if ( empty( $flight ) || (int) $flight['run'] !== (int) $run ) {
			Status::fail( self::JOB, __( 'The batch this run was waiting on is no longer there.', 'woo-product-categorizer-ai' ) );
			return;
		}

		$provider = $this->provider();

		if ( is_wp_error( $provider ) ) {
			Status::fail( self::JOB, $provider->get_error_message() );
			return;
		}

		$state = $provider->poll_batch( $flight['batch_id'] );

		if ( is_wp_error( $state ) ) {
			/*
			 * A poll that could not be made says nothing about the batch. Asking again
			 * later is right; failing a run that is very likely fine is not.
			 */
			Scheduler::log( 'warning', 'Could not read the batch state, will ask again: ' . $state->get_error_message() );
			Scheduler::chain_after( self::POLL_INTERVAL, Scheduler::ACTION_BULK_POLL, array( 'run' => $run ) );
			return;
		}

		update_option( self::OPTION_KEY, array_merge( $flight, $state ), false );

		if ( 'pending' === $state['state'] ) {
			Scheduler::chain_after( self::POLL_INTERVAL, Scheduler::ACTION_BULK_POLL, array( 'run' => $run ) );
			return;
		}

		if ( 'cancelled' === $state['state'] ) {
			delete_option( self::OPTION_KEY );
			Status::fail( self::JOB, __( 'The batch was cancelled. No products were changed.', 'woo-product-categorizer-ai' ) );
			return;
		}

		if ( 'failed' === $state['state'] ) {
			delete_option( self::OPTION_KEY );
			Status::fail(
				self::JOB,
				sprintf(
					/* translators: %s: the state the provider reported, for example "expired". */
					__( 'The batch did not complete (%s). No products were changed.', 'woo-product-categorizer-ai' ),
					$state['raw']
				)
			);
			return;
		}

		Scheduler::chain(
			Scheduler::ACTION_BULK_COLLECT,
			array(
				'offset' => 0,
				'run'    => $run,
			)
		);
	}

	/**
	 * Apply the results, a slice at a time.
	 *
	 * @param int $offset Which results to start from.
	 * @param int $run    The run this action belongs to.
	 * @return void
	 */
	public function collect( $offset, $run ) {
		if ( ! $this->is_live( $run ) ) {
			return;
		}

		$options = get_transient( self::options_key( $run ) );
		$map     = get_transient( self::map_key( $run ) );

		if ( ! is_array( $options ) || ! is_array( $map ) ) {
			Status::fail( self::JOB, __( 'This run\'s working set expired before its answers could be applied. Start it again.', 'woo-product-categorizer-ai' ) );
			return;
		}

		$results = $this->results( $run );

		if ( is_wp_error( $results ) ) {
			Status::fail( self::JOB, $results->get_error_message() );
			return;
		}

		$ids   = array_keys( $map );
		$slice = array_slice( $ids, (int) $offset, self::COLLECT_CHUNK );

		if ( empty( $slice ) ) {
			delete_transient( self::results_key( $run ) );
			delete_transient( self::map_key( $run ) );
			delete_option( self::OPTION_KEY );

			Scheduler::chain( Scheduler::ACTION_ASSIGN_FINALISE, array( 'run' => $run ) );
			return;
		}

		Applier::reset_log_cap();

		$counts = array();

		foreach ( $slice as $custom_id ) {
			$by_ref = $map[ $custom_id ];
			$result = isset( $results[ $custom_id ] ) ? $results[ $custom_id ] : null;

			if ( null === $result || is_wp_error( $result ) ) {
				// One request's worth of products, unanswered. The rest are unaffected.
				$counts['failed'] = ( isset( $counts['failed'] ) ? $counts['failed'] : 0 ) + count( $by_ref );
				continue;
			}

			$counts = self::merge( $counts, $result['usage'] );
			$counts = self::merge( $counts, $this->write( $result['payload'], $by_ref, $options ) );
		}

		Status::progress( self::JOB, $counts );
		Status::advance( self::JOB, array_sum( array_map( 'count', array_intersect_key( $map, array_flip( $slice ) ) ) ) );

		Scheduler::chain(
			Scheduler::ACTION_BULK_COLLECT,
			array(
				'offset' => (int) $offset + self::COLLECT_CHUNK,
				'run'    => $run,
			)
		);
	}

	/**
	 * The batch's results, downloaded once and kept for the collecting.
	 *
	 * Fetched on the first collect action and cached, because the file is the whole
	 * catalogue's worth of answers and downloading it again for every slice would
	 * be the most expensive thing the plugin does.
	 *
	 * @param int $run The run.
	 * @return array|WP_Error Custom id => result.
	 */
	protected function results( $run ) {
		$cached = get_transient( self::results_key( $run ) );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$flight = self::in_flight();

		if ( empty( $flight ) ) {
			return new WP_Error( 'wpcai_no_batch', __( 'There is no batch to collect.', 'woo-product-categorizer-ai' ) );
		}

		$provider = $this->provider();

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$results = $provider->fetch_batch_results( $flight['batch_id'] );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		set_transient( self::results_key( $run ), $results, self::STATE_TTL );

		return $results;
	}

	/**
	 * Write the answers for one request.
	 *
	 * @param array $payload The model's answer.
	 * @param array $by_ref  Ref => product id, for this request only.
	 * @param array $options The run's frozen options.
	 * @return array Counters.
	 */
	protected function write( array $payload, array $by_ref, array $options ) {
		$rows    = isset( $payload['assignments'] ) && is_array( $payload['assignments'] ) ? $payload['assignments'] : array();
		$counts  = array();
		$handled = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$ref = isset( $row['ref'] ) && is_scalar( $row['ref'] ) ? (string) $row['ref'] : '';

			if ( ! isset( $by_ref[ $ref ] ) || isset( $handled[ $ref ] ) ) {
				continue;
			}

			$handled[ $ref ] = true;

			$leaf_id = isset( $row['category_id'] ) && is_scalar( $row['category_id'] ) ? (string) $row['category_id'] : '';
			$outcome = Applier::apply( $by_ref[ $ref ], $leaf_id, $options );

			$counts[ $outcome ] = isset( $counts[ $outcome ] ) ? $counts[ $outcome ] + 1 : 1;
		}

		$missing = count( $by_ref ) - count( $handled );

		if ( $missing > 0 ) {
			$counts['unclassified'] = ( isset( $counts['unclassified'] ) ? $counts['unclassified'] : 0 ) + $missing;
		}

		return $counts;
	}

	/**
	 * Stop a batch that is still in flight.
	 *
	 * @return true|WP_Error
	 */
	public function cancel() {
		$flight = self::in_flight();

		if ( empty( $flight ) ) {
			return new WP_Error( 'wpcai_nothing_in_flight', __( 'There is no batch waiting to be cancelled.', 'woo-product-categorizer-ai' ) );
		}

		$provider = $this->provider();

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$cancelled = $provider->cancel_batch( $flight['batch_id'] );

		if ( is_wp_error( $cancelled ) ) {
			return $cancelled;
		}

		/*
		 * The status is closed here rather than left to the next poll. Cancelling is
		 * something a person just did and expects to see the result of, and the poll
		 * that would otherwise report it may be two minutes away.
		 */
		delete_option( self::OPTION_KEY );
		Status::fail( self::JOB, __( 'The batch was cancelled. No products were changed.', 'woo-product-categorizer-ai' ) );

		return true;
	}

	/**
	 * Forget the batch on the books without asking the provider anything.
	 *
	 * Separate from cancel(), which deliberately keeps the record when the provider
	 * could not be told to stop — there the batch really is still running and the
	 * button has to remain pressable. This is for the case where nothing will ever
	 * poll it again regardless, so a record that cannot be acted on is only in the
	 * way.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Add two sets of counters together.
	 *
	 * @param array $left  First set.
	 * @param array $right Second set.
	 * @return array The sum.
	 */
	protected static function merge( array $left, array $right ) {
		foreach ( $right as $name => $value ) {
			$left[ $name ] = ( isset( $left[ $name ] ) ? (int) $left[ $name ] : 0 ) + (int) $value;
		}

		return $left;
	}

	/**
	 * Transient key for a run's frozen options.
	 *
	 * @param int $run Run identifier.
	 * @return string
	 */
	protected static function options_key( $run ) {
		return 'wpcai_run_' . (int) $run;
	}

	/**
	 * Transient key for a build chunk.
	 *
	 * @param int $run   Run identifier.
	 * @param int $chunk Chunk number.
	 * @return string
	 */
	protected static function chunk_key( $run, $chunk ) {
		return 'wpcai_bulk_chunk_' . (int) $run . '_' . (int) $chunk;
	}

	/**
	 * Transient key for a run's ref map.
	 *
	 * @param int $run Run identifier.
	 * @return string
	 */
	protected static function map_key( $run ) {
		return 'wpcai_bulk_map_' . (int) $run;
	}

	/**
	 * Transient key for a run's downloaded results.
	 *
	 * @param int $run Run identifier.
	 * @return string
	 */
	protected static function results_key( $run ) {
		return 'wpcai_bulk_results_' . (int) $run;
	}
}
