=== BotCreds Agent Chat ===
Contributors: joeboydston
Tags: ai, agent, chat, rest-api, botcreds
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
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

== Changelog ==

= 1.0.0 =
* Initial standalone release — extracted from BotCreds Agent Access 2.0.3
* Added: channel creation UI (+ Add Channel button)
* Added: standalone top-level menu when Agent Access is not active
* Improved: message renderer handles UTC timestamp offset correctly
