<?php

namespace MCPMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Operations\Cli\Command;
use MCPMate\Operations\Cli\CommandException;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\ParsedCommand;

/**
 * `term list|get`. MCPMate Pro adds `term create|update`
 * (TermWriteCommands), which use taxonomy(), term() and describe(); terms
 * are never deleted (rule 3).
 *
 * Reads: a taxonomy visitors can see (is_taxonomy_viewable()), or one
 * whose terms the user may manage. Writes: a taxonomy with an admin UI that
 * is not in EXCLUDED (menus have their own commands; the others are
 * WordPress's bookkeeping), and the taxonomy's own capability.
 */
final class TermCommands extends CommandGroup
{
    public const FIELDS = ['term_id', 'name', 'slug', 'taxonomy', 'description', 'parent', 'count', 'url'];

    public const LIST_FIELDS = ['term_id', 'name', 'slug', 'parent', 'count'];

    /** Never written through `term create|update`. */
    public const EXCLUDED = ['nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area'];

    public const TAXONOMY_ARG = ['name' => 'taxonomy', 'required' => true, 'max_length' => 32, 'pattern' => '/^[a-z0-9_-]+$/D'];

    /** A term ID, or a slug with --by=slug. */
    public const TERM_ARG = ['name' => 'term', 'required' => true, 'max_length' => 200, 'pattern' => '/^[\p{L}\p{N}_.%-]+$/uD'];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('term list', [
            'summary'    => __('List the terms of one taxonomy (categories, tags, product categories…).', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'edit_posts',
            'args'       => [self::TAXONOMY_ARG],
            'flags'      => [
                'search'     => ['max_length' => 200],
                'parent'     => ['type' => 'integer', 'minimum' => 0],
                'hide_empty' => ['type' => 'boolean'],
                'orderby'    => ['enum' => ['name', 'slug', 'term_id', 'count'], 'default' => 'name'],
                'order'      => ['enum' => ['ASC', 'DESC', 'asc', 'desc'], 'default' => 'ASC'],
                'number'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 50],
                'paged'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                'fields'     => self::fieldsFlag(self::FIELDS),
            ],
            'target'     => ['taxonomy', 'taxonomy'],
            'handler'    => [self::class, 'list'],
        ]);

        $registry->add('term get', [
            'summary'    => __('Show one term by ID or slug.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'edit_posts',
            'args'       => [self::TAXONOMY_ARG, self::TERM_ARG],
            'flags'      => [
                'by'     => ['enum' => ['id', 'slug'], 'default' => 'id'],
                'fields' => self::fieldsFlag(self::FIELDS),
            ],
            'target'     => ['term', 'term'],
            'handler'    => [self::class, 'get'],
        ]);
    }

    // ──────────────────────────────────────────────
    //  Handlers
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        $taxonomy = self::taxonomy((string) $command->arg('taxonomy'), false);
        $number   = (int) $command->flag('number');
        $paged    = (int) $command->flag('paged');

        $args = [
            'taxonomy'   => $taxonomy->name,
            'hide_empty' => (bool) $command->flag('hide_empty', false),
            'orderby'    => (string) $command->flag('orderby'),
            'order'      => strtoupper((string) $command->flag('order')),
            'search'     => (string) $command->flag('search', ''),
        ];

        if ($command->hasFlag('parent')) {
            $args['parent'] = (int) $command->flag('parent');
        }

        $total = (int) wp_count_terms($args);
        $terms = get_terms($args + ['number' => $number, 'offset' => ($paged - 1) * $number]);
        $items = [];

        foreach (is_array($terms) ? $terms : [] as $term) {
            $items[] = self::describe($term);
        }

        return [
            'taxonomy'    => $taxonomy->name,
            'items'       => self::pickFields($items, $command->flag('fields') ?? self::LIST_FIELDS),
            'total'       => $total,
            'page'        => $paged,
            'per_page'    => $number,
            'total_pages' => (int) ceil($total / $number),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(ParsedCommand $command, bool $dryRun): array
    {
        $taxonomy = self::taxonomy((string) $command->arg('taxonomy'), false);
        $term     = self::term($taxonomy, (string) $command->arg('term'), (string) $command->flag('by'));

        return self::pickFields([self::describe($term)], $command->flag('fields'))[0];
    }

    // ──────────────────────────────────────────────
    //  Helpers (also MCPMate Pro's TermWriteCommands)
    // ──────────────────────────────────────────────

    /**
     * A taxonomy the current user may read ($write false) or write terms in.
     *
     * @throws CommandException 404 unknown, 400 not writable here, 403 not the user's to see.
     */
    public static function taxonomy(string $name, bool $write): \WP_Taxonomy
    {
        $taxonomy = get_taxonomy($name);

        if (!$taxonomy instanceof \WP_Taxonomy) {
            throw self::notFound(
                /* translators: %s: taxonomy name. */
                sprintf(esc_html__('Taxonomy "%s" does not exist.', 'mcpmate'), esc_html($name)),
                esc_html__('Run "taxonomy list" to see the taxonomies.', 'mcpmate')
            );
        }

        if ($write) {
            if (in_array($taxonomy->name, self::EXCLUDED, true) || !$taxonomy->show_ui) {
                throw self::notAllowed(
                    /* translators: %s: taxonomy name. */
                    sprintf(esc_html__('Terms of "%s" cannot be changed through MCPMate.', 'mcpmate'), esc_html($taxonomy->name)),
                    $taxonomy->name === 'nav_menu' ? esc_html__('Use the "menu item" commands for menus.', 'mcpmate') : ''
                );
            }

            return $taxonomy;
        }

        if (!is_taxonomy_viewable($taxonomy)) {
            self::authorize($taxonomy->cap->manage_terms);
        }

        return $taxonomy;
    }

    /**
     * A term of $taxonomy by ID (`$by` id) or slug.
     *
     * @throws CommandException 400 for an ID that is not a number, 404 when there is no such term.
     */
    public static function term(\WP_Taxonomy $taxonomy, string $value, string $by): \WP_Term
    {
        if ($by === 'id' && !preg_match('/^[1-9]\d{0,19}$/D', $value)) {
            throw self::notAllowed(
                /* translators: %s: the value given. */
                sprintf(esc_html__('"%s" is not a term ID.', 'mcpmate'), esc_html($value)),
                esc_html__('Give the numeric ID, or the slug with --by=slug.', 'mcpmate')
            );
        }

        $term = $by === 'id' ? get_term((int) $value, $taxonomy->name) : get_term_by('slug', $value, $taxonomy->name);

        if (!$term instanceof \WP_Term) {
            throw self::notFound(
                /* translators: 1: term ID or slug, 2: taxonomy name. */
                sprintf(esc_html__('There is no term "%1$s" in "%2$s".', 'mcpmate'), esc_html($value), esc_html($taxonomy->name)),
                /* translators: %s: taxonomy name. */
                sprintf(esc_html__('Run "term list %s" to see its terms.', 'mcpmate'), esc_html($taxonomy->name))
            );
        }

        return $term;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(\WP_Term $term): array
    {
        $taxonomy = get_taxonomy($term->taxonomy);
        $link     = $taxonomy instanceof \WP_Taxonomy && is_taxonomy_viewable($taxonomy) ? get_term_link($term) : null;

        return [
            'term_id'     => (int) $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'taxonomy'    => $term->taxonomy,
            'description' => $term->description,
            'parent'      => (int) $term->parent,
            'count'       => (int) $term->count,
            'url'         => is_string($link) ? $link : null,
        ];
    }
}
