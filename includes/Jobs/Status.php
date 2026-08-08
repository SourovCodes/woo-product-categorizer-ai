<?php
/**
 * Per-job run status.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Records how each job last went, for display on the settings screen.
 *
 * Kept in a single option rather than one per job so a run never has to read or
 * write more than one row.
 */
class Status {

	/**
	 * Option holding the status of every job.
	 */
	const OPTION_KEY = 'woo_product_categorizer_ai_job_status';

	/**
	 * Mark a job as started.
	 *
	 * @param string $job Job key, for example "products".
	 * @return int The run's start timestamp, used to identify the run.
	 */
	public static function start( $job ) {
		$started = time();

		self::write(
			$job,
			array(
				'state'     => 'running',
				'started'   => $started,
				'finished'  => 0,
				'message'   => '',
				'counts'    => array(),
				'total'     => 0,
				'processed' => 0,
			)
		);

		return $started;
	}

	/**
	 * Record how much work the run has in front of it.
	 *
	 * Kept apart from the counts, which say what happened to each record rather than
	 * where the run has got to. Every chunked job already knows this number the moment
	 * it starts — how many products are in scope, how many products the last run
	 * touched — so the only thing missing was somewhere to put it.
	 *
	 * A total of zero means "not known", which is how the screen tells a run it can
	 * measure from one it can only report as busy.
	 *
	 * @param string $job   Job key.
	 * @param int    $total Records this run expects to handle.
	 * @return void
	 */
	public static function measure( $job, $total ) {
		$current          = self::get( $job );
		$current['total'] = max( 0, (int) $total );

		self::write( $job, $current );
	}

	/**
	 * Move the run forward by the records just handled.
	 *
	 * @param string $job  Job key.
	 * @param int    $done Records handled since the last call.
	 * @return void
	 */
	public static function advance( $job, $done ) {
		$current              = self::get( $job );
		$current['processed'] = (int) $current['processed'] + max( 0, (int) $done );

		self::write( $job, $current );
	}

	/**
	 * How far through a run is, as a percentage.
	 *
	 * Clamped at 100 and never allowed below 0. The total is what the source claimed
	 * at the start — the API's totalCount is a promise about a catalogue that can
	 * change underneath the walk, and import_page() deliberately advances by the rows
	 * actually returned — so the two can disagree, and a bar that reads 104% is worse
	 * than one that sits at 100 for a moment.
	 *
	 * @param array $status Status array from get().
	 * @return int|null Percentage, or null when the run cannot be measured.
	 */
	public static function percentage( array $status ) {
		$total = isset( $status['total'] ) ? (int) $status['total'] : 0;

		if ( $total < 1 ) {
			return null;
		}

		$processed = isset( $status['processed'] ) ? (int) $status['processed'] : 0;

		return (int) max( 0, min( 100, floor( ( $processed / $total ) * 100 ) ) );
	}

	/**
	 * Update the running totals of an in-flight job.
	 *
	 * @param string $job    Job key.
	 * @param array  $counts Counters to merge into the stored totals.
	 * @return void
	 */
	public static function progress( $job, array $counts ) {
		$current = self::get( $job );

		foreach ( $counts as $name => $value ) {
			$existing                   = isset( $current['counts'][ $name ] ) ? (int) $current['counts'][ $name ] : 0;
			$current['counts'][ $name ] = $existing + (int) $value;
		}

		self::write( $job, $current );
	}

	/**
	 * Mark a job as finished successfully.
	 *
	 * @param string $job     Job key.
	 * @param string $message Optional summary message.
	 * @return void
	 */
	public static function finish( $job, $message = '' ) {
		$current             = self::get( $job );
		$current['state']    = 'success';
		$current['finished'] = time();
		$current['message']  = $message;

		self::write( $job, $current );
	}

	/**
	 * Mark a job as failed.
	 *
	 * @param string $job     Job key.
	 * @param string $message Reason for the failure.
	 * @return void
	 */
	public static function fail( $job, $message ) {
		$current             = self::get( $job );
		$current['state']    = 'failed';
		$current['finished'] = time();
		$current['message']  = $message;

		self::write( $job, $current );
	}

	/**
	 * Mark every in-flight job as failed.
	 *
	 * A run only ever leaves the "running" state from inside one of its own chained
	 * actions. Cancelling the queue therefore strands the status: nothing is left to
	 * call finish() or fail(), and the job reads as running until STALE_AFTER expires
	 * — six hours during which the admin screen lies and Scheduler::trigger() refuses
	 * every attempt to start it again. Whoever cancels the work owns closing the
	 * status behind it.
	 *
	 * The raw state is what is tested rather than is_running(), so a run that is
	 * already stale is closed out too instead of being left to look in-flight.
	 *
	 * @param string $message Reason to record against each abandoned run.
	 * @return array Job keys that were abandoned.
	 */
	public static function abandon( $message ) {
		$all = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $all ) ) {
			return array();
		}

		$abandoned = array();

		foreach ( $all as $job => $status ) {
			if ( ! is_array( $status ) || ! isset( $status['state'] ) || 'running' !== $status['state'] ) {
				continue;
			}

			$status['state']    = 'failed';
			$status['finished'] = time();
			$status['message']  = $message;

			$all[ $job ] = wp_parse_args( $status, self::defaults() );
			$abandoned[] = $job;
		}

		// One write for the lot; the option holds every job.
		if ( ! empty( $abandoned ) ) {
			update_option( self::OPTION_KEY, $all, false );
		}

		return $abandoned;
	}

	/**
	 * How long a job may sit in the "running" state before it is presumed dead.
	 *
	 * A crashed run must not block the job forever.
	 */
	const STALE_AFTER = 6 * HOUR_IN_SECONDS;

	/**
	 * Whether a job is currently running.
	 *
	 * @param string $job Job key.
	 * @return bool True when a run is in flight and not stale.
	 */
	public static function is_running( $job ) {
		$status = self::get( $job );

		return 'running' === $status['state'] && ! self::is_stranded( $status );
	}

	/**
	 * Whether a run has sat in the running state long enough to be presumed dead.
	 *
	 * A run leaves "running" from inside one of its own chained actions. When the
	 * chain breaks in a way Action Scheduler never notices — the successor was never
	 * queued, or the queue itself stopped being processed — nothing is left to close
	 * the status, and it stays as the last action wrote it forever.
	 *
	 * Expressed as its own rule rather than inlined into is_running(), because the
	 * screen has to ask the same question and the two answers drifting apart is the
	 * defect this exists to prevent: the Run button freeing itself on the timeout
	 * while the text beside it still read "Running now" from six hours earlier.
	 *
	 * @param array $status Status array from get().
	 * @return bool True when the run is past the point of being believed.
	 */
	public static function is_stranded( array $status ) {
		if ( ! isset( $status['state'] ) || 'running' !== $status['state'] ) {
			return false;
		}

		return ( time() - (int) $status['started'] ) >= self::STALE_AFTER;
	}

	/**
	 * Close out a run that is past being believed.
	 *
	 * Whoever looks at the job is the one who reaps it. There is no timer here that
	 * could do it instead — the whole point is that this run's own chain is gone —
	 * so the work happens on the next look at the settings screen, which is also the
	 * first moment anybody could have been misled by it.
	 *
	 * @param string $job Job key.
	 * @return bool True when a stranded run was closed out.
	 */
	public static function reap( $job ) {
		if ( ! self::is_stranded( self::get( $job ) ) ) {
			return false;
		}

		self::fail(
			$job,
			__( 'Interrupted: the run stopped without reporting an outcome, and nothing has moved it forward since. Nothing was left half-written — run it again when you are ready.', 'woo-product-categorizer-ai' )
		);

		return true;
	}

	/**
	 * Whether the given run is the one currently in flight.
	 *
	 * Chained actions carry the run they belong to. An action already executing
	 * cannot be cancelled, and it queues its own successor, so without this check a
	 * superseded run keeps walking the catalogue underneath a newer one — two runs
	 * then fight over the same products and both sets of counts are wrong.
	 *
	 * @param string $job Job key.
	 * @param int    $run Run identifier carried by the action.
	 * @return bool True when the action belongs to the current run.
	 */
	public static function is_current_run( $job, $run ) {
		return (int) self::get( $job )['started'] === (int) $run;
	}

	/**
	 * Read the status of a single job.
	 *
	 * @param string $job Job key.
	 * @return array Status array, with defaults filled in.
	 */
	public static function get( $job ) {
		$all = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $all ) || ! isset( $all[ $job ] ) || ! is_array( $all[ $job ] ) ) {
			return self::defaults();
		}

		return wp_parse_args( $all[ $job ], self::defaults() );
	}

	/**
	 * The shape of an unrun job.
	 *
	 * @return array Default status array.
	 */
	public static function defaults() {
		return array(
			'state'     => 'never',
			'started'   => 0,
			'finished'  => 0,
			'message'   => '',
			'counts'    => array(),
			'total'     => 0,
			'processed' => 0,
		);
	}

	/**
	 * Persist the status of a single job.
	 *
	 * @param string $job    Job key.
	 * @param array  $status Status array.
	 * @return void
	 */
	protected static function write( $job, array $status ) {
		$all = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$all[ $job ] = wp_parse_args( $status, self::defaults() );

		update_option( self::OPTION_KEY, $all, false );
	}
}
