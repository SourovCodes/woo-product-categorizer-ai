<?php
/**
 * The JSON Schemas sent to the provider.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Taxonomy\Schema;
use WP_UnitTestCase;

/**
 * Strict mode is unforgiving, and the API rejects a schema that breaks its rules
 * with a 400 that says very little. These assert the rules directly.
 */
class SchemaTest extends WP_UnitTestCase {

	/**
	 * Walk every object in a schema.
	 *
	 * @param array    $schema  Schema fragment.
	 * @param callable $visitor Called with each object node.
	 * @return void
	 */
	protected function walk( array $schema, callable $visitor ) {
		$type = isset( $schema['type'] ) ? $schema['type'] : '';

		if ( 'object' === $type ) {
			$visitor( $schema );

			foreach ( (array) $schema['properties'] as $property ) {
				if ( is_array( $property ) ) {
					$this->walk( $property, $visitor );
				}
			}
		}

		if ( 'array' === $type && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$this->walk( $schema['items'], $visitor );
		}
	}

	/**
	 * How deep a proposal schema nests.
	 *
	 * @param array $schema Proposal schema.
	 * @param int   $level  Level being counted.
	 * @return int The deepest level reachable.
	 */
	protected function depth_of( array $schema, $level = 1 ) {
		$node = $schema['properties']['categories']['items'];
		$deep = $level;

		while ( isset( $node['properties']['children'] ) ) {
			++$deep;
			$node = $node['properties']['children']['items'];
		}

		return $deep;
	}

	/**
	 * The depth cap has to be expressed in the schema, not merely requested in the
	 * prompt, or it is advice rather than a limit.
	 *
	 * @return void
	 */
	public function test_a_proposal_schema_nests_exactly_as_deep_as_asked() {
		$this->assertSame( 2, $this->depth_of( Schema::proposal( 2 ) ) );
		$this->assertSame( 3, $this->depth_of( Schema::proposal( 3 ) ) );
		$this->assertSame( 4, $this->depth_of( Schema::proposal( 4 ) ) );
	}

	/**
	 * A depth outside the offered range must still produce a usable schema rather
	 * than an unbounded or degenerate one.
	 *
	 * @return void
	 */
	public function test_an_out_of_range_depth_is_clamped() {
		$this->assertSame( 2, $this->depth_of( Schema::proposal( 0 ) ) );
		$this->assertSame( 4, $this->depth_of( Schema::proposal( 99 ) ) );
	}

	/**
	 * There is no optional property in strict mode. A children key at the deepest
	 * level would have to be required, forcing an empty array on every leaf.
	 *
	 * @return void
	 */
	public function test_the_deepest_level_has_no_children_property_at_all() {
		$node = Schema::proposal( 3 )['properties']['categories']['items'];

		$node = $node['properties']['children']['items'];
		$this->assertArrayHasKey( 'children', $node['properties'], 'Level two should still nest.' );

		$node = $node['properties']['children']['items'];
		$this->assertArrayNotHasKey( 'children', $node['properties'], 'Level three is the deepest and must not.' );
	}

	/**
	 * Both of strict mode's rules, on every object in both schemas.
	 *
	 * @return void
	 */
	public function test_every_object_satisfies_strict_mode() {
		$schemas = array(
			Schema::proposal( 2 ),
			Schema::proposal( 4 ),
			Schema::assignment( array( 'L1', 'L2' ) ),
			Schema::assignment( range( 1, Schema::ENUM_LIMIT + 100 ) ),
		);

		foreach ( $schemas as $schema ) {
			$this->walk(
				$schema,
				function ( $node ) {
					$this->assertFalse( $node['additionalProperties'], 'Strict mode requires additionalProperties: false.' );
					$this->assertSame(
						array_keys( $node['properties'] ),
						$node['required'],
						'Strict mode requires every declared property to be required.'
					);
				}
			);
		}
	}

	/**
	 * The enum is a cheap guard while it is affordable.
	 *
	 * @return void
	 */
	public function test_the_enum_is_sent_for_a_tree_small_enough_to_afford_it() {
		$schema   = Schema::assignment( array( 'L1', 'L2', 'L3' ) );
		$category = $schema['properties']['assignments']['items']['properties']['category_id'];

		$this->assertArrayHasKey( 'enum', $category );
		$this->assertContains( 'L1', $category['enum'] );
	}

	/**
	 * Above the limit it is dropped, because it costs input tokens on every request
	 * and validation happens on the way back regardless.
	 *
	 * @return void
	 */
	public function test_the_enum_is_dropped_for_a_tree_too_large_for_it() {
		$leaves = array();

		for ( $index = 0; $index <= Schema::ENUM_LIMIT; $index++ ) {
			$leaves[] = 'L' . $index;
		}

		$schema   = Schema::assignment( $leaves );
		$category = $schema['properties']['assignments']['items']['properties']['category_id'];

		$this->assertArrayNotHasKey( 'enum', $category );
	}

	/**
	 * A product the model cannot place must have a way of saying so. Without null
	 * the only options are an invented id or a wrong one.
	 *
	 * @return void
	 */
	public function test_null_is_always_an_allowed_answer() {
		foreach ( array( array( 'L1' ), range( 1, Schema::ENUM_LIMIT + 10 ) ) as $leaves ) {
			$category = Schema::assignment( $leaves )['properties']['assignments']['items']['properties']['category_id'];

			$this->assertContains( 'null', (array) $category['type'] );

			if ( isset( $category['enum'] ) ) {
				$this->assertContains( null, $category['enum'] );
			}
		}
	}

	/**
	 * The answer has to carry the ref back, or nothing can be matched to a product.
	 *
	 * @return void
	 */
	public function test_an_assignment_must_carry_a_ref_and_a_category() {
		$item = Schema::assignment( array( 'L1' ) )['properties']['assignments']['items'];

		$this->assertSame( array( 'ref', 'category_id' ), $item['required'] );
	}

	/**
	 * The schemas have to survive wp_json_encode intact, because that is how they
	 * reach the API.
	 *
	 * @return void
	 */
	public function test_the_schemas_encode_to_json() {
		$this->assertIsString( wp_json_encode( Schema::proposal( 3 ) ) );
		$this->assertIsString( wp_json_encode( Schema::assignment( array( 'L1' ) ) ) );
	}
}
