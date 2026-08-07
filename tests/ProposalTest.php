<?php
/**
 * The tree proposal job and the draft it produces.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Admin\Settings;
use WooProductCategorizerAi\Jobs\Status;
use WooProductCategorizerAi\Taxonomy\Draft;
use WooProductCategorizerAi\Taxonomy\Proposal;
use WooProductCategorizerAi\Taxonomy\Sampler;
use WooProductCategorizerAi\Tests\Doubles\StubProvider;
use WP_UnitTestCase;

/**
 * Covers the chain, the stale-run fence and the flattening of the model's answer.
 */
class ProposalTest extends WP_UnitTestCase {

	/**
	 * The double standing in for a provider.
	 *
	 * @var StubProvider
	 */
	protected $provider;

	/**
	 * Set up the fixture.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->provider = new StubProvider();

		delete_option( Status::OPTION_KEY );
		delete_option( Draft::OPTION_KEY );
		delete_option( Draft::BACKUP_KEY );
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Status::OPTION_KEY );
		delete_option( Draft::OPTION_KEY );
		delete_option( Draft::BACKUP_KEY );
		delete_option( Settings::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Settings with a key in place, so preflight passes.
	 *
	 * @param array $overrides Settings to change.
	 * @return array
	 */
	protected function settings( array $overrides = array() ) {
		return array_merge(
			Settings::default_settings(),
			array(
				'provider' => 'openai',
				'api_keys' => array( 'openai' => 'sk-test' ),
			),
			$overrides
		);
	}

	/**
	 * Build the job with the double wired in.
	 *
	 * @param array $overrides Settings to change.
	 * @return Proposal
	 */
	protected function job( array $overrides = array() ) {
		return new Proposal( $this->provider, $this->settings( $overrides ) );
	}

	/**
	 * A small nested tree, shaped the way the model returns one.
	 *
	 * @return array
	 */
	protected function tree() {
		return array(
			'categories' => array(
				array(
					'name'     => 'Spielwaren',
					'children' => array(
						array(
							'name'     => 'Holztiere',
							'children' => array(),
						),
						array(
							'name'     => 'Puzzles',
							'children' => array(),
						),
					),
				),
				array(
					'name'     => 'Wohnen',
					'children' => array(
						array(
							'name'     => 'Deko',
							'children' => array(),
						),
					),
				),
			),
		);
	}

	/**
	 * Create some products so the sampler has something to read.
	 *
	 * @param int $count How many.
	 * @return void
	 */
	protected function make_products( $count = 5 ) {
		for ( $index = 0; $index < $count; $index++ ) {
			self::factory()->post->create(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'post_title'  => 'Wudimals Tier ' . $index,
				)
			);
		}
	}

	/**
	 * Drive the whole chain by hand, the way Action Scheduler would.
	 *
	 * @param Proposal $job The job.
	 * @return int The run id.
	 */
	protected function run_chain( Proposal $job ) {
		$job->start();

		$run = (int) Status::get( Proposal::JOB )['started'];

		$job->sample( $run );
		$job->ask( $run );
		$job->finalise( $run );

		return $run;
	}

	/**
	 * The happy path: sample, ask, publish a draft.
	 *
	 * @return void
	 */
	public function test_a_proposal_produces_a_reviewable_draft() {
		$this->make_products();
		$this->provider->will_answer( $this->tree() );

		$this->run_chain( $this->job() );

		$this->assertSame( 'success', Status::get( Proposal::JOB )['state'] );

		$nodes = Draft::get()['nodes'];

		$this->assertCount( 5, $nodes );
		$this->assertSame( array( 'Spielwaren', 'Holztiere' ), Draft::path( $nodes, $nodes[1]['key'] ) );
	}

	/**
	 * Depth is derived from the ancestry rather than trusted from the payload.
	 *
	 * @return void
	 */
	public function test_nodes_record_their_level() {
		$this->make_products();
		$this->provider->will_answer( $this->tree() );

		$this->run_chain( $this->job() );

		$levels = wp_list_pluck( Draft::get()['nodes'], 'depth', 'name' );

		$this->assertSame( 1, $levels['Spielwaren'] );
		$this->assertSame( 2, $levels['Holztiere'] );
	}

	/**
	 * The draft records what produced it, so the screen can say which model and
	 * which guidance a tree came from.
	 *
	 * @return void
	 */
	public function test_the_draft_records_how_it_was_made() {
		$this->make_products();
		$this->provider->will_answer( $this->tree() );

		$this->run_chain( $this->job( array( 'guidance' => 'Nach Alter sortieren.' ) ) );

		$draft = Draft::get();

		$this->assertSame( 'Nach Alter sortieren.', $draft['guidance'] );
		$this->assertGreaterThan( 0, $draft['generated'] );
		$this->assertGreaterThan( 0, $draft['sample'] );
		$this->assertSame( 0, $draft['edited'], 'A fresh proposal has not been edited.' );
	}

	/**
	 * The shop's own guidance is the one part of the prompt written by someone who
	 * has seen the catalogue, so it has to actually be sent.
	 *
	 * @return void
	 */
	public function test_the_guidance_reaches_the_provider() {
		$this->make_products();
		$this->provider->will_answer( $this->tree() );

		$this->run_chain( $this->job( array( 'guidance' => 'Nach Alter sortieren.' ) ) );

		$this->assertStringContainsString( 'Nach Alter sortieren.', $this->provider->requests[0]['instructions'] );
	}

	/**
	 * The depth setting has to reach the schema, or it is decoration.
	 *
	 * @return void
	 */
	public function test_the_depth_setting_shapes_the_schema() {
		$this->make_products();
		$this->provider->will_answer( $this->tree() );

		$this->run_chain( $this->job( array( 'max_depth' => 2 ) ) );

		$items = $this->provider->requests[0]['schema']['properties']['categories']['items'];

		$this->assertArrayHasKey( 'children', $items['properties'] );
		$this->assertArrayNotHasKey( 'children', $items['properties']['children']['items']['properties'] );
	}

	/**
	 * A tree is designed once and a bad one makes every later assignment worse, so
	 * it is the wrong place to economise on thinking.
	 *
	 * @return void
	 */
	public function test_the_proposal_asks_for_more_reasoning_than_an_assignment_would() {
		$this->make_products();
		$this->provider->will_answer( $this->tree() );

		$this->run_chain( $this->job() );

		$this->assertSame( 'medium', $this->provider->requests[0]['effort'] );
	}

	/**
	 * An action from a superseded run must not overwrite the draft belonging to the
	 * run that replaced it.
	 *
	 * @return void
	 */
	public function test_a_superseded_run_does_no_work() {
		$this->make_products();
		$this->provider->will_answer( $this->tree() );

		$job = $this->job();
		$job->start();

		$stale = (int) Status::get( Proposal::JOB )['started'] - 1;

		$job->sample( $stale );
		$job->ask( $stale );

		$this->assertSame( 0, $this->provider->call_count(), 'A stale action must not spend a request.' );
		$this->assertFalse( Draft::exists() );
	}

	/**
	 * A provider failure has to be recorded rather than leaving the job reading as
	 * running until it goes stale six hours later.
	 *
	 * @return void
	 */
	public function test_a_provider_failure_fails_the_run_and_writes_no_draft() {
		$this->make_products();
		$this->provider->will_fail();

		$this->run_chain( $this->job() );

		$this->assertSame( 'failed', Status::get( Proposal::JOB )['state'] );
		$this->assertFalse( Draft::exists() );
	}

	/**
	 * An empty catalogue is a configuration problem, and must not spend a request to
	 * discover it.
	 *
	 * @return void
	 */
	public function test_an_empty_catalogue_fails_before_asking() {
		$job = $this->job();
		$job->start();
		$job->sample( (int) Status::get( Proposal::JOB )['started'] );

		$this->assertSame( 'failed', Status::get( Proposal::JOB )['state'] );
		$this->assertSame( 0, $this->provider->call_count() );
	}

	/**
	 * Without a key there is nothing to ask, and that is worth saying before a run
	 * starts rather than after it fails.
	 *
	 * @return void
	 */
	public function test_a_missing_key_refuses_to_start() {
		$this->make_products();

		$job = new Proposal( $this->provider, Settings::default_settings() );
		$job->start();

		$this->assertSame( 'failed', Status::get( Proposal::JOB )['state'] );
		$this->assertSame( 0, $this->provider->call_count() );
	}

	/**
	 * Re-proposing is exactly what someone does while refining their guidance,
	 * which is the moment they are most likely to have edited the tree first.
	 *
	 * @return void
	 */
	public function test_an_edited_draft_is_backed_up_before_a_proposal_replaces_it() {
		$this->make_products();

		$draft           = Draft::blank();
		$draft['nodes']  = array(
			array(
				'key'    => 'n1',
				'parent' => '',
				'name'   => 'Handmade',
				'depth'  => 1,
			),
		);
		$draft['edited'] = time();
		Draft::save( $draft );

		$this->provider->will_answer( $this->tree() );
		$this->run_chain( $this->job() );

		$this->assertTrue( Draft::has_backup() );
		$this->assertTrue( Draft::restore_backup() );
		$this->assertSame( 'Handmade', Draft::get()['nodes'][0]['name'] );
	}

	/**
	 * A draft nobody has touched is not worth keeping a copy of.
	 *
	 * @return void
	 */
	public function test_an_untouched_draft_is_not_backed_up() {
		$this->make_products();

		$this->provider->will_answer( $this->tree() );
		$this->run_chain( $this->job() );

		$this->provider->will_answer( $this->tree() );
		$this->run_chain( $this->job() );

		$this->assertFalse( Draft::has_backup() );
	}

	/**
	 * The model proposes near-duplicate siblings often enough that tidying them is
	 * part of reading the answer, not an edge case.
	 *
	 * @return void
	 */
	public function test_duplicate_siblings_are_merged_with_their_children_combined() {
		$this->make_products();

		$this->provider->will_answer(
			array(
				'categories' => array(
					array(
						'name'     => 'Wohnen',
						'children' => array( array( 'name' => 'Deko' ) ),
					),
					array(
						'name'     => 'wohnen',
						'children' => array( array( 'name' => 'Kissen' ) ),
					),
				),
			)
		);

		$this->run_chain( $this->job() );

		$nodes = Draft::get()['nodes'];
		$names = wp_list_pluck( $nodes, 'name' );

		$this->assertCount( 3, $nodes, 'The two spellings of Wohnen should be one node.' );
		$this->assertContains( 'Deko', $names );
		$this->assertContains( 'Kissen', $names );

		// Both children ended up under the surviving parent.
		$roots = array_filter(
			$nodes,
			static function ( $node ) {
				return '' === $node['parent'];
			}
		);
		$this->assertCount( 1, $roots );
	}

	/**
	 * The same leaf name under different parents is not a duplicate. It is the
	 * normal case, and it is what path-derived slugs exist to handle.
	 *
	 * @return void
	 */
	public function test_the_same_name_under_different_parents_is_kept() {
		$this->make_products();

		$this->provider->will_answer(
			array(
				'categories' => array(
					array(
						'name'     => 'Wohnen',
						'children' => array( array( 'name' => 'Deko' ) ),
					),
					array(
						'name'     => 'Garten',
						'children' => array( array( 'name' => 'Deko' ) ),
					),
				),
			)
		);

		$this->run_chain( $this->job() );

		$names = wp_list_pluck( Draft::get()['nodes'], 'name' );

		$this->assertSame( 2, count( array_keys( $names, 'Deko', true ) ) );
	}

	/**
	 * Observed on the first real proposal run: the model satisfied the required
	 * "children" property by echoing each category's own name back as its only
	 * child, turning 49 real categories into 108 nodes.
	 *
	 * @return void
	 */
	public function test_a_child_that_only_repeats_its_parent_is_removed() {
		$this->make_products();

		$this->provider->will_answer(
			array(
				'categories' => array(
					array(
						'name'     => 'Wohnen',
						'children' => array(
							array(
								'name'     => 'Deko',
								'children' => array( array( 'name' => 'Deko' ) ),
							),
						),
					),
				),
			)
		);

		$this->run_chain( $this->job() );

		$nodes = Draft::get()['nodes'];

		$this->assertCount( 2, $nodes );
		$this->assertSame( array( 'Wohnen', 'Deko' ), wp_list_pluck( $nodes, 'name' ) );
		$this->assertCount( 1, Draft::leaves( $nodes ) );
	}

	/**
	 * Splicing out an echoed node must not take its real children with it — they
	 * belong to the grandparent instead.
	 *
	 * @return void
	 */
	public function test_an_echoed_node_hands_its_children_to_the_grandparent() {
		$this->make_products();

		$this->provider->will_answer(
			array(
				'categories' => array(
					array(
						'name'     => 'Spielwaren',
						'children' => array(
							array(
								'name'     => 'Spielwaren',
								'children' => array( array( 'name' => 'Holztiere' ) ),
							),
						),
					),
				),
			)
		);

		$this->run_chain( $this->job() );

		$nodes = Draft::get()['nodes'];
		$paths = array();

		foreach ( $nodes as $node ) {
			$paths[] = implode( ' > ', Draft::path( $nodes, $node['key'] ) );
		}

		$this->assertSame( array( 'Spielwaren', 'Spielwaren > Holztiere' ), $paths );
	}

	/**
	 * A name that is empty, or only markup, cannot become a term.
	 *
	 * @return void
	 */
	public function test_unusable_names_are_dropped() {
		$this->make_products();

		$this->provider->will_answer(
			array(
				'categories' => array(
					array( 'name' => '   ' ),
					array( 'name' => '<b></b>' ),
					array( 'name' => 'Spielwaren' ),
				),
			)
		);

		$this->run_chain( $this->job() );

		$this->assertSame( array( 'Spielwaren' ), wp_list_pluck( Draft::get()['nodes'], 'name' ) );
	}

	/**
	 * A category legitimately called "50% Sale" must survive. sanitize_text_field()
	 * would quietly turn it into "50 Sale".
	 *
	 * @return void
	 */
	public function test_a_percent_sign_in_a_name_survives() {
		$this->assertSame( '50% Sale', Draft::clean_name( '50% Sale' ) );
		$this->assertSame( 'Grüße & Karten', Draft::clean_name( "Grüße &\n Karten" ) );
		$this->assertSame( 'Deko', Draft::clean_name( '<script>x</script>Deko' ) );
	}

	/**
	 * Leaves are where products go, so identifying them has to be right.
	 *
	 * @return void
	 */
	public function test_leaves_are_the_nodes_with_no_children() {
		$this->make_products();
		$this->provider->will_answer( $this->tree() );

		$this->run_chain( $this->job() );

		$leaves = wp_list_pluck( Draft::leaves( Draft::get()['nodes'] ), 'name' );

		sort( $leaves );

		$this->assertSame( array( 'Deko', 'Holztiere', 'Puzzles' ), $leaves );
	}

	/**
	 * The sampler has to put the long tail in front of the model, because that is
	 * where the categories it would otherwise miss live.
	 *
	 * @return void
	 */
	public function test_the_sample_reaches_beyond_the_largest_brand() {
		register_taxonomy( 'product_brand', 'product', array( 'hierarchical' => false ) );

		$big   = self::factory()->term->create( array( 'taxonomy' => 'product_brand' ) );
		$small = self::factory()->term->create( array( 'taxonomy' => 'product_brand' ) );

		for ( $index = 0; $index < 200; $index++ ) {
			$id = self::factory()->post->create(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'post_title'  => 'Holztier ' . $index,
				)
			);
			wp_set_object_terms( $id, array( $big ), 'product_brand' );
		}

		$id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Einzelstueck Schreibwaren',
			)
		);
		wp_set_object_terms( $id, array( $small ), 'product_brand' );

		$names = Sampler::collect( 'publish' );

		$this->assertContains( 'Einzelstueck Schreibwaren', $names, 'A one-product brand must still be sampled.' );
	}
}
