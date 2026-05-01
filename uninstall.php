<?php
/**
 * Uninstall BotCreds Agent Chat.
 *
 * NOTE: This does NOT drop the wp_agent_access_chat table by default —
 * preserving data if BotCreds Agent Access is still active. To force
 * a full wipe, set BOTCREDS_CHAT_UNINSTALL_DROP_TABLE=true in wp-config.php.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'botcreds_chat_version' );

if ( defined( 'BOTCREDS_CHAT_UNINSTALL_DROP_TABLE' ) && BOTCREDS_CHAT_UNINSTALL_DROP_TABLE ) {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'agent_access_chat' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
