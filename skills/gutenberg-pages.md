---
name: gutenberg-pages
description: Write block editor (Gutenberg) page content: blocks the site has, markup that survives the editor, checked with validate_blocks before anything is saved.
---
# Gutenberg pages

Use this to write page or post content for the block editor, or block markup for a block theme's templates. If `site_info` shows the page is built with Elementor, use `elementor-pages` instead.

## Where the content can go

McpMate has no tool that creates or edits posts and pages directly yet. Pick the first of these that fits, and tell the user which one you are using:

1. **An ability.** `list_abilities {search: "post"}` (or "page"). If a site admin enabled an ability that creates or updates a post, and its input schema takes a status, use `run_ability` and set the status to `draft`. McpMate checks every piece of block markup in the input before the ability runs.
2. **A block theme template or part.** Template files are theme work: follow `theme-building`. `write_theme_file` checks the markup of `.html` files.
3. **Hand it to the user.** Give the checked markup in one code block. They paste it into a new draft: open the editor, choose ⋮ → Code editor, paste, then Exit code editor and save as a draft.

## Steps

1. `list_block_types {namespace: "core"}`, plus `{search}` for anything specific (a gallery, a plugin's blocks). Use only blocks the site lists. Blocks registered only in the editor's JavaScript are not listed, so do not use them.
2. Write the markup in the exact form WordPress saves. The editor flags any other inner HTML as "unexpected or invalid content":
   - Paragraph: `<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->`
   - Heading: `<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->` (level 2 is the default and may be left out; one level 1 heading per page at most, and usually the theme prints the title as that one)
   - Group: `<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">…</div><!-- /wp:group -->`
   - Columns: `<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">…</div><!-- /wp:column --></div><!-- /wp:columns -->`
   - Buttons: `<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact us</a></div><!-- /wp:button --></div><!-- /wp:buttons -->`
   - Image (after `upload_media`, with its id and url): `<!-- wp:image {"id":123,"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="URL" alt="ALT" class="wp-image-123"/></figure><!-- /wp:image -->`
   - Dynamic blocks (latest posts, navigation, query loops…) self-close with attributes only: `<!-- wp:latest-posts {"postsToShow":3} /-->`.
3. Leave colours, spacing and fonts to the theme. Add a style attribute only when the user asks for one, and then use the matching class and inline style exactly as the editor writes them. When unsure, leave the style out.
4. `validate_blocks {content}`. Fix every error (each one names its line and block path), then validate again. Read the warnings too: an attribute the block does not define usually means a typo.
5. Deliver by the route you picked above, as a draft. Never publish.

## Writing the page

- Use the user's real copy. Do not invent facts, prices, reviews or statistics; mark gaps with visible placeholders such as `[Your phone number]` and list them at the end.
- Keep headings in order (h2 sections, h3 inside them), give every image alt text that describes it, and write link text that makes sense on its own (not "click here").
- Start each section with a group block, so the user can move and restyle sections in the editor.

## Done when

- `validate_blocks` reports no errors.
- The content is saved as a draft, or is in the user's hands with paste steps.
- The placeholders are listed.
