<?php
/**
 * Building what the model is asked.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Categorize;

use WooProductCategorizerAi\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Composes the instructions and the input for both jobs.
 *
 * The split between the two is the whole cost model of this plugin. Whatever goes
 * in `instructions` is identical on every request of a run and is therefore served
 * from the provider's prompt cache — measured at 2,816 of 3,164 input tokens.
 * Whatever goes in `input` is paid for in full, every time. So the taxonomy, which
 * is large and constant, belongs in the instructions; the products, which are small
 * and different each time, belong in the input.
 *
 * The instructions are rendered **once** at the start of a run and frozen. Building
 * them per batch would still work and would silently cost the cache, which is the
 * kind of regression that shows up only on an invoice.
 */
class Prompt {

	/**
	 * Longest excerpt sent per product.
	 *
	 * Enough to tell a wooden animal from a wooden puzzle, short enough that 25 of
	 * them fit comfortably in one request. The first sentence or two of a product
	 * description carries almost all of the signal; the rest is materials and care
	 * instructions.
	 */
	const EXCERPT_MAX = 300;

	/**
	 * Instructions for a tree proposal.
	 *
	 * @param int    $depth    Deepest level allowed.
	 * @param string $guidance The shop's own instructions, possibly empty.
	 * @return string
	 */
	public static function proposal_instructions( $depth, $guidance ) {
		$lines = array(
			'You design the product category tree for a WooCommerce shop.',
			'',
			'You will be given a representative sample of product names from the catalogue.',
			'Propose a category tree that covers the whole catalogue, not just the sample.',
			'',
			'Rules:',
			sprintf( '- At most %d levels deep. Fewer is better when the catalogue does not warrant more.', (int) $depth ),

			/*
			 * Both of these lines were added after watching a real run. The schema makes
			 * "children" a required property at every level above the deepest, and the
			 * model read "required" as "populate it": every one of 49 real categories
			 * came back with a single sub-category repeating its own name, doubling the
			 * tree. Saying that an empty list is a legitimate answer is what stops it.
			 */
			'- A category with no sub-categories must have an empty "children" list. That is a normal and expected answer.',
			'- Never give a category a sub-category with the same name as itself.',

			'- Between 6 and 12 top-level categories.',
			'- Write the category names in the same language the product names are written in.',
			'- Names should be short and read like a shop menu, not like a database field.',
			'- Every product in the sample must plausibly belong to some deepest-level category.',
			'- Do not create a category for a single product.',
			'- Do not use brand or manufacturer names as categories. They are recorded separately.',
			'- Do not create an "Other" or "Miscellaneous" category. Products that fit nowhere are handled elsewhere.',
		);

		$guidance = trim( (string) $guidance );

		if ( '' !== $guidance ) {
			/*
			 * The shop's own guidance goes last and is labelled as coming from the shop
			 * owner. It is the one part of this prompt written by someone who has seen
			 * the catalogue, so where it disagrees with the generic advice above, it
			 * should win — and being last is what makes that clear.
			 */
			$lines[] = '';
			$lines[] = 'The shop owner has asked for the following, and it takes precedence over the general rules above:';
			$lines[] = $guidance;
		}

		return implode( "\n", $lines );
	}

	/**
	 * The sample, as the model receives it.
	 *
	 * @param array $names Product names.
	 * @return string
	 */
	public static function proposal_input( array $names ) {
		return "PRODUCT NAMES:\n" . implode( "\n", $names );
	}

	/**
	 * Instructions for an assignment batch.
	 *
	 * The taxonomy is rendered as full paths, never bare names. The model routinely
	 * proposes the same leaf name under several parents — "Deko" under three of them
	 * on the catalogue this was built for — so a bare name is genuinely ambiguous,
	 * and a path is both unambiguous and tells the model what the category is *for*.
	 *
	 * @param array  $leaves   Leaf id => array with a path array.
	 * @param string $guidance The shop's own instructions, possibly empty.
	 * @return string
	 */
	public static function assignment_instructions( array $leaves, $guidance ) {
		$lines = array(
			'You file products into a WooCommerce shop\'s existing category tree.',
			'',
			'For each product you are given, choose the single category that fits it best.',
			'',
			'Rules:',
			'- Answer with the category id exactly as written in the list below.',
			'- Choose exactly one category per product, and answer for every product you are given.',
			'- Copy each product\'s "ref" into your answer unchanged, so the answers can be matched up.',
			'- If no category is a reasonable fit, answer with null. Do not force a bad match.',
			'- Judge by what the product *is*, not by who makes it or what it is made of, unless the tree is organised that way.',
		);

		$guidance = trim( (string) $guidance );

		if ( '' !== $guidance ) {
			$lines[] = '';
			$lines[] = 'The shop owner has asked for the following, and it takes precedence over the general rules above:';
			$lines[] = $guidance;
		}

		$lines[] = '';
		$lines[] = 'CATEGORIES:';

		foreach ( $leaves as $id => $leaf ) {
			$lines[] = $id . "\t" . implode( ' > ', $leaf['path'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * A batch of products, as the model receives it.
	 *
	 * Sent as JSON rather than prose so that the boundary between one product and
	 * the next is unambiguous — product descriptions contain newlines, bullet lists
	 * and the occasional stray delimiter, and a line-based format quietly merges two
	 * products the moment one of them does.
	 *
	 * @param array $products Ref => product data.
	 * @return string
	 */
	public static function batch_input( array $products ) {
		$rows = array();

		foreach ( $products as $ref => $product ) {
			$row = array( 'ref' => (string) $ref );

			foreach ( array( 'name', 'brand', 'sku', 'description' ) as $field ) {
				if ( ! empty( $product[ $field ] ) ) {
					$row[ $field ] = $product[ $field ];
				}
			}

			$rows[] = $row;
		}

		return (string) wp_json_encode( array( 'products' => $rows ) );
	}

	/**
	 * Read what the model needs to know about one product.
	 *
	 * @param int $product_id Product to describe.
	 * @return array Name, brand, sku and a trimmed description.
	 */
	public static function describe( $product_id ) {
		$post = get_post( $product_id );

		if ( ! $post ) {
			return array();
		}

		$description = $post->post_excerpt;

		if ( '' === trim( (string) $description ) ) {
			$description = $post->post_content;
		}

		/*
		 * Tags out, then entities decoded. Catalogue text imported from an ERP is full
		 * of &#8211; and &amp;, and sending those spends tokens on markup while giving
		 * the model a slightly wrong idea of what the product is called.
		 */
		$description = wp_strip_all_tags( (string) $description );
		$description = wp_specialchars_decode( $description, ENT_QUOTES );
		$description = trim( (string) preg_replace( '/\s+/u', ' ', $description ) );

		return array(
			'name'        => wp_specialchars_decode( (string) $post->post_title, ENT_QUOTES ),
			'brand'       => self::brand( $product_id ),
			'sku'         => (string) get_post_meta( $product_id, '_sku', true ),
			'description' => mb_substr( $description, 0, self::EXCERPT_MAX ),
		);
	}

	/**
	 * The product's brand, if the shop records one.
	 *
	 * Included because it is real signal at almost no cost: on a catalogue of
	 * wooden toys and novelty gifts, knowing that something is made by a toy company
	 * settles most of the ambiguous cases in one word.
	 *
	 * @param int $product_id Product to read.
	 * @return string Brand name, or an empty string.
	 */
	protected static function brand( $product_id ) {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return '';
		}

		$terms = get_the_terms( $product_id, 'product_brand' );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		return (string) $terms[0]->name;
	}

	/**
	 * The guidance to send, read from the settings.
	 *
	 * @param array|null $settings Optional settings override.
	 * @return string
	 */
	public static function guidance( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : Settings::get_settings();

		return isset( $settings['guidance'] ) ? (string) $settings['guidance'] : '';
	}
}
