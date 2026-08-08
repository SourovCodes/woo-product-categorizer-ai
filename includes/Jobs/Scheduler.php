<?php
/**
 * Background job routing.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Jobs;

use Exception;
use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Categorize\Assignment;
use WooProductCategorizerAi\Categorize\BulkRun;
use WooProductCategorizerAi\Categorize\Revert;
use WooProductCategorizerAi\Taxonomy\Proposal;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every Action Scheduler hook the plugin queues.
 *
 * The single routing layer: it starts runs, chains their follow-up actions and
 * closes out the ones that die. It never talks to a provider itself — the job
 * classes in Taxonomy\ and Categorize\ do that.
 *
 * Unlike the sibling sync plugin there are no recurring schedules here. Every job
 * is started by hand from the settings screen: you propose a tree once, create the
 * terms once, categorise once. Nothing is on a timer, so none of the
 * reconcile-the-queue-against-the-settings machinery is needed.
 */
class Scheduler {

	/**
	 * Action Scheduler group for everything this plugin queues.
	 */
	const GROUP = 'woo-product-categorizer-ai';

	/**
	 * Claim priority for the plugin's actions, matching Action Scheduler's own default.
	 */
	const PRIORITY_DEFAULT = 10;

	/**
	 * Start a taxonomy proposal.
	 */
	const ACTION_PROPOSE = 'woo_product_categorizer_ai_propose';

	/**
	 * Collect the sample of the catalogue the proposal is built from.
	 */
	const ACTION_PROPOSE_SAMPLE = 'woo_product_categorizer_ai_propose_sample';

	/**
	 * Ask the provider for a tree. Kept apart from the sampling because this one
	 * action holds a request measured at over a minute.
	 */
	const ACTION_PROPOSE_ASK = 'woo_product_categorizer_ai_propose_ask';

	/**
	 * Tidy the returned tree and publish it as the draft.
	 */
	const ACTION_PROPOSE_FINALISE = 'woo_product_categorizer_ai_propose_finalise';

	/**
	 * Start an assignment run.
	 */
	const ACTION_ASSIGN = 'woo_product_categorizer_ai_assign';

	/**
	 * Categorise one batch of products.
	 */
	const ACTION_ASSIGN_BATCH = 'woo_product_categorizer_ai_assign_batch';

	/**
	 * Close out an assignment run. Shared by both modes: the run ends the same way
	 * whether its answers arrived live or in a file.
	 */
	const ACTION_ASSIGN_FINALISE = 'woo_product_categorizer_ai_assign_finalise';

	/**
	 * Start an assignment run through the provider's bulk endpoint.
	 */
	const ACTION_BULK = 'woo_product_categorizer_ai_bulk';

	/**
	 * Describe a slice of the catalogue ready for sending.
	 */
	const ACTION_BULK_BUILD = 'woo_product_categorizer_ai_bulk_build';

	/**
	 * Upload everything and open the batch.
	 */
	const ACTION_BULK_SEND = 'woo_product_categorizer_ai_bulk_send';

	/**
	 * Ask whether the batch is finished. Chains to itself until it is.
	 */
	const ACTION_BULK_POLL = 'woo_product_categorizer_ai_bulk_poll';

	/**
	 * Apply a slice of the finished answers.
	 */
	const ACTION_BULK_COLLECT = 'woo_product_categorizer_ai_bulk_collect';

	/**
	 * Start a revert.
	 */
	const ACTION_REVERT = 'woo_product_categorizer_ai_revert';

	/**
	 * Restore one batch of products to their previous categories.
	 */
	const ACTION_REVERT_BATCH = 'woo_product_categorizer_ai_revert_batch';

	/**
	 * Close out a revert.
	 */
	const ACTION_REVERT_FINALISE = 'woo_product_categorizer_ai_revert_finalise';

	/**
	 * Log source shared by every class in the plugin.
	 *
	 * Lives here rather than on the provider so that a class which never makes a
	 * request still has one place to log to.
	 */
	const LOG_SOURCE = 'woo-product-categorizer-ai';

	/**
	 * Which job each action belongs to.
	 *
	 * Consulted by abandon_run() when Action Scheduler reports a failure: all it
	 * hands over is an action, and the status that needs closing is the job's.
	 *
	 * @param string $hook Action hook.
	 * @return string Job key, or an empty string when the hook is not ours.
	 */
	public static function job_for_action( $hook ) {
		$map = array(
			self::ACTION_PROPOSE          => 'taxonomy',
			self::ACTION_PROPOSE_SAMPLE   => 'taxonomy',
			self::ACTION_PROPOSE_ASK      => 'taxonomy',
			self::ACTION_PROPOSE_FINALISE => 'taxonomy',
			self::ACTION_ASSIGN           => 'assign',
			self::ACTION_ASSIGN_BATCH     => 'assign',
			self::ACTION_ASSIGN_FINALISE  => 'assign',
			self::ACTION_BULK             => 'assign',
			self::ACTION_BULK_BUILD       => 'assign',
			self::ACTION_BULK_SEND        => 'assign',
			self::ACTION_BULK_POLL        => 'assign',
			self::ACTION_BULK_COLLECT     => 'assign',
			self::ACTION_REVERT           => 'revert',
			self::ACTION_REVERT_BATCH     => 'revert',
			self::ACTION_REVERT_FINALISE  => 'revert',
		);

		return isset( $map[ $hook ] ) ? $map[ $hook ] : '';
	}

	/**
	 * Register the plugin's Action Scheduler hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::ACTION_PROPOSE, array( $this, 'handle_propose' ) );
		add_action( self::ACTION_PROPOSE_SAMPLE, array( $this, 'handle_propose_sample' ), 10, 1 );
		add_action( self::ACTION_PROPOSE_ASK, array( $this, 'handle_propose_ask' ), 10, 1 );
		add_action( self::ACTION_PROPOSE_FINALISE, array( $this, 'handle_propose_finalise' ), 10, 1 );
		add_action( self::ACTION_ASSIGN, array( $this, 'handle_assign' ) );
		add_action( self::ACTION_ASSIGN_BATCH, array( $this, 'handle_assign_batch' ), 10, 2 );
		add_action( self::ACTION_ASSIGN_FINALISE, array( $this, 'handle_assign_finalise' ), 10, 1 );
		add_action( self::ACTION_BULK, array( $this, 'handle_bulk' ) );
		add_action( self::ACTION_BULK_BUILD, array( $this, 'handle_bulk_build' ), 10, 3 );
		add_action( self::ACTION_BULK_SEND, array( $this, 'handle_bulk_send' ), 10, 2 );
		add_action( self::ACTION_BULK_POLL, array( $this, 'handle_bulk_poll' ), 10, 1 );
		add_action( self::ACTION_BULK_COLLECT, array( $this, 'handle_bulk_collect' ), 10, 2 );
		add_action( self::ACTION_REVERT, array( $this, 'handle_revert' ) );
		add_action( self::ACTION_REVERT_BATCH, array( $this, 'handle_revert_batch' ), 10, 2 );
		add_action( self::ACTION_REVERT_FINALISE, array( $this, 'handle_revert_finalise' ), 10, 1 );

		/*
		 * A dead chain cannot report its own failure: the action that would have
		 * called finish() or fail() is the one that just died. Without these three,
		 * a crashed run reads as "running" until STALE_AFTER expires six hours later,
		 * during which the screen lies and trigger() refuses to start it again.
		 */
		add_action( 'action_scheduler_failed_execution', array( $this, 'handle_failed_execution' ), 10, 2 );
		add_action( 'action_scheduler_failed_action', array( $this, 'handle_timed_out_action' ), 10, 1 );
		add_action( 'action_scheduler_unexpected_shutdown', array( $this, 'handle_unexpected_shutdown' ), 10, 2 );
	}

	/**
	 * The jobs the settings screen offers a button for.
	 *
	 * One registry, iterated by the jobs table, the run handler and the progress
	 * poll alike, so a job cannot appear in one and be missing from another.
	 *
	 * The revert is marked hidden: it is started the same way as the others and has
	 * to be here for trigger() to accept it, but it belongs beside the thing it
	 * undoes rather than in a list of things to start.
	 *
	 * @return array Job key => label, description, starting action and visibility.
	 */
	public static function get_jobs() {
		return array(
			'taxonomy' => array(
				'label'       => __( 'Propose a category tree', 'woo-product-categorizer-ai' ),
				'description' => __( 'Reads a representative sample of your catalogue and asks the model to design a category tree. Nothing is created until you review it and press Create categories.', 'woo-product-categorizer-ai' ),
				'action'      => self::ACTION_PROPOSE,
			),
			'assign'   => array(
				'label'       => __( 'Categorise the catalogue', 'woo-product-categorizer-ai' ),
				'description' => __( 'Files every product in scope under the category that fits it best. Uses the settings above as they are saved when the run starts.', 'woo-product-categorizer-ai' ),

				/*
				 * Which action starts this depends on the mode, and it is read when the
				 * button is pressed rather than baked in — the two modes are one job done
				 * two ways, sharing a status, a lock and a finaliser, so only one of them
				 * can ever be in flight.
				 */
				'action'      => Settings::uses_bulk_mode() ? self::ACTION_BULK : self::ACTION_ASSIGN,
			),
			'revert'   => array(
				'label'       => __( 'Undo the last run', 'woo-product-categorizer-ai' ),
				'description' => __( 'Puts every product the last run touched back to the categories it had before.', 'woo-product-categorizer-ai' ),
				'action'      => self::ACTION_REVERT,
				'hidden'      => true,
			),
		);
	}

	/**
	 * Close out any run whose chain has died without saying so.
	 *
	 * The three Action Scheduler failure hooks catch a run that died where Action
	 * Scheduler could see it: a thrown exception, a fatal, an action given up on.
	 * They cannot catch a chain that simply stopped — a successor that was never
	 * queued, or a queue that stopped being processed at all — and in that case
	 * nothing is left to report an outcome. Those runs are reaped here, on the
	 * timeout, by whoever next looks at the job.
	 *
	 * A batch sitting at the provider is deliberately exempt. It may legitimately
	 * wait a full day, four times longer than the timeout allows a run to look
	 * alive, and reaping it would report a healthy run as broken and strand the
	 * record that the Cancel button needs. That case already has its own way out.
	 *
	 * @return string[] Job keys that were closed out.
	 */
	public static function reap_stranded_runs() {
		$reaped = array();
		$flight = BulkRun::in_flight();

		foreach ( array_keys( self::get_jobs() ) as $job ) {
			if ( ! empty( $flight ) && BulkRun::JOB === $job ) {
				continue;
			}

			if ( Status::reap( $job ) ) {
				$reaped[] = $job;

				self::log( 'error', sprintf( '%s run reaped: it stopped without reporting an outcome.', $job ) );
			}
		}

		return $reaped;
	}

	/**
	 * Queue a job to run immediately.
	 *
	 * @param string $job Job key.
	 * @return true|\WP_Error True when the job was queued.
	 */
	public static function trigger( $job ) {
		$jobs = self::get_jobs();

		if ( ! isset( $jobs[ $job ] ) ) {
			return new \WP_Error( 'wpcai_unknown_job', __( 'That job does not exist.', 'woo-product-categorizer-ai' ) );
		}

		if ( ! self::is_available() ) {
			return new \WP_Error( 'wpcai_no_scheduler', __( 'Action Scheduler is not available, so background jobs cannot run.', 'woo-product-categorizer-ai' ) );
		}

		if ( Status::is_running( $job ) ) {
			return new \WP_Error( 'wpcai_already_running', __( 'That job is already running.', 'woo-product-categorizer-ai' ) );
		}

		/*
		 * A batch may legitimately sit at the provider for a full day, which is four
		 * times longer than Status::STALE_AFTER allows a run to look alive. Left to the
		 * staleness check alone, the Run button would quietly come back after six hours
		 * and a second press would open a second batch over the same products — paying
		 * twice for two sets of answers that then fight over what to write.
		 */
		$flight = BulkRun::in_flight();

		if ( ! empty( $flight ) && BulkRun::JOB === $job ) {
			return new \WP_Error(
				'wpcai_batch_in_flight',
				__( 'A batch for this job is still with the provider. Wait for it, or cancel it first.', 'woo-product-categorizer-ai' )
			);
		}

		as_enqueue_async_action( $jobs[ $job ]['action'], array(), self::GROUP, false, self::PRIORITY_DEFAULT );

		return true;
	}

	/**
	 * Start a taxonomy proposal.
	 *
	 * @return void
	 */
	public function handle_propose() {
		( new Proposal() )->start();
	}

	/**
	 * Collect the catalogue sample.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function handle_propose_sample( $run = 0 ) {
		( new Proposal() )->sample( (int) $run );
	}

	/**
	 * Ask the provider for a tree.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function handle_propose_ask( $run = 0 ) {
		( new Proposal() )->ask( (int) $run );
	}

	/**
	 * Close out the proposal.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function handle_propose_finalise( $run = 0 ) {
		( new Proposal() )->finalise( (int) $run );
	}

	/**
	 * Start an assignment run.
	 *
	 * @return void
	 */
	public function handle_assign() {
		( new Assignment() )->start();
	}

	/**
	 * Categorise one batch of products.
	 *
	 * @param int $after_id Continue after this product id.
	 * @param int $run      The run this action belongs to.
	 * @return void
	 */
	public function handle_assign_batch( $after_id = 0, $run = 0 ) {
		/*
		 * Every term write in a batch walks the ancestors to recount them, so across
		 * 4,386 products this is the single largest avoidable cost in the plugin.
		 * Deferred per batch rather than per run, so an action that dies part way
		 * through does not leave counting suspended site-wide.
		 */
		wp_defer_term_counting( true );

		( new Assignment() )->batch( (int) $after_id, (int) $run );

		wp_defer_term_counting( false );
	}

	/**
	 * Close out an assignment run.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function handle_assign_finalise( $run = 0 ) {
		( new Assignment() )->finalise( (int) $run );
	}

	/**
	 * Start an assignment run through the bulk endpoint.
	 *
	 * @return void
	 */
	public function handle_bulk() {
		( new BulkRun() )->start();
	}

	/**
	 * Describe a slice of the catalogue.
	 *
	 * @param int $after_id Continue after this product id.
	 * @param int $chunk    Which chunk this is.
	 * @param int $run      The run this action belongs to.
	 * @return void
	 */
	public function handle_bulk_build( $after_id = 0, $chunk = 0, $run = 0 ) {
		( new BulkRun() )->build( (int) $after_id, (int) $chunk, (int) $run );
	}

	/**
	 * Upload everything and open the batch.
	 *
	 * @param int $chunks How many build chunks there were.
	 * @param int $run    The run this action belongs to.
	 * @return void
	 */
	public function handle_bulk_send( $chunks = 0, $run = 0 ) {
		( new BulkRun() )->send( (int) $chunks, (int) $run );
	}

	/**
	 * Ask whether the batch is finished.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function handle_bulk_poll( $run = 0 ) {
		( new BulkRun() )->poll( (int) $run );
	}

	/**
	 * Apply a slice of the finished answers.
	 *
	 * @param int $offset Which results to start from.
	 * @param int $run    The run this action belongs to.
	 * @return void
	 */
	public function handle_bulk_collect( $offset = 0, $run = 0 ) {
		wp_defer_term_counting( true );

		( new BulkRun() )->collect( (int) $offset, (int) $run );

		wp_defer_term_counting( false );
	}

	/**
	 * Start a revert.
	 *
	 * @return void
	 */
	public function handle_revert() {
		( new Revert() )->start();
	}

	/**
	 * Restore one batch of products.
	 *
	 * @param int $after_id Continue after this product id.
	 * @param int $run      The run this action belongs to.
	 * @return void
	 */
	public function handle_revert_batch( $after_id = 0, $run = 0 ) {
		wp_defer_term_counting( true );

		( new Revert() )->batch( (int) $after_id, (int) $run );

		wp_defer_term_counting( false );
	}

	/**
	 * Close out a revert.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function handle_revert_finalise( $run = 0 ) {
		( new Revert() )->finalise( (int) $run );
	}

	/**
	 * Close out a run whose action threw.
	 *
	 * @param int       $action_id Action that failed.
	 * @param Exception $exception What it threw.
	 * @return void
	 */
	public function handle_failed_execution( $action_id = 0, $exception = null ) {
		$reason = $exception instanceof Exception ? $exception->getMessage() : __( 'an unknown error', 'woo-product-categorizer-ai' );

		$this->abandon_run(
			$action_id,
			/* translators: %s: error message. */
			sprintf( __( 'The run stopped because a background task failed: %s', 'woo-product-categorizer-ai' ), $reason )
		);
	}

	/**
	 * Close out a run whose action ran past Action Scheduler's timeout.
	 *
	 * @param int $action_id Action that was given up on.
	 * @return void
	 */
	public function handle_timed_out_action( $action_id = 0 ) {
		$this->abandon_run(
			$action_id,
			__( 'The run stopped because a background task took too long and was abandoned.', 'woo-product-categorizer-ai' )
		);
	}

	/**
	 * Close out a run whose action ended the request outright.
	 *
	 * @param int   $action_id Action that was running.
	 * @param array $error     The PHP error that ended the request.
	 * @return void
	 */
	public function handle_unexpected_shutdown( $action_id = 0, $error = array() ) {
		$reason = isset( $error['message'] ) ? (string) $error['message'] : __( 'a fatal error', 'woo-product-categorizer-ai' );

		$this->abandon_run(
			$action_id,
			/* translators: %s: error message. */
			sprintf( __( 'The run stopped because a background task ended unexpectedly: %s', 'woo-product-categorizer-ai' ), $reason )
		);
	}

	/**
	 * Record that a run died with the action that was carrying it.
	 *
	 * @param int    $action_id Action that failed.
	 * @param string $message   Reason to record against the run.
	 * @return void
	 */
	protected function abandon_run( $action_id, $message ) {
		$action = self::fetch_action( absint( $action_id ) );

		if ( null === $action ) {
			return;
		}

		$job = self::job_for_action( $action->get_hook() );

		if ( '' === $job ) {
			return;
		}

		$args = (array) $action->get_args();

		/*
		 * A superseded action failing says nothing about the run that replaced it, and
		 * failing that one on its behalf would report a healthy run as broken.
		 */
		if ( isset( $args['run'] ) && ! Status::is_current_run( $job, (int) $args['run'] ) ) {
			return;
		}

		// The job may already have recorded its own, better, reason for stopping.
		if ( 'running' !== Status::get( $job )['state'] ) {
			return;
		}

		Status::fail( $job, $message );

		self::log( 'error', sprintf( '%s run abandoned: %s', $job, $message ) );
	}

	/**
	 * Read a queued action back from Action Scheduler.
	 *
	 * @param int $action_id Action to read.
	 * @return \ActionScheduler_Action|null The action, or null when it cannot be read.
	 */
	protected static function fetch_action( $action_id ) {
		if ( ! $action_id || ! class_exists( '\ActionScheduler' ) ) {
			return null;
		}

		try {
			$action = \ActionScheduler::store()->fetch_action( $action_id );
		} catch ( Exception $exception ) {
			return null;
		}

		// A missing action comes back as a null action rather than as an error.
		if ( ! $action instanceof \ActionScheduler_Action || ! $action->get_hook() ) {
			return null;
		}

		return $action;
	}

	/**
	 * Queue a follow-up action for the current run.
	 *
	 * @param string $hook Action hook to queue.
	 * @param array  $args Arguments to pass along.
	 * @return void
	 */
	public static function chain( $hook, array $args ) {
		if ( ! self::is_available() ) {
			return;
		}

		as_enqueue_async_action( $hook, $args, self::GROUP, false, self::PRIORITY_DEFAULT );
	}

	/**
	 * Queue a follow-up action, but not yet.
	 *
	 * Used when a batch has just been rate-limited. The provider's own retries have
	 * already been spent by then, so continuing immediately would send the next
	 * batch into the same closed door — and with 176 of them, a run that keeps going
	 * at full speed through a rate limit burns the entire catalogue against it. The
	 * delay backs the run off as a whole rather than one request at a time.
	 *
	 * @param int    $delay Seconds to wait.
	 * @param string $hook  Action hook to queue.
	 * @param array  $args  Arguments to pass along.
	 * @return void
	 */
	public static function chain_after( $delay, $hook, array $args ) {
		if ( ! self::is_available() || ! function_exists( 'as_schedule_single_action' ) ) {
			self::chain( $hook, $args );
			return;
		}

		as_schedule_single_action( time() + max( 1, (int) $delay ), $hook, $args, self::GROUP, false, self::PRIORITY_DEFAULT );
	}

	/**
	 * Cancel everything this plugin has queued.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( '', array(), self::GROUP );
	}

	/**
	 * Write to the WooCommerce log under this plugin's source.
	 *
	 * Static because the callers are spread across job classes that have no reason
	 * to hold a scheduler. Guarded because the logger comes from WooCommerce, and a
	 * job must not fatal on a site where it has gone missing.
	 *
	 * Never log a request body — it carries the catalogue — and never log headers,
	 * because they carry the API key.
	 *
	 * @param string $level   One of the WC_Log_Levels constants.
	 * @param string $message What happened.
	 * @return void
	 */
	public static function log( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}

	/**
	 * Determine whether Action Scheduler is loaded.
	 *
	 * It ships inside WooCommerce, but guard anyway so the plugin degrades to a
	 * no-op instead of fatally erroring if that ever changes.
	 *
	 * @return bool True when the Action Scheduler API is available.
	 */
	public static function is_available() {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}
}
