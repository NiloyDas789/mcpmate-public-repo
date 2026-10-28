<?php

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Migration\Migration;
use McpMate\Operations\Cli\CommandSettings;
use McpMate\Services\Abilities\AbilitySettings;
use McpMate\Services\Connection\AccessPause;
use McpMate\Services\Connection\Grants;
use McpMate\Services\CoreProxy\CoreProxySettings;
use McpMate\Services\DbInspect\DbInspectSettings;
use McpMate\Services\Elementor\ElementorSettings;
use McpMate\Services\Mcp\Stock\StockPhotoSettings;
use McpMate\Services\Media\MediaSettings;
use McpMate\Services\Onboarding\OnboardingState;
use McpMate\Services\OAuth\OAuthRoutes;
use McpMate\Services\Settings\Preferences;
use McpMate\Services\ThemeDraft\ThemeDraftSettings;
use McpMate\Services\Tools\ToolSettings;

if (!defined('ABSPATH')) {
    exit;
}

final class McpMatePluginOptions extends Migration
{
    public function up(): void
    {
        // Also runs on every DB_VERSION upgrade: add_option() keeps existing values.
        Config::updateOption('db_version', Config::DB_VERSION, true);
        Config::addOption('installed', time(), true);
        Config::updateOption('version', Config::VERSION, true);

        // 0.5.0: the boilerplate example settings are gone.
        Config::deleteOption('settings');

        // 0.5.0: setup wizard. Runs after McpMateConnection, so the grants
        // table exists; a site with a grant was set up before the wizard.
        OnboardingState::seed(Grants::countAll() > 0);

        // 0.6.0: the feature switches became groups of the Tools page. Runs
        // before the SVG line below, which reads the media switch through it.
        ToolSettings::seed();

        // 0.5.0: SVG got its own switch. Sites that already allow media
        // uploads keep SVG working; new installs start with it off.
        Config::addOption(MediaSettings::SVG_OPTION, MediaSettings::isEnabled(), false);
    }

    public function down(): void
    {
        $options = [
            Config::withPrefix('db_version'),
            Config::withPrefix('installed'),
            Config::withPrefix('version'),
            Config::withPrefix('settings'),
            Config::withPrefix(CommandSettings::OPTION),
            Config::withPrefix(CoreProxySettings::OPTION),
            Config::withPrefix(DbInspectSettings::OPTION),
            Config::withPrefix(MediaSettings::OPTION),
            Config::withPrefix(MediaSettings::SVG_OPTION),
            Config::withPrefix(ThemeDraftSettings::OPTION),
            Config::withPrefix(ElementorSettings::OPTION),
            Config::withPrefix(AbilitySettings::OPTION),
            Config::withPrefix(OnboardingState::OPTION),
            Config::withPrefix(ToolSettings::OPTION),
            Config::withPrefix(AccessPause::OPTION),
            Config::withPrefix(Preferences::OPTION),
            Config::withPrefix(OAuthRoutes::RULES_OPTION),
            // The sealed Unsplash key goes with the plugin.
            Config::withPrefix(StockPhotoSettings::OPTION),
            // Theme draft and backup records stay: their folders and zips are
            // never deleted (rule 3), so the records keep pointing at them.
        ];

        $placeholders = implode(',', array_fill(0, count($options), '%s'));

        DB::query(
            "DELETE FROM `" . DB::wpPrefix() . "options` WHERE option_name IN ({$placeholders})",
            $options
        );
    }
}
