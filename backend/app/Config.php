<?php

namespace McpMate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central configuration for the McpMate plugin.
 *
 * All plugin-wide constants, path/URL resolvers, and option helpers live here.
 * This avoids scattering magic strings across the codebase and provides a
 * single source of truth for every environment-dependent value.
 */
final class Config
{
    public const SLUG = 'mcpmate';

    public const TITLE = 'McpMate';

    public const VERSION = '1.0.0';

    public const DB_VERSION = '0.10.0';

    public const VAR_PREFIX = 'MCPMATE_';

    public const REQUIRED_PHP_VERSION = '7.4';

    public const REQUIRED_WP_VERSION = '6.0';

    public const API_VERSION = '1';

    public const ASSETS_FOLDER = 'assets';

    /**
     * Resolve plugin paths and URLs by key.
     *
     * Using a centralized resolver prevents hardcoded paths throughout the
     * plugin. Each case returns a lazily-computed value so nothing is
     * evaluated until it is actually needed.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        switch ($key) {
            case 'MAIN_FILE':
                return realpath(__DIR__ . '/../../mcpmate.php');
            case 'BASENAME':
                return plugin_basename(trim(self::get('MAIN_FILE')));
            case 'ROOT_DIR':
                return plugin_dir_path(self::get('MAIN_FILE'));
            case 'BASEDIR':
                return self::get('ROOT_DIR') . 'backend';
            case 'SITE_URL':
                return site_url();
            case 'ADMIN_URL':
                return str_replace(self::get('SITE_URL'), '', get_admin_url());
            case 'ROOT_URI':
                return set_url_scheme(
                    plugins_url('', self::get('MAIN_FILE')),
                    wp_parse_url(home_url())['scheme']
                );
            case 'ASSET_URI':
                return self::get('ROOT_URI') . '/' . self::ASSETS_FOLDER;
            case 'API_URL':
                return self::resolveApiUrl();
            case 'BUILD_CODE_NAME':
                return self::resolveBuildCodeName();
            case 'WP_DB_PREFIX':
                return $GLOBALS['wpdb']->prefix;
            case 'UPLOAD_BASE_URL':
                return wp_upload_dir()['baseurl'];
            case 'UPLOAD_BASE_DIR':
                return wp_upload_dir()['basedir'];
            case 'SIDE_BAR_MENU':
                return Menu::getSideBarMenu(new Views\Body());
            default:
                return $default;
        }
    }

    // ──────────────────────────────────────────────
    //  Prefix helpers
    // ──────────────────────────────────────────────

    public static function withPrefix(string $name): string
    {
        return self::VAR_PREFIX . $name;
    }

    /**
     * Full table name, e.g. `wp_mcpmate_logs`.
     *
     * Lowercased because MySQL table-name case sensitivity depends on the
     * host OS and `lower_case_table_names`.
     */
    public static function withDBPrefix(string $table): string
    {
        return self::get('WP_DB_PREFIX') . strtolower(self::withPrefix($table));
    }

    // ──────────────────────────────────────────────
    //  WP Options API wrappers
    // ──────────────────────────────────────────────

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getOption(string $option, $default = false, bool $wp = false)
    {
        return $wp
            ? get_option($option, $default)
            : get_option(self::withPrefix($option), $default);
    }

    /**
     * @param mixed $value
     */
    public static function addOption(string $option, $value, bool $autoload = false): bool
    {
        return add_option(
            self::withPrefix($option),
            $value,
            '',
            $autoload
        );
    }

    /**
     * @param mixed     $value
     * @param bool|null $autoload Null keeps the option's current autoload setting.
     */
    public static function updateOption(string $option, $value, ?bool $autoload = null): bool
    {
        return update_option(
            self::withPrefix($option),
            $value,
            $autoload
        );
    }

    public static function deleteOption(string $option): bool
    {
        return delete_option(self::withPrefix($option));
    }

    // ──────────────────────────────────────────────
    //  Environment helpers
    // ──────────────────────────────────────────────

    /**
     * @return mixed
     */
    public static function getEnv(string $key)
    {
        $prefixedKey = self::VAR_PREFIX . $key;

        return isset($_ENV[$prefixedKey])
            ? sanitize_text_field($_ENV[$prefixedKey])
            : false;
    }

    public static function isDev(): bool
    {
        return (bool) self::getEnv('DEV');
    }

    /**
     * Help and footer links (docs/ui.md B14): the constant
     * `MCPMATE_<KEY>` or `<KEY>` in `.env`, http(s) only. An empty
     * value hides the link, so the admin app never shows a dead one.
     *
     * @return array{docs: string, support: string, review: string, feedback: string}
     */
    public static function links(): array
    {
        $keys = [
            'docs'     => 'DOCS_URL',
            'support'  => 'SUPPORT_URL',
            'review'   => 'REVIEW_URL',
            'feedback' => 'FEEDBACK_URL',
        ];

        $links = [];

        foreach ($keys as $name => $key) {
            $constant = self::withPrefix($key);
            $value    = defined($constant) ? constant($constant) : self::getEnv($key);

            $links[$name] = is_string($value) && $value !== ''
                ? esc_url_raw(trim($value), ['http', 'https'])
                : '';
        }

        return $links;
    }

    public static function title(): string
    {
        return self::TITLE;
    }

    // ──────────────────────────────────────────────
    //  Private resolvers
    // ──────────────────────────────────────────────

    private static function resolveApiUrl(): array
    {
        global $wp_rewrite;

        return [
            'base'      => get_rest_url(null, self::SLUG . '/v' . self::API_VERSION),
            'separator' => $wp_rewrite->permalink_structure ? '?' : '&',
        ];
    }

    private static function resolveBuildCodeName(): string
    {
        if (self::isDev()) {
            return '';
        }

        $file = self::get('ROOT_DIR') . self::ASSETS_FOLDER . '/build-code-name.txt';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local build artifact, not a remote URL.
        return file_exists($file) ? trim((string) file_get_contents($file)) : '';
    }
}
