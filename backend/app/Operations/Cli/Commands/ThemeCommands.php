<?php

namespace MCPMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Operations\Cli\Command;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\ParsedCommand;

/**
 * `theme list|get`. MCPMate Pro adds `theme activate` (ThemeWriteCommands).
 */
final class ThemeCommands extends CommandGroup
{
    public const FIELDS = ['name', 'title', 'status', 'version', 'parent', 'update', 'update_version', 'auto_update'];

    public const STATUSES = ['active', 'parent', 'inactive'];

    public const GET_FIELDS = [
        'name', 'title', 'status', 'version', 'parent', 'update', 'update_version', 'auto_update',
        'author', 'description', 'theme_uri', 'requires_wp', 'requires_php', 'block_theme', 'tags',
    ];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('theme list', [
            'summary'    => __('List installed themes with status, version and update availability.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'switch_themes',
            'flags'      => [
                'status' => ['enum' => self::STATUSES],
                'fields' => self::fieldsFlag(self::FIELDS),
            ],
            'handler'    => [self::class, 'list'],
        ]);

        $registry->add('theme get', [
            'summary'    => __('Show one installed theme: version, author, requirements and update availability.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'switch_themes',
            'args'       => [
                ['name' => 'theme', 'required' => true, 'max_length' => 200, 'pattern' => '#^[A-Za-z0-9._-]+$#D'],
            ],
            'flags'      => [
                'fields' => self::fieldsFlag(self::GET_FIELDS),
            ],
            'target'     => ['theme', 'theme'],
            'handler'    => [self::class, 'get'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        $items = [];

        foreach (wp_get_themes() as $slug => $theme) {
            $items[] = self::describe((string) $slug, $theme);
        }

        $status = $command->flag('status');
        if ($status !== null) {
            $items = array_values(array_filter($items, static fn (array $item) => $item['status'] === $status));
        }

        return [
            'items' => self::pickFields($items, $command->flag('fields')),
            'total' => count($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(ParsedCommand $command, bool $dryRun): array
    {
        $slug  = (string) $command->arg('theme');
        $theme = wp_get_theme($slug);

        if (!$theme->exists()) {
            throw self::notFound(
                /* translators: %s: theme slug. */
                sprintf(esc_html__('Theme "%s" is not installed.', 'mcpmate'), esc_html($slug)),
                esc_html__('Run "theme list" to see installed themes.', 'mcpmate')
            );
        }

        $item = self::describe($theme->get_stylesheet(), $theme) + [
            'author'       => wp_strip_all_tags((string) $theme->get('Author')),
            'description'  => wp_strip_all_tags((string) $theme->get('Description')),
            'theme_uri'    => (string) $theme->get('ThemeURI'),
            'requires_wp'  => (string) $theme->get('RequiresWP'),
            'requires_php' => (string) $theme->get('RequiresPHP'),
            'block_theme'  => $theme->is_block_theme(),
            'tags'         => array_values((array) $theme->get('Tags')),
        ];

        return self::pickFields([$item], $command->flag('fields'))[0];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(string $slug, \WP_Theme $theme): array
    {
        $transient   = get_site_transient('update_themes');
        $updates     = is_object($transient) && isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $autoUpdates = (array) get_site_option('auto_update_themes', []);

        if ($slug === get_stylesheet()) {
            $status = 'active';
        } elseif ($slug === get_template()) {
            $status = 'parent';
        } else {
            $status = 'inactive';
        }

        $newVersion = isset($updates[$slug]['new_version']) ? (string) $updates[$slug]['new_version'] : null;

        return [
            'name'           => $slug,
            'title'          => (string) $theme->get('Name'),
            'status'         => $status,
            'version'        => (string) $theme->get('Version'),
            'parent'         => $theme->parent() ? $theme->get_template() : null,
            'update'         => $newVersion !== null ? 'available' : 'none',
            'update_version' => $newVersion,
            'auto_update'    => in_array($slug, $autoUpdates, true) ? 'on' : 'off',
        ];
    }
}
