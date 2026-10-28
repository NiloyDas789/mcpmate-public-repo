=== McpMate ===
Contributors: niloy122
Tags: mcp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A short description of your plugin (150 characters max).

== Description ==

Describe what your plugin does and why someone should install it.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open **McpMate** in the admin sidebar.

== Connecting ==

After activation, a setup wizard opens once (it can be resumed from the Dashboard notice or **Settings → Run setup again**). It checks the site, lets you choose what the AI may do, shows how to add the site to your AI app, and waits for the app's first request. You can also connect by hand:

1. Open **McpMate → Connections** and copy your site's MCP URL (`https://your-site/wp-json/mcpmate/mcp`). The plugin is the MCP server and its own OAuth server: nothing runs anywhere else.
2. Add the MCP URL to your AI app (Claude, ChatGPT, Cursor, Claude Code).
3. The AI app opens your wp-admin to ask for approval. It acts as the administrator who approves it, and nothing is shared until you approve.
4. **McpMate → Connections** lists every approved app with a **Revoke** button. A revoke takes effect on your site at once.

No password is copied anywhere. Application Passwords and HTTP Basic auth are not accepted by this plugin's REST routes.

== External services ==

AI apps connect to the site's own MCP URL (`/wp-json/mcpmate/mcp`). The plugin does not use a McpMate server, and sends nothing anywhere on its own. It makes outbound requests only in these cases:

* Media uploads: when an AI app you approved asks to upload an image or PDF from a URL (`upload_media`), the plugin downloads that one file from the address it gave (public addresses only).
* AI app sign-in: when an AI app identifies itself with a client metadata document URL, the plugin fetches that small JSON document from the app's own address to show its name on the approval screen.
* This site itself: the setup wizard requests the site's own address (and its tunnel, when `MCPMATE_PUBLIC_URL` is set) to test that the REST API answers, and `get_page_html` requests one of the site's own pages.

Stock photos (optional): when an administrator adds their own Unsplash access key under McpMate → Tools → Integrations, the `search_stock_photos` and `upload_media` tools send the search words, page and photo IDs to the Unsplash API (https://api.unsplash.com) with that key, download the chosen photo from images.unsplash.com, and tell Unsplash when a photo was used, as Unsplash's API guidelines require. Nothing is sent to Unsplash without a key. Unsplash's terms: https://unsplash.com/api-terms and privacy policy: https://unsplash.com/privacy.

== Frequently Asked Questions ==

= Question? =

Answer.

== Screenshots ==

1. Description of the first screenshot.

== Changelog ==

= 1.0.0 =
* Initial release.
