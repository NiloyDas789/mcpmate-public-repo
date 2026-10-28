<?php

namespace McpMate\Services\DbInspect;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What database inspection never returns.
 *
 * - Protected columns (password hashes, activation keys, session data, API
 *   keys, in any table) are never selected, filtered or sorted on, so their
 *   values cannot leak through results or through which rows match.
 * - In key/value tables (options, every *meta table) the value of a row
 *   whose key looks secret is replaced with REDACTED. When a query filters
 *   or sorts on the value column, those rows are left out entirely.
 *
 * Matching is by case-insensitive substring, in PHP and in SQL alike, so
 * both sides agree on which rows are secret. Masking too much is the
 * intended failure mode.
 */
final class Sensitive
{
    public const REDACTED = '[redacted]';

    /** A column whose name contains one of these is protected. */
    public const PROTECTED_COLUMN_PARTS = [
        'pass', 'secret', 'token', 'activation_key', 'salt', 'hash', 'api_key', 'apikey', 'private_key',
        'consumer_key', 'credential', 'session',
    ];

    /**
     * A key/value row whose key contains one of these has its value masked.
     * Letters only: they are used as LIKE patterns without escaping.
     */
    public const SENSITIVE_KEY_PARTS = ['pass', 'secret', 'token', 'key', 'salt', 'nonce', 'session', 'auth', 'credential', 'licen'];

    /** Key column => value column, for tables that store key/value rows. */
    public const KEY_VALUE_COLUMNS = [
        'option_name' => 'option_value',
        'meta_key'    => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Column name in a lookup table, not a WP_Query argument.
    ];

    public static function isProtectedColumn(string $column): bool
    {
        return self::containsAny($column, self::PROTECTED_COLUMN_PARTS);
    }

    /**
     * @param mixed $key
     */
    public static function isSensitiveKey($key): bool
    {
        return is_string($key) && self::containsAny($key, self::SENSITIVE_KEY_PARTS);
    }

    /**
     * The key and value columns of a key/value table, or null.
     *
     * @param string[] $columns All columns of the table.
     * @return array{key: string, value: string}|null
     */
    public static function keyValuePair(array $columns): ?array
    {
        foreach (self::KEY_VALUE_COLUMNS as $key => $value) {
            if (in_array($key, $columns, true) && in_array($value, $columns, true)) {
                return ['key' => $key, 'value' => $value];
            }
        }

        return null;
    }

    /**
     * SQL condition that keeps only rows whose $keyColumn is not secret,
     * with one `%s` placeholder per SENSITIVE_KEY_PARTS entry.
     *
     * @param string $keyColumn Validated identifier.
     * @return array{sql: string, args: string[]}
     */
    public static function excludeSensitiveKeysSql(string $keyColumn): array
    {
        $sql  = ["`{$keyColumn}` IS NOT NULL"];
        $args = [];

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            $sql[]  = "LOWER(`{$keyColumn}`) NOT LIKE %s";
            $args[] = '%' . $part . '%';
        }

        return ['sql' => '(' . implode(' AND ', $sql) . ')', 'args' => $args];
    }

    /**
     * @param string[] $parts
     */
    private static function containsAny(string $value, array $parts): bool
    {
        foreach ($parts as $part) {
            if (stripos($value, $part) !== false) {
                return true;
            }
        }

        return false;
    }
}
