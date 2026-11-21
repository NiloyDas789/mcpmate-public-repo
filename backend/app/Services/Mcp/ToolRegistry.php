<?php

namespace MCPMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Extensions\Features;
use MCPMate\Extensions\ProUpsell;
use MCPMate\Services\Connection\ReadMode;
use MCPMate\Services\Tools\ToolCatalog;
use MCPMate\Services\Tools\ToolRegistry as SiteTools;
use MCPMate\Services\Tools\ToolSettings;

/**
 * The MCP tools: names, titles, descriptions, schemas and annotations from
 * docs/mcp-tools.json (edited only there, docs/mcp-in-plugin.md §7.4), each
 * with its PHP handler (§7.1, items 1–2).
 *
 * A grant's tools/list holds exactly the tools it may call now (§4.6, the
 * cloud's register() filter): ping, and every catalog tool that a site
 * admin left on, that this site can run and that the grant's access level
 * allows; a grant without site:write lists only tools with a read call.
 * MCPMate Pro adds its tools through Features (handlers and a definitions
 * file of its own); nothing else can add to the list: there is no filter.
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
        'list_abilities'      => Tools\ListAbilitiesTool::class,
        'run_ability'         => Tools\RunAbilityTool::class,
        'list_block_types'    => Tools\ListBlockTypesTool::class,
        'validate_blocks'     => Tools\ValidateBlocksTool::class,
    ];

    /** Tools every grant has, whatever the catalog says (like GET ping). */
    private const OPEN_TOOLS = ['ping'];

    /** @var array{instructions: string, tools: array<string, \stdClass>}|null */
    private static ?array $definitions = null;

    /** The registered features $definitions were read with: a change of them reads the files again. */
    private static string $definitionsFor = '';

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

    /**
     * The server `instructions` (initialize, server/discover): each
     * feature's leading text (MCPMate Pro's site instructions), then the
     * definitions files' instructions, then each feature's trailing text
     * (what depends on the grant's tools, such as memory), blocks separated
     * by a blank line. While read mode is on, ReadMode::INSTRUCTION comes
     * first.
     */
    public static function instructions(): string
    {
        $leading  = [];
        $trailing = [];

        foreach (Features::all() as $feature) {
            $leading[]  = trim($feature->leadingInstructions());
            $trailing[] = trim($feature->trailingInstructions());
        }

        // While MCPMate Pro does not run here, what it would add, so the app can tell the user.
        $parts = array_merge($leading, [self::load()['instructions']], $trailing, [ProUpsell::instructions()]);

        if (ReadMode::isOn()) {
            // First, before the site admin's own words, so no instruction reads as permission to write.
            array_unshift($parts, ReadMode::INSTRUCTION);
        }

        return implode("\n\n", array_values(array_filter($parts, static fn (string $part): bool => $part !== '')));
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
        return array_keys(self::handlers());
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
        if (!isset(self::handlers()[$name]) || self::definition($name) === null || !McpContext::active()) {
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
        $class = self::handlers()[$name];

        return new $class($client, McpSite::id(), McpSite::url());
    }

    /**
     * The free handlers and MCPMate Pro's (a feature never replaces one).
     *
     * @return array<string, class-string>
     */
    private static function handlers(): array
    {
        $handlers = self::HANDLERS;

        foreach (Features::handlers() as $name => $class) {
            if (!isset($handlers[$name]) && is_subclass_of($class, Tools\Tool::class)) {
                $handlers[$name] = $class;
            }
        }

        return $handlers;
    }

    /**
     * @return array{instructions: string, tools: array<string, \stdClass>}
     */
    private static function load(): array
    {
        $features = implode(',', array_map(static fn ($feature): string => $feature->id(), Features::all()));

        // Kept until the registered features change (Features::reset() and register() in tests).
        if (self::$definitions !== null && self::$definitionsFor === $features) {
            return self::$definitions;
        }

        self::$definitionsFor = $features;

        $handlers     = self::handlers();
        $instructions = [];
        $tools        = [];
        $files        = [self::file()];

        // MCPMate Pro's definitions, after the plugin's (the tests' file override stands alone).
        if (self::$file === null) {
            foreach (Features::all() as $feature) {
                if ($feature->definitionsFile() !== '') {
                    $files[] = $feature->definitionsFile();
                }
            }
        }

        foreach ($files as $file) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file shipped with the plugin or MCPMate Pro.
            $data = json_decode((string) file_get_contents($file), false);

            if (is_string($data->instructions ?? null) && $data->instructions !== '') {
                $instructions[] = $data->instructions;
            }

            foreach ((array) ($data->tools ?? []) as $tool) {
                $name = $tool instanceof \stdClass ? (string) ($tool->name ?? '') : '';
                if ($name !== '' && isset($handlers[$name]) && !isset($tools[$name])) {
                    $tools[$name] = $tool;
                }
            }
        }

        self::$definitions = ['instructions' => implode(' ', $instructions), 'tools' => $tools];

        return self::$definitions;
    }
}
