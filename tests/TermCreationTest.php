<?php
/**
 * Turning a draft into real product categories.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Taxonomy\Creator;
use WooProductCategorizerAi\Taxonomy\Draft;
use WP_UnitTestCase;

/**
 * The slug collision is the headline case here and has its own test: it is the
 * defect this class exists to prevent, and it is invisible until someone follows a
 * category URL.
 */
class TermCreationTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Draft::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Build a draft from an indented list of paths.
	 *
	 * @param array $paths Full paths, each an array of names.
	 * @return array A draft.
	 */
	protected function draft_from_paths( array $paths ) {
		$draft = Draft::blank();
		$nodes = array();
		$keys  = array();

		foreach ( $paths as $path ) {
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

		$draft['nodes'] = $nodes;

		return $draft;
	}

	/**
	 * The slug of the term created for a path.
	 *
	 * @param array $path Names from the root down.
	 * @return string|null
	 */
	protected function slug_at( array $path ) {
		$term = get_term_by( 'slug', Creator::path_slug( $path ), 'product_cat' );

		return $term instanceof \WP_Term ? $term->slug : null;
	}

	/**
	 * The defect this class exists to prevent.
	 *
	 * WooCommerce allows same-name terms under different parents, but slugs are
	 * global: wp_insert_term() silently appends -2 and -3, and which term gets which
	 * suffix depends on insertion order.
	 *
	 * @return void
	 */
	public function test_the_same_leaf_name_under_three_parents_gets_three_meaningful_slugs() {
		$draft = $this->draft_from_paths(
			array(
				array( 'Wohnen', 'Deko' ),
				array( 'Garten', 'Deko' ),
				array( 'Weihnachten', 'Deko' ),
			)
		);

		$result = Creator::create_from_draft( $draft );

		$this->assertSame( 6, $result['counts']['created'] );

		$slugs = wp_list_pluck(
			get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			),
			'slug'
		);

		$this->assertContains( 'wohnen-deko', $slugs );
		$this->assertContains( 'garten-deko', $slugs );
		$this->assertContains( 'weihnachten-deko', $slugs );

		foreach ( $slugs as $slug ) {
			$this->assertStringNotContainsString( 'deko-2', $slug );
			$this->assertStringNotContainsString( 'deko-3', $slug );
		}
	}

	/**
	 * Top-level categories are the URLs anyone actually looks at, and there is
	 * nothing above them to disambiguate against.
	 *
	 * @return void
	 */
	public function test_a_top_level_category_keeps_its_bare_slug() {
		Creator::create_from_draft( $this->draft_from_paths( array( array( 'Spielwaren', 'Holztiere' ) ) ) );

		$this->assertSame( 'spielwaren', $this->slug_at( array( 'Spielwaren' ) ) );
		$this->assertSame( 'spielwaren-holztiere', $this->slug_at( array( 'Spielwaren', 'Holztiere' ) ) );
	}

	/**
	 * Pressing Create again after no edits must be a no-op, or the button is a way
	 * to end up with two of every category.
	 *
	 * @return void
	 */
	public function test_creating_twice_from_an_unchanged_draft_changes_nothing() {
		$draft = $this->draft_from_paths(
			array(
				array( 'Wohnen', 'Deko' ),
				array( 'Spielwaren' ),
			)
		);

		$first = Creator::create_from_draft( $draft );
		$this->assertSame( 3, $first['counts']['created'] );

		$before = wp_count_terms( array( 'taxonomy' => 'product_cat' ) );

		$second = Creator::create_from_draft( $draft );

		$this->assertSame( 0, $second['counts']['created'] );
		$this->assertSame( 3, $second['counts']['unchanged'] );
		$this->assertSame( $before, wp_count_terms( array( 'taxonomy' => 'product_cat' ) ) );
	}

	/**
	 * WordPress does not store what you give it: a term created as "Basteln &
	 * Malen" comes back as "Basteln &amp; Malen". Comparing the raw strings
	 * reported a rename on every run — 11 of 63 real categories rewritten forever,
	 * on every press of Create, for no change at all.
	 *
	 * @return void
	 */
	public function test_an_ampersand_in_a_name_is_not_mistaken_for_a_rename() {
		$draft = $this->draft_from_paths(
			array(
				array( 'Basteln & Malen', 'Knete' ),
				array( 'Mode & Accessoires' ),
			)
		);

		$this->assertSame( 3, Creator::create_from_draft( $draft )['counts']['created'] );

		$second = Creator::create_from_draft( $draft );

		$this->assertSame( 0, $second['counts']['renamed'], 'An unchanged name must never read as renamed.' );
		$this->assertSame( 3, $second['counts']['unchanged'] );
	}

	/**
	 * The decoding must not hide a real rename.
	 *
	 * @return void
	 */
	public function test_a_genuine_rename_is_still_detected_around_an_ampersand() {
		$draft = $this->draft_from_paths( array( array( 'Basteln & Malen' ) ) );

		Creator::create_from_draft( $draft );

		$draft['nodes'][0]['name'] = 'Basteln & Zeichnen';

		$this->assertSame( 1, Creator::create_from_draft( $draft )['counts']['renamed'] );
	}

	/**
	 * The slug is in every URL already pointing at the archive. A stale slug beside
	 * a new name is invisible to shoppers; a changed one is a 404.
	 *
	 * @return void
	 */
	public function test_renaming_a_category_never_changes_its_slug() {
		$draft = $this->draft_from_paths( array( array( 'Wohnen', 'Deko' ) ) );

		Creator::create_from_draft( $draft );

		$term_id = get_term_by( 'slug', 'wohnen-deko', 'product_cat' )->term_id;

		$draft['nodes'][1]['name'] = 'Dekoration';

		$result = Creator::create_from_draft( $draft );

		$term = get_term( $term_id, 'product_cat' );

		$this->assertSame( 'Dekoration', $term->name );
		$this->assertSame( 'wohnen-deko', $term->slug, 'The slug must survive a rename.' );
		$this->assertSame( 1, $result['counts']['renamed'] );
	}

	/**
	 * The path hash is derived from the names, so a rename changes it. Matching on
	 * the hash alone therefore found nothing and created a second category beside
	 * the first — the exact duplication this class exists to prevent, triggered by
	 * the single most common edit anyone makes to a draft. The node key is the
	 * stable identity and has to be tried first.
	 *
	 * @return void
	 */
	public function test_renaming_a_category_does_not_create_a_second_one() {
		$draft = $this->draft_from_paths(
			array(
				array( 'Wohnen', 'Deko' ),
				array( 'Spielwaren' ),
			)
		);

		Creator::create_from_draft( $draft );

		$before = wp_count_terms( array( 'taxonomy' => 'product_cat' ) );

		$draft['nodes'][1]['name'] = 'Dekoration';
		Creator::create_from_draft( $draft );

		$this->assertSame( $before, wp_count_terms( array( 'taxonomy' => 'product_cat' ) ), 'A rename must not add a term.' );
		$this->assertFalse( get_term_by( 'slug', 'wohnen-dekoration', 'product_cat' ), 'The rename must not mint a new slug.' );
	}

	/**
	 * A fresh proposal mints all-new node keys, so the path hash is what carries an
	 * existing tree across one rather than duplicating every category in it.
	 *
	 * @return void
	 */
	public function test_a_new_draft_of_the_same_tree_reuses_the_existing_terms() {
		$paths = array(
			array( 'Wohnen', 'Deko' ),
			array( 'Spielwaren' ),
		);

		Creator::create_from_draft( $this->draft_from_paths( $paths ) );

		$before = wp_count_terms( array( 'taxonomy' => 'product_cat' ) );

		// Same tree, different keys — exactly what re-proposing produces.
		$result = Creator::create_from_draft( $this->draft_from_paths( $paths ) );

		$this->assertSame( $before, wp_count_terms( array( 'taxonomy' => 'product_cat' ) ) );
		$this->assertSame( 0, $result['counts']['created'] );
	}

	/**
	 * A shop that already made that category by hand should have it taken over, not
	 * duplicated beside it.
	 *
	 * @return void
	 */
	public function test_an_existing_category_at_the_same_place_is_adopted() {
		$parent = wp_insert_term( 'Wohnen', 'product_cat' );
		wp_insert_term( 'Deko', 'product_cat', array( 'parent' => $parent['term_id'] ) );

		$before = wp_count_terms( array( 'taxonomy' => 'product_cat' ) );

		$result = Creator::create_from_draft( $this->draft_from_paths( array( array( 'Wohnen', 'Deko' ) ) ) );

		$this->assertSame( 2, $result['counts']['adopted'] );
		$this->assertSame( 0, $result['counts']['created'] );
		$this->assertSame( $before, wp_count_terms( array( 'taxonomy' => 'product_cat' ) ) );
	}

	/**
	 * Once adopted, a term is stamped, so the next run recognises it immediately
	 * rather than adopting it all over again.
	 *
	 * @return void
	 */
	public function test_an_adopted_category_is_stamped_and_then_reported_as_unchanged() {
		wp_insert_term( 'Spielwaren', 'product_cat' );

		$draft = $this->draft_from_paths( array( array( 'Spielwaren' ) ) );

		$this->assertSame( 1, Creator::create_from_draft( $draft )['counts']['adopted'] );
		$this->assertSame( 1, Creator::create_from_draft( $draft )['counts']['unchanged'] );
	}

	/**
	 * Children have to land under the term their parent resolved to, not at the
	 * root, or the tree comes out flat.
	 *
	 * @return void
	 */
	public function test_children_are_parented_correctly() {
		Creator::create_from_draft( $this->draft_from_paths( array( array( 'Wohnen', 'Deko', 'Kerzen' ) ) ) );

		$wohnen = get_term_by( 'slug', 'wohnen', 'product_cat' );
		$deko   = get_term_by( 'slug', 'wohnen-deko', 'product_cat' );
		$kerzen = get_term_by( 'slug', 'wohnen-deko-kerzen', 'product_cat' );

		$this->assertSame( 0, (int) $wohnen->parent );
		$this->assertSame( (int) $wohnen->term_id, (int) $deko->parent );
		$this->assertSame( (int) $deko->term_id, (int) $kerzen->parent );
	}

	/**
	 * A dry run has to report the same shape of answer while writing nothing, or it
	 * is not a preview of anything.
	 *
	 * @return void
	 */
	public function test_a_dry_run_creates_nothing_and_reports_what_it_would_do() {
		$draft = $this->draft_from_paths(
			array(
				array( 'Wohnen', 'Deko' ),
				array( 'Garten', 'Deko' ),
			)
		);

		$before = wp_count_terms( array( 'taxonomy' => 'product_cat' ) );
		$dry    = Creator::create_from_draft( $draft, true );

		$this->assertSame( $before, wp_count_terms( array( 'taxonomy' => 'product_cat' ) ) );

		$real = Creator::create_from_draft( $draft );

		$this->assertSame( $dry['counts'], $real['counts'], 'A dry run must predict the real run exactly.' );
	}

	/**
	 * Deleting categories is not something a button called "Create categories" gets
	 * to do. They may hold products or be linked from a menu.
	 *
	 * @return void
	 */
	public function test_a_category_dropped_from_the_draft_is_reported_but_never_deleted() {
		$draft = $this->draft_from_paths(
			array(
				array( 'Wohnen', 'Deko' ),
				array( 'Spielwaren' ),
			)
		);

		Creator::create_from_draft( $draft );

		$narrowed          = $draft;
		$narrowed['nodes'] = array( $draft['nodes'][2] );

		Creator::create_from_draft( $narrowed );

		$this->assertNotNull( get_term_by( 'slug', 'wohnen-deko', 'product_cat' ), 'The dropped category must still exist.' );
		$this->assertCount( 2, Creator::orphans( $narrowed ) );
	}

	/**
	 * A path long enough to overflow the column still has to produce a unique,
	 * stable slug — German compound nouns get there faster than you would think.
	 *
	 * @return void
	 */
	public function test_a_very_long_path_is_truncated_but_stays_unique_and_stable() {
		$prefix = str_repeat( 'Weihnachtsdekoration', 12 );

		$first  = Creator::path_slug( array( $prefix, 'Kerzen' ) );
		$second = Creator::path_slug( array( $prefix, 'Kugeln' ) );

		$this->assertLessThanOrEqual( Creator::SLUG_MAX, strlen( $first ) );
		$this->assertNotSame( $first, $second, 'Two long paths sharing a prefix must not collide.' );
		$this->assertSame( $first, Creator::path_slug( array( $prefix, 'Kerzen' ) ), 'The same path must always give the same slug.' );
	}

	/**
	 * A name in a script sanitize_title() cannot transliterate would otherwise
	 * produce an empty slug and an uncreatable term.
	 *
	 * @return void
	 */
	public function test_a_name_that_sanitises_to_nothing_still_gets_a_slug() {
		$slug = Creator::path_slug( array( '日本語', 'カテゴリ' ) );

		$this->assertNotSame( '', $slug );
		$this->assertSame( $slug, sanitize_title( $slug ) );
	}

	/**
	 * A change of capitalisation is a rename of the same category, not the
	 * discovery of a new one.
	 *
	 * @return void
	 */
	public function test_path_identity_ignores_capitalisation() {
		$this->assertSame(
			Creator::path_hash( array( 'Wohnen', 'Deko' ) ),
			Creator::path_hash( array( 'wohnen', 'DEKO' ) )
		);

		$this->assertNotSame(
			Creator::path_hash( array( 'Wohnen', 'Deko' ) ),
			Creator::path_hash( array( 'Garten', 'Deko' ) )
		);
	}

	/**
	 * Only leaves can hold products. A product filed on a branch would sit in a
	 * category whose entire purpose is to contain other categories.
	 *
	 * @return void
	 */
	public function test_the_leaf_map_offers_only_the_deepest_categories() {
		$draft = $this->draft_from_paths(
			array(
				array( 'Wohnen', 'Deko' ),
				array( 'Wohnen', 'Textilien' ),
				array( 'Spielwaren' ),
			)
		);

		Creator::create_from_draft( $draft );

		$map   = Creator::leaf_map( $draft );
		$names = wp_list_pluck( $map, 'name' );

		sort( $names );

		$this->assertSame( array( 'Deko', 'Spielwaren', 'Textilien' ), $names );
		$this->assertNotContains( 'Wohnen', $names, 'A branch is not somewhere a product goes.' );
	}

	/**
	 * The map has to carry the full path, because that is what the model is shown —
	 * a bare "Deko" is genuinely ambiguous across branches.
	 *
	 * @return void
	 */
	public function test_the_leaf_map_carries_full_paths_and_real_term_ids() {
		$draft = $this->draft_from_paths( array( array( 'Wohnen', 'Deko' ) ) );

		Creator::create_from_draft( $draft );

		$map  = Creator::leaf_map( $draft );
		$leaf = reset( $map );

		$this->assertSame( array( 'Wohnen', 'Deko' ), $leaf['path'] );
		$this->assertSame( (int) get_term_by( 'slug', 'wohnen-deko', 'product_cat' )->term_id, $leaf['term'] );
	}

	/**
	 * A leaf whose term has not been created cannot be assigned to, so offering it
	 * would guarantee a wasted answer.
	 *
	 * @return void
	 */
	public function test_the_leaf_map_skips_categories_that_do_not_exist_yet() {
		$draft = $this->draft_from_paths( array( array( 'Wohnen', 'Deko' ) ) );

		$this->assertSame( array(), Creator::leaf_map( $draft ) );
	}

	/**
	 * The default category is what "not filed yet" means, so it must never be
	 * mistaken for a real one.
	 *
	 * @return void
	 */
	public function test_the_default_category_is_recognised() {
		$default = wp_insert_term( 'Uncategorized wpcai', 'product_cat' );
		update_option( 'default_product_cat', $default['term_id'] );

		$this->assertTrue( Creator::is_default( $default['term_id'] ) );
		$this->assertFalse( Creator::is_default( $default['term_id'] + 1000 ) );

		delete_option( 'default_product_cat' );
	}
}
