<?php

namespace MCPMate\Services\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Extensions\Features;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\CommandSettings;
use MCPMate\Services\Abilities\Abilities;
use MCPMate\Services\Abilities\AbilityRisk;
use MCPMate\Services\Abilities\AbilityRunner;
use MCPMate\Services\Abilities\AbilitySettings;
use MCPMate\Services\Mcp\Stock\StockPhotoSettings;

/**
 * The Tools page's data (GET /tools) and its one save (POST /tools): the
 * catalog with each tool's state, whether the site can run it, and the
 * items of run_wp_cli (CLI commands, MCPMate Pro's included) and
 * run_ability (the abilities this site can run: read ones, and with
 * MCPMate Pro the rest). A feature's own tool with items is read and saved
 * by that feature.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages become JSON error messages (REST envelope, wp_send_json), never HTML.
final class ToolRegistry
{
    /** The plugin's tools with item lists (a feature's are its own). */
    private const OWN_ITEMS = ['run_wp_cli', 'run_ability'];

    /**
     * Longest ability description the Tools page gets, in characters. The
     * page shows it under the ability's label; AI apps read the whole text
     * through list_abilities.
     */
    public const ABILITY_DESCRIPTION_MAX = 600;

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
                    $entry['items'] = self::items($name);
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
     * `wordpress_too_old` (Abilities API), `not_configured` (stock photos
     * without an Unsplash key), or a MCPMate Pro feature's own reason
     * (`plugin_missing`, `plugin_inactive`, `file_edit_disallowed`).
     *
     * @return array{available: bool, reason: string}
     */
    public static function availability(string $group): array
    {
        $reason = '';

        if ($group === 'abilities' && !Abilities::isSupported()) {
            $reason = 'wordpress_too_old';
        } elseif ($group === 'stock_photos' && !StockPhotoSettings::isConfigured()) {
            $reason = 'not_configured';
        } else {
            foreach (Features::all() as $feature) {
                $own = $feature->unavailableReason($group);
                if ($own !== null) {
                    $reason = $own;
                    break;
                }
            }
        }

        return ['available' => $reason === '', 'reason' => $reason];
    }

    /**
     * A group's own settings: whether stock photos have a key and its app
     * name (never the key), or a MCPMate Pro feature's (media's SVG switch).
     *
     * @return array<string, mixed>|object
     */
    private static function options(string $group)
    {
        if ($group === 'stock_photos') {
            return StockPhotoSettings::summary();
        }

        foreach (Features::all() as $feature) {
            $options = $feature->groupOptions($group);
            if ($options !== null) {
                return $options;
            }
        }

        return (object) [];
    }

    /**
     * Abilities also carry `description` and `section` (their source).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function items(string $tool): array
    {
        $items = [];

        if ($tool === 'run_wp_cli') {
            foreach (CommandRegistry::defaults()->all() as $command) {
                $items[] = [
                    'name'     => $command->name(),
                    'label'    => $command->summary(),
                    'kind'     => $command->risk() === 'read' ? ToolCatalog::KIND_READ : ToolCatalog::KIND_WRITE,
                    'approval' => $command->isDestructive(),
                    'enabled'  => CommandSettings::isEnabled($command->name()),
                ];
            }

            return $items;
        }

        if ($tool === 'run_ability') {
            $risks = AbilityRunner::allowedRisks();

            foreach (Abilities::all() as $ability) {
                $described = Abilities::describe($ability, false);

                if (!in_array($described['risk'], $risks, true)) {
                    continue;
                }

                $name    = (string) $described['name'];
                $section = Abilities::categoryLabel((string) $described['category']);

                $items[] = [
                    'name'        => $name,
                    'label'       => (string) $described['label'],
                    'description' => self::clip((string) $described['description'], self::ABILITY_DESCRIPTION_MAX),
                    // The Tools page lists abilities by source: the category's
                    // label, else the namespace (`woocommerce`).
                    'section'     => $section !== '' ? $section : explode('/', $name, 2)[0],
                    'kind'        => $described['risk'] === AbilityRisk::READ ? ToolCatalog::KIND_READ : ToolCatalog::KIND_WRITE,
                    'approval'    => $described['risk'] === AbilityRisk::DESTRUCTIVE,
                    'enabled'     => (bool) $described['enabled'],
                ];
            }

            return $items;
        }

        $owner = Features::ownerOfTool($tool);

        return $owner === null ? [] : (array) $owner->items($tool);
    }

    /**
     * The groups with ability descriptions short enough that their JSON fits
     * $budget bytes, so descriptions never cost an ability its row.
     *
     * GET /tools answers through RestEnvelope, which cuts an answer over its
     * cap from the end: the abilities' item list loses its last items and the
     * groups after it are dropped. Descriptions are the only part of an
     * ability's row the Tools page can do without, so they give way first:
     * all are cut to one length, the longest that fits, down to none. When
     * the rows do not fit even without descriptions, the envelope still cuts
     * them as before (`truncated: true`).
     *
     * @param array<int, array<string, mixed>> $groups As groups() returns them.
     * @return array<int, array<string, mixed>>
     */
    public static function fitAbilityDescriptions(array $groups, int $budget): array
    {
        if (self::jsonSize($groups) <= $budget) {
            return $groups;
        }

        // The longest per-description length that fits (0 drops the text).
        $low  = 0;
        $high = self::ABILITY_DESCRIPTION_MAX;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);

            if (self::jsonSize(self::withAbilityDescriptions($groups, $mid)) <= $budget) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return self::withAbilityDescriptions($groups, $low);
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private static function withAbilityDescriptions(array $groups, int $max): array
    {
        foreach ($groups as $g => $group) {
            foreach ((array) ($group['tools'] ?? []) as $t => $tool) {
                if (($tool['name'] ?? '') !== 'run_ability' || !isset($tool['items'])) {
                    continue;
                }

                foreach ((array) $tool['items'] as $i => $item) {
                    $groups[$g]['tools'][$t]['items'][$i]['description'] = self::clip((string) ($item['description'] ?? ''), $max);
                }
            }
        }

        return $groups;
    }

    /**
     * $text cut to $max characters, ending in an ellipsis when cut; '' for 0.
     */
    private static function clip(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(0, $max - 1), 'UTF-8')) . '…';
    }

    /**
     * Bytes of JSON for $value, measured as RestEnvelope measures its cap
     * (wp_json_encode()). Without WordPress (the unit suite), plain JSON with
     * invalid UTF-8 replaced, as wp_json_encode() repairs it.
     *
     * @param mixed $value
     */
    private static function jsonSize($value): int
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($value)
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Only without WordPress (unit tests), where wp_json_encode() does not exist.
            : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? PHP_INT_MAX : strlen($json);
    }

    // ──────────────────────────────────────────────
    //  Saving
    // ──────────────────────────────────────────────

    /**
     * Apply one save from the Tools page: `tools` (name => bool), `items`
     * (tool with an item list => item name => bool) and `options`
     * (a feature's option name => bool, e.g. `svg`). Everything is checked
     * before anything is saved.
     *
     * @param array<string, mixed> $tools
     * @param array<string, mixed> $items
     * @param array<string, mixed> $options
     * @return array<string, mixed> What changed: {tools, items, options}, for the audit row.
     * @throws ToolsException 400, and nothing is saved.
     */
    public static function apply(array $tools, array $items, array $options): array
    {
        $itemChanges = [];

        foreach (array_keys($items) as $tool) {
            $tool  = (string) $tool;
            $entry = ToolCatalog::tool($tool);
            $owner = Features::ownerOfTool($tool);

            if ($entry === null || $entry['items'] === '' || ($owner === null && !in_array($tool, self::OWN_ITEMS, true))) {
                throw new ToolsException(
                    /* translators: %s: tool name. */
                    sprintf(__('"%s" has no items to turn on or off.', 'mcpmate'), $tool),
                    ToolsException::UNKNOWN_ITEM
                );
            }

            $known              = array_map(static fn (array $item): string => $item['name'], self::items($tool));
            $itemChanges[$tool] = self::itemChanges($items, $tool, $known);
        }

        $optionOwners = [];
        foreach (Features::all() as $feature) {
            foreach ($feature->optionNames() as $name) {
                $optionOwners[$name] ??= $feature;
            }
        }

        foreach ($options as $name => $value) {
            if (!isset($optionOwners[$name]) || !is_bool($value)) {
                /* translators: %s: option names, comma-separated. */
                $message = sprintf(__('Only these options can be set, to true or false: %s.', 'mcpmate'), implode(', ', array_keys($optionOwners)));

                throw new ToolsException($optionOwners === [] ? __('No options can be set on this site.', 'mcpmate') : $message, ToolsException::INVALID_VALUE);
            }
        }

        // Validates every tool before saving any.
        ToolSettings::update($tools);

        foreach ($itemChanges as $tool => $changes) {
            if ($changes === []) {
                continue;
            }

            if ($tool === 'run_wp_cli') {
                CommandSettings::update(self::merge(CommandSettings::enabled(), $changes), CommandRegistry::defaults());
            } elseif ($tool === 'run_ability') {
                AbilitySettings::update(self::merge(AbilitySettings::enabled(), $changes), array_keys(Abilities::all()));
            } else {
                $owner = Features::ownerOfTool($tool);
                if ($owner !== null) {
                    $owner->updateItems($tool, $changes);
                }
            }
        }

        foreach ($options as $name => $value) {
            $optionOwners[$name]->updateOption((string) $name, (bool) $value);
        }

        return [
            'tools'   => $tools,
            'items'   => array_filter($itemChanges),
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
