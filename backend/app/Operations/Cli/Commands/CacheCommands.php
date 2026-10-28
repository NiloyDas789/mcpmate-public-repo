<?php

namespace McpMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\ParsedCommand;

/**
 * `cache flush`, `rewrite flush`, `transient delete`: caches WordPress
 * rebuilds on its own.
 *
 * `rewrite flush` is always a soft flush (the `rewrite_rules` option only).
 * A hard flush writes .htaccess, and file writes are not allowed, so
 * `--hard` is not accepted.
 */
final class CacheCommands extends CommandGroup
{
    public static function register(CommandRegistry $registry): void
    {
        $registry->add('cache flush', [
            'summary'    => __('Flush the object cache.', 'mcpmate'),
            'risk'       => Command::RISK_WRITE,
            'capability' => 'manage_options',
            'handler'    => [self::class, 'cacheFlush'],
        ]);

        $registry->add('rewrite flush', [
            'summary'    => __('Regenerate the rewrite rules (soft flush, no .htaccess changes).', 'mcpmate'),
            'risk'       => Command::RISK_WRITE,
            'capability' => 'manage_options',
            'handler'    => [self::class, 'rewriteFlush'],
        ]);

        $registry->add('transient delete', [
            'summary'          => __('Delete one transient, or all expired transients.', 'mcpmate'),
            'risk'             => Command::RISK_WRITE,
            'capability'       => 'manage_options',
            'supports_dry_run' => true,
            'args'             => [
                // Core's limit for transient names.
                ['name' => 'key', 'max_length' => 172, 'pattern' => '/^[A-Za-z0-9_.:-]+$/D'],
            ],
            'flags'            => [
                'expired' => ['type' => 'boolean'],
                'network' => ['type' => 'boolean'],
            ],
            'target'           => ['transient', 'key'],
            'handler'          => [self::class, 'transientDelete'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function cacheFlush(ParsedCommand $command, bool $dryRun): array
    {
        return ['flushed' => (bool) wp_cache_flush()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rewriteFlush(ParsedCommand $command, bool $dryRun): array
    {
        flush_rewrite_rules(false);

        return ['flushed' => true, 'hard' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public static function transientDelete(ParsedCommand $command, bool $dryRun): array
    {
        $key     = $command->arg('key');
        $expired = (bool) $command->flag('expired', false);
        $network = (bool) $command->flag('network', false);

        if (($key === null) === !$expired) {
            throw self::notAllowed(
                esc_html__('Give either a transient name or --expired.', 'mcpmate'),
                esc_html__('Usage: transient delete <key> [--network], or transient delete --expired.', 'mcpmate')
            );
        }

        if ($expired) {
            if (!$dryRun) {
                delete_expired_transients(true);
            }

            return ['expired' => true, 'status' => $dryRun ? 'would_delete' : 'deleted'];
        }

        $exists = $network ? get_site_transient($key) !== false : get_transient($key) !== false;

        if (!$exists) {
            return ['key' => $key, 'network' => $network, 'status' => 'not_found'];
        }

        if ($dryRun) {
            return ['key' => $key, 'network' => $network, 'status' => 'would_delete'];
        }

        $deleted = $network ? delete_site_transient($key) : delete_transient($key);

        return ['key' => $key, 'network' => $network, 'status' => $deleted ? 'deleted' : 'failed'];
    }
}
