<?php
/**
 * Plugin Uninstaller.
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Respects data retention settings: preserves configuration and history by default.
 * When opt-in cleanup is enabled, removes plugin options, transients, and tracking metadata.
 * Store pages, page content, and WooCommerce page assignments are ALWAYS retained.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Check if administrator opted in to data cleanup upon uninstall.
$settings = get_option( 'wpcalibrate_wcpr_settings', [] );
$cleanup_enabled = is_array( $settings ) && ! empty( $settings['uninstall_cleanup'] );

if ( ! $cleanup_enabled ) {
	// Default behavior: preserve all settings, history, and metadata.
	return;
}

global $wpdb;

// 1. Delete plugin-owned configuration and state options.
$plugin_options = [
	'wpcalibrate_wcpr_settings',
	'wpcalibrate_wcpr_version',
	'wpcalibrate_wcpr_history',
	'wpcalibrate_wcpr_last_check',
	'wpcalibrate_wcpr_retry_count',
	'wpcalibrate_wcpr_dismissed_hash',
	'wpcalibrate_wcpr_lock',
];

foreach ( $plugin_options as $opt ) {
	delete_option( $opt );
}

// 2. Delete plugin transients.
delete_transient( 'wpcalibrate_wcpr_lock' );
delete_transient( 'all_roles_health' );
delete_transient( 'wpcalibrate_wcpr_debounce' );

// 3. Clear scheduled hooks from WP-Cron and Action Scheduler.
wp_clear_scheduled_hook( 'wpcalibrate_wcpr_run_recovery' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wpcalibrate_wcpr_run_recovery', [], 'wpcalibrate-wc-pages-recovery' );
}

// 4. Remove plugin ownership and tracking metadata from posts.
// Note: Pages themselves and WooCommerce options are NEVER deleted or altered.
if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->postmeta ) ) {
	$plugin_meta_keys = [
		'_wpcalibrate_wcpr_tracked_role',
		'_wpcalibrate_wcpr_created_by',
		'_wpcalibrate_wcpr_created_at',
	];

	foreach ( $plugin_meta_keys as $meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->postmeta,
			[ 'meta_key' => $meta_key ],
			[ '%s' ]
		);
	}
}
