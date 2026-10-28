<?php

namespace McpMate\Services\CoreProxy;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\SiteContext\PostStructure;

/**
 * Runs one core REST route from the CoreRoutes table as the current user,
 * with rest_do_request() (connection v2, §6.4, POST /proxy).
 *
 * Capability, object-level and status checks are core's own. On top:
 * the table and its argument rules (CoreRoutes), and here the rules that
 * need the database:
 *
 *  - an update runs only while the stored post is draft, pending or
 *    auto-draft (409 mcpmate_proxy_not_draft), and a page-builder post
 *    refuses `content` (409 mcpmate_proxy_page_builder);
 *  - a delete always sends force=false, so the post goes to the trash, and
 *    is refused when the trash is off (EMPTY_TRASH_DAYS = 0, rule 3);
 *  - a new post or page defaults to `draft`.
 *
 * The request is built from scratch: no client header reaches it, so no
 * method override either.
 */
final class CoreProxy
{
    public const DISABLED = 'mcpmate_proxy_disabled';

    public const NOT_DRAFT = 'mcpmate_proxy_not_draft';

    public const PAGE_BUILDER = 'mcpmate_proxy_page_builder';

    public const TRASH_UNAVAILABLE = 'mcpmate_trash_unavailable';

    /** Stored statuses an update may change. */
    public const EDITABLE_STATUSES = ['draft', 'pending', 'auto-draft'];

    /** Largest JSON-encoded body, in bytes (1 MiB). */
    public const MAX_BODY_BYTES = 1048576;

    private static int $depth = 0;

    /**
     * Whether an allowlisted inner request is being dispatched right now
     * (RestGuard lets only those reach core routes during an MCP call).
     */
    public static function isDispatching(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return array{match: array{kind: string, collection: string, post_type: string, id: int}, status: int, total: ?int, total_pages: ?int, body: mixed}|\WP_Error
     */
    public static function run(string $method, string $route, array $query, array $body)
    {
        $method = strtoupper($method);
        $match  = CoreRoutes::match($method, $route);

        if ($match === null) {
            return new \WP_Error(
                CoreRoutes::ROUTE_NOT_ALLOWED,
                __('This route is not on the list of core routes McpMate may use.', 'mcpmate'),
                ['status' => 403, 'hint' => __('Posts, pages, categories, tags, media (read), types, taxonomies and search are available.', 'mcpmate')]
            );
        }

        $refused = CoreRoutes::checkArgs($match, $method, $query, $body);
        if ($refused !== null) {
            return new \WP_Error($refused['code'], $refused['message'], ['status' => 400]);
        }

        $refused = self::checkStored($match, $body);
        if ($refused instanceof \WP_Error) {
            return $refused;
        }

        if ($match['kind'] === CoreRoutes::KIND_CREATE && !isset($body['status'])) {
            $body['status'] = 'draft';
        }

        $inner = new \WP_REST_Request($method, $route);

        if ($match['kind'] === CoreRoutes::KIND_TRASH) {
            // The body is dropped, so a `force` in it cannot outrank this one.
            $inner->set_query_params(['force' => false]);
        } elseif ($method === 'GET') {
            $inner->set_query_params($query);
        } else {
            $inner->set_body_params($body);
        }

        self::$depth++;
        try {
            $response = rest_do_request($inner);
        } finally {
            self::$depth--;
        }

        if ($response->is_error()) {
            return $response->as_error();
        }

        $headers = $response->get_headers();

        return [
            'match'       => $match,
            'status'      => $response->get_status(),
            'total'       => isset($headers['X-WP-Total']) ? (int) $headers['X-WP-Total'] : null,
            'total_pages' => isset($headers['X-WP-TotalPages']) ? (int) $headers['X-WP-TotalPages'] : null,
            'body'        => $response->get_data(),
        ];
    }

    /**
     * Rules against the stored post. Applied only when the user may edit
     * the post; otherwise core answers (403/404) and nothing leaks.
     *
     * @param array{kind: string, post_type: string, id: int} $match
     * @param array<string, mixed>                           $body
     */
    private static function checkStored(array $match, array $body): ?\WP_Error
    {
        if ($match['kind'] === CoreRoutes::KIND_TRASH && !EMPTY_TRASH_DAYS) {
            return new \WP_Error(
                self::TRASH_UNAVAILABLE,
                __('The trash is turned off on this site (EMPTY_TRASH_DAYS is 0), so deleting would be permanent.', 'mcpmate'),
                ['status' => 409]
            );
        }

        if ($match['kind'] !== CoreRoutes::KIND_UPDATE) {
            return null;
        }

        $post = get_post($match['id']);

        if (!$post instanceof \WP_Post || $post->post_type !== $match['post_type'] || !current_user_can('edit_post', $post->ID)) {
            return null;
        }

        if (!in_array($post->post_status, self::EDITABLE_STATUSES, true)) {
            return new \WP_Error(
                self::NOT_DRAFT,
                __('Only drafts and pending posts can be changed through McpMate.', 'mcpmate'),
                ['status' => 409, 'hint' => __('Create a new draft instead, and let the site owner publish it.', 'mcpmate')]
            );
        }

        if (array_key_exists('content', $body)) {
            $builder = PostStructure::describe($post)['builder'];

            if (is_array($builder)) {
                return new \WP_Error(
                    self::PAGE_BUILDER,
                    /* translators: %s: page builder name, e.g. Elementor. */
                    sprintf(__('This post is laid out with %s, so its content cannot be replaced here.', 'mcpmate'), (string) $builder['name']),
                    ['status' => 409, 'hint' => __('Edit the layout with the page builder\'s own tools.', 'mcpmate')]
                );
            }
        }

        return null;
    }
}
