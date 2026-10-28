<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Check 3: WordPress and PHP versions. Activation already refuses anything
 * below the minimums, so this passes; its info line says whether other
 * plugins' abilities (WordPress 6.9+) are available.
 */
final class VersionsCheck extends Check
{
    public const ID = 'versions';

    /** First WordPress version with the Abilities API in core. */
    public const ABILITIES_WP = '6.9';

    private string $wpVersion;

    private string $phpVersion;

    public function __construct(string $wpVersion, string $phpVersion)
    {
        $this->wpVersion  = $wpVersion;
        $this->phpVersion = $phpVersion;
    }

    public function run(): array
    {
        $title = __('WordPress and PHP are up to date enough', 'mcpmate');

        $message = sprintf(
            /* translators: 1: WordPress version, 2: PHP version, 3: minimum WordPress version, 4: minimum PHP version. */
            __('WordPress %1$s and PHP %2$s (McpMate needs WordPress %3$s and PHP %4$s or later).', 'mcpmate'),
            $this->wpVersion,
            $this->phpVersion,
            Config::REQUIRED_WP_VERSION,
            Config::REQUIRED_PHP_VERSION
        );

        $note = version_compare($this->wpVersion, self::ABILITIES_WP, '>=')
            ? __('Other plugins\' actions (abilities) are available.', 'mcpmate')
            : __('Other plugins\' actions need WordPress 6.9 or later. Everything else works.', 'mcpmate');

        return self::result(self::PASS, $title, $message, '', [['text' => $note]]);
    }
}
