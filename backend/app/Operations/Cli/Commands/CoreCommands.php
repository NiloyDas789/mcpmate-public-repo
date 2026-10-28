<?php

namespace McpMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandException;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\ParsedCommand;

/**
 * `core version`, `core verify-checksums`.
 */
final class CoreCommands extends CommandGroup
{
    /** Longest list of modified or missing files returned. */
    public const MAX_FILES = 50;

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('core version', [
            'summary'    => __('Show the WordPress version.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'manage_options',
            'flags'      => [
                'extra' => ['type' => 'boolean'],
            ],
            'handler'    => [self::class, 'version'],
        ]);

        $registry->add('core verify-checksums', [
            'summary'    => __('Compare core files with the official checksums from WordPress.org.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'update_core',
            'handler'    => [self::class, 'verifyChecksums'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(ParsedCommand $command, bool $dryRun): array
    {
        global $wp_version, $wp_db_version, $tinymce_version, $required_php_version, $required_mysql_version;

        $result = ['version' => (string) $wp_version];

        if ($command->flag('extra')) {
            $result += [
                'db_version'             => (int) $wp_db_version,
                'tinymce_version'        => (string) $tinymce_version,
                'locale'                 => get_locale(),
                'required_php_version'   => (string) $required_php_version,
                'required_mysql_version' => (string) $required_mysql_version,
            ];
        }

        return $result;
    }

    /**
     * Checks every core file listed by api.wordpress.org (wp-content is
     * skipped, as in WP-CLI). One outbound request, through core's
     * get_core_checksums().
     *
     * @return array<string, mixed>
     */
    public static function verifyChecksums(ParsedCommand $command, bool $dryRun): array
    {
        global $wp_version, $wp_local_package;

        self::requireAdmin('update.php');

        $locale    = isset($wp_local_package) ? (string) $wp_local_package : 'en_US';
        $checksums = get_core_checksums($wp_version, $locale);

        if (!is_array($checksums) && $locale !== 'en_US') {
            $locale    = 'en_US';
            $checksums = get_core_checksums($wp_version, $locale);
        }

        if (!is_array($checksums) || $checksums === []) {
            throw new CommandException(
                esc_html__('Could not get core checksums from WordPress.org.', 'mcpmate'),
                esc_html(CommandException::FAILED),
                502,
                esc_html__('The site may be offline, or this WordPress version may not be a release. Try again later.', 'mcpmate')
            );
        }

        $modified = [];
        $missing  = [];
        $checked  = 0;

        foreach ($checksums as $file => $md5) {
            $file = (string) $file;

            if (strpos($file, 'wp-content/') === 0) {
                continue;
            }

            $checked++;
            $path = ABSPATH . $file;

            if (!is_file($path)) {
                $missing[] = $file;
            } elseif (md5_file($path) !== $md5) {
                $modified[] = $file;
            }
        }

        return [
            'version'        => (string) $wp_version,
            'locale'         => $locale,
            'verified'       => $modified === [] && $missing === [],
            'files_checked'  => $checked,
            'modified_count' => count($modified),
            'missing_count'  => count($missing),
            'modified'       => array_slice($modified, 0, self::MAX_FILES),
            'missing'        => array_slice($missing, 0, self::MAX_FILES),
        ];
    }
}
