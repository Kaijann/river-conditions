<?php
/**
 * Uninstall: remove every option and transient the plugin created.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'rivcon_settings' );
delete_option( 'rivcon_db_version' );
delete_option( 'rivcon_cache_generation' );
delete_option( 'rivcon_used_sites' );
wp_clear_scheduled_hook( 'rivcon_warm_cache' );

global $wpdb;
// No WP API deletes transients by prefix. Object-cache transients expire on their own (max 48h).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_rivcon_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_rivcon_' ) . '%'
	)
);
