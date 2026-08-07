<?php
/**
 * Choosing what the model sees of the catalogue.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Taxonomy;

use WooProductCategorizerAi\Categorize\Batch;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the sample a tree proposal is designed from.
 *
 * The tree has to cover the whole catalogue, but the whole catalogue does not fit
 * in one request and would not improve the answer if it did — a category scheme is
 * a summary, and 500 well-chosen names describe a shop about as well as 4,400 do.
 * What matters is that the sample is *representative*, because anything it misses
 * gets no category and every product like it is then unclassifiable.
 */
class Sampler {

	/**
	 * How many product names the proposal is built from.
	 *
	 * Measured: 480 names cost about 4,800 input tokens and produced a usable
	 * 61-term tree. Going much higher spends tokens on repetition — a shop's
	 * hundredth wooden animal says nothing the first ten did not.
	 */
	const SAMPLE_SIZE = 500;

	/**
	 * The most products read out of the database to sample from.
	 *
	 * A ceiling on the memory a proposal costs, not on the catalogue.
	 */
	const SCAN_LIMIT = 20000;

	/**
	 * Collect a representative sample of product names.
	 *
	 * Stratified by brand rather than taken at random. A shop's brands are already
	 * a rough proxy for the kind of thing it sells, and they are wildly unevenly
	 * sized: one supplier with 800 wooden toys would dominate any uniform sample and
	 * the tree would come back with six kinds of wooden toy and nowhere to put the
	 * stationery. Taking a share from each brand puts the long tail in front of the
	 * model, which is precisely where the categories it would otherwise miss live.
	 *
	 * @param string $scope Which products to draw from.
	 * @return array Sampled product names.
	 */
	public static function collect( $scope = 'publish_draft' ) {
		$ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => Batch::statuses( $scope ),
				'posts_per_page'         => self::SCAN_LIMIT,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		return self::names( self::choose( self::group_by_brand( $ids ) ) );
	}

	/**
	 * Bucket product ids by the brand they carry.
	 *
	 * Products with no brand share one bucket of their own rather than being
	 * dropped: on this catalogue they are a real slice of the shop, and they are
	 * disproportionately the odd items a tree needs to have somewhere to put.
	 *
	 * @param array $ids Product ids.
	 * @return array Brand term id => product ids.
	 */
	protected static function group_by_brand( array $ids ) {
		$buckets = array();

		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return array( 0 => $ids );
		}

		// One query for the lot; asking per product would be 4,386 of them.
		$terms = wp_get_object_terms(
			$ids,
			'product_brand',
			array(
				'fields'                 => 'all_with_object_id',
				'update_term_meta_cache' => false,
			)
		);

		$by_product = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				// A product in two brands is counted under the first; it only needs one home.
				if ( ! isset( $by_product[ $term->object_id ] ) ) {
					$by_product[ $term->object_id ] = (int) $term->term_id;
				}
			}
		}

		foreach ( $ids as $id ) {
			$brand = isset( $by_product[ $id ] ) ? $by_product[ $id ] : 0;

			$buckets[ $brand ][] = $id;
		}

		return $buckets;
	}

	/**
	 * Take a fair share from each bucket, up to the sample size.
	 *
	 * Buckets are walked smallest first and each is allowed an equal share of
	 * whatever is left. A small brand therefore contributes all of its products and
	 * hands its unused allowance back to the larger ones, rather than the largest
	 * brand claiming its share first and starving the tail.
	 *
	 * @param array $buckets Brand term id => product ids.
	 * @return array Sampled product ids.
	 */
	protected static function choose( array $buckets ) {
		usort(
			$buckets,
			static function ( $left, $right ) {
				return count( $left ) <=> count( $right );
			}
		);

		$chosen       = array();
		$remaining    = self::SAMPLE_SIZE;
		$buckets_left = count( $buckets );

		foreach ( $buckets as $bucket ) {
			if ( $remaining < 1 ) {
				break;
			}

			$size  = count( $bucket );
			$share = max( 1, (int) floor( $remaining / max( 1, $buckets_left ) ) );
			$take  = min( $share, $size );

			/*
			 * Spread across the bucket rather than taking the first N. Products are
			 * ordered by id, which is import order, which for a catalogue loaded from an
			 * ERP means the first N of a brand are frequently one product line in a row.
			 */
			$step = max( 1, (int) floor( $size / max( 1, $take ) ) );

			for ( $index = 0; $index < $size && $take > 0; $index += $step ) {
				$chosen[] = $bucket[ $index ];
				--$take;
			}

			$remaining = self::SAMPLE_SIZE - count( $chosen );
			--$buckets_left;
		}

		return $chosen;
	}

	/**
	 * Read the names of the chosen products.
	 *
	 * Titles only. A tree is designed from what things are called, and adding
	 * descriptions would multiply the input tokens by twenty to tell the model the
	 * same thing at greater length.
	 *
	 * @param array $ids Product ids.
	 * @return array Product names, deduplicated.
	 */
	protected static function names( array $ids ) {
		$names = array();

		foreach ( $ids as $id ) {
			/*
			 * Entity-decoded before it is sent. Titles imported from an ERP arrive full
			 * of &#8211; and &amp;, and a model reading "BubbleSaver &#8211; für
			 * stressfreien Seifenblasen-Spass" is spending tokens on markup and being
			 * given a slightly wrong idea of what the product is called.
			 */
			$title = trim( wp_specialchars_decode( (string) get_the_title( $id ), ENT_QUOTES ) );

			if ( '' !== $title ) {
				$names[] = $title;
			}
		}

		/*
		 * Deduplicated because a repeated name is a repeated token for no extra
		 * information, and catalogues loaded from an ERP repeat far more than you
		 * would expect — the same item in six colours, listed six times.
		 */
		return array_values( array_unique( $names ) );
	}
}
