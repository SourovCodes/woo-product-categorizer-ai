<?php
/**
 * Deactivation routine.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi;

use WooProductCategorizerAi\Categorize\BulkRun;
use WooProductCategorizerAi\Jobs\Scheduler;
use WooProductCategorizerAi\Jobs\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is deactivated.
 */
final class Deactivator {

	/**
	 * Stop all background work.
	 *
	 * Settings, the taxonomy draft and the revert ledger are deliberately left in
	 * place so that a deactivate/reactivate cycle does not lose an approved tree or
	 * the ability to undo the last run. Removal belongs in uninstall.php.
	 *
	 * A run in flight is closed out first, and the order matters. Cancelling the
	 * queue destroys the chain that would have reported the outcome, so without this
	 * a deactivation timed to land mid-run leaves the job reading "running" for the
	 * next six hours, with Run now refusing to start another because one is
	 * supposedly already going.
	 *
	 * @return void
	 */
	public static function deactivate() {
		/*
		 * A batch already handed to the provider is the one thing here that keeps
		 * costing money after the plugin stops. Nothing local will poll it again —
		 * the queue is about to be emptied — so it would run to completion, be billed
		 * in full, and produce answers no one will ever collect. Told to stop first,
		 * while there is still something able to ask.
		 */
		if ( ! empty( BulkRun::in_flight() ) ) {
			$cancelled = ( new BulkRun() )->cancel();

			if ( is_wp_error( $cancelled ) ) {
				Scheduler::log( 'warning', 'Could not stop the batch during deactivation: ' . $cancelled->get_error_message() );
			}

			/*
			 * Dropped either way. If the provider could not be reached, the batch is
			 * beyond reach too — nothing here will poll it again — and a record that
			 * cannot be acted on would only strand the job behind the in-flight guard
			 * on reactivation.
			 */
			BulkRun::forget();
		}

		Status::abandon( __( 'Interrupted: the plugin was deactivated while this run was in progress.', 'woo-product-categorizer-ai' ) );

		Scheduler::unschedule_all();

		/**
		 * Fires after the plugin has finished its deactivation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_product_categorizer_ai_deactivated' );
	}
}
