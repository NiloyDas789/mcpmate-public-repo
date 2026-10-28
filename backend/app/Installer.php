<?php

namespace McpMate;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Hooks\Hooks;
use McpMate\Migration\MigrationHelper;

/**
 * Plugin installer — validates requirements and wires up activation/uninstall hooks.
 *
 *
 *   $installer = new Installer(
 *       ['php' => '7.4', 'wp' => '6.0', 'version' => '1.0', 'oldVersion' => '0.0', ...],
 *       ['activate' => 'mcpmate_activate', 'uninstall' => 'mcpmate_uninstall'],
 *       ['migration' => [...], 'drop' => [...]]
 *   );
 *   $installer->register();
 */
final class Installer
{
    /** @var array<string, mixed> */
    private array $requirements;

    /** @var array<string, string> Hook names for activate/uninstall */
    private array $hooks;

    /** @var array<string, array> Migration/drop configs */
    private array $migrations;

    /**
     * @param array<string, mixed>  $requirements PHP/WP version requirements + plugin metadata
     * @param array<string, string> $hooks        Hook names: 'activate' and 'uninstall'
     * @param array<string, array>  $migrations   Keys 'migration' (up) and 'drop' (down)
     */
    public function __construct(array $requirements, array $hooks, array $migrations)
    {
        $this->requirements = $requirements;
        $this->hooks        = $hooks;
        $this->migrations   = $migrations;
    }

    /**
     * Register activation and uninstall callbacks.
     */
    public function register(): void
    {
        // Activation: check requirements, then run migrations and fire the hook
        Hooks::addAction($this->hooks['activate'] ?? '', [$this, 'onActivate']);

        // Uninstall: drop database tables
        Hooks::addAction($this->hooks['uninstall'] ?? '', [$this, 'onUninstall']);
    }

    /**
     * Fires on plugin activation.
     * Checks PHP and WP version requirements. If requirements are not met,
     * deactivates the plugin and shows an admin notice. Otherwise runs migrations.
     */
    public function onActivate(): void
    {
        if (!$this->meetsRequirements()) {
            return;
        }

        if (!empty($this->migrations['migration'])) {
            MigrationHelper::migrate($this->migrations['migration']);
        }

        // Persist current plugin version to options
        if (!empty($this->requirements['version'])) {
            Config::updateOption('version', $this->requirements['version']);
        }
    }

    /**
     * Fires on plugin uninstall — drops all plugin database tables.
     */
    public function onUninstall(): void
    {
        if (!empty($this->migrations['drop'])) {
            MigrationHelper::drop($this->migrations['drop']);
        }
    }

    // ──────────────────────────────────────────────
    //  Requirements check
    // ──────────────────────────────────────────────

    private function meetsRequirements(): bool
    {
        $requiredPhp = $this->requirements['php'] ?? '7.4';
        $requiredWp  = $this->requirements['wp']  ?? '6.0';

        if (version_compare(PHP_VERSION, $requiredPhp, '<')) {
            add_action('admin_notices', function () use ($requiredPhp) {
                $message = sprintf(
                    '<b>%s</b> requires PHP %s or higher. You are running PHP %s.',
                    esc_html(Config::title()),
                    esc_html($requiredPhp),
                    esc_html(PHP_VERSION)
                );
                echo '<div class="notice notice-error"><p>' . wp_kses_post($message) . '</p></div>';
            });

            return false;
        }

        global $wp_version;

        if (version_compare($wp_version, $requiredWp, '<')) {
            add_action('admin_notices', function () use ($requiredWp) {
                global $wp_version;
                $message = sprintf(
                    '<b>%s</b> requires WordPress %s or higher. You are running %s.',
                    esc_html(Config::title()),
                    esc_html($requiredWp),
                    esc_html($wp_version)
                );
                echo '<div class="notice notice-error"><p>' . wp_kses_post($message) . '</p></div>';
            });

            return false;
        }

        return true;
    }
}
