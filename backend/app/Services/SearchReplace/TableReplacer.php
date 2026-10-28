<?php

namespace McpMate\Services\SearchReplace;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Hooks\Hooks;

/**
 * Database side of `search-replace`: scans the text columns of the given
 * tables and applies SerializedReplacer to every matching value.
 *
 * Scope:
 * - Only allowedTables(): this site's core content tables (posts, postmeta,
 *   options, comments, commentmeta, terms, termmeta, term_taxonomy, links),
 *   plus any table the `MCPMATE_search_replace_tables` filter adds that
 *   exists and carries this site's prefix. users and usermeta are never
 *   allowed: they hold password hashes and capabilities.
 * - The primary key and ALWAYS_SKIPPED_COLUMNS are never changed; callers
 *   can skip more columns.
 * - Rows of protectedOptions() and auto_update_* options are skipped, so
 *   the command cannot change roles, active plugins/themes, auto-update
 *   settings, salts, cron or this plugin's settings.
 *
 * Rows are read in primary-key order in batches of BATCH_SIZE, so memory
 * stays flat on large tables. A dry run only counts.
 */
final class TableReplacer
{
    public const BATCH_SIZE = 500;

    /** $wpdb properties of the tables allowed by default. */
    public const CORE_TABLES = ['posts', 'postmeta', 'options', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'links'];

    /**
     * Never changed, in any table: identity and key columns, and columns
     * whose change would publish content, approve comments or reveal a
     * post password.
     */
    public const ALWAYS_SKIPPED_COLUMNS = [
        'guid', 'option_name', 'autoload', 'meta_key', 'post_status', 'post_type', 'post_password', 'post_mime_type',
        'comment_approved', 'comment_type', 'taxonomy',
    ];

    public const PROTECTED_OPTIONS = [
        'active_plugins', 'admin_email', 'auth_key', 'auth_salt', 'cron',
        'default_role', 'logged_in_key', 'logged_in_salt', 'new_admin_email', 'nonce_key', 'nonce_salt',
        'recently_activated', 'secure_auth_key', 'secure_auth_salt', 'stylesheet', 'template', 'users_can_register',
    ];

    /** Every auto-update setting (plugins, themes, core) is protected too. */
    private const AUTO_UPDATE_PREFIX = 'auto_update_';

    private const IDENTIFIER = '/^[A-Za-z0-9_$]{1,64}$/D';

    /**
     * Full names of the tables search-replace may touch.
     *
     * @return string[]
     */
    public static function allowedTables(): array
    {
        global $wpdb;

        $tables = [];
        foreach (self::CORE_TABLES as $property) {
            $tables[] = $wpdb->{$property};
        }

        $extra = Hooks::applyFilter(Config::withPrefix('search_replace_tables'), []);
        $never = [$wpdb->users, $wpdb->usermeta, Config::withDBPrefix('audit_log')];

        foreach (is_array($extra) ? $extra : [] as $table) {
            if (
                is_string($table)
                && preg_match(self::IDENTIFIER, $table)
                && strpos($table, $wpdb->prefix) === 0
                && !in_array($table, $never, true)
                && DB::getVar('SHOW TABLES LIKE %s', [$wpdb->esc_like($table)]) === $table
            ) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Option names whose rows are never changed.
     *
     * @return string[]
     */
    public static function protectedOptions(): array
    {
        global $wpdb;

        return array_merge(self::PROTECTED_OPTIONS, [$wpdb->get_blog_prefix() . 'user_roles']);
    }

    public static function isProtectedOption(string $name): bool
    {
        return in_array($name, self::protectedOptions(), true)
            || stripos($name, self::AUTO_UPDATE_PREFIX) === 0
            || stripos($name, Config::VAR_PREFIX) === 0;
    }

    /**
     * @param string[] $tables      Full table names, all in allowedTables().
     * @param string[] $skipColumns Extra columns to leave alone.
     * @return array<string, mixed> Report: per table/column counts and totals.
     */
    public static function run(string $search, string $replace, array $tables, array $skipColumns, bool $dryRun): array
    {
        global $wpdb;

        $report = [];
        $totals = ['rows' => 0, 'replacements' => 0, 'skipped_values' => 0, 'skipped_protected_rows' => 0];
        $skip   = array_merge(self::ALWAYS_SKIPPED_COLUMNS, $skipColumns);

        foreach ($tables as $table) {
            [$primaryKey, $columns] = self::describe($table);

            foreach ($columns as $column) {
                if (in_array($column, $skip, true)) {
                    continue;
                }

                $counts = self::replaceColumn($table, $primaryKey, $column, $search, $replace, $dryRun, $table === $wpdb->options);

                foreach ($counts as $key => $count) {
                    $totals[$key] += $count;
                }

                if ($counts['rows'] > 0 || $counts['skipped_values'] > 0 || $counts['skipped_protected_rows'] > 0) {
                    $report[] = ['table' => $table, 'column' => $column] + $counts;
                }
            }
        }

        if (!$dryRun && $totals['rows'] > 0) {
            wp_cache_flush();
        }

        return [
            'dry_run' => $dryRun,
            'tables'  => $tables,
            'changes' => $report,
            'total'   => $totals,
        ];
    }

    /**
     * Primary key and text columns of $table.
     *
     * @return array{0: string, 1: string[]}
     */
    private static function describe(string $table): array
    {
        self::assertIdentifier($table);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from allowedTables() and checked against IDENTIFIER; there are no values to prepare.
        $rows = DB::getResults("SHOW COLUMNS FROM `{$table}`", [], ARRAY_A);

        $primary = [];
        $text    = [];

        foreach ($rows as $row) {
            $field = (string) $row['Field'];
            self::assertIdentifier($field);

            if ($row['Key'] === 'PRI') {
                $primary[] = $field;
            } elseif (preg_match('/char|text/i', (string) $row['Type'])) {
                $text[] = $field;
            }
        }

        if (count($primary) !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer error for a misconfigured table; never rendered as HTML.
            throw new \RuntimeException('search-replace needs a single-column primary key: ' . $table);
        }

        return [$primary[0], $text];
    }

    /**
     * @return array{rows: int, replacements: int, skipped_values: int, skipped_protected_rows: int}
     */
    private static function replaceColumn(string $table, string $primaryKey, string $column, string $search, string $replace, bool $dryRun, bool $isOptions): array
    {
        global $wpdb;

        $counts = ['rows' => 0, 'replacements' => 0, 'skipped_values' => 0, 'skipped_protected_rows' => 0];
        $like   = '%' . $wpdb->esc_like($search) . '%';
        $extra  = $isOptions ? ', `option_name`' : '';
        $lastId = 0;

        do {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from SHOW COLUMNS on an allowlisted table and match IDENTIFIER; all values are prepared.
            $rows = DB::getResults(
                "SELECT `{$primaryKey}` AS id, `{$column}` AS value{$extra} FROM `{$table}` WHERE `{$primaryKey}` > %d AND `{$column}` LIKE %s ORDER BY `{$primaryKey}` ASC LIMIT %d",
                [$lastId, $like, self::BATCH_SIZE],
                ARRAY_A
            );
            // phpcs:enable

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];

                if ($isOptions && self::isProtectedOption((string) $row['option_name'])) {
                    $counts['skipped_protected_rows']++;
                    continue;
                }

                $result = SerializedReplacer::replace((string) $row['value'], $search, $replace);
                $counts['skipped_values'] += $result['skipped'];

                if ($result['value'] === $row['value']) {
                    continue;
                }

                $counts['rows']++;
                $counts['replacements'] += $result['replacements'];

                if (!$dryRun) {
                    DB::update($table, [$column => $result['value']], [$primaryKey => $lastId]);
                }
            }
        } while (count($rows) === self::BATCH_SIZE);

        return $counts;
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match(self::IDENTIFIER, $name)) {
            throw new \RuntimeException('Unexpected identifier in search-replace.');
        }
    }
}
