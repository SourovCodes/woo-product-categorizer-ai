<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen. WordPress loads this
 * file directly, so nothing from the plugin's own bootstrap is available here.
 *
 * @package WooProductCategorizerAi
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Cancel any queued work before its handlers disappear.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'woo-product-categorizer-ai' );
}

// The settings hold the API key, so this one is not merely tidy-up.
delete_option( 'woo_product_categorizer_ai_settings' );
delete_option( 'woo_product_categorizer_ai_version' );
delete_option( 'woo_product_categorizer_ai_job_status' );
delete_option( 'woo_product_categorizer_ai_taxonomy_draft' );
delete_option( 'woo_product_categorizer_ai_taxonomy_draft_previous' );
delete_option( 'woo_product_categorizer_ai_last_apply' );
delete_option( 'woo_product_categorizer_ai_batch' );

/*
 * Drop the working set of any run interrupted part way through. delete_expired_transients()
 * only takes the ones that have already lapsed, and a bulk run's survive for 36 hours —
 * long enough that uninstalling mid-run would leave megabytes of catalogue behind — so
 * they are removed by name as well.
 */
global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removing our own transients by prefix on uninstall; there is no API for a prefix delete.
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_wpcai\_%'
	    OR option_name LIKE '\_transient\_timeout\_wpcai\_%'"
);

delete_expired_transients();

/*
 * Three things are deliberately left behind.
 *
 * The product_cat terms are the shop's catalogue now. Whoever created them, they
 * are what the menus link to, what the category pages are, and what every product
 * is filed under. Deleting a shop's categories is not something uninstalling a
 * plugin gets to do.
 *
 * The _wpcai_path_hash and _wpcai_node_key term meta is what makes creating terms
 * from a draft idempotent. Dropping it would strand a reinstall: the next Create
 * would recognise none of the existing terms and duplicate every one of them.
 *
 * The _wpcai_previous_cats post meta is the harder call — it is thousands of rows
 * nothing will read again once the plugin is gone. But it is also the only
 * surviving record of what each product's categories were before the plugin
 * rewrote them, and uninstalling should not be the act that destroys the ability
 * to reconstruct that. The settings screen carries a "Forget revert history"
 * button for anyone who wants those rows gone, which is the explicit way to ask
 * for it.
 */
