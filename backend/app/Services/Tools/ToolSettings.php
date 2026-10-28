<?php

namespace McpMate\Services\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Which tools a site admin turned on (docs/ui.md B1), in one option,
 * `MCPMATE_tools`: tool name => bool. A tool missing from it has its
 * ToolCatalog default (off for anything new, rule 1).
 *
 * The older feature switches (CoreProxySettings, MediaSettings,
 * ThemeDraftSettings, DbInspectSettings, ElementorSettings) read and write
 * their group here, so the Tools page, the setup wizard and the feature
 * checks inside each controller never disagree. The CLI command and ability
 * enable lists stay in their own options: they are items of run_wp_cli and
 * run_ability, not tools.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages become JSON error messages (REST envelope, wp_send_json), never HTML.
final class ToolSettings
{
    public const OPTION = 'tools';

    /**
     * Feature switch options before 0.6.0 => the group they became.
     */
    public const LEGACY_SWITCHES = [
        'core_proxy'   => 'posts',
        'media_upload' => 'media',
        'theme_drafts' => 'theme_drafts',
        'db_inspect'   => 'database',
        'elementor'    => 'elementor',
    ];

    public static function isEnabled(string $name): bool
    {
        $tool = ToolCatalog::tool($name);

        if ($tool === null) {
            return false;
        }

        if ($tool['always_on']) {
            return true;
        }

        $saved = self::saved();

        return array_key_exists($name, $saved) ? $saved[$name] : $tool['default'];
    }

    /**
     * Every tool's state, in catalog order.
     *
     * @return array<string, bool>
     */
    public static function states(): array
    {
        $states = [];

        foreach (ToolCatalog::names() as $name) {
            $states[$name] = self::isEnabled($name);
        }

        return $states;
    }

    /**
     * Turn tools on or off. Every name must be in the catalog, and an
     * always-on tool cannot be turned off; nothing is saved otherwise.
     *
     * @param array<array-key, mixed> $changes name => bool
     * @return array<string, bool> Every tool's state after the change.
     * @throws ToolsException 400 for an unknown tool, a value that is not a boolean, or an always-on tool turned off.
     */
    public static function update(array $changes): array
    {
        $saved = self::saved();

        foreach ($changes as $name => $value) {
            $name = (string) $name;
            $tool = ToolCatalog::tool($name);

            if ($tool === null) {
                throw new ToolsException(
                    /* translators: %s: tool name. */
                    sprintf(__('There is no tool named "%s".', 'mcpmate'), $name),
                    ToolsException::UNKNOWN_TOOL
                );
            }

            if (!is_bool($value)) {
                throw new ToolsException(
                    /* translators: %s: tool name. */
                    sprintf(__('The value for "%s" must be true or false.', 'mcpmate'), $name),
                    ToolsException::INVALID_VALUE
                );
            }

            if ($tool['always_on'] && !$value) {
                throw new ToolsException(
                    /* translators: %s: tool name. */
                    sprintf(__('"%s" is always on: AI apps need it to hear back about requests that wait for you.', 'mcpmate'), $name),
                    ToolsException::ALWAYS_ON
                );
            }

            $saved[$name] = $value;
        }

        self::save($saved);

        return self::states();
    }

    /**
     * Whether any tool of $group is on (the old feature switches).
     */
    public static function groupEnabled(string $group): bool
    {
        foreach (ToolCatalog::toolsIn($group) as $name) {
            if (self::isEnabled($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn every tool of $group on or off (always-on tools stay on).
     */
    public static function setGroup(string $group, bool $enabled): bool
    {
        $saved = self::saved();

        foreach (ToolCatalog::toolsIn($group) as $name) {
            $tool = (array) ToolCatalog::tool($name);
            if (empty($tool['always_on'])) {
                $saved[$name] = $enabled;
            }
        }

        self::save($saved);

        return $enabled;
    }

    /**
     * 0.6.0 migration: the first time, each group takes the state of the
     * feature switch it replaces, every other tool keeps its default; then
     * the old switch options are deleted. Later runs change nothing.
     */
    public static function seed(): void
    {
        if (Config::getOption(self::OPTION, null) !== null) {
            return;
        }

        $saved = [];
        foreach (ToolCatalog::names() as $name) {
            $saved[$name] = (bool) ((array) ToolCatalog::tool($name))['default'];
        }

        foreach (self::LEGACY_SWITCHES as $option => $group) {
            // update_option() stored true as '1'.
            $on = in_array(Config::getOption($option, false), [true, 1, '1'], true);

            foreach (ToolCatalog::toolsIn($group) as $name) {
                $saved[$name] = $on;
            }

            Config::deleteOption($option);
        }

        self::save($saved);
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @return array<string, bool>
     */
    private static function saved(): array
    {
        $saved = Config::getOption(self::OPTION, []);

        if (!is_array($saved)) {
            return [];
        }

        $states = [];
        foreach ($saved as $name => $value) {
            if (is_string($name) && ToolCatalog::has($name)) {
                $states[$name] = in_array($value, [true, 1, '1'], true);
            }
        }

        return $states;
    }

    /**
     * @param array<string, bool> $saved
     */
    private static function save(array $saved): void
    {
        // Read on MCP calls only, not on every page: no autoload.
        Config::updateOption(self::OPTION, $saved, false);
    }
}
// phpcs:enable
