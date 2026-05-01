<?php
/**
 * Plugin Name: BotCreds Agent Chat
 * Plugin URI:  https://botcreds.com/agent-chat/
 * Description: Multi-channel chat between WordPress site owners and AI agents. REST API + Discord-style admin UI. Standalone companion to BotCreds Agent Access.
 * Version:     1.0.0
 * Author:      Joe Boydston
 * Author URI:  https://botcreds.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: botcreds-agent-chat
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BOTCREDS_CHAT_VERSION', '1.0.0' );
define( 'BOTCREDS_CHAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOTCREDS_CHAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once BOTCREDS_CHAT_PLUGIN_DIR . 'includes/class-botcreds-chat.php';

/**
 * Initialize the plugin.
 */
function botcreds_agent_chat_init() {
	BotCreds_Chat::init();
}
add_action( 'plugins_loaded', 'botcreds_agent_chat_init' );

/**
 * Activation: create the chat table.
 */
register_activation_hook( __FILE__, array( 'BotCreds_Chat', 'install' ) );
