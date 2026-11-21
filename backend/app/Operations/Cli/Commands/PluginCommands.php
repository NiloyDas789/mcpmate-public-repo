<?php

namespace MCPMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Operations\Cli\Command;
use MCPMate\Operations\Cli\CommandException;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\ParsedCommand;

/**
 * `plugin list|get`. MCPMate Pro adds `plugin activate|deactivate|update|install`
 * (PluginWriteCommands), which use resolve() and availableUpdates(). Update
 * availability comes from core's cached `update_plugins` check; nothing here
 * asks WordPress.org for fresh data.
 */
final class PluginCommands extends CommandGroup
{
    public const FIELDS = ['name', 'file', 'title', 'status', 'version', 'update', 'update_version'];

    public const STATUSES = ['active', 'inactive', 'must-use', 'dropin'];

    public const GET_FIELDS = [
        'name', 'file', 'title', 'status', 'version', 'update', 'update_version', 'auto_update',
        'author', 'description', 'plugin_uri', 'requires_wp', 'requires_php', 'network', 'text_domain',
    ];

    /** Plugin slug (`akismet`) or main file (`akismet/akismet.php`). */
    public const PLUGIN_ARG = [
        'name'       => 'plugin',
        'required'   => true,
        'variadic'   => true,
        'unique'     => true,
        'max_items'  => 10,
        'max_length' => 200,
        'pattern'    => '#^(?!.*\.\.)[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+\.php)?$#D',
    ];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('plugin list', [
            'summary'    => __('List installed plugins with status, version and update availability.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'activate_plugins',
            'flags'      => [
                'status' => ['enum' => self::STATUSES],
                'fields' => self::fieldsFlag(self::FIELDS),
            ],
            'handler'    => [self::class, 'list'],
        ]);

        $registry->add('plugin get', [
            'summary'    => __('Show one installed plugin: version, author, requirements and update availability.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'activate_plugins',
            'args'       => [array_diff_key(self::PLUGIN_ARG, ['variadic' => true, 'unique' => true, 'max_items' => true])],
            'flags'      => [
                'fields' => self::fieldsFlag(self::GET_FIELDS),
            ],
            'target'     => ['plugin', 'plugin'],
            'handler'    => [self::class, 'get'],
        ]);
    }

    // ──────────────────────────────────────────────
    //  Handlers
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        self::requireAdmin('plugin.php');

        $updates = self::availableUpdates();
        $items   = [];

        foreach (get_plugins() as $file => $data) {
            $items[] = self::describe($file, $data, is_plugin_active($file) ? 'active' : 'inactive', $updates);
        }

        foreach (get_mu_plugins() as $file => $data) {
            $items[] = self::describe($file, $data, 'must-use', []);
        }

        foreach (get_dropins() as $file => $data) {
            $items[] = self::describe($file, $data, 'dropin', []);
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
        self::requireAdmin('plugin.php');

        $resolved    = self::resolve([(string) $command->arg('plugin')]);
        $file        = (string) reset($resolved);
        $data        = get_plugins()[$file] ?? [];
        $autoUpdates = (array) get_site_option('auto_update_plugins', []);
        $item        = self::describe($file, $data, is_plugin_active($file) ? 'active' : 'inactive', self::availableUpdates()) + [
            'auto_update'  => in_array($file, $autoUpdates, true) ? 'on' : 'off',
            'author'       => wp_strip_all_tags((string) ($data['Author'] ?? '')),
            'description'  => wp_strip_all_tags((string) ($data['Description'] ?? '')),
            'plugin_uri'   => (string) ($data['PluginURI'] ?? ''),
            'requires_wp'  => (string) ($data['RequiresWP'] ?? ''),
            'requires_php' => (string) ($data['RequiresPHP'] ?? ''),
            'network'      => !empty($data['Network']),
            'text_domain'  => (string) ($data['TextDomain'] ?? ''),
        ];

        return self::pickFields([$item], $command->flag('fields'))[0];
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Map slugs or main files to installed plugin files.
     *
     * @param string[] $inputs
     * @return array<string, string> Slug => plugin file.
     * @throws CommandException 404 for a plugin that is not installed.
     */
    public static function resolve(array $inputs): array
    {
        $bySlug = [];
        foreach (array_keys(get_plugins()) as $file) {
            $bySlug[self::slug($file)] = $file;
        }

        $resolved = [];
        foreach ($inputs as $input) {
            $file = in_array($input, $bySlug, true) ? $input : ($bySlug[$input] ?? null);

            if ($file === null) {
                throw self::notFound(
                    /* translators: %s: plugin slug. */
                    sprintf(esc_html__('Plugin "%s" is not installed.', 'mcpmate'), esc_html($input)),
                    esc_html__('Run "plugin list" to see installed plugins.', 'mcpmate')
                );
            }

            $resolved[self::slug($file)] = $file;
        }

        return $resolved;
    }

    public static function slug(string $file): string
    {
        $dir = dirname($file);

        return $dir === '.' ? basename($file, '.php') : $dir;
    }

    /**
     * New versions from the cached update check, by plugin file.
     *
     * @return array<string, string>
     */
    public static function availableUpdates(): array
    {
        $transient = get_site_transient('update_plugins');
        $versions  = [];

        if (is_object($transient) && isset($transient->response) && is_array($transient->response)) {
            foreach ($transient->response as $file => $update) {
                if (is_object($update) && isset($update->new_version)) {
                    $versions[(string) $file] = (string) $update->new_version;
                }
            }
        }

        return $versions;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $updates
     * @return array<string, mixed>
     */
    private static function describe(string $file, array $data, string $status, array $updates): array
    {
        return [
            'name'           => self::slug($file),
            'file'           => $file,
            'title'          => (string) ($data['Name'] ?? ''),
            'status'         => $status,
            'version'        => (string) ($data['Version'] ?? ''),
            'update'         => isset($updates[$file]) ? 'available' : 'none',
            'update_version' => $updates[$file] ?? null,
        ];
    }
}
