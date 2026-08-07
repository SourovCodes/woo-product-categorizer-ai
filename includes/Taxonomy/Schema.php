<?php
/**
 * The JSON Schemas the model is asked to answer in.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Builds both of the plugin's schemas.
 *
 * One file, so that strict mode's contract is stated once rather than remembered
 * in two places: every object must set `additionalProperties: false`, and every
 * property it declares must appear in `required`. There is no such thing as an
 * optional property in a strict schema — a field that may be absent has to be
 * declared nullable and always sent.
 */
class Schema {

	/**
	 * The most leaf ids that may be sent as an enum.
	 *
	 * Set well under the provider's own ceiling, because the limit is really on the
	 * total size of the enum rather than the count, and it costs input tokens on
	 * every request whether or not it is doing anything. Above this the enum is
	 * dropped and the ids are validated on the way back instead — which happens
	 * regardless, so nothing is lost but the shape guarantee.
	 */
	const ENUM_LIMIT = 250;

	/**
	 * Schema for a proposed category tree, nested to exactly the requested depth.
	 *
	 * Built explicitly per depth rather than with a recursive `$ref`. Recursion is
	 * accepted by the API and does work, but it cannot express a maximum depth — the
	 * cap would then live only in the prose of the prompt, which is a request rather
	 * than a constraint. Nesting the objects means a tree deeper than the shop asked
	 * for is not something the model is able to return.
	 *
	 * @param int $depth How many levels deep, 2 to 4.
	 * @return array A strict JSON Schema.
	 */
	public static function proposal( $depth ) {
		$depth = max( 2, min( 4, (int) $depth ) );

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'categories' ),
			'properties'           => array(
				'categories' => array(
					'type'  => 'array',
					'items' => self::node( 1, $depth ),
				),
			),
		);
	}

	/**
	 * One level of the proposal tree.
	 *
	 * The deepest level has no `children` property at all, rather than an empty
	 * array. Strict mode requires every declared property to be required, so a
	 * children key that exists but must be empty would oblige the model to send
	 * `"children": []` on every leaf in the tree — pure output tokens saying
	 * nothing.
	 *
	 * @param int $level Level being built, starting at 1.
	 * @param int $depth Deepest level allowed.
	 * @return array A schema for one node.
	 */
	protected static function node( $level, $depth ) {
		$node = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'name' ),
			'properties'           => array(
				'name' => array(
					'type'        => 'string',
					'description' => 'A short category name in the shop\'s own language.',
				),
			),
		);

		if ( $level < $depth ) {
			$node['required'][]             = 'children';
			$node['properties']['children'] = array(
				'type'  => 'array',
				'items' => self::node( $level + 1, $depth ),
			);
		}

		return $node;
	}

	/**
	 * Schema for a batch of category assignments.
	 *
	 * `category_id` is nullable and that is load-bearing: a product the model cannot
	 * place has to have a way of saying so. Without it the only options are an
	 * invented id or a wrong one, and both are worse than an honest blank.
	 *
	 * @param array $leaf_ids Every leaf key the model may choose from.
	 * @return array A strict JSON Schema.
	 */
	public static function assignment( array $leaf_ids ) {
		$category = array(
			'type'        => array( 'string', 'null' ),
			'description' => 'The id of the single best-fitting category, or null if none fits.',
		);

		/*
		 * The enum stops a malformed id at the wire, which saves a wasted product and
		 * some output tokens. It is emphatically not the validation: testing against
		 * the live API produced a valid id for the wrong product, so Applier checks
		 * every returned id against the run's own map whether or not this was sent.
		 */
		if ( count( $leaf_ids ) <= self::ENUM_LIMIT ) {
			$category['enum'] = array_values( array_merge( $leaf_ids, array( null ) ) );
		}

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'assignments' ),
			'properties'           => array(
				'assignments' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'ref', 'category_id' ),
						'properties'           => array(
							'ref'         => array(
								'type'        => 'string',
								'description' => 'The ref of the product this applies to, copied from the input.',
							),
							'category_id' => $category,
						),
					),
				),
			),
		);
	}
}
