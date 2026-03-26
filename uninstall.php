<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * This file ensures that all database traces of the Quelora Integration
 * are completely purged when the user deletes the plugin from the WordPress
 * admin panel. It removes all registered options, sync transients, and
 * post metadata.
 *
 * @package Quelora
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Purge all standard and internal options.
 */
$quelora_options = array(
	'quelora_dashboard_url',
	'quelora_asset_source',
	'quelora_cdn_js_url',
	'quelora_cdn_css_url',
	'quelora_header_selector',
	'quelora_config_script',
	'quelora_custom_css',
	'quelora_default_active',
	'quelora_placement_strategy',
	'quelora_hide_wp_comments',
	'quelora_sso_enabled',
	'quelora_sso_secret_key',
	'quelora_sso_token_ttl',
	'quelora_sync_posts_endpoint',
	'quelora_sync_users_endpoint',
	'quelora_sync_posts_status',
	'quelora_sync_posts_synced',
	'quelora_sync_posts_total',
	'quelora_sync_posts_last_run',
	'quelora_sync_posts_offset',
	'quelora_sync_posts_running',
	'quelora_sync_users_status',
	'quelora_sync_users_synced',
	'quelora_sync_users_total',
	'quelora_sync_users_last_run',
	'quelora_sync_users_offset',
	'quelora_sync_users_running',
	'quelora_is_configured',
);

foreach ( $quelora_options as $option ) {
	delete_option( $option );
}

/**
 * Purge temporary transients used for sync locks.
 */
delete_transient( 'quelora_posts_sync_lock' );
delete_transient( 'quelora_users_sync_lock' );

/**
 * Purge post metadata.
 * Executes a direct database query to prevent memory exhaustion on large datasets.
 */
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_quelora_active'" );