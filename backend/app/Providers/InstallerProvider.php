<?php

namespace MCPMate\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Hooks\Hooks;
use MCPMate\Installer;
use MCPMate\Services\AuditLog\Retention;
use MCPMate\Services\OAuth\OAuthRoutes;
use MCPMate\Services\Onboarding\ActivationRedirect;
use MCPMate\Services\Settings\Preferences;

/**
 * Handles plugin activation, deactivation, and uninstallation.
 *
 * Migrations run on activation and are cleaned up on uninstall.
 * Scheduled events are cleared on deactivation.
 */
final class InstallerProvider
{
    private string $activateHook;

    private string $deactivateHook;

    private static string $uninstallHook;

    public function __construct()
    {
        $mainFile = Config::get('MAIN_FILE');

        register_activation_hook($mainFile, [$this, 'onActivate']);
        register_deactivation_hook($mainFile, [$this, 'onDeactivate']);

        $this->activateHook   = Config::withPrefix('activate');
        $this->deactivateHook = Config::withPrefix('deactivate');
        self::$uninstallHook  = Config::withPrefix('uninstall');

        Hooks::addAction($this->deactivateHook, [$this, 'deactivate']);
        register_uninstall_hook($mainFile, [self::class, 'onUninstall']);
    }

    public function register(): void
    {
        $installer = new Installer(
            [
                'php'        => Config::REQUIRED_PHP_VERSION,
                'wp'         => Config::REQUIRED_WP_VERSION,
                'version'    => Config::VERSION,
                'oldVersion' => Config::getOption('version', '0.0'),
                'multisite'  => true,
                'basename'   => Config::get('BASENAME'),
            ],
            [
                'activate'  => $this->activateHook,
                'uninstall' => self::$uninstallHook,
            ],
            [
                'migration' => self::migration(),
                'drop'      => self::drop(),
            ]
        );

        $installer->register();
    }

    public function deactivate(): void
    {
        // Clear scheduled cron events — list every hook your plugin schedules
        wp_clear_scheduled_hook(Retention::HOOK);
        flush_rewrite_rules();
    }

    public function onActivate(bool $networkWide): void
    {
        Hooks::doAction($this->activateHook, $networkWide);

        // The OAuth rewrite rules are flushed on the next request, once every plugin has added its own.
        Config::deleteOption(OAuthRoutes::RULES_OPTION);

        // After the migrations: open the setup wizard once (single activation by a site admin only).
        ActivationRedirect::arm($networkWide);
    }

    public function onDeactivate(bool $networkWide): void
    {
        Hooks::doAction($this->deactivateHook, $networkWide);
    }

    public static function onUninstall(bool $networkWide = false): void
    {
        // Settings → Advanced, "Remove all data on uninstall" (on by default):
        // when off, the tables, settings and grants stay for a reinstall.
        if (!Preferences::deleteDataOnUninstall()) {
            return;
        }

        Hooks::doAction(self::$uninstallHook, $networkWide);
    }

    /**
     * Migration classes to run on activation / upgrade.
     */
    public static function migration(): array
    {
        return [
            'path'       => Config::get('BASEDIR')
                . DIRECTORY_SEPARATOR . 'db'
                . DIRECTORY_SEPARATOR . 'Migrations'
                . DIRECTORY_SEPARATOR,
            'migrations' => [
                'MCPMateAuditLog',
                'MCPMateApprovals',
                'MCPMateConnection',
                'MCPMateChanges',
                'MCPMateOAuth',
                'MCPMateSkills',
                'MCPMateMemories',
                'MCPMateBlockBatches',
                'MCPMateUploadLinks',
                'MCPMateJobs',
                'MCPMateChatSessions',
                'MCPMateLoginLinks',
                'MCPMatePluginOptions',
            ],
        ];
    }

    /**
     * Tables to drop on uninstall (reverse order of creation).
     */
    public static function drop(): array
    {
        return [
            'path'       => Config::get('BASEDIR')
                . DIRECTORY_SEPARATOR . 'db'
                . DIRECTORY_SEPARATOR . 'Migrations'
                . DIRECTORY_SEPARATOR,
            'migrations' => [
                'MCPMatePluginOptions',
                'MCPMateLoginLinks',
                'MCPMateChatSessions',
                'MCPMateJobs',
                'MCPMateUploadLinks',
                'MCPMateBlockBatches',
                'MCPMateMemories',
                'MCPMateSkills',
                'MCPMateOAuth',
                'MCPMateChanges',
                'MCPMateConnection',
                'MCPMateApprovals',
                'MCPMateAuditLog',
            ],
        ];
    }
}
