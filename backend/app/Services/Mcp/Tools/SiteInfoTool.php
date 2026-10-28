<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\ToolResult;

/**
 * site_info → GET site-info (cloud siteInfo.ts).
 */
final class SiteInfoTool extends Tool
{
    /** Plugins listed by name; the rest are counted. */
    private const MAX_ACTIVE_LISTED = 60;

    private const MAX_OTHER_LISTED = 30;

    public function run(\stdClass $args): ToolResult // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This tool takes no arguments.
    {
        return self::out(self::format($this->siteId, $this->client->siteInfo()['data']));
    }

    /**
     * @param array<string, mixed> $info
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, array $info): array
    {
        $updateOf = static fn (array $entry): ?string => $entry['update_available'] ? ($entry['new_version'] ?? 'yes') : null;
        $plugin   = static fn (array $entry): array => [
            'name'    => C::oneLine($entry['name'], 80),
            'slug'    => $entry['slug'],
            'version' => $entry['version'],
            'update'  => $updateOf($entry),
        ];
        $detected = static fn (array $entry): array => ['name' => C::oneLine($entry['name'], 80), 'version' => $entry['version'] ?? null];

        $active   = $info['plugins']['active'];
        $inactive = $info['plugins']['inactive'];
        $mustUse  = $info['plugins']['must_use'];
        $theme    = $info['theme'];
        $parent   = $theme['parent'];

        $s = [
            'site_id'       => $siteId,
            'home_url'      => $info['home_url'],
            'site_url'      => $info['site_url'],
            'wp_version'    => $info['wp_version'],
            'php_version'   => $info['php_version'],
            'database'      => trim($info['database']['type'] . ' ' . $info['database']['version']),
            'multisite'     => $info['multisite'],
            'locale'        => $info['locale'],
            'theme'         => [
                'name'        => C::oneLine($theme['name'], 80),
                'stylesheet'  => $theme['stylesheet'],
                'version'     => $theme['version'],
                'block_theme' => $theme['block_theme'],
                'update'      => $updateOf($theme),
                'parent'      => $parent === null ? null : [
                    'name'       => C::oneLine($parent['name'], 80),
                    'stylesheet' => $parent['stylesheet'],
                    'version'    => $parent['version'],
                    'update'     => $updateOf($parent),
                ],
            ],
            'plugins'       => [
                'active_count'      => count($active),
                'inactive_count'    => count($inactive),
                'must_use_count'    => count($mustUse),
                'updates_available' => count(array_filter(array_merge($active, $inactive), static fn (array $entry): bool => $entry['update_available'])),
                'active'            => array_map($plugin, array_slice($active, 0, self::MAX_ACTIVE_LISTED)),
                'inactive'          => array_map($plugin, array_slice($inactive, 0, self::MAX_OTHER_LISTED)),
                'must_use'          => array_map(
                    static fn (array $entry): array => ['name' => C::oneLine($entry['name'], 80), 'version' => $entry['version']],
                    array_slice($mustUse, 0, self::MAX_OTHER_LISTED)
                ),
                'lists_truncated'   => count($active) > self::MAX_ACTIVE_LISTED || count($inactive) > self::MAX_OTHER_LISTED || count($mustUse) > self::MAX_OTHER_LISTED,
            ],
            'page_builders' => array_map($detected, $info['page_builders']),
            'seo_plugins'   => array_map($detected, $info['seo_plugins']),
            'woocommerce'   => $info['woocommerce'],
            'permalinks'    => $info['permalinks']['plain'] ? 'plain (?p=123)' : $info['permalinks']['structure'],
            'site_health'   => $info['site_health'],
        ];

        $versioned  = static fn (array $entry): string => $entry['version'] === null || $entry['version'] === '' ? $entry['name'] : $entry['name'] . ' ' . $entry['version'];
        $pluginLine = static fn (array $entry): string => '- ' . $entry['name'] . ' (' . $entry['slug'] . ') ' . $entry['version'] . ($entry['update'] === null ? '' : ' → update ' . $entry['update']);
        $more       = static fn (int $shown, int $total): array => $total > $shown ? ['- … ' . ($total - $shown) . ' more'] : [];
        $health     = $s['site_health']['source'] === 'not_run'
            ? 'not run yet'
            : ($s['site_health']['critical'] ?? 0) . ' critical, ' . ($s['site_health']['recommended'] ?? 0) . ' recommended, ' . ($s['site_health']['good'] ?? 0) . ' good';
        $t          = $s['theme'];
        $p          = $s['plugins'];
        $woo        = $s['woocommerce'];

        $body = [
            'WordPress ' . $s['wp_version'] . ' · PHP ' . $s['php_version'] . ' · ' . $s['database'] . ' · ' . $s['locale'] . ' · ' . ($s['multisite'] ? 'multisite' : 'single site'),
            'Home: ' . $s['home_url'] . ($s['site_url'] === $s['home_url'] ? '' : ' (WordPress files at ' . $s['site_url'] . ')'),
            'Theme: ' . $t['name'] . ' ' . $t['version'] . ' (' . $t['stylesheet'] . ($t['block_theme'] ? ', block theme' : ', classic theme') . ')'
                . ($t['update'] === null ? '' : ' → update ' . $t['update']),
        ];

        if ($t['parent'] !== null) {
            $body[] = 'Parent theme: ' . $t['parent']['name'] . ' ' . $t['parent']['version'] . ' (' . $t['parent']['stylesheet'] . ')'
                . ($t['parent']['update'] === null ? '' : ' → update ' . $t['parent']['update']);
        }

        $body[] = 'Page builders: ' . ($s['page_builders'] === [] ? 'none detected' : implode(', ', array_map($versioned, $s['page_builders'])));
        $body[] = 'SEO plugins: ' . ($s['seo_plugins'] === [] ? 'none detected' : implode(', ', array_map($versioned, $s['seo_plugins'])));
        $body[] = 'WooCommerce: ' . ($woo['active'] ? trim('active ' . ($woo['version'] ?? '')) : ($woo['installed'] ? 'installed, inactive' : 'not installed'));
        $body[] = 'Permalinks: ' . $s['permalinks'];
        $body[] = 'Site Health: ' . $health;
        $body[] = 'Plugins: ' . $p['active_count'] . ' active, ' . $p['inactive_count'] . ' inactive, ' . $p['must_use_count'] . ' must-use; ' . $p['updates_available'] . ' with updates';

        if ($p['active_count'] > 0) {
            array_push($body, 'Active:', ...array_map($pluginLine, $p['active']), ...$more(count($p['active']), $p['active_count']));
        }

        if ($p['inactive_count'] > 0) {
            array_push($body, 'Inactive:', ...array_map($pluginLine, $p['inactive']), ...$more(count($p['inactive']), $p['inactive_count']));
        }

        if ($p['must_use_count'] > 0) {
            array_push($body, 'Must-use:', ...array_map(static fn (array $entry): string => '- ' . $versioned($entry), $p['must_use']), ...$more(count($p['must_use']), $p['must_use_count']));
        }

        if ($s['page_builders'] !== []) {
            $next = 'This site uses a page builder (listed above), so its content is not plain post content: look at a page with get_page_html before suggesting changes to it.';
        } else {
            $next = $p['lists_truncated'] ? 'For the full plugin list, use run_wp_cli with "plugin list".' : null;
        }

        return ['text' => C::finish(['Site ' . $siteId . ':', C::siteData('site-info', implode("\n", $body))], $next), 'structured' => $s];
    }
}
