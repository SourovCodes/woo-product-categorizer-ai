<?php
/**
 * Walking the catalogue.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Categorize;

use WooProductCategorizerAi\Jobs\Preflight;

defined( 'ABSPATH' ) || exit;

/**
 * Pages through the products a run is responsible for.
 */
class Batch {

	/**
	 * The post statuses a scope covers.
	 *
	 * @param string $scope Either publish or publish_draft.
	 * @return array Post statuses.
	 */
	public static function statuses( $scope ) {
		return 'publish_draft' === $scope ? array( 'publish', 'draft' ) : array( 'publish' );
	}

	/**
	 * How many products are in scope.
	 *
	 * Used once, to give the progress bar something to measure against.
	 *
	 * @param string $scope Either publish or publish_draft.
	 * @return int Product count.
	 */
	public static function count( $scope ) {
		$query = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => self::statuses( $scope ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * The next page of product ids.
	 *
	 * Keyset paging — "the next N products with an id above this one" — rather than
	 * an offset. Two reasons, and the second is the one that actually bites: an
	 * offset walk degrades as it goes, because the database has to count past every
	 * row it is skipping; and it silently loses products, because any product
	 * published while the run is walking shifts every later row back by one and the
	 * next page steps straight over the boundary.
	 *
	 * @param int    $after_id Return products with an id greater than this.
	 * @param string $scope    Either publish or publish_draft.
	 * @param int    $size     How many to return.
	 * @return array Product ids, ascending.
	 */
	public static function next( $after_id, $scope, $size ) {
		$after_id = (int) $after_id;

		$where = static function ( $clause ) use ( $after_id ) {
			global $wpdb;

			return $clause . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
		};

		add_filter( 'posts_where', $where );

		$ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => self::statuses( $scope ),
				'posts_per_page'         => max( 1, (int) $size ),
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		remove_filter( 'posts_where', $where );

		return array_map( 'intval', $ids );
	}

	/**
	 * Split a page into the products worth asking about and the ones to skip.
	 *
	 * The skip test happens here, before a prompt is built, rather than inside the
	 * applier. Checking only at the write point would mean the run pays input and
	 * output tokens for every product it was never going to touch — on a catalogue
	 * that is already categorised, that is the entire bill for no effect.
	 *
	 * @param array $ids      Product ids.
	 * @param bool  $override Whether existing categories are replaced.
	 * @return array Two lists, keyed ask and skip.
	 */
	public static function partition( array $ids, $override ) {
		if ( $override ) {
			return array(
				'ask'  => $ids,
				'skip' => array(),
			);
		}

		$default_term_id = Preflight::default_category_id();
		$ask             = array();
		$skip            = array();

		foreach ( $ids as $id ) {
			if ( self::has_real_category( $id, $default_term_id ) ) {
				$skip[] = $id;
				continue;
			}

			$ask[] = $id;
		}

		return array(
			'ask'  => $ask,
			'skip' => $skip,
		);
	}

	/**
	 * Whether a product already has a category worth keeping.
	 *
	 * "Uncategorized" does not count. It is what WooCommerce files a product under
	 * when nobody has said anything, so treating it as an existing category would
	 * make "leave categorised products alone" skip the entire catalogue.
	 *
	 * @param int $product_id     Product to check.
	 * @param int $default_term_id The default category's term id.
	 * @return bool True when the product carries a real category.
	 */
	public static function has_real_category( $product_id, $default_term_id ) {
		$terms = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) ) {
			return false;
		}

		foreach ( $terms as $term_id ) {
			if ( (int) $term_id !== (int) $default_term_id ) {
				return true;
			}
		}

		return false;
	}
}
