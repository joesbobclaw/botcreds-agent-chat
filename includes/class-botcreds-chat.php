<?php
/**
 * BotCreds Agent Chat — REST API endpoints and wp-admin chat UI.
 *
 * Primary REST namespace: botcreds-agent-chat/v1
 * Legacy aliases:         agent-access/v1 (registered only when Agent Access
 *                         plugin is NOT active, to avoid route conflicts)
 *
 * New in 1.2.0:
 *   - OC Push: after each human message saved, WordPress pushes it to the
 *     configured OpenClaw gateway endpoint in real-time (no polling).
 *   - SSE stream: GET /botcreds-agent-chat/v1/chat/stream — browser gets
 *     messages in real-time without polling.
 *   - Admin UI upgraded from 5s polling to SSE.
 *
 * OC Push settings (wp_options):
 *   botcreds_chat_oc_endpoint  — Full URL to OC inbound endpoint
 *   botcreds_chat_oc_secret    — Shared secret (sent as Bearer token)
 *   botcreds_chat_oc_enabled   — 1 = enabled, 0 = disabled
 *
 * @package BotCreds_Agent_Chat
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotCreds_Agent_Chat {

	/** @var string DB table name (without prefix). */
	const TABLE = 'agent_access_chat';

	/**
	 * Boot the chat subsystem.
	 */
	public static function init() {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Admin menu
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function register_menu() {
		if ( defined( 'AGENT_ACCESS_VERSION' ) ) {
			add_management_page(
				__( 'Agent Chat', 'botcreds-agent-chat' ),
				__( 'Agent Chat', 'botcreds-agent-chat' ),
				'manage_options',
				'botcreds-agent-chat',
				array( __CLASS__, 'render_page' )
			);
		} else {
			add_menu_page(
				__( 'BotCreds Chat', 'botcreds-agent-chat' ),
				__( 'Agent Chat', 'botcreds-agent-chat' ),
				'manage_options',
				'botcreds-agent-chat',
				array( __CLASS__, 'render_page' ),
				'dashicons-format-chat',
				80
			);
		}

		// OC Push settings sub-page
		add_submenu_page(
			defined( 'AGENT_ACCESS_VERSION' ) ? 'tools.php' : 'botcreds-agent-chat',
			__( 'OC Push Settings', 'botcreds-agent-chat' ),
			__( 'OC Push', 'botcreds-agent-chat' ),
			'manage_options',
			'botcreds-agent-chat-oc',
			array( __CLASS__, 'render_oc_settings_page' )
		);
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Assets
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'botcreds-agent-chat' ) ) {
			return;
		}
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * REST API routes
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function register_routes() {
		$perm = array( __CLASS__, 'permission_check' );

		// ── Primary routes ──────────────────────────────────────────────────

		register_rest_route( 'botcreds-agent-chat/v1', '/chat/channels', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'api_channels' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( 'botcreds-agent-chat/v1', '/chat/messages', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'api_messages' ),
			'permission_callback' => $perm,
			'args'                => array(
				'channel'  => array( 'type' => 'string',  'default' => 'general' ),
				'since_id' => array( 'type' => 'integer', 'default' => 0 ),
				'limit'    => array( 'type' => 'integer', 'default' => 50 ),
			),
		) );

		register_rest_route( 'botcreds-agent-chat/v1', '/chat/send', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'api_send' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( 'botcreds-agent-chat/v1', '/chat/poll', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'api_poll' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( 'botcreds-agent-chat/v1', '/chat/channels/create', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'api_create_channel' ),
			'permission_callback' => $perm,
		) );

		// ── SSE stream (new in 1.2.0) ────────────────────────────────────
		register_rest_route( 'botcreds-agent-chat/v1', '/chat/stream', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'api_stream' ),
			'permission_callback' => $perm,
			'args'                => array(
				'channel'  => array( 'type' => 'string',  'default' => 'general' ),
				'since_id' => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );

		// ── OC Push settings (new in 1.2.0) ─────────────────────────────
		register_rest_route( 'botcreds-agent-chat/v1', '/oc-settings', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'api_get_oc_settings' ),
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
		) );

		register_rest_route( 'botcreds-agent-chat/v1', '/oc-settings', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'api_save_oc_settings' ),
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
		) );

		// ── Legacy aliases (agent-access/v1) ────────────────────────────────
		if ( ! defined( 'AGENT_ACCESS_VERSION' ) ) {
			foreach ( array( 'channels', 'messages', 'poll' ) as $endpoint ) {
				register_rest_route( 'agent-access/v1', '/chat/' . $endpoint, array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'api_' . $endpoint ),
					'permission_callback' => $perm,
				) );
			}
			register_rest_route( 'agent-access/v1', '/chat/send', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'api_send' ),
				'permission_callback' => $perm,
			) );
			register_rest_route( 'agent-access/v1', '/chat/channels/create', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'api_create_channel' ),
				'permission_callback' => $perm,
			) );
			// SSE legacy alias
			register_rest_route( 'agent-access/v1', '/chat/stream', array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'api_stream' ),
				'permission_callback' => $perm,
			) );
		}
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * API handlers
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function api_channels( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$cache_key = 'botcreds_chat_api_channels';
		$rows      = wp_cache_get( $cache_key );
		if ( false === $rows ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT channel, COUNT(*) as msg_count, COUNT(DISTINCT sender) as member_count, MAX(timestamp) as last_message_at FROM `' . esc_sql( $table ) . '` GROUP BY channel ORDER BY channel'
				)
			);
			wp_cache_set( $cache_key, $rows, '', 30 );
		}

		$channels = array();
		foreach ( $rows as $r ) {
			$channels[] = array(
				'name'            => $r->channel,
				'description'     => '',
				'private'         => false,
				'member_count'    => (int) $r->member_count,
				'msg_count'       => (int) $r->msg_count,
				'last_message_at' => $r->last_message_at,
			);
		}

		return new WP_REST_Response( array( 'channels' => $channels ), 200 );
	}

	public static function api_messages( $request ) {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$channel = sanitize_text_field( $request->get_param( 'channel' ) ?: 'general' );
		$limit   = min( absint( $request->get_param( 'limit' ) ?: 50 ), 200 );
		$since   = absint( $request->get_param( 'since_id' ) ?: 0 );

		$cache_key = 'botcreds_msgs_' . md5( $channel . '_' . $since . '_' . $limit );
		$rows      = wp_cache_get( $cache_key );
		if ( false === $rows ) {
			if ( $since > 0 ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						'SELECT id, channel, sender, sender_type, message, timestamp FROM `' . esc_sql( $table ) . '` WHERE channel = %s AND id > %d ORDER BY id ASC LIMIT %d',
						$channel, $since, $limit
					)
				);
			} else {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						'SELECT id, channel, sender, sender_type, message, timestamp FROM `' . esc_sql( $table ) . '` WHERE channel = %s ORDER BY id ASC LIMIT %d',
						$channel, $limit
					)
				);
			}
			wp_cache_set( $cache_key, $rows, '', 5 );
		}

		return new WP_REST_Response( $rows ?: array(), 200 );
	}

	public static function api_send( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$channel     = sanitize_text_field( $request->get_param( 'channel' ) ?: 'general' );
		$sender      = sanitize_text_field( $request->get_param( 'sender' ) ?: 'anonymous' );
		$sender_type = sanitize_text_field( $request->get_param( 'sender_type' ) ?: 'human' );
		$message     = sanitize_textarea_field( $request->get_param( 'message' ) ?: '' );

		if ( empty( $message ) ) {
			return new WP_Error( 'empty_message', 'Message cannot be empty.', array( 'status' => 400 ) );
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'channel'     => $channel,
				'sender'      => $sender,
				'sender_type' => $sender_type,
				'message'     => $message,
				'timestamp'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'insert_failed', 'Failed to save message.', array( 'status' => 500 ) );
		}

		// Bust caches
		wp_cache_delete( 'botcreds_chat_channels' );
		wp_cache_delete( 'botcreds_chat_api_channels' );
		wp_cache_delete( 'botcreds_msgs_' . md5( $channel . '_0_50' ) );

		$result = array(
			'id'          => $wpdb->insert_id,
			'channel'     => $channel,
			'sender'      => $sender,
			'sender_type' => $sender_type,
			'message'     => $message,
			'timestamp'   => current_time( 'mysql', true ),
		);

		// NEW (1.2.0): Push to OpenClaw in real-time if this is a human message.
		if ( 'agent' !== $sender_type ) {
			self::push_to_oc( $result );
		}

		return new WP_REST_Response( $result, 201 );
	}

	public static function api_poll( $request ) {
		$channel = sanitize_text_field( $request->get_param( 'channel' ) ?: 'general' );
		$since   = absint( $request->get_param( 'since_id' ) ?: 0 );
		$timeout = min( absint( $request->get_param( 'timeout' ) ?: 25 ), 30 );

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$end = time() + $timeout;
		while ( time() < $end ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT id, channel, sender, sender_type, message, timestamp FROM `' . esc_sql( $table ) . '` WHERE channel = %s AND id > %d ORDER BY id ASC LIMIT 50',
					$channel, $since
				)
			);
			if ( ! empty( $rows ) ) {
				return new WP_REST_Response( $rows, 200 );
			}
			usleep( 500000 );
		}

		return new WP_REST_Response( array(), 200 );
	}

	public static function api_create_channel( $request ) {
		$name = sanitize_text_field( $request->get_param( 'name' ) ?: '' );
		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', 'Channel name is required.', array( 'status' => 400 ) );
		}
		$request->set_param( 'channel', $name );
		$request->set_param( 'sender', 'system' );
		$request->set_param( 'sender_type', 'system' );
		$request->set_param( 'message', '📢 Channel created.' );
		return self::api_send( $request );
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * SSE stream — new in 1.2.0
	 * GET /botcreds-agent-chat/v1/chat/stream?channel=xxx&since_id=xxx
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function api_stream( $request ) {
		$channel  = sanitize_text_field( $request->get_param( 'channel' ) ?: 'general' );
		$since_id = absint( $request->get_param( 'since_id' ) ?: 0 );

		// SSE response headers
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache, no-store' );
		header( 'X-Accel-Buffering: no' );  // Nginx: disable proxy buffering
		header( 'Connection: keep-alive' );

		// Flush all output buffers
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		flush();

		global $wpdb;
		$table     = $wpdb->prefix . self::TABLE;
		$start     = time();
		$max_secs  = 50;   // Stay under typical 60s proxy timeouts
		$poll_secs = 1;
		$hb_every  = 15;   // Heartbeat comment every N iterations
		$iter      = 0;

		while ( time() - $start < $max_secs ) {
			// Query for new messages with id > since_id
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, channel, sender, sender_type, message, timestamp
					 FROM `' . esc_sql( $table ) . '`
					 WHERE channel = %s AND id > %d
					 ORDER BY id ASC LIMIT 20',
					$channel,
					$since_id
				)
			);
			// phpcs:enable

			if ( ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					$data = wp_json_encode( array(
						'id'          => (int) $row->id,
						'sender'      => $row->sender,
						'sender_type' => $row->sender_type,
						'channel'     => $row->channel,
						'message'     => $row->message,
						'timestamp'   => $row->timestamp,
					) );
					echo 'id: ' . (int) $row->id . "\n";
					echo 'data: ' . $data . "\n\n";
					$since_id = max( $since_id, (int) $row->id );
				}
				flush();
			}

			$iter++;
			if ( 0 === ( $iter % $hb_every ) ) {
				echo ': heartbeat ' . time() . "\n\n";
				flush();
			}

			// Clear object cache between polls to avoid stale reads
			wp_cache_flush_group( 'default' );

			sleep( $poll_secs );
		}

		// Tell client to reconnect
		echo 'event: reconnect' . "\n";
		echo 'data: ' . wp_json_encode( array( 'since_id' => $since_id ) ) . "\n\n";
		flush();
		exit;
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * OC Push — new in 1.2.0
	 * ──────────────────────────────────────────────────────────────────────── */

	/**
	 * Get OC push configuration from wp_options.
	 */
	public static function get_oc_config() {
		return array(
			'endpoint' => get_option( 'botcreds_chat_oc_endpoint', '' ),
			'secret'   => get_option( 'botcreds_chat_oc_secret', '' ),
			'enabled'  => (bool) get_option( 'botcreds_chat_oc_enabled', false ),
		);
	}

	/**
	 * Push a message to the configured OC gateway endpoint.
	 * Fire-and-forget: non-blocking, won't delay the API response.
	 */
	public static function push_to_oc( array $message ) {
		$cfg = self::get_oc_config();

		if ( empty( $cfg['endpoint'] ) || ! $cfg['enabled'] ) {
			return;
		}

		$headers = array( 'Content-Type' => 'application/json' );
		if ( ! empty( $cfg['secret'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $cfg['secret'];
		}

		wp_remote_post(
			$cfg['endpoint'],
			array(
				'headers'   => $headers,
				'body'      => wp_json_encode( array(
					'id'          => $message['id'] ?? null,
					'sender'      => $message['sender'] ?? 'visitor',
					'sender_type' => $message['sender_type'] ?? 'human',
					'channel'     => $message['channel'] ?? 'general',
					'message'     => $message['message'] ?? '',
					'timestamp'   => $message['timestamp'] ?? gmdate( 'Y-m-d H:i:s' ),
					'source'      => site_url(),
				) ),
				'timeout'   => 3,
				'blocking'  => false,  // Fire and forget
				'sslverify' => true,
			)
		);
	}

	/**
	 * REST: GET /botcreds-agent-chat/v1/oc-settings
	 */
	public static function api_get_oc_settings( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$cfg = self::get_oc_config();
		// Mask the secret
		$cfg['secret'] = ! empty( $cfg['secret'] ) ? str_repeat( '*', 8 ) : '';
		return new WP_REST_Response( $cfg, 200 );
	}

	/**
	 * REST: POST /botcreds-agent-chat/v1/oc-settings
	 */
	public static function api_save_oc_settings( $request ) {
		$endpoint = $request->get_param( 'endpoint' );
		$secret   = $request->get_param( 'secret' );
		$enabled  = $request->get_param( 'enabled' );

		if ( null !== $endpoint ) {
			update_option( 'botcreds_chat_oc_endpoint', esc_url_raw( $endpoint ) );
		}
		if ( null !== $secret && $secret !== str_repeat( '*', 8 ) ) {
			update_option( 'botcreds_chat_oc_secret', sanitize_text_field( $secret ) );
		}
		if ( null !== $enabled ) {
			update_option( 'botcreds_chat_oc_enabled', (bool) $enabled );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Admin chat page renderer (upgraded to SSE in 1.2.0)
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function render_page() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$table_exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( ! $table_exists ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'BotCreds Agent Chat', 'botcreds-agent-chat' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Chat table not found. Please deactivate and reactivate the plugin.', 'botcreds-agent-chat' );
			echo '</p></div></div>';
			return;
		}

		$cache_key = 'botcreds_chat_channels';
		$channels  = wp_cache_get( $cache_key );
		if ( false === $channels ) {
			$channels = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT DISTINCT channel FROM `' . esc_sql( $table ) . '` ORDER BY channel ASC' )
			);
			wp_cache_set( $cache_key, $channels, '', 30 );
		}
		if ( empty( $channels ) ) {
			$channels = array( 'general' );
		}

		$current_user = wp_get_current_user();
		$display_name = $current_user->display_name ?: $current_user->user_login;
		$oc_cfg       = self::get_oc_config();
		$oc_status    = $oc_cfg['enabled'] && ! empty( $oc_cfg['endpoint'] )
			? '<span style="color:#00a32a">● Real-time push active</span>'
			: '<span style="color:#d63638">● Real-time push not configured</span>';

		?>
		<div class="wrap" id="bc-chat-wrap">
			<style>
				#bc-chat-wrap { display: flex; gap: 0; min-height: 70vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
				.bc-sidebar { width: 240px; flex-shrink: 0; background: #1d2327; color: #f0f0f1; display: flex; flex-direction: column; border-radius: 6px 0 0 6px; overflow-y: auto; }
				.bc-sidebar h2 { font-size: 14px; color: #a7aaad; padding: 16px 16px 8px; margin: 0; text-transform: uppercase; letter-spacing: 0.5px; }
				.bc-channel-list { list-style: none; margin: 0; padding: 0; flex: 1; }
				.bc-channel-list li { padding: 8px 16px; cursor: pointer; color: #c3c4c7; transition: background 0.15s; }
				.bc-channel-list li:hover { background: #2c3338; }
				.bc-channel-list li.active { background: #2271b1; color: #fff; }
				.bc-channel-list li::before { content: '#'; margin-right: 6px; opacity: 0.6; }
				.bc-add-channel { padding: 8px 16px 8px; }
				.bc-add-channel button { background: #2c3338; color: #a7aaad; border: 1px dashed #3c4349; border-radius: 4px; padding: 6px 12px; cursor: pointer; font-size: 12px; width: 100%; }
				.bc-add-channel button:hover { background: #3c4349; color: #f0f0f1; }
				.bc-oc-status { padding: 8px 16px; font-size: 11px; border-top: 1px solid #2c3338; }
				.bc-security-notice { padding: 12px 16px; background: #2c3338; border-top: 1px solid #3c4349; margin-top: auto; font-size: 11px; line-height: 1.5; color: #a7aaad; }
				.bc-security-notice summary { cursor: pointer; color: #c3c4c7; font-size: 12px; font-weight: 600; }
				.bc-security-notice ul { margin: 8px 0 0; padding-left: 14px; }
				.bc-main { flex: 1; display: flex; flex-direction: column; background: #fff; border: 1px solid #c3c4c7; border-left: 0; border-radius: 0 6px 6px 0; }
				.bc-header { padding: 12px 16px; border-bottom: 1px solid #e0e0e0; font-weight: 600; font-size: 15px; background: #f6f7f7; display: flex; align-items: center; gap: 8px; }
				.bc-header-channel::before { content: '#'; opacity: 0.5; }
				.bc-conn-dot { width: 8px; height: 8px; border-radius: 50%; background: #999; display: inline-block; transition: background 0.3s; }
				.bc-conn-dot.live { background: #00a32a; }
				.bc-conn-dot.error { background: #d63638; }
				.bc-header-meta { font-size: 12px; color: #999; font-weight: normal; margin-left: auto; }
				.bc-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; min-height: 300px; }
				.bc-msg { padding: 6px 0; }
				.bc-msg-sender { font-weight: 600; margin-right: 8px; }
				.bc-msg-sender.bot, .bc-msg-sender.agent { color: #2271b1; }
				.bc-msg-sender.bot::after, .bc-msg-sender.agent::after { content: ' 🤖'; font-size: 12px; }
				.bc-msg-time { color: #999; font-size: 11px; }
				.bc-msg-body { margin-top: 2px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
				.bc-compose { display: flex; gap: 8px; padding: 12px 16px; border-top: 1px solid #e0e0e0; background: #f6f7f7; }
				.bc-compose input { flex: 1; padding: 8px 12px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 14px; }
				.bc-compose button { padding: 8px 20px; background: #2271b1; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
				.bc-compose button:hover { background: #135e96; }
				.bc-compose button:disabled { opacity: 0.5; cursor: not-allowed; }
				.bc-status { padding: 4px 16px; color: #999; font-size: 12px; font-style: italic; min-height: 20px; }
			</style>

			<div class="bc-sidebar">
				<h2><?php esc_html_e( 'Channels', 'botcreds-agent-chat' ); ?></h2>
				<ul class="bc-channel-list" id="bc-channels">
					<?php foreach ( $channels as $i => $ch ) : ?>
						<li data-channel="<?php echo esc_attr( $ch ); ?>" class="<?php echo 0 === $i ? 'active' : ''; ?>">
							<?php echo esc_html( $ch ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="bc-add-channel"><button id="bc-add-channel-btn">+ Add Channel</button></div>
				<div class="bc-oc-status"><?php echo $oc_status; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<div class="bc-security-notice">
					<details>
						<summary>🔒 Security &amp; Scope</summary>
						<ul>
							<li><strong>Auth:</strong> WP Application Passwords over HTTPS</li>
							<li><strong>Visibility:</strong> Site admins + AI agents</li>
							<li><strong>Storage:</strong> WordPress DB, plaintext at rest</li>
							<li><strong>Push:</strong> Real-time to OC (1.2.0+)</li>
						</ul>
					</details>
				</div>
			</div>

			<div class="bc-main">
				<div class="bc-header">
					<span class="bc-header-channel" id="bc-channel-label"><?php echo esc_html( $channels[0] ?? 'general' ); ?></span>
					<span class="bc-conn-dot" id="bc-conn-dot" title="SSE connection status"></span>
					<span class="bc-header-meta" id="bc-channel-meta"></span>
				</div>
				<div class="bc-messages" id="bc-messages"></div>
				<div class="bc-status" id="bc-status"></div>
				<div class="bc-compose">
					<input type="text" id="bc-input" placeholder="<?php esc_attr_e( 'Message #general...', 'botcreds-agent-chat' ); ?>" autocomplete="off" />
					<button id="bc-send"><?php esc_html_e( 'Send', 'botcreds-agent-chat' ); ?></button>
				</div>
			</div>

			<script>
			(function() {
				const restUrl     = '<?php echo esc_js( rest_url( 'botcreds-agent-chat/v1/chat' ) ); ?>';
				const nonce       = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
				const sender      = '<?php echo esc_js( $display_name ); ?>';
				const channelList = document.getElementById('bc-channels');
				const msgBox      = document.getElementById('bc-messages');
				const input       = document.getElementById('bc-input');
				const sendBtn     = document.getElementById('bc-send');
				const chanLabel   = document.getElementById('bc-channel-label');
				const chanMeta    = document.getElementById('bc-channel-meta');
				const statusEl    = document.getElementById('bc-status');
				const connDot     = document.getElementById('bc-conn-dot');
				const addBtn      = document.getElementById('bc-add-channel-btn');

				let activeChannel = channelList.querySelector('li.active')?.dataset?.channel || 'general';
				let lastId        = 0;
				let sseCtrl       = null;
				let sseRetryMs    = 1000;

				function hdrs() {
					return { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };
				}

				function escHtml(s) {
					const d = document.createElement('div');
					d.textContent = s;
					return d.innerHTML;
				}

				function renderMsg(m) {
					const st = m.sender_type || 'human';
					const cls = (st === 'bot' || st === 'agent') ? ' ' + st : '';
					const time = m.timestamp
						? new Date(m.timestamp.replace(' ', 'T') + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
						: '';
					return `<div class="bc-msg" data-id="${parseInt(m.id)||0}">
						<span class="bc-msg-sender${escHtml(cls)}">${escHtml(m.sender)}</span>
						<span class="bc-msg-time">${escHtml(time)}</span>
						<div class="bc-msg-body">${escHtml(m.message)}</div>
					</div>`;
				}

				function appendMsg(m) {
					const mid = parseInt(m.id) || 0;
					if (mid && msgBox.querySelector(`.bc-msg[data-id="${mid}"]`)) return; // dedupe
					const wasBottom = msgBox.scrollHeight - msgBox.scrollTop - msgBox.clientHeight < 60;
					msgBox.insertAdjacentHTML('beforeend', renderMsg(m));
					if (mid > lastId) lastId = mid;
					if (wasBottom) msgBox.scrollTop = msgBox.scrollHeight;
				}

				async function loadMessages(channel) {
					statusEl.textContent = 'Loading…';
					try {
						const r = await fetch(restUrl + '/messages?channel=' + encodeURIComponent(channel) + '&limit=50', { headers: hdrs() });
						if (!r.ok) throw new Error(r.status);
						const msgs = await r.json();
						const list = Array.isArray(msgs) ? msgs : (msgs.messages || []);
						msgBox.innerHTML = list.map(renderMsg).join('');
						msgBox.scrollTop = msgBox.scrollHeight;
						lastId = list.length ? Math.max(...list.map(m => parseInt(m.id) || 0)) : 0;
						chanMeta.textContent = list.length + ' messages';
						statusEl.textContent = '';
					} catch (e) {
						statusEl.textContent = 'Failed to load.';
					}
				}

				// ── SSE stream ──────────────────────────────────────────────────

				async function subscribeSSE() {
					if (sseCtrl) sseCtrl.abort();
					sseCtrl = new AbortController();

					const params = new URLSearchParams({
						channel:  activeChannel,
						since_id: lastId,
						_wpnonce: nonce,
					});

					try {
						const res = await fetch(restUrl + '/stream?' + params, {
							headers: { 'X-WP-Nonce': nonce },
							signal: sseCtrl.signal,
						});
						if (!res.ok) throw new Error('SSE ' + res.status);

						connDot.className = 'bc-conn-dot live';
						statusEl.textContent = '';
						sseRetryMs = 1000;

						const reader  = res.body.getReader();
						const decoder = new TextDecoder();
						let   buf     = '';

						while (true) {
							const { done, value } = await reader.read();
							if (done) break;
							buf += decoder.decode(value, { stream: true });
							const lines = buf.split('\n');
							buf = lines.pop();

							for (const line of lines) {
								if (line.startsWith('data: ')) {
									const raw = line.slice(6).trim();
									if (!raw || raw === '{}') continue;
									try {
										const msg = JSON.parse(raw);
										if (msg.since_id !== undefined) {
											lastId = Math.max(lastId, msg.since_id);
										} else {
											appendMsg(msg);
										}
									} catch {}
								}
							}
						}

						connDot.className = 'bc-conn-dot';
						setTimeout(subscribeSSE, 300);

					} catch (err) {
						if (err.name === 'AbortError') return;
						connDot.className = 'bc-conn-dot error';
						statusEl.textContent = '⚠ Reconnecting…';
						sseRetryMs = Math.min(sseRetryMs * 2, 15000);
						setTimeout(subscribeSSE, sseRetryMs);
					}
				}

				// ── Send ────────────────────────────────────────────────────────

				async function sendMessage() {
					const text = input.value.trim();
					if (!text) return;
					input.value = '';
					sendBtn.disabled = true;
					try {
						const res = await fetch(restUrl + '/send', {
							method: 'POST',
							headers: hdrs(),
							body: JSON.stringify({ channel: activeChannel, sender, sender_type: 'human', message: text })
						});
						if (!res.ok) throw new Error(res.status);
						// Optimistic: append own message
						const saved = await res.json();
						appendMsg({ ...saved, sender, sender_type: 'human', message: text });
					} catch (e) {
						statusEl.textContent = 'Send failed.';
					}
					sendBtn.disabled = false;
					input.focus();
				}

				// ── Channel switching ───────────────────────────────────────────

				function switchChannel(ch) {
					activeChannel = ch;
					chanLabel.textContent = ch;
					input.placeholder = 'Message #' + ch + '…';
					channelList.querySelectorAll('li').forEach(el => el.classList.toggle('active', el.dataset.channel === ch));
					lastId = 0;
					msgBox.innerHTML = '';
					loadMessages(ch).then(subscribeSSE);
				}

				channelList.querySelectorAll('li').forEach(el =>
					el.addEventListener('click', () => switchChannel(el.dataset.channel))
				);

				addBtn.addEventListener('click', () => {
					const name = prompt('New channel name:');
					if (!name) return;
					const clean = name.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
					fetch(restUrl + '/channels/create', {
						method: 'POST', headers: hdrs(),
						body: JSON.stringify({ name: clean })
					}).then(() => {
						const li = document.createElement('li');
						li.dataset.channel = clean;
						li.textContent = clean;
						li.addEventListener('click', () => switchChannel(clean));
						channelList.appendChild(li);
						switchChannel(clean);
					});
				});

				sendBtn.addEventListener('click', sendMessage);
				input.addEventListener('keydown', e => { if (e.key === 'Enter') sendMessage(); });

				// Init
				loadMessages(activeChannel).then(subscribeSSE);
			})();
			</script>
		</div>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * OC Push settings page — new in 1.2.0
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function render_oc_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}

		$saved = false;
		if ( isset( $_POST['_botcreds_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['_botcreds_nonce'] ), 'botcreds_oc_settings' ) ) {
			update_option( 'botcreds_chat_oc_endpoint', esc_url_raw( $_POST['oc_endpoint'] ?? '' ) );
			$secret = sanitize_text_field( $_POST['oc_secret'] ?? '' );
			if ( $secret && $secret !== str_repeat( '*', 8 ) ) {
				update_option( 'botcreds_chat_oc_secret', $secret );
			}
			update_option( 'botcreds_chat_oc_enabled', isset( $_POST['oc_enabled'] ) ? 1 : 0 );
			$saved = true;
		}

		$cfg = self::get_oc_config();
		?>
		<div class="wrap">
			<h1>BotCreds Agent Chat — OC Push Settings</h1>
			<?php if ( $saved ) : ?><div class="notice notice-success"><p>Settings saved.</p></div><?php endif; ?>
			<p>When a human sends a message, WordPress pushes it to your OpenClaw gateway in real-time — no polling needed on the OC side.</p>
			<form method="post">
				<?php wp_nonce_field( 'botcreds_oc_settings', '_botcreds_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="oc_endpoint">OC Inbound Endpoint</label></th>
						<td>
							<input type="url" id="oc_endpoint" name="oc_endpoint"
								   value="<?php echo esc_attr( $cfg['endpoint'] ); ?>"
								   class="regular-text code"
								   placeholder="https://beff.newsy.us/plugins/oc-agent-chat/inbound">
							<p class="description">The HTTP route registered by the OC channel plugin. For Beff: <code>https://beff.newsy.us/plugins/oc-agent-chat/inbound</code></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oc_secret">Shared Secret</label></th>
						<td>
							<input type="password" id="oc_secret" name="oc_secret"
								   value="<?php echo $cfg['secret'] ? esc_attr( str_repeat( '*', 8 ) ) : ''; ?>"
								   class="regular-text"
								   placeholder="Leave blank if not set; replace to update">
							<p class="description">Must match <code>inboundSecret</code> in the OC plugin config. Sent as <code>Authorization: Bearer &lt;secret&gt;</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Enable Push</th>
						<td>
							<label>
								<input type="checkbox" name="oc_enabled" value="1" <?php checked( $cfg['enabled'] ); ?>>
								Push new human messages to OpenClaw in real-time
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>
			<hr>
			<h2>Current Status</h2>
			<p>
				<strong>Endpoint:</strong> <code><?php echo esc_html( $cfg['endpoint'] ?: '(not set)' ); ?></code><br>
				<strong>Secret:</strong> <?php echo $cfg['secret'] ? '✅ Configured' : '⚠ Not set'; ?><br>
				<strong>Push enabled:</strong> <?php echo $cfg['enabled'] ? '✅ Yes' : '❌ No'; ?>
			</p>
		</div>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Permission callback
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function permission_check() {
		return current_user_can( 'manage_options' );
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Install
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function install() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			channel VARCHAR(100) NOT NULL DEFAULT 'general',
			sender VARCHAR(200) NOT NULL,
			sender_type VARCHAR(50) NOT NULL DEFAULT 'human',
			message LONGTEXT NOT NULL,
			timestamp DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY channel_ts (channel, timestamp),
			KEY channel_id (channel, id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'botcreds_chat_version', BOTCREDS_CHAT_VERSION );
	}
}
