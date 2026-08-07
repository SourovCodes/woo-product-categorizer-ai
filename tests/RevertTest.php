<?php
/**
 * Undoing an assignment run.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Categorize\Applier;
use WooProductCategorizerAi\Categorize\Revert;
use WooProductCategorizerAi\Jobs\Status;
use WP_UnitTestCase;

/**
 * The safety net for ~4,400 rewritten products. If it is wrong, it is wrong at the
 * moment somebody has already decided the run was a mistake.
 */
class RevertTest extends WP_UnitTestCase {

	/**
	 * A term id standing in for a category the products used to be in.
	 *
	 * @var int
	 */
	protected $old_term = 0;

	/**
	 * A term id standing in for what the run assigned.
	 *
	 * @var int
	 */
	protected $new_term = 0;

	/**
	 * Set up the fixture.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Status::OPTION_KEY );
		delete_option( Revert::OPTION_KEY );

		$this->old_term = (int) wp_insert_term( 'Altkategorie', 'product_cat' )['term_id'];
		$this->new_term = (int) wp_insert_term( 'Neukategorie', 'product_cat' )['term_id'];
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Status::OPTION_KEY );
		delete_option( Revert::OPTION_KEY );
		delete_option( 'default_product_cat' );

		parent::tear_down();
	}

	/**
	 * Create products that look like a run has been over them.
	 *
	 * @param int   $count    How many.
	 * @param int   $run      The run to stamp them with.
	 * @param array $previous The categories to record as their previous ones.
	 * @return array Product ids.
	 */
	protected function make_categorised( $count, $run, array $previous ) {
		$ids = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$id = self::factory()->post->create(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'post_title'  => 'Produkt ' . $index,
				)
			);

			wp_set_object_terms( $id, array( $this->new_term ), 'product_cat' );
			update_post_meta( $id, Applier::META_PREVIOUS, $previous );
			update_post_meta( $id, Applier::META_RUN, $run );

			$ids[] = $id;
		}

		update_option(
			Revert::OPTION_KEY,
			array(
				'run'      => $run,
				'finished' => time(),
				'products' => $count,
			),
			false
		);

		return $ids;
	}

	/**
	 * Drive the whole chain by hand.
	 *
	 * @return void
	 */
	protected function run_all() {
		$job = new Revert();

		$job->start();

		$run = (int) Status::get( Revert::JOB )['started'];

		if ( 'running' !== Status::get( Revert::JOB )['state'] ) {
			return;
		}

		$guard = 0;

		while ( $guard < 20 ) {
			++$guard;

			$before = Status::get( Revert::JOB )['processed'];

			$job->batch( 0, $run );

			if ( Status::get( Revert::JOB )['processed'] === $before ) {
				break;
			}
		}

		$job->finalise( $run );
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
	 * The whole point.
	 *
	 * @return void
	 */
	public function test_products_are_restored_to_what_they_had_before() {
		$ids = $this->make_categorised( 3, 12345, array( $this->old_term ) );

		$this->run_all();

		foreach ( $ids as $id ) {
			$this->assertSame( array( 'Altkategorie' ), $this->categories( $id ) );
		}

		$this->assertSame( 'success', Status::get( Revert::JOB )['state'] );
		$this->assertSame( 3, Status::get( Revert::JOB )['counts']['restored'] );
	}

	/**
	 * The stash is consumed, not left behind — otherwise a second revert would find
	 * work to do and undo something that was already undone.
	 *
	 * @return void
	 */
	public function test_the_stash_is_cleared_as_it_is_used() {
		$ids = $this->make_categorised( 1, 12345, array( $this->old_term ) );

		$this->run_all();

		$this->assertSame( '', get_post_meta( $ids[0], Applier::META_PREVIOUS, true ) );
		$this->assertSame( '', get_post_meta( $ids[0], Applier::META_RUN, true ) );
	}

	/**
	 * Clearing the ledger is what disables the button. A second press would
	 * otherwise find nothing and cheerfully report success.
	 *
	 * @return void
	 */
	public function test_a_second_revert_has_nothing_to_do() {
		$this->make_categorised( 2, 12345, array( $this->old_term ) );

		$this->run_all();

		$this->assertSame( array(), Revert::last_apply() );

		delete_option( Status::OPTION_KEY );

		( new Revert() )->start();

		$this->assertSame( 'failed', Status::get( Revert::JOB )['state'] );
	}

	/**
	 * A product that had no categories at all goes back to the default, not to an
	 * empty set — WooCommerce re-adds the default on the next save anyway, so an
	 * empty write is both pointless and misleading.
	 *
	 * @return void
	 */
	public function test_a_product_that_had_nothing_goes_back_to_the_default() {
		update_option( 'default_product_cat', $this->old_term );

		$ids = $this->make_categorised( 1, 12345, array() );

		$this->run_all();

		$this->assertSame( array( 'Altkategorie' ), $this->categories( $ids[0] ) );
	}

	/**
	 * A category can be deleted between the run and the undo. Writing a term id
	 * that no longer exists would fail the whole product.
	 *
	 * @return void
	 */
	public function test_a_category_deleted_since_the_run_is_dropped() {
		$doomed = (int) wp_insert_term( 'Verschwunden', 'product_cat' )['term_id'];

		$ids = $this->make_categorised( 1, 12345, array( $this->old_term, $doomed ) );

		wp_delete_term( $doomed, 'product_cat' );

		$this->run_all();

		$this->assertSame( array( 'Altkategorie' ), $this->categories( $ids[0] ) );
		$this->assertSame( 1, Status::get( Revert::JOB )['counts']['restored'] );
	}

	/**
	 * A product drafted since the run still carries the stash, and leaving it
	 * behind would mean the undo quietly missed products.
	 *
	 * @return void
	 */
	public function test_a_product_drafted_since_the_run_is_still_restored() {
		$ids = $this->make_categorised( 1, 12345, array( $this->old_term ) );

		wp_update_post(
			array(
				'ID'          => $ids[0],
				'post_status' => 'draft',
			)
		);

		$this->run_all();

		$this->assertSame( array( 'Altkategorie' ), $this->categories( $ids[0] ) );
	}

	/**
	 * Only the run being undone is touched. A product from an earlier run that was
	 * never cleaned up must not be dragged into this one.
	 *
	 * @return void
	 */
	public function test_only_the_named_runs_products_are_touched() {
		$mine = $this->make_categorised( 1, 12345, array( $this->old_term ) );

		$other = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $other, array( $this->new_term ), 'product_cat' );
		update_post_meta( $other, Applier::META_PREVIOUS, array( $this->old_term ) );
		update_post_meta( $other, Applier::META_RUN, 999 );

		$this->run_all();

		$this->assertSame( array( 'Altkategorie' ), $this->categories( $mine[0] ) );
		$this->assertSame( array( 'Neukategorie' ), $this->categories( $other ), 'Another run\'s products are not this revert\'s business.' );
	}

	/**
	 * Nothing to undo has to be said before a run starts, not discovered in the
	 * middle of one.
	 *
	 * @return void
	 */
	public function test_a_revert_with_no_ledger_refuses_to_start() {
		( new Revert() )->start();

		$this->assertSame( 'failed', Status::get( Revert::JOB )['state'] );
	}

	/**
	 * An action from a superseded revert must not write anything.
	 *
	 * @return void
	 */
	public function test_a_superseded_batch_writes_nothing() {
		$ids = $this->make_categorised( 1, 12345, array( $this->old_term ) );

		$job = new Revert();
		$job->start();

		$run = (int) Status::get( Revert::JOB )['started'];

		$job->batch( 0, $run - 1 );

		$this->assertSame( array( 'Neukategorie' ), $this->categories( $ids[0] ) );
	}

	/**
	 * The explicit way to say "I am happy with this, drop the undo history". The
	 * categories themselves must not move.
	 *
	 * @return void
	 */
	public function test_forgetting_drops_the_stashes_and_leaves_the_categories_alone() {
		$ids = $this->make_categorised( 3, 12345, array( $this->old_term ) );

		$this->assertSame( 3, Revert::forget() );

		foreach ( $ids as $id ) {
			$this->assertSame( array( 'Neukategorie' ), $this->categories( $id ), 'Forgetting must not change a category.' );
			$this->assertSame( '', get_post_meta( $id, Applier::META_PREVIOUS, true ) );
			$this->assertSame( '', get_post_meta( $id, Applier::META_RUN, true ) );
		}

		$this->assertSame( array(), Revert::last_apply() );
	}

	/**
	 * The ledger has to be readable to size the progress bar and to name what the
	 * button will undo.
	 *
	 * @return void
	 */
	public function test_the_ledger_reports_what_the_last_run_did() {
		$this->make_categorised( 7, 4242, array( $this->old_term ) );

		$last = Revert::last_apply();

		$this->assertSame( 4242, $last['run'] );
		$this->assertSame( 7, $last['products'] );
		$this->assertGreaterThan( 0, $last['finished'] );
	}

	/**
	 * A malformed ledger must read as "nothing to undo" rather than as a run with
	 * an id of zero.
	 *
	 * @return void
	 */
	public function test_a_broken_ledger_reads_as_nothing_to_undo() {
		update_option( Revert::OPTION_KEY, 'nonsense', false );
		$this->assertSame( array(), Revert::last_apply() );

		update_option( Revert::OPTION_KEY, array( 'products' => 5 ), false );
		$this->assertSame( array(), Revert::last_apply() );
	}
}
