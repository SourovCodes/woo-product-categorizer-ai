<?php
/**
 * Deactivation routine.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi;

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
