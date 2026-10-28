<?php

namespace McpMate\Services\SiteContext;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Hooks\Hooks;
use McpMate\Services\AuditLog\Logger;

/**
 * Read-only summary of one post: type, status, template, builder, meta keys
 * (values cut to META_VALUE_LENGTH, secret-looking keys redacted) and, for
 * block content, the block tree (names and attributes only).
 */
final class PostStructure
{
    public const META_VALUE_LENGTH = 200;

    /** Non-public post types that are still page content. */
    private const EXTRA_POST_TYPES = ['wp_block', 'wp_template', 'wp_template_part'];

    /**
     * Post types this endpoint may read: public post types plus reusable
     * blocks and block-theme templates. Filter:
     * `MCPMATE_post_structure_post_types` (array of post type names).
     *
     * @return string[]
     */
    public static function allowedPostTypes(): array
    {
        $types = array_merge(array_values(get_post_types(['public' => true])), self::EXTRA_POST_TYPES);
        $types = Hooks::applyFilter(Config::withPrefix('post_structure_post_types'), $types);

        return is_array($types) ? array_values(array_filter($types, 'is_string')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(\WP_Post $post): array
    {
        $meta      = self::meta($post->ID);
        $hasBlocks = has_blocks($post->post_content);
        $tree      = $hasBlocks ? BlockTree::build(parse_blocks($post->post_content)) : null;

        $firstValues = [];
        foreach ($meta['raw'] as $key => $values) {
            $firstValues[$key] = isset($values[0]) ? (string) $values[0] : '';
        }

        return [
            'id'               => $post->ID,
            'type'             => $post->post_type,
            'status'           => $post->post_status,
            'title'            => get_the_title($post),
            'slug'             => $post->post_name,
            'parent'           => $post->post_parent,
            'template'         => (string) get_page_template_slug($post),
            'modified_gmt'     => $post->post_modified_gmt,
            'builder'          => Detector::postBuilder($firstValues, $tree['names'] ?? []),
            'editor'           => $hasBlocks ? 'block' : 'classic',
            'content_length'   => strlen($post->post_content),
            'meta'             => $meta['summary'],
            'blocks'           => $tree['blocks'] ?? null,
            'blocks_truncated' => $tree['truncated'] ?? false,
        ];
    }

    /**
     * @return array{raw: array<string, array<int, mixed>>, summary: array<int, array<string, mixed>>}
     */
    private static function meta(int $postId): array
    {
        // Raw (still serialized) strings: nothing is unserialized here.
        $raw = get_post_meta($postId);
        $raw = is_array($raw) ? $raw : [];
        ksort($raw, SORT_STRING);

        $summary = [];

        foreach ($raw as $key => $values) {
            $key    = (string) $key;
            $values = is_array($values) ? $values : [$values];
            $first  = isset($values[0]) ? (string) $values[0] : '';
            $secret = (bool) preg_match(Logger::SENSITIVE_KEY, $key);

            $summary[] = [
                'key'    => $key,
                'count'  => count($values),
                'length' => $secret ? null : strlen($first),
                'value'  => $secret ? Logger::REDACTED : BlockTree::clip($first, self::META_VALUE_LENGTH),
            ];
        }

        return ['raw' => $raw, 'summary' => $summary];
    }
}
