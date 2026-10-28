<?php

namespace McpMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandException;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\ParsedCommand;

/**
 * `plugin list|activate|deactivate|update`.
 *
 * Activate, deactivate and update run third-party code or replace it, so
 * they are destructive: without dry_run they wait for admin approval. Update
 * availability comes from core's cached `update_plugins` check; nothing here
 * asks WordPress.org for fresh data.
 */
final class PluginCommands extends CommandGroup
{
    public const FIELDS = ['name', 'file', 'title', 'status', 'version', 'update', 'update_version'];

    public const STATUSES = ['active', 'inactive', 'must-use', 'dropin'];

    /** Plugin slug (`akismet`) or main file (`akismet/akismet.php`). */
    private const PLUGIN_ARG = [
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

        $registry->add('plugin activate', [
            'summary'          => __('Activate one or more plugins.', 'mcpmate'),
            'risk'             => Command::RISK_DESTRUCTIVE,
            'capability'       => 'activate_plugins',
            'supports_dry_run' => true,
            'args'             => [self::PLUGIN_ARG],
            'target'           => ['plugin', 'plugin'],
            'handler'          => [self::class, 'activate'],
        ]);

        $registry->add('plugin deactivate', [
            'summary'          => __('Deactivate one or more plugins.', 'mcpmate'),
            'risk'             => Command::RISK_DESTRUCTIVE,
            'capability'       => 'activate_plugins',
            'supports_dry_run' => true,
            'args'             => [self::PLUGIN_ARG],
            'target'           => ['plugin', 'plugin'],
            'handler'          => [self::class, 'deactivate'],
        ]);

        $registry->add('plugin update', [
            'summary'          => __('Update one or more plugins to the version WordPress last found.', 'mcpmate'),
            'risk'             => Command::RISK_DESTRUCTIVE,
            'capability'       => 'update_plugins',
            'supports_dry_run' => true,
            'args'             => [self::PLUGIN_ARG],
            'target'           => ['plugin', 'plugin'],
            'handler'          => [self::class, 'update'],
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
    public static function activate(ParsedCommand $command, bool $dryRun): array
    {
        self::requireAdmin('plugin.php');

        $results = [];

        foreach (self::resolve($command->arg('plugin')) as $slug => $file) {
            self::authorize('activate_plugin', $file);

            if (is_plugin_active($file)) {
                $results[] = ['plugin' => $slug, 'file' => $file, 'status' => 'already_active'];
                continue;
            }

            if (is_network_only_plugin($file) && !is_network_admin()) {
                $results[] = ['plugin' => $slug, 'file' => $file, 'status' => 'skipped', 'reason' => 'network_only'];
                continue;
            }

            if ($dryRun) {
                $results[] = ['plugin' => $slug, 'file' => $file, 'status' => 'would_activate'];
                continue;
            }

            $error     = activate_plugin($file);
            $results[] = is_wp_error($error)
                ? ['plugin' => $slug, 'file' => $file, 'status' => 'failed', 'error' => $error->get_error_message()]
                : ['plugin' => $slug, 'file' => $file, 'status' => 'activated'];
        }

        return ['plugins' => $results];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deactivate(ParsedCommand $command, bool $dryRun): array
    {
        self::requireAdmin('plugin.php');

        $plugins = self::resolve($command->arg('plugin'));
        $self    = Config::get('BASENAME');

        if (in_array($self, $plugins, true)) {
            throw self::notAllowed(
                esc_html__('McpMate cannot deactivate itself.', 'mcpmate'),
                esc_html__('A site admin can deactivate it from the Plugins screen.', 'mcpmate')
            );
        }

        $results = [];

        foreach ($plugins as $slug => $file) {
            self::authorize('deactivate_plugin', $file);

            if (!is_plugin_active($file)) {
                $results[] = ['plugin' => $slug, 'file' => $file, 'status' => 'already_inactive'];
                continue;
            }

            if ($dryRun) {
                $results[] = ['plugin' => $slug, 'file' => $file, 'status' => 'would_deactivate'];
                continue;
            }

            deactivate_plugins($file);
            $results[] = ['plugin' => $slug, 'file' => $file, 'status' => 'deactivated'];
        }

        return ['plugins' => $results];
    }

    /**
     * @return array<string, mixed>
     */
    public static function update(ParsedCommand $command, bool $dryRun): array
    {
        self::requireAdmin('plugin.php');

        $plugins = self::resolve($command->arg('plugin'));
        $updates = self::availableUpdates();
        $all     = get_plugins();
        $pending = [];
        $results = [];

        foreach ($plugins as $slug => $file) {
            $from = (string) ($all[$file]['Version'] ?? '');

            if (!isset($updates[$file])) {
                $results[$file] = ['plugin' => $slug, 'file' => $file, 'from' => $from, 'status' => 'up_to_date'];
                continue;
            }

            $results[$file] = ['plugin' => $slug, 'file' => $file, 'from' => $from, 'to' => $updates[$file], 'status' => 'would_update'];
            $pending[]      = $file;
        }

        if ($dryRun || $pending === []) {
            return ['plugins' => array_values($results)];
        }

        self::requireAdmin('class-wp-upgrader.php');
        self::requireAdmin('file.php');

        if (get_filesystem_method() !== 'direct') {
            throw new CommandException(
                esc_html__('WordPress needs FTP credentials to update plugins on this server.', 'mcpmate'),
                esc_html(CommandException::FAILED),
                409,
                esc_html__('Update the plugins from the WordPress dashboard instead.', 'mcpmate')
            );
        }

        $skin     = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $outcome  = $upgrader->bulk_upgrade($pending);
        $errors   = $skin->get_error_messages();

        foreach ($pending as $file) {
            $ok = is_array($outcome) && isset($outcome[$file]) && $outcome[$file] !== false && !is_wp_error($outcome[$file]);

            $results[$file]['status'] = $ok ? 'updated' : 'failed';
            if (!$ok && $errors !== '') {
                $results[$file]['error'] = $errors;
            }
        }

        wp_clean_plugins_cache();

        return ['plugins' => array_values($results)];
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
    private static function resolve(array $inputs): array
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

    private static function slug(string $file): string
    {
        $dir = dirname($file);

        return $dir === '.' ? basename($file, '.php') : $dir;
    }

    /**
     * New versions from the cached update check, by plugin file.
     *
     * @return array<string, string>
     */
    private static function availableUpdates(): array
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
