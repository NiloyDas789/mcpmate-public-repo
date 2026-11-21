<?php

namespace MCPMate\Services\Uninstall;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Database\Connection as DB;
use MCPMate\Services\AuditLog\AuditLogQuery;
use MCPMate\Services\AuditLog\Logger;
use MCPMate\Services\ChangeHistory\ChangeStore;
use MCPMate\Services\Settings\Preferences;

/**
 * "Before you uninstall" (docs/pro-parity.md P21): what Remove all data on
 * uninstall would delete from this site and what stays, and the audit log
 * and change history a page at a time, so a site admin can keep a copy.
 * Nothing here writes a file: the browser puts the pages together into
 * one download, so no export sits on the server where anyone with its URL
 * could fetch it (as theme backups can on nginx, known gap 15).
 *
 * MCPMate Pro's leftovers (sandbox folder, theme drafts and backups,
 * designs) are read from their option names, folders and post type, so
 * the summary is right with Pro inactive too.
 */
final class UninstallReview
{
    public const KINDS = ['audit_log', 'changes'];

    /** Rows per export page: audit rows are small; change rows carry two snapshots of ≤ 512 KiB. */
    public const PER_PAGE = ['audit_log' => AuditLogQuery::MAX_PER_PAGE, 'changes' => 10];

    /** At most this many files are counted in the sandbox folder. */
    private const MAX_FILES = 5000;

    /**
     * @return array{delete_data: bool, multisite: bool, tables: array<int, array{name: string, rows: int}>, options: string[],
     *               keeps: string[], sandbox: array{plugins: int, files: int, more: bool}, theme_backups: array{count: int, bytes: int},
     *               theme_drafts: int, designs: int}
     */
    public static function summary(): array
    {
        return [
            'delete_data'   => Preferences::deleteDataOnUninstall(),
            'multisite'     => is_multisite(),
            'tables'        => self::tables(),
            'options'       => array_values(array_intersect(UninstallData::options(), self::storedOptions())),
            'keeps'         => array_values(array_diff(self::storedOptions(), UninstallData::options())),
            'sandbox'       => self::sandbox(),
            'theme_backups' => self::themeBackups(),
            'theme_drafts'  => self::themeDrafts(),
            'designs'       => self::designs(),
        ];
    }

    /**
     * The rows of $kind after ID $after, oldest first, up to ID $until. The
     * first call ($until 0) fixes $until at the highest ID now, and every
     * later call names it, so rows added during the export stay out and a
     * purge between pages skips nothing that is still there. The first
     * call writes one audit row.
     *
     * @return array{kind: string, per_page: int, until: int, total: int, next_after: int, done: bool, items: array<int, array<string, mixed>>}
     */
    public static function export(string $kind, int $after, int $until): array
    {
        $kind    = in_array($kind, self::KINDS, true) ? $kind : 'audit_log';
        $perPage = self::PER_PAGE[$kind];
        $after   = max(0, $after);
        $first   = $until < 1;

        if ($first) {
            $after = 0;
            $until = $kind === 'changes' ? ChangeStore::maxId() : AuditLogQuery::maxId();
        }

        $result = $kind === 'changes'
            ? ChangeStore::exportPage($after, $until, $perPage)
            : AuditLogQuery::exportPage($after, $until, $perPage);
        $last   = $result['items'] === [] ? $after : (int) end($result['items'])['id'];

        if ($first) {
            // One row per export, not per page: the pages are one download.
            Logger::log([
                'tool'   => 'uninstall_review',
                'action' => 'export',
                'target' => $kind,
                'args'   => ['kind' => $kind, 'total' => $result['total'], 'until' => $until],
                'result' => Logger::RESULT_OK,
                'kind'   => Logger::KIND_READ,
            ]);
        }

        return [
            'kind'       => $kind,
            'per_page'   => $perPage,
            'until'      => $until,
            'total'      => $result['total'],
            'next_after' => $last,
            'done'       => count($result['items']) < $perPage || $last >= $until,
            'items'      => $result['items'],
        ];
    }

    // ──────────────────────────────────────────────
    //  What is deleted
    // ──────────────────────────────────────────────

    /**
     * Each table that exists, with its row count.
     *
     * @return array<int, array{name: string, rows: int}>
     */
    private static function tables(): array
    {
        $tables = [];

        foreach (UninstallData::tables() as $name) {
            if (DB::getVar('SHOW TABLES LIKE %s', [self::escapeLike($name)]) !== $name) {
                continue;
            }

            // The name comes from UninstallData::TABLES, never from the request.
            $tables[] = ['name' => $name, 'rows' => (int) DB::getVar("SELECT COUNT(*) FROM `{$name}`")];
        }

        return $tables;
    }

    /**
     * This site's options under the plugin's prefix (MCPMate Pro's included).
     *
     * @return string[]
     */
    private static function storedOptions(): array
    {
        $rows = DB::getResults(
            'SELECT option_name FROM `' . DB::wpPrefix() . 'options` WHERE option_name LIKE %s ORDER BY option_name',
            [self::escapeLike(Config::VAR_PREFIX) . '%'],
            ARRAY_A
        );

        return array_map(static fn (array $row): string => (string) $row['option_name'], $rows);
    }

    // ──────────────────────────────────────────────
    //  What stays (rule 3)
    // ──────────────────────────────────────────────

    /**
     * Sandbox plugins' folders: their records go, the files stay. `more`:
     * the count is incomplete (past MAX_FILES, or a folder that would not open).
     *
     * @return array{plugins: int, files: int, more: bool}
     */
    private static function sandbox(): array
    {
        $root   = trailingslashit(WP_CONTENT_DIR) . 'mcpmate-sandbox';
        $counts = ['plugins' => 0, 'files' => 0, 'more' => false];

        if (!is_dir($root) || is_link($root)) {
            return $counts;
        }

        foreach ((array) scandir($root) as $entry) {
            $path = $root . DIRECTORY_SEPARATOR . $entry;

            if (!is_string($entry) || $entry === '' || $entry[0] === '.' || !is_dir($path) || is_link($path)) {
                continue;
            }

            $counts['plugins']++;

            try {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

                foreach ($iterator as $file) {
                    if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                        continue;
                    }

                    if ($counts['files'] >= self::MAX_FILES) {
                        $counts['more'] = true;
                        break 2;
                    }

                    $counts['files']++;
                }
            } catch (\UnexpectedValueException $e) {
                // A folder PHP cannot open: the count is a floor, as past MAX_FILES.
                $counts['more'] = true;
            }
        }

        return $counts;
    }

    /**
     * @return array{count: int, bytes: int}
     */
    private static function themeBackups(): array
    {
        $records = Config::getOption('theme_backups', []);
        $records = is_array($records) ? array_filter($records, 'is_array') : [];

        return [
            'count' => count($records),
            'bytes' => (int) array_sum(array_map(static fn (array $record): int => (int) ($record['bytes'] ?? 0), $records)),
        ];
    }

    private static function themeDrafts(): int
    {
        $records = Config::getOption('theme_draft_records', []);

        return is_array($records) ? count(array_filter($records, 'is_array')) : 0;
    }

    /**
     * Saved designs (MCPMate Pro's `mcpmate_design` posts, with their revisions: known gap 59).
     */
    private static function designs(): int
    {
        return (int) DB::getVar(
            'SELECT COUNT(*) FROM `' . DB::wpPrefix() . 'posts` WHERE post_type = %s',
            ['mcpmate_design']
        );
    }

    private static function escapeLike(string $value): string
    {
        global $wpdb;

        return $wpdb->esc_like($value);
    }
}
