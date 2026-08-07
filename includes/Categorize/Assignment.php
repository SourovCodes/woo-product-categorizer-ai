<?php
/**
 * The assignment job.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Categorize;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Jobs\Preflight;
use WooProductCategorizerAi\Jobs\Scheduler;
use WooProductCategorizerAi\Jobs\Status;
use WooProductCategorizerAi\Provider\OpenAiProvider;
use WooProductCategorizerAi\Provider\Providers;
use WooProductCategorizerAi\Taxonomy\Creator;
use WooProductCategorizerAi\Taxonomy\Draft;
use WooProductCategorizerAi\Taxonomy\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the catalogue in batches, asking the model where each product belongs.
 *
 * The governing rule, and the reason several decisions below look
 * over-careful: **a batch failing must not fail the run.** A full pass over this
 * catalogue is roughly 176 requests, so anything that abandons the whole walk on
 * one bad response will abandon it. Only conditions certain to recur — a rejected
 * key, a model the account cannot use, a taxonomy that has gone — take a run down.
 */
class Assignment {

	/**
	 * The job key this reports under.
	 */
	const JOB = 'assign';

	/**
	 * Ceiling on one batch's answer, in tokens.
	 *
	 * Comfortably more than 25 products of `{"ref":"p01","category_id":"c042"}` plus
	 * the reasoning at low effort. The provider grows this itself if a batch ever
	 * does run out of room.
	 */
	const MAX_TOKENS = 4000;

	/**
	 * How long a run's working set stays readable.
	 */
	const OPTIONS_TTL = 12 * HOUR_IN_SECONDS;

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

		$leaves = Creator::leaf_map( Draft::get() );

		if ( empty( $leaves ) ) {
			Status::fail( self::JOB, __( 'No categories have been created from the draft yet. Press "Create categories" first.', 'woo-product-categorizer-ai' ) );
			return;
		}

		$run = Status::start( self::JOB );

		Status::measure( self::JOB, Batch::count( $settings['scope'] ) );

		/*
		 * Everything the run depends on is frozen here, and this is load-bearing twice
		 * over. Saving the settings while a run is walking cannot change the rules half
		 * way through it. And every batch then sends a byte-identical instructions
		 * string, which is the entire prompt-cache lever — rendering it per batch would
		 * work perfectly and silently cost the cache, which shows up only as a bill.
		 */
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
			self::OPTIONS_TTL
		);

		Scheduler::chain(
			Scheduler::ACTION_ASSIGN_BATCH,
			array(
				'after_id' => 0,
				'run'      => $run,
			)
		);
	}

	/**
	 * Categorise one batch.
	 *
	 * @param int $after_id Continue after this product id.
	 * @param int $run      The run this action belongs to.
	 * @return void
	 */
	public function batch( $after_id, $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			return;
		}

		$options = get_transient( self::options_key( $run ) );

		if ( ! is_array( $options ) ) {
			/*
			 * Continuing without the working set would mean asking against a taxonomy
			 * this run never agreed to. Stopping and saying so is the honest option.
			 */
			Status::fail( self::JOB, __( 'This run\'s working set expired before it finished. Start it again.', 'woo-product-categorizer-ai' ) );
			return;
		}

		$ids = Batch::next( $after_id, $options['scope'], $options['batch_size'] );

		if ( empty( $ids ) ) {
			Scheduler::chain( Scheduler::ACTION_ASSIGN_FINALISE, array( 'run' => $run ) );
			return;
		}

		Applier::reset_log_cap();

		$partition = Batch::partition( $ids, $options['override'] );
		$counts    = array( 'skipped_has_cats' => count( $partition['skip'] ) );

		if ( ! empty( $partition['ask'] ) ) {
			$counts = self::merge( $counts, $this->handle( $partition['ask'], $options ) );
		}

		Status::progress( self::JOB, $counts );
		Status::advance( self::JOB, count( $ids ) );

		// A run that failed inside handle() has already said so; do not chain past it.
		if ( ! Status::is_current_run( self::JOB, $run ) || 'running' !== Status::get( self::JOB )['state'] ) {
			return;
		}

		Scheduler::chain(
			Scheduler::ACTION_ASSIGN_BATCH,
			array(
				'after_id' => max( $ids ),
				'run'      => $run,
			)
		);
	}

	/**
	 * Ask about one batch of products and write the answers.
	 *
	 * @param array $ids     Products to ask about.
	 * @param array $options The run's frozen options.
	 * @return array Counters.
	 */
	protected function handle( array $ids, array $options ) {
		$provider = null === $this->provider ? Providers::get( $this->settings() ) : $this->provider;

		if ( is_wp_error( $provider ) ) {
			Status::fail( self::JOB, $provider->get_error_message() );

			return array();
		}

		/*
		 * Short synthetic refs rather than product ids. They cost fewer tokens on every
		 * request of a long run, and a ref that was never sent is obviously wrong,
		 * where a hallucinated product id looks exactly like a real one.
		 */
		$products = array();
		$by_ref   = array();

		foreach ( array_values( $ids ) as $index => $product_id ) {
			$ref = sprintf( 'p%02d', $index + 1 );

			$description = Prompt::describe( $product_id );

			if ( empty( $description ) ) {
				continue;
			}

			$products[ $ref ] = $description;
			$by_ref[ $ref ]   = $product_id;
		}

		if ( empty( $products ) ) {
			return array();
		}

		$result = $provider->complete(
			array(
				'instructions' => $options['instructions'],
				'input'        => Prompt::batch_input( $products ),
				'schema'       => Schema::assignment( array_keys( $options['leaves'] ) ),
				'schema_name'  => 'category_assignments',
				'effort'       => 'low',
				'max_tokens'   => self::MAX_TOKENS,
				'cache_key'    => 'wpcai-assign-' . $options['run'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->handle_error( $result, count( $by_ref ) );
		}

		return self::merge(
			array(
				'calls' => 1,
			),
			self::merge( $result['usage'], $this->write( $result['payload'], $by_ref, $options ) )
		);
	}

	/**
	 * Decide whether a failed batch is the run's problem or just this batch's.
	 *
	 * @param \WP_Error $error The failure.
	 * @param int       $size  How many products were in the batch.
	 * @return array Counters.
	 */
	protected function handle_error( $error, $size ) {
		$status = (int) OpenAiProvider::detail( $error, 'status', '0' );

		/*
		 * A rejected key, a model this account cannot use, an endpoint that is not
		 * there: none of these fix themselves, and grinding through the remaining
		 * batches to report the same thing 170 more times is worse than stopping once
		 * with a message someone can act on.
		 */
		if ( in_array( $status, array( 400, 401, 403, 404 ), true ) ) {
			Status::fail( self::JOB, $error->get_error_message() );

			return array();
		}

		Scheduler::log( 'error', 'A batch failed and was skipped: ' . $error->get_error_message() );

		return array(
			'failed' => $size,
			'calls'  => 1,
		);
	}

	/**
	 * Write the answers for one batch.
	 *
	 * @param array $payload The model's answer.
	 * @param array $by_ref  Ref => product id, for this batch only.
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

			// A ref this batch never sent belongs to nothing that can be written.
			if ( ! isset( $by_ref[ $ref ] ) || isset( $handled[ $ref ] ) ) {
				continue;
			}

			$handled[ $ref ] = true;

			$leaf_id = isset( $row['category_id'] ) && is_scalar( $row['category_id'] ) ? (string) $row['category_id'] : '';

			$outcome = Applier::apply( $by_ref[ $ref ], $leaf_id, $options );

			$counts[ $outcome ] = isset( $counts[ $outcome ] ) ? $counts[ $outcome ] + 1 : 1;
		}

		/*
		 * A product the model simply left out of its answer is in exactly the same
		 * position as one it explicitly declined: still uncategorised, and worth
		 * counting so the total adds up.
		 */
		$missing = count( $by_ref ) - count( $handled );

		if ( $missing > 0 ) {
			$counts['unclassified'] = isset( $counts['unclassified'] ) ? $counts['unclassified'] + $missing : $missing;
		}

		return $counts;
	}

	/**
	 * Close out a run.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function finalise( $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			return;
		}

		$options = get_transient( self::options_key( $run ) );
		$dry_run = is_array( $options ) && ! empty( $options['dry_run'] );
		$status  = Status::get( self::JOB );
		$counts  = (array) $status['counts'];
		$done    = isset( $counts['assigned'] ) ? (int) $counts['assigned'] : 0;

		delete_transient( self::options_key( $run ) );

		if ( ! $dry_run && $done > 0 ) {
			/*
			 * Kept apart from the job status, which the next run overwrites. The revert
			 * needs to know which run to undo long after another has been started.
			 */
			update_option(
				'woo_product_categorizer_ai_last_apply',
				array(
					'run'      => (int) $run,
					'finished' => time(),
					'products' => $done,
				),
				false
			);
		}

		Status::finish( self::JOB, $this->summarise( $counts, $dry_run ) );
	}

	/**
	 * Say how a finished run went.
	 *
	 * @param array $counts  The run's counters.
	 * @param bool  $dry_run Whether it wrote anything.
	 * @return string
	 */
	protected function summarise( array $counts, $dry_run ) {
		$read = static function ( $name ) use ( $counts ) {
			return isset( $counts[ $name ] ) ? (int) $counts[ $name ] : 0;
		};

		$parts = array();

		$parts[] = $dry_run
			? sprintf(
				/* translators: %s: number of products. */
				__( '%s products would have been categorised', 'woo-product-categorizer-ai' ),
				number_format_i18n( $read( 'assigned' ) )
			)
			: sprintf(
				/* translators: %s: number of products. */
				__( '%s products categorised', 'woo-product-categorizer-ai' ),
				number_format_i18n( $read( 'assigned' ) )
			);

		$templates = array(
			/* translators: %s: number of products the model could not place. */
			'unclassified'     => __( '%s left uncategorised', 'woo-product-categorizer-ai' ),
			/* translators: %s: number of products that already had a category. */
			'skipped_has_cats' => __( '%s skipped as already categorised', 'woo-product-categorizer-ai' ),
			/* translators: %s: number of products given an unusable category id. */
			'invalid_id'       => __( '%s given a category that does not exist', 'woo-product-categorizer-ai' ),
			/* translators: %s: number of products that could not be written. */
			'failed'           => __( '%s failed', 'woo-product-categorizer-ai' ),
		);

		foreach ( $templates as $name => $template ) {
			if ( $read( $name ) > 0 ) {
				$parts[] = sprintf( $template, number_format_i18n( $read( $name ) ) );
			}
		}

		return implode( ', ', $parts ) . '.';
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
	 * Transient key for a run's working set.
	 *
	 * @param int $run Run identifier.
	 * @return string
	 */
	protected static function options_key( $run ) {
		return 'wpcai_run_' . (int) $run;
	}
}
