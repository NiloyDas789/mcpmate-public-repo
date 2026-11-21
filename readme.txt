=== MCPMate ===
Contributors: niloy122
Tags: mcp, ai, model context protocol, ai agent, oauth
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let Claude, ChatGPT and Cursor work on your WordPress site over MCP. You approve each AI app, choose its tools, and every change is logged.

== Description ==

MCPMate turns your WordPress site into an MCP (Model Context Protocol) server, so AI apps such as Claude, ChatGPT, Cursor and Claude Code can read and manage it for you. The plugin is the MCP server and its own OAuth sign-in server: AI apps connect straight to your site, and nothing runs on anyone else's servers.

You stay in control:

* **You approve every AI app.** An app that adds your site opens your wp-admin and waits. Nothing is shared until an administrator approves it, and each app can be revoked at any time.
* **An AI app never has more rights than you.** It acts as the administrator who approved it, through the same WordPress capability checks.
* **You choose the tools.** MCPMate → Tools switches each tool on or off. Anything that writes or reaches further than reading starts off. Each app can have Full, Read-only or a custom set of tools.
* **Drafts, not publish.** Content the AI writes is saved as a draft or pending review. Removal goes to the trash, never a permanent delete.
* **Risky steps wait for you.** With MCPMate Pro, destructive commands, theme publishing and rollback, and destructive abilities are held on the Approvals page until an administrator approves them.
* **Everything is recorded.** The audit log lists every call with its app, tool and result. Change history keeps a before and after copy of each post, option, term, user or comment the AI changed, with a diff.
* **Pause AI access** stops every AI app at once without revoking anything. **Read mode** keeps every app connected but lets them only read, whatever their access level.
* **A call limit per app.** Each AI app gets a set number of calls per minute (Settings), so a runaway agent cannot flood the site.

What AI apps can do, once you switch it on:

* **Understand the site:** WordPress, PHP and plugin versions, the active theme, page builders and SEO plugins, and the rendered HTML of public pages.
* **Check Gutenberg content:** list the block types the site has and check block markup before anything saves it.
* **Read the database without SQL:** list tables, describe them and read rows, with passwords and secrets never returned.
* **Run read-only WP-CLI-style commands:** plugins, themes, posts and their custom fields, post types, taxonomies and terms, menus, users and roles, comments, options and transients, cron events, rewrite rules and core checksums (for example `plugin list`, `term list` or `rewrite list --match`). They run as reviewed PHP handlers, never in a shell, and each one must be enabled by name. Secret-looking values are hidden.
* **Use other plugins' abilities:** list the operations other plugins register with the WordPress Abilities API (WordPress 6.9 or later), and run the read-only ones you enable by name.
* **Read the audit log and check on approvals.**
* **Skills:** a tested SEO audit playbook (read-only), and conventions you save so later conversations follow them. Skills are also offered as MCP prompts to apps that support them, and you can read, edit, trash and restore saved skills on **Tools → Skills**.
* **Stock photos:** optional Unsplash search with your own key.

For administrators:

* **`wp mcpmate` commands:** check the status, pause and resume AI access, turn read mode on or off, list and revoke AI apps, switch tools and read the audit log from the server's command line. Every command is audited.
* **Review data before deleting:** before you delete the plugin, **Settings → Advanced** (or **Review data before deleting** on the Plugins screen) shows every table, option and folder that would be removed, and lets you download the audit log and change history as a JSON file.

MCPMate Pro, a separate paid add-on, adds the tools that change the site: posts, pages and their blocks as drafts, media uploads, theme drafts with preview, approval and rollback, saved design systems, maintenance and destructive WP-CLI-style commands (such as `cache flush`, `option update` or `search-replace`), other plugins' abilities that write or delete, and integrations with popular page builders, themes, SEO, form and custom field plugins and WooCommerce, along with tested playbooks for these jobs. Everything in this plugin works without it.

MCPMate needs HTTPS (a local development site may use plain HTTP) and pretty permalinks. The Dashboard's Site checks card can help diagnose these and common hosting issues.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open **MCPMate** in the admin sidebar.

== Connecting ==

After activation, a setup wizard opens once (it can be resumed from the Dashboard notice or **Settings → Run setup again**). It lets you choose what the AI may do, shows how to add the site to your AI app, and waits for the app's first request. Run diagnostics any time from the Dashboard's Site checks card. You can also connect by hand:

1. Open **MCPMate → Connections** and copy your site's MCP URL (`https://your-site/wp-json/mcpmate/mcp`). The plugin is the MCP server and its own OAuth server: nothing runs anywhere else.
2. Add the MCP URL to your AI app (Claude, ChatGPT, Cursor, Claude Code, or one of the other apps **Connect an AI app** lists).
3. The AI app opens your wp-admin to ask for approval. It acts as the administrator who approves it, and nothing is shared until you approve.
4. **MCPMate → Connections** lists every approved app with a **Revoke** button. A revoke takes effect on your site at once.

No password is copied anywhere. Application Passwords and HTTP Basic auth are not accepted by this plugin's REST routes.

== External services ==

AI apps connect to the site's own MCP URL (`/wp-json/mcpmate/mcp`). The plugin does not use a MCPMate server, and sends nothing anywhere on its own. It makes outbound requests only in these cases:

* Media uploads (with MCPMate Pro): when an AI app you approved asks to upload an image or PDF from a URL (`upload_media`), the plugin downloads that one file from the address it gave (public addresses only).
* AI app sign-in: when an AI app identifies itself with a client metadata document URL, the plugin fetches that small JSON document from the app's own address to show its name on the approval screen.
* This site itself: the setup wizard requests the site's own address (and its tunnel, when `MCPMATE_PUBLIC_URL` is set) to test that the REST API answers, and `get_page_html` requests one of the site's own pages.

Stock photos (optional): when an administrator adds their own Unsplash access key under MCPMate → Tools → Integrations, the `search_stock_photos` and `upload_media` tools send the search words, page and photo IDs to the Unsplash API (https://api.unsplash.com) with that key, download the chosen photo from images.unsplash.com, and tell Unsplash when a photo was used, as Unsplash's API guidelines require. Nothing is sent to Unsplash without a key. Unsplash's terms: https://unsplash.com/api-terms and privacy policy: https://unsplash.com/privacy.

== Frequently Asked Questions ==

= Which AI apps work with MCPMate? =

Any app that can connect to a remote MCP server with OAuth sign-in, such as Claude (claude.ai and the desktop app), Claude Code, ChatGPT and Cursor. **Connections → Connect an AI app** also has steps for Codex CLI, Gemini CLI, VS Code with Copilot, Windsurf, Antigravity, Zed, Cline, Continue and Amp.

= Do I need an account or an API key? =

No. There is no MCPMate account or service. Your site signs AI apps in itself. You only need an account with the AI app you use. An Unsplash key is optional and only needed for stock photo search.

= Can the AI publish or delete my content? =

Not on its own. This plugin's tools only read. With MCPMate Pro, posts and pages the AI creates or edits are saved as drafts or pending review, and removal goes to the trash. Publishing a draft theme, rolling a theme back and destructive commands wait on the Approvals page until an administrator approves them. Abilities from other plugins run that plugin's own code, so read each one's description before you enable it.

= Who can approve an AI app? =

An administrator (a user with the `manage_options` capability), on the approval screen in wp-admin. The app then acts as that administrator. There is no "remember this app": every new connection is approved by hand.

= How do I stop an AI app? =

**Connections → Revoke** removes one app at once, along with all its sign-in tokens. **Pause AI access** on the Dashboard stops every app until you resume, without revoking anything, and **Turn on read mode** lets every app keep reading but change nothing. Both are also available from the command line (`wp mcpmate pause`, `wp mcpmate read-mode on`).

= What does MCPMate store, and where? =

Everything stays in your site's own database: approved apps and their hashed tokens, the audit log, change history snapshots and pending approvals. Old entries are removed automatically after the periods set under **Settings**. Tokens and secrets are stored only as hashes and never written to the log.

= What happens when I delete the plugin? =

By default its tables and options are removed. Turn off **Settings → Remove all data on uninstall** to keep them. **Settings → Advanced → Review data before deleting** lists what would go and lets you download the audit log and change history first.

= A site check needs attention. What now? =

Each check says what is wrong and how to fix it. The most common causes are plain permalinks, a host or security plugin that strips the `Authorization` header, and a cache in front of `/.well-known/` or the MCP URL. Because the checks are requests from your site to itself, a firewall may treat AI apps differently: allow the addresses the check lists.

== Source code ==

The admin app is built with React. Its source ships with the plugin in the `frontend` folder, with `package.json`, `vite.config.ts` and the other build files. Build it with `pnpm install && pnpm build`, which writes the `assets` folder.

The source code is also on GitHub: https://github.com/NiloyDas789/mcpmate-public-repo

== Changelog ==

= Unreleased =
* Read mode: every AI app may only read, without disconnecting anyone (Dashboard → AI access).
* `wp mcpmate` WP-CLI commands for status, pause and resume, read mode, AI apps, tools and the audit log.
* Review data before deleting: see what an uninstall removes and download the audit log and change history.
* More read-only commands: terms, menus and menu items, post types, taxonomies, rewrite rules, cron schedules, transients, users, roles, comments, plugin and theme details, and network sites.
* Skills as MCP prompts, and Tools → Skills to view, edit, trash and restore saved skills.
* Connection steps for Codex CLI, Gemini CLI, VS Code with Copilot, Windsurf, Antigravity, Zed, Cline, Continue and Amp, and app logos on Connections.

= 1.0.0 =
* Initial release: MCP server and OAuth sign-in in the plugin, per-app approval and access levels, tool switches, approvals, audit log, change history, site and page reads, block checks, database reads, read-only WP-CLI-style commands and abilities, skills and stock photo search.
