<?php

use MCPMate\Config;
use MCPMate\Database\Connection as DB;
use MCPMate\Migration\Migration;
use MCPMate\Services\Connection\Grants;
use MCPMate\Services\Onboarding\OnboardingState;
use MCPMate\Services\Tools\ToolSettings;
use MCPMate\Services\Uninstall\UninstallData;

if (!defined('ABSPATH')) {
    exit;
}

final class MCPMatePluginOptions extends Migration
{
    public function up(): void
    {
        // Also runs on every DB_VERSION upgrade: add_option() keeps existing values.
        Config::updateOption('db_version', Config::DB_VERSION, true);
        Config::addOption('installed', time(), true);
        Config::updateOption('version', Config::VERSION, true);

        // 0.5.0: the boilerplate example settings are gone.
        Config::deleteOption('settings');

        // 0.5.0: setup wizard. Runs after MCPMateConnection, so the grants
        // table exists; a site with a grant was set up before the wizard.
        OnboardingState::seed(Grants::countAll() > 0);

        // 0.6.0: the feature switches became groups of the Tools page. Runs
        // before the SVG line below, which reads the media switch through it.
        ToolSettings::seed();

        // 0.5.0: SVG got its own switch (MCPMate Pro's media uploads). Sites
        // that already allow media uploads keep SVG working; new installs
        // start with it off.
        $tools = Config::getOption(ToolSettings::OPTION, []);
        Config::addOption('media_svg', is_array($tools) && !empty($tools['upload_media']), false);
    }

    public function down(): void
    {
        // The one list the uninstall review shows too (Settings → Advanced).
        $options      = UninstallData::options();
        $placeholders = implode(',', array_fill(0, count($options), '%s'));

        DB::query(
            "DELETE FROM `" . DB::wpPrefix() . "options` WHERE option_name IN ({$placeholders})",
            $options
        );

        foreach (UninstallData::USER_META as $key) {
            delete_metadata('user', 0, Config::withPrefix($key), '', true);
        }
    }
}
