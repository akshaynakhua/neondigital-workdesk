<?php
/**
 * Uninstall. Data is kept unless the administrator explicitly enabled
 * "Delete all WorkDesk data when the plugin is deleted" in Settings.
 * Deactivation never removes data.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'ndwd_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$tables = array( 'teams', 'team_members', 'projects', 'tags', 'tasks', 'task_assignees', 'task_teams', 'task_handoffs', 'task_tags', 'task_links', 'remarks', 'activity', 'attendance', 'notifications', 'email_log', 'reports', 'package_carryovers', 'scheduled_jobs', 'push_subscriptions', 'push_queue' );
$tables = array_merge( $tables, array( 'ws_connections', 'ws_entitlements', 'ws_invitations', 'ws_visibility', 'ws_requests', 'ws_comments', 'ws_deliverables', 'ws_approvals', 'ws_events', 'ws_directory', 'ws_leads', 'ws_bookings', 'ws_audit', 'ws_api_log', 'ws_nonces', 'ws_webhooks', 'ws_backup_log' ) );
foreach ( $tables as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ndwd_' . $t ); // phpcs:ignore
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ndwd\\_%'" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ndwd\\_%'" ); // phpcs:ignore
foreach ( array( 'ndwd_member', 'ndwd_manager', 'ndwd_admin' ) as $role ) {
	remove_role( $role );
}
// Report files in uploads are intentionally left for the administrator to remove manually.
