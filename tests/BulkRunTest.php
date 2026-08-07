<?php
/**
 * The assignment run done through the provider's bulk endpoint.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Categorize\Applier;
use WooProductCategorizerAi\Categorize\Assignment;
use WooProductCategorizerAi\Categorize\BulkRun;
use WooProductCategorizerAi\Jobs\Status;
use WooProductCategorizerAi\Taxonomy\Creator;
use WooProductCategorizerAi\Taxonomy\Draft;
use WooProductCategorizerAi\Tests\Doubles\StubBatchProvider;
use WP_UnitTestCase;

/**
 * A bulk run's whole point is that it survives a gap of hours between asking and
 * answering, so most of what is tested here is what happens across that gap.
 */
class BulkRunTest extends WP_UnitTestCase {

	/**
	 * The double standing in for a bulk provider.
	 *
	 * @var StubBatchProvider
	 */
	protected $provider;

	/**
	 * The leaf map for the created tree.
	 *
	 * @var array
	 */
	protected $leaves = array();

	/**
	 * Set up the fixture.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->provider = new StubBatchProvider();

		delete_option( Status::OPTION_KEY );
		delete_option( BulkRun::OPTION_KEY );
		delete_option( 'woo_product_categorizer_ai_last_apply' );

		$this->make_tree();
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Status::OPTION_KEY );
		delete_option( BulkRun::OPTION_KEY );
		delete_option( Draft::OPTION_KEY );
		delete_option( Settings::OPTION_KEY );
		delete_option( 'woo_product_categorizer_ai_last_apply' );

		parent::tear_down();
	}

	/**
	 * Create a small tree and remember its leaf map.
	 *
	 * @return void
	 */
	protected function make_tree() {
		$draft = Draft::blank();
		$nodes = array();
		$keys  = array();

		foreach ( array( array( 'Wohnen', 'Deko' ), array( 'Spielwaren', 'Holztiere' ) ) as $path ) {
			$parent = '';

			foreach ( $path as $depth => $name ) {
				$signature = $parent . '/' . $name;

				if ( ! isset( $keys[ $signature ] ) ) {
					$key                = Draft::mint_key();
					$keys[ $signature ] = $key;
					$nodes[]            = array(
						'key'    => $key,
						'parent' => $parent,
						'name'   => $name,
						'depth'  => $depth + 1,
					);
				}

				$parent = $keys[ $signature ];
			}
		}

		$draft['nodes']   = $nodes;
		$draft['created'] = time();

		Draft::save( $draft );
		Creator::create_from_draft( $draft );

		$this->leaves = Creator::leaf_map( Draft::get() );
	}

	/**
	 * The leaf id for a category name.
	 *
	 * @param string $name Leaf name.
	 * @return string
	 */
	protected function leaf( $name ) {
		foreach ( $this->leaves as $id => $leaf ) {
			if ( $leaf['name'] === $name ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * Settings with a key and bulk mode selected.
	 *
	 * @param array $overrides Settings to change.
	 * @return array
	 */
	protected function settings( array $overrides = array() ) {
		return array_merge(
			Settings::default_settings(),
			array(
				'provider'       => 'openai',
				'api_keys'       => array( 'openai' => 'sk-test' ),
				'execution_mode' => 'bulk',
				'batch_size'     => 25,
			),
			$overrides
		);
	}

	/**
	 * Create some products.
	 *
	 * @param int $count How many.
	 * @return array Product ids.
	 */
	protected function make_products( $count ) {
		$ids = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'    => 'product',
					'post_status'  => 'publish',
					'post_title'   => 'Produkt ' . $index,
					'post_excerpt' => 'Eine Beschreibung.',
				)
			);
		}

		return $ids;
	}

	/**
	 * Build the job with the double wired in.
	 *
	 * @param array $overrides Settings to change.
	 * @return BulkRun
	 */
	protected function job( array $overrides = array() ) {
		return new BulkRun( $this->provider, $this->settings( $overrides ) );
	}

	/**
	 * Drive start → build → send, the way Action Scheduler would.
	 *
	 * @param BulkRun $job The job.
	 * @return int The run id.
	 */
	protected function submit( BulkRun $job ) {
		$job->start();

		$run = (int) Status::get( Assignment::JOB )['started'];

		if ( 'running' !== Status::get( Assignment::JOB )['state'] ) {
			return $run;
		}

		$after = 0;
		$chunk = 0;

		// build() chains itself until the catalogue runs out, then chains send().
		while ( $chunk < 20 ) {
			$job->build( $after, $chunk, $run );

			$ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => BulkRun::BUILD_CHUNK,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( empty( $ids ) || max( $ids ) <= $after ) {
				break;
			}

			$after = max( $ids );
			++$chunk;
		}

		$job->send( $chunk + 1, $run );

		return $run;
	}

	/**
	 * Drive poll → collect → finalise.
	 *
	 * @param BulkRun $job The job.
	 * @param int     $run The run.
	 * @return void
	 */
	protected function collect( BulkRun $job, $run ) {
		$job->poll( $run );

		if ( 'running' !== Status::get( Assignment::JOB )['state'] ) {
			return;
		}

		/*
		 * collect() chains itself only while there is more to apply, and clears the
		 * batch record on the pass that finds nothing left. Stopping on that signal is
		 * what the queue does; looping past it would call collect() on a run that has
		 * already tidied up after itself.
		 */
		for ( $offset = 0; $offset < 4000; $offset += BulkRun::COLLECT_CHUNK ) {
			$job->collect( $offset, $run );

			if ( 'running' !== Status::get( Assignment::JOB )['state'] ) {
				return;
			}

			if ( array() === BulkRun::in_flight() ) {
				break;
			}
		}

		( new Assignment( $this->provider, $this->settings() ) )->finalise( $run );
	}

	/**
	 * The category names a product ended up with.
	 *
	 * @param int $product_id Product to read.
	 * @return array
	 */
	protected function categories( $product_id ) {
		$names = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );

		sort( $names );

		return $names;
	}

	/**
	 * The counters the run recorded.
	 *
	 * @return array
	 */
	protected function counts() {
		return (array) Status::get( Assignment::JOB )['counts'];
	}

	/**
	 * The whole path: submit, wait, collect, apply.
	 *
	 * @return void
	 */
	public function test_a_bulk_run_categorises_the_catalogue() {
		$ids = $this->make_products( 3 );
		$job = $this->job();

		$run = $this->submit( $job );

		$this->assertCount( 1, $this->provider->submissions, 'Everything goes up in one submission.' );
		$this->assertNotEmpty( BulkRun::in_flight() );

		$custom_id = array_key_first( $this->provider->submissions[0] );

		$this->provider->will_return(
			$custom_id,
			array(
				'assignments' => array(
					array(
						'ref'         => 'p001',
						'category_id' => $this->leaf( 'Deko' ),
					),
					array(
						'ref'         => 'p002',
						'category_id' => $this->leaf( 'Holztiere' ),
					),
					array(
						'ref'         => 'p003',
						'category_id' => null,
					),
				),
			)
		);

		$this->collect( $job, $run );

		$this->assertSame( 'success', Status::get( Assignment::JOB )['state'] );
		$this->assertSame( array( 'Deko', 'Wohnen' ), $this->categories( $ids[0] ) );
		$this->assertSame( array( 'Holztiere', 'Spielwaren' ), $this->categories( $ids[1] ) );
		$this->assertSame( array(), $this->categories( $ids[2] ) );
		$this->assertSame( 2, $this->counts()['assigned'] );
		$this->assertSame( 1, $this->counts()['unclassified'] );
	}

	/**
	 * Nothing may be written before the provider has answered — that is the whole
	 * bargain of waiting.
	 *
	 * @return void
	 */
	public function test_nothing_is_written_while_the_batch_is_in_flight() {
		$ids = $this->make_products( 2 );

		$this->submit( $this->job() );

		foreach ( $ids as $id ) {
			$this->assertSame( array(), $this->categories( $id ) );
			$this->assertSame( '', get_post_meta( $id, Applier::META_PREVIOUS, true ) );
		}
	}

	/**
	 * The request bodies are built by the same code as the live path, so the
	 * cacheable instructions and the schema have to be there.
	 *
	 * @return void
	 */
	public function test_the_submitted_requests_carry_the_taxonomy_and_schema() {
		$this->make_products( 2 );

		$this->submit( $this->job() );

		$request = reset( $this->provider->submissions[0] );

		$this->assertStringContainsString( 'Wohnen > Deko', $request['instructions'] );
		$this->assertSame( 'category_assignments', $request['schema_name'] );
		$this->assertArrayHasKey( 'enum', $request['schema']['properties']['assignments']['items']['properties']['category_id'] );
	}

	/**
	 * A run bigger than one request has to be split, and every product has to end
	 * up in exactly one of the pieces.
	 *
	 * @return void
	 */
	public function test_products_are_split_across_requests_without_loss() {
		$ids = $this->make_products( 7 );

		$this->submit( $this->job( array( 'batch_size' => 3 ) ) );

		$requests = $this->provider->submissions[0];

		$this->assertCount( 3, $requests, '7 products at 3 per request is 3 requests.' );

		$seen = array();

		foreach ( $requests as $request ) {
			$decoded = json_decode( $request['input'], true );

			foreach ( $decoded['products'] as $product ) {
				$seen[] = $product['name'];
			}
		}

		$this->assertCount( 7, $seen );
		$this->assertCount( 7, array_unique( $seen ), 'No product may appear twice.' );
	}

	/**
	 * A poll that says "not yet" must not be mistaken for a finished batch.
	 *
	 * @return void
	 */
	public function test_a_pending_batch_keeps_waiting() {
		$ids = $this->make_products( 2 );
		$job = $this->job();
		$run = $this->submit( $job );

		$this->provider->will_report( array( 'pending' ) );
		$job->poll( $run );

		$this->assertSame( 'running', Status::get( Assignment::JOB )['state'] );
		$this->assertSame( array(), $this->categories( $ids[0] ) );
	}

	/**
	 * Progress has to be visible during the wait, or the screen has nothing to say
	 * for hours.
	 *
	 * @return void
	 */
	public function test_a_poll_records_how_far_the_provider_has_got() {
		$this->make_products( 2 );
		$job = $this->job();
		$run = $this->submit( $job );

		$this->provider->will_report(
			array(
				array(
					'state'     => 'pending',
					'raw'       => 'in_progress',
					'total'     => 10,
					'completed' => 4,
					'failed'    => 0,
				),
			)
		);
		$job->poll( $run );

		$flight = BulkRun::in_flight();

		$this->assertSame( 10, $flight['total'] );
		$this->assertSame( 4, $flight['completed'] );
	}

	/**
	 * A poll that could not be made says nothing about the batch, and failing a run
	 * that is very likely fine would be the worst possible response.
	 *
	 * @return void
	 */
	public function test_an_unreadable_poll_does_not_fail_the_run() {
		$this->make_products( 2 );
		$job = $this->job();
		$run = $this->submit( $job );

		$this->provider->will_report( array( array( 'state' => 'pending' ) ) );
		$this->provider->states = array( new \WP_Error( 'wpcai_transport_error', 'network down' ) );

		$job->poll( $run );

		$this->assertSame( 'running', Status::get( Assignment::JOB )['state'] );
	}

	/**
	 * An expired or rejected batch has to end the run, not leave it waiting for
	 * results that are never coming.
	 *
	 * @return void
	 */
	public function test_a_failed_batch_fails_the_run_and_writes_nothing() {
		$ids = $this->make_products( 2 );
		$job = $this->job();
		$run = $this->submit( $job );

		$this->provider->will_report(
			array(
				array(
					'state' => 'failed',
					'raw'   => 'expired',
				),
			)
		);
		$job->poll( $run );

		$status = Status::get( Assignment::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertStringContainsString( 'expired', $status['message'] );
		$this->assertSame( array(), $this->categories( $ids[0] ) );
		$this->assertSame( array(), BulkRun::in_flight(), 'A dead batch must not stay on the books.' );
	}

	/**
	 * One request failing inside an otherwise fine batch costs its own products
	 * and no others.
	 *
	 * @return void
	 */
	public function test_one_failed_request_does_not_cost_the_rest() {
		$ids = $this->make_products( 6 );
		$job = $this->job( array( 'batch_size' => 3 ) );
		$run = $this->submit( $job );

		$requests = array_keys( $this->provider->submissions[0] );

		$this->provider->will_fail_request( $requests[0] );
		$this->provider->will_return(
			$requests[1],
			array(
				'assignments' => array(
					array(
						'ref'         => 'p001',
						'category_id' => $this->leaf( 'Deko' ),
					),
					array(
						'ref'         => 'p002',
						'category_id' => $this->leaf( 'Deko' ),
					),
					array(
						'ref'         => 'p003',
						'category_id' => $this->leaf( 'Deko' ),
					),
				),
			)
		);

		$this->collect( $job, $run );

		$this->assertSame( 'success', Status::get( Assignment::JOB )['state'] );
		$this->assertSame( 3, $this->counts()['failed'] );
		$this->assertSame( 3, $this->counts()['assigned'] );
	}

	/**
	 * Cancelling has to reach the provider and close the run out at once, rather
	 * than waiting for a poll that may be minutes away.
	 *
	 * @return void
	 */
	public function test_cancelling_stops_the_batch_and_closes_the_run() {
		$this->make_products( 2 );
		$job = $this->job();
		$this->submit( $job );

		$this->assertTrue( $job->cancel() );
		$this->assertSame( array( 'batch_stub_1' ), $this->provider->cancelled );
		$this->assertSame( 'failed', Status::get( Assignment::JOB )['state'] );
		$this->assertSame( array(), BulkRun::in_flight() );
	}

	/**
	 * Cancelling when there is nothing in flight is a refusal, not a crash.
	 *
	 * @return void
	 */
	public function test_cancelling_nothing_is_refused() {
		$this->assertWPError( $this->job()->cancel() );
	}

	/**
	 * A dry run must write nothing here either, and leave nothing to revert.
	 *
	 * @return void
	 */
	public function test_a_dry_bulk_run_writes_nothing() {
		$ids = $this->make_products( 2 );
		$job = $this->job( array( 'dry_run' => true ) );
		$run = $this->submit( $job );

		$custom_id = array_key_first( $this->provider->submissions[0] );

		$this->provider->will_return(
			$custom_id,
			array(
				'assignments' => array(
					array(
						'ref'         => 'p001',
						'category_id' => $this->leaf( 'Deko' ),
					),
					array(
						'ref'         => 'p002',
						'category_id' => $this->leaf( 'Deko' ),
					),
				),
			)
		);

		$this->collect( $job, $run );

		foreach ( $ids as $id ) {
			$this->assertSame( array(), $this->categories( $id ) );
		}

		$this->assertSame( 2, $this->counts()['assigned'] );
		$this->assertSame( array(), get_option( 'woo_product_categorizer_ai_last_apply', array() ) );
	}

	/**
	 * With override off, an already-categorised product must not even be put in
	 * the file — the saving is the same one the live path makes.
	 *
	 * @return void
	 */
	public function test_override_off_keeps_products_out_of_the_submission() {
		$ids      = $this->make_products( 2 );
		$existing = wp_insert_term( 'Altkategorie', 'product_cat' );

		wp_set_object_terms( $ids[0], array( (int) $existing['term_id'] ), 'product_cat' );

		$this->submit( $this->job( array( 'override_existing' => false ) ) );

		$request = reset( $this->provider->submissions[0] );
		$decoded = json_decode( $request['input'], true );

		$this->assertCount( 1, $decoded['products'], 'Only the uncategorised product should be sent.' );
		$this->assertSame( 1, $this->counts()['skipped_has_cats'] );
	}

	/**
	 * A whole catalogue already categorised means nothing to send, which is a
	 * complete run rather than an error.
	 *
	 * @return void
	 */
	public function test_nothing_to_send_finishes_without_a_submission() {
		$ids      = $this->make_products( 2 );
		$existing = wp_insert_term( 'Altkategorie', 'product_cat' );

		foreach ( $ids as $id ) {
			wp_set_object_terms( $id, array( (int) $existing['term_id'] ), 'product_cat' );
		}

		$run = $this->submit( $this->job( array( 'override_existing' => false ) ) );

		$this->assertSame( array(), $this->provider->submissions, 'Nothing to ask means no request at all.' );

		( new Assignment( $this->provider, $this->settings() ) )->finalise( $run );

		$this->assertSame( 'success', Status::get( Assignment::JOB )['state'] );
	}

	/**
	 * A superseded run must not submit, poll or apply anything.
	 *
	 * @return void
	 */
	public function test_a_superseded_run_does_nothing() {
		$ids = $this->make_products( 2 );
		$job = $this->job();

		$job->start();
		$run = (int) Status::get( Assignment::JOB )['started'];

		$job->build( 0, 0, $run - 1 );
		$job->send( 1, $run - 1 );
		$job->collect( 0, $run - 1 );

		$this->assertSame( array(), $this->provider->submissions );
		$this->assertSame( array(), $this->categories( $ids[0] ) );
	}

	/**
	 * A provider with no bulk endpoint must refuse before starting, not fail
	 * halfway through.
	 *
	 * @return void
	 */
	public function test_a_provider_without_a_bulk_endpoint_refuses() {
		$this->make_products( 2 );

		$job = new BulkRun( new Doubles\StubProvider(), $this->settings() );
		$job->start();

		$this->assertSame( 'failed', Status::get( Assignment::JOB )['state'] );
	}

	/**
	 * The mode only takes effect when the provider can honour it, so switching to
	 * one that cannot degrades to a live run rather than to a broken button.
	 *
	 * @return void
	 */
	public function test_bulk_mode_is_only_used_when_the_provider_supports_it() {
		$this->assertTrue( Settings::uses_bulk_mode( $this->settings() ) );
		$this->assertFalse( Settings::uses_bulk_mode( $this->settings( array( 'execution_mode' => 'live' ) ) ) );

		// A provider id the registry does not know cannot support anything.
		$this->assertFalse( Settings::uses_bulk_mode( $this->settings( array( 'provider' => 'nope' ) ) ) );
	}

	/**
	 * A submission the provider rejects outright has to fail the run cleanly.
	 *
	 * @return void
	 */
	public function test_a_rejected_submission_fails_the_run() {
		$this->make_products( 2 );

		$this->provider->submit_error = new \WP_Error( 'wpcai_api_error', 'Batch quota exceeded.' );

		$this->submit( $this->job() );

		$status = Status::get( Assignment::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'Batch quota exceeded.', $status['message'] );
		$this->assertSame( array(), BulkRun::in_flight() );
	}
}
