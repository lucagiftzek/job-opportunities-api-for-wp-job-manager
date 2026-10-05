<?php
/**
 * Uninstall: removes every option, transient and scheduled event of the
 * plugin. Imported listings are deleted only when the site owner enabled
 * "Delete imported listings when the plugin is uninstalled".
 *
 * @package JOAWPJM
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans up one site.
 *
 * @return void
 */
function joawpjm_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'joawpjm_settings', array() );
	if ( is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] ) ) {
		// Direct query on purpose: WP Job Manager (and its "expired" status) may already be inactive.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup.
			$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 200", '_joawpjm_id' ) );
			foreach ( $post_ids as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}
		} while ( ! empty( $post_ids ) );
	}

	foreach ( array( 'joawpjm_settings', 'joawpjm_api_key', 'joawpjm_searches', 'joawpjm_state', 'joawpjm_log', 'joawpjm_key_invalid', 'joawpjm_lock' ) as $option ) {
		delete_option( $option );
	}
	foreach ( array( 'joawpjm_me', 'joawpjm_no_expired_feed', 'joawpjm_retry_after' ) as $transient ) {
		delete_transient( $transient );
	}
	// Per-user flash messages: joawpjm_flash_<user id>.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_joawpjm_' ) . '%', $wpdb->esc_like( '_transient_timeout_joawpjm_' ) . '%' ) );

	wp_clear_scheduled_hook( 'joawpjm_cron_import' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $joawpjm_site_id ) {
		switch_to_blog( $joawpjm_site_id );
		joawpjm_uninstall_site();
		restore_current_blog();
	}
} else {
	joawpjm_uninstall_site();
}
