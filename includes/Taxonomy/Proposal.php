<?php
/**
 * The tree proposal job.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Taxonomy;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Categorize\Prompt;
use WooProductCategorizerAi\Jobs\Preflight;
use WooProductCategorizerAi\Jobs\Scheduler;
use WooProductCategorizerAi\Jobs\Status;
use WooProductCategorizerAi\Provider\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Samples the catalogue, asks for a tree, and publishes it as the draft.
 *
 * Three chained actions rather than one. The ask alone was measured at 65 seconds
 * against the real catalogue, and some hosts cap max_execution_time at 30; putting
 * the sampling in the same action would mean a retry of the slow part re-ran the
 * catalogue scan as well.
 */
class Proposal {

	/**
	 * The job key this reports under.
	 */
	const JOB = 'taxonomy';

	/**
	 * How many steps the run reports, so the bar means something.
	 */
	const STEPS = 3;

	/**
	 * Ceiling on the answer.
	 *
	 * A tree proposal is mostly reasoning: the measured run spent 8,199 reasoning
	 * tokens to emit a 61-node tree. The cap has to cover both.
	 */
	const MAX_TOKENS = 16000;

	/**
	 * How long a run's sample stays readable.
	 */
	const SAMPLE_TTL = 12 * HOUR_IN_SECONDS;

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
	 * Start a proposal.
	 *
	 * @return void
	 */
	public function start() {
		if ( Status::is_running( self::JOB ) ) {
			return;
		}

		$ready = Preflight::check( self::JOB, $this->settings(), $this->provider );

		if ( is_wp_error( $ready ) ) {
			Status::fail( self::JOB, $ready->get_error_message() );
			return;
		}

		$run = Status::start( self::JOB );

		Status::measure( self::JOB, self::STEPS );
		Scheduler::chain( Scheduler::ACTION_PROPOSE_SAMPLE, array( 'run' => $run ) );
	}

	/**
	 * Collect the sample this proposal is designed from.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function sample( $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			return;
		}

		$settings = $this->settings();
		$names    = Sampler::collect( $settings['scope'] );

		if ( empty( $names ) ) {
			Status::fail( self::JOB, __( 'There are no products to design a category tree from.', 'woo-product-categorizer-ai' ) );
			return;
		}

		/*
		 * The sample goes in a transient rather than an action argument. Action
		 * Scheduler serialises arguments into a database column, and 500 product names
		 * is tens of kilobytes that would be written, read and logged with every step
		 * of the chain. Keyed by run so a superseded proposal's sample is never picked
		 * up by the one that replaced it.
		 */
		set_transient( self::sample_key( $run ), $names, self::SAMPLE_TTL );

		Status::advance( self::JOB, 1 );
		Scheduler::chain( Scheduler::ACTION_PROPOSE_ASK, array( 'run' => $run ) );
	}

	/**
	 * Ask the provider for a tree.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function ask( $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			return;
		}

		$names = get_transient( self::sample_key( $run ) );

		if ( ! is_array( $names ) || empty( $names ) ) {
			Status::fail( self::JOB, __( 'The catalogue sample expired before it could be used. Start the proposal again.', 'woo-product-categorizer-ai' ) );
			return;
		}

		$settings = $this->settings();
		$provider = null === $this->provider ? Providers::get( $settings ) : $this->provider;

		if ( is_wp_error( $provider ) ) {
			Status::fail( self::JOB, $provider->get_error_message() );
			return;
		}

		$depth = (int) $settings['max_depth'];

		$result = $provider->complete(
			array(
				'instructions' => Prompt::proposal_instructions( $depth, Prompt::guidance( $settings ) ),
				'input'        => Prompt::proposal_input( $names ),
				'schema'       => Schema::proposal( $depth ),
				'schema_name'  => 'category_tree',

				/*
				 * Medium, not low. Designing a scheme that covers a catalogue it has only
				 * seen a sample of is the one genuinely hard judgement this plugin asks
				 * for, it happens once, and a poor tree makes every later assignment
				 * worse. This is the wrong place to save a few cents.
				 */
				'effort'       => 'medium',
				'max_tokens'   => self::MAX_TOKENS,

				/*
				 * Keyed on the shape of the request rather than the run. Two proposals at
				 * the same depth share a prefix up to the guidance, so re-proposing after
				 * a wording change still gets some of the benefit.
				 */
				'cache_key'    => 'wpcai-proposal-' . $depth,
			)
		);

		if ( is_wp_error( $result ) ) {
			Status::fail( self::JOB, $result->get_error_message() );
			Scheduler::log( 'error', 'Taxonomy proposal failed: ' . $result->get_error_message() );
			return;
		}

		Draft::store_from_payload(
			$result['payload'],
			array(
				'run'      => $run,
				'model'    => Providers::model( $settings ),
				'depth'    => $depth,
				'guidance' => Prompt::guidance( $settings ),
				'sample'   => count( $names ),
				'usage'    => $result['usage'],
			)
		);

		Status::progress( self::JOB, $result['usage'] );
		Status::advance( self::JOB, 1 );
		Scheduler::chain( Scheduler::ACTION_PROPOSE_FINALISE, array( 'run' => $run ) );
	}

	/**
	 * Close out the proposal.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function finalise( $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			return;
		}

		delete_transient( self::sample_key( $run ) );

		$draft = Draft::get();
		$nodes = $draft['nodes'];

		if ( empty( $nodes ) ) {
			Status::fail( self::JOB, __( 'The model did not return a usable category tree. Try again, or adjust your guidance.', 'woo-product-categorizer-ai' ) );
			return;
		}

		$top    = 0;
		$leaves = count( Draft::leaves( $nodes ) );

		foreach ( $nodes as $node ) {
			if ( '' === $node['parent'] ) {
				++$top;
			}
		}

		Status::advance( self::JOB, 1 );
		Status::finish(
			self::JOB,
			sprintf(
				/* translators: 1: number of top-level categories. 2: total categories. 3: number of categories products can be filed under. */
				__( 'Proposed %1$d top-level categories, %2$d in total, of which %3$d can hold products. Review them below.', 'woo-product-categorizer-ai' ),
				$top,
				count( $nodes ),
				$leaves
			)
		);
	}

	/**
	 * Transient key for a run's sample.
	 *
	 * @param int $run Run identifier.
	 * @return string
	 */
	protected static function sample_key( $run ) {
		return 'wpcai_proposal_sample_' . (int) $run;
	}
}
