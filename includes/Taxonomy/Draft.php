<?php
/**
 * The proposed category tree, between the model answering and a person approving.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the draft option.
 *
 * The draft is stored **flat**: one row per node, each carrying its own opaque key
 * and its parent's. It is a tree, and storing it as one would be the obvious
 * choice, but the tree has to survive a round trip through $_POST. Nested data
 * cannot be expressed in flat form-field names without either a JSON blob in a
 * textarea — which nobody can review, and where one typo loses the whole tree — or
 * positional index arithmetic that breaks the moment a row is deleted. A flat list
 * where each row names its own key and its parent's is exactly what a table of
 * inputs submits, and the nesting is rebuilt in one pass.
 *
 * Keys are minted here and are **never derived from the name**, so renaming a node
 * does not orphan its children.
 */
class Draft {

	/**
	 * Option holding the draft.
	 */
	const OPTION_KEY = 'woo_product_categorizer_ai_taxonomy_draft';

	/**
	 * Option holding the one draft a proposal replaced.
	 */
	const BACKUP_KEY = 'woo_product_categorizer_ai_taxonomy_draft_previous';

	/**
	 * Longest a category name may be.
	 *
	 * The term.name column holds 200; leaving headroom means a name is never
	 * truncated by the database after passing validation here.
	 */
	const NAME_MAX = 190;

	/**
	 * Read the stored draft.
	 *
	 * @return array The draft, or an empty shape when there is none.
	 */
	public static function get() {
		$stored = get_option( self::OPTION_KEY, array() );

		return is_array( $stored ) ? wp_parse_args( $stored, self::blank() ) : self::blank();
	}

	/**
	 * The shape of "no draft".
	 *
	 * @return array
	 */
	public static function blank() {
		return array(
			'generated' => 0,
			'run'       => 0,
			'model'     => '',
			'depth'     => 3,
			'guidance'  => '',
			'sample'    => 0,
			'usage'     => array(),
			'edited'    => 0,
			'created'   => 0,
			'nodes'     => array(),
		);
	}

	/**
	 * Whether there is a draft to show.
	 *
	 * @return bool
	 */
	public static function exists() {
		return ! empty( self::get()['nodes'] );
	}

	/**
	 * Persist a draft.
	 *
	 * @param array $draft The draft to store.
	 * @return void
	 */
	public static function save( array $draft ) {
		update_option( self::OPTION_KEY, wp_parse_args( $draft, self::blank() ), false );
	}

	/**
	 * Throw the draft away.
	 *
	 * @return void
	 */
	public static function discard() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Store a draft built from a model's answer.
	 *
	 * Backs up whatever it replaces if that draft had been edited by hand. Proposing
	 * again is otherwise an irreversible loss of somebody's work, and re-proposing
	 * is exactly what a person does when they are refining their guidance — the
	 * moment they are most likely to have edited something first.
	 *
	 * @param array $payload  The decoded tree from the provider.
	 * @param array $metadata Run, model, depth, guidance, sample size and usage.
	 * @return array The stored draft.
	 */
	public static function store_from_payload( array $payload, array $metadata ) {
		$existing = self::get();

		if ( ! empty( $existing['nodes'] ) && ! empty( $existing['edited'] ) ) {
			update_option( self::BACKUP_KEY, $existing, false );
		}

		$categories = isset( $payload['categories'] ) && is_array( $payload['categories'] )
			? $payload['categories']
			: array();

		$draft = wp_parse_args(
			$metadata,
			array(
				'generated' => time(),
				'edited'    => 0,
				'created'   => 0,
				'nodes'     => self::flatten( $categories, '', 1, (int) ( isset( $metadata['depth'] ) ? $metadata['depth'] : 3 ) ),
			)
		);

		$draft['nodes'] = self::normalise( $draft['nodes'] );

		self::save( $draft );

		return self::get();
	}

	/**
	 * Whether a replaced draft is available to put back.
	 *
	 * @return bool
	 */
	public static function has_backup() {
		$backup = get_option( self::BACKUP_KEY, array() );

		return is_array( $backup ) && ! empty( $backup['nodes'] );
	}

	/**
	 * Put the replaced draft back.
	 *
	 * @return bool True when something was restored.
	 */
	public static function restore_backup() {
		$backup = get_option( self::BACKUP_KEY, array() );

		if ( ! is_array( $backup ) || empty( $backup['nodes'] ) ) {
			return false;
		}

		self::save( $backup );
		delete_option( self::BACKUP_KEY );

		return true;
	}

	/**
	 * Turn the model's nested answer into flat rows.
	 *
	 * @param array  $nodes      Nested nodes from the payload.
	 * @param string $parent_key Parent key, empty at the root.
	 * @param int    $level      Level being read.
	 * @param int    $depth      Deepest level allowed.
	 * @return array Flat rows, in pre-order.
	 */
	protected static function flatten( array $nodes, $parent_key, $level, $depth ) {
		$rows = array();

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$name = self::clean_name( isset( $node['name'] ) ? $node['name'] : '' );

			if ( '' === $name ) {
				continue;
			}

			$key = self::mint_key();

			$rows[] = array(
				'key'    => $key,
				'parent' => $parent_key,
				'name'   => $name,
				'depth'  => $level,
			);

			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();

			if ( $level < $depth && ! empty( $children ) ) {
				/*
				 * Children follow their parent immediately. Storing in pre-order is what
				 * lets the editor render the table by walking the array once, and lets
				 * Creator resolve a parent's term id before it needs it.
				 */
				$rows = array_merge( $rows, self::flatten( $children, $key, $level + 1, $depth ) );
			}
		}

		return $rows;
	}

	/**
	 * Tidy a set of rows.
	 *
	 * Merges siblings that share a name, case-insensitively, folding the children of
	 * the loser into the winner. The model proposes near-duplicates fairly often —
	 * two sub-categories a person would obviously have written once — and two terms
	 * with the same name under the same parent are indistinguishable in every UI
	 * WooCommerce has.
	 *
	 * Rows whose parent no longer exists are dropped, which is what makes removing a
	 * node remove its whole subtree without any recursive deletion.
	 *
	 * @param array $rows Flat rows.
	 * @return array Tidied rows, still in pre-order.
	 */
	public static function normalise( array $rows ) {
		$rows = self::collapse_echoes( $rows );

		$by_key   = array();
		$merged   = array();
		$survivor = array();
		$out      = array();

		foreach ( $rows as $row ) {
			$key    = isset( $row['key'] ) ? (string) $row['key'] : '';
			$name   = self::clean_name( isset( $row['name'] ) ? $row['name'] : '' );
			$parent = isset( $row['parent'] ) ? (string) $row['parent'] : '';

			if ( '' === $key || '' === $name ) {
				continue;
			}

			// A parent that did not survive takes its descendants with it.
			if ( '' !== $parent && ! isset( $by_key[ $parent ] ) ) {
				continue;
			}

			// Follow a merged parent, so a child of a folded node lands on the survivor.
			while ( isset( $survivor[ $parent ] ) ) {
				$parent = $survivor[ $parent ];
			}

			$signature = $parent . "\0" . self::fold( $name );

			if ( isset( $merged[ $signature ] ) ) {
				$survivor[ $key ] = $merged[ $signature ];
				$by_key[ $key ]   = true;
				continue;
			}

			$merged[ $signature ] = $key;
			$by_key[ $key ]       = true;

			$out[] = array(
				'key'    => $key,
				'parent' => $parent,
				'name'   => $name,
				'depth'  => '' === $parent ? 1 : 0,
			);
		}

		return self::recompute_depths( $out );
	}

	/**
	 * Remove children that only repeat their parent's name.
	 *
	 * "Figuren > Figuren" is not a category tree, it is a node with a shadow. This
	 * happened on every branch of the first real proposal run: the schema makes
	 * `children` a required property at every level above the deepest, and the model
	 * satisfied it by echoing each category's own name back as its only child,
	 * turning 49 real categories into 108 nodes.
	 *
	 * The prompt now says an empty list is a legitimate answer, which fixed it. This
	 * stays as the structural guarantee, because a tree that survived one model's
	 * habits is not a tree that will survive the next model's. An echoed node is
	 * spliced out and any children it had are reattached to the grandparent, so the
	 * shape is preserved and only the redundant level goes.
	 *
	 * @param array $rows Flat rows in pre-order.
	 * @return array Rows with echoed nodes removed.
	 */
	protected static function collapse_echoes( array $rows ) {
		$names = array();

		foreach ( $rows as $row ) {
			$names[ $row['key'] ] = self::fold( $row['name'] );
		}

		$replacement = array();
		$kept        = array();

		foreach ( $rows as $row ) {
			$parent = $row['parent'];

			// Follow any ancestors that were themselves spliced out.
			while ( isset( $replacement[ $parent ] ) ) {
				$parent = $replacement[ $parent ];
			}

			if ( '' !== $parent && isset( $names[ $parent ] ) && self::fold( $row['name'] ) === $names[ $parent ] ) {
				$replacement[ $row['key'] ] = $parent;
				continue;
			}

			$row['parent'] = $parent;
			$kept[]        = $row;
		}

		return $kept;
	}

	/**
	 * Recalculate every row's depth from its parent chain.
	 *
	 * The stored depth is a convenience for rendering, not a source of truth. It is
	 * derived here so that a row edited, merged or re-parented cannot end up
	 * claiming a level its ancestry does not support.
	 *
	 * @param array $rows Flat rows in pre-order.
	 * @return array Rows with depths filled in.
	 */
	protected static function recompute_depths( array $rows ) {
		$depths = array();

		foreach ( $rows as $index => $row ) {
			$parent = $row['parent'];

			$rows[ $index ]['depth'] = '' === $parent
				? 1
				: ( isset( $depths[ $parent ] ) ? $depths[ $parent ] + 1 : 2 );

			$depths[ $row['key'] ] = $rows[ $index ]['depth'];
		}

		return $rows;
	}

	/**
	 * Build the parent => children index the editor and the creator both walk.
	 *
	 * @param array $rows Flat rows.
	 * @return array Parent key => rows.
	 */
	public static function tree( array $rows ) {
		$tree = array();

		foreach ( $rows as $row ) {
			$tree[ $row['parent'] ][] = $row;
		}

		return $tree;
	}

	/**
	 * The full path of a node, root first.
	 *
	 * @param array  $rows Flat rows.
	 * @param string $key  Node to describe.
	 * @return array Names from the root down to this node.
	 */
	public static function path( array $rows, $key ) {
		$by_key = array();

		foreach ( $rows as $row ) {
			$by_key[ $row['key'] ] = $row;
		}

		$path  = array();
		$guard = 0;

		while ( isset( $by_key[ $key ] ) && $guard < 10 ) {
			array_unshift( $path, $by_key[ $key ]['name'] );

			$key = $by_key[ $key ]['parent'];
			++$guard;
		}

		return $path;
	}

	/**
	 * Every node with no children of its own.
	 *
	 * Leaves are where products go. A product filed on a branch instead would sit in
	 * a category whose whole purpose is to contain other categories.
	 *
	 * @param array $rows Flat rows.
	 * @return array The leaf rows.
	 */
	public static function leaves( array $rows ) {
		$parents = array();

		foreach ( $rows as $row ) {
			if ( '' !== $row['parent'] ) {
				$parents[ $row['parent'] ] = true;
			}
		}

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $parents ) {
					return ! isset( $parents[ $row['key'] ] );
				}
			)
		);
	}

	/**
	 * Normalise a category name.
	 *
	 * Deliberately not sanitize_text_field(): it strips percent-encoded octets, and
	 * a category legitimately called "50% Sale" would silently become "50 Sale".
	 * Tags have to go, whitespace is collapsed so a stray newline from the model
	 * cannot produce a name no one can match, and the length is capped below what
	 * the column holds.
	 *
	 * @param mixed $raw Submitted or returned name.
	 * @return string A usable name, or an empty string.
	 */
	public static function clean_name( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$name = wp_strip_all_tags( (string) $raw );
		$name = preg_replace( '/\s+/u', ' ', $name );
		$name = trim( (string) $name );

		return mb_substr( $name, 0, self::NAME_MAX );
	}

	/**
	 * The form a name is compared in.
	 *
	 * Case-insensitive and accent-preserving: "Deko" and "deko" are the same
	 * category, while "Grüße" and "Grusse" are not, and a shop that writes both
	 * meant something by it.
	 *
	 * @param string $name Category name.
	 * @return string A comparison key.
	 */
	public static function fold( $name ) {
		return mb_strtolower( trim( (string) $name ) );
	}

	/**
	 * Mint a key no existing node uses.
	 *
	 * Opaque and sequential rather than derived from the name. A key built from the
	 * name would change when the name did, orphaning every child of a renamed node —
	 * which is the single most likely edit anyone makes to a proposed tree.
	 *
	 * @return string A new key.
	 */
	public static function mint_key() {
		static $counter = 0;

		++$counter;

		return 'n' . $counter . base_convert( (string) wp_rand( 1000, 99999 ), 10, 36 );
	}
}
