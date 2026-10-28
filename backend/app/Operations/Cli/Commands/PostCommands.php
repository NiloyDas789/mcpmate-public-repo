<?php

namespace McpMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Hooks\Hooks;
use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\ParsedCommand;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\SearchReplace\Serialized;
use McpMate\Services\SiteContext\PostStructure;

/**
 * `post list|get`, `post meta get|update`.
 *
 * Only PostStructure::allowedPostTypes() are visible. Every post-level call
 * re-checks `edit_post` on that post. `post_password` is never returned.
 *
 * `post meta update` writes only META_WRITABLE keys (SEO titles and
 * descriptions; filter `MCPMATE_cli_post_meta_allowlist`). `post meta
 * get` reads any key, but values of secret-looking keys (Logger::SENSITIVE_KEY)
 * are redacted unless the key is writable.
 */
final class PostCommands extends CommandGroup
{
    public const FIELDS = [
        'ID', 'post_title', 'post_name', 'post_type', 'post_status', 'post_author', 'post_date', 'post_date_gmt',
        'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'post_excerpt', 'post_content',
        'comment_status', 'ping_status', 'url',
    ];

    public const LIST_FIELDS = ['ID', 'post_title', 'post_name', 'post_type', 'post_status', 'post_date', 'post_modified'];

    public const META_WRITABLE = [
        '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw',
        'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
        '_seopress_titles_title', '_seopress_titles_desc',
    ];

    private const ID_ARG = ['name' => 'id', 'type' => 'integer', 'required' => true, 'minimum' => 1];

    private const META_KEY_ARG = ['name' => 'key', 'required' => true, 'max_length' => 255, 'pattern' => '/^[A-Za-z0-9_.:-]+$/D'];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('post list', [
            'summary'    => __('List posts of one post type.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'edit_posts',
            'flags'      => [
                'post_type'      => ['max_length' => 20, 'pattern' => '/^[a-z0-9_-]+$/D', 'default' => 'post'],
                'post_status'    => ['max_length' => 20, 'pattern' => '/^[a-z0-9_-]+$/D', 'default' => 'any'],
                's'              => ['max_length' => 200],
                'orderby'        => ['enum' => ['date', 'modified', 'title', 'ID', 'menu_order'], 'default' => 'date'],
                'order'          => ['enum' => ['ASC', 'DESC', 'asc', 'desc'], 'default' => 'DESC'],
                'posts_per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 20],
                'paged'          => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                'fields'         => self::fieldsFlag(self::FIELDS),
            ],
            'handler'    => [self::class, 'list'],
        ]);

        $registry->add('post get', [
            'summary'    => __('Show one post, including its content.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'edit_posts',
            'args'       => [self::ID_ARG],
            'flags'      => [
                'fields' => self::fieldsFlag(self::FIELDS),
            ],
            'target'     => ['post', 'id'],
            'handler'    => [self::class, 'get'],
        ]);

        $registry->add('post meta get', [
            'summary'    => __('Read one custom field of a post.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'edit_posts',
            'args'       => [self::ID_ARG, self::META_KEY_ARG],
            'target'     => ['post', 'id'],
            'handler'    => [self::class, 'metaGet'],
        ]);

        $registry->add('post meta update', [
            'summary'          => __('Change one allowlisted custom field of a post (SEO title, description, focus keyword).', 'mcpmate'),
            'risk'             => Command::RISK_WRITE,
            'capability'       => 'edit_posts',
            'supports_dry_run' => true,
            'args'             => [self::ID_ARG, self::META_KEY_ARG, ['name' => 'value', 'required' => true, 'max_length' => 1000]],
            'target'           => ['post', 'id'],
            'handler'          => [self::class, 'metaUpdate'],
        ]);
    }

    /**
     * Meta keys `post meta update` may write.
     *
     * @return string[]
     */
    public static function writableMetaKeys(): array
    {
        $keys = Hooks::applyFilter(Config::withPrefix('cli_post_meta_allowlist'), self::META_WRITABLE);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    // ──────────────────────────────────────────────
    //  Handlers
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        $type = (string) $command->flag('post_type');
        if (!in_array($type, PostStructure::allowedPostTypes(), true)) {
            throw self::notAllowed(
                /* translators: %s: post type name. */
                sprintf(esc_html__('Post type "%s" is not available.', 'mcpmate'), esc_html($type)),
                /* translators: %s: comma-separated post type names. */
                sprintf(esc_html__('Allowed post types: %s.', 'mcpmate'), esc_html(implode(', ', PostStructure::allowedPostTypes())))
            );
        }

        $status = (string) $command->flag('post_status');
        if ($status !== 'any' && !in_array($status, get_post_stati(), true)) {
            throw self::notFound(
                /* translators: %s: post status name. */
                sprintf(esc_html__('Post status "%s" does not exist.', 'mcpmate'), esc_html($status))
            );
        }

        $perPage = (int) $command->flag('posts_per_page');
        $paged   = (int) $command->flag('paged');

        $query = new \WP_Query([
            'post_type'           => $type,
            'post_status'         => $status,
            's'                   => (string) $command->flag('s', ''),
            'orderby'             => $command->flag('orderby'),
            'order'               => strtoupper((string) $command->flag('order')),
            'posts_per_page'      => $perPage,
            'paged'               => $paged,
            'perm'                => 'editable',
            'ignore_sticky_posts' => true,
            'suppress_filters'    => false,
        ]);

        $items = [];
        foreach ($query->posts as $post) {
            if ($post instanceof \WP_Post && current_user_can('edit_post', $post->ID)) {
                $items[] = self::describe($post);
            }
        }

        $total = (int) $query->found_posts;

        return [
            'items'       => self::pickFields($items, $command->flag('fields') ?? self::LIST_FIELDS),
            'total'       => $total,
            'page'        => $paged,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(ParsedCommand $command, bool $dryRun): array
    {
        $post = self::post((int) $command->arg('id'));
        $item = self::pickFields([self::describe($post)], $command->flag('fields'));

        return $item[0];
    }

    /**
     * Values are read without unserializing objects: serialized values are
     * decoded with objects left as `__PHP_Incomplete_Class` stubs.
     *
     * @return array<string, mixed>
     */
    public static function metaGet(ParsedCommand $command, bool $dryRun): array
    {
        $post = self::post((int) $command->arg('id'));
        $key  = (string) $command->arg('key');

        // Without a key, core returns raw (still serialized) strings.
        $all = get_post_meta($post->ID);
        if (!is_array($all) || !array_key_exists($key, $all)) {
            throw self::notFound(
                /* translators: 1: meta key, 2: post ID. */
                sprintf(esc_html__('Post %2$d has no custom field "%1$s".', 'mcpmate'), esc_html($key), (int) $post->ID)
            );
        }

        $raw = (string) ($all[$key][0] ?? '');

        if (preg_match(Logger::SENSITIVE_KEY, $key) && !in_array($key, self::writableMetaKeys(), true)) {
            return ['post_id' => $post->ID, 'key' => $key, 'value' => Logger::REDACTED, 'count' => count($all[$key]), 'redacted' => true];
        }

        $value = $raw;
        if (Serialized::isSerialized($raw)) {
            $decoded = Serialized::decode($raw);
            $value   = $decoded['ok'] ? $decoded['value'] : $raw;
        }

        return ['post_id' => $post->ID, 'key' => $key, 'value' => $value, 'count' => count($all[$key]), 'redacted' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public static function metaUpdate(ParsedCommand $command, bool $dryRun): array
    {
        $post = self::post((int) $command->arg('id'));
        $key  = (string) $command->arg('key');

        if (!in_array($key, self::writableMetaKeys(), true)) {
            throw self::notAllowed(
                /* translators: %s: meta key. */
                sprintf(esc_html__('Custom field "%s" cannot be changed through McpMate.', 'mcpmate'), esc_html($key)),
                /* translators: %s: comma-separated meta keys. */
                sprintf(esc_html__('Allowed custom fields: %s.', 'mcpmate'), esc_html(implode(', ', self::writableMetaKeys())))
            );
        }

        $old  = get_post_meta($post->ID, $key, true);
        $new  = sanitize_text_field((string) $command->arg('value'));

        $result = [
            'post_id' => $post->ID,
            'key'     => $key,
            'old'     => is_scalar($old) ? (string) $old : null,
            'new'     => $new,
            'changed' => !is_scalar($old) || (string) $old !== $new,
        ];

        if ($dryRun || !$result['changed']) {
            return $result;
        }

        // update_post_meta() unslashes its input.
        update_post_meta($post->ID, $key, wp_slash($new));

        return $result;
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Load a post the current user may edit, of an allowed type.
     */
    private static function post(int $id): \WP_Post
    {
        $post = get_post($id);

        if (!$post instanceof \WP_Post) {
            /* translators: %d: post ID. */
            throw self::notFound(sprintf(esc_html__('Post %d does not exist.', 'mcpmate'), (int) $id));
        }

        self::authorize('edit_post', $post->ID);

        if (!in_array($post->post_type, PostStructure::allowedPostTypes(), true)) {
            throw self::notAllowed(
                /* translators: %s: post type name. */
                sprintf(esc_html__('Post type "%s" is not available.', 'mcpmate'), esc_html($post->post_type))
            );
        }

        return $post;
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(\WP_Post $post): array
    {
        return [
            'ID'                => $post->ID,
            'post_title'        => $post->post_title,
            'post_name'         => $post->post_name,
            'post_type'         => $post->post_type,
            'post_status'       => $post->post_status,
            'post_author'       => (int) $post->post_author,
            'post_date'         => $post->post_date,
            'post_date_gmt'     => $post->post_date_gmt,
            'post_modified'     => $post->post_modified,
            'post_modified_gmt' => $post->post_modified_gmt,
            'post_parent'       => $post->post_parent,
            'menu_order'        => $post->menu_order,
            'post_excerpt'      => $post->post_excerpt,
            'post_content'      => $post->post_content,
            'comment_status'    => $post->comment_status,
            'ping_status'       => $post->ping_status,
            'url'               => get_permalink($post),
        ];
    }
}
