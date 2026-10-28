<?php

namespace McpMate\Services\SiteContext;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only snapshot of the site's software: versions, theme, plugins,
 * builders, SEO plugin, WooCommerce, permalinks and Site Health counts.
 *
 * Nothing here makes HTTP requests or runs Site Health tests. Update
 * availability comes from core's cached update checks (`update_plugins`,
 * `update_themes` site transients) and Site Health counts from the result
 * core caches when someone opens Tools → Site Health; `source: not_run`
 * means it has not been opened recently.
 */
final class SiteInfo
{
    /** Core caches the Site Health page's totals here (JSON: good, recommended, critical). */
    public const SITE_HEALTH_TRANSIENT = 'health-check-site-status-result';

    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = self::plugins();
        $theme   = self::theme();

        $activeThemes = [$theme['stylesheet'] => $theme['version']];
        if ($theme['parent'] !== null) {
            $activeThemes[$theme['parent']['stylesheet']] = $theme['parent']['version'];
        }

        return [
            'wp_version'    => get_bloginfo('version'),
            'php_version'   => PHP_VERSION,
            'database'      => self::database(),
            'multisite'     => is_multisite(),
            'home_url'      => home_url(),
            'site_url'      => site_url(),
            'locale'        => get_locale(),
            'theme'         => $theme,
            'plugins'       => [
                'active'             => $plugins['active'],
                'inactive'           => $plugins['inactive'],
                'must_use'           => self::mustUsePlugins(),
                'updates_checked_at' => $plugins['checked_at'],
            ],
            'page_builders' => Detector::siteBuilders($plugins['active_slugs'], $activeThemes),
            'seo_plugins'   => Detector::seoPlugins($plugins['active_slugs']),
            'woocommerce'   => $plugins['woocommerce'],
            'permalinks'    => self::permalinks(),
            'site_health'   => self::siteHealth(),
        ];
    }

    /**
     * Database type and version from the server string, e.g.
     * `8.0.35` → MySQL 8.0.35, `5.5.5-10.6.12-MariaDB-log` → MariaDB 10.6.12.
     *
     * @return array{type: string, version: string}
     */
    public static function parseDbServer(string $serverInfo): array
    {
        if (stripos($serverInfo, 'mariadb') !== false) {
            return [
                'type'    => 'MariaDB',
                'version' => preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $serverInfo, $m) ? $m[1] : '',
            ];
        }

        return [
            'type'    => 'MySQL',
            'version' => preg_match('/^\d+(?:\.\d+)*/', $serverInfo, $m) ? $m[0] : '',
        ];
    }

    // ──────────────────────────────────────────────
    //  Sections
    // ──────────────────────────────────────────────

    /**
     * @return array{type: string, version: string, server_info: string}
     */
    private static function database(): array
    {
        global $wpdb;

        $info = method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : (string) $wpdb->db_version();

        return self::parseDbServer($info) + ['server_info' => $info];
    }

    /**
     * Installed plugins split into active/inactive, with cached update info.
     * `active_slugs` (slug → version) feeds the Detector.
     *
     * @return array{
     *     active: array<int, array<string, mixed>>,
     *     inactive: array<int, array<string, mixed>>,
     *     active_slugs: array<string, string>,
     *     checked_at: string|null,
     *     woocommerce: array<string, mixed>
     * }
     */
    private static function plugins(): array
    {
        $updates     = get_site_transient('update_plugins');
        $available   = is_object($updates) && isset($updates->response) && is_array($updates->response) ? $updates->response : [];
        $checkedAt   = is_object($updates) && !empty($updates->last_checked) ? gmdate('c', (int) $updates->last_checked) : null;
        $active      = [];
        $inactive    = [];
        $slugs       = [];
        $woocommerce = ['installed' => false, 'active' => false, 'version' => null];

        foreach (get_plugins() as $file => $data) {
            $file       = (string) $file;
            $slug       = Detector::pluginSlug($file);
            $isActive   = is_plugin_active($file);
            $newVersion = self::newVersion($available[$file] ?? null);

            $entry = [
                'file'             => $file,
                'slug'             => $slug,
                'name'             => (string) ($data['Name'] ?? $file),
                'version'          => (string) ($data['Version'] ?? ''),
                'network_active'   => is_multisite() && is_plugin_active_for_network($file),
                'update_available' => $newVersion !== null,
                'new_version'      => $newVersion,
            ];

            if ($isActive) {
                $active[]     = $entry;
                $slugs[$slug] = $entry['version'];
            } else {
                $inactive[] = $entry;
            }

            if ($slug === 'woocommerce') {
                $woocommerce = ['installed' => true, 'active' => $isActive, 'version' => $entry['version']];
            }
        }

        return [
            'active'       => $active,
            'inactive'     => $inactive,
            'active_slugs' => $slugs,
            'checked_at'   => $checkedAt,
            'woocommerce'  => $woocommerce,
        ];
    }

    /**
     * @return array<int, array{file: string, name: string, version: string}>
     */
    private static function mustUsePlugins(): array
    {
        $out = [];

        foreach (get_mu_plugins() as $file => $data) {
            $out[] = [
                'file'    => (string) $file,
                'name'    => (string) ($data['Name'] ?? $file),
                'version' => (string) ($data['Version'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function theme(): array
    {
        $updates   = get_site_transient('update_themes');
        $available = is_object($updates) && isset($updates->response) && is_array($updates->response) ? $updates->response : [];
        $theme     = wp_get_theme();
        $parent    = $theme->parent();

        return self::describeTheme($theme, $available) + [
            'block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme(),
            'parent'      => $parent instanceof \WP_Theme ? self::describeTheme($parent, $available) : null,
        ];
    }

    /**
     * @param array<string, mixed> $available update_themes->response.
     * @return array{name: string, stylesheet: string, version: string, update_available: bool, new_version: string|null}
     */
    private static function describeTheme(\WP_Theme $theme, array $available): array
    {
        $newVersion = self::newVersion($available[$theme->get_stylesheet()] ?? null);

        return [
            'name'             => (string) $theme->get('Name'),
            'stylesheet'       => $theme->get_stylesheet(),
            'version'          => (string) $theme->get('Version'),
            'update_available' => $newVersion !== null,
            'new_version'      => $newVersion,
        ];
    }

    /**
     * @return array{structure: string, plain: bool}
     */
    private static function permalinks(): array
    {
        $structure = (string) get_option('permalink_structure', '');

        return ['structure' => $structure, 'plain' => $structure === ''];
    }

    /**
     * @return array{critical: int|null, recommended: int|null, good: int|null, source: string}
     */
    private static function siteHealth(): array
    {
        $cached = get_transient(self::SITE_HEALTH_TRANSIENT);
        $counts = is_string($cached) ? json_decode($cached, true) : null;

        if (!is_array($counts)) {
            return ['critical' => null, 'recommended' => null, 'good' => null, 'source' => 'not_run'];
        }

        return [
            'critical'    => (int) ($counts['critical'] ?? 0),
            'recommended' => (int) ($counts['recommended'] ?? 0),
            'good'        => (int) ($counts['good'] ?? 0),
            'source'      => 'site_health_cache',
        ];
    }

    /**
     * `new_version` from an update_plugins / update_themes entry (object for plugins, array for themes).
     *
     * @param mixed $update
     */
    private static function newVersion($update): ?string
    {
        if (is_object($update) && isset($update->new_version)) {
            return (string) $update->new_version;
        }

        if (is_array($update) && isset($update['new_version'])) {
            return (string) $update['new_version'];
        }

        return null;
    }
}
