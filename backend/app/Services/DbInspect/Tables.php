<?php

namespace McpMate\Services\DbInspect;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Hooks\Hooks;

/**
 * Which tables database inspection may read, and their structure.
 *
 * Allowed (default-deny): base tables in this site's database whose name
 * starts with this site's table prefix. Never allowed:
 * - views (they can select from anywhere);
 * - on multisite, other sites' tables (`{base_prefix}{n}_…`) and, unless
 *   the user is a super admin, the network-wide tables (users, sitemeta…);
 * - tables the `MCPMATE_db_inspect_excluded_tables` filter adds. The
 *   filter can only exclude more tables, never allow others.
 *
 * Table and column names are used as SQL identifiers only after they pass
 * IDENTIFIER and are read back from SHOW TABLE STATUS / SHOW COLUMNS.
 */
final class Tables
{
    public const IDENTIFIER = '/^[A-Za-z0-9_$]{1,64}$/D';

    /**
     * Allowed tables with their status, keyed by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function allowed(): array
    {
        $tables = [];

        foreach (self::status(DB::wpPrefix()) as $row) {
            if (self::isAllowed($row)) {
                $tables[(string) $row['Name']] = self::describeStatus($row);
            }
        }

        ksort($tables);

        return $tables;
    }

    /**
     * Status of one allowed table, or null when it does not exist or is not allowed.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $name): ?array
    {
        if (!preg_match(self::IDENTIFIER, $name) || strpos($name, DB::wpPrefix()) !== 0) {
            return null;
        }

        foreach (self::status($name) as $row) {
            if ($row['Name'] === $name && self::isAllowed($row)) {
                return self::describeStatus($row);
            }
        }

        return null;
    }

    /**
     * Columns of an allowed table, in table order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function columns(string $table): array
    {
        self::assertIdentifier($table);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table passed find() and IDENTIFIER; there are no values to prepare.
        $rows    = DB::getResults("SHOW FULL COLUMNS FROM `{$table}`", [], ARRAY_A);
        $columns = [];

        foreach ($rows as $row) {
            $name = (string) $row['Field'];
            self::assertIdentifier($name);

            $columns[] = [
                'name'      => $name,
                'type'      => (string) $row['Type'],
                'nullable'  => $row['Null'] === 'YES',
                'key'       => (string) $row['Key'],
                'default'   => $row['Default'],
                'extra'     => (string) $row['Extra'],
                'collation' => $row['Collation'],
                'comment'   => (string) $row['Comment'],
                'protected' => Sensitive::isProtectedColumn($name),
            ];
        }

        return $columns;
    }

    /**
     * Indexes of an allowed table.
     *
     * @return array<int, array{name: string, unique: bool, columns: string[]}>
     */
    public static function indexes(string $table): array
    {
        self::assertIdentifier($table);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table passed find() and IDENTIFIER; there are no values to prepare.
        $rows    = DB::getResults("SHOW INDEX FROM `{$table}`", [], ARRAY_A);
        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row['Key_name'];

            if (!isset($indexes[$name])) {
                $indexes[$name] = ['name' => $name, 'unique' => (string) $row['Non_unique'] === '0', 'columns' => []];
            }

            $indexes[$name]['columns'][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
        }

        foreach ($indexes as $name => $index) {
            ksort($index['columns']);
            $indexes[$name]['columns'] = array_values($index['columns']);
        }

        return array_values($indexes);
    }

    /**
     * Whether $name may be read, given where this site's tables live.
     * Pure: callers pass the site's prefixes and table lists.
     *
     * @param string[] $globalTables Network-wide tables to refuse (empty on single site or for super admins).
     * @param string[] $excluded     Tables excluded by the filter.
     */
    public static function isAllowedName(string $name, string $prefix, string $basePrefix, bool $multisite, array $globalTables, array $excluded): bool
    {
        if (!preg_match(self::IDENTIFIER, $name) || $prefix === '' || strpos($name, $prefix) !== 0) {
            return false;
        }

        if (in_array($name, $excluded, true) || in_array($name, $globalTables, true)) {
            return false;
        }

        // On the main site, other sites' tables share the base prefix: wp_2_posts.
        if ($multisite && $prefix === $basePrefix && preg_match('/^' . preg_quote($basePrefix, '/') . '\d+_/', $name)) {
            return false;
        }

        return true;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * SHOW TABLE STATUS rows of tables whose name starts with $like.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function status(string $like): array
    {
        global $wpdb;

        return DB::getResults('SHOW TABLE STATUS LIKE %s', [$wpdb->esc_like($like) . '%'], ARRAY_A);
    }

    /**
     * @param array<string, mixed> $row SHOW TABLE STATUS row.
     */
    private static function isAllowed(array $row): bool
    {
        global $wpdb;

        // Views have no engine and the comment VIEW.
        if (empty($row['Engine']) || strtoupper((string) $row['Comment']) === 'VIEW') {
            return false;
        }

        $multisite = is_multisite();
        $global    = $multisite && !is_super_admin() ? array_values($wpdb->tables('global')) : [];

        return self::isAllowedName((string) $row['Name'], $wpdb->prefix, $wpdb->base_prefix, $multisite, $global, self::excluded());
    }

    /**
     * @return string[]
     */
    private static function excluded(): array
    {
        $tables = Hooks::applyFilter(Config::withPrefix('db_inspect_excluded_tables'), []);

        return is_array($tables) ? array_values(array_filter($tables, 'is_string')) : [];
    }

    /**
     * @param array<string, mixed> $row SHOW TABLE STATUS row.
     * @return array<string, mixed>
     */
    private static function describeStatus(array $row): array
    {
        return [
            'name'          => (string) $row['Name'],
            'engine'        => (string) $row['Engine'],
            'rows_estimate' => (int) $row['Rows'],
            'data_bytes'    => (int) $row['Data_length'],
            'index_bytes'   => (int) $row['Index_length'],
            'collation'     => (string) $row['Collation'],
        ];
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match(self::IDENTIFIER, $name)) {
            throw self::unreadable();
        }
    }

    private static function unreadable(): DbInspectException
    {
        return new DbInspectException(esc_html__('The table has a column name that cannot be read safely.', 'mcpmate'), esc_html(DbInspectException::FAILED), 500);
    }
}
