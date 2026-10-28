---
name: theme-building
description: Build or restyle a classic PHP theme with Tailwind CSS in a draft copy of the active theme, preview it, and publish only when the user says so (approved by a site admin).
---
# Theme building (classic PHP + Tailwind, draft first)

Use this for a new theme design, a restyle, or changes to theme templates (header, footer, page layouts). The work always happens in a draft: a copy of the active theme. The live site does not change until the user asks to publish and a site admin approves.

## Before you start

1. `site_info`: note the active theme, whether it is a child theme, and the page builders in use. Pages built with Elementor keep their own layout, so only the header, footer and templates around them change.
2. Check whether the active theme is a block theme: after step 1 of Build, `list_theme_files` shows a `templates/index.html`. McpMate cannot delete files, so a draft of a block theme stays a block theme. In that case, either build in its `.html` templates (block markup, see `gutenberg-pages`), or ask the user to activate a classic starter theme first and start again.
3. Agree on the design with the user: pages, sections, colours, fonts and logo. Do not invent business facts; use visible placeholders.

## Build

1. `create_draft_theme` returns a `draft_id`. Keep using that one draft for the whole job; a site holds at most 5.
2. `list_theme_files`, then `read_theme_file` for `style.css`, `functions.php`, `header.php` and `footer.php`, to see what the copy already has.
3. Write the theme with `write_theme_file` (whole files) and `edit_theme_file` (one exact change in a file):
   - `style.css` keeps its header comment with `Theme Name:`. Add your own `Version`, `Text Domain` and `Description`.
   - `functions.php`: `add_theme_support('title-tag')`, `'post-thumbnails'`, `'html5'` and `'custom-logo'`; `register_nav_menus()`; enqueue styles and scripts with `wp_enqueue_style()` and `wp_enqueue_script()`. Prefix every function with the theme slug. Never print output, and leave out the closing `?>`.
   - `header.php` has the doctype, `<html <?php language_attributes(); ?>>`, `wp_head()`, `<body <?php body_class(); ?>>`, `wp_body_open()`, a skip link and `wp_nav_menu()`. `footer.php` calls `wp_footer()` just before `</body>`.
   - Templates: `index.php` (required, with the loop), then as needed `front-page.php`, `page.php`, `single.php`, `archive.php`, `search.php` and `404.php`, with shared pieces in `template-parts/` loaded by `get_template_part()`. Templates copied from the old theme still win in the template hierarchy until you overwrite them, so overwrite every one the old theme had that your design replaces.
   - Escape everything you print: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`. Wrap text in translation functions with the theme's text domain.
   - PHP is linted when it is saved. A failing file is not saved, and the error names the line: fix it and write again.
4. Tailwind CSS. The site cannot run a build step, and neither can you.
   - For the draft and its previews, enqueue Tailwind's browser build from a CDN, pinned to an exact version (for example `https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.11`), from `functions.php`. Put brand colours and fonts in a `<style type="text/tailwindcss">@theme { … }</style>` block printed in `wp_head`.
   - Tailwind's reset removes the default styles of post content. Style headings, lists, links, images and blockquotes inside `.entry-content` (the wrapper around `the_content()`) in `style.css`.
   - Before publishing, tell the user the browser build is meant for prototyping: it loads a script from a CDN and builds the CSS in every visitor's browser. The production step is to compile `assets/css/app.css` with the Tailwind CLI on their computer, then swap the enqueue. Offer to write `assets/css/input.css` (`@import "tailwindcss";` plus `@source` lines for the theme's PHP files) for that.
5. No remote calls in PHP, no tracking or analytics scripts, and no `eval`. Use no third-party code the user did not ask for.

## Preview and iterate

1. `get_preview_url {draft_id, path: "/"}`, and one link for each page type that changed (`"/about/"`, a post as `"/?p=ID"`). Each link shows one page with the draft theme. Links on that page go to the live theme.
2. Give the links to the user only. Anyone holding one can see the draft until it expires.
3. Make the changes they ask for, and preview again.

## Publish (only when the user explicitly says publish, go live or ship it)

1. Ask the user to confirm they have a recent backup of the site (their host's backup, or a backup plugin). McpMate zips the live theme before switching, but it does not back up the database or the uploads.
2. `publish_draft_theme {draft_id, dry_run: true}` and show the user what would change.
3. `publish_draft_theme {draft_id}` answers `pending_approval`. Show the approval link, wait for the user to say a site admin approved it, then `check_approval`. Never resend a pending request.
4. If something is wrong after the switch, `rollback_theme` (dry run first; it needs an approval too) restores the previous theme.

## Done when

- Every changed page type was previewed, and the user reviewed the links.
- The live theme changed only through an approved publish.
- The user knows how to compile the Tailwind CSS for production.
