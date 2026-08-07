<?php
/**
 * Turning an approved draft into real product categories.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Taxonomy;

use WooProductCategorizerAi\Jobs\Preflight;
use WooProductCategorizerAi\Jobs\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Materialises the draft as product_cat terms.
 *
 * Idempotent by design: running it twice on an unchanged draft must report
 * everything as unchanged and touch nothing. That is what makes "press Create
 * again after an edit" a safe thing to do rather than a way to end up with two of
 * every category.
 */
class Creator {

	/**
	 * Term meta recording which path a term was created for.
	 */
	const META_PATH_HASH = '_wpcai_path_hash';

	/**
	 * Term meta recording which draft node a term came from.
	 */
	const META_NODE_KEY = '_wpcai_node_key';

	/**
	 * Longest slug written, before the uniqueness tail.
	 *
	 * The column holds 200. A path slug can genuinely run long in German, where
	 * compound nouns are one word.
	 */
	const SLUG_MAX = 191;

	/**
	 * Create, adopt or rename every term the draft describes.
	 *
	 * @param array $draft   The approved draft.
	 * @param bool  $dry_run When true, report what would happen and write nothing.
	 * @return array Counts, and the node key => term id map that was resolved.
	 */
	public static function create_from_draft( array $draft, $dry_run = false ) {
		$nodes = isset( $draft['nodes'] ) ? (array) $draft['nodes'] : array();

		$counts = array(
			'created'   => 0,
			'adopted'   => 0,
			'renamed'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
		);

		$terms = array();

		/*
		 * The draft is stored in pre-order, so a parent is always resolved before the
		 * children that need its term id. Sorting by depth here would work too; relying
		 * on the stored order is what makes that ordering worth maintaining.
		 */
		foreach ( $nodes as $node ) {
			$parent_id = 0;

			if ( '' !== $node['parent'] ) {
				if ( ! isset( $terms[ $node['parent'] ] ) ) {
					// The parent failed, so this node has nowhere to go.
					++$counts['failed'];
					continue;
				}

				$parent_id = $terms[ $node['parent'] ];
			}

			$path   = Draft::path( $nodes, $node['key'] );
			$result = self::resolve( $node, $path, $parent_id, $dry_run );

			if ( 0 === $result['term_id'] ) {
				++$counts['failed'];
				continue;
			}

			$terms[ $node['key'] ] = $result['term_id'];
			++$counts[ $result['outcome'] ];
		}

		return array(
			'counts' => $counts,
			'terms'  => $terms,
		);
	}

	/**
	 * Find or create the term for one node.
	 *
	 * Resolution runs in order of how confident each step is, which is the same
	 * shape the sibling plugin's brand matching uses:
	 *
	 * 1. A term stamped with this **node key**. Definitely ours, definitely this
	 *    node. This step has to come first, and it is the reason node keys are
	 *    opaque and stable: the path hash is derived from the names, so renaming a
	 *    category changes it. Matching on the hash alone meant a rename found
	 *    nothing and created a second category beside the first — the exact
	 *    duplication this class is supposed to prevent, triggered by the single most
	 *    common edit anyone makes to a draft.
	 * 2. A term stamped with this path's hash. Ours, from a previous draft whose
	 *    keys were minted separately — a re-proposal mints all-new keys, so this is
	 *    what carries an existing tree across one.
	 * 3. A term at this path's slug. Almost certainly one we created before the
	 *    stamps existed, or one a shop owner made by hand at the same place.
	 * 4. A term with this name under this parent. Covers a shop that already had
	 *    that child, spelled the same way.
	 * 5. Nothing matched, so create it.
	 *
	 * @param array $node      The draft node.
	 * @param array $path      Names from the root down to this node.
	 * @param int   $parent_id Parent term id, or 0 at the root.
	 * @param bool  $dry_run   When true, write nothing.
	 * @return array The term id and what happened.
	 */
	protected static function resolve( array $node, array $path, $parent_id, $dry_run ) {
		$hash = self::path_hash( $path );
		$slug = self::path_slug( $path );

		$adopted  = false;
		$existing = self::find_by_meta( self::META_NODE_KEY, $node['key'] );

		if ( null === $existing ) {
			$existing = self::find_by_meta( self::META_PATH_HASH, $hash );
		}

		if ( null === $existing ) {
			$found    = get_term_by( 'slug', $slug, 'product_cat' );
			$existing = $found instanceof \WP_Term ? $found : null;
			$adopted  = null !== $existing;
		}

		if ( null === $existing ) {
			$found = term_exists( $node['name'], 'product_cat', $parent_id );

			if ( is_array( $found ) ) {
				$term     = get_term( (int) $found['term_id'], 'product_cat' );
				$existing = $term instanceof \WP_Term ? $term : null;
				$adopted  = null !== $existing;
			}
		}

		if ( null !== $existing ) {
			return self::reuse( $existing, $node, $hash, $adopted, $dry_run );
		}

		if ( $dry_run ) {
			// A plausible id, so the caller's parent map still resolves the children.
			return array(
				'term_id' => -1,
				'outcome' => 'created',
			);
		}

		$created = wp_insert_term(
			$node['name'],
			'product_cat',
			array(
				'parent' => (int) $parent_id,
				'slug'   => $slug,
			)
		);

		if ( is_wp_error( $created ) ) {
			Scheduler::log( 'error', sprintf( 'Could not create the category "%s": %s', $node['name'], $created->get_error_message() ) );

			return array(
				'term_id' => 0,
				'outcome' => 'failed',
			);
		}

		$term_id = (int) $created['term_id'];

		update_term_meta( $term_id, self::META_PATH_HASH, $hash );
		update_term_meta( $term_id, self::META_NODE_KEY, $node['key'] );

		return array(
			'term_id' => $term_id,
			'outcome' => 'created',
		);
	}

	/**
	 * Reuse a term that already exists at this path.
	 *
	 * A renamed node renames the term but **never changes its slug**. That is the
	 * same rule the sibling plugin applies to brands, for the same reason: the slug
	 * is in every URL that already points at the category archive, in every menu
	 * item built from it and in every link anyone has shared. A stale slug beside a
	 * new name is invisible to shoppers; a changed one is a 404.
	 *
	 * @param \WP_Term $term    The existing term.
	 * @param array    $node    The draft node.
	 * @param string   $hash    This path's hash.
	 * @param bool     $adopted Whether it was found other than by its stamp.
	 * @param bool     $dry_run When true, write nothing.
	 * @return array The term id and what happened.
	 */
	protected static function reuse( $term, array $node, $hash, $adopted, $dry_run ) {
		$renamed = ! self::same_name( $term->name, $node['name'] );

		if ( $dry_run ) {
			return array(
				'term_id' => (int) $term->term_id,
				'outcome' => self::outcome( $adopted, $renamed ),
			);
		}

		if ( $renamed ) {
			wp_update_term( (int) $term->term_id, 'product_cat', array( 'name' => $node['name'] ) );
		}

		update_term_meta( (int) $term->term_id, self::META_PATH_HASH, $hash );
		update_term_meta( (int) $term->term_id, self::META_NODE_KEY, $node['key'] );

		return array(
			'term_id' => (int) $term->term_id,
			'outcome' => self::outcome( $adopted, $renamed ),
		);
	}

	/**
	 * Whether a stored term name and a draft name are the same name.
	 *
	 * Not a string comparison, because WordPress does not store what you give it.
	 * A term created as "Basteln & Malen" comes back as "Basteln &amp; Malen", so a
	 * naive `!==` reports a rename on every single run — on the real catalogue that
	 * was 11 of 63 categories rewritten forever, on every press of Create, for no
	 * change at all.
	 *
	 * Both sides are decoded before comparing, so the encoding WordPress applies is
	 * invisible here and a genuine rename is still detected.
	 *
	 * @param string $stored The name as WordPress has it.
	 * @param string $wanted The name the draft asks for.
	 * @return bool True when they are the same name.
	 */
	protected static function same_name( $stored, $wanted ) {
		return wp_specialchars_decode( (string) $stored, ENT_QUOTES ) === wp_specialchars_decode( (string) $wanted, ENT_QUOTES );
	}

	/**
	 * Name what happened to a reused term.
	 *
	 * Adoption is reported ahead of renaming, because "this category already
	 * existed and is now managed here" is the more surprising of the two and the one
	 * worth surfacing.
	 *
	 * @param bool $adopted Whether it was found other than by its stamp.
	 * @param bool $renamed Whether its name changed.
	 * @return string One of the count keys.
	 */
	protected static function outcome( $adopted, $renamed ) {
		if ( $adopted ) {
			return 'adopted';
		}

		return $renamed ? 'renamed' : 'unchanged';
	}

	/**
	 * Find a term by one of the stamps this plugin writes.
	 *
	 * @param string $meta_key   Which stamp to match.
	 * @param string $meta_value Value to match.
	 * @return \WP_Term|null
	 */
	protected static function find_by_meta( $meta_key, $meta_value ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an indexed lookup on our own stamp, run once per category.
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * The slug for a full path.
	 *
	 * **This is the reason this class exists.** A proposed tree genuinely reuses leaf
	 * names across branches — "Deko" appeared under three different parents on the
	 * catalogue this was built for. WooCommerce is happy to have same-name terms
	 * under different parents, but slugs are global: wp_insert_term() silently
	 * appends -2 and -3, the resulting URLs say nothing about which branch they
	 * belong to, and which term gets which suffix depends on insertion order, so a
	 * re-run reshuffles them.
	 *
	 * Deriving the slug from the whole path makes it both meaningful and stable:
	 * "Wohnen › Deko" is wohnen-deko and stays wohnen-deko.
	 *
	 * Top-level categories keep their bare slug, because those are the URLs anyone
	 * actually looks at and there is nothing above them to disambiguate against.
	 *
	 * @param array $path Names from the root down.
	 * @return string A slug.
	 */
	public static function path_slug( array $path ) {
		$slug = sanitize_title( implode( '-', $path ) );

		if ( '' === $slug ) {
			// Every character was stripped — a name in a script sanitize_title() cannot
			// transliterate. Fall back to the path's hash so the term is still creatable.
			return 'wpcai-' . substr( self::path_hash( $path ), 0, 12 );
		}

		if ( strlen( $slug ) <= self::SLUG_MAX ) {
			return $slug;
		}

		/*
		 * Truncating alone would collide two long paths that share a prefix, which is
		 * exactly what deep paths under one parent look like. The hash tail keeps them
		 * distinct and keeps the slug stable across runs.
		 */
		return substr( $slug, 0, self::SLUG_MAX - 9 ) . '-' . substr( self::path_hash( $path ), 0, 8 );
	}

	/**
	 * A stable identifier for a path.
	 *
	 * Folded to lower case so that a change of capitalisation is a rename of the
	 * same category rather than the discovery of a new one.
	 *
	 * @param array $path Names from the root down.
	 * @return string An md5 hash.
	 */
	public static function path_hash( array $path ) {
		return md5( implode( "\0", array_map( array( Draft::class, 'fold' ), $path ) ) );
	}

	/**
	 * Terms this plugin created that the current draft no longer describes.
	 *
	 * Reported, never deleted. They may hold products, they may be linked from a
	 * menu, and a shop owner may have decided to keep one. Deleting categories is
	 * not something a button called "Create categories" gets to do.
	 *
	 * @param array $draft The approved draft.
	 * @return array Term ids.
	 */
	public static function orphans( array $draft ) {
		$nodes  = isset( $draft['nodes'] ) ? (array) $draft['nodes'] : array();
		$wanted = array();

		foreach ( $nodes as $node ) {
			$wanted[ self::path_hash( Draft::path( $nodes, $node['key'] ) ) ] = true;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- run only when the screen renders this notice.
					array(
						'key'     => self::META_PATH_HASH,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$orphans = array();

		foreach ( $terms as $term ) {
			$hash = (string) get_term_meta( $term->term_id, self::META_PATH_HASH, true );

			if ( ! isset( $wanted[ $hash ] ) ) {
				$orphans[] = (int) $term->term_id;
			}
		}

		return $orphans;
	}

	/**
	 * Every leaf in the draft, mapped to the terms that were created for it.
	 *
	 * This is what an assignment run is given to choose from. Only leaves: a product
	 * filed on a branch would sit in a category whose entire purpose is to contain
	 * other categories.
	 *
	 * The ids are short and synthetic rather than term ids. They cost fewer tokens
	 * on every one of a run's requests, and a wrong one is obviously wrong — a
	 * hallucinated term id looks exactly like a real one.
	 *
	 * @param array $draft The approved draft.
	 * @return array Leaf id => term id, path and name.
	 */
	public static function leaf_map( array $draft ) {
		$nodes  = isset( $draft['nodes'] ) ? (array) $draft['nodes'] : array();
		$leaves = Draft::leaves( $nodes );
		$map    = array();
		$index  = 0;

		foreach ( $leaves as $leaf ) {
			$path = Draft::path( $nodes, $leaf['key'] );
			$term = self::find_by_meta( self::META_NODE_KEY, $leaf['key'] );

			if ( null === $term ) {
				$term = self::find_by_meta( self::META_PATH_HASH, self::path_hash( $path ) );
			}

			// A leaf with no term has not been created yet, and cannot be assigned to.
			if ( null === $term ) {
				continue;
			}

			++$index;

			$map[ sprintf( 'c%03d', $index ) ] = array(
				'term' => (int) $term->term_id,
				'path' => $path,
				'name' => $leaf['name'],
			);
		}

		return $map;
	}

	/**
	 * Whether a term is the one WooCommerce files uncategorised products under.
	 *
	 * The default category is never created from a draft, never renamed, never given
	 * a parent and never emitted as a leaf. It is the definition of "not filed yet",
	 * so treating it as a real category would make the whole idea of an
	 * uncategorised product meaningless.
	 *
	 * @param int $term_id Term to check.
	 * @return bool
	 */
	public static function is_default( $term_id ) {
		return Preflight::default_category_id() === (int) $term_id;
	}
}
