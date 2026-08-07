<?php
/**
 * Writing a product's categories.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Categorize;

use Exception;
use WooProductCategorizerAi\Jobs\Preflight;
use WooProductCategorizerAi\Jobs\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a product's categories are changed.
 *
 * Every path into this — the real run, the dry run, a batch where override is off
 * — goes through apply(). One write path means the rules cannot disagree with each
 * other depending on who called.
 */
class Applier {

	/**
	 * Post meta holding the categories a product had before a run rewrote them.
	 */
	const META_PREVIOUS = '_wpcai_previous_cats';

	/**
	 * Post meta naming the run that last rewrote a product.
	 */
	const META_RUN = '_wpcai_run';

	/**
	 * How many invalid ids are logged in detail before the log gives up.
	 *
	 * A model having a bad day could otherwise write one warning per product, and
	 * the log becomes the largest thing on the site.
	 */
	const LOG_CAP = 20;

	/**
	 * How many warnings this request has already written.
	 *
	 * @var int
	 */
	protected static $logged = 0;

	/**
	 * File one product under one leaf category and its ancestors.
	 *
	 * @param int    $product_id Product to file.
	 * @param string $leaf_id    The leaf the model chose, or an empty string.
	 * @param array  $options    The run's frozen options.
	 * @return string One of the outcome keys.
	 */
	public static function apply( $product_id, $leaf_id, array $options ) {
		$product_id = (int) $product_id;

		// The model declined to place this product, which is a legitimate answer.
		if ( '' === $leaf_id ) {
			return 'unclassified';
		}

		if ( ! isset( $options['leaves'][ $leaf_id ] ) ) {
			self::log_invalid( $product_id, $leaf_id );

			return 'invalid_id';
		}

		$default_term_id = Preflight::default_category_id();

		/*
		 * Re-checked here even though Batch::partition() already filtered these out
		 * before the prompt was built. The skip is a rule about what may be written,
		 * and a rule enforced only by the caller is a rule that holds until someone
		 * adds a second caller.
		 */
		if ( empty( $options['override'] ) && Batch::has_real_category( $product_id, $default_term_id ) ) {
			return 'skipped_has_cats';
		}

		$term_ids = self::terms_for( (int) $options['leaves'][ $leaf_id ]['term'] );

		if ( empty( $term_ids ) ) {
			return 'invalid_id';
		}

		if ( ! empty( $options['dry_run'] ) ) {
			/*
			 * A dry run reaches this line having done every lookup, every check and
			 * every resolution the real run does, and stops at the three writes below.
			 * That is what makes its counts a prediction rather than a guess.
			 */
			return 'assigned';
		}

		$previous = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		$previous = is_wp_error( $previous ) ? array() : array_map( 'intval', $previous );

		/*
		 * Stashed before the write, and always overwritten. The ledger holds exactly
		 * one level of undo on purpose: two would need a schema, and nobody has ever
		 * wanted the state of two runs ago.
		 */
		update_post_meta( $product_id, self::META_PREVIOUS, $previous );
		update_post_meta( $product_id, self::META_RUN, (int) $options['run'] );

		try {
			// False replaces rather than appends, which is what "override" means.
			$result = wp_set_object_terms( $product_id, $term_ids, 'product_cat', false );
		} catch ( Exception $exception ) {
			$result = new \WP_Error( 'wpcai_write_failed', $exception->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			Scheduler::log( 'error', sprintf( 'Could not categorise product %d: %s', $product_id, $result->get_error_message() ) );

			return 'failed';
		}

		return 'assigned';
	}

	/**
	 * The term ids to write for a chosen leaf: the leaf and everything above it.
	 *
	 * Ancestors are included because a shop whose products sit only on the leaves
	 * has empty parent archives and a category menu that appears to lead nowhere.
	 *
	 * @param int $leaf_term_id The leaf term.
	 * @return array Term ids, root first.
	 */
	protected static function terms_for( $leaf_term_id ) {
		if ( ! term_exists( $leaf_term_id, 'product_cat' ) ) {
			return array();
		}

		$ancestors = get_ancestors( $leaf_term_id, 'product_cat', 'taxonomy' );
		$ancestors = array_map( 'intval', (array) $ancestors );

		// get_ancestors() returns nearest first; the shop reads better root first.
		$ancestors = array_reverse( $ancestors );

		$ancestors[] = (int) $leaf_term_id;

		return $ancestors;
	}

	/**
	 * Note an id the model returned that is not in this run's map.
	 *
	 * @param int    $product_id Product it was returned for.
	 * @param string $leaf_id    The unusable id.
	 * @return void
	 */
	protected static function log_invalid( $product_id, $leaf_id ) {
		++self::$logged;

		if ( self::$logged > self::LOG_CAP ) {
			return;
		}

		$message = sprintf( 'Product %d was given category id "%s", which is not in this run.', $product_id, $leaf_id );

		if ( self::LOG_CAP === self::$logged ) {
			$message .= ' Further occurrences in this batch will not be logged individually.';
		}

		Scheduler::log( 'warning', $message );
	}

	/**
	 * Forget how much has been logged.
	 *
	 * Called at the start of each batch so the cap applies per batch rather than
	 * per PHP process, which under WP-CLI would be the entire run.
	 *
	 * @return void
	 */
	public static function reset_log_cap() {
		self::$logged = 0;
	}
}
