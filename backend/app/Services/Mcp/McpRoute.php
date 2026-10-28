<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Hooks\Hooks;
use McpMate\Services\Connection\RestGuard;
use McpMate\Services\OAuth\BearerAuth;
use McpMate\Services\OAuth\OAuthRequest;
use McpMate\Services\OAuth\OAuthResponse;

/**
 * The MCP endpoint R as a REST route, `mcpmate/mcp` (its own
 * namespace, so RestEnvelope never wraps it; docs/mcp-in-plugin.md §3.3).
 * McpEndpoint answers; this class is the WordPress side:
 *
 *  - `determine_current_user`: on this route only, a valid bearer token is
 *    the user (security plugins that allow logged-in REST requests then let
 *    it through) and nothing else is (no cookies, no Application
 *    Passwords). It never sets a user on any other request (rule 9).
 *  - `rest_pre_serve_request`: the answer is written as is (raw JSON-RPC,
 *    our status and headers), and core's CORS headers, which echo any
 *    Origin with credentials, are left out for this route.
 */
final class McpRoute
{
    public const NAMESPACE = 'mcpmate';

    public const ROUTE = '/mcpmate/mcp';

    private static ?OAuthResponse $response = null;

    public static function register(): void
    {
        Hooks::addAction('rest_api_init', [self::class, 'registerRoute']);
        Hooks::addFilter('determine_current_user', [self::class, 'determineUser'], 30);
        Hooks::addFilter('application_password_is_api_request', [self::class, 'appPasswordsForRequest'], 100);
        Hooks::addFilter('rest_pre_serve_request', [self::class, 'serve'], 0, 4);
    }

    public static function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, '/mcp', [
            'methods'             => 'GET, POST, DELETE',
            'callback'            => [self::class, 'callback'],
            // The endpoint checks the bearer token itself and answers 401 with
            // WWW-Authenticate; core's own 401 would not name the metadata.
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @return \WP_REST_Response Empty: serve() writes the answer.
     */
    public static function callback(\WP_REST_Request $request): \WP_REST_Response
    {
        self::$response = McpEndpoint::handle(self::requestFrom($request));

        return new \WP_REST_Response(null, self::$response->status());
    }

    /**
     * `rest_pre_serve_request`: write the answer for this route.
     *
     * @param mixed $served
     * @param mixed $result
     * @param mixed $server
     * @return mixed
     */
    public static function serve($served, $result, \WP_REST_Request $request, $server) // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- The filter's fourth argument.
    {
        if (!self::isRoute($request->get_route())) {
            return $served;
        }

        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

        $response       = self::$response;
        self::$response = null;

        if ($response === null) {
            // Core answered before the callback: a preflight, or a body that is not JSON.
            $error = $result instanceof \WP_REST_Response ? $result->as_error() : null;

            if ($request->get_method() !== 'OPTIONS' && !(is_wp_error($error) && $error->get_error_code() === 'rest_invalid_json')) {
                return $served;
            }

            $response = McpEndpoint::handle(self::requestFrom($request));
        }

        // Page caches that honour it store nothing (§4.8).
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The page caches' own constant.
        }

        if (!headers_sent()) {
            foreach (['Access-Control-Allow-Headers', 'Access-Control-Expose-Headers', 'Access-Control-Allow-Credentials', 'Link', 'Allow'] as $header) {
                header_remove($header);
            }
        }

        $response->send();

        return true;
    }

    /**
     * `determine_current_user`: on this route, the bearer token's user or nobody.
     *
     * @param int|false $userId
     * @return int|false
     */
    public static function determineUser($userId)
    {
        if (!self::isRequestForRoute()) {
            return $userId;
        }

        $auth = BearerAuth::authenticate(BearerAuth::authorizationHeader());

        return $auth['ok'] ? $auth['user_id'] : 0;
    }

    /**
     * `application_password_is_api_request`: never on this route.
     *
     * @param mixed $isApiRequest
     * @return mixed
     */
    public static function appPasswordsForRequest($isApiRequest)
    {
        return self::isRequestForRoute() ? false : $isApiRequest;
    }

    private static function isRequestForRoute(): bool
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        $route = RestGuard::requestedRoute($_SERVER);

        return $route !== null && self::isRoute($route);
    }

    public static function isRoute(string $route): bool
    {
        return untrailingslashit($route) === self::ROUTE;
    }

    /**
     * The REST request as McpEndpoint reads it: lowercase dashed headers,
     * the raw body, the Authorization header wherever the server put it.
     */
    public static function requestFrom(\WP_REST_Request $request): OAuthRequest
    {
        $headers = [];
        foreach ($request->get_headers() as $name => $values) {
            $headers[str_replace('_', '-', strtolower((string) $name))] = is_array($values) ? (string) reset($values) : (string) $values;
        }

        $authorization = BearerAuth::authorizationHeader();
        if ($authorization !== '') {
            $headers['authorization'] = $authorization;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared with filter_var() only.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';

        return new OAuthRequest($request->get_method(), [], [], $headers, $request->get_body(), filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '', is_ssl());
    }
}
