<?php

namespace McpMate\Services\Tools;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The MCP tools that reach this site (docs/ui.md §1.2, B1), grouped as the
 * Tools page shows them, and the plugin routes each one calls. Pure: no
 * WordPress calls.
 *
 * This is an allowlist. Router::authorizeMcp() maps every request an MCP
 * call dispatches to its tool through ROUTES; a route that is not in ROUTES
 * is refused, and so is a tool that is off (ToolSettings) or outside the
 * grant's tool list.
 *
 * Tool names are the MCP tools' (docs/mcp-tools.json, Services/Mcp/Policy),
 * except `core_content`, which no MCP tool uses yet (connection-v2 Q8).
 * The skills and stock photo tools reach no route; the MCP layer answers
 * them. docs/tool-catalog-vectors.json lists them for the tests.
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
        'posts'         => self::SOURCE_CORE,
        'blocks'        => self::SOURCE_CORE,
        'media'         => self::SOURCE_CORE,
        'theme_drafts'  => self::SOURCE_CORE,
        'database'      => self::SOURCE_CORE,
        'wp_cli'        => self::SOURCE_CORE,
        'elementor'     => self::SOURCE_INTEGRATIONS,
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
        'core_content'        => ['group' => 'posts', 'kind' => self::KIND_WRITE, 'default' => false],
        'list_block_types'    => ['group' => 'blocks', 'kind' => self::KIND_READ, 'default' => true],
        'validate_blocks'     => ['group' => 'blocks', 'kind' => self::KIND_READ, 'default' => true],
        'upload_media'        => ['group' => 'media', 'kind' => self::KIND_WRITE, 'default' => false],
        'create_draft_theme'  => ['group' => 'theme_drafts', 'kind' => self::KIND_WRITE, 'default' => false],
        'list_theme_files'    => ['group' => 'theme_drafts', 'kind' => self::KIND_READ, 'default' => false],
        'read_theme_file'     => ['group' => 'theme_drafts', 'kind' => self::KIND_READ, 'default' => false],
        'write_theme_file'    => ['group' => 'theme_drafts', 'kind' => self::KIND_WRITE, 'default' => false],
        'edit_theme_file'     => ['group' => 'theme_drafts', 'kind' => self::KIND_WRITE, 'default' => false],
        'get_preview_url'     => ['group' => 'theme_drafts', 'kind' => self::KIND_READ, 'default' => false],
        'publish_draft_theme' => ['group' => 'theme_drafts', 'kind' => self::KIND_WRITE, 'approval' => true, 'default' => false],
        'rollback_theme'      => ['group' => 'theme_drafts', 'kind' => self::KIND_WRITE, 'approval' => true, 'default' => false],
        'query_db'            => ['group' => 'database', 'kind' => self::KIND_READ, 'default' => false],
        'run_wp_cli'          => ['group' => 'wp_cli', 'kind' => self::KIND_WRITE, 'default' => true, 'items' => 'cli'],
        'elementor_get'       => ['group' => 'elementor', 'kind' => self::KIND_READ, 'default' => false],
        'elementor_widgets'   => ['group' => 'elementor', 'kind' => self::KIND_READ, 'default' => false],
        'elementor_save'      => ['group' => 'elementor', 'kind' => self::KIND_WRITE, 'default' => false],
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
        'POST proxy'                            => 'core_content',
        'GET blocks/types'                      => 'list_block_types',
        'POST blocks/validate'                  => 'validate_blocks',
        'POST media'                            => 'upload_media',
        'POST theme-draft'                      => 'create_draft_theme',
        'GET theme-draft'                       => 'list_theme_files',
        'GET theme-draft/{id}/files'            => 'list_theme_files',
        'GET theme-draft/{id}/file'             => 'read_theme_file',
        'PUT theme-draft/{id}/file'             => 'write_theme_file',
        'DELETE theme-draft/{id}/file'          => 'write_theme_file',
        'POST theme-draft/{id}/file/replace'    => 'edit_theme_file',
        'POST theme-draft/{id}/preview'         => 'get_preview_url',
        'POST theme-draft/publish'              => 'publish_draft_theme',
        'POST theme-draft/rollback'             => 'rollback_theme',
        'GET db/tables'                         => 'query_db',
        'GET db/tables/{table}/schema'          => 'query_db',
        'GET db/tables/{table}/rows'            => 'query_db',
        'POST cli'                              => 'run_wp_cli',
        'GET elementor/{post_id}'               => 'elementor_get',
        'GET elementor/widgets'                 => 'elementor_widgets',
        'POST elementor/{post_id}'              => 'elementor_save',
        'POST elementor'                        => 'elementor_save',
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
        return array_keys(self::GROUPS);
    }

    /**
     * @return array{id: string, label: string, source: string}|null
     */
    public static function group(string $id): ?array
    {
        if (!isset(self::GROUPS[$id])) {
            return null;
        }

        return ['id' => $id, 'label' => self::texts()['groups'][$id], 'source' => self::GROUPS[$id]];
    }

    /**
     * @return string[] Every tool name, in display order.
     */
    public static function names(): array
    {
        return array_keys(self::TOOLS);
    }

    public static function has(string $name): bool
    {
        return isset(self::TOOLS[$name]);
    }

    /**
     * @return array{name: string, group: string, label: string, description: string, kind: string, approval: bool, default: bool, always_on: bool, items: string}|null
     */
    public static function tool(string $name): ?array
    {
        if (!isset(self::TOOLS[$name])) {
            return null;
        }

        $tool = self::TOOLS[$name];
        $text = self::texts()['tools'][$name];

        return [
            'name'        => $name,
            'group'       => $tool['group'],
            'label'       => $text[0],
            'description' => $text[1],
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
        return array_keys(array_filter(self::TOOLS, static fn (array $tool): bool => $tool['group'] === $group));
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
        return self::ROUTES[strtoupper($method) . ' ' . $path] ?? null;
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
        return self::ROUTES;
    }

    /**
     * @return string[]
     */
    public static function openRoutes(): array
    {
        return self::OPEN_ROUTES;
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
                'posts' => __('Posts and pages', 'mcpmate'),
                'blocks' => __('Blocks', 'mcpmate'),
                'media' => __('Media', 'mcpmate'),
                'theme_drafts' => __('Theme drafts', 'mcpmate'),
                'database' => __('Database', 'mcpmate'),
                'wp_cli' => __('WP-CLI commands', 'mcpmate'),
                'elementor' => __('Elementor', 'mcpmate'),
                'abilities' => __('Abilities', 'mcpmate'),
                'skills' => __('Skills', 'mcpmate'),
                'stock_photos' => __('Stock photos', 'mcpmate'),
            ],
            'tools'  => [
                'site_info' => [__('Get a summary of this site', 'mcpmate'), __('Reads the site name, versions, theme, plugins, page builder and SEO plugin.', 'mcpmate')],
                'get_page_html' => [__('Read a page\'s HTML', 'mcpmate'), __('Reads the HTML of a page on this site, and how a post is built.', 'mcpmate')],
                'get_audit_log' => [__('Read the audit log', 'mcpmate'), __('Reads McpMate\'s record of what AI apps did on this site.', 'mcpmate')],
                'check_approval' => [__('Check on an approval request', 'mcpmate'), __('Asks whether you approved or rejected a request that waits for you.', 'mcpmate')],
                'core_content' => [__('Posts and pages', 'mcpmate'), __('Reads posts, pages, categories and tags, and saves posts and pages as drafts.', 'mcpmate')],
                'list_block_types' => [__('List block types', 'mcpmate'), __('Lists the blocks this site can use.', 'mcpmate')],
                'validate_blocks' => [__('Check block markup', 'mcpmate'), __('Checks block markup before anything saves it. Saves nothing.', 'mcpmate')],
                'upload_media' => [__('Upload an image or PDF', 'mcpmate'), __('Adds an image or PDF to the media library, and can attach it to a draft.', 'mcpmate')],
                'create_draft_theme' => [__('Create a draft copy of the theme', 'mcpmate'), __('Copies the active theme into a draft that visitors never see.', 'mcpmate')],
                'list_theme_files' => [__('List draft theme files', 'mcpmate'), __('Lists the drafts and the files in a draft theme.', 'mcpmate')],
                'read_theme_file' => [__('Read a draft theme file', 'mcpmate'), __('Reads a file in a draft theme.', 'mcpmate')],
                'write_theme_file' => [__('Write a draft theme file', 'mcpmate'), __('Saves or deletes a file in a draft theme, after checking its code.', 'mcpmate')],
                'edit_theme_file' => [__('Edit part of a draft theme file', 'mcpmate'), __('Replaces one exact piece of text in a draft theme file.', 'mcpmate')],
                'get_preview_url' => [__('Make a preview link', 'mcpmate'), __('Makes a short-lived link that shows the site with a draft theme.', 'mcpmate')],
                'publish_draft_theme' => [__('Publish a draft theme', 'mcpmate'), __('Makes a draft the live theme, after a backup. Waits for your approval.', 'mcpmate')],
                'rollback_theme' => [__('Undo a theme publish', 'mcpmate'), __('Puts the theme from a backup back. Waits for your approval.', 'mcpmate')],
                'query_db' => [__('Look up database rows', 'mcpmate'), __('Reads this site\'s tables. Passwords and secret values are never shown.', 'mcpmate')],
                'run_wp_cli' => [__('Run WP-CLI commands', 'mcpmate'), __('Runs the WP-CLI commands you turn on, in PHP. Never a shell.', 'mcpmate')],
                'elementor_get' => [__('Read an Elementor page', 'mcpmate'), __('Reads the elements and settings of a page built with Elementor.', 'mcpmate')],
                'elementor_widgets' => [__('List Elementor widgets', 'mcpmate'), __('Lists the Elementor widgets and settings this site can use.', 'mcpmate')],
                'elementor_save' => [__('Save an Elementor page as a draft', 'mcpmate'), __('Saves or creates a draft page through Elementor itself.', 'mcpmate')],
                'list_abilities' => [__('List abilities', 'mcpmate'), __('Lists the abilities your other plugins offer.', 'mcpmate')],
                'run_ability' => [__('Run abilities', 'mcpmate'), __('Runs the abilities you turn on. Each runs its own plugin\'s code.', 'mcpmate')],
                'list_skills' => [__('List skills', 'mcpmate'), __('Lists McpMate\'s playbooks and the conventions you asked AI apps to remember.', 'mcpmate')],
                'get_skill' => [__('Load a skill', 'mcpmate'), __('Reads one playbook or saved convention in full.', 'mcpmate')],
                'save_skill' => [__('Save a skill', 'mcpmate'), __('Remembers a convention you ask for, for later conversations. Changes nothing on the site.', 'mcpmate')],
                'search_stock_photos' => [__('Search stock photos', 'mcpmate'), __('Searches Unsplash with your own key. Uploading one still needs Media uploads.', 'mcpmate')],
            ],
        ];
    }
}
