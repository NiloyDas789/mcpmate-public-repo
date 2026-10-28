---
name: safe-bulk-changes
description: Change many posts, options or database values at once (search-replace, SEO fields, settings) with a backup, a dry run, a one-item pilot, batches that stop on surprises, and a report.
---
# Safe bulk changes

Use this before any change that touches more than a handful of items: a `search-replace` across tables (an old domain, a renamed product, a phone number), SEO titles or descriptions on many posts, or a series of option updates. One wrong pattern across thousands of rows is hard to undo, so every step below is required.

## 1. Scope

- Write down exactly what changes: the old value, the new value, which tables or posts, and why.
- Count what is affected, using reads only: `run_wp_cli "post list …"` (page through with `--paged`), `run_wp_cli "post meta get <id> <key>"`, or `query_db` when a site admin enabled database inspection.
- Show the user the scope and the count, and get a clear yes before going on.

## 2. Backup

Ask the user to confirm they have a recent backup of the database: their host's backup, or a backup plugin such as UpdraftPlus or Duplicator. Wait for the answer. McpMate does not take database backups and does not install plugins.

## 3. Dry run

- `search-replace` is a dry run unless approved: `run_wp_cli {command: "search-replace 'https://old.example.com' 'https://new.example.com' wp_posts wp_postmeta --skip-columns=guid", dry_run: true}`. Show the user the counts for each table and column.
- Name only the tables that need the change (with the site's table prefix; `query_db {action: "tables"}` lists them). Never include the users tables.
- Make `old` specific: include the scheme and the whole domain or phrase. Never replace short or common strings such as `http`, a single word, or a bare number.
- Always skip the `guid` column; WordPress uses GUIDs as permanent ids.
- `post meta update` and `option update` take `dry_run: true` too. Use it on the first item to see the before and after.

## 4. Pilot

Apply the change to one item first (one post's meta, one option), or run the approved `search-replace` on the single most important table. Then check the result with `run_wp_cli "post get <id>"`, `get_page_html` or `elementor_get`. Continue only if it looks exactly as intended.

## 5. Apply in batches

- One command per call. Keep a running log in the conversation with the id, the old value → the new value, and ok or the error code.
- Stop at the first unexpected error and tell the user. Do not resend a call that failed with a 400, 401 or 403. Do not work around a `policy_refused`.
- Destructive commands (`search-replace`, plugin or theme changes) answer `pending_approval`: show the approval link, wait for the user to say it was approved, then `check_approval`. Never resend a pending command. Each command needs its own approval.
- Before a long batch, check `usage`. On `rate_limited`, wait the time it gives. On `quota_exceeded`, stop and tell the user.

## 6. Verify and report

- Re-read a sample: the first item, the last item and a few in between.
- `run_wp_cli "cache flush"` when the site uses an object cache, so visitors see the change.
- `get_audit_log` shows what the site recorded. Give the user a summary: what changed, how many items, anything skipped or failed, and how to undo it (the reverse `search-replace` works only when the new value appears nowhere else).

## Never

- Change `siteurl`, `home`, users, roles or plugin settings this way. McpMate refuses them; tell the user they belong in wp-admin.
- Publish, delete or empty the trash as part of a bulk change.
- Skip the backup question or the dry run, even when the user is in a hurry: say why they matter, and let the user decide whether to stop.
