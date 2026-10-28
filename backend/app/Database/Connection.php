<?php

namespace McpMate\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin static wrapper around $wpdb.
 *
 * query(), getResults(), getVar() and getRow() take the SQL with %s, %d and
 * %f placeholders and its values in $args, and prepare them here. Every
 * value goes in $args; table names come from Config::withDBPrefix() or an
 * allowlist (WordPress 6.0 has no %i placeholder).
 */
final class Connection
{
    /**
     * Execute a query and return the number of affected rows (or false on error).
     *
     * @param array<int, mixed> $args Values for the placeholders in $sql.
     * @return int|bool
     */
    public static function query(string $sql, array $args = [])
    {
        global $wpdb;

        $sql = self::prepareArgs($sql, $args);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepared via $wpdb->prepare() above or is a static query; all callers audited.
        return $wpdb->query($sql);
    }

    /**
     * Get multiple rows as an array of objects or arrays.
     *
     * @param array<int, mixed> $args Values for the placeholders in $sql.
     */
    public static function getResults(string $sql, array $args = [], string $output = OBJECT): array
    {
        global $wpdb;

        $sql = self::prepareArgs($sql, $args);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepared via $wpdb->prepare() above or is a static query; all callers audited.
        return $wpdb->get_results($sql, $output) ?? [];
    }

    /**
     * Get a single value from the first column of the first row.
     *
     * @param array<int, mixed> $args Values for the placeholders in $sql.
     * @return mixed
     */
    public static function getVar(string $sql, array $args = [])
    {
        global $wpdb;

        $sql = self::prepareArgs($sql, $args);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepared via $wpdb->prepare() above or is a static query; all callers audited.
        return $wpdb->get_var($sql);
    }

    /**
     * Insert one row via $wpdb->insert(), which prepares every value.
     *
     * @param array<string, mixed>  $data   Column => value.
     * @param array<int, string>    $format One placeholder (%s, %d, %f) per column.
     * @return int|false Insert ID, or false on error.
     */
    public static function insert(string $table, array $data, array $format)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned table; $wpdb->insert() prepares the values.
        if ($wpdb->insert($table, $data, $format) === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Update rows via $wpdb->update(), which prepares every value.
     *
     * @param array<string, mixed> $data  Column => new value.
     * @param array<string, mixed> $where Column => value.
     * @return int|false Rows updated, or false on error.
     */
    public static function update(string $table, array $data, array $where)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers clear caches themselves; $wpdb->update() prepares the values.
        return $wpdb->update($table, $data, $where);
    }

    /**
     * Prepare a SQL query with placeholders via $wpdb->prepare().
     *
     * @param mixed ...$args
     */
    public static function prepare(string $query, ...$args): string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This method IS the prepare wrapper; callers pass the format string and values.
        return $wpdb->prepare($query, ...$args);
    }

    /**
     * Turn $wpdb error output on or off; returns the previous setting.
     * Keeps database errors out of REST responses when WP_DEBUG displays them.
     */
    public static function suppressErrors(bool $suppress = true): bool
    {
        global $wpdb;

        return (bool) $wpdb->suppress_errors($suppress);
    }

    /**
     * Error text of the last query ($wpdb->last_error), empty when it succeeded.
     */
    public static function lastError(): string
    {
        global $wpdb;

        return (string) $wpdb->last_error;
    }

    /**
     * Return the WordPress table prefix ($wpdb->prefix).
     */
    public static function wpPrefix(): string
    {
        global $wpdb;

        return $wpdb->prefix;
    }

    /**
     * Get a single row as an object or array.
     *
     * @param array<int, mixed> $args Values for the placeholders in $sql.
     * @return mixed
     */
    public static function getRow(string $sql, array $args = [], string $output = OBJECT)
    {
        global $wpdb;

        $sql = self::prepareArgs($sql, $args);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepared via $wpdb->prepare() above or is a static query; all callers audited.
        return $wpdb->get_row($sql, $output);
    }

    /**
     * $sql with $args bound to its placeholders, or $sql as is when there are none.
     *
     * @param array<int, mixed> $args
     */
    private static function prepareArgs(string $sql, array $args): string
    {
        return $args === [] ? $sql : self::prepare($sql, ...$args);
    }
}
