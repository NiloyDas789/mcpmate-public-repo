<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Draft previews: a front-end URL with `?MCPMATE_preview=<token>`
 * (PreviewToken) renders that one request with the draft theme.
 *
 * On `setup_theme`, before WordPress loads any theme code, a valid token
 * adds `stylesheet` and `template` filters that point at the draft (and its
 * parent, for a child theme). Nothing is stored and the options are not
 * touched, so every other request, and every other visitor, still gets the
 * live theme. Customizer settings are the live theme's.
 *
 * The token works only on front-end GET/HEAD requests (not wp-admin, AJAX,
 * cron, REST, XML-RPC or wp-login.php), only while theme drafts are enabled
 * and the draft is unpublished and valid. A preview response is not cached
 * (DONOTCACHEPAGE, no-cache headers), not indexed, and sends no Referer, so
 * the token does not leak to third-party assets. An invalid or expired token
 * gets a 403 page instead of the live theme, so a reviewer is never shown
 * the live site by mistake.
 *
 * Anyone holding the link sees the draft until it expires; the draft's PHP
 * runs for them like a theme's does.
 */
final class ThemePreview
{
    public const QUERY_ARG = 'preview';

    private static bool $refused = false;

    public static function register(): void
    {
        add_action('setup_theme', [self::class, 'start'], 0);
    }

    public static function queryArg(): string
    {
        return Config::withPrefix(self::QUERY_ARG);
    }

    /**
     * The signing key: derived from the site's AUTH salts, so changing them
     * revokes every preview link.
     */
    public static function key(): string
    {
        return hash_hmac('sha256', 'theme-preview', wp_salt('auth'));
    }

    /**
     * A preview link for $record at $path (a path on the site, e.g. `/about/`).
     *
     * @param array<string, mixed> $record
     * @return array{url: string, expires_at: string, expires_in: int}
     */
    public static function url(array $record, string $path, int $ttl): array
    {
        $ttl     = PreviewToken::ttl($ttl);
        $expires = time() + $ttl;
        $token   = PreviewToken::create((string) $record['id'], (string) $record['slug'], $expires, self::key());

        return [
            'url'        => add_query_arg(self::queryArg(), $token, home_url($path)),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires),
            'expires_in' => $ttl,
        ];
    }

    /**
     * A site path for url(): starts with one `/`, no scheme, host, fragment,
     * backslashes or control characters.
     *
     * @throws ThemeDraftException 400
     */
    public static function pagePath(string $path): string
    {
        $path = trim($path);

        if (
            $path === ''
            || $path[0] !== '/'
            || strpos($path, '//') === 0
            || strpos($path, '\\') !== false
            || strpos($path, '#') !== false
            || preg_match('/[\x00-\x20\x7f]/', $path)
        ) {
            throw new ThemeDraftException(
                esc_html__('The preview path must be a path on this site, like "/" or "/about/".', 'mcpmate'),
                esc_html(ThemeDraftException::INVALID_PATH),
                400
            );
        }

        return $path;
    }

    // ──────────────────────────────────────────────
    //  Hooks
    // ──────────────────────────────────────────────

    /**
     * `setup_theme`: switch this request to the draft when the token is valid.
     */
    public static function start(): void
    {
        $arg = self::queryArg();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The signed token is the credential, and a preview changes nothing.
        if (!isset($_GET[$arg]) || !self::isFrontEnd()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
        $token = is_string($_GET[$arg]) ? sanitize_text_field(wp_unslash($_GET[$arg])) : '';
        $theme = self::themeFor($token);

        // Translations are not loaded before `init`, so the refusal page waits for template_redirect.
        add_action('send_headers', [self::class, 'sendHeaders']);

        if ($theme === null) {
            self::$refused = true;
            add_action('template_redirect', [self::class, 'refuse'], 0);

            return;
        }

        $stylesheet = $theme->get_stylesheet();
        $template   = $theme->get_template();

        add_filter('stylesheet', static fn () => $stylesheet, PHP_INT_MAX);
        add_filter('template', static fn () => $template, PHP_INT_MAX);
        add_filter('wp_robots', 'wp_robots_no_robots');

        if (!defined('DONOTCACHEPAGE')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The page-cache plugins' shared convention.
            define('DONOTCACHEPAGE', true);
        }
    }

    /**
     * `send_headers`: no caching, no indexing, no Referer.
     */
    public static function sendHeaders(): void
    {
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
    }

    /**
     * `template_redirect`: the token was invalid or expired.
     */
    public static function refuse(): void
    {
        if (!self::$refused) {
            return;
        }

        wp_die(
            esc_html__('This theme preview link is invalid or has expired. Ask the AI for a new preview link.', 'mcpmate'),
            esc_html__('Preview unavailable', 'mcpmate'),
            ['response' => 403]
        );
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * The draft theme a token opens, or null.
     */
    public static function themeFor(string $token): ?\WP_Theme
    {
        $parsed = PreviewToken::parse($token);

        if ($parsed === null || !ThemeDraftSettings::isEnabled()) {
            return null;
        }

        $record = Drafts::find($parsed['id']);

        if ($record === null || !PreviewToken::isValid($parsed, (string) $record['slug'], self::key(), time())) {
            return null;
        }

        try {
            Drafts::workingDirectory($record);

            return Drafts::validTheme($record);
        } catch (ThemeDraftException $e) {
            return null;
        }
    }

    private static function isFrontEnd(): bool
    {
        if (
            is_admin()
            || wp_doing_ajax()
            || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            || ($GLOBALS['pagenow'] ?? '') === 'wp-login.php'
        ) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks whether this is a REST request.
        if (isset($_GET['rest_route'])) {
            return false;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        $uri    = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path   = (string) wp_parse_url($uri, PHP_URL_PATH);

        return in_array($method, ['GET', 'HEAD'], true)
            && strpos($path, '/' . rest_get_url_prefix() . '/') === false;
    }
}
