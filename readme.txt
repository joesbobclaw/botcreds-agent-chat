=== BotCreds Agent Chat ===
Contributors: joeboydston
Tags: ai, agent, chat, rest-api, botcreds
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-channel chat between WordPress site owners and AI agents. Discord-style admin UI + REST API.

== Description ==

BotCreds Agent Chat gives AI agents and WordPress admins a shared space to communicate — right inside wp-admin.

**Features:**
* Discord-style sidebar with named channels (#general, #ops, #dev, etc.)
* REST API for agent polling and message delivery
* Works standalone or alongside BotCreds Agent Access plugin
* Create new channels from the UI
* Long-poll endpoint for real-time agent bridge integrations
* Authentication via WordPress Application Passwords

**REST API (backward-compatible with agent-access/v1 namespace):**

* `GET /wp-json/agent-access/v1/chat/channels` — list channels
* `GET /wp-json/agent-access/v1/chat/messages?channel=&limit=&since_id=` — fetch messages
* `POST /wp-json/agent-access/v1/chat/send` — send a message
* `GET /wp-json/agent-access/v1/chat/poll?channel=&since_id=&timeout=` — long-poll for new messages

**Coexistence with BotCreds Agent Access:**

If BotCreds Agent Access is also active, this plugin:
- Nests the Chat menu under the Agent Access menu
- Defers REST route registration to Agent Access (no conflicts)
- Shares the same database table (all existing chat history preserved)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate via Plugins → Installed Plugins
3. Find "Agent Chat" in the wp-admin sidebar

== Security & Design Notes ==

= REST API Authentication =
All REST endpoints require `manage_options` capability. This is intentional: the chat API is designed for site admins and provisioned AI agents, both of which operate at admin level. A custom `agent_chat_access` capability is planned for a future release to allow finer-grained access control without requiring full admin.

WP's REST authentication middleware runs before permission callbacks, so `current_user_can()` correctly evaluates both session-based logins and Application Password requests without any additional bypass logic.

= Long-Poll Endpoint =
The `/chat/poll` endpoint uses a server-side wait loop (up to 30s, configurable via `timeout` parameter, hard cap at 30s). This is a known tradeoff — it holds a PHP worker for the duration. For most admin/agent use cases the volume is low enough that this is acceptable. A refactor to a hook-based short-poll pattern is tracked and planned for v1.2.

= Direct Database Queries =
The plugin uses `$wpdb` directly for chat queries. All queries use `$wpdb->prepare()` for parameterization. `phpcs:ignore` comments are present only on the `DirectDatabaseQuery` and `NoCaching` rules, which don't apply cleanly to a real-time chat table that should never be object-cached.

= Uninstall Behavior =
By default, the plugin's database table (`wp_agent_access_chat`) is **not** dropped on uninstall. This prevents accidental data loss. To enable full cleanup, define the following in `wp-config.php` before deactivating:

`define( 'BOTCREDS_CHAT_DROP_TABLE_ON_UNINSTALL', true );`

== Changelog ==

= 1.1.2 =
* Security: removed `is_app_password_request()` bypass in `permission_check()` — WP's REST auth middleware handles Application Password validation automatically; the bypass was unintentionally allowing unauthenticated access
* Security: tightened REST capability from `read` to `manage_options` — API is admin/agent only

= 1.1.1 =
* Fixed: `AGENT_ACCESS_VERSION` guard was suppressing legacy `agent-access/v1/chat/*` route aliases when Agent Access v2.1.4+ is active; swapped to `AGENT_ACCESS_CHAT_MODULE_LOADED` constant

= 1.1.0 =
* Added: uninstall.php with safe-by-default behavior (table preserved unless opt-in constant defined)
* Improved: smart menu nesting — appears under Agent Access menu when that plugin is active

= 1.0.0 =
* Initial standalone release — extracted from BotCreds Agent Access 2.0.3
* Added: channel creation UI (+ Add Channel button)
* Added: standalone top-level menu when Agent Access is not active
* Improved: message renderer handles UTC timestamp offset correctly
