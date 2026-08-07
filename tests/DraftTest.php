<?php
/**
 * The draft's round trip through the edit form.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Taxonomy\Draft;
use WP_UnitTestCase;

/**
 * A tree that has to survive $_POST is where this plugin is most likely to lose
 * someone's work quietly, so the rules that stop it are each pinned here.
 */
class DraftTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Draft::OPTION_KEY );
		delete_option( Draft::BACKUP_KEY );

		parent::tear_down();
	}

	/**
	 * A three-node draft: Wohnen > Deko, and Spielwaren.
	 *
	 * @return array
	 */
	protected function stored() {
		$draft = Draft::blank();

		$draft['nodes'] = array(
			array(
				'key'    => 'n1',
				'parent' => '',
				'name'   => 'Wohnen',
				'depth'  => 1,
			),
			array(
				'key'    => 'n2',
				'parent' => 'n1',
				'name'   => 'Deko',
				'depth'  => 2,
			),
			array(
				'key'    => 'n3',
				'parent' => '',
				'name'   => 'Spielwaren',
				'depth'  => 1,
			),
		);

		return $draft;
	}

	/**
	 * Submit an edit.
	 *
	 * @param array  $rows      Submitted rows.
	 * @param string $additions Additions box.
	 * @param int    $depth     Deepest level allowed.
	 * @return array The result.
	 */
	protected function submit( array $rows, $additions = '', $depth = 3 ) {
		return Draft::from_request( $this->stored(), $rows, $additions, $depth );
	}

	/**
	 * Read the names out of a result.
	 *
	 * @param array $result The result.
	 * @return array Names.
	 */
	protected function names( array $result ) {
		return wp_list_pluck( $result['draft']['nodes'], 'name' );
	}

	/**
	 * The baseline: submitting the form unchanged changes nothing.
	 *
	 * @return void
	 */
	public function test_an_unchanged_submission_round_trips_intact() {
		$result = $this->submit(
			array(
				'n1' => array( 'name' => 'Wohnen' ),
				'n2' => array( 'name' => 'Deko' ),
				'n3' => array( 'name' => 'Spielwaren' ),
			)
		);

		$this->assertSame( array( 'Wohnen', 'Deko', 'Spielwaren' ), $this->names( $result ) );
		$this->assertSame( 0, $result['counts']['renamed'] );
	}

	/**
	 * The single most likely edit anyone makes. A key derived from the name would
	 * change here and orphan the child.
	 *
	 * @return void
	 */
	public function test_renaming_a_node_keeps_its_key_and_its_children() {
		$result = $this->submit(
			array(
				'n1' => array( 'name' => 'Wohnen & Deko' ),
				'n2' => array( 'name' => 'Deko' ),
				'n3' => array( 'name' => 'Spielwaren' ),
			)
		);

		$nodes = $result['draft']['nodes'];

		$this->assertSame( 'Wohnen & Deko', $nodes[0]['name'] );
		$this->assertSame( 'n1', $nodes[0]['key'] );
		$this->assertSame( 'n1', $nodes[1]['parent'], 'The child must still point at its parent.' );
		$this->assertSame( 1, $result['counts']['renamed'] );
	}

	/**
	 * Removing a category removes what is filed under it. A child left pointing at
	 * a parent that is gone would be a category with no path.
	 *
	 * @return void
	 */
	public function test_removing_a_node_removes_its_whole_subtree() {
		$result = $this->submit(
			array(
				'n1' => array(
					'name'   => 'Wohnen',
					'remove' => '1',
				),
			)
		);

		$this->assertSame( array( 'Spielwaren' ), $this->names( $result ) );
		$this->assertSame( 1, $result['counts']['removed'] );
	}

	/**
	 * Clearing the field is the same request as ticking the box, said differently.
	 *
	 * @return void
	 */
	public function test_a_blank_name_removes_the_node() {
		$result = $this->submit( array( 'n2' => array( 'name' => '   ' ) ) );

		$this->assertSame( array( 'Wohnen', 'Spielwaren' ), $this->names( $result ) );
	}

	/**
	 * The settings' rule, applied to a tree: absence is not deletion. If it were, a
	 * browser that dropped a field would silently delete a category.
	 *
	 * @return void
	 */
	public function test_an_absent_node_keeps_its_stored_value() {
		$result = $this->submit( array( 'n1' => array( 'name' => 'Wohnen' ) ) );

		$this->assertSame( array( 'Wohnen', 'Deko', 'Spielwaren' ), $this->names( $result ) );
	}

	/**
	 * A crafted POST must not be able to invent nodes.
	 *
	 * @return void
	 */
	public function test_an_unknown_key_is_discarded() {
		$result = $this->submit(
			array(
				'n1'      => array( 'name' => 'Wohnen' ),
				'evil'    => array( 'name' => 'Injected' ),
				'n999999' => array( 'name' => 'Also injected' ),
			)
		);

		$this->assertNotContains( 'Injected', $this->names( $result ) );
		$this->assertNotContains( 'Also injected', $this->names( $result ) );
		$this->assertCount( 3, $result['draft']['nodes'] );
	}

	/**
	 * Re-parenting is not offered, so a submitted parent must be ignored rather
	 * than trusted — accepting it would mean validating for cycles on every save.
	 *
	 * @return void
	 */
	public function test_a_submitted_parent_is_ignored() {
		$result = $this->submit(
			array(
				'n2' => array(
					'name'   => 'Deko',
					'parent' => 'n3',
				),
			)
		);

		$deko = $result['draft']['nodes'][1];

		$this->assertSame( 'n1', $deko['parent'], 'The parent comes from the stored draft, never the POST.' );
	}

	/**
	 * The additions box is how anything new gets in.
	 *
	 * @return void
	 */
	public function test_an_addition_creates_the_whole_path() {
		$result = $this->submit( array(), "Garten > Moebel > Stuehle\n" );

		$names = $this->names( $result );

		$this->assertContains( 'Garten', $names );
		$this->assertContains( 'Moebel', $names );
		$this->assertContains( 'Stuehle', $names );
		$this->assertSame( 3, $result['counts']['added'] );
	}

	/**
	 * Typing a path under an existing category has to extend it, not create a
	 * second copy of it.
	 *
	 * @return void
	 */
	public function test_an_addition_reuses_existing_levels() {
		$result = $this->submit( array(), 'Wohnen > Deko > Kerzen' );

		$names = $this->names( $result );

		$this->assertSame( 1, count( array_keys( $names, 'Wohnen', true ) ) );
		$this->assertSame( 1, count( array_keys( $names, 'Deko', true ) ) );
		$this->assertContains( 'Kerzen', $names );
		$this->assertSame( 1, $result['counts']['added'], 'Only the new level counts as added.' );
	}

	/**
	 * Someone typing a path from memory should not create a near-duplicate over a
	 * capital letter.
	 *
	 * @return void
	 */
	public function test_addition_matching_ignores_case() {
		$result = $this->submit( array(), 'wohnen > DEKO > Kerzen' );

		$names = $this->names( $result );

		$this->assertSame( 1, count( array_keys( $names, 'Wohnen', true ) ) );
		$this->assertSame( 1, count( array_keys( $names, 'Deko', true ) ) );
	}

	/**
	 * Quietly filing a category one level above where it was asked for is worse
	 * than saying it did not fit.
	 *
	 * @return void
	 */
	public function test_a_path_deeper_than_the_limit_is_rejected_not_truncated() {
		$result = $this->submit( array(), 'A > B > C > D', 3 );

		$names = $this->names( $result );

		$this->assertNotContains( 'A', $names );
		$this->assertNotContains( 'D', $names );
		$this->assertSame( 1, $result['counts']['rejected'] );
		$this->assertSame( 0, $result['counts']['added'] );
	}

	/**
	 * A path typed against a category being removed in the same submission must
	 * not resurrect it by matching against the old tree.
	 *
	 * @return void
	 */
	public function test_an_addition_is_matched_against_the_edited_tree() {
		$result = $this->submit(
			array(
				'n1' => array(
					'name'   => 'Wohnen',
					'remove' => '1',
				),
			),
			'Wohnen > Kissen'
		);

		$names = $this->names( $result );

		$this->assertContains( 'Kissen', $names );
		$this->assertSame( 1, count( array_keys( $names, 'Wohnen', true ) ) );
		$this->assertSame( 2, $result['counts']['added'], 'Both levels are new, because the old Wohnen went.' );
	}

	/**
	 * Blank lines and stray separators are what a real paste looks like.
	 *
	 * @return void
	 */
	public function test_empty_and_ragged_lines_are_ignored() {
		$result = $this->submit( array(), "\n\n  \n> > >\nGarten\n" );

		$this->assertSame( 1, $result['counts']['added'] );
		$this->assertContains( 'Garten', $this->names( $result ) );
	}

	/**
	 * Two children of the same parent with the same name are indistinguishable in
	 * every UI WooCommerce has.
	 *
	 * @return void
	 */
	public function test_renaming_a_node_onto_its_sibling_merges_them() {
		$draft          = Draft::blank();
		$draft['nodes'] = array(
			array(
				'key'    => 'n1',
				'parent' => '',
				'name'   => 'Wohnen',
				'depth'  => 1,
			),
			array(
				'key'    => 'n2',
				'parent' => 'n1',
				'name'   => 'Deko',
				'depth'  => 2,
			),
			array(
				'key'    => 'n3',
				'parent' => 'n1',
				'name'   => 'Dekoration',
				'depth'  => 2,
			),
			array(
				'key'    => 'n4',
				'parent' => 'n3',
				'name'   => 'Kerzen',
				'depth'  => 3,
			),
		);

		$result = Draft::from_request( $draft, array( 'n3' => array( 'name' => 'Deko' ) ), '', 3 );

		$nodes = $result['draft']['nodes'];
		$names = wp_list_pluck( $nodes, 'name' );

		$this->assertSame( 1, count( array_keys( $names, 'Deko', true ) ) );
		$this->assertContains( 'Kerzen', $names, 'The merged node\'s children must survive.' );

		// Kerzen now hangs off the surviving Deko.
		$kerzen = end( $nodes );
		$this->assertSame( 'n2', $kerzen['parent'] );
	}

	/**
	 * The same name under different parents is the normal case, not a duplicate.
	 * It is what path-derived slugs exist to handle.
	 *
	 * @return void
	 */
	public function test_the_same_name_under_different_parents_survives_an_edit() {
		$result = $this->submit( array( 'n3' => array( 'name' => 'Deko' ) ) );

		$names = $this->names( $result );

		$this->assertSame( 2, count( array_keys( $names, 'Deko', true ) ) );
	}

	/**
	 * Editing has to mark the draft, because that is what makes a later proposal
	 * back it up instead of destroying it.
	 *
	 * @return void
	 */
	public function test_saving_marks_the_draft_as_edited_without_rewriting_its_provenance() {
		$stored              = $this->stored();
		$stored['generated'] = 1700000000;
		$stored['model']     = 'gpt-5.4-mini';

		$result = Draft::from_request( $stored, array( 'n1' => array( 'name' => 'Wohnraum' ) ), '', 3 );

		$this->assertGreaterThan( 0, $result['draft']['edited'] );
		$this->assertSame( 1700000000, $result['draft']['generated'], 'Provenance describes the model\'s answer, not the edit.' );
		$this->assertSame( 'gpt-5.4-mini', $result['draft']['model'] );
	}

	/**
	 * Depth is derived from the ancestry, so an edit cannot leave a row claiming a
	 * level its parents do not support.
	 *
	 * @return void
	 */
	public function test_depths_are_recomputed_from_the_ancestry() {
		$result = $this->submit( array(), 'Garten > Moebel > Stuehle' );

		$levels = wp_list_pluck( $result['draft']['nodes'], 'depth', 'name' );

		$this->assertSame( 1, $levels['Garten'] );
		$this->assertSame( 2, $levels['Moebel'] );
		$this->assertSame( 3, $levels['Stuehle'] );
	}

	/**
	 * The nodes stay in pre-order, which is what lets the editor render the table
	 * in one pass and the creator resolve a parent before its children.
	 *
	 * @return void
	 */
	public function test_nodes_stay_in_pre_order() {
		$result = $this->submit( array(), 'Wohnen > Kissen' );

		$nodes = $result['draft']['nodes'];

		foreach ( $nodes as $index => $node ) {
			if ( '' === $node['parent'] ) {
				continue;
			}

			$parent_at = null;

			foreach ( $nodes as $other_index => $other ) {
				if ( $other['key'] === $node['parent'] ) {
					$parent_at = $other_index;
					break;
				}
			}

			$this->assertNotNull( $parent_at, 'Every node must have a parent in the list.' );
			$this->assertLessThan( $index, $parent_at, 'A parent must appear before its children.' );
		}
	}

	/**
	 * Keys have to be unique, or two nodes share a form field.
	 *
	 * @return void
	 */
	public function test_minted_keys_are_unique() {
		$keys = array();

		for ( $index = 0; $index < 200; $index++ ) {
			$keys[] = Draft::mint_key();
		}

		$this->assertCount( 200, array_unique( $keys ) );
	}

	/**
	 * Keys go into form field names, so they have to survive sanitize_key().
	 *
	 * @return void
	 */
	public function test_minted_keys_survive_being_used_as_field_names() {
		for ( $index = 0; $index < 50; $index++ ) {
			$key = Draft::mint_key();

			$this->assertSame( $key, sanitize_key( $key ) );
		}
	}
}
