<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Hooks\Hooks;

/**
 * The front-end paths of the OAuth server (docs/mcp-in-plugin.md §4.2,
 * §6.1): rewrite rules that set one query var, answered on `parse_request`
 * before the theme loads.
 *
 *   I/{resource-metadata|authorize|token|register|revoke|probe}
 *   I/.well-known/{oauth-authorization-server|openid-configuration}     (path-appended)
 *   /.well-known/oauth-protected-resource[/wp-json/mcpmate/mcp]    (root and path-inserted)
 *   /.well-known/{oauth-authorization-server|openid-configuration}/mcpmate
 *
 * The rules are flushed once whenever they change (rulesVersion()), after
 * every plugin added its own, and again after activation.
 */
final class OAuthRoutes
{
    public const QUERY_VAR = 'mcpmate_oauth';

    /** Option holding the version of the rules last flushed. */
    public const RULES_OPTION = 'oauth_rewrite_version';

    public static function register(): void
    {
        self::addRules();

        Hooks::addFilter('query_vars', [self::class, 'queryVars']);
        Hooks::addAction('parse_request', [self::class, 'serve'], 0);
        Hooks::addAction('wp_loaded', [self::class, 'maybeFlush']);
    }

    /**
     * Rewrite regex (relative to the home path) => endpoint.
     *
     * @return array<string, string>
     */
    public static function rules(): array
    {
        $issuer = preg_quote(OAuthUrls::ISSUER_PATH, '#');
        $mcp    = preg_quote(rest_get_url_prefix() . '/' . OAuthUrls::MCP_ROUTE, '#');

        return [
            '^' . $issuer . '/(resource-metadata|authorize|token|register|revoke|probe)/?$'           => '$matches[1]',
            '^' . $issuer . '/\.well-known/(?:oauth-authorization-server|openid-configuration)/?$'    => OAuthServer::SERVER_METADATA,
            '^\.well-known/oauth-protected-resource(?:/' . $mcp . ')?/?$'                              => OAuthServer::RESOURCE_METADATA,
            '^\.well-known/(?:oauth-authorization-server|openid-configuration)/' . $issuer . '/?$'    => OAuthServer::SERVER_METADATA,
        ];
    }

    public static function addRules(): void
    {
        foreach (self::rules() as $regex => $endpoint) {
            add_rewrite_rule($regex, 'index.php?' . self::QUERY_VAR . '=' . $endpoint, 'top');
        }
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public static function queryVars(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    /**
     * `parse_request`: answer an OAuth endpoint and end the request.
     */
    public static function serve(\WP $wp): void
    {
        $endpoint = self::endpoint($wp);

        if ($endpoint === null) {
            return;
        }

        // Page caches that honour it (WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache) store nothing.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The page caches' own constant.
        }

        OAuthServer::handle($endpoint, OAuthRequest::fromGlobals())->send();
        exit;
    }

    /**
     * The endpoint the parsed request names, or null when it is not one.
     */
    public static function endpoint(\WP $wp): ?string
    {
        $endpoint = $wp->query_vars[self::QUERY_VAR] ?? null;

        return is_string($endpoint) && in_array($endpoint, OAuthServer::ENDPOINTS, true) ? $endpoint : null;
    }

    /**
     * `wp_loaded`: flush once when the rules changed (an update, a new REST
     * prefix) or after activation, when every plugin has added its rules.
     */
    public static function maybeFlush(): void
    {
        if (Config::getOption(self::RULES_OPTION, '') === self::rulesVersion()) {
            return;
        }

        flush_rewrite_rules(false);
        Config::updateOption(self::RULES_OPTION, self::rulesVersion(), true);
    }

    public static function rulesVersion(): string
    {
        return md5((string) wp_json_encode(self::rules()));
    }
}
