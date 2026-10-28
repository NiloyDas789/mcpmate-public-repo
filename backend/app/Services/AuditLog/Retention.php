<?php

namespace McpMate\Services\AuditLog;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Hooks\Hooks;
use McpMate\Services\ChangeHistory\ChangeStore;
use McpMate\Services\Settings\Preferences;

/**
 * Daily cron that removes audit rows older than the retention window, read
 * rows older than theirs, and change history past its own.
 *
 * This is the only code that deletes audit rows, and it deletes by age only.
 * A site admin can run purge() early from the Audit log (admin-ajax
 * `audit-log/purge`, after `audit-log/purge-preview` shows expired()).
 * Each window comes from Settings (Preferences) and can be changed in code
 * with a filter, which receives the setting as its default:
 * `MCPMATE_audit_log_retention_days` (every row, 90 days),
 * `MCPMATE_read_log_retention_days` (rows of `kind` read, 30) and
 * `MCPMATE_change_history_retention_days` (ChangeStore, 30). A value
 * below 1 keeps every row.
 */
final class Retention
{
    public const HOOK = Config::VAR_PREFIX . 'audit_log_purge';

    public const DEFAULT_DAYS = 90;

    private const FILTER_AUDIT = 'audit_log_retention_days';

    private const FILTER_READS = 'read_log_retention_days';

    private const FILTER_CHANGES = 'change_history_retention_days';

    /** Rows deleted per query, so a large backlog does not hold one long lock. */
    private const BATCH = 1000;

    /**
     * Hook the purge onto the cron event and make sure the event is scheduled.
     */
    public static function register(): void
    {
        Hooks::addAction(self::HOOK, [self::class, 'purge']);
        Hooks::addAction(self::HOOK, [self::class, 'purgeReads']);
        Hooks::addAction(self::HOOK, [self::class, 'purgeChanges']);

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function days(): int
    {
        return (int) Hooks::applyFilter(Config::withPrefix(self::FILTER_AUDIT), (int) Preferences::get('audit_retention_days'));
    }

    public static function readDays(): int
    {
        return (int) Hooks::applyFilter(Config::withPrefix(self::FILTER_READS), (int) Preferences::get('read_retention_days'));
    }

    public static function changeDays(): int
    {
        return (int) Hooks::applyFilter(Config::withPrefix(self::FILTER_CHANGES), (int) Preferences::get('change_retention_days'));
    }

    /**
     * Windows a filter sets in code: preference key => days. The Settings
     * page shows them as fixed ("Set by code on this site: 120 days.").
     *
     * @return array<string, int>
     */
    public static function filtered(): array
    {
        $filtered = [];

        foreach (['audit_retention_days' => self::FILTER_AUDIT, 'read_retention_days' => self::FILTER_READS, 'change_retention_days' => self::FILTER_CHANGES] as $key => $filter) {
            if (has_filter(Config::withPrefix($filter))) {
                $filtered[$key] = (int) Hooks::applyFilter(Config::withPrefix($filter), (int) Preferences::get($key));
            }
        }

        return $filtered;
    }

    /**
     * Delete rows created before now minus days().
     *
     * @return int Rows deleted.
     */
    public static function purge(): int
    {
        return self::delete(self::days(), '');
    }

    /**
     * How many rows purge() would delete now.
     */
    public static function expired(): int
    {
        $days = self::days();

        if ($days < 1) {
            return 0;
        }

        $table = Logger::table();

        return (int) DB::getVar("SELECT COUNT(*) FROM `{$table}` WHERE created_at < %s", [self::cutoff($days)]);
    }

    /**
     * Delete read rows created before now minus readDays().
     *
     * @return int Rows deleted.
     */
    public static function purgeReads(): int
    {
        return self::delete(self::readDays(), Logger::KIND_READ);
    }

    /**
     * Delete change history rows created before now minus changeDays().
     *
     * @return int Rows deleted.
     */
    public static function purgeChanges(): int
    {
        return ChangeStore::purge(self::changeDays());
    }

    /**
     * @param string $kind '' for every row, or one `kind`.
     */
    private static function delete(int $days, string $kind): int
    {
        if ($days < 1) {
            return 0;
        }

        $table   = Logger::table();
        $cutoff  = self::cutoff($days);
        $deleted = 0;
        $byKind  = $kind !== '' ? ' AND kind = %s' : '';
        $args    = $kind !== '' ? [$cutoff, $kind, self::BATCH] : [$cutoff, self::BATCH];

        do {
            $count = DB::query("DELETE FROM `{$table}` WHERE created_at < %s{$byKind} LIMIT %d", $args);

            if (!is_int($count)) {
                break;
            }

            $deleted += $count;
        } while ($count === self::BATCH);

        return $deleted;
    }

    /**
     * `Y-m-d H:i:s` UTC, $days ago.
     */
    private static function cutoff(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    }
}
