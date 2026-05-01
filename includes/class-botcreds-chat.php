<?php
/**
 * BotCreds Agent Chat — REST API endpoints and wp-admin chat UI.
 *
 * Primary REST namespace: botcreds-agent-chat/v1
 * Legacy aliases:         agent-access/v1 (registered only when Agent Access
 *                         plugin is NOT active, to avoid route conflicts)
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
		// Don't double-init if somehow called twice.
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
		// Nest under Agent Access if active; otherwise, own top-level menu.
		if ( defined( 'AGENT_ACCESS_VERSION' ) ) {
			add_submenu_page(
				'botcreds-agent-access',
				__( 'Agent Chat', 'botcreds-agent-chat' ),
				__( 'Agent Chat', 'botcreds-agent-chat' ),
				'read',
				'botcreds-agent-chat',
				array( __CLASS__, 'render_page' )
			);
			// Remove the old built-in Chat submenu from Agent Access if it still exists.
			remove_submenu_page( 'botcreds-agent-access', 'agent-access-chat' );
		} else {
			add_menu_page(
				__( 'BotCreds Chat', 'botcreds-agent-chat' ),
				__( 'Agent Chat', 'botcreds-agent-chat' ),
				'read',
				'botcreds-agent-chat',
				array( __CLASS__, 'render_page' ),
				'dashicons-format-chat',
				80
			);
		}
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Chat page renderer
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function render_page() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// Check if chat table exists.
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

		// Get channels.
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

		?>
		<div class="wrap" id="bc-chat-wrap">
			<style>
				#bc-chat-wrap { display: flex; gap: 0; min-height: 70vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
				.bc-sidebar { width: 240px; flex-shrink: 0; background: #1d2327; color: #f0f0f1; display: flex; flex-direction: column; border-radius: 6px 0 0 6px; overflow-y: auto; }
				.bc-sidebar h2 { font-size: 14px; color: #a7aaad; padding: 16px 16px 8px; margin: 0; text-transform: uppercase; letter-spacing: 0.5px; }
				.bc-sidebar .bc-channel-list { list-style: none; margin: 0; padding: 0; flex: 1; }
				.bc-sidebar .bc-channel-list li { padding: 8px 16px; cursor: pointer; color: #c3c4c7; transition: background 0.15s; }
				.bc-sidebar .bc-channel-list li:hover { background: #2c3338; }
				.bc-sidebar .bc-channel-list li.active { background: #2271b1; color: #fff; }
				.bc-sidebar .bc-channel-list li::before { content: '#'; margin-right: 6px; opacity: 0.6; }
				.bc-add-channel { padding: 8px 16px 16px; }
				.bc-add-channel button { background: #2c3338; color: #a7aaad; border: 1px dashed #3c4349; border-radius: 4px; padding: 6px 12px; cursor: pointer; font-size: 12px; width: 100%; }
				.bc-add-channel button:hover { background: #3c4349; color: #f0f0f1; }
				.bc-security-notice { padding: 12px 16px; background: #2c3338; border-top: 1px solid #3c4349; margin-top: auto; font-size: 11px; line-height: 1.5; color: #a7aaad; }
				.bc-security-notice summary { cursor: pointer; color: #c3c4c7; font-size: 12px; font-weight: 600; }
				.bc-security-notice summary:hover { color: #f0f0f1; }
				.bc-security-notice ul { margin: 8px 0 0; padding-left: 14px; }
				.bc-security-notice li { margin-bottom: 4px; }
				.bc-main { flex: 1; display: flex; flex-direction: column; background: #fff; border: 1px solid #c3c4c7; border-left: 0; border-radius: 0 6px 6px 0; }
				.bc-header { padding: 12px 16px; border-bottom: 1px solid #e0e0e0; font-weight: 600; font-size: 15px; background: #f6f7f7; display: flex; align-items: center; gap: 8px; }
				.bc-header-channel::before { content: '#'; opacity: 0.5; }
				.bc-header-meta { font-size: 12px; color: #999; font-weight: normal; margin-left: auto; }
				.bc-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; min-height: 300px; }
				.bc-msg { padding: 6px 0; }
				.bc-msg-sender { font-weight: 600; margin-right: 8px; }
				.bc-msg-sender.bot { color: #2271b1; }
				.bc-msg-sender.bot::after { content: ' 🤖'; font-size: 12px; }
				.bc-msg-sender.agent { color: #7c3aed; }
				.bc-msg-sender.agent::after { content: ' 🤖'; font-size: 12px; }
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
						<li data-channel="<?php echo esc_attr( $ch ); ?>"
							class="<?php echo 0 === $i ? 'active' : ''; ?>">
							<?php echo esc_html( $ch ); ?>
						</li>
					<?php endforeach; ?>
				</ul>

				<div class="bc-add-channel">
					<button id="bc-add-channel-btn">+ Add Channel</button>
				</div>

				<div class="bc-security-notice">
					<details>
						<summary>🔒 Security &amp; Scope</summary>
						<ul>
							<li><strong>Auth:</strong> WP Application Passwords over HTTPS</li>
							<li><strong>Visibility:</strong> Site admins + connected AI agents</li>
							<li><strong>Storage:</strong> WordPress DB, plaintext at rest</li>
							<li><strong>Isolation:</strong> Messages stay on this site</li>
							<li><strong>No E2E encryption</strong></li>
						</ul>
					</details>
				</div>
			</div>

			<div class="bc-main">
				<div class="bc-header">
					<span class="bc-header-channel" id="bc-channel-label"><?php echo esc_html( $channels[0] ?? 'general' ); ?></span>
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
				const restUrl   = '<?php echo esc_js( rest_url( 'botcreds-agent-chat/v1/chat' ) ); ?>';
				const nonce     = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
				const sender    = '<?php echo esc_js( $display_name ); ?>';
				const channelList = document.getElementById('bc-channels');
				const msgBox    = document.getElementById('bc-messages');
				const input     = document.getElementById('bc-input');
				const sendBtn   = document.getElementById('bc-send');
				const chanLabel = document.getElementById('bc-channel-label');
				const chanMeta  = document.getElementById('bc-channel-meta');
				const statusEl  = document.getElementById('bc-status');
				const addBtn    = document.getElementById('bc-add-channel-btn');

				let activeChannel = channelList.querySelector('li.active')?.dataset?.channel || 'general';
				let lastId = 0;
				let polling = null;

				function headers() {
					return { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };
				}

				function escHtml(s) {
					const d = document.createElement('div');
					d.textContent = s;
					return d.innerHTML;
				}

				function renderMsg(m) {
					const st = m.sender_type || 'human';
					const isBotClass = (st === 'bot' || st === 'agent') ? ' ' + st : '';
					const time = m.timestamp
						? new Date(m.timestamp.replace(' ', 'T') + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
						: '';
					return `<div class="bc-msg" data-id="${parseInt(m.id)||0}">
						<span class="bc-msg-sender${isBotClass}">${escHtml(m.sender)}</span>
						<span class="bc-msg-time">${escHtml(time)}</span>
						<div class="bc-msg-body">${escHtml(m.message)}</div>
					</div>`;
				}

				async function loadMessages(channel) {
					statusEl.textContent = 'Loading…';
					try {
						const r = await fetch(restUrl + '/messages?channel=' + encodeURIComponent(channel) + '&limit=50', { headers: headers() });
						if (!r.ok) throw new Error(r.status);
						const msgs = await r.json();
						const list = Array.isArray(msgs) ? msgs : (msgs.messages || []);
						msgBox.innerHTML = list.map(renderMsg).join('');
						msgBox.scrollTop = msgBox.scrollHeight;
						lastId = list.length ? Math.max(...list.map(m => parseInt(m.id) || 0)) : 0;
						chanMeta.textContent = list.length + ' messages';
						statusEl.textContent = '';
					} catch (e) {
						statusEl.textContent = 'Failed to load messages.';
					}
				}

				async function pollNew() {
					try {
						const r = await fetch(restUrl + '/messages?channel=' + encodeURIComponent(activeChannel) + '&since_id=' + lastId + '&limit=20', { headers: headers() });
						if (!r.ok) return;
						const msgs = await r.json();
						const list = Array.isArray(msgs) ? msgs : (msgs.messages || []);
						for (const m of list) {
							const mid = parseInt(m.id) || 0;
							if (mid > lastId) {
								lastId = mid;
								msgBox.insertAdjacentHTML('beforeend', renderMsg(m));
							}
						}
						if (list.length) msgBox.scrollTop = msgBox.scrollHeight;
					} catch (e) { /* silent */ }
				}

				async function sendMessage() {
					const text = input.value.trim();
					if (!text) return;
					input.value = '';
					sendBtn.disabled = true;
					statusEl.textContent = 'Sending…';

					try {
						await fetch(restUrl + '/send', {
							method: 'POST',
							headers: headers(),
							body: JSON.stringify({
								channel: activeChannel,
								sender: sender,
								sender_type: 'human',
								message: text
							})
						});
						await pollNew();
						statusEl.textContent = '';
					} catch (e) {
						statusEl.textContent = 'Send failed.';
					} finally {
						sendBtn.disabled = false;
						input.focus();
					}
				}

				function addChannelToSidebar(ch) {
					const li = document.createElement('li');
					li.dataset.channel = ch;
					li.textContent = ch;
					li.addEventListener('click', () => switchChannel(ch));
					channelList.appendChild(li);
				}

				async function createChannel(name) {
					try {
						await fetch(restUrl + '/send', {
							method: 'POST',
							headers: headers(),
							body: JSON.stringify({ channel: name, sender: sender, sender_type: 'system', message: '📢 Channel created.' })
						});
						addChannelToSidebar(name);
						switchChannel(name);
					} catch (e) {
						alert('Failed to create channel.');
					}
				}

				function switchChannel(ch) {
					activeChannel = ch;
					chanLabel.textContent = ch;
					input.placeholder = 'Message #' + ch + '…';
					channelList.querySelectorAll('li').forEach(el => el.classList.toggle('active', el.dataset.channel === ch));
					lastId = 0;
					msgBox.innerHTML = '';
					loadMessages(ch);
				}

				// Wire up sidebar channel clicks.
				channelList.querySelectorAll('li').forEach(el =>
					el.addEventListener('click', () => switchChannel(el.dataset.channel))
				);

				// Add channel button.
				addBtn.addEventListener('click', () => {
					const name = prompt('New channel name (lowercase, no spaces):');
					if (name) createChannel(name.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, ''));
				});

				sendBtn.addEventListener('click', sendMessage);
				input.addEventListener('keydown', e => { if (e.key === 'Enter') sendMessage(); });

				// Initial load + poll.
				loadMessages(activeChannel);
				polling = setInterval(pollNew, 5000);
			})();
			</script>
		</div>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Assets
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function enqueue_assets( $hook ) {
		// Assets are inline in render_page() for simplicity.
		// Extract to separate files in a future version.
		if ( false === strpos( $hook, 'botcreds-agent-chat' ) ) {
			return;
		}
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * REST API routes
	 *
	 * Primary:       botcreds-agent-chat/v1/chat/*
	 * Legacy aliases: agent-access/v1/chat/* — only registered when Agent
	 *                 Access is NOT active, to prevent route conflicts.
	 *                 Remove Agent Access's chat module before relying on these.
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

		// ── Legacy aliases (agent-access/v1) ────────────────────────────────
		// Only registered when Agent Access plugin is NOT active.
		// If Agent Access is active, it still owns these routes;
		// remove its chat module first, then these aliases take over cleanly.
		if ( ! defined( 'AGENT_ACCESS_VERSION' ) ) {
			foreach ( array( 'channels', 'messages', 'poll' ) as $endpoint ) {
				$method = 'GET';
				register_rest_route( 'agent-access/v1', '/chat/' . $endpoint, array(
					'methods'             => $method,
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

		// Bust caches so next read is fresh.
		wp_cache_delete( 'botcreds_chat_channels' );
		wp_cache_delete( 'botcreds_chat_api_channels' );
		wp_cache_delete( 'botcreds_msgs_' . md5( $channel . '_0_50' ) );

		return new WP_REST_Response(
			array(
				'id'          => $wpdb->insert_id,
				'channel'     => $channel,
				'sender'      => $sender,
				'sender_type' => $sender_type,
				'message'     => $message,
				'timestamp'   => current_time( 'mysql', true ),
			),
			201
		);
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
			usleep( 500000 ); // 0.5s
		}

		return new WP_REST_Response( array(), 200 );
	}

	public static function api_create_channel( $request ) {
		$name = sanitize_text_field( $request->get_param( 'name' ) ?: '' );
		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', 'Channel name is required.', array( 'status' => 400 ) );
		}
		// Create channel by posting a system message to it.
		$request->set_param( 'channel', $name );
		$request->set_param( 'sender', 'system' );
		$request->set_param( 'sender_type', 'system' );
		$request->set_param( 'message', '📢 Channel created.' );
		return self::api_send( $request );
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Permission callback
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function permission_check() {
		return current_user_can( 'read' ) || self::is_app_password_request();
	}

	private static function is_app_password_request() {
		return ! empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['PHP_AUTH_PW'] );
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Install — create the chat table on activation.
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
