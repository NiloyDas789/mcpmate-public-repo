<?php

namespace MCPMate\Services\Connection;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Hooks\Hooks;
use MCPMate\HTTP\Controllers\Api\RestController;
use MCPMate\Services\Mcp\McpContext;
use MCPMate\Services\Mcp\McpRoute;

/**
 * What keeps REST_NS for wp-admin and the MCP layer only
 * (docs/mcp-in-plugin.md §6.1, security rule 9), and the errors the router
 * gives an MCP call's internal requests.
 *
 *  - Application Passwords and HTTP Basic auth are not accepted on REST_NS:
 *    core's Application Password check is switched off for those routes,
 *    and a request that still sends Basic credentials gets 401
 *    `mcpmate_legacy_auth_disabled`.
 *  - While an MCP call runs, its tools reach only REST_NS routes (checked
 *    further in Router::authorizeMcp()), and core routes only through
 *    dispatchCore() (MCPMate Pro's POST /proxy, behind its CoreRoutes table).
 *
 * Bearer tokens set a user only on the MCP route (McpRoute), so a REST_NS
 * route over HTTP is reached only with a wp-admin cookie and its nonce.
 */
final class RestGuard
{
    public const ROUTE_NOT_ALLOWED = 'mcpmate_route_not_allowed';

    public const INSUFFICIENT_SCOPE = 'mcpmate_insufficient_scope';

    public const LEGACY_AUTH_DISABLED = 'mcpmate_legacy_auth_disabled';

    public const ACCESS_PAUSED = 'mcpmate_access_paused';

    public const TOOL_DISABLED = 'mcpmate_tool_disabled';

    public const TOOL_NOT_ALLOWED = 'mcpmate_tool_not_allowed';

    public const READ_MODE = 'mcpmate_read_mode';

    /** Nesting depth of dispatchCore(). */
    private static int $coreDepth = 0;

    /** Nesting depth of asConsole(): `wp mcpmate` running a command. */
    private static int $consoleDepth = 0;

    public static function register(): void
    {
        Hooks::addFilter('application_password_is_api_request', [self::class, 'appPasswordsForRequest'], 100);
        Hooks::addFilter('rest_pre_dispatch', [self::class, 'guardDispatch'], 5, 3);
    }

    // ──────────────────────────────────────────────
    //  WordPress hooks
    // ──────────────────────────────────────────────

    /**
     * `application_password_is_api_request`: Application Passwords never
     * authenticate a request for a route under REST_NS.
     *
     * @param mixed $isApiRequest
     * @return mixed
     */
    public static function appPasswordsForRequest($isApiRequest)
    {
        $route = self::requestedRoute($_SERVER);

        return $route !== null && self::isOwnRoute($route) ? false : $isApiRequest;
    }

    /**
     * Run one core REST request inside a REST_NS route (POST /proxy, after
     * its allowlist), the only way an MCP call reaches a core route.
     */
    public static function dispatchCore(\WP_REST_Request $request): \WP_REST_Response
    {
        self::$coreDepth++;
        try {
            return rest_do_request($request);
        } finally {
            self::$coreDepth--;
        }
    }

    /**
     * Run $action as a `wp mcpmate` command: what it audits has `auth`
     * `cli`. Under WP-CLI that is already so; this also holds where WP_CLI
     * is not defined (the integration tests run the commands' service).
     *
     * @template T
     * @param callable(): T $action
     * @return T
     */
    public static function asConsole(callable $action)
    {
        self::$consoleDepth++;
        try {
            return $action();
        } finally {
            self::$consoleDepth--;
        }
    }

    /**
     * Whether dispatchCore() is running.
     */
    public static function isDispatchingCore(): bool
    {
        return self::$coreDepth > 0;
    }

    /**
     * `rest_pre_dispatch`: an MCP call's requests reach only REST_NS routes
     * (core routes only through POST /proxy), and REST_NS refuses Basic
     * credentials outright.
     *
     * @param mixed $result
     * @param mixed $server
     * @return mixed
     */
    public static function guardDispatch($result, $server, \WP_REST_Request $request)
    {
        if (!empty($result)) {
            return $result;
        }

        $route = $request->get_route();
        $own   = self::isOwnRoute($route);

        if (McpContext::active() && !$own && !McpRoute::isRoute($route) && self::$coreDepth === 0) {
            return self::error(self::ROUTE_NOT_ALLOWED, 403);
        }

        if ($own && !McpContext::active() && self::hasBasicCredentials($_SERVER)) {
            return self::error(self::LEGACY_AUTH_DISABLED, 401);
        }

        return $result;
    }

    // ──────────────────────────────────────────────
    //  Current request
    // ──────────────────────────────────────────────

    /**
     * How the current request authenticated, for the audit log: `oauth`
     * (an MCP call's bearer token), `chat` (an MCP call MCPMate Pro's Chat
     * made in wp-admin, McpEndpoint::handleInternal()), `cli` (WP-CLI on the server, `wp
     * mcpmate` included), `cookie` (wp-admin: admin-ajax, or REST with a
     * cookie and nonce), or '' (cron).
     */
    public static function authMethod(): string
    {
        if (McpContext::active()) {
            return McpContext::internal() ? 'chat' : 'oauth';
        }

        if (self::$consoleDepth > 0 || (defined('WP_CLI') && WP_CLI)) {
            return 'cli';
        }

        // Core sets it to true for a valid cookie, and to other values (`malformed`) otherwise.
        return wp_doing_ajax() || ($GLOBALS['wp_rest_auth_cookie'] ?? null) === true ? 'cookie' : '';
    }

    /**
     * The REST route the request asks for (`/mcpmate/v1/ping`), from
     * `rest_route` or the path after the REST prefix; null when the request
     * is not for the REST API. Works before REST_REQUEST is defined, as
     * core's own Application Password check needs to.
     *
     * @param array<string, mixed> $server
     */
    public static function requestedRoute(array $server): ?string
    {
        $split = explode('?', (string) ($server['REQUEST_URI'] ?? ''), 2);

        foreach (explode('&', $split[1] ?? '') as $part) {
            $pair = explode('=', $part, 2);

            if (urldecode($pair[0]) === 'rest_route') {
                return '/' . ltrim(urldecode($pair[1] ?? ''), '/');
            }
        }

        $homePath = (string) wp_parse_url(home_url(), PHP_URL_PATH);
        $prefix   = trailingslashit($homePath) . rest_get_url_prefix() . '/';
        $path     = rawurldecode($split[0]);

        if (str_starts_with($path, $prefix)) {
            return '/' . (string) substr($path, strlen($prefix));
        }

        return null;
    }

    public static function isOwnRoute(string $route): bool
    {
        return str_starts_with($route, '/' . RestController::NAMESPACE . '/');
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function hasBasicCredentials(array $server): bool
    {
        if (isset($server['PHP_AUTH_USER'])) {
            return true;
        }

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $name) {
            if (isset($server[$name]) && is_string($server[$name]) && stripos($server[$name], 'basic ') === 0) {
                return true;
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────
    //  Errors
    // ──────────────────────────────────────────────

    /**
     * A stable error for $code. Messages and hints never carry a secret.
     */
    public static function error(string $code, int $status): \WP_Error
    {
        $reconnect = __('Reconnect the AI app and approve it again in wp-admin (MCPMate → Connections).', 'mcpmate');

        $messages = [
            self::ROUTE_NOT_ALLOWED    => [__('AI apps cannot use this route.', 'mcpmate'), ''],
            self::INSUFFICIENT_SCOPE   => [
                __('This AI app was approved for read-only access.', 'mcpmate'),
                $reconnect,
            ],
            self::ACCESS_PAUSED        => [
                __('A site admin paused AI access to this site.', 'mcpmate'),
                __('A site admin paused AI access in wp-admin. Nothing was revoked: ask them to resume it, then try again.', 'mcpmate'),
            ],
            self::TOOL_DISABLED        => [
                __('This tool is turned off on this site.', 'mcpmate'),
                __('A site admin turned this tool off in wp-admin.', 'mcpmate'),
            ],
            self::TOOL_NOT_ALLOWED     => [
                __('This AI app is not allowed to use this tool.', 'mcpmate'),
                __('A site admin chose which tools this AI app may use in wp-admin.', 'mcpmate'),
            ],
            self::READ_MODE            => [
                __('Read mode is on: AI apps may only read this site.', 'mcpmate'),
                __('A site admin turned on read mode in wp-admin. Nothing was revoked: ask them to turn it off, then try again.', 'mcpmate'),
            ],
            self::LEGACY_AUTH_DISABLED => [
                __('Application Passwords and HTTP Basic auth are not accepted here.', 'mcpmate'),
                __('Add this site\'s MCP URL to your AI app (MCPMate → Connections) and approve it in wp-admin.', 'mcpmate'),
            ],
        ];

        [$message, $hint] = $messages[$code] ?? [__('The request could not be authenticated.', 'mcpmate'), ''];

        $data = ['status' => $status];
        if ($hint !== '') {
            $data['hint'] = $hint;
        }

        return new \WP_Error($code, $message, $data);
    }
}
