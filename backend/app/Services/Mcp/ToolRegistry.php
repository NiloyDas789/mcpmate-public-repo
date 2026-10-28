<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\Tools\ToolCatalog;
use McpMate\Services\Tools\ToolRegistry as SiteTools;
use McpMate\Services\Tools\ToolSettings;

/**
 * The MCP tools: names, titles, descriptions, schemas and annotations from
 * docs/mcp-tools.json (edited only there, docs/mcp-in-plugin.md §7.4), each
 * with its PHP handler (§7.1, items 1–2).
 *
 * A grant's tools/list holds exactly the tools it may call now (§4.6, the
 * cloud's register() filter): ping, and every catalog tool that a site
 * admin left on, that this site can run and that the grant's access level
 * allows; a grant without site:write lists only tools with a read call.
 * Nothing else can add to the list: there is no filter.
 */
final class ToolRegistry
{
    /** Tool name => handler, in the order the definitions file lists them. */
    private const HANDLERS = [
        'ping'                => Tools\PingTool::class,
        'list_skills'         => Tools\ListSkillsTool::class,
        'get_skill'           => Tools\GetSkillTool::class,
        'save_skill'          => Tools\SaveSkillTool::class,
        'site_info'           => Tools\SiteInfoTool::class,
        'get_page_html'       => Tools\GetPageHtmlTool::class,
        'get_audit_log'       => Tools\GetAuditLogTool::class,
        'run_wp_cli'          => Tools\RunWpCliTool::class,
        'query_db'            => Tools\QueryDbTool::class,
        'check_approval'      => Tools\CheckApprovalTool::class,
        'search_stock_photos' => Tools\SearchStockPhotosTool::class,
        'upload_media'        => Tools\UploadMediaTool::class,
        'create_draft_theme'  => Tools\CreateDraftThemeTool::class,
        'list_theme_files'    => Tools\ListThemeFilesTool::class,
        'read_theme_file'     => Tools\ReadThemeFileTool::class,
        'write_theme_file'    => Tools\WriteThemeFileTool::class,
        'edit_theme_file'     => Tools\EditThemeFileTool::class,
        'get_preview_url'     => Tools\GetPreviewUrlTool::class,
        'publish_draft_theme' => Tools\PublishDraftThemeTool::class,
        'rollback_theme'      => Tools\RollbackThemeTool::class,
        'list_abilities'      => Tools\ListAbilitiesTool::class,
        'run_ability'         => Tools\RunAbilityTool::class,
        'list_block_types'    => Tools\ListBlockTypesTool::class,
        'validate_blocks'     => Tools\ValidateBlocksTool::class,
        'elementor_get'       => Tools\ElementorGetTool::class,
        'elementor_widgets'   => Tools\ElementorWidgetsTool::class,
        'elementor_save'      => Tools\ElementorSaveTool::class,
    ];

    /** Tools every grant has, whatever the catalog says (like GET ping). */
    private const OPEN_TOOLS = ['ping'];

    /** @var array{instructions: string, tools: array<string, \stdClass>}|null */
    private static ?array $definitions = null;

    /** @var string|null Override of the definitions file (tests). */
    private static ?string $file = null;

    public static function useFile(?string $file): void
    {
        self::$file        = $file;
        self::$definitions = null;
    }

    public static function file(): string
    {
        return self::$file ?? Config::get('ROOT_DIR') . 'docs/mcp-tools.json';
    }

    public static function instructions(): string
    {
        return self::load()['instructions'];
    }

    /**
     * Every definition by name, in the file's order.
     *
     * @return array<string, \stdClass>
     */
    public static function definitions(): array
    {
        return self::load()['tools'];
    }

    /**
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::HANDLERS);
    }

    public static function definition(string $name): ?\stdClass
    {
        return self::definitions()[$name] ?? null;
    }

    /**
     * The definitions the current grant (McpContext) may call now.
     *
     * @return \stdClass[]
     */
    public static function visible(): array
    {
        return array_values(array_filter(self::definitions(), static fn (\stdClass $tool): bool => self::isVisible((string) $tool->name)));
    }

    public static function isVisible(string $name): bool
    {
        if (!isset(self::HANDLERS[$name]) || self::definition($name) === null || !McpContext::active()) {
            return false;
        }

        if (!McpContext::canWrite() && !Policy::hasReadCalls($name)) {
            return false;
        }

        if (in_array($name, self::OPEN_TOOLS, true)) {
            return true;
        }

        $tool = ToolCatalog::tool($name);

        return $tool !== null
            && ToolSettings::isEnabled($name)
            && SiteTools::availability($tool['group'])['available']
            && McpContext::allowsTool($name);
    }

    public static function handler(string $name, InternalClient $client): Tools\Tool
    {
        $class = self::HANDLERS[$name];

        return new $class($client, McpSite::id(), McpSite::url());
    }

    /**
     * @return array{instructions: string, tools: array<string, \stdClass>}
     */
    private static function load(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file shipped with the plugin.
        $json  = (string) file_get_contents(self::file());
        $data  = json_decode($json, false);
        $tools = [];

        foreach ((array) ($data->tools ?? []) as $tool) {
            if ($tool instanceof \stdClass && isset(self::HANDLERS[(string) ($tool->name ?? '')])) {
                $tools[(string) $tool->name] = $tool;
            }
        }

        self::$definitions = ['instructions' => (string) ($data->instructions ?? ''), 'tools' => $tools];

        return self::$definitions;
    }
}
