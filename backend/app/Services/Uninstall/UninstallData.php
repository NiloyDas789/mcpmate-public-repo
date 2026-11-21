<?php

namespace MCPMate\Services\Uninstall;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Services\Abilities\AbilitySettings;
use MCPMate\Services\Connection\AccessPause;
use MCPMate\Services\Connection\ReadMode;
use MCPMate\Services\DbInspect\DbInspectSettings;
use MCPMate\Services\Mcp\Stock\StockPhotoSettings;
use MCPMate\Services\OAuth\OAuthRoutes;
use MCPMate\Services\Onboarding\OnboardingState;
use MCPMate\Services\Settings\Preferences;
use MCPMate\Services\Tools\ToolSettings;

/**
 * What "Remove all data on uninstall" deletes: the one list the uninstall
 * (MCPMatePluginOptions::down() and each migration's down()) and the
 * review screen (UninstallReview) share. `UninstallDataTest` keeps TABLES
 * equal to the tables the migrations drop.
 */
final class UninstallData
{
    /** Tables the migrations drop, without the prefix (`Config::withDBPrefix()`). */
    public const TABLES = [
        'audit_log',
        'approvals',
        'grants',
        'changes',
        'oauth_clients',
        'oauth_requests',
        'oauth_tokens',
        'skills',
        'memories',
        'block_batches',
        'block_batch_items',
        'upload_links',
        'jobs',
        'chat_sessions',
        'chat_images',
        'login_links',
    ];

    /** MCPMate Pro's settings options, kept under this plugin's prefix, so they go with it. */
    public const PRO_OPTIONS = [
        'cli_commands',
        'core_proxy',
        'media_upload',
        'media_svg',
        'theme_drafts',
        'elementor',
        // Settings → AI instructions.
        'site_instructions',
        // Tools → Design: which design is active (the designs are posts and stay).
        'active_design',
        // Upload links: the rewrite rule's version.
        'upload_rewrite_version',
        // Sandbox plugins: their records (the files in wp-content/mcpmate-sandbox stay, rule 3).
        'sandbox_plugins',
        // Plugin integrations: the operations a site admin turned on.
        'integration_operations',
        // Chat: the sealed Anthropic key, the model and the switch.
        'chat',
        // Opt-in hatches: Ghost Mode, and the login links' rewrite rule version.
        'ghost_mode',
        'login_rewrite_version',
    ];

    /** User meta keys (prefixed) deleted on uninstall: MCPMate Pro's Chat grant and its first-use notice. */
    public const USER_META = ['chat_grant', 'chat_consent'];

    /**
     * Every option deleted on uninstall, with its prefix. Theme draft and
     * backup records stay: their folders and zips are never deleted
     * (rule 3), so the records keep pointing at them.
     *
     * @return string[]
     */
    public static function options(): array
    {
        $names = [
            'db_version',
            'installed',
            'version',
            'settings',
            DbInspectSettings::OPTION,
            AbilitySettings::OPTION,
            OnboardingState::OPTION,
            ToolSettings::OPTION,
            AccessPause::OPTION,
            ReadMode::OPTION,
            Preferences::OPTION,
            OAuthRoutes::RULES_OPTION,
            // The sealed Unsplash key goes with the plugin.
            StockPhotoSettings::OPTION,
        ];

        return array_map([Config::class, 'withPrefix'], array_merge($names, self::PRO_OPTIONS));
    }

    /**
     * Every table, with its prefix.
     *
     * @return string[]
     */
    public static function tables(): array
    {
        return array_map([Config::class, 'withDBPrefix'], self::TABLES);
    }
}
