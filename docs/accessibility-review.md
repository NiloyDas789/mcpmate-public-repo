# Admin UI: accessibility and usability review

**Date:** 2026-10-27. **Scope:** every screen of the admin app (`docs/ui.md` §2): Dashboard, Connections, Tools (three tabs), Activity (Audit log, Change history, Approvals list and request), Settings, Setup, Connect, Not found. No features were added; the fixes below change markup, words and layout only.
**Standard:** WCAG 2.2 AA, plus `docs/ui.md` §4 and §9.

---

## 1. How it was checked

| Check | How | Where it lives |
| --- | --- | --- |
| axe-core | In Chromium, on every page and every state the fake site can produce: 0, 1 and 12 AI apps, paused, not connected, every request failing, every request hanging (loading), and each dialog, drawer and inline error open. 1280, 640, 360 and 320 px. | `pnpm test:a11y` (`tools/a11y-ui-kit.mjs`) |
| Keyboard only | Each page's main flows driven with Tab, Shift+Tab, arrows, Space, Enter and Esc only: pause and resume, edit access, revoke, connect an app, tool switches, filters, search, sort, pages, drawers, purge, settings save and leave, approve and reject. Every stop must show a 2 px ring; focus must never fall to `<body>`. | `pnpm test:a11y` |
| Screen reader | Not run with a real screen reader in this session (VoiceOver can't be driven here; NVDA needs Windows). What a screen reader depends on is asserted instead: accessible names, roles, `aria-current`, `aria-sort`, `aria-describedby`, focus targets and live-region announcements. §3 is the script for the manual pass. | `pnpm test:a11y`, Vitest |
| Contrast of all tokens | 48 foreground/background pairs from §6.1 computed from `config/tokens.ts`; axe also measures rendered text. | `config/tokens.contrast.test.ts` |
| 200 % zoom | 1280 px at 200 % = 640 px: axe, no sideways scroll, no clipped text. Text size 200 % at 1280 px (root font 32 px): no sideways scroll, no clipped text. | `pnpm test:a11y zoom` |
| Text spacing | WCAG 1.4.12 overrides (line height 1.5, letter 0.12 em, word 0.16 em, paragraph 2 em): nothing clipped. | `pnpm test:a11y zoom` |
| 360 / 320 px | axe and no sideways scroll on every page, drawers and filters open. | `pnpm test:a11y` |
| RTL | `dir="rtl"`, `lang="ar"`, wp-admin's RTL stylesheets: axe and no sideways scroll on every page at 1280 and 320 px; navigation runs right to left; drawers open from the left; Left arrow moves to the next tab. | `pnpm test:a11y rtl` |
| prefers-reduced-motion | Every page in its loading state: no animation that repeats or lasts longer than 50 ms. | `pnpm test:a11y motion` |
| Slow network | Every answer 1.5 s late: loading regions are `aria-busy`, buttons say what they are doing ("Pausing…", "Saving…", "Running…"), outcomes are announced. | `pnpm test:a11y slow` |
| Server errors | Every route failing: each view shows its error with **Try again**; a failed save or decision keeps the form and says why. | `pnpm test:a11y`, Vitest |

---

## 2. Findings

Severity: **High** fails WCAG 2.2 AA or stops a task; **Medium** misleads, adds steps, or misses a §9 rule without failing AA; **Low** is polish.

| # | Issue | Page | Severity | Fix | Status |
| --- | --- | --- | --- | --- | --- |
| 1 | Status tags ("Pending" gold, "Executed" green, Ant Design tag colours) fail text contrast (axe `color-contrast`). | Activity → Approvals, list and request | High | Status is a dot plus a word in token colours (`StatusText`). | Fixed |
| 2 | At 320–360 px the status filter pushes the page 43 px sideways, and the table is a scroll box the keyboard can't reach (axe `scrollable-region-focusable`). | Activity → Approvals | High | The shared `SegmentedControl` (wraps) and `Table` (cards below 720 px). | Fixed |
| 3 | Approve and Reject report their outcome, and any error, only in a toast that vanishes; the buttons disappear and focus falls to `<body>`. | Approval request | High | The outcome stays on the page and is announced (assertive when the run failed); a refused decision shows an inline alert; focus moves to the request's heading. | Fixed |
| 4 | Setup's "not started" step names are `#8c8f94` on white, 3.24:1 (axe `color-contrast`). | Setup | High | `#50575e` (7.33:1). Done and not started still differ by icon and by the visually hidden word. | Fixed |
| 5 | Times are raw strings ("2026-10-24 09:30:00 UTC"), unlike the site timezone used everywhere else; the expiry is a clock time only. | Approvals | Medium | Site timezone (`fullDateTime`, `shortDateTime`); "Expires in 11 min · <date and time>". | Fixed |
| 6 | Developer words: "AI client", "destructive operation", "Executed", "Pending", "Dry-run preview". | Approvals | Medium | Glossary words (§8.1): AI app, hard to undo, Run, Waiting for approval, Preview. | Fixed |
| 7 | Reject runs on one click, with no confirmation. | Approval request | Medium | "Reject this request?" `ConfirmDialog`, focus on Cancel. The Approve button names what runs: "Approve and run search-replace". | Fixed |
| 8 | No caption, unlabelled filter, filter not in the URL, a spinner with no text or `aria-busy`, an empty state with no next step. | Approvals | Medium | Caption with the timezone; "Show" radio group kept in `?status=`; skeleton rows; "Nothing waiting for you." + **View all requests**; the count is announced. | Fixed |
| 9 | After approving, the Approvals tab still says "1 waiting" and the shell still says "1 request is waiting for your approval." | Activity tabs, top notices | Medium | One pending-count query (`usePendingApprovalCount`) updated by the list and by each decision. | Fixed |
| 10 | No `<h1>` (axe `page-has-heading-one`). | Setup | Medium | "MCPMate setup" is the `<h1>`; each step stays an `<h2>`. | Fixed |
| 11 | At 200 % text size the Audit log table overflows 15 px sideways: container breakpoints were measured in px and ignored text size. | Activity, and every container-responsive part | Medium | `useContainerWidth` reports width at the default text size, so at 200 % text the table becomes cards and the grids stack, as at 200 % zoom. | Fixed |
| 12 | Copy without the Clipboard API (sites on plain http): at narrow widths the URL in its textarea is not selected, and "Press Ctrl+C" is only announced, never shown. | Dashboard, Connections (`CopyField`) | Medium | The textarea is selected too; the instruction is visible and linked with `aria-describedby`. | Fixed |
| 13 | Task 2: the Dashboard says "AI apps made 14 changes" and links to the Audit log, not to the changes. | Dashboard | Medium | **View change history** → `#/activity/changes`. | Fixed |
| 14 | Task 4: Integrations lists only Elementor. WooCommerce's tools are abilities, and nothing on the tab says so. | Tools → Integrations | Medium | One line: tools a plugin offers itself, such as WooCommerce, are under Abilities, with **Go to Abilities**. | Fixed |
| 15 | Task 5: Pause exists only on the Dashboard. Connections, where the status pill leads, offers only **Revoke all**, which makes every app reconnect. | Connections | Medium | Next to Revoke all and in its dialog: pause instead, with a link to the Dashboard. | Fixed |
| 16 | "Yesterday" takes Period → Custom dates → From → To, or scanning "Last 7 days" by date. | Change history | Low | A "Yesterday" / "Today" period preset. | Open |
| 17 | The change action reads "Trash"; the glossary says "Move to trash". | Change history | Low | Relabel. | Open |
| 18 | Ability groups use the raw namespace ("Abilities from woocommerce"). | Tools → Abilities | Low | Needs the plugin's name from the server. | Open |
| 19 | Skip setup uses an Ant Design `Popconfirm` (small popover, no focus trap). It works by keyboard and passes axe. | Setup | Low | `ConfirmDialog`. | Open |
| 20 | Connect still uses Ant Design `Typography` and `Alert`. It passes axe and the Tab walk. | Connect | Low | Move to the shared components with its next change. | Open |

Checked and passing with no change: every token pair (§6.1), RTL on every page, reduced motion, text spacing, focus rings, sticky bars never covering focus, and the Dashboard, Connections, Tools, Audit log, Change history and Settings flows by keyboard.

---

## 3. Screen reader script (VoiceOver + Safari, NVDA + Firefox)

Run it before a release (§9, "Manual passes"). Start each part on the MCPMate menu item in wp-admin. Write down what is read where it differs from "Expect".

| # | Do | Expect |
| --- | --- | --- |
| 1 | Open MCPMate. Press Tab once. | "Skip to MCPMate content, link". Enter moves into the page and reads "Dashboard, heading level 1". |
| 2 | List landmarks (VO: rotor; NVDA: D). | Navigation "MCPMate", main, footer (`contentinfo`); no unnamed regions. |
| 3 | Tab to the navigation. | "Dashboard, current page, link", then Connections, Tools, Activity, Settings. Then the pill: "1 AI app connected, link". |
| 4 | Dashboard: Tab to **Copy** under Your MCP URL; press it. | "Copy MCP URL, button"; then "MCP URL copied." without moving focus. On a plain-http site: "Press Ctrl+C (⌘C on Mac) to copy." and the field is selected. |
| 5 | Tab to **Pause AI access**; press it. | Dialog "Pause AI access?" is read with its text; focus on "Cancel, button". Shift+Tab, Enter: "AI access paused." The button is now "Resume AI access". |
| 6 | Connections: list tables (VO: rotor; NVDA: T). | "AI apps that can use this site, sorted by Last used, newest first". Headers read with each cell; "Last used, sorted newest first…" on the sort button. |
| 7 | Tab to **Edit access for Claude**; press it. | Dialog "Access for Claude"; focus on the checked level, "Full access, radio button, 1 of 3", with its hint. Down arrow: "Read-only". Tab to **Save access**, Enter: "Access for Claude saved." and focus returns to Edit access. |
| 8 | Tools: Tab to the tabs; press Right. | "Core, 11 of 20 on, tab, selected, 1 of 3", then "Integrations". On Integrations the line about WooCommerce and Abilities is read before the list. |
| 9 | Tab to a group checkbox (Theme drafts); press Space. | "Theme drafts, mixed checkbox" with "3 of 5 on · …" as its description; then "Theme drafts: 5 of 5 on. Saved." |
| 10 | Activity: Tab to Search; type "home". | After a pause: "N entries match." Focus stays in Search. |
| 11 | Tab to a **View** button; press it. | Dialog named after the entry ("elementor_save · 26 Oct…"); "Arguments, region". Esc: focus back on that View button. |
| 12 | Change history: open **View before and after**. | Removed and added lines read "Removed line:" and "Added line:"; "Show 10 unchanged lines, button, collapsed". |
| 13 | Approvals: arrow through **Show**. | "Waiting, radio button, 1 of 5, Show", then "4 requests." after Right arrow to All. |
| 14 | Open a waiting request. | "Approval request #12, heading level 2" has focus; then "Waiting for approval". The warning reads "Warning: An AI app is asking to do something that's hard to undo." and "Expires in 11 min · …". |
| 15 | Press **Reject request**, then Esc. Press **Approve and run search-replace**. | "Reject this request?" dialog, focus on Cancel; Esc returns to Reject request. After Approve: "Approved and run.", focus on the request heading, status "Run". |
| 16 | Settings: change "Keep read calls for" to 90 days with "Keep the audit log for" at 60; press **Save changes**. | "Fix 1 setting to save."; focus on "Keep read calls for", which reads its error as the description. |
| 17 | Setup (`?setup=1`). | "MCPMate setup, heading level 1"; "Setup progress, list, 6 items", the current one "current step". |
| 18 | Repeat 1–7 with the page zoomed to 200 % and in an RTL locale. | Same reading order; the Menu button reads "Menu, collapsed" below 600 px. |

---

## 4. Usability script: five tasks for a site owner

**Participant:** runs a WordPress site, has connected one AI app, is not a developer. **Setup:** a site with 3 AI apps (one is ChatGPT), WooCommerce active with abilities, a day of activity. Start each task on the WordPress Dashboard. Say: "Think aloud. There are no wrong answers; we're testing the plugin, not you." Don't point at the screen.

| # | Say this | Done when | Watch for |
| --- | --- | --- | --- |
| 1 | "You want to add your site to a new AI app. Copy the address you'd paste into it." | The MCP URL is on the clipboard. | Do they find MCPMate in the menu? Do they understand "MCP URL"? Do they trust that it isn't a password? |
| 2 | "Find out what the AI changed on your site yesterday." | They name at least one change from yesterday and what it was before. | Audit log vs Change history; the Period filter; whether they open **View before and after**. |
| 3 | "Make ChatGPT read-only, so it can look but not change anything." | ChatGPT shows "Read-only". | Do they look in Connections or Tools? Do they find **Edit access**? |
| 4 | "Stop AI apps from changing anything in WooCommerce, but let them still read it." | WooCommerce's write abilities are off; reads stay on. | Integrations vs Abilities; the **Write** filter; the group checkbox under a filter. |
| 5 | "Something looks wrong. Stop all AI apps right now, in a way you can undo later." | AI access is paused. | Do they reach for **Revoke all** instead? Do they understand Pause vs Revoke? |

Afterwards ask: "What was hardest?" and "What would you call 'Abilities'?"

### Walkthrough (reviewer, 2026-10-27, before the fixes)

| Task | Path | Steps | Notes |
| --- | --- | --- | --- |
| 1 | Dashboard → **Copy** in Your MCP URL | 2 | Clear. On a plain-http site Copy only selected the text, with no visible instruction; at narrow widths it selected nothing (#12). |
| 2 | Dashboard → "AI apps made 14 changes. **View activity**" → Audit log → **Change history** tab → Period → Custom dates → From → To | 7 | The link went to the Audit log (#13), an extra tab change. "Yesterday" needs two date fields (#16); scanning "Last 30 days" by date also works. After the fix: 2 steps to the list, 4 more to narrow it to yesterday. |
| 3 | Connections → **Edit access** (ChatGPT) → Read-only → **Save access** | 4 | Clear. "Read-only: Can only read. It can't change anything." says it all. |
| 4 | Tools → Integrations (no WooCommerce) → Abilities → Show **Write** → untick "Abilities from woocommerce" | 5 | Integrations is the obvious place and was a dead end (#14). The group checkbox acting on the filtered tools is not obvious, but the announcement ("…: 2 of 5 on. Saved.") confirms it. The group's name is the raw namespace (#18). |
| 5 | Dashboard → **Pause AI access** → **Pause AI access** in the dialog | 3 | Clear from the Dashboard. From Connections, where the status pill leads, only **Revoke all** was on offer (#15). |
