<?php
/**
 * The assignment run.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Categorize\Applier;
use WooProductCategorizerAi\Categorize\Assignment;
use WooProductCategorizerAi\Categorize\Batch;
use WooProductCategorizerAi\Jobs\Status;
use WooProductCategorizerAi\Taxonomy\Creator;
use WooProductCategorizerAi\Taxonomy\Draft;
use WooProductCategorizerAi\Tests\Doubles\StubProvider;
use WP_UnitTestCase;

/**
 * Several tests here assert on what the stub was *asked*, not on what came back:
 * a batch that made no call, an instructions string identical across batches. Both
 * are invisible in the result and are exactly the things that break quietly.
 */
class AssignmentTest extends WP_UnitTestCase {

	/**
	 * The double standing in for a provider.
	 *
	 * @var StubProvider
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

		$this->provider = new StubProvider();

		delete_option( Status::OPTION_KEY );
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
		delete_option( Draft::OPTION_KEY );
		delete_option( Settings::OPTION_KEY );
		delete_option( 'woo_product_categorizer_ai_last_apply' );

		parent::tear_down();
	}

	/**
	 * Create a two-level tree and remember its leaf map.
	 *
	 * @return void
	 */
	protected function make_tree() {
		$draft = Draft::blank();
		$nodes = array();
		$keys  = array();

		foreach ( array(
			array( 'Wohnen', 'Deko' ),
			array( 'Wohnen', 'Textilien' ),
			array( 'Spielwaren', 'Holztiere' ),
		) as $path ) {
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
	 * Settings with a key in place.
	 *
	 * @param array $overrides Settings to change.
	 * @return array
	 */
	protected function settings( array $overrides = array() ) {
		return array_merge(
			Settings::default_settings(),
			array(
				'provider' => 'openai',
				'api_keys' => array( 'openai' => 'sk-test' ),
			),
			$overrides
		);
	}

	/**
	 * Create some products.
	 *
	 * @param int    $count  How many.
	 * @param string $status Post status.
	 * @return array Product ids.
	 */
	protected function make_products( $count, $status = 'publish' ) {
		$ids = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'    => 'product',
					'post_status'  => $status,
					'post_title'   => 'Produkt ' . $index,
					'post_excerpt' => 'Eine Beschreibung.',
				)
			);
		}

		return $ids;
	}

	/**
	 * Script an answer filing every product in the batch under one leaf.
	 *
	 * @param int    $size    How many products the batch has.
	 * @param string $leaf_id Leaf to choose, or an empty string for null.
	 * @return void
	 */
	protected function will_file_all( $size, $leaf_id ) {
		$rows = array();

		for ( $index = 1; $index <= $size; $index++ ) {
			$rows[] = array(
				'ref'         => sprintf( 'p%02d', $index ),
				'category_id' => '' === $leaf_id ? null : $leaf_id,
			);
		}

		$this->provider->will_answer( array( 'assignments' => $rows ) );
	}

	/**
	 * Run the whole chain by hand, the way Action Scheduler would.
	 *
	 * @param array $overrides Settings to change.
	 * @return int The run id.
	 */
	protected function run_all( array $overrides = array() ) {
		$job = new Assignment( $this->provider, $this->settings( $overrides ) );

		$job->start();

		$run = (int) Status::get( Assignment::JOB )['started'];

		if ( 'running' !== Status::get( Assignment::JOB )['state'] ) {
			return $run;
		}

		$settings = $this->settings( $overrides );
		$after    = 0;
		$guard    = 0;

		/*
		 * Stands in for the chain Action Scheduler would drive, advancing exactly the
		 * way Assignment::batch() does: keyset, by the highest id the page covered.
		 */
		while ( $guard < 50 ) {
			++$guard;

			$page = Batch::next( $after, $settings['scope'], (int) $settings['batch_size'] );

			if ( empty( $page ) ) {
				break;
			}

			$job->batch( $after, $run );

			if ( 'running' !== Status::get( Assignment::JOB )['state'] ) {
				return $run;
			}

			$after = max( $page );
		}

		$job->finalise( $run );

		return $run;
	}

	/**
	 * The counters a finished run recorded.
	 *
	 * @return array
	 */
	protected function counts() {
		return (array) Status::get( Assignment::JOB )['counts'];
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
	 * The happy path, and the ancestor rule: a product gets the leaf it was given
	 * and everything above it, so no parent archive is left empty.
	 *
	 * @return void
	 */
	public function test_a_product_is_filed_under_its_leaf_and_every_ancestor() {
		$ids = $this->make_products( 2 );

		$this->will_file_all( 2, $this->leaf( 'Deko' ) );

		$this->run_all( array( 'batch_size' => 25 ) );

		$this->assertSame( array( 'Deko', 'Wohnen' ), $this->categories( $ids[0] ) );
		$this->assertSame( 2, $this->counts()['assigned'] );
	}

	/**
	 * A product the model declines to place is left exactly as it was. It is never
	 * forced into a catch-all bucket the shop did not ask for.
	 *
	 * @return void
	 */
	public function test_a_null_answer_leaves_the_product_untouched() {
		$ids = $this->make_products( 1 );

		$this->will_file_all( 1, '' );

		$this->run_all();

		$this->assertSame( 1, $this->counts()['unclassified'] );
		$this->assertArrayNotHasKey( 'assigned', $this->counts() );
		$this->assertSame( array(), $this->categories( $ids[0] ) );
	}

	/**
	 * Testing against the live API produced a valid id for the wrong product, so an
	 * id is checked against the run's own map whether or not the enum was sent.
	 *
	 * @return void
	 */
	public function test_an_id_outside_this_run_writes_nothing() {
		$ids = $this->make_products( 1 );

		$this->provider->will_answer(
			array(
				'assignments' => array(
					array(
						'ref'         => 'p01',
						'category_id' => 'c999',
					),
				),
			)
		);

		$this->run_all();

		$this->assertSame( 1, $this->counts()['invalid_id'] );
		$this->assertSame( array(), $this->categories( $ids[0] ) );
	}

	/**
	 * A ref this batch never sent belongs to nothing that can be written.
	 *
	 * @return void
	 */
	public function test_an_unknown_ref_is_discarded() {
		$ids = $this->make_products( 1 );

		$this->provider->will_answer(
			array(
				'assignments' => array(
					array(
						'ref'         => 'p99',
						'category_id' => $this->leaf( 'Deko' ),
					),
				),
			)
		);

		$this->run_all();

		$this->assertSame( array(), $this->categories( $ids[0] ) );
		$this->assertSame( 1, $this->counts()['unclassified'], 'The real product was never answered for.' );
	}

	/**
	 * Two answers for the same product: the first wins, and the second must not be
	 * counted as a second product.
	 *
	 * @return void
	 */
	public function test_a_duplicate_ref_is_applied_once() {
		$ids = $this->make_products( 1 );

		$this->provider->will_answer(
			array(
				'assignments' => array(
					array(
						'ref'         => 'p01',
						'category_id' => $this->leaf( 'Deko' ),
					),
					array(
						'ref'         => 'p01',
						'category_id' => $this->leaf( 'Holztiere' ),
					),
				),
			)
		);

		$this->run_all();

		$this->assertSame( array( 'Deko', 'Wohnen' ), $this->categories( $ids[0] ) );
		$this->assertSame( 1, $this->counts()['assigned'] );
	}

	/**
	 * A product the model simply left out is in the same position as one it
	 * declined: still uncategorised, and worth counting so the total adds up.
	 *
	 * @return void
	 */
	public function test_a_product_the_model_omitted_is_counted() {
		$this->make_products( 3 );

		$this->provider->will_answer(
			array(
				'assignments' => array(
					array(
						'ref'         => 'p01',
						'category_id' => $this->leaf( 'Deko' ),
					),
				),
			)
		);

		$this->run_all();

		$this->assertSame( 1, $this->counts()['assigned'] );
		$this->assertSame( 2, $this->counts()['unclassified'] );
	}

	/**
	 * Override replaces rather than appends, which is what the word means.
	 *
	 * @return void
	 */
	public function test_override_replaces_the_existing_categories() {
		$ids = $this->make_products( 1 );

		$existing = wp_insert_term( 'Altkategorie', 'product_cat' );
		wp_set_object_terms( $ids[0], array( (int) $existing['term_id'] ), 'product_cat' );

		$this->will_file_all( 1, $this->leaf( 'Deko' ) );

		$this->run_all( array( 'override_existing' => true ) );

		$this->assertSame( array( 'Deko', 'Wohnen' ), $this->categories( $ids[0] ) );
	}

	/**
	 * With override off, an already-categorised product is skipped **without being
	 * sent** — otherwise the run pays tokens for products it was never going to
	 * touch, which on a categorised catalogue is the entire bill for no effect.
	 *
	 * @return void
	 */
	public function test_override_off_skips_without_spending_a_request() {
		$ids = $this->make_products( 1 );

		$existing = wp_insert_term( 'Altkategorie', 'product_cat' );
		wp_set_object_terms( $ids[0], array( (int) $existing['term_id'] ), 'product_cat' );

		$this->run_all( array( 'override_existing' => false ) );

		$this->assertSame( 1, $this->counts()['skipped_has_cats'] );
		$this->assertSame( 0, $this->provider->call_count(), 'A fully skipped batch must make no call at all.' );
		$this->assertSame( array( 'Altkategorie' ), $this->categories( $ids[0] ) );
	}

	/**
	 * "Uncategorized" is what WooCommerce files a product under when nobody has
	 * said anything, so treating it as an existing category would make "leave
	 * categorised products alone" skip the entire catalogue.
	 *
	 * @return void
	 */
	public function test_the_default_category_does_not_count_as_already_categorised() {
		$ids = $this->make_products( 1 );

		$default = wp_insert_term( 'Uncategorized wpcai', 'product_cat' );
		update_option( 'default_product_cat', (int) $default['term_id'] );
		wp_set_object_terms( $ids[0], array( (int) $default['term_id'] ), 'product_cat' );

		$this->will_file_all( 1, $this->leaf( 'Deko' ) );

		$this->run_all( array( 'override_existing' => false ) );

		delete_option( 'default_product_cat' );

		$this->assertSame( array( 'Deko', 'Wohnen' ), $this->categories( $ids[0] ) );
	}

	/**
	 * Published-only must not touch drafts.
	 *
	 * @return void
	 */
	public function test_the_scope_setting_decides_what_is_walked() {
		$published = $this->make_products( 1 );
		$drafted   = $this->make_products( 1, 'draft' );

		$this->will_file_all( 1, $this->leaf( 'Deko' ) );

		$this->run_all( array( 'scope' => 'publish' ) );

		$this->assertSame( array( 'Deko', 'Wohnen' ), $this->categories( $published[0] ) );
		$this->assertSame( array(), $this->categories( $drafted[0] ) );
	}

	/**
	 * The whole cost model rests on this: the taxonomy lives in the instructions,
	 * is rendered once, and is byte-identical on every request of the run.
	 *
	 * @return void
	 */
	public function test_every_batch_sends_an_identical_instructions_string() {
		$this->make_products( 4 );

		$this->will_file_all( 2, $this->leaf( 'Deko' ) );
		$this->will_file_all( 2, $this->leaf( 'Deko' ) );

		$this->run_all( array( 'batch_size' => 2 ) );

		$this->assertGreaterThanOrEqual( 2, $this->provider->call_count() );
		$this->assertSame(
			$this->provider->requests[0]['instructions'],
			$this->provider->requests[1]['instructions'],
			'A changing prefix silently costs the prompt cache.'
		);
	}

	/**
	 * The model is shown full paths, never bare names: "Deko" alone is genuinely
	 * ambiguous once it exists under more than one parent.
	 *
	 * @return void
	 */
	public function test_the_instructions_carry_full_category_paths() {
		$this->make_products( 1 );
		$this->will_file_all( 1, $this->leaf( 'Deko' ) );

		$this->run_all();

		$this->assertStringContainsString( 'Wohnen > Deko', $this->provider->requests[0]['instructions'] );
	}

	/**
	 * Changing the settings mid-run must not change the rules half way through it.
	 *
	 * @return void
	 */
	public function test_the_runs_options_are_frozen_when_it_starts() {
		$ids = $this->make_products( 4 );

		$job = new Assignment( $this->provider, $this->settings( array( 'batch_size' => 2 ) ) );
		$job->start();

		$run = (int) Status::get( Assignment::JOB )['started'];

		$this->will_file_all( 2, $this->leaf( 'Deko' ) );
		$job->batch( 0, $run );

		/*
		 * Somebody turns override off and widens the scope while the run is walking.
		 * Neither may take effect until the next run.
		 */
		update_option(
			Settings::OPTION_KEY,
			$this->settings(
				array(
					'scope'             => 'publish_draft',
					'override_existing' => false,
				)
			),
			false
		);

		$this->will_file_all( 2, $this->leaf( 'Deko' ) );
		$job->batch( $ids[1], $run );

		$this->assertSame( 4, Status::get( Assignment::JOB )['processed'] );
		$this->assertSame( 4, $this->counts()['assigned'], 'The mid-run settings change must not have applied.' );
	}

	/**
	 * An action already executing cannot be cancelled and queues its own successor,
	 * so without the fence a superseded run keeps walking underneath a newer one.
	 *
	 * @return void
	 */
	public function test_a_superseded_batch_writes_nothing() {
		$ids = $this->make_products( 1 );

		$job = new Assignment( $this->provider, $this->settings() );
		$job->start();

		$run = (int) Status::get( Assignment::JOB )['started'];

		$this->will_file_all( 1, $this->leaf( 'Deko' ) );
		$job->batch( 0, $run - 1 );

		$this->assertSame( 0, $this->provider->call_count() );
		$this->assertSame( array(), $this->categories( $ids[0] ) );
	}

	/**
	 * With 176 batches, anything that abandons the walk on one bad response will
	 * abandon it.
	 *
	 * @return void
	 */
	public function test_a_transient_failure_costs_one_batch_not_the_run() {
		$ids = $this->make_products( 4 );

		$this->provider->will_fail( 'wpcai_api_error', 'retry' );
		$this->will_file_all( 2, $this->leaf( 'Deko' ) );

		$this->run_all( array( 'batch_size' => 2 ) );

		$this->assertSame( 'success', Status::get( Assignment::JOB )['state'] );
		$this->assertSame( 2, $this->counts()['failed'] );
		$this->assertSame( 2, $this->counts()['assigned'] );
		$this->assertSame( array( 'Deko', 'Wohnen' ), $this->categories( $ids[2] ) );
	}

	/**
	 * A rejected key will not fix itself, and saying so 170 more times is worse
	 * than stopping once with a message someone can act on.
	 *
	 * @return void
	 */
	public function test_an_auth_failure_stops_the_whole_run() {
		$this->make_products( 4 );

		$this->provider->answers[] = new \WP_Error(
			'wpcai_api_error',
			'Incorrect API key provided.',
			array(
				'disposition' => 'fail',
				'status'      => 401,
			)
		);

		$this->run_all( array( 'batch_size' => 2 ) );

		$status = Status::get( Assignment::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'Incorrect API key provided.', $status['message'] );
		$this->assertSame( 1, $this->provider->call_count(), 'It must stop rather than try the next batch.' );
	}

	/**
	 * Continuing without the working set would mean asking against a taxonomy this
	 * run never agreed to.
	 *
	 * @return void
	 */
	public function test_a_lost_working_set_fails_the_run_honestly() {
		$this->make_products( 2 );

		$job = new Assignment( $this->provider, $this->settings() );
		$job->start();

		$run = (int) Status::get( Assignment::JOB )['started'];

		delete_transient( 'wpcai_run_' . $run );

		$job->batch( 0, $run );

		$this->assertSame( 'failed', Status::get( Assignment::JOB )['state'] );
		$this->assertSame( 0, $this->provider->call_count() );
	}

	/**
	 * Without a created tree there is nothing to choose from, and asking anyway
	 * would charge for every request to be told "none of these fit".
	 *
	 * @return void
	 */
	public function test_a_run_refuses_to_start_without_categories() {
		Draft::discard();

		foreach ( get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		) as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}

		$this->make_products( 1 );

		( new Assignment( $this->provider, $this->settings() ) )->start();

		$this->assertSame( 'failed', Status::get( Assignment::JOB )['state'] );
		$this->assertSame( 0, $this->provider->call_count() );
	}

	/**
	 * Token counters ride the existing progress mechanism, and the cached share is
	 * the one figure that shows prompt caching is working.
	 *
	 * @return void
	 */
	public function test_token_usage_is_accumulated_across_batches() {
		$this->make_products( 4 );

		$this->will_file_all( 2, $this->leaf( 'Deko' ) );
		$this->will_file_all( 2, $this->leaf( 'Deko' ) );

		$this->run_all( array( 'batch_size' => 2 ) );

		$counts = $this->counts();

		$this->assertSame( 200, $counts['input_tokens'] );
		$this->assertSame( 160, $counts['cached_tokens'] );
		$this->assertSame( 2, $counts['calls'] );
	}

	/**
	 * The bar measures products considered, not products asked about, or it stalls
	 * on a run where everything is being skipped.
	 *
	 * @return void
	 */
	public function test_progress_counts_skipped_products_too() {
		$ids = $this->make_products( 2 );

		$existing = wp_insert_term( 'Altkategorie', 'product_cat' );

		foreach ( $ids as $id ) {
			wp_set_object_terms( $id, array( (int) $existing['term_id'] ), 'product_cat' );
		}

		$this->run_all( array( 'override_existing' => false ) );

		$this->assertSame( 2, Status::get( Assignment::JOB )['processed'] );
	}

	/**
	 * A dry run must predict the real run exactly, or it is not a preview.
	 *
	 * @return void
	 */
	public function test_a_dry_run_writes_nothing_and_predicts_the_real_run() {
		$ids = $this->make_products( 3 );

		$this->will_file_all( 3, $this->leaf( 'Deko' ) );
		$this->run_all( array( 'dry_run' => true ) );

		$dry = $this->counts();

		foreach ( $ids as $id ) {
			$this->assertSame( array(), $this->categories( $id ), 'A dry run writes no terms.' );
			$this->assertSame( '', get_post_meta( $id, Applier::META_PREVIOUS, true ), 'A dry run stashes nothing.' );
		}

		$this->assertSame( array(), get_option( 'woo_product_categorizer_ai_last_apply', array() ), 'A dry run leaves nothing to revert.' );

		delete_option( Status::OPTION_KEY );
		$this->provider = new StubProvider();
		$this->will_file_all( 3, $this->leaf( 'Deko' ) );
		$this->run_all( array( 'dry_run' => false ) );

		$real = $this->counts();

		unset( $dry['input_tokens'], $dry['output_tokens'], $dry['reasoning_tokens'], $dry['cached_tokens'], $dry['calls'] );
		unset( $real['input_tokens'], $real['output_tokens'], $real['reasoning_tokens'], $real['cached_tokens'], $real['calls'] );

		$this->assertSame( $dry, $real );
	}

	/**
	 * A real run has to leave the ledger the revert reads.
	 *
	 * @return void
	 */
	public function test_a_real_run_records_what_it_did_for_the_revert() {
		$ids = $this->make_products( 2 );

		$this->will_file_all( 2, $this->leaf( 'Deko' ) );

		$run = $this->run_all();

		$last = get_option( 'woo_product_categorizer_ai_last_apply' );

		$this->assertSame( $run, $last['run'] );
		$this->assertSame( 2, $last['products'] );

		foreach ( $ids as $id ) {
			$this->assertSame( $run, (int) get_post_meta( $id, Applier::META_RUN, true ) );
		}
	}

	/**
	 * The stash has to record what was there before, including nothing.
	 *
	 * @return void
	 */
	public function test_the_previous_categories_are_stashed_before_the_write() {
		$ids = $this->make_products( 1 );

		$existing = wp_insert_term( 'Altkategorie', 'product_cat' );
		wp_set_object_terms( $ids[0], array( (int) $existing['term_id'] ), 'product_cat' );

		$this->will_file_all( 1, $this->leaf( 'Deko' ) );

		$this->run_all();

		$this->assertSame(
			array( (int) $existing['term_id'] ),
			get_post_meta( $ids[0], Applier::META_PREVIOUS, true )
		);
	}
}
