<?php
/**
 * Undoing the last assignment run.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Categorize;

use WooProductCategorizerAi\Jobs\Preflight;
use WooProductCategorizerAi\Jobs\Scheduler;
use WooProductCategorizerAi\Jobs\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Puts every product a run touched back where it was.
 *
 * Driven by its own option rather than by the job status, because the status of
 * the assignment job is overwritten the moment a second run starts — and the whole
 * point of a revert is to be available after you have seen the results and decided
 * against them.
 */
class Revert {

	/**
	 * The job key this reports under.
	 */
	const JOB = 'revert';

	/**
	 * Option recording the last run that actually wrote something.
	 */
	const OPTION_KEY = 'woo_product_categorizer_ai_last_apply';

	/**
	 * How many products are restored per action.
	 *
	 * Larger than an assignment batch because there is no network here — this is a
	 * meta read and a term write per product, so the batch is sized to a comfortable
	 * amount of database work rather than to a request.
	 */
	const BATCH_SIZE = 100;

	/**
	 * What the last run left behind, if anything.
	 *
	 * @return array The ledger, or an empty array.
	 */
	public static function last_apply() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) || empty( $stored['run'] ) ) {
			return array();
		}

		return wp_parse_args(
			$stored,
			array(
				'run'      => 0,
				'finished' => 0,
				'products' => 0,
			)
		);
	}

	/**
	 * Start a revert.
	 *
	 * @return void
	 */
	public function start() {
		if ( Status::is_running( self::JOB ) ) {
			return;
		}

		$ready = Preflight::check( self::JOB );

		if ( is_wp_error( $ready ) ) {
			Status::fail( self::JOB, $ready->get_error_message() );
			return;
		}

		$last = self::last_apply();
		$run  = Status::start( self::JOB );

		Status::measure( self::JOB, (int) $last['products'] );

		Scheduler::chain(
			Scheduler::ACTION_REVERT_BATCH,
			array(
				'after_id' => 0,
				'run'      => $run,
			)
		);
	}

	/**
	 * Restore one batch of products.
	 *
	 * @param int $after_id Continue after this product id.
	 * @param int $run      The run this action belongs to.
	 * @return void
	 */
	public function batch( $after_id, $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			return;
		}

		$last = self::last_apply();

		if ( empty( $last ) ) {
			Status::fail( self::JOB, __( 'There is no longer a run to undo.', 'woo-product-categorizer-ai' ) );
			return;
		}

		$ids = self::next( $after_id, (int) $last['run'] );

		if ( empty( $ids ) ) {
			Scheduler::chain( Scheduler::ACTION_REVERT_FINALISE, array( 'run' => $run ) );
			return;
		}

		$counts = array();

		foreach ( $ids as $product_id ) {
			$outcome            = self::restore( $product_id );
			$counts[ $outcome ] = isset( $counts[ $outcome ] ) ? $counts[ $outcome ] + 1 : 1;
		}

		Status::progress( self::JOB, $counts );
		Status::advance( self::JOB, count( $ids ) );

		Scheduler::chain(
			Scheduler::ACTION_REVERT_BATCH,
			array(
				'after_id' => max( $ids ),
				'run'      => $run,
			)
		);
	}

	/**
	 * Put one product back.
	 *
	 * @param int $product_id Product to restore.
	 * @return string An outcome key.
	 */
	protected static function restore( $product_id ) {
		$previous = get_post_meta( $product_id, Applier::META_PREVIOUS, true );
		$previous = is_array( $previous ) ? array_map( 'intval', $previous ) : array();

		/*
		 * A category can be deleted between the run and the undo — by this plugin's own
		 * Create after an edit, or by hand. Writing a term id that no longer exists
		 * would fail the whole product, so the gone ones are simply dropped.
		 */
		$previous = array_values(
			array_filter(
				$previous,
				static function ( $term_id ) {
					return (bool) term_exists( $term_id, 'product_cat' );
				}
			)
		);

		if ( empty( $previous ) ) {
			/*
			 * Never write an empty set: WooCommerce re-adds the default category on the
			 * next save, so an empty write is both pointless and misleading. Restoring a
			 * product that had nothing means restoring it to the default.
			 */
			$default_term_id = Preflight::default_category_id();
			$previous        = $default_term_id > 0 ? array( $default_term_id ) : array();
		}

		if ( ! empty( $previous ) ) {
			$result = wp_set_object_terms( $product_id, $previous, 'product_cat', false );

			if ( is_wp_error( $result ) ) {
				Scheduler::log( 'error', sprintf( 'Could not restore product %d: %s', $product_id, $result->get_error_message() ) );

				return 'failed';
			}
		} else {
			// No previous categories and no default to fall back on: clear them.
			wp_set_object_terms( $product_id, array(), 'product_cat', false );
		}

		delete_post_meta( $product_id, Applier::META_PREVIOUS );
		delete_post_meta( $product_id, Applier::META_RUN );

		return 'restored';
	}

	/**
	 * The next page of products belonging to a run.
	 *
	 * @param int $after_id Return products with an id greater than this.
	 * @param int $run      The run whose products to find.
	 * @return array Product ids, ascending.
	 */
	protected static function next( $after_id, $run ) {
		$after_id = (int) $after_id;

		$where = static function ( $clause ) use ( $after_id ) {
			global $wpdb;

			return $clause . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
		};

		add_filter( 'posts_where', $where );

		$ids = get_posts(
			array(
				'post_type'              => 'product',

				/*
				 * Any status. A product drafted since the run was categorised still has
				 * this plugin's stash on it, and leaving that behind would mean the undo
				 * quietly missed products and the meta was never cleaned up.
				 */
				'post_status'            => 'any',
				'posts_per_page'         => self::BATCH_SIZE,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an indexed lookup on our own stamp; there is no other way to find a run's products.
					array(
						'key'   => Applier::META_RUN,
						'value' => (int) $run,
					),
				),
			)
		);

		remove_filter( 'posts_where', $where );

		return array_map( 'intval', $ids );
	}

	/**
	 * Close out a revert.
	 *
	 * @param int $run The run this action belongs to.
	 * @return void
	 */
	public function finalise( $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			return;
		}

		/*
		 * Clearing the ledger is what disables the button. Reverting twice is
		 * meaningless, and a second press would silently find nothing and report
		 * success.
		 */
		delete_option( self::OPTION_KEY );

		$counts   = (array) Status::get( self::JOB )['counts'];
		$restored = isset( $counts['restored'] ) ? (int) $counts['restored'] : 0;

		Status::finish(
			self::JOB,
			sprintf(
				/* translators: %s: number of products restored. */
				__( '%s products put back the way they were.', 'woo-product-categorizer-ai' ),
				number_format_i18n( $restored )
			)
		);
	}

	/**
	 * Forget the stashes without restoring anything.
	 *
	 * The explicit way to say "I am happy with this, drop the undo history". Without
	 * it the only options are keeping thousands of meta rows forever or having the
	 * plugin delete them on a schedule nobody asked for.
	 *
	 * @return int How many products were cleaned.
	 */
	public static function forget() {
		global $wpdb;

		$products = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- deleting our own meta across the catalogue; there is no API for a bulk meta delete.
			$wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Applier::META_RUN )
		);

		foreach ( $products as $product_id ) {
			delete_post_meta( (int) $product_id, Applier::META_PREVIOUS );
			delete_post_meta( (int) $product_id, Applier::META_RUN );
		}

		delete_option( self::OPTION_KEY );

		return count( $products );
	}
}
