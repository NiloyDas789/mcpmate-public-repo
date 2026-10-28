<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Connection\Grants;
use McpMate\Services\OAuth\OAuthUrls;

/**
 * The grant behind the current MCP request, and the plugin routes its tools
 * dispatch in-process (docs/mcp-in-plugin.md §6.1).
 *
 * McpEndpoint begins it once the bearer token checked out. Tools reach
 * REST_NS routes only through dispatch(), which marks each request object
 * it sends: Router::authorizeMcp() applies the route → tool → on → grant's
 * tools → scope checks to exactly those (object identity, never a header),
 * and refuses any other request under REST_NS while an MCP call runs.
 * Audit rows and change history read the grant from here.
 */
final class McpContext
{
    /**
     * @var array{grant_id: string, user_id: int, scopes: string[], tools: string[]|null, access: string, client_name: string, request_id: string}|null
     */
    private static ?array $grant = null;

    /** @var \SplObjectStorage<\WP_REST_Request, true>|null */
    private static ?\SplObjectStorage $owned = null;

    /** Routes dispatched in this request so far (a call that reached none is audited by McpAudit). */
    private static int $dispatches = 0;

    /**
     * @param array<string, mixed> $grant  The grants row.
     * @param string[]             $scopes The access token's scopes.
     */
    public static function begin(array $grant, array $scopes, string $requestId): void
    {
        self::$grant = [
            'grant_id'    => (string) $grant['grant_id'],
            'user_id'     => (int) $grant['wp_user_id'],
            // A grant made read-only after the token was issued reads only.
            'scopes'      => array_values(array_intersect($scopes, Grants::scopes($grant))),
            'tools'       => Grants::tools($grant),
            'access'      => Grants::access($grant),
            'client_name' => (string) $grant['client_name'],
            'request_id'  => $requestId,
        ];
    }

    /**
     * Forget the grant (the end of a request; tests).
     */
    public static function reset(): void
    {
        self::$grant      = null;
        self::$owned      = null;
        self::$dispatches = 0;
    }

    public static function active(): bool
    {
        return self::$grant !== null;
    }

    public static function grantId(): string
    {
        return self::$grant['grant_id'] ?? '';
    }

    public static function userId(): int
    {
        return self::$grant['user_id'] ?? 0;
    }

    public static function requestId(): string
    {
        return self::$grant['request_id'] ?? '';
    }

    public static function hasScope(string $scope): bool
    {
        return in_array($scope, self::$grant['scopes'] ?? [], true);
    }

    public static function canWrite(): bool
    {
        return self::hasScope(OAuthUrls::SCOPE_WRITE);
    }

    /**
     * Whether the grant may use $tool: every tool unless a site admin gave
     * it a custom list.
     */
    public static function allowsTool(string $tool): bool
    {
        if (self::$grant === null) {
            return false;
        }

        return self::$grant['tools'] === null || in_array($tool, self::$grant['tools'], true);
    }

    // ──────────────────────────────────────────────
    //  In-process dispatch
    // ──────────────────────────────────────────────

    /**
     * Run $request with rest_do_request(), marked as this MCP call's own.
     */
    public static function dispatch(\WP_REST_Request $request): \WP_REST_Response
    {
        if (self::$owned === null) {
            self::$owned = new \SplObjectStorage();
        }

        self::$owned->attach($request);
        self::$dispatches++;

        try {
            return rest_ensure_response(rest_do_request($request));
        } finally {
            self::$owned->detach($request);
        }
    }

    public static function dispatchCount(): int
    {
        return self::$dispatches;
    }

    /**
     * Whether $request is one dispatch() is running.
     */
    public static function owns(\WP_REST_Request $request): bool
    {
        return self::$grant !== null && self::$owned !== null && self::$owned->contains($request);
    }
}
