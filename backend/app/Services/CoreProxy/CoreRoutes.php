<?php

namespace McpMate\Services\CoreProxy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The default-deny table of core REST routes POST /proxy may run
 * (connection v2, §6.4), and the argument rules that need no database.
 *
 * Anything not on the table is refused: users, application passwords,
 * settings, plugins, themes, /batch/v1, widgets, menus, templates, global
 * styles, comments, other plugins' namespaces and REST_NS itself. The rules
 * that need WordPress (the stored status, page-builder posts, trash) are in
 * CoreProxy.
 *
 * Pure PHP, unit-tested without WordPress.
 */
final class CoreRoutes
{
    public const KIND_LIST = 'list';

    public const KIND_READ = 'read';

    public const KIND_READ_OTHER = 'read_other';

    public const KIND_CREATE = 'create';

    public const KIND_UPDATE = 'update';

    public const KIND_TRASH = 'trash';

    public const KIND_CREATE_TERM = 'create_term';

    public const ROUTE_NOT_ALLOWED = 'mcpmate_proxy_route_not_allowed';

    public const INVALID_ARGS = 'mcpmate_proxy_invalid_args';

    public const STATUS_NOT_ALLOWED = 'mcpmate_proxy_status_not_allowed';

    public const META_NOT_ALLOWED = 'mcpmate_proxy_meta_not_allowed';

    /** Statuses the AI may save (rule 2). */
    public const DRAFT_STATUSES = ['draft', 'pending'];

    /** Never accepted, in the query or the body. `_embed` would fetch linked resources (users) outside the table. */
    public const FORBIDDEN_ARGS = ['_method', '_envelope', '_jsonp', '_embed'];

    /** [methods, route pattern, kind]. Group 1 is the collection, group 2 the ID. */
    private const TABLE = [
        [['GET'], '#^/wp/v2/(posts|pages)$#D', self::KIND_LIST],
        [['GET'], '#^/wp/v2/(posts|pages)/([1-9][0-9]{0,19})$#D', self::KIND_READ],
        [['GET'], '#^/wp/v2/(categories|tags|media|types|taxonomies|search)$#D', self::KIND_READ_OTHER],
        [['GET'], '#^/wp/v2/(media)/([1-9][0-9]{0,19})$#D', self::KIND_READ_OTHER],
        [['POST'], '#^/wp/v2/(posts|pages)$#D', self::KIND_CREATE],
        [['POST', 'PUT', 'PATCH'], '#^/wp/v2/(posts|pages)/([1-9][0-9]{0,19})$#D', self::KIND_UPDATE],
        [['DELETE'], '#^/wp/v2/(posts|pages)/([1-9][0-9]{0,19})$#D', self::KIND_TRASH],
        [['POST'], '#^/wp/v2/(categories|tags)$#D', self::KIND_CREATE_TERM],
    ];

    /** Collection => post type. */
    private const POST_TYPES = ['posts' => 'post', 'pages' => 'page'];

    /**
     * The table row for $method $route: {kind, collection, post_type, id},
     * or null when the route is not on the table (or not clean).
     *
     * @return array{kind: string, collection: string, post_type: string, id: int}|null
     */
    public static function match(string $method, string $route): ?array
    {
        $method = strtoupper($method);

        if (!self::isClean($route)) {
            return null;
        }

        foreach (self::TABLE as [$methods, $pattern, $kind]) {
            if (in_array($method, $methods, true) && preg_match($pattern, $route, $match)) {
                return [
                    'kind'       => $kind,
                    'collection' => $match[1],
                    'post_type'  => self::POST_TYPES[$match[1]] ?? '',
                    'id'         => isset($match[2]) ? (int) $match[2] : 0,
                ];
            }
        }

        return null;
    }

    /**
     * Check the arguments of a matched request. Reads take a query and no
     * body; writes take a body and no query, so a `status` cannot hide in
     * the query string of a POST.
     *
     * @param array{kind: string} $match
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return array{code: string, message: string}|null Null when allowed.
     */
    public static function checkArgs(array $match, string $method, array $query, array $body): ?array
    {
        $isRead = strtoupper($method) === 'GET';

        if (($isRead && $body !== []) || (!$isRead && $query !== [])) {
            return self::refuse(
                self::INVALID_ARGS,
                $isRead ? __('A GET request takes no body.', 'mcpmate') : __('A write takes its arguments in the body, not the query.', 'mcpmate')
            );
        }

        foreach ([$query, $body] as $args) {
            foreach (self::FORBIDDEN_ARGS as $name) {
                if (array_key_exists($name, $args)) {
                    return self::refuse(
                        self::INVALID_ARGS,
                        /* translators: %s: request argument name, e.g. _embed. */
                        sprintf(__('"%s" is not allowed.', 'mcpmate'), $name)
                    );
                }
            }
        }

        switch ($match['kind']) {
            case self::KIND_LIST:
            case self::KIND_READ:
                if (isset($query['context']) && !in_array($query['context'], ['view', 'edit'], true)) {
                    return self::refuse(self::INVALID_ARGS, __('"context" must be "view" or "edit".', 'mcpmate'));
                }
                break;

            case self::KIND_CREATE:
            case self::KIND_UPDATE:
                if (array_key_exists('status', $body) && !in_array($body['status'], self::DRAFT_STATUSES, true)) {
                    return self::refuse(self::STATUS_NOT_ALLOWED, __('Only "draft" and "pending" can be saved; nothing is published through McpMate.', 'mcpmate'));
                }

                if (array_key_exists('meta', $body)) {
                    return self::refuse(self::META_NOT_ALLOWED, __('"meta" cannot be written through the proxy.', 'mcpmate'));
                }
                break;
        }

        return null;
    }

    /**
     * A route string as the table expects it: absolute, plain characters,
     * no `..`, `//`, percent-encoding, query or `rest_route`.
     */
    public static function isClean(string $route): bool
    {
        return (bool) preg_match('#^/[A-Za-z0-9/_-]{1,200}$#D', $route)
            && !str_contains($route, '//')
            && !str_contains($route, 'rest_route');
    }

    /**
     * @return array{code: string, message: string}
     */
    private static function refuse(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
