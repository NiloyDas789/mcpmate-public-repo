<?php

namespace McpMate\Services\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\CommandSettings;
use McpMate\Services\Abilities\Abilities;
use McpMate\Services\Abilities\AbilityRisk;
use McpMate\Services\Abilities\AbilitySettings;
use McpMate\Services\Elementor\ElementorPages;
use McpMate\Services\Mcp\Stock\StockPhotoSettings;
use McpMate\Services\Media\MediaSettings;

/**
 * The Tools page's data (GET /tools) and its one save (POST /tools): the
 * catalog with each tool's state, whether the site can run it, and the
 * items of run_wp_cli (CLI commands) and run_ability (abilities).
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages become JSON error messages (REST envelope, wp_send_json), never HTML.
final class ToolRegistry
{
    public const ITEMS_CLI = 'cli';

    public const ITEMS_ABILITIES = 'abilities';

    private const ELEMENTOR_FILE = 'elementor/elementor.php';

    // ──────────────────────────────────────────────
    //  Listing
    // ──────────────────────────────────────────────

    /**
     * Every group with its tools. With $grantTools (a grant's
     * custom tool list, or null for every tool), each tool also says
     * whether that grant may use it now.
     *
     * @param string[]|null $grantTools
     * @return array<int, array<string, mixed>>
     */
    public static function groups(?array $grantTools = null): array
    {
        $groups = [];

        foreach (ToolCatalog::groupIds() as $id) {
            $group        = (array) ToolCatalog::group($id);
            $availability = self::availability($id);
            $tools        = [];

            foreach (ToolCatalog::toolsIn($id) as $name) {
                $tool    = (array) ToolCatalog::tool($name);
                $enabled = ToolSettings::isEnabled($name);

                $entry = [
                    'name'        => $name,
                    'label'       => $tool['label'],
                    'description' => $tool['description'],
                    'kind'        => $tool['kind'],
                    'approval'    => $tool['approval'],
                    'always_on'   => $tool['always_on'],
                    'enabled'     => $enabled,
                    'allowed'     => $enabled && $availability['available'] && ($grantTools === null || in_array($name, $grantTools, true)),
                ];

                if ($tool['items'] !== '') {
                    $entry['items'] = self::items($tool['items']);
                }

                $tools[] = $entry;
            }

            $groups[] = [
                'id'                 => $id,
                'label'              => $group['label'],
                'source'             => $group['source'],
                'available'          => $availability['available'],
                'unavailable_reason' => $availability['reason'],
                'options'            => self::options($id),
                'tools'              => $tools,
            ];
        }

        return $groups;
    }

    /**
     * Tools turned on, of those this site can run. A tool with items
     * (run_wp_cli, run_ability) counts each item instead of itself.
     *
     * @return array{enabled: int, total: int}
     */
    public static function counts(): array
    {
        $enabled = 0;
        $total   = 0;

        foreach (self::groups() as $group) {
            if (!$group['available']) {
                continue;
            }

            foreach ($group['tools'] as $tool) {
                if (!isset($tool['items'])) {
                    $total++;
                    $enabled += $tool['enabled'] ? 1 : 0;
                    continue;
                }

                foreach ($tool['items'] as $item) {
                    $total++;
                    $enabled += $tool['enabled'] && $item['enabled'] ? 1 : 0;
                }
            }
        }

        return ['enabled' => $enabled, 'total' => $total];
    }

    /**
     * Whether this site can run a group's tools, and why not:
     * `plugin_missing` / `plugin_inactive` (Elementor),
     * `wordpress_too_old` (Abilities API), `file_edit_disallowed`
     * (DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS withhold edit_themes),
     * `not_configured` (stock photos without an Unsplash key).
     *
     * @return array{available: bool, reason: string}
     */
    public static function availability(string $group): array
    {
        $reason = '';

        if ($group === 'elementor' && !ElementorPages::isAvailable()) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $reason = isset(get_plugins()[self::ELEMENTOR_FILE]) ? 'plugin_inactive' : 'plugin_missing';
        } elseif ($group === 'abilities' && !Abilities::isSupported()) {
            $reason = 'wordpress_too_old';
        } elseif ($group === 'theme_drafts' && ((defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) || !wp_is_file_mod_allowed('capability_edit_themes'))) {
            $reason = 'file_edit_disallowed';
        } elseif ($group === 'stock_photos' && !StockPhotoSettings::isConfigured()) {
            $reason = 'not_configured';
        }

        return ['available' => $reason === '', 'reason' => $reason];
    }

    /**
     * A group's own settings: media's SVG switch, and whether stock photos
     * have a key and its app name (never the key).
     *
     * @return array<string, mixed>|object
     */
    private static function options(string $group)
    {
        if ($group === 'media') {
            return ['svg' => MediaSettings::svgEnabled()];
        }

        return $group === 'stock_photos' ? StockPhotoSettings::summary() : (object) [];
    }

    /**
     * @return array<int, array{name: string, label: string, kind: string, approval: bool, enabled: bool}>
     */
    private static function items(string $kind): array
    {
        $items = [];

        if ($kind === self::ITEMS_CLI) {
            foreach (CommandRegistry::defaults()->all() as $command) {
                $items[] = [
                    'name'     => $command->name(),
                    'label'    => $command->summary(),
                    'kind'     => $command->risk() === 'read' ? ToolCatalog::KIND_READ : ToolCatalog::KIND_WRITE,
                    'approval' => $command->isDestructive(),
                    'enabled'  => CommandSettings::isEnabled($command->name()),
                ];
            }
        } elseif ($kind === self::ITEMS_ABILITIES) {
            foreach (Abilities::all() as $ability) {
                $described = Abilities::describe($ability, false);
                $items[]   = [
                    'name'     => (string) $described['name'],
                    'label'    => (string) $described['label'],
                    'kind'     => $described['risk'] === AbilityRisk::READ ? ToolCatalog::KIND_READ : ToolCatalog::KIND_WRITE,
                    'approval' => $described['risk'] === AbilityRisk::DESTRUCTIVE,
                    'enabled'  => (bool) $described['enabled'],
                ];
            }
        }

        return $items;
    }

    // ──────────────────────────────────────────────
    //  Saving
    // ──────────────────────────────────────────────

    /**
     * Apply one save from the Tools page: `tools` (name => bool), `items`
     * (`run_wp_cli` / `run_ability` => item name => bool) and `options`
     * (`svg` => bool). Everything is checked before anything is saved.
     *
     * @param array<string, mixed> $tools
     * @param array<string, mixed> $items
     * @param array<string, mixed> $options
     * @return array<string, mixed> What changed: {tools, items, options}, for the audit row.
     * @throws ToolsException 400, and nothing is saved.
     */
    public static function apply(array $tools, array $items, array $options): array
    {
        $cliChanges     = self::itemChanges($items, 'run_wp_cli', CommandRegistry::defaults()->names());
        $abilityChanges = self::itemChanges($items, 'run_ability', array_keys(Abilities::all()));

        foreach (array_keys($items) as $tool) {
            if (!in_array($tool, ['run_wp_cli', 'run_ability'], true)) {
                throw new ToolsException(
                    /* translators: %s: tool name. */
                    sprintf(__('"%s" has no items to turn on or off.', 'mcpmate'), (string) $tool),
                    ToolsException::UNKNOWN_ITEM
                );
            }
        }

        foreach ($options as $name => $value) {
            if ($name !== 'svg' || !is_bool($value)) {
                throw new ToolsException(__('Only the "svg" option can be set, to true or false.', 'mcpmate'), ToolsException::INVALID_VALUE);
            }
        }

        // Validates every tool before saving any.
        ToolSettings::update($tools);

        if ($cliChanges !== []) {
            CommandSettings::update(self::merge(CommandSettings::enabled(), $cliChanges), CommandRegistry::defaults());
        }

        if ($abilityChanges !== []) {
            AbilitySettings::update(self::merge(AbilitySettings::enabled(), $abilityChanges), array_keys(Abilities::all()));
        }

        if (isset($options['svg'])) {
            MediaSettings::updateSvg((bool) $options['svg']);
        }

        return [
            'tools'   => $tools,
            'items'   => array_filter(['run_wp_cli' => $cliChanges, 'run_ability' => $abilityChanges]),
            'options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $items
     * @param string[]             $known
     * @return array<string, bool>
     * @throws ToolsException
     */
    private static function itemChanges(array $items, string $tool, array $known): array
    {
        if (!isset($items[$tool])) {
            return [];
        }

        if (!is_array($items[$tool])) {
            throw new ToolsException(
                /* translators: %s: tool name. */
                sprintf(__('The items of "%s" must be an object of names and true or false.', 'mcpmate'), $tool),
                ToolsException::INVALID_VALUE
            );
        }

        $changes = [];
        foreach ($items[$tool] as $name => $value) {
            if (!in_array((string) $name, $known, true)) {
                throw new ToolsException(
                    /* translators: 1: item name, 2: tool name. */
                    sprintf(__('"%1$s" is not an item of "%2$s" on this site.', 'mcpmate'), (string) $name, $tool),
                    ToolsException::UNKNOWN_ITEM
                );
            }

            if (!is_bool($value)) {
                throw new ToolsException(
                    /* translators: %s: item name. */
                    sprintf(__('The value for "%s" must be true or false.', 'mcpmate'), (string) $name),
                    ToolsException::INVALID_VALUE
                );
            }

            $changes[(string) $name] = $value;
        }

        return $changes;
    }

    /**
     * @param string[]            $enabled
     * @param array<string, bool> $changes
     * @return string[]
     */
    private static function merge(array $enabled, array $changes): array
    {
        foreach ($changes as $name => $on) {
            $enabled = $on ? array_merge($enabled, [$name]) : array_diff($enabled, [$name]);
        }

        return array_values(array_unique($enabled));
    }
}
// phpcs:enable
