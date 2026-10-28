<?php

namespace McpMate\Services\ChangeHistory;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\DbInspect\Sensitive;

/**
 * Snapshots of one object as it is now, for change history: the fields a
 * site owner recognises, as a flat array of field => value (null when the
 * object does not exist). Never a password, activation key or session
 * token; a value under a secret-looking key (Sensitive) is masked, and
 * nested secret keys are redacted (Logger::redact()).
 */
final class ChangeSnapshots
{
    public const MASKED = '[masked]';

    /** WooCommerce post types: their snapshot is the CRUD object's data. */
    private const PRODUCT_POST_TYPES = ['product', 'product_variation'];

    private const ORDER_POST_TYPES = ['shop_order', 'shop_order_refund', 'shop_order_placehold'];

    /** Bookkeeping WordPress writes on its own. */
    private const IGNORED_POST_TYPES = ['revision', 'customize_changeset', 'oembed_cache', 'user_request'];

    private const IGNORED_META_KEYS = ['_edit_lock', '_edit_last', '_encloseme', '_pingme', 'session_tokens', '_application_passwords'];

    private const IGNORED_TAXONOMIES = ['product_type', 'product_visibility'];

    /** Options WordPress rewrites as bookkeeping (exact names). */
    private const IGNORED_OPTIONS = ['cron', 'rewrite_rules', 'doing_cron', 'recently_edited', 'can_compress_scripts', 'auto_updater.lock', 'core_updater.lock'];

    /** Option name prefixes never captured: caches, and this plugin's own options (the sealed signing secret is one). */
    private const IGNORED_OPTION_PREFIXES = ['_transient_', '_site_transient_', Config::VAR_PREFIX];

    /**
     * Whether $type $id ($key) is worth a row at all.
     */
    public static function isTracked(string $type, int $id, string $key): bool
    {
        switch ($type) {
            case 'post':
                $post = get_post($id);

                return $post instanceof \WP_Post && !in_array($post->post_type, self::IGNORED_POST_TYPES, true);
            case 'post_meta':
            case 'term_meta':
            case 'user_meta':
                return !in_array($key, self::IGNORED_META_KEYS, true) && !str_starts_with($key, '_transient');
            case 'term':
                $term = get_term($id);

                return !($term instanceof \WP_Term) || !in_array($term->taxonomy, self::IGNORED_TAXONOMIES, true);
            case 'option':
                if (in_array($key, self::IGNORED_OPTIONS, true)) {
                    return false;
                }

                foreach (self::IGNORED_OPTION_PREFIXES as $prefix) {
                    if (stripos($key, $prefix) === 0) {
                        return false;
                    }
                }

                // `{taxonomy}_children`: a term hierarchy cache core rebuilds on any term change.
                return !(str_ends_with($key, '_children') && taxonomy_exists((string) substr($key, 0, -9)));
        }

        return true;
    }

    /**
     * `post`, or `product` / `order` for WooCommerce's post types.
     */
    public static function typeOfPost(int $postId): string
    {
        $type = (string) get_post_type($postId);

        if (in_array($type, self::PRODUCT_POST_TYPES, true)) {
            return 'product';
        }

        return in_array($type, self::ORDER_POST_TYPES, true) ? 'order' : 'post';
    }

    /**
     * The user meta that holds roles (`{prefix}capabilities`, `{prefix}user_level`).
     */
    public static function isRoleKey(string $key): bool
    {
        global $wpdb;

        return in_array($key, [$wpdb->get_blog_prefix() . 'capabilities', $wpdb->get_blog_prefix() . 'user_level'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function take(string $type, int $id, string $key = '')
    {
        switch ($type) {
            case 'post':
                return self::post($id);
            case 'post_meta':
            case 'term_meta':
            case 'user_meta':
                return self::meta((string) substr($type, 0, -5), $id, $key);
            case 'term':
                return self::term($id);
            case 'option':
                return self::option($key);
            case 'user':
                return self::user($id);
            case 'comment':
                return self::comment($id);
            case 'product':
                return self::wooObject(function_exists('wc_get_product') ? wc_get_product($id) : null);
            case 'order':
                return self::wooObject(function_exists('wc_get_order') ? wc_get_order($id) : null);
        }

        return null;
    }

    /**
     * A readable name for the change row: `Page "Home"`, `blogdescription`.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function label(string $type, int $id, string $key, ?array $before, ?array $after): string
    {
        $snapshot = $after ?? $before ?? [];

        switch ($type) {
            case 'post':
                return sprintf('%s "%s"', (string) ($snapshot['type'] ?? 'post'), (string) ($snapshot['title'] ?? ''));
            case 'term':
                return sprintf('%s "%s"', (string) ($snapshot['taxonomy'] ?? 'term'), (string) ($snapshot['name'] ?? ''));
            case 'user':
                return sprintf('user "%s"', (string) ($snapshot['login'] ?? ''));
            case 'comment':
                return sprintf('comment by "%s"', (string) ($snapshot['author'] ?? ''));
            case 'product':
            case 'order':
                return sprintf('%s "%s"', $type, (string) ($snapshot['name'] ?? $snapshot['number'] ?? $id));
            case 'option':
                return $key;
        }

        return $key;
    }

    // ──────────────────────────────────────────────
    //  Per type
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private static function post(int $id): ?array
    {
        $post = get_post($id);

        if (!$post instanceof \WP_Post) {
            return null;
        }

        $terms = [];
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            if (in_array($taxonomy, self::IGNORED_TAXONOMIES, true)) {
                continue;
            }

            $names = wp_get_object_terms($id, $taxonomy, ['fields' => 'names', 'update_term_meta_cache' => false]);
            if (is_array($names) && $names !== []) {
                sort($names);
                $terms[$taxonomy] = $names;
            }
        }

        return [
            'title'          => $post->post_title,
            'content'        => $post->post_content,
            'excerpt'        => $post->post_excerpt,
            'status'         => $post->post_status,
            'type'           => $post->post_type,
            'slug'           => $post->post_name,
            'parent'         => (int) $post->post_parent,
            'menu_order'     => (int) $post->menu_order,
            'author'         => (int) $post->post_author,
            'comment_status' => $post->comment_status,
            'template'       => (string) get_post_meta($id, '_wp_page_template', true),
            'terms'          => $terms,
        ];
    }

    /**
     * Every value of one meta key: `{values: [...]}`, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    private static function meta(string $metaType, int $id, string $key): ?array
    {
        $values = get_metadata_raw($metaType, $id, $key, false);

        if (!is_array($values) || $values === []) {
            return null;
        }

        if (Sensitive::isSensitiveKey($key)) {
            return ['values' => array_fill(0, count($values), self::MASKED)];
        }

        return ['values' => array_map(static fn ($value) => Logger::redact(maybe_unserialize($value)), $values)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function term(int $id): ?array
    {
        $term = get_term($id);

        if (!$term instanceof \WP_Term) {
            return null;
        }

        return [
            'name'        => $term->name,
            'slug'        => $term->slug,
            'taxonomy'    => $term->taxonomy,
            'description' => $term->description,
            'parent'      => (int) $term->parent,
        ];
    }

    /**
     * `{value}`, or null when the option does not exist.
     *
     * @return array<string, mixed>|null
     */
    private static function option(string $name): ?array
    {
        $missing = new \stdClass();
        $value   = get_option($name, $missing);

        if ($value === $missing) {
            return null;
        }

        return ['value' => Sensitive::isSensitiveKey($name) ? self::MASKED : Logger::redact($value)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function user(int $id): ?array
    {
        $user = get_userdata($id);

        if (!$user instanceof \WP_User) {
            return null;
        }

        $roles = array_values($user->roles);
        sort($roles);

        // Never user_pass or user_activation_key.
        return [
            'login'        => $user->user_login,
            'email'        => $user->user_email,
            'nicename'     => $user->user_nicename,
            'display_name' => $user->display_name,
            'url'          => $user->user_url,
            'roles'        => $roles,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function comment(int $id): ?array
    {
        $comment = get_comment($id);

        if (!$comment instanceof \WP_Comment) {
            return null;
        }

        return [
            'post_id'      => (int) $comment->comment_post_ID,
            'author'       => $comment->comment_author,
            'author_email' => $comment->comment_author_email,
            'author_url'   => $comment->comment_author_url,
            'content'      => $comment->comment_content,
            'status'       => (string) $comment->comment_approved,
            'type'         => $comment->comment_type,
            'parent'       => (int) $comment->comment_parent,
        ];
    }

    /**
     * A WooCommerce product or order as its CRUD class reads it (get_data()),
     * with dates as ISO 8601 and nested objects as their data.
     *
     * @param mixed $object
     * @return array<string, mixed>|null
     */
    private static function wooObject($object): ?array
    {
        if (!is_object($object) || !method_exists($object, 'get_data')) {
            return null;
        }

        $data = self::plain($object->get_data());

        return is_array($data) ? (array) Logger::redact($data) : null;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function plain($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_object($value) && method_exists($value, 'get_data')) {
            $value = $value->get_data();
        } elseif ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map([self::class, 'plain'], $value) : $value;
    }
}
