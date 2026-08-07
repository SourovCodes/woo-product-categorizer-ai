<?php
/**
 * The draft review and edit section.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Admin;

use WooProductCategorizerAi\Taxonomy\Draft;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the proposed tree and parses the edits made to it.
 *
 * Split out of Settings because it is the one part of the screen with its own
 * form, its own POST parsing and its own script. It still renders into the same
 * page — this is a section, not a second screen.
 *
 * The editor is rendered by PHP only. taxonomy-editor.js never builds a row, so
 * there is exactly one implementation of that markup rather than two that have to
 * be kept in step.
 */
class TaxonomyScreen {

	/**
	 * Register the section's handlers.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_wpcai_save_draft', array( $this, 'handle_save' ) );
		add_action( 'admin_post_wpcai_discard_draft', array( $this, 'handle_discard' ) );
		add_action( 'admin_post_wpcai_restore_draft', array( $this, 'handle_restore' ) );
	}

	/**
	 * Save the edits made to the draft.
	 *
	 * @return void
	 */
	public function handle_save() {
		$this->authorise( 'wpcai_save_draft' );

		$stored = Draft::get();

		if ( empty( $stored['nodes'] ) ) {
			$this->redirect( 'no_draft' );
		}

		$settings = Settings::get_settings();

		$result = Draft::from_request(
			$stored,
			$this->submitted_rows(),
			$this->submitted_additions(),
			(int) $settings['max_depth']
		);

		Draft::save( $result['draft'] );

		$this->redirect( 'draft_saved', $result['counts'] );
	}

	/**
	 * Throw the draft away.
	 *
	 * @return void
	 */
	public function handle_discard() {
		$this->authorise( 'wpcai_discard_draft' );

		Draft::discard();

		$this->redirect( 'draft_discarded' );
	}

	/**
	 * Put back the draft a proposal replaced.
	 *
	 * @return void
	 */
	public function handle_restore() {
		$this->authorise( 'wpcai_restore_draft' );

		$this->redirect( Draft::restore_backup() ? 'draft_restored' : 'no_backup' );
	}

	/**
	 * Check the nonce and the capability.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	protected function authorise( $action ) {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-product-categorizer-ai' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Read the submitted node rows.
	 *
	 * Only the two fields the form actually offers are read, and both are cleaned
	 * where they are used. Nothing else in the row is trusted — notably not the
	 * parent, which is read from the stored draft instead.
	 *
	 * @return array Node key => submitted fields.
	 */
	protected function submitted_rows() {
		/*
		 * The nonce is verified by authorise(), which the sniff cannot see from here.
		 * The array itself is not sanitised as it arrives — every value taken out of it
		 * below is cleaned individually, and anything not named here is discarded.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised below.
		$raw = isset( $_POST['nodes'] ) && is_array( $_POST['nodes'] ) ? wp_unslash( $_POST['nodes'] ) : array();

		$rows = array();

		foreach ( $raw as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[ sanitize_key( $key ) ] = array(
				'name'   => isset( $row['name'] ) && is_scalar( $row['name'] ) ? (string) $row['name'] : '',
				'remove' => ! empty( $row['remove'] ),
			);
		}

		return $rows;
	}

	/**
	 * Read the additions box.
	 *
	 * @return string
	 */
	protected function submitted_additions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorise() verified it before this runs.
		return isset( $_POST['additions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['additions'] ) ) : '';
	}

	/**
	 * Go back to the settings screen with a result code.
	 *
	 * A code and some counts, never a message: the screen owns the wording, so
	 * nothing that arrived from outside it is ever echoed back.
	 *
	 * @param string $notice Result code.
	 * @param array  $counts Optional counts to carry.
	 * @return void
	 */
	protected function redirect( $notice, array $counts = array() ) {
		$args = array(
			'page'         => Settings::PAGE_SLUG,
			'wpcai_notice' => $notice,
		);

		foreach ( $counts as $name => $value ) {
			if ( $value > 0 ) {
				$args[ 'wpcai_' . $name ] = (int) $value;
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );

		exit;
	}

	/**
	 * Render the section.
	 *
	 * @return void
	 */
	public function render() {
		$draft = Draft::get();

		echo '<h2>' . esc_html__( 'Category tree', 'woo-product-categorizer-ai' ) . '</h2>';

		if ( empty( $draft['nodes'] ) ) {
			$this->render_empty();
			return;
		}

		$this->render_summary( $draft );
		$this->render_editor( $draft );
	}

	/**
	 * What to say when there is no draft yet.
	 *
	 * @return void
	 */
	protected function render_empty() {
		?>
		<p class="description">
			<?php echo esc_html__( 'No tree has been proposed yet. Run "Propose a category tree" below, and the result will appear here for you to review before anything is created.', 'woo-product-categorizer-ai' ); ?>
		</p>
		<?php
		$this->render_restore_form();
	}

	/**
	 * Say where this draft came from.
	 *
	 * @param array $draft The draft.
	 * @return void
	 */
	protected function render_summary( array $draft ) {
		$leaves = count( Draft::leaves( $draft['nodes'] ) );

		?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: total categories. 2: categories that can hold products. 3: number of products sampled. 4: model id. */
					__( '%1$d categories, of which %2$d can hold products. Designed from a sample of %3$d products by %4$s.', 'woo-product-categorizer-ai' ),
					count( $draft['nodes'] ),
					$leaves,
					(int) $draft['sample'],
					'' === $draft['model'] ? __( 'the default model', 'woo-product-categorizer-ai' ) : $draft['model']
				)
			);
			?>
			<?php if ( ! empty( $draft['edited'] ) ) : ?>
				<strong><?php echo esc_html__( 'You have edited this draft since it was proposed.', 'woo-product-categorizer-ai' ); ?></strong>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php echo esc_html__( 'Nothing exists in your shop yet. Edit the names below, remove anything you do not want, then press Create categories.', 'woo-product-categorizer-ai' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the editable tree.
	 *
	 * @param array $draft The draft.
	 * @return void
	 */
	protected function render_editor( array $draft ) {
		$settings = Settings::get_settings();
		$nodes    = $draft['nodes'];
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wpcai-tree-form">
			<?php wp_nonce_field( 'wpcai_save_draft' ); ?>
			<input type="hidden" name="action" value="wpcai_save_draft" />

			<table class="widefat striped wpcai-tree">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Category', 'woo-product-categorizer-ai' ); ?></th>
						<th scope="col" class="wpcai-tree-actions"><?php echo esc_html__( 'Remove', 'woo-product-categorizer-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $nodes as $node ) : ?>
						<?php $path = Draft::path( $nodes, $node['key'] ); ?>
						<tr
							class="wpcai-node wpcai-depth-<?php echo esc_attr( $node['depth'] ); ?>"
							data-key="<?php echo esc_attr( $node['key'] ); ?>"
							data-parent="<?php echo esc_attr( $node['parent'] ); ?>"
						>
							<td>
								<?php
								/*
								 * Indentation is a CSS class on the row, not padding baked into
								 * the value, so the field holds only the name. A name that
								 * arrived with its own indentation would become a term called
								 * "   Deko".
								 */
								?>
								<input
									type="text"
									class="regular-text wpcai-node-name"
									name="nodes[<?php echo esc_attr( $node['key'] ); ?>][name]"
									value="<?php echo esc_attr( $node['name'] ); ?>"
									maxlength="<?php echo esc_attr( Draft::NAME_MAX ); ?>"
									aria-label="<?php echo esc_attr( implode( ' › ', $path ) ); ?>"
								/>
								<code class="wpcai-path"><?php echo esc_html( implode( ' › ', $path ) ); ?></code>
							</td>
							<td class="wpcai-tree-actions">
								<label>
									<input type="checkbox" name="nodes[<?php echo esc_attr( $node['key'] ); ?>][remove]" value="1" />
									<span class="screen-reader-text">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: the category's full path. */
												__( 'Remove %s and everything under it', 'woo-product-categorizer-ai' ),
												implode( ' › ', $path )
											)
										);
										?>
									</span>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'Add categories', 'woo-product-categorizer-ai' ); ?></h3>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: the deepest level allowed. */
						__( 'One category per line, written as a full path with ">" between the levels — for example "Wohnen > Deko > Kerzen". A level that already exists is reused rather than duplicated. Paths deeper than %d levels are not added.', 'woo-product-categorizer-ai' ),
						(int) $settings['max_depth']
					)
				);
				?>
			</p>
			<textarea name="additions" rows="5" class="large-text code" placeholder="Wohnen &gt; Deko &gt; Kerzen"></textarea>

			<p class="submit">
				<button type="submit" class="button"><?php echo esc_html__( 'Save changes', 'woo-product-categorizer-ai' ); ?></button>
			</p>
		</form>

		<?php $this->render_restore_form(); ?>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wpcai-inline-form">
			<?php wp_nonce_field( 'wpcai_discard_draft' ); ?>
			<input type="hidden" name="action" value="wpcai_discard_draft" />
			<button type="submit" class="button-link wpcai-danger" data-wpcai-confirm="<?php echo esc_attr__( 'Discard this draft? The categories it describes have not been created, so nothing in your shop changes.', 'woo-product-categorizer-ai' ); ?>">
				<?php echo esc_html__( 'Discard this draft', 'woo-product-categorizer-ai' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Offer the previous draft back, when a proposal replaced an edited one.
	 *
	 * @return void
	 */
	protected function render_restore_form() {
		if ( ! Draft::has_backup() ) {
			return;
		}

		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wpcai-inline-form">
			<?php wp_nonce_field( 'wpcai_restore_draft' ); ?>
			<input type="hidden" name="action" value="wpcai_restore_draft" />
			<button type="submit" class="button-link">
				<?php echo esc_html__( 'Restore the draft you had edited before', 'woo-product-categorizer-ai' ); ?>
			</button>
		</form>
		<?php
	}
}
