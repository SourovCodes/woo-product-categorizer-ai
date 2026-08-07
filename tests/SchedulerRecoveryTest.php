<?php
/**
 * What happens when a run dies with the action carrying it.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use Exception;
use WooProductCategorizerAi\Categorize\Assignment;
use WooProductCategorizerAi\Jobs\Scheduler;
use WooProductCategorizerAi\Jobs\Status;
use WP_UnitTestCase;

/**
 * A dead chain cannot report its own failure: the action that would have called
 * finish() or fail() is the one that just died. Without the three Action Scheduler
 * failure hooks, a crashed run reads as running for six hours, during which the
 * screen lies and trigger() refuses to start it again.
 */
class SchedulerRecoveryTest extends WP_UnitTestCase {

	/**
	 * Set up the fixture.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Status::OPTION_KEY );
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Status::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Queue an action so there is a real one to fail.
	 *
	 * @param string $hook Action hook.
	 * @param array  $args Arguments.
	 * @return int The action id.
	 */
	protected function queue( $hook, array $args ) {
		return (int) as_schedule_single_action( time() + 3600, $hook, $args, Scheduler::GROUP );
	}

	/**
	 * A run whose action throws must be recorded as failed.
	 *
	 * @return void
	 */
	public function test_a_thrown_exception_closes_the_run_out() {
		$run       = Status::start( Assignment::JOB );
		$action_id = $this->queue(
			Scheduler::ACTION_ASSIGN_BATCH,
			array(
				'after_id' => 0,
				'run'      => $run,
			)
		);

		( new Scheduler() )->handle_failed_execution( $action_id, new Exception( 'the database went away' ) );

		$status = Status::get( Assignment::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertStringContainsString( 'the database went away', $status['message'] );
	}

	/**
	 * A fatal error ends the request outright, so the shutdown hook is the only
	 * thing left to notice.
	 *
	 * @return void
	 */
	public function test_an_unexpected_shutdown_closes_the_run_out() {
		$run       = Status::start( Assignment::JOB );
		$action_id = $this->queue(
			Scheduler::ACTION_ASSIGN_BATCH,
			array(
				'after_id' => 0,
				'run'      => $run,
			)
		);

		( new Scheduler() )->handle_unexpected_shutdown( $action_id, array( 'message' => 'allowed memory exhausted' ) );

		$this->assertSame( 'failed', Status::get( Assignment::JOB )['state'] );
		$this->assertStringContainsString( 'allowed memory exhausted', Status::get( Assignment::JOB )['message'] );
	}

	/**
	 * A superseded action failing says nothing about the run that replaced it, and
	 * failing that one on its behalf would report a healthy run as broken.
	 *
	 * @return void
	 */
	public function test_a_superseded_actions_failure_does_not_fail_the_current_run() {
		$run       = Status::start( Assignment::JOB );
		$action_id = $this->queue(
			Scheduler::ACTION_ASSIGN_BATCH,
			array(
				'after_id' => 0,
				'run'      => $run - 100,
			)
		);

		( new Scheduler() )->handle_failed_execution( $action_id, new Exception( 'stale' ) );

		$this->assertSame( 'running', Status::get( Assignment::JOB )['state'] );
	}

	/**
	 * A job that already recorded why it stopped has a better reason than "an
	 * action failed", and must keep it.
	 *
	 * @return void
	 */
	public function test_a_run_that_already_failed_keeps_its_own_reason() {
		$run = Status::start( Assignment::JOB );

		Status::fail( Assignment::JOB, 'Incorrect API key provided.' );

		$action_id = $this->queue(
			Scheduler::ACTION_ASSIGN_BATCH,
			array(
				'after_id' => 0,
				'run'      => $run,
			)
		);

		( new Scheduler() )->handle_failed_execution( $action_id, new Exception( 'something else' ) );

		$this->assertSame( 'Incorrect API key provided.', Status::get( Assignment::JOB )['message'] );
	}

	/**
	 * An action id that is not ours, or not there at all, must be ignored quietly.
	 *
	 * @return void
	 */
	public function test_an_unknown_action_is_ignored() {
		Status::start( Assignment::JOB );

		$scheduler = new Scheduler();

		$scheduler->handle_failed_execution( 0, new Exception( 'nothing' ) );
		$scheduler->handle_failed_execution( 99999999, new Exception( 'nothing' ) );

		$foreign = (int) as_schedule_single_action( time() + 3600, 'some_other_plugins_hook', array(), 'someone-else' );
		$scheduler->handle_failed_execution( $foreign, new Exception( 'nothing' ) );

		$this->assertSame( 'running', Status::get( Assignment::JOB )['state'] );
	}

	/**
	 * Whoever cancels the work owns closing the status behind it: cancelling the
	 * queue destroys the chain that would have reported the outcome.
	 *
	 * @return void
	 */
	public function test_abandoning_closes_every_run_in_flight() {
		Status::start( Assignment::JOB );
		Status::start( 'taxonomy' );
		Status::finish( 'taxonomy', 'done' );

		$abandoned = Status::abandon( 'the plugin was deactivated' );

		$this->assertContains( Assignment::JOB, $abandoned );
		$this->assertNotContains( 'taxonomy', $abandoned, 'A finished job has nothing to abandon.' );
		$this->assertSame( 'failed', Status::get( Assignment::JOB )['state'] );
		$this->assertSame( 'success', Status::get( 'taxonomy' )['state'] );
	}

	/**
	 * A crashed run must not block the job forever, and a live one must not be
	 * mistaken for a dead one.
	 *
	 * @return void
	 */
	public function test_a_run_is_only_stale_after_the_timeout() {
		Status::start( Assignment::JOB );

		$this->assertTrue( Status::is_running( Assignment::JOB ) );

		$all = get_option( Status::OPTION_KEY );

		$all[ Assignment::JOB ]['started'] = time() - Status::STALE_AFTER - 1;
		update_option( Status::OPTION_KEY, $all, false );

		$this->assertFalse( Status::is_running( Assignment::JOB ), 'A run this old is not coming back.' );
	}

	/**
	 * Trigger has to refuse a job that is already going, or two runs fight over the
	 * same products and both sets of counts are wrong.
	 *
	 * @return void
	 */
	public function test_trigger_refuses_a_job_that_is_already_running() {
		Status::start( Assignment::JOB );

		$queued = Scheduler::trigger( Assignment::JOB );

		$this->assertWPError( $queued );
		$this->assertSame( 'wpcai_already_running', $queued->get_error_code() );
	}

	/**
	 * A job key that does not exist must be refused rather than queued as a hook
	 * nothing listens to.
	 *
	 * @return void
	 */
	public function test_trigger_refuses_an_unknown_job() {
		$queued = Scheduler::trigger( 'not_a_job' );

		$this->assertWPError( $queued );
		$this->assertSame( 'wpcai_unknown_job', $queued->get_error_code() );
	}

	/**
	 * The three jobs are all startable, and the revert is registered even though it
	 * is not shown in the jobs table.
	 *
	 * @return void
	 */
	public function test_every_job_including_the_hidden_one_can_be_triggered() {
		$jobs = Scheduler::get_jobs();

		$this->assertArrayHasKey( 'taxonomy', $jobs );
		$this->assertArrayHasKey( 'assign', $jobs );
		$this->assertArrayHasKey( 'revert', $jobs );
		$this->assertTrue( $jobs['revert']['hidden'] );
		$this->assertArrayNotHasKey( 'hidden', $jobs['assign'] );

		foreach ( $jobs as $key => $job ) {
			$this->assertSame( $key, Scheduler::job_for_action( $job['action'] ) );
		}
	}

	/**
	 * A delayed follow-up is what backs a run off after a rate limit, rather than
	 * sending the next batch into the same closed door.
	 *
	 * @return void
	 */
	public function test_a_delayed_chain_is_scheduled_for_later() {
		Scheduler::chain_after( 60, Scheduler::ACTION_ASSIGN_BATCH, array( 'run' => 1 ) );

		$next = as_next_scheduled_action( Scheduler::ACTION_ASSIGN_BATCH, array( 'run' => 1 ), Scheduler::GROUP );

		$this->assertIsInt( $next );
		$this->assertGreaterThan( time() + 30, $next );
	}

	/**
	 * A run that cannot be measured shows an indeterminate bar rather than one
	 * stuck at zero, which reads as broken.
	 *
	 * @return void
	 */
	public function test_percentage_is_null_until_a_run_can_be_measured() {
		Status::start( Assignment::JOB );

		$this->assertNull( Status::percentage( Status::get( Assignment::JOB ) ) );

		Status::measure( Assignment::JOB, 200 );
		Status::advance( Assignment::JOB, 50 );

		$this->assertSame( 25, Status::percentage( Status::get( Assignment::JOB ) ) );
	}

	/**
	 * The total is what the source claimed at the start, and the walk advances by
	 * what it actually handled, so the two can disagree. A bar reading 104% is
	 * worse than one that sits at 100 for a moment.
	 *
	 * @return void
	 */
	public function test_percentage_is_clamped_to_a_hundred() {
		Status::start( Assignment::JOB );
		Status::measure( Assignment::JOB, 10 );
		Status::advance( Assignment::JOB, 25 );

		$this->assertSame( 100, Status::percentage( Status::get( Assignment::JOB ) ) );
	}

	/**
	 * Token totals ride the counter mechanism, so they need no code of their own.
	 *
	 * @return void
	 */
	public function test_counters_accumulate_across_batches() {
		Status::start( Assignment::JOB );

		Status::progress(
			Assignment::JOB,
			array(
				'assigned'     => 20,
				'input_tokens' => 1000,
			)
		);
		Status::progress(
			Assignment::JOB,
			array(
				'assigned'     => 5,
				'input_tokens' => 500,
				'failed'       => 2,
			)
		);

		$counts = Status::get( Assignment::JOB )['counts'];

		$this->assertSame( 25, $counts['assigned'] );
		$this->assertSame( 1500, $counts['input_tokens'] );
		$this->assertSame( 2, $counts['failed'] );
	}
}
