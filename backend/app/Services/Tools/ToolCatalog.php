<?php

namespace MCPMate\Services\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Extensions\Features;

/**
 * The MCP tools that reach this site (docs/ui.md §1.2, B1), grouped as the
 * Tools page shows them, and the plugin routes each one calls. Pure apart
 * from translations.
 *
 * This is an allowlist. Router::authorizeMcp() maps every request an MCP
 * call dispatches to its tool through ROUTES; a route that is not in ROUTES
 * is refused, and so is a tool that is off (ToolSettings) or outside the
 * grant's tool list.
 *
 * Tool names are the MCP tools' (docs/mcp-tools.json, Services/Mcp/Policy).
 * The skills and stock photo tools reach no route; the MCP layer answers
 * them. docs/tool-catalog-vectors.json lists them for the tests.
 *
 * MCPMate Pro adds its tools, groups and routes through Features (never a
 * filter): a feature cannot replace a free entry, and maps routes only to
 * its own tools.
 */
final class ToolCatalog
{
    public const SOURCE_CORE = 'core';

    public const SOURCE_INTEGRATIONS = 'integrations';

    public const SOURCE_ABILITIES = 'abilities';

    public const KIND_READ = 'read';

    public const KIND_WRITE = 'write';

    /**
     * Groups in display order: id => source. Labels are in texts().
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        'site_overview' => self::SOURCE_CORE,
        'blocks'        => self::SOURCE_CORE,
        'database'      => self::SOURCE_CORE,
        'wp_cli'        => self::SOURCE_CORE,
        'abilities'     => self::SOURCE_ABILITIES,
        'skills'        => self::SOURCE_CORE,
        'stock_photos'  => self::SOURCE_INTEGRATIONS,
    ];

    /**
     * Tools in display order; labels and descriptions are in texts().
     * `default` is the state before a site admin chooses (rule 1: a new
     * tool ships off); `always_on` tools cannot be
     * turned off (check_approval: turning it off would strand pending
     * requests); `items` names the per-item enable list a tool has.
     *
     * @var array<string, array{group: string, kind: string, approval?: bool, default: bool, always_on?: bool, items?: string}>
     */
    private const TOOLS = [
        'site_info'           => ['group' => 'site_overview', 'kind' => self::KIND_READ, 'default' => true],
        'get_page_html'       => ['group' => 'site_overview', 'kind' => self::KIND_READ, 'default' => true],
        'get_audit_log'       => ['group' => 'site_overview', 'kind' => self::KIND_READ, 'default' => true],
        'check_approval'      => ['group' => 'site_overview', 'kind' => self::KIND_READ, 'default' => true, 'always_on' => true],
        'list_block_types'    => ['group' => 'blocks', 'kind' => self::KIND_READ, 'default' => true],
        'validate_blocks'     => ['group' => 'blocks', 'kind' => self::KIND_READ, 'default' => true],
        'query_db'            => ['group' => 'database', 'kind' => self::KIND_READ, 'default' => false],
        // Gated by their item lists: the commands and abilities a site admin enables by name.
        'run_wp_cli'          => ['group' => 'wp_cli', 'kind' => self::KIND_WRITE, 'default' => true, 'items' => 'cli'],
        'list_abilities'      => ['group' => 'abilities', 'kind' => self::KIND_READ, 'default' => true],
        'run_ability'         => ['group' => 'abilities', 'kind' => self::KIND_WRITE, 'default' => true, 'items' => 'abilities'],
        // Answered by the plugin's MCP layer itself, no route (docs/mcp-in-plugin.md §7.2).
        'list_skills'         => ['group' => 'skills', 'kind' => self::KIND_READ, 'default' => true],
        'get_skill'           => ['group' => 'skills', 'kind' => self::KIND_READ, 'default' => true],
        'save_skill'          => ['group' => 'skills', 'kind' => self::KIND_WRITE, 'default' => true],
        'search_stock_photos' => ['group' => 'stock_photos', 'kind' => self::KIND_READ, 'default' => false],
    ];

    /**
     * Route (`METHOD path`, as declared in backend/hooks/api.php) => tool.
     *
     * @var array<string, string>
     */
    private const ROUTES = [
        'GET site-info'                         => 'site_info',
        'GET page-html'                         => 'get_page_html',
        'GET post-structure'                    => 'get_page_html',
        'GET audit-log'                         => 'get_audit_log',
        'POST approvals/{id}/status'            => 'check_approval',
        'GET blocks/types'                      => 'list_block_types',
        'POST blocks/validate'                  => 'validate_blocks',
        'GET db/tables'                         => 'query_db',
        'GET db/tables/{table}/schema'          => 'query_db',
        'GET db/tables/{table}/rows'            => 'query_db',
        'POST cli'                              => 'run_wp_cli',
        'GET abilities'                         => 'list_abilities',
        'POST abilities/run'                    => 'run_ability',
    ];

    /**
     * Routes no tool calls: the plugin's own check (the setup loopback) and
     * the Tools page's list. Only wp-admin reaches them.
     */
    private const OPEN_ROUTES = [
        'GET ping',
        'GET tools',
    ];

    // ──────────────────────────────────────────────
    //  Groups and tools
    // ──────────────────────────────────────────────

    /**
     * @return string[] Group IDs in display order.
     */
    public static function groupIds(): array
    {
        return array_keys(self::allGroups());
    }

    /**
     * @return array{id: string, label: string, source: string}|null
     */
    public static function group(string $id): ?array
    {
        $group = self::allGroups()[$id] ?? null;

        return $group === null ? null : ['id' => $id] + $group;
    }

    /**
     * @return string[] Every tool name, in display order.
     */
    public static function names(): array
    {
        return array_keys(self::allTools());
    }

    public static function has(string $name): bool
    {
        return isset(self::allTools()[$name]);
    }

    /**
     * @return array{name: string, group: string, label: string, description: string, kind: string, approval: bool, default: bool, always_on: bool, items: string}|null
     */
    public static function tool(string $name): ?array
    {
        $tool = self::allTools()[$name] ?? null;

        if ($tool === null) {
            return null;
        }

        return [
            'name'        => $name,
            'group'       => $tool['group'],
            'label'       => $tool['label'],
            'description' => $tool['description'],
            'kind'        => $tool['kind'],
            'approval'    => $tool['approval'] ?? false,
            'default'     => $tool['default'],
            'always_on'   => $tool['always_on'] ?? false,
            'items'       => $tool['items'] ?? '',
        ];
    }

    /**
     * @return string[] The group's tools, in display order.
     */
    public static function toolsIn(string $group): array
    {
        return array_keys(array_filter(self::allTools(), static fn (array $tool): bool => $tool['group'] === $group));
    }

    // ──────────────────────────────────────────────
    //  Routes
    // ──────────────────────────────────────────────

    /**
     * The tool behind a route, or null when no tool uses it.
     *
     * @param string $method HTTP method of the route declaration.
     * @param string $path   Route path as declared (`theme-draft/{id}/file`).
     */
    public static function toolForRoute(string $method, string $path): ?string
    {
        return self::routes()[strtoupper($method) . ' ' . $path] ?? null;
    }

    /**
     * Whether a user grant reaches the route without naming a tool.
     */
    public static function isOpenRoute(string $method, string $path): bool
    {
        return in_array(strtoupper($method) . ' ' . $path, self::OPEN_ROUTES, true);
    }

    /**
     * Every mapped route, `METHOD path` => tool (tests check that each
     * declared route is mapped or open).
     *
     * @return array<string, string>
     */
    public static function routes(): array
    {
        $routes = self::ROUTES;
        $tools  = Features::tools();

        foreach (Features::routes() as $route => $tool) {
            // A feature maps routes to its own tools only, and never remaps one.
            if (isset($tools[$tool]) && !isset(self::TOOLS[$tool]) && !isset($routes[$route]) && !in_array($route, self::OPEN_ROUTES, true)) {
                $routes[$route] = $tool;
            }
        }

        return $routes;
    }

    /**
     * @return string[]
     */
    public static function openRoutes(): array
    {
        return self::OPEN_ROUTES;
    }

    /**
     * The free groups and the features' (MCPMate Pro), in display order.
     *
     * @return array<string, array{label: string, source: string}>
     */
    private static function allGroups(): array
    {
        $texts  = self::texts()['groups'];
        $groups = [];

        foreach (self::GROUPS as $id => $source) {
            $groups[$id] = ['label' => $texts[$id], 'source' => $source];
        }

        foreach (Features::groups() as $id => $group) {
            if (isset($groups[$id])) {
                continue;
            }

            $entry = ['label' => (string) $group['label'], 'source' => (string) $group['source']];
            $after = (string) ($group['after'] ?? '');
            $keys  = array_keys($groups);

            if ($after !== '' && isset($groups[$after])) {
                $at = (int) array_search($after, $keys, true) + 1;
            } else {
                $skills = array_search('skills', $keys, true);
                $at     = $skills === false ? count($groups) : (int) $skills;
            }

            $groups = array_slice($groups, 0, $at, true) + [$id => $entry] + array_slice($groups, $at, null, true);
        }

        return $groups;
    }

    /**
     * The free tools and the features' (each in a group that exists).
     *
     * @return array<string, array{group: string, kind: string, approval?: bool, default: bool, always_on?: bool, items?: string, label: string, description: string}>
     */
    private static function allTools(): array
    {
        $texts = self::texts()['tools'];
        $tools = [];

        foreach (self::TOOLS as $name => $tool) {
            $tools[$name] = $tool + ['label' => $texts[$name][0], 'description' => $texts[$name][1]];
        }

        $groups = self::allGroups();

        foreach (Features::tools() as $name => $tool) {
            if (!isset($tools[$name]) && isset($groups[$tool['group'] ?? ''])) {
                $tools[$name] = $tool;
            }
        }

        // Display order: by group, then as listed (the plugin's before a feature's).
        $ordered = [];
        foreach (array_keys($groups) as $group) {
            $ordered += array_filter($tools, static fn (array $tool): bool => $tool['group'] === $group);
        }

        return $ordered;
    }

    /**
     * Translated labels: groups, and tools as [label, description].
     *
     * @return array{groups: array<string, string>, tools: array<string, array{0: string, 1: string}>}
     */
    private static function texts(): array
    {
        return [
            'groups' => [
                'site_overview' => __('Site overview', 'mcpmate'),
                'blocks' => __('Blocks', 'mcpmate'),
                'database' => __('Database', 'mcpmate'),
                'wp_cli' => __('WP-CLI commands', 'mcpmate'),
                'abilities' => __('Abilities', 'mcpmate'),
                'skills' => __('Skills', 'mcpmate'),
                'stock_photos' => __('Stock photos', 'mcpmate'),
            ],
            'tools'  => [
                'site_info' => [__('Get a summary of this site', 'mcpmate'), __('Reads the site name, versions, theme, plugins, page builder and SEO plugin.', 'mcpmate')],
                'get_page_html' => [__('Read a page\'s HTML', 'mcpmate'), __('Reads the HTML of a page on this site, and how a post is built.', 'mcpmate')],
                'get_audit_log' => [__('Read the audit log', 'mcpmate'), __('Reads MCPMate\'s record of what AI apps did on this site.', 'mcpmate')],
                'check_approval' => [__('Check on an approval request', 'mcpmate'), __('Asks whether you approved or rejected a request that waits for you.', 'mcpmate')],
                'list_block_types' => [__('List block types', 'mcpmate'), __('Lists the blocks this site can use.', 'mcpmate')],
                'validate_blocks' => [__('Check block markup', 'mcpmate'), __('Checks block markup before anything saves it. Saves nothing.', 'mcpmate')],
                'query_db' => [__('Look up database rows', 'mcpmate'), __('Reads this site\'s tables. Passwords and secret values are never shown.', 'mcpmate')],
                'run_wp_cli' => [__('Run WP-CLI commands', 'mcpmate'), __('Runs the WP-CLI commands you turn on, in PHP. Never a shell.', 'mcpmate')],
                'list_abilities' => [__('List abilities', 'mcpmate'), __('Lists the abilities your other plugins offer.', 'mcpmate')],
                'run_ability' => [__('Run abilities', 'mcpmate'), __('Runs the abilities you turn on. Each runs its own plugin\'s code.', 'mcpmate')],
                'list_skills' => [__('List skills', 'mcpmate'), __('Lists MCPMate\'s playbooks and the conventions you asked AI apps to remember.', 'mcpmate')],
                'get_skill' => [__('Load a skill', 'mcpmate'), __('Reads one playbook or saved convention in full.', 'mcpmate')],
                'save_skill' => [__('Save a skill', 'mcpmate'), __('Remembers a convention you ask for, for later conversations. Changes nothing on the site.', 'mcpmate')],
                'search_stock_photos' => [__('Search stock photos', 'mcpmate'), __('Searches Unsplash with your own key. Uploading one still needs Media uploads.', 'mcpmate')],
            ],
        ];
    }
}
