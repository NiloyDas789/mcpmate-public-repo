# Admin UI

**Status:** spec, 2026-10-26. **Changed in 0.9.0** (docs/mcp-in-plugin.md §9): the site link card is now "Your MCP URL" (the site's own `/wp-json/mcpmate/mcp`, no registration or reset), the status pill has no connection states, and Settings → Advanced has no MCPMate server row (D4, B11's `cloud_url_source` are gone). **Changed in 0.10.0** (mcp-in-plugin.md §10): Connections gains **Registered clients** and **Token lifetimes and client registration** (moved there from Settings → Access and safety, D10's rule of one place per value); Site checks are the hosting checks of `docs/onboarding.md` §5.1. Built so far: the shell, tokens and components (§3, §6, §7) and every page of §5 (Dashboard, Connections, Tools, Activity, Settings); each built page notes where it differs. Anything marked `[B<n>]` needs the backend work listed in §10.
**Goal:** an admin area that a site owner with no developer background can read at a glance. It should answer: is my AI connected, what is it doing, what may it do, and how do I stop it? It must meet WCAG 2.2 AA.
**Read with:** `AGENTS.md` (rules, stack, known gaps), `docs/onboarding.md` (setup wizard, environment checks, client steps data file), `docs/connection-v2.md` (grants, approval, revocation).

---

## 0. Where this spec departs from the brief

Each change below has a reason. Open questions in §12 ask you to confirm the ones that are yours to decide.

| # | Brief | This spec | Why |
| --- | --- | --- | --- |
| D1 | Activity has two tabs | Activity has three tabs: **Audit log**, **Change history**, **Approvals** (with a count) | Approval links sent by AI apps (`?approval=<id>`, a cloud contract) need a home in the navigation. A pending approval is activity that waits for the owner. |
| D2 | Settings: "audit log on/off" | Write calls are **always** logged. The switch becomes **"Also log read calls"** `[B4]` | AGENTS.md says every write is audited, and the setup wizard depends on the first-call row. Turning that off would remove the main safety record. |
| D3 | Settings: "force draft on create" (a toggle) | A fixed row that reads **"Always on"** | Security rule 2: no endpoint may publish. A switch that could turn it off must not exist. |
| D4 | Settings: CLOUD_URL override field | A **read-only** row that shows the value, where it comes from, and the `wp-config.php` line to copy | AGENTS.md: "there is deliberately no admin field for it". An admin field would let anyone holding an admin session point the site at another server. |
| D5 | Minimum capability to approve a connection (as low as `publish_posts`) | Specified, but it ships **locked to Administrators** until security rule 9 is changed and signed off `[B8]` | Rule 9 requires `manage_options` on the approval screen. Lowering it changes the security model on both repos. |
| D6 | Audit log "cleanup button" | **"Remove entries older than 90 days now"**: it runs the retention purge early `[B9]` | The log has no update or delete API, and only `Retention` removes rows. The button keeps that true: nobody picks individual rows to delete. |
| D7 | Change history action "Delete" | "Delete" appears only for **theme draft files** | Security rule 3: everything else is moved to the trash. Theme draft files are the only documented exception. |
| D8 | `@wordpress/components` for accessible primitives | **Not added.** Ant Design 5 stays, wrapped in our own components and tokens | AGENTS.md sets the stack (Ant Design 5), and `onboarding.md` §1.1 made the same call. Ant Design 5.29 (installed) already covers the requirements: Tabs use roving tabindex with arrow keys and RTL (`rc-tabs` 15.7), Table supports `caption`, `th scope` and `aria-sort` (`rc-table` 7.54), and Modal and Drawer trap focus and return it on close. A second design system would add a runtime dependency, a second theme to keep in sync, and older behaviour on WordPress 6.0 when used as the `wp-components` global. |
| D9 | Top bar "tab navigation" | A `<nav>` of **links** with `aria-current="page"` | These items change the page. The ARIA tabs pattern is for panels within one page. Tabs inside a page (Tools sources, Activity) do use roving tabindex and arrow keys. |
| D10 | Onboarding feature toggles in Settings → Access and safety | They become **groups on the Tools page**. Settings shows a summary row with a link | Two places to switch the same thing would contradict each other. The SVG switch becomes an option of the Media group. |
| D11 | "Connect an AI client" | **"Connect an AI app"** | The plugin already says "AI app" everywhere, and so does the status pill in the brief. One term throughout. |
| D12 | Integrations: Elementor, WooCommerce and others | Only integrations **that have tools** are listed. Today that is Elementor. WooCommerce appears in the wireframe as an example and is added once it has tools `[B13]` | A row with nothing to switch on is a dead end for the owner. |
| D13 | Works at 360 px | Designed for **320 CSS px**, which covers 360 px, 200 % zoom (1280 → 640) and 400 % zoom (1280 → 320) | WCAG 1.4.10 Reflow is measured at 320 CSS px. |
| D14 | Tools page with a save bar (rule 8) | The Tools page **saves each change at once**, shows "Saving…", then "Saved." (or the error with **Retry**), and announces it | The owner asked for it (2026-10-26). Each checkbox is one small, reversible change, and the server refuses a tool the moment it is off. Settings and Edit access keep the save bar. |

---

## 1. Audit: what exists today

### 1.1 Screens, endpoints and their new home

All screens need `manage_options` (`Menu.php`). Today there is one top-level menu item plus six submenu items that point at hash routes.

| Today | Route | Data and endpoints | New home |
| --- | --- | --- | --- |
| Dashboard | `#/` | `SERVER_VARIABLES.connection` (status, `mcp_url`), `SERVER_VARIABLES.onboarding` (setup card), `version` | **Dashboard**: MCP URL card, setup notice. **Top bar**: status pill and version. |
| Connection | `#/connection` | admin-ajax `connection/status`, `connection/register`, `connection/register-status` (`services/registration.ts`); `McpUrl`; per-client steps from `data/aiClients.ts` | **Connections**: the "This site's link to MCPMate" card. Client steps move to the **Connect an AI app** modal. |
| Connected AI apps | `#/connected-apps` | admin-ajax `grants/list` (`{grants, limit}`), `grants/revoke`, `grants/revoke-all` | **Connections**: the AI apps table, the Revoke dialogs, and "Revoked in the last 30 days". |
| Approvals | `#/approvals`, `#/approvals/:id` | admin-ajax `approvals` (status filter, page), `approvals/show`, `approvals/approve`, `approvals/reject` | **Activity → Approvals** (D1). The detail screen moves to `#/activity/approvals/:id`. |
| Audit Log | `#/audit-log` | REST `GET /audit-log` (cookie + `X-WP-Nonce`; `page`, `per_page`, `tool`, `result`, `grant_id`, `from`, `to`); filters read from the hash query | **Activity → Audit log**. |
| Settings | `#/settings` | Seven feature cards: admin-ajax `core-proxy`, `cli-commands`, `abilities`, `db-inspect`, `media-upload` (+ `svg`), `theme-drafts`, `elementor` (each with `/update`); `onboarding/update` for "Run setup again" | **Tools** (each card becomes a group, D10). **Settings → Advanced**: "Run setup again". |
| Setup wizard | `#/setup` | admin-ajax `onboarding/state`, `onboarding/update`, plus the switch, registration and grant routes | Stays **outside the navigation** (minimal shell). Site checks are available from the Dashboard. |
| Connect (approve an AI app) | `#/connect` (from `?connect=<token>`) | admin-ajax `connection/approve {token, read_only}`, `connection/reject` | Stays **outside the navigation** (minimal shell, §3.6). |
| Not found | `*` | none | Unchanged, inside the new shell. |

### 1.2 Tools: where they come from

MCP tools are defined by the MCP server (`mcp-server/src/guard/policy.ts`, `TOOLS`), not by this plugin. Each tool calls one or more plugin routes. The plugin's switches today are per feature, not per tool. The Tools page (§5.3) needs a plugin-side catalog of the tools that reach this site `[B1]`:

| Group (source) | Tool | Label in the UI | Kind | Plugin route | Switch today |
| --- | --- | --- | --- | --- | --- |
| Site overview (Core) | `site_info` | Get a summary of this site | Read | `GET /site-info` | none (always on) |
| | `get_page_html` | Read a page's HTML | Read | `GET /page-html` | none |
| | `get_audit_log` | Read the audit log | Read | `GET /audit-log` | none |
| | `check_approval` | Check on an approval request | Read | `POST /approvals/{id}/status` | none, **stays on** (turning it off would strand pending requests) |
| Posts and pages (Core) | *(no tool yet; server open question Q8)* | Posts and pages | Write | `POST /proxy` | `core-proxy` |
| Blocks (Core) | `list_block_types` | List block types | Read | `GET /blocks/types` | none |
| | `validate_blocks` | Check block markup | Read | `POST /blocks/validate` | none |
| Media (Core) | `upload_media` | Upload an image or PDF | Write | `POST /media` | `media-upload`; option: SVG files (`svg`) |
| Theme drafts (Core) | `create_draft_theme` | Create a draft copy of the theme | Write | `POST /theme-draft` | `theme-drafts` |
| | `list_theme_files` | List draft theme files | Read | `GET /theme-draft/{id}/files` | |
| | `read_theme_file` | Read a draft theme file | Read | `GET /theme-draft/{id}/file` | |
| | `write_theme_file` | Write a draft theme file | Write | `PUT /theme-draft/{id}/file` | |
| | `edit_theme_file` | Edit part of a draft theme file | Write | `POST /theme-draft/{id}/file/replace` | |
| | `get_preview_url` | Make a preview link | Read | `POST /theme-draft/{id}/preview` | |
| | `publish_draft_theme` | Publish a draft theme | Write, asks you first | `POST /theme-draft/publish` | |
| | `rollback_theme` | Undo a theme publish | Write, asks you first | `POST /theme-draft/rollback` | |
| Database (Core) | `query_db` | Look up database rows | Read | `GET /db/…` | `db-inspect` |
| WP-CLI commands (Core) | `run_wp_cli` | One item per command (21 today, `CommandRegistry`) | Read / Write / Write, asks you first | `POST /cli` | `cli-commands` (enable list) |
| Elementor (Integrations) | `elementor_get` | Read an Elementor page | Read | `GET /elementor/{id}` | `elementor` |
| | `elementor_widgets` | List Elementor widgets | Read | `GET /elementor/widgets` | |
| | `elementor_save` | Save an Elementor page as a draft | Write | `POST /elementor/{id}`, `POST /elementor` | |
| Abilities | `list_abilities` | List abilities | Read | `GET /abilities` | none |
| | `run_ability` | One item per ability (risk from `AbilityRisk`) | Read / Write / Write, asks you first | `POST /abilities/run` | `abilities` (enable list) |

These tools never reach the site, so they are **not** listed: `ping`, `usage`, `connect_site`, `list_sites`, `list_skills`, `get_skill`, `save_skill`, `search_stock_photos`.

The 21 WP-CLI commands: `core version`, `core verify-checksums`, `plugin list`, `theme list`, `post list`, `post get`, `post meta get`, `option get`, `option list`, `user list`, `cron event list` (Read); `cache flush`, `rewrite flush`, `transient delete`, `option update`, `post meta update` (Write); `plugin activate`, `plugin deactivate`, `plugin update`, `theme activate`, `search-replace` (Write, asks you first unless it is a dry run).

### 1.3 Findings

1. **Tailwind utilities are global.** `tailwind.config.mjs` has no `important` selector and no prefix, so `.hidden`, `.block`, `.fixed` and others apply to the whole admin page and collide with core classes (`.hidden` exists in wp-admin). Fix: `important: '#mcpmate-root'`.
2. **Ant Design portals render into `document.body`**, outside the app root. Modals, drawers, dropdowns and toasts therefore miss any CSS variable or rule scoped to the root. Fix: render every portal into `#mcpmate-portal`, a sibling of the app inside the root wrapper (`ConfigProvider getPopupContainer`, `Modal`/`Drawer` `getContainer`).
3. `global.css` hides `#wpfooter` with a global rule. It is harmless because the file loads only on our page, but scope it to `body.toplevel_page_mcpmate` anyway.
4. **Every time is shown in UTC** (`formatUtc`). Owners think in their site's timezone `[B11]`.
5. **Reads are not logged**, so tool calls (24h), success rate and "recent activity" cannot be computed today `[B4]`.
6. The audit log has **no client, duration or result** data. The client can be joined from `grant_id` `[B5]`; duration and result are not stored `[B4]` `[B6]`. Its `tool` column holds the plugin's own name (`cli`, `theme_draft`), not the MCP tool (`run_wp_cli`) `[B4]`.
7. **Feedback relies on toasts** (antd `message`). Toasts vanish and are not reliably announced. The new pages show an inline status message and also use the live region (§9).
8. **Revoke uses `Popconfirm`**, a small popover that does not trap focus and gives little room to explain. Replace it with `ConfirmDialog`.
9. **No logo.** The menu uses `dashicons-admin-generic` `[B15]`.
10. **Tables have no caption**, and the actions column has an empty header, which leaves its `th` without `scope`. Give every column a header, visually hidden where needed.
11. `$isDarkTheme` exists but is never set. Version 1 is light only (open question 11).
12. ~~Inter is loaded from `fonts.googleapis.com`, which sends a request from wp-admin to Google (open question 12).~~ Done: Inter is bundled (`@fontsource-variable/inter`, imported in `main.tsx`), so wp-admin makes no request to Google.

---

## 2. Sitemap

```
WordPress admin menu
└── MCPMate  (one item, no submenu)  admin.php?page=mcpmate
    │
    ├── #/                         Dashboard
    ├── #/connections              Connections
    │     ├── modal: Connect an AI app        (?connect-app=claude|chatgpt|cursor|claude-code)
    │     ├── drawer: Access for <app>        [B2]
    │     └── dialogs: Revoke, Revoke all, Revoke a registered client
    ├── #/tools                    Tools → Core           [B1]
    │     ├── #/tools/integrations   Integrations
    │     ├── #/tools/abilities      Abilities
    │     ├── #/tools/skills         Skills
    │     │     └── drawer: Skill      (?skill=<id> or ?playbook=<name>)
    │     └── #/tools/memory         Memory (MCPMate Pro)
    │           └── drawer: Memory     (?memory=<id>)
    ├── #/activity                 Activity → Audit log
    │     ├── drawer: Entry details           (?entry=<id>)  [B5]
    │     ├── #/activity/changes     Change history         [B7]
    │     │     └── drawer: Before and after  (?change=<id>)
    │     ├── #/activity/approvals   Approvals
    │     └── #/activity/approvals/:id  Approval request
    └── #/settings                 Settings (Access and safety, Logging and history, Advanced)

Outside the navigation (minimal shell)
    ├── #/setup                    Setup wizard        (?setup=1)
    ├── #/connect                  Approve an AI app   (?connect=<token>)
    └── *                          Page not found
```

**Redirects** (old links stay valid, including the setup wizard's links, bookmarks and `approval_url`):

| Old | New |
| --- | --- |
| `#/connection`, `#/connected-apps` | `#/connections` |
| `#/audit-log?<query>` | `#/activity?<query>` (query kept) |
| `#/approvals`, `#/approvals/:id` | `#/activity/approvals`, `#/activity/approvals/:id` |
| `?approval=<id>` (`utils/approvalLink.ts`) | `#/activity/approvals/<id>` |
| `#/settings` cards | `#/tools` (Settings keeps its own route for the new sections) |

Filters, tabs, and open drawers live in the hash query, so Back, Reload and shared links all work. On load, an invalid query value is dropped, as `AuditLog/filters.ts` does today.

---

## 3. App shell

### 3.1 Wireframe: desktop (≥ 960 px)

```
+------------------------------------------------------------------------------------------------+
| [Skip to MCPMate content]   (first focusable element; visible only when focused)              |
| (o) MCPMate   Dashboard  Connections  Tools  Activity  Settings  (*) 3 AI apps connected  v1.0.0 |
|                    =========                                        [B11]                      |
+------------------------------------------------------------------------------------------------+
| (!) AI access is paused. AI apps get an error until you resume it.  [Resume AI access]   [B3]  |
| (i) 2 requests are waiting for your approval.                       [Review requests]          |
+------------------------------------------------------------------------------------------------+
| <main id="mcpmate-main" tabindex="-1">                                                        |
|   h1  Page title                                                                               |
|   one-line intro in plain words                                                                |
|   ...page content...                                                                           |
| </main>                                                                                        |
+------------------------------------------------------------------------------------------------+
| Documentation   Support   Rate the plugin   Share feedback                             [B14]   |
+------------------------------------------------------------------------------------------------+
```

### 3.2 Wireframe: narrow (< 600 px, also 200 % zoom on a small laptop)

```
+--------------------------------------+
| (o) MCPMate          [Menu  v]  |
| (*) 3 AI apps connected      v1.0.0  |
+--------------------------------------+
| (open)                               |
|  Dashboard          <- current       |
|  Connections                         |
|  Tools                               |
|  Activity                            |
|  Settings                            |
+--------------------------------------+
| h1 Dashboard                         |
| ...                                  |
```

From 600 to 959 px the navigation drops to a second row under the logo, with the status pill and version on the right. Below 600 px it collapses behind **Menu**, a disclosure button (`aria-expanded`, `aria-controls`), not a modal. Nothing in the shell scrolls sideways.

### 3.3 Parts

| Part | Spec | Data |
| --- | --- | --- |
| Skip link | "Skip to MCPMate content" goes to `#mcpmate-main`, which has `tabindex="-1"` and receives focus. WordPress's own "Skip to main content" stays in place. | none |
| Logo + name | 24 px SVG mark (§6.8) plus the text "MCPMate" as real text, not an image, so it zooms and can be translated. Links to `#/`. | `[B15]` |
| Navigation | `<nav aria-label="MCPMate">`, a list of links (Chat, MCPMate Pro, after Dashboard only while it is set up: `SERVER_VARIABLES.chat.ready`), `aria-current="page"` on the current one. Shows a 3 px underline plus bold text, so it does not rely on colour alone. Tab and Shift+Tab move between links (D9). | static |
| Status pill | A `StatusPill` that is also a link to `#/connections`. States are in the table below. | `[B11]` (`grants_live`, `access.paused`, `read_mode.on`) |
| Version | "v1.0.0" in secondary text. The accessible name is "Version 1.0.0". | `SERVER_VARIABLES.version` |
| Global notices | Stack under the top bar, at most two. Order: paused, read mode (P21; off the Dashboard it links there), approvals waiting, setup not finished. They come from our app, not `admin_notices`, because `Layout::removeAdminNotices()` already clears core notices on our page. | `[B3]`, `[B11]` |
| Footer | `<footer>` with four links in this fixed order: Documentation, Support, Rate the plugin, Share feedback. External links get "(opens in a new tab)" as visually hidden text plus an icon. A link whose URL is not configured is **hidden**, never shown as a dead link. | `[B14]` |

**Status pill states** (each one is a dot plus text, never colour alone):

| Condition | Dot | Text |
| --- | --- | --- |
| `access.paused` | warning (pause glyph) | AI access paused |
| `read_mode.on` (P21) | warning | Read only |
| 0 live grants | neutral (hollow) | No AI apps connected |
| n live grants | success | `_n( '%d AI app connected', '%d AI apps connected', n )` |

### 3.4 Full-width app in wp-admin

- One top-level menu item, "MCPMate", with **no submenu** `[B12]`. The icon is our mark as a monochrome SVG data URI, so WordPress's `svg-painter` tints it for each admin colour scheme `[B15]`.
- The app fills `#wpbody-content`. The only CSS outside `#mcpmate-root` is `body.toplevel_page_mcpmate #wpcontent { padding-inline-start: 0 }` and the `#wpfooter` rule (§1.3, item 3).
- The page background (`--el-bg-page`) covers the full content area. Content is capped at 1440 px and centred, with a 24 px gutter (16 px below 600 px).
- The top bar is `position: sticky` below the WordPress admin bar (`top: var(--wp-admin--admin-bar--height, 32px)`). `html { scroll-padding-top }` equals the admin bar plus the top bar, so a focused element is never hidden behind it (WCAG 2.4.11).

### 3.5 Scoping

- Tokens are CSS custom properties on `#mcpmate-root` and `#mcpmate-portal` (§6). The Ant Design `ConfigProvider` tokens are generated from the same values (`config/theme.ts`).
- Tailwind runs with `important: '#mcpmate-root'`. Preflight stays off.
- Every portal renders into `#mcpmate-portal` (§1.3, item 2).
- No rule targets `body`, `a`, `h1`–`h6` or form elements without the root prefix.

### 3.6 Minimal shell (Setup, Connect)

The logo and name only: no navigation, status pill or footer. Setup adds **Exit setup** on the right, which works as today's "Skip setup". The approval screen (`#/connect`) shows nothing that could pull attention away from the decision (security rule 9).

---

## 4. Rules every page follows

1. **Words.** Plain language, sentence case, second person ("your site"), no jargon without an explanation next to it. Use the glossary in §8.
2. **Destructive buttons say what happens:** "Revoke access for Claude", not "OK" or "Confirm". The cancel button says what is kept: "Keep access".
3. **Status is a dot plus text**, always. Colour only reinforces it.
4. **Every async view has four states:** a `Skeleton` while loading; an inline `Notice` (error) with **Try again**; an `EmptyState` with one next action; and the content itself. When content changes after a user action (filter, search, save, page change), the result is announced in the live region ("24 entries match.").
5. **Monospace** only for tool names, command names, IDs, request IDs, URLs, hosts, file paths and capability names. Mono text sits inside `<bdi dir="ltr">`, so it reads correctly in RTL.
6. **Times.** Lists show relative time ("5 min ago") in a `<time datetime>` element. The drawer shows the absolute date and time in the site's timezone with its name ("26 Oct 2026, 10:42 (Europe/Dhaka)") `[B11]`. Times use `Intl.RelativeTimeFormat` and `Intl.DateTimeFormat` with `SERVER_VARIABLES.lang`.
7. **Numbers** use `Intl.NumberFormat`. Counts use `_n()`. No string concatenation: `sprintf()` with a translators comment.
8. **Saving.** Forms (Settings sections, Edit access) save with an explicit button; the Tools page saves each change at once (D14). While anything is unsaved, a sticky save bar shows how many changes there are ("3 unsaved changes") with **Save changes** and **Discard**. Leaving the page with unsaved changes opens a confirm dialog (§5.6). After saving, the bar says "Saved." for 5 seconds, and so does the live region.

---

## 5. Pages

Legend: `[B<n>]` marks backend work (§10). Anything not marked already exists.

### 5.1 Dashboard (`#/`)

**h1:** Dashboard
**Intro:** See what your AI apps are doing on this site, and stop them at any time.

#### Wireframe: ≥ 1280 px

```
+------------------------------------------------------------------------------------------------+
| (i) Finish setting up. You're on step 2 of 4.     (until setup is completed)  [Resume setup]  |
+------------------------------------------------------------------------------------------------+
| AI ACCESS                                                   | YOUR MCP URL                     |
|                                                             |                                  |
|  3   AI apps connected                                      | Paste this into your AI app to   |
|      Last used 5 min ago by Claude                  [B4]    | connect it. It isn't a password: |
|      (*) Active                                     [B3]    | every app still needs your       |
|                                                             | approval here.                   |
|  [Manage connections]   [Pause AI access]           [B3]    | [https://example.com/wp-json/…]  |
|                                                             | [Copy]                           |
+-------------------------------------------------------------+----------------------------------+
| TOOL CALLS            [B4] | SUCCESS RATE          [B4] | TOOLS TURNED ON              [B1]  |
| 42                         | 96%                        | 18 of 31                           |
| Last 24 hours              | Last 24 hours · 2 failed   | Manage tools                       |
| 30 reads, 12 changes       | View failed calls          |                                    |
+----------------------------+----------------------------+------------------------------------+
| In the last 7 days, AI apps made 14 changes: 3 created, 9 updated, 2 moved to trash.           |
| View change history                                                                    [B7]    |
+------------------------------------------------------------------------------------------------+
| RECENT ACTIVITY                                             | SITE CHECKS                      |
|                                                             | 7 of 9 passed                    |
| (*) Succeeded  site_info         Claude · admin   5 min ago | (!) 1 needs attention            |
| (*) Succeeded  elementor_save    Claude · admin  12 min ago | Last checked 2 days ago   [B10]  |
| (x) Failed     upload_media      ChatGPT · editor 1 h ago   | [Run checks again]               |
| (~) Waiting    run_wp_cli        Claude · admin   2 h ago   | [Show all checks (9)  v]         |
| (*) Succeeded  query_db          Cursor · admin   3 h ago   +----------------------------------+
|                                                    [B4][B5] | HELP                             |
| View all activity                                           | Read the documentation   [B14]  |
|                                                             | Run setup again                  |
|                                                             | Get support              [B14]   |
|                                                             | Share feedback           [B14]   |
+-------------------------------------------------------------+----------------------------------+
```

#### Wireframe: < 600 px (single column, in this order)

```
+--------------------------------------+
| h1 Dashboard                         |
| AI ACCESS                            |
| 3  AI apps connected                 |
| (*) Active                           |
| [Manage connections]                 |
| [Pause AI access]                    |
+--------------------------------------+
| YOUR MCP URL                         |
| [https://example.com/wp-json/ea…]    |  <- wraps, never cut off
| [Copy]                               |
+--------------------------------------+
| TOOL CALLS        42                 |
| Last 24 hours · 30 reads, 12 changes |
+--------------------------------------+
| SUCCESS RATE      96%                |
+--------------------------------------+
| TOOLS TURNED ON   18 of 31           |
+--------------------------------------+
| In the last 7 days … View change …   |
| RECENT ACTIVITY (stacked rows)       |
| SITE CHECKS                          |
| HELP                                 |
+--------------------------------------+
```

#### Elements

| Element | Behaviour | Data |
| --- | --- | --- |
| Setup notice | Today's `SetupCard`, restyled as a `Notice` (info). Hidden once setup is completed. | `SERVER_VARIABLES.onboarding` |
| **AI access** card | The big number is the live grant count. "Last used … by …" shows the most recent `last_used_at` among grants. The state line reads "(*) Active" or "(pause) Paused since 10:42 by Niloy". **Manage connections** links to `#/connections`. **Pause AI access** (danger, secondary) opens the dialog below; while paused, the button becomes **Resume AI access** (primary), which needs no confirmation and announces "AI access resumed." Next to it, **Turn on read mode** (secondary, P21) opens a confirmation ("Turn on read mode?"); while on, the state line adds "Read only since 10:42 by Niloy" and the button becomes **Turn off read mode** (no confirmation, announces "Read mode is off."). | grants: `grants/list`; pause: `[B3]`; read mode: `read-mode`, `read-mode/on`, `read-mode/off` |
| **Your MCP URL** card | `CopyField` (today's `McpUrl`) with the site's own MCP URL (`…/wp-json/mcpmate/mcp`, under the tunnel's address when `MCPMATE_PUBLIC_URL` is set). There is nothing to connect first. | `SERVER_VARIABLES.connection` |
| **Tool calls** tile | Signed calls in the last 24 hours that reached a tool. The second line splits reads and changes. | `[B4]` |
| **Success rate** tile | `ok / (ok + error)`, rounded to a whole percent. Pending approvals are left out. With no calls it shows "—" (accessible name "No calls") and "No calls in the last 24 hours". **View failed calls** goes to `#/activity?result=error&period=24h`. A note under the tile: "Counts calls that reached a tool." (known gap 5). | `[B4]` |
| **Tools turned on** tile | "18 of 31", counted over every available tool and item (CLI commands and abilities count one each). **Manage tools** goes to `#/tools`. | `[B1]` |
| This week line | One sentence plus a link. With nothing to report: "No changes by AI apps in the last 7 days." Until `[B7]` exists, it counts write rows in the audit log ("In the last 7 days, AI apps made 14 changes.") and links to **View activity**. | `[B7]`, fallback `[B4]` |
| **Recent activity** | The last 5 calls as a list (`<ul>`). Each row is a link to `#/activity?entry=<id>` and shows: a status dot with its text, the tool name (mono), "App as user", and the relative time. **View all activity** goes to `#/activity`. | `[B4]` `[B5]` |
| **Site checks** | The hosting checks (`onboarding/checks`; originally documented for setup, now run from the Dashboard; the ten rows of `docs/onboarding.md` §5.1: HTTPS, permalinks, MCP URL reachable, Authorization header, `/.well-known`, caching, PHP time limit, versions, administrator, where the site runs). The summary reads "X of Y passed", where Y counts checks whose status is pass, warn or fail; checks with status `info` or `later` are listed but not counted. A status line follows: "(!) 1 needs attention" or "(*) Everything looks good". **Run checks again** re-runs the checks (≤ 10 s); the button shows "Checking…", the list gets `aria-busy`, and the summary is announced when done. **Show all checks (10)** is a disclosure that reveals the check rows (title, status word, message, "How to fix": the fix sentence, then its lines in `CopyField`s, multi-line ones as a text area; a passed row can carry such lines as a note). It runs automatically only the first time; after that it shows the stored result `[B10]`. | `onboarding/checks`; stored result `[B10]` |
| **Help** card | Links only, in the same order as the footer, so help is in a predictable place (WCAG 3.2.6). "Run setup again" calls the existing `onboarding/update` mutation. | `[B14]` |

**Built (2026-10-26), where it differs:** Site checks run when the owner selects **Run checks** and are kept for the visit, not stored (`[B10]` is not built), so the card opens on "Not checked yet." The week line gives the total from `GET /stats` (`changes_week` counts by object type, not by created/updated/trash) and links to **View change history**; with change history off it says so and links to Settings. **View failed calls** goes to `#/activity?result=error` (the audit log's default period, the last 7 days, applies). Recent activity reads the newest 50 audit rows and lists the signed calls among them (no `auth` filter on `GET /audit-log`); each row links to `#/activity?entry=<id>`, which opens that entry's drawer. `GET /audit-log` items now carry `kind`, `mcp_tool` and `duration_ms`.

**Redesigned (2026-11-15), where it differs from the wireframes:** AI access is one full-width band: its title, then the state line at the title row's inline end (the band takes the warning tint while paused), the apps figure on the start side and **Manage connections** with Pause or Resume at the end. The three figures share one card as a strip (columns from 600 px; below that each figure is a row, its number at the end), with the week line along the strip's foot. Below, Recent activity is a ledger (status, tool and "Claude as admin", time; **View all activity** in its title row) beside Your MCP URL, Site checks and Help from 960 px. Card titles and figure labels are sentence case. The meta lines read "30 reads, 12 changes in the last 24 hours" and "2 failed in the last 24 hours" (no middle dots). Styles: `frontend/src/resource/styles/dashboard.css`.

**Pause dialog** (`ConfirmDialog`, danger):

> **Pause AI access?**
> Every connected AI app gets an error until you resume. Nothing is revoked, and you can resume at any time. Requests already waiting for your approval stay in the list.
> [Pause AI access] [Cancel]

After pausing: the global paused notice appears, the pill changes, and the live region says "AI access paused."

**States**

| View | Loading | Error | Empty |
| --- | --- | --- | --- |
| AI access card | Skeleton: number and two lines | "Couldn't load your AI apps." [Try again] | "No AI apps connected yet." + **Connect an AI app** (opens the modal on `#/connections`) |
| Stat tiles | Skeleton: number block | "Couldn't load the numbers." [Try again] (one notice for the row) | Numbers show 0 or "—", as described above |
| Recent activity | 5 skeleton rows | "Couldn't load recent activity." [Try again] | With no grants: "No activity yet. When an AI app uses this site, its calls show up here." + **Connect an AI app**. With grants: "No activity yet. Try asking your AI app: "Give me a health summary of my WordPress site."" + **Copy prompt** |
| Site checks | Rows with the status word "Checking…" (as in setup) | "We couldn't run the checks." [Try again] | "Not checked yet." + **Run checks** |

---

### 5.2 Connections (`#/connections`)

**h1:** Connections
**Intro:** Your site's MCP URL, the AI apps you've approved, and how they sign in. Each app acts as the WordPress user who approved it.

#### Wireframe: ≥ 960 px

```
+------------------------------------------------------------------------------------------------+
| YOUR MCP URL                                                                                   |
| Add this address to your AI app. The app connects to this site directly, and you approve it    |
| here.                                                                                          |
| MCP URL  [https://example.com/wp-json/mcpmate/mcp            ] [Copy]                     |
| [Show technical details  v]   (OAuth issuer, whether apps may register themselves)             |
+------------------------------------------------------------------------------------------------+
| AI apps                                                       4 of 25 apps  [Connect an AI app]|
| +--------------------------------------------------------------------------------------------+ |
| | caption: AI apps that can use this site, sorted by Last used, newest first                 | |
| | AI app            | Access            | Approved   | Last used   ^ |                       | |
| |-------------------+-------------------+------------+---------------+-----------------------| |
| | Claude            | Full access       | 12 Oct     | 5 min ago     | [Edit access] [Revoke]| |
| |  claude.ai        |                   |            |               |                       | |
| |  acts as admin    |                   |            |               |                       | |
| | Cursor            | Custom, 6 tools   | 25 Oct     | (~) Waiting   | [Edit access] [Revoke]| |
| |  (!) not verified |                   |            |  for first use|                       | |
| +--------------------------------------------------------------------------------------------+ |
| [Revoked in the last 30 days (2)  v]                                                           |
| Revoke all AI apps                                                                             |
| Use this if you think an AI app or account was misused.        [Revoke all 4 apps]             |
+------------------------------------------------------------------------------------------------+
| REGISTERED CLIENTS                                                 12 of 200 self-registered   |
| Each AI app registers with this site once, so it can sign in. Revoking a client signs out      |
| every app it connected and stops it from connecting again until you allow it.                  |
| +--------------------------------------------------------------------------------------------+ |
| | caption: OAuth clients registered with this site, newest first                             | |
| | Client          | Client ID                 | Redirect domain | Registered | Status      |  | |
| |-----------------+---------------------------+-----------------+------------+-------------+--| |
| | Unknown tool    | mcpm_c_3F…               | 127.0.0.1       | 27 Oct     | (!) Never   |[Revoke]
| |  (!) not verif. |                           |                 |            |   approved  |  | |
| | Claude          | https://claude.ai/oauth/… | claude.ai       | 12 Oct     | (*) Connected (1 app) |[Revoke]
| |  verified by    |                           |                 |            |             |  | |
| |  claude.ai      |                           |                 |            |             |  | |
| | Old app         | mcpm_c_9a…               | app.example     | 1 Oct      | (x) Revoked 20 Oct |[Allow again]
| +--------------------------------------------------------------------------------------------+ |
+------------------------------------------------------------------------------------------------+
| TOKEN LIFETIMES AND CLIENT REGISTRATION                                                        |
| [on ] Let AI apps register themselves                                                          |
|       Apps that register themselves show as not verified when you approve them. Apps with a    |
|       published client ID document can always connect.                                        |
| Access tokens last                          [15 minutes  v]                                    |
| An app renews its token after this, without asking you.                                        |
| Approve apps again after                    [30 days     v]                                    |
| Counted from your approval. Applies to apps you approve from now on.                           |
|                                         No unsaved changes.            [Save changes]          |
+------------------------------------------------------------------------------------------------+
```

#### Wireframe: < 720 px container (the tables become stacked cards)

```
+--------------------------------------+
| AI apps         4 of 25 apps         |
| [Connect an AI app]                  |
| Sort by [Last used, newest first v]  |
| +----------------------------------+ |
| | Claude                           | |
| | claude.ai · acts as admin        | |
| | Access      Full access          | |
| | Approved    12 Oct 2026          | |
| | Last used   5 min ago            | |
| | [Edit access]  [Revoke]          | |
| +----------------------------------+ |
| Registered clients                   |
| +----------------------------------+ |
| | Claude · verified by claude.ai   | |
| | Client ID   https://claude.ai/…  | |
| | Redirect domain  claude.ai       | |
| | Registered  12 Oct 2026          | |
| | Status      Connected (1 app)    | |
| | [Revoke]                         | |
| +----------------------------------+ |
+--------------------------------------+
```

#### Elements

| Element | Behaviour | Data |
| --- | --- | --- |
| **Your MCP URL** card | `CopyField` with the MCP URL, the tunnel line when `MCPMATE_PUBLIC_URL` is set, and **Show technical details** (the OAuth issuer, and whether apps may register themselves). Nothing to register or reset. | `connection/status` |
| Count | "4 of 25 apps" (`_n`), counting live grants against `limit`. | `grants/list` |
| **Connect an AI app** | Opens the modal below. Always enabled. | `data/aiClients.ts` |
| AI apps table | The active connections. Columns: **AI app** (name in bold, then the verified host in mono or "(!) not verified" for non-CIMD apps, then "acts as <login>" or "acts as deleted user #12"), **Access**, **Approved**, **Last used** ("(~) Waiting for first use" while a grant is pending), and **Actions** (a header that is visually hidden). Sortable by Approved and Last used, client-side, since there are at most 200 rows. | `grants/list` |
| Access column | "Full access" (`site:write`, all tools that are on), "Read-only" (no `site:write`), "Custom, N tools". | `scopes`, `tools` |
| **Edit access** | Opens the modal below. | `grants/update` |
| **Revoke** | `ConfirmDialog`, below. On success the row moves to "Revoked in the last 30 days" and the live region says "Claude can no longer use this site." | `grants/revoke` |
| Revoked list | A collapsed disclosure. Rows show the app, user and why it was revoked (`revoke_reason`: by a site admin, its user deleted, a used token came back, "Revoked with its registered client"). | `grants/list` |
| **Revoke all N apps** | A danger button, disabled (with its reason as visible text: "No AI apps to revoke.") when no grant is live. Opens the dialog below. | `grants/revoke-all` |
| **Registered clients** | Every OAuth client of the site (`oauth_clients`): the ones that registered themselves (DCR, "not verified") and the ones known by a client ID metadata document ("verified by <host>"). Columns: **Client** (row header), **Client ID** (mono, wraps), **Redirect domain** (each host of its redirect URIs, mono), **Registered**, **Status** (dot + words: "Connected (n apps)", "Not connected", "Never approved" (deleted after a day), "Revoked <date>"), **Actions** (hidden header). Live clients first, newest first. The header shows "n of 200 self-registered" (the DCR cap). | `clients/list` |
| Client **Revoke** | `ConfirmDialog` (danger, opens on **Keep it**), below. Revokes every grant of the client (reason `client_revoked`, with its tokens) and refuses the client at authorize, token and approve. The row stays and its button becomes **Allow again**, which keeps focus; the live region says "Claude is revoked. 1 AI app lost access." The AI apps table is fetched again. | `clients/revoke` |
| Client **Allow again** | No confirmation. Clears the revoke; the old grants stay revoked, so the app is approved again like a new one. Announced: "Claude can connect again. You approve it here when it asks." | `clients/restore` |
| **Token lifetimes and client registration** | One form (`SettingsForm` from `pages/Settings/rows.tsx`) with its own save bar, as Settings' sections (§4 rule 8; Leave without saving applies): **Let AI apps register themselves** (toggle, `open_registration`), **Access tokens last** (5/15/30/60 minutes), **Approve apps again after** (7/30/60/90 days, absolute from the approval). Checked with `pages/Settings/logic.ts` before it sends; a server refusal shows under its control. `?section=sign-in` focuses its heading once loaded (Settings links there). | `preferences`, `preferences/update` |

**Built (2026-10-26), where it differs:** **Edit access** opens a `Modal`, not a drawer, and its state is not in the URL. Custom lists the groups with tools that are on as tri-state checkbox groups (`pages/Tools/ToolPicker`, for the Tools page to reuse), with `check_approval` always included; the tools that are off are named per group under "Off for all apps". Custom needs one tool besides the always-on ones. The revoked list shows why an app was revoked, not who revoked it: grants do not store that. After an AI app's revoke, focus goes to the table's caption, since the row and its button are gone. New apps in the Connect modal are found by grant ID (not there when it opened), not by time. Layout (`resource/styles/connections.css`, 2026-11-15): section titles are sentence-case headings, not small capitals; Your MCP URL and AI apps are the two cards, Registered clients sits on the page without a card (quieter: it is OAuth bookkeeping), and Token lifetimes keeps its settings form; an AI app's name and proven address share a line, "acts as" under them; the revoked list gives the date and the reason on two lines (no dot); narrow tables become lists with hairlines inside their card, not cards in a card.

**Connect an AI app modal** (`Modal`, title "Connect an AI app"):

```
+-----------------------------------------------------------------+
| Connect an AI app                                          [x]  |
|                                                                 |
| 1. Copy your MCP URL                                            |
|    [https://example.com/wp-json/mcpmate/mcp ] [Copy]       |
|                                                                 |
| 2. Add it to your AI app                                        |
|    [ Claude ][ ChatGPT ][ Cursor ][ Claude Code ]   <- Tabs      |
|    1. In Claude, open Customize → Connectors, select + Add ...  |
|    2. Name it, paste the MCP URL and select Continue ...        |
|    3. Approve the request on the page that opens on this site.  |
|    On Team and Enterprise plans, an owner adds the connector... |
|    Steps look different? See Claude's help (opens in a new tab) |
|                                                                 |
| 3. Approve it on this site                                      |
|    (~) Waiting for an approval…   (polls grants/list, 5 s)      |
|    -> (*) Claude is connected. It acts as admin.                |
|                                                                 |
|                                                       [Done]    |
+-----------------------------------------------------------------+
```

- The steps come from `data/aiClients.ts` (shared with setup and Connection). The tab you pick is kept in the hash (`?connect-app=cursor`) and remembered for the session (WCAG 3.3.7).
- Step 3 reuses the setup wizard's step 4 logic: poll `grants/list` for a grant created after the modal opened, pause while the tab is hidden, and listen on the `BroadcastChannel` from `pages/Connect`. When it arrives, the live region says "Claude is connected." and focus stays where it is. Nothing jumps.
- Closing the modal (Esc, ×, Done) returns focus to **Connect an AI app**.

**Edit access drawer** `[B2]` (title: "Access for Claude"):

```
+--------------------------------------------+
| Access for Claude                     [x]  |
| Acts as admin · approved 12 Oct            |
|                                            |
| ( ) Full access                            |
|     Can read and make changes with every   |
|     tool that's turned on.                 |
| ( ) Read-only                              |
|     Can only read. It can't change         |
|     anything.                              |
| (o) Custom                                 |
|     Choose the tools it can use.           |
|     [x] Site overview          4 of 4      |
|     [-] Theme drafts           3 of 8   v  |
|     [ ] Database               0 of 1      |
|     Off for all apps: Media (turn it on    |
|     in Tools)                              |
|                                            |
| Claude may need to reconnect, or start a   |
| new chat, to see the change.               |
|                                            |
|              [Cancel]  [Save access]       |
+--------------------------------------------+
```

- Radio group: Full / Read-only / Custom. Custom shows a `CheckboxGroup` tree of the tools that are **on site-wide**. Tools that are off site-wide are listed below as plain text with a link to Tools, never as checkboxes that look usable.
- Saving writes an audit row. It applies to the app's next request.

**Dialogs**

> **Revoke access for Claude?**
> Claude (acting as admin) loses access to this site right away. To use it again, you'll need to connect it and approve it here. Its past activity stays in the audit log.
> [Revoke access for Claude] [Keep access]

> **Revoke access for all AI apps?**
> All 4 AI apps lose access to this site right away. Each one needs to be connected and approved again. Their past activity stays in the audit log.
> [Revoke all 4 apps] [Keep access]

> **Revoke Claude?** (Registered clients)
> 1 AI app connected through Claude loses access to this site right away.
> Claude can't connect again until you allow it here. Past activity stays in the audit log.
> [Revoke Claude] [Keep it]

**States**

| View | Loading | Error | Empty |
| --- | --- | --- | --- |
| Your MCP URL | Skeleton: 2 lines | "Couldn't load this site's MCP URL." [Try again] | — (there is always one) |
| AI apps table | 3 skeleton rows | "Couldn't load your AI apps." [Try again] | **"No AI apps connected yet."** "Connect Claude, ChatGPT, Cursor or another AI app. You approve each one here before it can do anything." + **Connect an AI app** |
| Registered clients | 3 skeleton rows | "Couldn't load the registered clients." [Try again] | **"No AI apps have registered yet."** "An AI app registers here the first time you add this site's MCP URL to it." + **Connect an AI app** |
| Token lifetimes and client registration | Skeleton: 3 lines | "Couldn't load these settings." [Try again] | — |

---

### 5.3 Tools (`#/tools`, `#/tools/integrations`, `#/tools/abilities`) `[B1]`

**h1:** Tools
**Intro:** Tools are the actions your AI apps can take on this site. Turn off anything you don't want them to use.

#### Wireframe: ≥ 960 px

```
+------------------------------------------------------------------------------------------------+
| (i) When a tool is off, AI apps get an error if they try to use it. Connected AI apps may need |
|     to reconnect, or start a new chat, to see your changes.                                    |
+------------------------------------------------------------------------------------------------+
| [ Core  24/38 ]  [ Integrations  3/3 ]  [ Abilities  0/5 ]          <- Tabs (roving tabindex)  |
|------------------------------------------------------------------------------------------------|
| Show  ( All | Read-only | Write )   Search tools [________________]   Show descriptions [ o ]  |
| 38 tools                                                                                       |
|                                                                                                |
| [x] Site overview                               4 of 4 on · 4 read                    [Hide v] |
|     [x] Get a summary of this site         site_info           [Read]                          |
|     [x] Read a page's HTML                 get_page_html       [Read]                          |
|     [x] Read the audit log                 get_audit_log       [Read]                          |
|     [x] Check on an approval request       check_approval      [Read]  Always on               |
|                                                                                                |
| [-] Theme drafts                                3 of 8 on · 4 read, 4 write           [Hide v] |
|     [x] Create a draft copy of the theme   create_draft_theme  [Write]                         |
|     [x] List draft theme files             list_theme_files    [Read]                          |
|     [x] Read a draft theme file            read_theme_file     [Read]                          |
|     [ ] Write a draft theme file           write_theme_file    [Write]                         |
|     ...                                                                                        |
|     [ ] Publish a draft theme              publish_draft_theme [Write] [Asks you first]        |
|                                                                                                |
| [ ] Media                                       0 of 1 on · 1 write                   [Show >] |
|     Group option: [ ] Allow SVG files (administrators only; files are cleaned first)           |
|                                                                                                |
| [-] WP-CLI commands   run_wp_cli               11 of 21 on · 11 read, 10 write        [Show >] |
|                                                                                                |
| [ ] Database                                    0 of 1 on · 1 read                    [Show >] |
|                                                                                                |
| [ ] Posts and pages                             0 of 1 on · 1 write                   [Show >] |
|     No MCPMate tool uses this yet.                                                            |
|                                                                                                |
+------------------------------------------------------------------------------------------------+
| 3 unsaved changes                                           [Discard]  [Save changes]          |
+------------------------------------------------------------------------------------------------+
```

**Integrations tab** (the WooCommerce row is only an example, D12 `[B13]`)

```
| [x] Elementor                                   3 of 3 on · 2 read, 1 write           [Show >] |
|                                                                                                |
| [ ] WooCommerce                                 Not installed                                  |
|     (greyed; no checkbox) Install WooCommerce to use these tools.                              |
```

**Abilities tab**

```
| Abilities come from your other plugins. Each one is off until you turn it on.                  |
| Read each description first: an ability runs its plugin's own code.                            |
|                                                                                                |
| [ ] Site tools (my-plugin)                      0 of 3 on · 1 read, 2 write           [Show >] |
|     [ ] Export orders        my-plugin/export-orders   [Write]                                 |
|     [ ] Delete old drafts    my-plugin/delete-drafts   [Write] [Asks you first]                |
```

#### Elements

| Element | Behaviour |
| --- | --- |
| Note | `Notice` (info) with the exact copy shown above. Always visible. |
| Source tabs | `Tabs` with Core, Integrations and Abilities. Each label shows "on/total" (accessible name "Core, 24 of 38 on"). Each tab has its own route; the panel is `role="tabpanel"`. |
| Filter | `SegmentedControl` (a radio group) with All, Read-only and Write. "Write" includes "asks you first". |
| Search | Matches the label, tool name, command name and description. It filters after a 300 ms pause, then announces "6 tools match." Groups with no match are hidden; groups with a match open. |
| Show descriptions | `Toggle`. Shows the one-sentence description under each tool. The choice is remembered per browser (`localStorage`, wrapped in try/catch). |
| Group row | A tri-state `CheckboxGroup` header: checked = all on, mixed = some, unchecked = none. Clicking it when mixed turns all on (the usual pattern). Then the group name, plus the mono tool name for single-tool groups such as `run_wp_cli`. Then the counts: "12 of 14 on · 8 read, 6 write", where read and write count all tools in the group. Then **Show** or **Hide** (a disclosure). |
| Tool row | Checkbox, label, mono tool or command name, a Read or Write `Badge`, and an **Asks you first** `Badge` for approval-gated items. `check_approval` shows "Always on" with a disabled checkbox; its reason is visible text, not a tooltip. |
| Group option | Settings that belong to a group and are not tools (SVG files under Media). Indented, with a `Toggle`. |
| Unavailable group | Greyed (`--el-text-disabled`), no checkboxes, with the reason as text: "Not installed", "Installed, not active" + **Activate in Plugins** (link to `plugins.php`), "Not available on this site: file editing is turned off (DISALLOW_FILE_EDIT)", or "Needs WordPress 6.9 or later". |
| Saving (D14) | Each change is saved at once: one request with only the changed items. A sticky status under the list says "Saving…", then "Saved. Connected AI apps may need to reconnect to see the change." for 5 seconds, and the live region names the change ("Upload an image or PDF turned on. Saved.", "Theme drafts: 5 of 5 on. Saved."). A failed save is taken back and shows "Couldn't save your change." with why and **Retry**. |

**Default state.** New tools ship off (security rule 1). For tools that exist today, the migration maps the current switches exactly (`[B1]`): a feature switch that is on turns its group on, and the enable lists become item states. Tools that need no switch today (Site overview, Blocks, `list_abilities`) start on.

**States**

| View | Loading | Error | Empty |
| --- | --- | --- | --- |
| Any tab | 4 skeleton group rows | "Couldn't load the tools." [Try again] | Search: **"No tools match "xyz"."** + **Clear search**. Filter: "No read-only tools here." + **Show all** |
| Abilities, none registered | | | **"No abilities yet."** "Other plugins can offer abilities to AI apps. None of your active plugins does yet." + **Learn about abilities** (docs `[B14]`) |
| Abilities, WP < 6.9 | | | **"Abilities need WordPress 6.9 or later."** "This site runs WordPress 6.8." + **Update WordPress** (link to `update-core.php`) |

**Built (2026-10-26), where it differs:** Integrations says that tools a plugin offers itself (WooCommerce) are under Abilities, with a link. Changes save at once (D14), not through a save bar, so leaving the page asks nothing. `run_wp_cli` and `run_ability` show one checkbox per command or ability, and the tool is on while any of them is; turning the last one off turns the tool off. Abilities are a ledger (`pages/Tools/AbilitiesLedger`, 2026-11-15): a list of sources (MCPMate's own `list_abilities` first, then each ability category's label, else its namespace, sorted) beside a table of the open source's abilities (On, Ability with its own description while descriptions are on, Access badges) and an All on checkbox over the rows a filter leaves; the open source is `?source=` (the first shown when it names none, or one a search emptied), and below a 960 px container the list is a Source select. Ability IDs are never shown; search still finds them. Core is a switchboard (`.el-switchboard`, `resource/styles/core-tools.css`, 2026-11-15): one panel, each group a tinted band (its checkbox, single tool name, "3 of 5 on" then "2 read, 3 write" set apart by space, Show/Hide) over its tools in columns (label, tool name, Access badges, note), stacking under a 52rem container and again under 30rem; screen readers hear the counts as "3 of 5 on, 2 read, 3 write". Search waits 400 ms (FilterBar's delay), and when other tabs have matches the count line links to them ("1 more in Abilities"); `?q=` and `?show=` follow the tabs. A group's checkbox acts on the tools a filter or search leaves visible; its counts are over the whole group. Groups of 8 tools or fewer start open. A tab whose groups the site cannot run shows no count. The WordPress 6.9 empty state does not name the running version (`wp_version` from `[B11]` is not in `SERVER_VARIABLES`). Search, filter and descriptions are not stored server-side; the descriptions choice is kept in `localStorage`.

**Integrations as plugin tiles (2026-11-15).** The Integrations tab shows the plugin discovery tool (`list_integration_tools`) as an ordinary group, then one tile per plugin under the headings Forms, SEO, Custom fields, Store, Page builders, Themes (classic themes, since 2026-11-16) and Services. Every plugin MCPMate Pro supports has a tile, installed or not (`options.plugins` of the `integrations` group, `{id, label, status, theme, category}`; each `run_integration_tool` item names its plugin in `integration`). A tile is a button with the plugin's logo (`frontend/src/resource/integrations/<id>.svg|png|webp`; without a file, an empty square), its name and "3 of 11 on", "Installed, not active", "Theme not active", "Not installed" or "Needs an Unsplash key"; its accessible name is "Contact Form 7, 3 of 11 on". Missing and inactive plugins are quieter (greyed logo, the page's background), and still open. A tile opens a `Modal` (lg) titled with the plugin's name: the logo, the status, one sentence, then the plugin's groups, open, with their checkboxes, saved at once as on the list (the save status shows inside the dialog). Elementor's tile holds its page tools and its Elementor 4 operations as two groups; Stock photos holds the Unsplash key form. An inactive plugin's dialog keeps its switches under a warning **Notice** with **Activate in Plugins** (or **Activate in Themes**); a missing one says "Install X to use these tools." with no checkboxes. The open dialog is `?plugin=<id>` in the hash query: Back and Esc close it, and focus returns to the tile. A filter or search keeps only tiles with a match ("2 tools match"), and the dialog shows those rows with "Showing 2 of 11 tools." and **Show all**. Loading shows tile skeletons.

**Skills tab (docs/pro-parity.md P3).** Tools has a **Skills** tab (`#/tools/skills`) after the three source tabs, without a count, with or without MCPMate Pro. **Saved skills**: a `SegmentedControl` **Saved (n) | Trash (n)** and a `Table` (Skill with its description, User, For: This site / All sites, Last changed or Trashed, Actions). **MCPMate playbooks**: a `Table` (Playbook with its description, Needs: the tools its MCP prompt needs). The drawer shows a playbook as text (read-only, with a note that the user's own skill is the way to work differently), edits a saved skill (Description, Skill as Markdown with a byte counter; the server's refusal under its field) or moves it to the trash; a trashed one is shown as text with **Restore** and **Delete permanently** (`ConfirmDialog`, focus on **Keep it**). `?status=`, `?skill=` and `?playbook=` live in the hash query. Layout (`resource/styles/skills.css`, 2026-11-15): the **Saved | Trash** control shares the Saved skills heading's line; skill and playbook names are set in mono (they are identifiers); MCPMate playbooks follow the saved skills as a catalog in columns (three from a 64rem tab width, two from 40rem, else one): each entry is the name (opens the drawer), what it does cut at three lines and **Needs** cut at one (the drawer shows both whole), under a hairline; **Filter playbooks** (`?find=`) matches name, description and tool names, says "3 playbooks match." (announced) and offers **Clear filter** when nothing matches. Empty states: "No saved skills yet." (with how skills get saved), "The trash is empty."

**Memory tab (MCPMate Pro, docs/pro-parity.md P2).** While Pro is active, Tools has a fifth tab, **Memory** (`#/tools/memory`), after Skills and without an on/total count: memories are notes, not tools (their four tools are switched in the Core tab's Memory group). It shows every WordPress user's memories: a `SegmentedControl` **Saved (n) | Trash (n)**, a `FilterBar` (User, Tag, Search memories), a `Table` (Memory with its excerpt, User, Tags, Last changed or Trashed, Actions) and `Pagination`; `?status=`, `?user=`, `?tag=`, `?q=`, `?page=` and the open `?memory=` live in the hash query. The drawer edits a saved memory (Title, Tags as comma-separated text, Memory with a byte counter; the server's refusal, a secret included, is shown under its field) or moves it to the trash; a trashed one is shown as text with **Restore** and **Delete permanently**. Deleting for good, one or **Empty the trash**, always asks first (`ConfirmDialog`, focus on **Keep it**). Empty states: "No memories yet." (with how memories get saved), "No memories match these filters." + **Clear filters**, "The trash is empty."

---

### 5.4 Activity (`#/activity`, `#/activity/changes`, `#/activity/approvals`)

**h1:** Activity
**Intro:** Everything your AI apps did on this site, and what each change looked like before and after.
**Tabs:** Audit log · Change history · Approvals (n) (D1). The Approvals tab label includes the pending count ("Approvals, 2 waiting").

#### 5.4.1 Audit log

```
+------------------------------------------------------------------------------------------------+
| [ Audit log ]  [ Change history ]  [ Approvals  2 ]                                            |
|------------------------------------------------------------------------------------------------|
| Search [________________]  Tool [Any v]  User [Any v]  AI app [Any v]  Status [Any v]           |
| Period [Last 7 days v]                                     [Clear filters]   2 filters active  |
|                                                                                 [B5]           |
| 1,240 entries                                                                                  |
| caption: Audit log, newest first. Times in Asia/Dhaka.                                         |
| Date v       | User   | AI app  | Tool           | Arguments            | Status        | Dur.   |  |
|--------------+--------+---------+----------------+----------------------+---------------+--------+--|
| 26 Oct 10:42 | admin  | Claude  | elementor_save | post_id 41, 3 patch… | (*) Succeeded | 640 ms |[View]
| 26 Oct 10:30 | admin  | Claude  | run_wp_cli     | command: option upd… | (~) Waiting   | 210 ms |[View]
| 26 Oct 09:12 | editor | ChatGPT | upload_media   | url: https://…       | (x) Failed    | 1.2 s  |[View]
|                                                                                 [B4] [B5]      |
| Showing 1–20 of 1,240              [< Previous]  1  2  3  …  62  [Next >]                      |
|                                                                                                |
| Entries are kept for 90 days, then removed automatically. Change this in Settings.             |
| [Remove entries older than 90 days now]                                              [B9]      |
+------------------------------------------------------------------------------------------------+
```

| Element | Behaviour | Data |
| --- | --- | --- |
| Search | Free text over tool, action, target and message. Applies on Enter or after a 400 ms pause. | `[B5]` (`search`) |
| Tool | A select of tool names (mono) from the catalog, plus "Setup and connection" for `connection` rows. | `[B1]`, `[B4]` (`mcp_tool`) |
| User | The users that appear in grants and audit rows. | `[B5]` (`user_id`) |
| AI app | One option per grant: "Claude · admin (approved 12 Oct)". Grants can share a name, so the filter is the grant. | `grant_id` (exists) |
| Status | Succeeded / Failed / Waiting for approval. | `result` (exists) |
| Period | Last 24 hours, Last 7 days (default), Last 30 days, Last 90 days, Custom dates. **Custom dates** shows two native `<input type="date">` fields, "From" and "To", in the site's timezone. They replace the antd `RangePicker`, because native date inputs work with the keyboard and screen readers and fit at 320 px. | `from`, `to` (exist) |
| Clear filters | Shown when any filter is active, with a visible count ("2 filters active"). Clearing announces "Filters cleared. 1,240 entries." | |
| Table | Caption: "Audit log, newest first. Times in <timezone>." It is sortable by **Date** (default, descending) and **Duration**, server-side. Sorting announces "Sorted by duration, longest first." **Arguments** is a one-line summary: the first two key and value pairs from the redacted args, cut to 60 characters in plain text. Cookie-authenticated rows show the AI app as "wp-admin", and rows without auth show "System". **View** (accessible name "View details: elementor_save, 26 Oct 10:42") opens the drawer. | `[B4]` (duration, kind, mcp_tool), `[B5]` (client name, sort) |
| Pagination | 20 per page. After a page change, focus moves to the table caption and the live region says "Page 2 of 62." | `page`, `per_page` |
| Retention | One line plus a link to `#/settings?section=logging`. | `[B8]` |
| Remove old entries | Opens the purge dialog with a preview count. If the count is 0, no dialog opens; an inline note says "Nothing to remove: every entry is newer than 90 days." | `[B9]` |

**Entry drawer** (`?entry=<id>`, `Drawer`, title "elementor_save, 26 Oct 2026 10:42"):

```
+----------------------------------------------+
| elementor_save · 26 Oct 2026, 10:42:07  [x]  |
| (Asia/Dhaka)                                 |
|                                              |
| Status      (*) Succeeded                    |
| Message     Saved 3 changes to "Home".       |
| AI app      Claude (claude.ai)               |
|             Show only this app               |
| User        admin                            |
| Action      update                           |
| Object      Page "Home" (#41)  Open in editor|
| Duration    640 ms                    [B4]   |
| Request ID  4f1c…e2  [Copy]                  |
|                                              |
| Arguments (secrets hidden)                   |
| +------------------------------------------+ |
| | {                                        | |
| |   "post_id": 41,                         | |
| |   "patches": ["update:a1b2", …],         | |
| |   "token": "[redacted]"                  | |
| | }                                        | |
| +------------------------------------------+ |
| Result                                [B6]   |
| +------------------------------------------+ |
| | { "saved": true, "css": "ok" }           | |
| +------------------------------------------+ |
|                                              |
| View before and after               [B7]     |
| Waiting for approval: Review request         |
+----------------------------------------------+
```

- Arguments and result are pretty-printed JSON in a scrollable, focusable region (`tabindex="0"`, `role="region"`, an accessible name). `[redacted]` stays literal and is explained by the heading "Arguments (secrets hidden)".
- **Open in editor** appears only when the target is a post the current user may edit.
- **View before and after** appears when a change row exists `[B7]`. **Review request** appears for pending approvals.

**Purge dialog** `[B9]`:

> **Remove old entries now?**
> This removes 1,240 entries older than 90 days from this site. They can't be recovered. Entries from the last 90 days stay.
> [Remove 1,240 entries] [Cancel]

**States**

| Loading | Error | Empty |
| --- | --- | --- |
| Filter bar shown; 8 skeleton rows | "Couldn't load the audit log." + server message + [Try again] | No filters: **"Nothing logged yet."** "Every change an AI app makes is listed here, with who made it and when." + **Connect an AI app**. With filters: **"No entries match these filters."** + **Clear filters** |

#### 5.4.2 Change history `[B7]`

```
+------------------------------------------------------------------------------------------------+
| Tool [Any v]  Action [Any v]  Period [Last 30 days v]  Search objects [__________]             |
|                                                                                                |
| caption: Changes made through MCPMate, newest first. Times in Asia/Dhaka.                     |
| Date         | Tool             | Action | Object                     | By               | Status      |
|--------------+------------------+--------+----------------------------+------------------+-------------|
| 26 Oct 10:42 | elementor_save   | Update | Page "Home" (#41)          | Claude · admin   | (*) Applied |
|              |                  |        | [View before/after]  [Originating call]                     |
| 26 Oct 10:05 | write_theme_file | Update | style.css (draft a1b2c3d4) | Claude · admin   | (*) Applied |
| 25 Oct 17:20 | run_wp_cli       | Update | Option blogdescription     | Claude · admin   | (*) Applied |
|              |                  |        |                            |                  | after appr. |
| 25 Oct 16:02 | upload_media     | Create | Image "hero.jpg" (#88)     | ChatGPT · editor | (*) Applied |
| 24 Oct 11:11 | (posts proxy)    | Trash  | Post "Old news" (#12)      | Claude · admin   | (*) Applied |
|   (each row ends with [View before/after] and [Originating call] in an Actions column)        |
+------------------------------------------------------------------------------------------------+
```

| Element | Behaviour |
| --- | --- |
| Action | Create, Update, Trash, Delete. **Delete** happens only for theme draft files (D7). Each action has a text label and an icon; the icon is never shown alone. |
| Object | A readable label: the post title with its type and ID, a file path (mono) with the draft ID, an option name (mono), or a plugin name. It links to the object when it still exists and the user may edit it. |
| By | "AI app · user"; "wp-admin" for an admin action. |
| Status | "Applied" or "Applied after approval" (the drawer names the approver). |
| View before/after | Opens the diff drawer (`?change=<id>`). |
| Originating call | Opens the audit log drawer for that call (`#/activity?entry=<audit_id>`). |
| Not recorded | Bulk and opaque operations get a row whose diff says why: `search-replace` ("MCPMate keeps a summary for bulk changes: 3 tables, 214 rows."), abilities ("MCPMate can't see what this ability changed. Check the plugin that provides it."), cache and rewrite flushes (no row at all). |

**Diff drawer** (`Drawer`, wide: 720 px, full screen below 960 px):

```
+---------------------------------------------------------------------+
| Before and after: Page "Home" (#41)                            [x]  |
| Update · elementor_save · 26 Oct 2026, 10:42 · Claude · admin       |
| View  ( Side by side | Inline )        3 lines added, 1 removed     |
|                                                                     |
| Title          (unchanged) Home                                     |
| Status         (unchanged) Draft                                    |
| Content                                                             |
| +------------------------------+------------------------------+     |
| | Before                       | After                        |     |
| |   <h2>Welcome</h2>           |   <h2>Welcome</h2>           |     |
| | - <p>Old intro.</p>          | + <p>New intro text.</p>     |     |
| |                              | + <p>Second paragraph.</p>   |     |
| +------------------------------+------------------------------+     |
| Elementor elements: 3 changed (heading a1b2, text c3d4, image e5f6) |
|                                                                     |
| Originating call                                                    |
+---------------------------------------------------------------------+
```

- The diff is computed on the server as structured operations (`equal`, `add`, `remove`) per field `[B7]`. The UI renders it with `<ins>` and `<del>`, a visible `+` or `−` gutter, and visually hidden "Added line:" and "Removed line:" text, so the diff never relies on colour.
- Unchanged fields collapse into one line ("Title (unchanged) Home"). Long unchanged runs collapse to "12 unchanged lines" + **Show** (a disclosure).
- **Side by side** is offered only when the drawer is ≥ 960 px wide. Inline is the default and the only option on narrow screens.
- A snapshot that was cut short says so: "This snapshot was too large to keep in full (over 512 KB). The first 512 KB are shown."

**States**

| Loading | Error | Empty |
| --- | --- | --- |
| 8 skeleton rows | "Couldn't load the change history." [Try again] | **"No changes yet."** "When an AI app creates, updates or trashes something, you'll see what it looked like before and after." + **View the audit log**. When turned off: `Notice` "Change history is turned off, so new changes aren't recorded." + **Turn it on in Settings** |

#### 5.4.3 Approvals

Today's `pages/Approvals` list and detail, moved into the shell without changes in behaviour. The status filter is a `SegmentedControl` (Pending, Run, Rejected, Expired, All; today's "Executed" becomes "Run"); the table gets a caption ("Approval requests, newest first."); and expiry shows as relative time ("Expires in 9 min"), with the absolute time in the detail view.

| Loading | Error | Empty |
| --- | --- | --- |
| 5 skeleton rows | "Couldn't load approval requests." [Try again] | Pending: **"Nothing waiting for you."** "When an AI app asks to do something that's hard to undo, the request waits here for you." + **View all requests**. Other filters: "No requests here." + **View all requests** |

The detail screen (`#/activity/approvals/:id`) keeps its copy and its Approve and Reject flow. Reject becomes a `ConfirmDialog`:

> **Reject this request?**
> Claude's request to run `search-replace` won't run. Claude is told it was rejected.
> [Reject request] [Cancel]

Approve keeps its current preview screen. Its button says what runs: "Approve and run `search-replace`".

Approvals expire after 15 minutes for security reasons. This is an essential time limit (WCAG 2.2.1 exception), and the screen shows the expiry plainly.

---

**Built (2026-10-26), where it differs:** Every filter, the sort, the page and an open drawer (`?entry=`, `?change=`) are in the hash query, so a view can be shared or reloaded; old links (`#/audit-log?tool=connection&grant_id=…`) still filter. The Tool filter lists the catalog's MCP tool names (`mcp_tool`) plus "Setup and connection" (the internal `connection` tool); an internal tool from an old link (`?tool=cli`) gets an option of its own. The default period (Last 7 days for the audit log, Last 30 days for change history) is the select's empty value, so it never counts as a filter; custom dates are days in the site's timezone, sent as RFC 3339 UTC bounds. With no filters and nothing in the last 7 days the empty state offers **Show all time**; "Nothing logged yet." + **Connect an AI app** shows for All time. A drawer opened from a row adds a history entry (Back or Close returns); one opened from a link is closed by removing the key. The entry drawer shows Status, Message, AI app (+ **Show only this app**), User, Action, Object (+ **Open in editor** for `post:<id>` targets, without checking `edit_post`: the page is administrators-only), Duration, Request ID (`CopyField`), the redacted arguments, **Review request** (to the pending list: an audit row does not hold its approval ID) and a **View before and after** link per change the call made (`changes/list` by `audit_id`). There is no Result (`[B6]`). Change history has a Type filter (the kinds Settings can record) and no Status column: a change row does not record that an approval ran it, so "Applied after approval" can't be told apart (changes made in wp-admin, such as approvals, show "wp-admin · <user>"). **Search objects** matches the object's label and key. Objects are not linked. **Remove old entries now** uses the configured window (`Retention::days()`, so a filter wins) and is hidden when a filter keeps every entry. The visible tab content starts with a visually hidden `<h2>` named after the tab, so the narrow cards' `<h3>` headings are in order. **Approvals** (2026-10-27) uses the shared components: the "Show" filter (Waiting, Run, Rejected, Expired, All) is in `?status=`, times are in the site's timezone, the request page's `<h2>` takes focus when it opens and after a decision, the outcome stays on the page and is announced (assertively when the run failed), and the Approvals tab count and the shell's notice follow decisions (`usePendingApprovalCount`). The list has no Target column (the request page shows it). Accessibility review: `docs/accessibility-review.md`.

### 5.5 Settings (`#/settings`)

**h1:** Settings
**Intro:** How MCPMate protects your site, and what it keeps a record of.

On screens ≥ 960 px, an "On this page" list of links (Access and safety, Logging and history, Advanced) sits at the inline-start side. Each section is an `<h2>` and its own form with its own **Save changes** button (§4, rule 8). `?section=<id>` scrolls to a section and focuses its heading.

```
+------------------------------------------------------------------------------------------------+
| On this page        | ACCESS AND SAFETY                                                        |
|  Access and safety  |                                                                          |
|  Logging and history| Calls per minute for each app               [ 120  v]              [B8]  |
|  Advanced           | An app that sends more waits until the minute is over.                   |
|                     |                                                                          |
|                     | Token lifetimes and client registration                                  |
|                     | Set on the Connections page.   Tokens 15 minutes, approvals 30 days      |
|                     |                                              [Open Connections]          |
|                     |                                                                          |
|                     | Who can approve AI apps                     [Administrators   v]  [B8]   |
|                     | Only administrators, for now. (locked, D5)                               |
|                     |                                                                          |
|                     | New content is saved as a draft             (lock) Always on             |
|                     | MCPMate never publishes. You review and publish drafts yourself.        |
|                     |                                                                          |
|                     | What AI apps can do                         18 of 31 tools on            |
|                     | Choose tools on the Tools page.             [Manage tools]               |
|                     |                                                  [Save changes]          |
|                     |--------------------------------------------------------------------------|
|                     | LOGGING AND HISTORY                                                      |
|                     |                                                                          |
|                     | Log write calls                             (lock) Always on             |
|                     | Every change an AI app makes is logged. This can't be turned off.        |
|                     | Also log read calls                         [ on  ]               [B4]   |
|                     | Needed for the Dashboard numbers and recent activity.                    |
|                     | Keep the audit log for                      [90 days  v]          [B8]   |
|                     | Keep read calls for                         [30 days  v]          [B8]   |
|                     | Change history                              [ on  ]               [B7]   |
|                     | Keeps a before and after copy of everything an AI app changes.           |
|                     | Keep change history for                     [30 days  v]          [B8]   |
|                     |                                                  [Save changes]          |
|                     |--------------------------------------------------------------------------|
|                     | ADVANCED                                                                 |
|                     |                                                                          |
|                     | Setup                                       [Run setup again]            |
|                     | Walks you through checks, connecting and safety choices. Changes         |
|                     | nothing until you choose to.                                             |
|                     |                                                                          |
|                     | Remove all data on uninstall                [ on  ]               [B8]   |
|                     | Deletes MCPMate's settings, audit log, change history and connection    |
|                     | from this site when you delete the plugin. Every AI app loses access.    |
|                     | Theme drafts and theme backups stay in place.                            |
|                     |                                                  [Save changes]          |
+------------------------------------------------------------------------------------------------+
```

| Setting | Control | Default | Range or values | Data |
| --- | --- | --- | --- | --- |
| Calls per minute for each app | Select | 120 | 30, 60, 120, 300, 600, 1,000 | `[B8]` |
| Token lifetimes and client registration | Summary + link to `#/connections?section=sign-in`, where the three values are set (§5.2) | | | `preferences` |
| Who can approve AI apps | Select: Administrators (`manage_options`), Editors and administrators (`edit_others_posts`), Authors, editors and administrators (`publish_posts`) | Administrators | Locked until D5 is signed off; the select is then disabled with visible text "Only administrators, for now." | `[B8]` |
| New content is saved as a draft | Fixed row, lock icon + "Always on" | on | not changeable (D3) | none |
| What AI apps can do | Summary + link | | | `[B1]` |
| Log write calls | Fixed row, "Always on" | on | not changeable (D2) | none |
| Also log read calls | Toggle | on | | `[B4]` |
| Keep the audit log for | Select | 90 days | 30, 60, 90, 180, 365 | `[B8]` |
| Keep read calls for | Select | 30 days | 7, 14, 30, 90 | `[B8]` |
| Change history | Toggle; turning it off opens a dialog | on | | `[B7]` |
| Keep change history for | Select | 30 days | 7, 14, 30, 90 | `[B8]` |
| Run setup again | Secondary button (existing mutation) | | | `onboarding/update` |
| Remove all data on uninstall | Toggle | on (today's behaviour) | | `[B8]` |
| Chat (P22, MCPMate Pro; its own card after AI instructions, `?section=chat`) | Password field for the Anthropic API key (write-only: once saved it shows "A key is saved", with **Remove the saved key**), a Model select, and **Turn on Chat** (disabled without a key) | off | | `chat/settings`, `chat/settings/update` |
| Before you uninstall (P21) | Secondary button **Review data before deleting**, also linked from the Plugins screen: opens a Drawer (`?section=advanced&review=uninstall`) with the tables and row counts and the settings the uninstall deletes, what stays (theme drafts and backups, sandbox folders, designs, their records), and **Download audit log** / **Download change history** (JSON, put together in the browser a page at a time) | | | `uninstall/summary`, `uninstall/export` |

If the `MCPMATE_audit_log_retention_days` filter is in use, the select is disabled with visible text: "Set by code on this site: 120 days."

**Dialog: turning change history off**

> **Turn off change history?**
> New changes won't be recorded. Snapshots you already have stay until they expire after 30 days.
> [Turn off change history] [Keep it on]

**Dialog: leaving with unsaved changes** (also used on Tools and in Edit access)

> **Leave without saving?**
> You changed 3 settings. If you leave now, those changes are lost.
> [Leave without saving] [Stay on this page]

**States**

| Loading | Error |
| --- | --- |
| Skeleton per section | "Couldn't load your settings." [Try again]. A save error is shown inline above that section's button: "Couldn't save. Nothing was changed." + the server message; the form keeps your input. |

---

**Built (2026-10-26), where it differs:** There is no rate limit row (AGENTS.md gap 43), so Access and safety has nothing to save and no **Save changes**; its approval select is disabled with "Only administrators, for now." (D5). Each of the other two sections is a `<form>` named by its heading, with a save bar that shows "No unsaved changes." / "2 unsaved changes" / "Saved." and **Save changes**, plus **Discard** while something is unsaved; it is sticky at the bottom of the window only while there are unsaved changes. Values are checked as they change, with the server's rules and words (`pages/Settings/logic.ts` mirrors `Preferences::update()`): read calls can't be kept longer than the audit log, and change history that is on needs at least one kind of change. The error sits under its control (`aria-invalid`, `aria-describedby`); Save with an error sends nothing, says "Fix 1 setting to save." and focuses the first invalid setting. A refusal from the server is shown the same way, next to the setting it names, above "Couldn't save. Nothing was changed.", and the form keeps the input. Logging and history also has **Record changes to** (the kinds of change history, a tri-state `CheckboxGroup`). Turning change history off asks first, then waits for **Save changes** like any other value. Leaving for another page with unsaved changes opens "Leave without saving?" (on **Stay on this page**); a `?section=` link is not leaving, and closing or reloading the tab gets the browser's own question. Since 0.9.0 there is no MCPMate server row. Since 0.10.0 Access and safety saves only the rate limit; "Let AI apps register themselves" and the token lifetimes are on Connections (§5.2), with a summary row and **Open Connections** here. Section headings are sentence case (not the small capitals in the sketch above). From a section width of 36rem, every value (select, switch, "Always on", a count over its link) sits in one column at the inline-end side, 17rem wide, so the values line up down the page; **Record changes to** hangs under its switch on a rule, not in a box of its own. The "On this page" links mark the current section with a bar at their inline-start edge.

**AI instructions (MCPMate Pro, docs/pro-parity.md P1).** While Pro is active, a section **AI instructions** sits between Access and safety and Logging and history, with its own form and save bar (admin-ajax `site-instructions*`, not `preferences`), and "On this page" lists it; `?section=instructions` moves to it. Rows: **Send these instructions to AI apps** (`Toggle`), **Instructions** (a textarea with a byte counter "20 of 8,192 bytes", the last save's author and date, and the server's error under it when the text is over 8 KB), and **Preview** (a `Drawer`, "What AI apps receive", kept in `?preview=instructions`, showing the full server instructions for the unsaved draft as plain text). Unsaved changes there count for "Leave without saving?".

### 5.5a Chat (`#/chat`, MCPMate Pro, P22)

The one top-level page beyond the five (D3). Shown in the top bar only while Chat is set up.

| Element | Behaviour | Data |
| --- | --- | --- |
| Not set up | `EmptyState` "Chat isn't set up" with **Open Chat settings** (`#/settings?section=chat`) | `SERVER_VARIABLES.chat` |
| First use | A card with the cost notice (what is sent to Anthropic, that the key's account is billed, that changes wait for Run and Approvals) and **Start using Chat**; after a revoke on Connections it reads "Chat's access to this site was revoked" with **Connect Chat again** | `chat/consent` |
| Conversations | `<nav aria-label="Conversations">`: **New chat**, then up to 50, newest first, each a link (`?session=<id>`, `aria-current`) with **Delete** (a confirmation) | `chat/sessions`, `chat/delete` |
| Transcript | The admin's messages (with image chips), Claude's text, a collapsible "Thinking" summary, and each tool call as a `<details>` row: a status dot with text (Done, Failed, Skipped, Waiting for your approval, Waiting for you), what Claude sent (`JsonBlock`) and a short result; an approval links to Activity → Approvals. While a step streams, the text grows in place; nothing streaming is a live region. When it ends, a polite announcement: "Claude answered." or "Claude is waiting for you to review its changes." | `chat/session`, the step's events |
| Plan | A card "Claude wants to make N changes": each call with a kind badge, **Run** (primary) and **Skip**. Only calls that change the site wait for it; reads run at once | `chat/run` |
| Composer | A labelled textarea (Enter sends, Shift+Enter is a new line, never mid-IME), **Attach images** (≤ 4, ≤ 3 MB, JPEG/PNG/GIF/WebP, checked before sending with the server's words) and **Send** ("Claude is answering…" while busy). An error shows as a danger `Notice` with **Try again** | `chat/send`, `chat/retry` |

### 5.6 Screens outside the navigation

| Screen | Change |
| --- | --- |
| Setup (`#/setup`) | Minimal shell (§3.6). Content unchanged. Its links to `#/audit-log` and `#/connected-apps` still work through the redirects. |
| Connect (`#/connect`) | Minimal shell. Content unchanged. Approve and Deny already say what they do. |
| Not found | "This page doesn't exist." + **Go to the Dashboard**. |

---

## 6. Design tokens

All tokens are CSS custom properties prefixed `--el-` on `#mcpmate-root` and `#mcpmate-portal`. `config/theme.ts` and `tailwind.config.mjs` read the same values (one source: `frontend/src/config/tokens.ts`).

### 6.1 Colour

The brand is the MCPMate palette: navy `#1E3A8A` and blue `#2563EB` (the logo on light backgrounds), with light blue `#BFDBFE` for the mark on dark ones (app icon, logo on dark; 1.40:1 on white, so it is used for nothing a reader must see in the app), on a cool light grey page. The tints `#DBEAFE` and `#EFF6FF` and the darker `#172554` and `#1D4ED8` are shades of the same blue. Status colours are ours, chosen to pass contrast on both white and their own tint.

| Token | Value | Use |
| --- | --- | --- |
| `--el-bg-page` | `#F4F6FB` | Page background |
| `--el-bg-surface` | `#FFFFFF` | Cards, tables, top bar, drawers |
| `--el-bg-subtle` | `#F1F3F8` | Code and JSON blocks, table header |
| `--el-bg-brand-tint` | `#DBEAFE` | Selected row, selected segment, secondary hover |
| `--el-border` | `#DCE0EA` | Card and table borders (decorative) |
| `--el-border-strong` | `#7C8599` | Input, checkbox and toggle-off borders (need 3:1) |
| `--el-text` | `#1B2233` | Body text, headings |
| `--el-text-secondary` | `#4A5468` | Secondary text, meta lines |
| `--el-text-label` | `#5A6478` | Small uppercase labels |
| `--el-text-disabled` | `#636C80` | Unavailable groups ("Not installed") |
| `--el-brand` | `#1E3A8A` | Primary buttons, links, current nav item, toggle on, the logo's right stroke |
| `--el-brand-hover` | `#172554` | Primary hover and pressed (`#1E3A8A` darkened: `#2563EB` text on the tint is 4.24:1) |
| `--el-brand-accent` | `#2563EB` | The logo's left stroke |
| `--el-focus` | `#2563EB` | Focus ring |
| `--el-success-text` / `-bg` / `-dot` | `#1D6B36` / `#E7F4EC` / `#2E8540` | Succeeded, Connected, Passed |
| `--el-warning-text` / `-bg` / `-dot` | `#7A4500` / `#FFF3DC` / `#B25E00` | Waiting, Paused, Warning |
| `--el-danger-text` / `-bg` / `-dot` | `#A3222A` / `#FCEBEC` / `#C4321F` | Failed, Needs attention |
| `--el-danger` / `-hover` | `#B42318` / `#912018` | Danger buttons |
| `--el-info-text` / `-bg` | `#1D4ED8` / `#EFF6FF` | Info notices, Read badge (a darker shade of the brand blue on a lighter blue than `--el-bg-brand-tint`) |
| `--el-neutral-text` / `-bg` / `-dot` | `#3D4659` / `#EDEFF4` / `#6B7489` | Neutral badges, "not connected" |
| `--el-diff-add-text` / `-bg` | `#14532D` / `#E6F4EA` | Added lines |
| `--el-diff-del-text` / `-bg` | `#8A1C1C` / `#FCE8E8` | Removed lines |
| `--el-skeleton` | `#E3E7EF` | Skeleton blocks (decorative) |

**Contrast** (WCAG 2.x relative luminance, computed for this spec). Text needs 4.5:1 and UI parts need 3:1.

| Pair | Ratio | Needs | |
| --- | --- | --- | --- |
| text on surface / on page | 15.87 / 14.68 | 4.5 | pass |
| text-secondary on surface / on page | 7.61 / 7.04 | 4.5 | pass |
| text-secondary on brand-tint | 6.24 | 4.5 | pass |
| text-label on surface / on page | 5.95 / 5.50 | 4.5 | pass |
| text-disabled on surface / on page | 5.27 / 4.87 | 4.5 | pass (kept readable on purpose) |
| white on brand (primary button) | 10.36 | 4.5 | pass |
| white on brand-hover | 14.69 | 4.5 | pass |
| brand-hover on brand-tint (secondary hover) | 12.04 | 4.5 | pass |
| brand (link) on surface / on page | 10.36 / 9.58 | 4.5 | pass |
| brand on brand-tint (selected segment) | 8.49 | 4.5 | pass |
| white on danger / danger-hover | 6.57 / 8.66 | 4.5 | pass |
| success-text on success-bg / on surface | 5.78 / 6.54 | 4.5 | pass |
| warning-text on warning-bg / on surface | 7.13 / 7.84 | 4.5 | pass |
| danger-text on danger-bg / on surface | 6.47 / 7.45 | 4.5 | pass |
| info-text on info-bg / on surface | 6.16 / 6.70 | 4.5 | pass |
| neutral-text on neutral-bg | 8.23 | 4.5 | pass |
| text on bg-subtle (JSON, code) | 14.30 | 4.5 | pass |
| diff-add-text on diff-add-bg | 8.02 | 4.5 | pass |
| diff-del-text on diff-del-bg | 7.88 | 4.5 | pass |
| text on each status tint (warning, danger, info, success) | 14.44 / 13.78 / 14.58 / 14.02 | 4.5 | pass |
| success-dot / warning-dot / danger-dot / neutral-dot on surface | 4.62 / 4.67 / 5.49 / 4.68 | 3 | pass |
| border-strong on surface / on page / on bg-subtle | 3.70 / 3.42 / 3.33 | 3 | pass |
| toggle track on / off on surface | 10.36 / 3.70 | 3 | pass |
| focus ring on surface / on page / on bg-subtle / on brand-tint | 5.17 / 4.78 / 4.66 / 4.24 | 3 | pass |
| focus ring next to brand | 2.00 | 3 | **fails if the ring touches the button**, so the ring always has a 2 px offset over the surface or page colour (§6.6) |
| border on surface (cards) | 1.32 | n/a | decorative: a card is not a control, and its content identifies it |
| skeleton on surface | 1.24 | n/a | decorative, `aria-hidden` |

`forced-colors: active`: borders become `CanvasText`, the focus ring `Highlight`, and status dots keep their text. Nothing depends on a background colour alone.

### 6.2 Spacing (4 px base)

`--el-space-1` 4 · `-2` 8 · `-3` 12 · `-4` 16 · `-5` 20 · `-6` 24 · `-8` 32 · `-10` 40 · `-12` 48 (px, written in rem: 0.25rem–3rem).
Card padding is 24 (16 below 600 px). Gaps are 16 between cards and 24 between sections. A table cell is 12 × 16.

### 6.3 Radius

`--el-radius-sm` 6 px (badges, inputs) · `--el-radius` 8 px (buttons, cards, notices; matches the antd `borderRadius`) · `--el-radius-lg` 12 px (modal, drawer edge) · `--el-radius-full` 999 px (pill, dot, toggle).

### 6.4 Type

Font: `Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`. Mono: `ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace`. All sizes are in rem, so the browser's text size and zoom apply.

| Token | Size / line height | Weight | Use |
| --- | --- | --- | --- |
| `--el-text-xs` | 0.75rem / 1rem (12/16) | 600, uppercase via CSS, letter-spacing 0.04em | Small labels above numbers, card titles |
| `--el-text-sm` | 0.8125rem / 1.25rem (13/20) | 400 | Meta lines, table cells, badges |
| `--el-text-base` | 0.875rem / 1.375rem (14/22) | 400 | Body |
| `--el-text-md` | 1rem / 1.5rem (16/24) | 600 | h3, drawer and modal titles |
| `--el-text-lg` | 1.25rem / 1.75rem (20/28) | 600 | h2 (section) |
| `--el-text-xl` | 1.5rem / 2rem (24/32) | 600 | h1 (page) |
| `--el-text-2xl` | 2rem / 2.5rem (32/40) | 600, `font-variant-numeric: tabular-nums` | Stat numbers |
| `--el-text-mono` | 0.8125rem / 1.25rem | 400 | Tool names, IDs, URLs, paths |

Uppercase is applied with `text-transform` only, never typed into strings, so translations stay natural and screen readers don't spell words out letter by letter. Text spacing overrides (WCAG 1.4.12) must not clip anything: no fixed heights on text containers.

### 6.5 Layout

Breakpoints: `sm` 600 · `md` 782 (WordPress's mobile admin breakpoint) · `lg` 960 · `xl` 1280. Max content width is 1440. Components that change shape (Table → cards, Dashboard grid) respond to their **container** width (a `ResizeObserver` hook), so they behave the same at 200 % zoom and in narrow columns.

### 6.6 Focus, elevation, motion, layers

- **Focus:** `outline: 2px solid var(--el-focus); outline-offset: 2px` on `:focus-visible` for every focusable element, including antd ones (override their box-shadow focus style). Inside tables and lists, the offset is −2 px (the ring sits inside the cell).
- **Elevation:** cards have none (border only). Overlays use `--el-shadow-overlay: 0 8px 24px rgb(27 34 51 / 0.16)`.
- **Motion:** `--el-duration-fast` 120 ms, `--el-duration` 200 ms, `ease-out`. Under `prefers-reduced-motion: reduce`, durations are 0, the skeleton shimmer stops, drawers appear without sliding, and spinners are replaced by a static icon plus text ("Loading…").
- **Layers:** the sticky top bar is z 20 and the save bar z 20. Overlays use antd's 1000, which stays below the WordPress admin bar (99999); the modal top offset includes the admin bar height.

### 6.7 RTL

`ConfigProvider direction` comes from `SERVER_VARIABLES.rtl` `[B11]`. CSS uses logical properties only (`margin-inline-start`; Tailwind `ms-*`/`me-*`/`ps-*`/`pe-*`/`start-*`/`end-*`). Directional icons (chevrons, arrows, the drawer side) flip. Mono text and numbers with units sit in `<bdi dir="ltr">`.

### 6.8 Logo `[B15]`

Our own mark: two rounded strokes forming an M, the right one in `--el-brand` (#1E3A8A) and the left one in `--el-brand-accent` (#2563EB), as a 24 × 24 SVG (both strokes in `CanvasText` under forced colours). It has `aria-hidden` next to the visible name. The menu icon is the same mark as a single-colour SVG data URI (`fill="black"`) so `svg-painter` can tint it. The source files (mark on light and on dark, mono mark, app icon (navy tile, white and `#BFDBFE` strokes), a favicon that follows the browser's colour scheme, logos on light and dark) are in `frontend/src/resource/brand/`; the WordPress.org directory icons are in `.wordpress-org/`. The name "MCPMate" is set in `--el-text-md`, weight 600. No other product's mark, colours or name.

---

## 7. Components

Each component lives in `frontend/src/components/ui/<Name>.tsx`, with a test next to it (`*.test.tsx`: role, name, keyboard and states). "Base" names the Ant Design part it wraps (D8). Every text prop is a translated string.

### Card
- **Anatomy:** a `<section>` with an optional title (`--el-text-xs` label style as an `<h2>`/`<h3>` at the right level, set by the page), an optional action slot at the inline end, and the body. 1 px `--el-border`, `--el-radius`, surface background, 24 px padding.
- **Props:** `title`, `headingLevel: 2 | 3`, `actions`, `children`, `busy` (sets `aria-busy`).
- **A11y:** `aria-labelledby` points at the title. Never clickable as a whole.
- **Base:** none (a `div`). Not antd `Card`, which renders a title without a heading element.

### StatTile
- **Anatomy:** label (uppercase xs), value (2xl), meta line (sm, secondary), optional link.
- **Props:** `label`, `value: number | null`, `format` ("count" | "percent" | "fraction"), `total?`, `meta`, `link?`, `loading`, `error`.
- **A11y:** a `<dl>`: the label is `<dt>`, the value and meta are `<dd>`. A null value renders "—" with a visually hidden "No data". The value is not live; the page announces refreshes.
- **Base:** none.

### Button
- **Variants:** `primary` (brand fill, white text), `secondary` (surface, `--el-border-strong` border, brand text), `danger` (danger fill, white text), `danger-secondary` (surface, danger border and text), `link` (brand text, underlined on hover and always underlined inside running text).
- **Sizes:** `md` 36 px tall (default), `sm` 32 px; minimum 24 × 24 target (WCAG 2.5.8). An icon-only button is 32 × 32 and needs `label`.
- **Props:** `variant`, `size`, `icon`, `loading`, `disabled`, `label` (required when there's no visible text), `href` (renders `<a>`).
- **Rules:** a destructive label states the result ("Revoke access for Claude"). While `loading`, the label stays the same (or changes to a progress word like "Saving…"), `aria-disabled` is set, and the width doesn't jump. A disabled button gets its reason as visible text nearby, never only in a tooltip.
- **Base:** antd `Button`, with our styles.

### StatusPill
- **Anatomy:** dot + text on a tinted background, `--el-radius-full`, `sm` text.
- **Props:** `tone` ("success" | "warning" | "danger" | "neutral"), `text`, `href?`, `glyph?` ("pause" | "hollow").
- **A11y:** the text always carries the meaning; the dot is `aria-hidden`. As a link, the accessible name is the text ("3 AI apps connected").
- **Base:** none.

### StatusText (dot + text inside tables and lists)
- The same dots as StatusPill, without the background. The words: Succeeded, Failed, Waiting for approval, Applied, Applied after approval, Active, Paused, Waiting for first use, Revoked, Passed, Warning, Failed check, Checked later.

### Badge
- **Variants:** `read` (info tint, "Read"), `write` (warning tint, "Write"), `approval` (neutral tint + shield icon, "Asks you first"), `neutral`, `unavailable` (no fill, disabled text: "Not installed").
- **A11y:** plain text inside a `<span>`, never only an icon. Inside a row, the badges follow the label in reading order.
- **Base:** none (not antd `Tag`, whose close button and colours we don't use).

### Table
- **Anatomy:** a real `<table>` with a `<caption>` (it can be visually hidden but must exist), `<th scope="col">` for every column (the actions column gets a visually hidden "Actions"), and the first cell of each row as `<th scope="row">` when it names the row (AI app, Date).
- **Sorting:** a sortable header holds a `<button>`. The `th` carries `aria-sort` ("ascending" / "descending"; left off when unsorted). The button's accessible name is "Date, sorted newest first. Select to sort oldest first." The live region announces the new order after a change.
- **Narrow:** below a 720 px container width, it renders a `<ul>` of cards. Each card is an `<li>` with a heading (the row header) and a `<dl>` of the other columns. Sorting moves to a "Sort by" `<select>`. Only one of the two forms is in the DOM at a time.
- **Props:** `caption`, `columns` (`{key, title, sortable, rowHeader, hiddenTitle, render}`), `rows`, `sort`, `onSort`, `loading` (renders skeleton rows with the same column count), `empty` (an `EmptyState`), `error`.
- **Base:** antd `Table` for the wide form (`caption`, `rowScope: "row"`, its `aria-sort`), our own list for the narrow form. Turn off antd's sort tooltip (`showSorterTooltip: false`) so the button name is the only sort text.

### Tabs (in-page)
- **Behaviour:** `role="tablist"`; the selected tab has `tabindex="0"` and the others `-1` (roving). Left and Right move and activate (activation follows focus, since panels are cheap); Home and End jump to the ends; RTL reverses Left and Right. Each tab is linked to its route, so the panels are separate routes.
- **Props:** `items` (`{key, label, count?, href}`), `activeKey`.
- **A11y:** a count is part of the accessible name ("Approvals, 2 waiting"). The panel has `aria-labelledby` set to its tab.
- **Base:** antd `Tabs` (`rc-tabs` 15.7 has roving tabindex and RTL arrows). Check Home and End; if they are missing, add a keydown handler in the wrapper.

### SegmentedControl
- **Behaviour:** a radio group (`role="radiogroup"`, each option `role="radio"`, `aria-checked`). Arrow keys move and select. The group has a visible label ("Show").
- **Base:** antd `Segmented`. Check that its roles are as above; if not, render native radios styled as segments.

### Toggle
- **Anatomy:** a switch with a visible label on the inline-start side and an optional description under it.
- **A11y:** `role="switch"` + `aria-checked`. The label is the accessible name; the description is linked with `aria-describedby`. On and off are shown by the knob position **and** "On"/"Off" text next to it (not colour alone). The track is 3:1 in both states.
- **Base:** antd `Switch`, wrapped with today's `FeatureSwitchRow` layout.

### CheckboxGroup (tri-state)
- **Anatomy:** a parent checkbox (group name + counts) and child checkboxes in a disclosure.
- **Behaviour:** the parent is `checked` when all children are on, `mixed` (`aria-checked="mixed"`, `indeterminate`) when some are, and unchecked when none are. Activating a mixed or unchecked parent turns all available children on; a checked one turns them all off. Disabled children (Always on, unavailable) never change.
- **A11y:** a `<fieldset>` with a `<legend>` for the group. The counts are part of the parent's description, not its name. The Show and Hide disclosure is a separate button (`aria-expanded`), so the checkbox and the disclosure never share one control.
- **Base:** antd `Checkbox` (`indeterminate`).

### Modal
- **Behaviour:** `role="dialog"`, `aria-modal`, labelled by its title. On open, focus goes to the first field or the first action, or the title when the content is long. Focus is trapped. Esc closes it. On close, focus returns to the element that opened it. The background is inert. The modal is full screen below 600 px.
- **Props:** `title`, `open`, `onClose`, `footer`, `size` ("md" 560 | "lg" 720).
- **Base:** antd `Modal`, with `getContainer` set to `#mcpmate-portal` and `focusTriggerAfterClose` (default true).

### ConfirmDialog
- A Modal with a fixed layout: the title is a question, the body says what happens and what stays, then the confirm and cancel buttons. The confirm button names the action; the cancel button names what is kept.
- `role="alertdialog"`. Initial focus is on **Cancel** for danger dialogs, so a stray Enter does nothing harmful.
- **Props:** `title`, `body`, `confirmLabel`, `cancelLabel`, `tone` ("danger" | "default"), `onConfirm` (async: the button shows its progress word, the dialog stays open on error and shows the error inside it).

### Drawer
- The same focus rules as Modal. It opens from the inline-end side (start in RTL). Width is 480 px (720 for diffs); full screen below 600 px. The title is an `<h2>`. The URL holds the drawer state (`?entry=`), so Back closes it.
- **Base:** antd `Drawer` (`getContainer` set to the portal).

### CopyField
- A read-only input plus a **Copy** button (today's `McpUrl`, generalised). Its name is "Copy MCP URL". On success the button reads "Copied" for 3 s and the live region says "MCP URL copied." Without the Clipboard API, the text is selected and the live region says "Press Ctrl+C (⌘C on Mac) to copy." Long values wrap below 600 px instead of being cut.
- **Props:** `value`, `label`, `copyLabel`, `mono` (default true).

### FilterBar
- **Anatomy:** labelled controls (each with a visible `<label>`), "N filters active", and **Clear filters**. Below 782 px, the controls collapse behind a **Filters (2)** disclosure.
- **Behaviour:** selects apply on change; search applies on Enter or after a 400 ms pause. Every change updates the hash query and announces the result count. Nothing moves focus.
- **Base:** antd `Select` and `Input`, plus native date inputs.

### Pagination
- A `<nav aria-label="Audit log pages">` with Previous, page numbers (`aria-current="page"` on the current one) and Next, plus "Showing 21–40 of 1,240". Below 600 px it shows only Previous, "Page 2 of 62" and Next. After a change, focus goes to the table caption and the live region announces the page.
- **Base:** antd `Pagination`, or our own if its markup doesn't produce the `nav` and labels above.

### EmptyState
- **Anatomy:** an optional icon (decorative), a heading (`--el-text-md`), one sentence, and **one** action.
- **Props:** `title`, `body`, `action` (`{label, onClick | href}`), `headingLevel`.

### Notice
- **Tones:** info, success, warning, danger. Each has an icon **and** a visible tone word for screen readers ("Warning:"), then a title, an optional body, and an optional action.
- **A11y:** a notice shown after a user action uses `role="status"` (info, success) or `role="alert"` (warning, danger). Notices present on load have no live role, to avoid noise. Dismissible notices have a labelled close button ("Dismiss: Finish setting up").
- **Base:** none (antd `Alert` adds its own roles inconsistently).

### Skeleton
- Blocks in `--el-skeleton`, shaped like the content that is coming (rows, lines, number). `aria-hidden="true"`. The surrounding region has `aria-busy="true"` and visually hidden "Loading…". The shimmer stops under reduced motion.

### Also needed

- **AppShell:** top bar, notices, `<main>`, footer, skip link, portal container.
- **Announcer:** one polite live region (`role="status"`) and one assertive (`role="alert"`) at the root, exposed as `useAnnounce()`. It replaces each page's own live region (setup keeps its own until it moves). The same text is never announced twice within 500 ms.
- **RelativeTime:** `<time dateTime={iso}>` with relative text that updates each minute without announcing anything.
- **JsonBlock:** a focusable scroll region showing pretty-printed JSON in mono.
- **DiffView:** §5.4.2.

---

## 8. Words

### 8.1 Glossary (use these words, and only these)

| Use | Not | Meaning |
| --- | --- | --- |
| AI app | client, AI client, connector, grant | Claude, ChatGPT, Cursor… once approved |
| Connect / connected | link, pair, authorize | An AI app approved on this site |
| Revoke access | delete, disconnect, remove | Ends one app's access |
| Pause AI access / Resume | disable, kill switch | Stops every app without revoking |
| Tool | endpoint, route, operation | Something an AI app can do |
| Turned on / off | enabled / disabled (in body text) | A tool's state |
| Asks you first | destructive, gated | Needs your approval each time |
| Read / Write | read-only, mutating | Whether a tool can change the site |
| Waiting for approval | pending, pending_approval | A request waiting for you |
| Succeeded / Failed | ok / error | A call's result |
| Move to trash | delete (for posts) | Rule 3 |
| Draft | unpublished | Rule 2 |
| Registered client | OAuth client, DCR client, client registration | How an AI app identifies itself to this site's sign-in (Connections) |
| MCP URL | endpoint URL, server URL | What you paste into the AI app |

### 8.2 Headings (exact)

Pages: Dashboard · Connections · Tools · Activity · Settings.
Cards and sections: AI access · Your MCP URL · Tool calls · Success rate · Tools turned on · Recent activity · Site checks · Help · AI apps · Revoked in the last 30 days · Revoke all AI apps · Registered clients · Token lifetimes and client registration · Core · Integrations · Abilities · Audit log · Change history · Approvals · Access and safety · Logging and history · Advanced.
Modal and drawers: Connect an AI app · Access for <app> · <tool> · <date> · Before and after: <object>.

All empty-state and dialog copy is in §5, next to its screen.

---

## 9. Accessibility checklist (WCAG 2.2 AA)

Each item is tested in Vitest + Testing Library where it can be (roles, names, keyboard) and checked by hand where it can't (zoom, screen readers).

**Structure**
- [ ] One `<h1>` per page; headings in order; cards and sections use `<h2>`/`<h3>`.
- [ ] Landmarks: `<nav>` (shell), `<main id="mcpmate-main">`, `<footer>`. Each `<nav>` has a distinct label.
- [ ] A skip link is the first focusable element and moves focus into `<main>` (2.4.1).
- [ ] The page `<title>` names the screen: "Activity ‹ MCPMate ‹ My Site — WordPress" (`document.title` updated per route) (2.4.2).
- [ ] Every table has a `<caption>`, `th scope="col"`, row headers where they name the row, and `aria-sort` on the sorted column (1.3.1).
- [ ] Lists are `<ul>`/`<ol>`; label and value pairs are `<dl>`.

**Keyboard and focus**
- [ ] Everything works with the keyboard alone, and there are no keyboard traps outside modals (2.1.1, 2.1.2).
- [ ] In-page tabs: roving tabindex; Left, Right, Home and End; RTL-aware.
- [ ] Modals and drawers trap focus, close with Esc and return focus to the element that opened them.
- [ ] A visible focus ring is on everything, 2 px with a 2 px offset, at least 3:1 against the surface (2.4.7).
- [ ] The sticky top bar and save bar never cover the focused element (`scroll-padding`) (2.4.11).
- [ ] Focus order follows the visual order, including in RTL (2.4.3).
- [ ] After a route change, focus moves to the `<h1>`; after a filter change, focus stays put; after a page change, focus goes to the table caption.

**Perception**
- [ ] Text 4.5:1 and UI parts 3:1, per the table in §6.1 (1.4.3, 1.4.11).
- [ ] No status is shown by colour alone: dot + text, badges with words, diff with +/− and hidden text (1.4.1).
- [ ] Reflow at 320 CSS px with no sideways scrolling, except inside the JSON and diff regions, which scroll in their own focusable box (1.4.10).
- [ ] Text resized to 200 % loses nothing; text spacing overrides clip nothing (1.4.4, 1.4.12).
- [ ] Windows High Contrast (`forced-colors`) keeps borders, focus and status text.
- [ ] Icons are `aria-hidden`; icon-only buttons have names (1.1.1, 4.1.2).

**Feedback**
- [ ] One polite and one assertive live region at the root. They announce result counts, saves, copies, sort changes and async results (4.1.3).
- [ ] Every async view has a skeleton (`aria-busy`), an inline error with **Try again**, and an empty state with one action.
- [ ] Errors say what happened and what to do; form errors are linked to their field with `aria-describedby` and focus moves to the first invalid field (3.3.1, 3.3.3).
- [ ] Toasts are never the only feedback.

**Forms and controls**
- [ ] Every control has a visible label (3.3.2). Placeholders are never labels.
- [ ] Targets are at least 24 × 24 CSS px, or spaced so a 24 px circle doesn't overlap a neighbour (2.5.8).
- [ ] No action needs dragging (2.5.7) or a precise gesture.
- [ ] Destructive actions confirm and name their result; initial focus is on Cancel (3.3.4 spirit).
- [ ] Choices you already made are remembered (client tab, filters in the URL) (3.3.7).
- [ ] No CAPTCHA or memory test; sign-in is WordPress's own (3.3.8).

**Consistency, time, motion**
- [ ] The navigation, footer links and Help card are in the same place and order on every page (3.2.3, 3.2.6).
- [ ] No content changes context on focus or input alone (3.2.1, 3.2.2): filters update results in place.
- [ ] The 15-minute approval expiry is shown plainly; it's an essential security limit (2.2.1).
- [ ] `prefers-reduced-motion` turns off shimmer, slides and spinners.
- [ ] Polling (registration, the Connect modal, site checks) pauses while the tab is hidden and never moves focus.

**Language**
- [ ] Every string goes through `@wordpress/i18n` with the `mcpmate` domain; plurals use `_n`, values use `sprintf` with translators comments, and nothing is concatenated.
- [ ] `lang` and `dir` come from WordPress (`SERVER_VARIABLES.lang`, `rtl`) (3.1.1).
- [ ] Uppercase comes from CSS only; no layout depends on English string lengths (labels can grow 40 %).

**Manual passes before release**
- [ ] VoiceOver + Safari (macOS), NVDA + Firefox (Windows), TalkBack + Chrome (Android, at 360 px).
- [ ] 200 % and 400 % browser zoom at 1280 × 800.
- [ ] One RTL locale (Arabic or Hebrew) with translations loaded.

---

## 10. Backend gaps

Every `[B<n>]` above. Each follows AGENTS.md: prefixed names, capability checks, migrations with a `Config::DB_VERSION` bump, admin-ajax routes with `->middleware('nonce', 'isAdmin')`, and tests (401, 403, not allowlisted, happy path) for REST routes. Rows marked **contract** change the REST contract and need the MCP server's `AGENTS.md` (and `connection-v2.md` where it lists signed routes) updated in the same change.

| # | What | Plugin work | Cloud (`mcp-server`) | Rules touched |
| --- | --- | --- | --- | --- |
| **B1** | **Tool catalog and per-tool switches** (Tools page, the "Tools turned on" tile, tool filters) | `Services/Tools/ToolCatalog` (pure, an allowlist): tool name, label, description, source, group, kind (read/write), approval-gated, route + method patterns, required capability, availability check (plugin active, `edit_themes`, Abilities API). Option `MCPMATE_tools` (map of name → bool, plus item lists for `run_wp_cli` and `run_ability`). A migration seeds it from today's switches and enable lists (§5.3). Enforcement in `Router::authorizeSigned()`: route → tool → off ⇒ 403 `mcpmate_tool_disabled` (hint: "A site admin turned this tool off in wp-admin."). Admin-ajax `tools/list`, `tools/update {changes}`. The old switch routes stay as adapters, so the setup wizard keeps working. A signed `GET /tools` (any grant, read) returns the enabled names. **Contract.** | Reads `GET /tools` when it builds a site's tool list (cached briefly) and sends `notifications/tools/list_changed` when it changes. `TOOLS` in `policy.ts` and the catalog must list the same names: add a shared test vector, like `connection-v2-vectors.json`. | Rule 1: new tools default to off. |
| **B2** | **Per-connection access levels** (Full / Read-only / Custom, Edit access) | Grants table: `tools` (`text NULL`; NULL = every tool that is on). `scopes` becomes editable. Admin-ajax `grants/update {grant_id, access, tools[]}`; it writes an audit row (`connection`, `grant_access`). Enforced in `authorizeSigned()` after B1. Migration. Syncs to the cloud like `RevocationSync` (signed `k_s2c`, hourly retry). **Contract.** | `POST /api/v1/sites/{site_id}/grants/{id}/access` to store scopes and tools, which then filter `tools/list`. | Rule 9: changed only by a site admin in wp-admin, never over REST. |
| **B3** | **Pause AI access** | Option `MCPMATE_access_paused` `{at, by}` (autoload on: it's read on every signed request). Checked in `Router::authorizeSigned()` for user grants ⇒ 403 `mcpmate_access_paused` (hint: "A site admin paused AI access in wp-admin."). The `site` grant (cloud revoke) still works. Admin-ajax `access/pause`, `access/resume`, both audited. `SERVER_VARIABLES.access`. Approvals stay decidable in wp-admin; their status polling is refused while paused. **Contract.** | Treat it as "not now": don't end the grant, don't retry; tell the user the site admin paused access. | Rule 7: 403, an authenticated request refused by policy. |
| **B4** | **Call log for reads, duration and MCP tool name** (tiles, recent activity, Duration column, tool filter) | Audit log columns `kind` (read/write), `duration_ms`, `mcp_tool` (from B1's route → tool map, never from a client header). Reads are logged for signed requests only, with args limited to query keys and short values, through `Logger::redact()`. They are controlled by "Also log read calls" and have their own retention (default 30 days). An index on `(created_at, kind)`. Admin-ajax `stats/summary {window}` → `{calls, reads, writes, ok, error, pending, success_rate, last_used: {grant_id, at}, changes_week}`. Migration. Update the AGENTS.md audit bullet ("reads are logged when …"). | `get_audit_log` gains `kind` in its output. | Rule 4: redaction. Known gap 5 remains (refused calls are not counted), so the tile says "Counts calls that reached a tool." |
| **B5** | **Audit log query additions** | `GET /audit-log` gains `search` (LIKE through `$wpdb->esc_like()`, on tool, action, target and message), `user_id`, `kind`, `mcp_tool`, `id`, `order_by` (`created_at`, `duration_ms`) and `order`. Each item gains `client_name` (joined from grants) and `change_id`. Arg schema and tests. **Contract** (additive). | Optional: expose the new filters in `get_audit_log`. | none |
| **B6** | **Result summary in the drawer** | Audit column `result_json` (≤ 4 KiB): HTTP status, error code, created or changed IDs, through `Logger::redact()`. No content bodies. | none | Rule 4 |
| **B7** | **Change history** | Table `{prefix}mcpmate_changes` (`id, audit_id, created_at, user_id, grant_id, tool, action create\|update\|trash\|delete, object_type, object_id, object_label, before_json, after_json, truncated, bytes`). `Services/ChangeHistory/Recorder`, called by the handlers that write: proxy post and page writes (title, content, excerpt, status, slug, parent, template, terms), Elementor saves (elements + page settings), theme draft file writes, replacements and deletions, media uploads (create only), CLI `option update` and `post meta update` (values masked through `DbInspect\Sensitive`), `plugin activate`/`deactivate`/`update`, `theme activate`, theme publish and rollback (theme and backup ID), and `search-replace` (summary only). Snapshots are capped at 512 KiB each. `ChangeDiff` uses WordPress's bundled `Text_Diff` to return `equal`/`add`/`remove` operations per field. Option `MCPMATE_change_history` (on). Admin-ajax `changes/list`, `changes/show`. It joins `Retention`. Migration; dropped on uninstall; registered with WordPress's personal data eraser and exporter (snapshots can hold personal data). Admin-ajax only: no REST route, so snapshots never reach the MCP surface. | none | Rule 3 (Delete only for draft files); rule 4 (masking) |
| **B8** | **Settings** | Option `MCPMATE_preferences` (not `settings`, which the 0.5.0 migration removed): `rate_limit` (10–1000, default 120), `approve_capability` (enum, default `manage_options`), `log_reads`, `audit_retention_days`, `read_retention_days`, `change_retention_days`, `delete_data_on_uninstall` (default true). Admin-ajax `preferences/show`, `preferences/update` (enum and range checks, unknown keys ignored). **Rate limit:** a per-grant counter per minute (`wp_cache_incr` with a persistent object cache, else a transient; an approximate count is fine) ⇒ 429 `mcpmate_rate_limited` + `Retry-After`, **contract**: add 429 to rule 7 in both AGENTS.md files. **Retention:** the `…_retention_days` filters receive the option value as their default. **Uninstall:** `onUninstall()` drops data only when `delete_data_on_uninstall` is true. **Approve capability:** needs D5 sign-off first. Then `Head::connectRequest()`, `connection/approve` and `connection/reject` check that capability, and a separate approval-only admin page reachable with it is added (the rest of the app stays `manage_options`). | Handles 429 (wait for `Retry-After`; no automatic retry for writes). | Rule 7 (429). Rule 9 (`approve_capability`): **needs sign-off.** |
| **B9** | **Remove old entries now** | Admin-ajax `audit-log/purge-preview` → `{count, older_than}` and `audit-log/purge` → `{removed}`. Both run `Retention`'s own query with the configured window; nothing else can be deleted. Writes an audit row (`audit_log`, `purge`, `{removed}`). Update AGENTS.md: "only `Retention` (daily cron, or a site admin running it early) removes rows". | none | none |
| **B10** | **Stored site checks** | `onboarding/checks` stores its last result in option `MCPMATE_last_checks` `{at, summary, checks}` (autoload off). New admin-ajax `onboarding/checks-last`. | none | none |
| **B11** | **Shell data** in `SERVER_VARIABLES` | `grants_live` (`Grants::countLive()`), `pending_approvals`, `access` (B3), `rtl` (`is_rtl()`), `timezone` (`wp_timezone_string()`), `cloud_url_source` (`constant` / `env` / `none`) next to `cloud_origin`, `links` (B14), `features` (`elementor`: `active` / `installed` / `missing`, `abilities_supported`, `can_edit_themes`, `file_edit_disallowed`), `wp_version`. Read-only, admin page only. | none | Rule 4: no secrets. |
| **B12** | **One menu item** | `Menu::getSideBarMenu()` returns the top-level item only, with the B15 icon. The body-class CSS from §3.4. | none | none |
| **B13** | **Integration detection** | `ToolCatalog` availability uses `get_plugins()` (with `wp-admin/includes/plugin.php`) to tell `missing`, `installed` and `active` apart. WooCommerce is listed only once it has tools (D12). | WooCommerce tools, if wanted (server open question). | Rule 1 |
| **B14** | **Help and footer links** | `Config` constants or `.env` keys: `DOCS_URL`, `SUPPORT_URL`, `REVIEW_URL` (the wordpress.org review page once the plugin is listed), `FEEDBACK_URL`. Empty ⇒ the link is hidden. Ties into known gap 39 (no support link). | none | none |
| **B15** | **Logo and menu icon** | An SVG mark (§6.8) in `frontend/src/resource/` and a data URI for the menu icon. | none | none |

Not gaps (they exist and are reused): MCP URL and copy, grants list, revoke and revoke all, the approval flow, environment checks, client steps (`aiClients.ts`), audit log filters by tool, result, grant and dates, and the setup wizard.

**Built (2026-10-26):** B1, B2, B3, B5, B7, B8's settings, B9, B4's read logging, and B11's `cloud_url_source`. Where the build differs from the table above (AGENTS.md has the details):

- **B1:** the catalog and its save are REST, `GET /tools` and `POST /tools {tools, items, options}`; the save takes the `wpAdmin` middleware, which refuses every signed request. There is no `tools/list` / `tools/update` admin-ajax pair. `GET /tools` answers any grant with the whole catalog plus its `access` and `allowed_tools`.
- **B2:** nothing is pushed to the cloud. The cloud reads the grant's access level and allowed tools from `GET /tools`, signed with that grant.
- **B3:** the pause is checked in `SignedAuth`, right after the signature and nonce verify and before the grant is looked at, not in `authorizeSigned()`: no user is ever set while paused, and an unsigned caller still gets 401.
- **B4:** `kind`, `mcp_tool` and `duration_ms` columns and read logging are built. The Dashboard numbers are REST, `GET /stats` (`wpAdmin`, cached 5 minutes), not admin-ajax `stats/summary`.
- **B7:** changes are captured from WordPress's hooks while a plugin write runs (posts, post meta, terms, term meta, options, users, user meta, comments, WooCommerce products and orders), not by each handler. Theme draft files, theme switches and `search-replace` are not captured (AGENTS.md known gap 45). The list filter for the action is `change_action`: admin-ajax keeps `action` for itself.
- **B5:** `GET /audit-log` takes `id`, `mcp_tool`, `kind`, `user_id`, `search` (≤ 100 characters; LIKE wildcards are literal) and `order_by` (`created_at`, `duration_ms`) + `order`; items carry `client_name`. There is no `change_id`: the drawer asks `changes/list` with `audit_id`. Additive; no MCP tool sends the new filters yet.
- **B9:** as specified. The preview also returns `days` and `older_than`. The audit row's args are `{removed, days, older_than}`.
- **B8:** no rate limit yet. `approve_capability` is validated, never below `publish_posts`, and stays locked to administrators (D5). `preferences/update` also refuses values that disagree (read calls kept longer than the audit log; change history on with no kinds), named on the key that changed.

Suggested order: B11, B12, B15, then the shell and redirects (no feature changes). Then B1 → Tools. Then B3 → pause. Then B4 + B5 + B6 → Activity and Dashboard. Then B7 → Change history. B8's rate limit and retention can go in any time; its approve capability waits for sign-off. B9, B10, B13 and B14 are small and independent.

---

## 11. Frontend build notes

- New: `components/ui/*` (§7), `components/AppShell`, `hooks/useAnnounce`, `hooks/useContainerWidth`, `config/tokens.ts`. Pages: `Dashboard`, `Connections` (merges `Connection` + `ConnectedApps`), `Tools`, `Activity` (`AuditLog`, `Changes`, `Approvals`), `Settings`. Today's `pages/Settings/*` cards are removed once Tools covers them; `FeatureSwitchRow` and `useFeatureSwitch` stay for setup.
- `AppRoutes.tsx`: the new routes and the redirects in §2. `utils/approvalLink.ts` targets `/activity/approvals/:id`.
- `tailwind.config.mjs`: `important: '#mcpmate-root'`; token colours from `tokens.ts`.
- No new runtime npm dependency. The diff comes from the server (B7); dates and numbers use `Intl`.
- Tests: each component's roles, names and keyboard behaviour; each page's four states; the redirects; the save bar; the Tools tri-state logic and the change it sends (pure, in `pages/Tools/logic.ts`).

---

## 12. Open questions

1. **D1:** is Approvals as a third tab under Activity right, or should it stay a separate page reached only from notices?
2. **D2:** write calls always logged, plus a switch for read calls. Is that acceptable in place of an audit log on/off switch?
3. **D5:** should lower roles ever approve AI apps? A grant acts as its approver, so an Author's app can only do what an Author can (rule 5 holds), but rule 9 and the approval page would change. Recommendation: ship locked to Administrators and decide later.
4. **Read logging volume (B4).** An AI app can make hundreds of reads a day. Is 30 days' retention for reads right, or should reads be aggregated per hour instead of stored per call (the Dashboard would then lose "recent activity" for reads)?
5. **Change history for posts (B7):** keep our own snapshots, or point at WordPress revisions (less storage, but nothing when `WP_POST_REVISIONS` is off, and they don't cover options or theme files)? Recommendation: our snapshots, plus a "Compare in WordPress" link when a revision exists.
6. **"Remove all data on uninstall": on by default** (today's behaviour) or off (keeps the audit log; also lets a reinstall at the same address keep its connection, which would ease known gap 39)?
7. **Tool switches and the cloud (B1):** should the cloud hide turned-off tools from `tools/list` (cleaner for the AI, needs `list_changed` support in each client), or list them and let the plugin's 403 explain? Recommendation: hide them, and keep the 403 as the guarantee.
8. **Rate limit default (B8):** 120 per minute per app. Does that sit comfortably under the cloud's own limits?
9. **Posts and pages group:** show it before any tool uses `POST /proxy` (server Q8), or hide it until a tool exists?
10. **Logo:** who designs the mark? §6.8 describes a placeholder brief.
11. **Dark mode:** wp-admin has none, so version 1 is light only. Add it later?
12. **Fonts:** keep loading Inter from Google Fonts (a request from wp-admin to Google, which matters under GDPR), bundle it, or use the system font stack? *Answered: bundled (`@fontsource-variable/inter`); no request leaves wp-admin for the font.*
