<?php

namespace McpMate\Services\DbInspect;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Database\Connection as DB;

/**
 * Reads rows of one allowed table from a structured request, never from SQL
 * text:
 *
 *   columns  names to return (default: every column that is not protected)
 *   where    up to MAX_FILTERS `{column, op, value|values}` filters, joined with AND
 *   order_by one column; order asc|desc (default: the primary key, ascending)
 *   limit    1..MAX_LIMIT (default DEFAULT_LIMIT); offset 0..MAX_OFFSET
 *
 * build() is pure. It accepts only column names read from SHOW COLUMNS and
 * operators from OPERATORS, and every value becomes a placeholder. Sensitive
 * rules (see Sensitive) are applied while building, then present() masks
 * secret key/value rows and clips long or binary cells.
 *
 * The SELECT carries a MAX_EXECUTION_TIME hint (MySQL) or runs under
 * SET STATEMENT max_statement_time (MariaDB), so a slow scan is cut off.
 */
final class RowQuery
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 1000;

    public const MAX_OFFSET = 1000000;

    public const MAX_FILTERS = 10;

    public const MAX_IN_VALUES = 100;

    public const MAX_VALUE_LENGTH = 1000;

    /** Longest cell returned, in characters; longer strings are cut. */
    public const MAX_CELL_LENGTH = 2000;

    public const TIMEOUT_MS = 5000;

    /** Request operator => SQL. */
    public const OPERATORS = [
        'eq'       => '=',
        'ne'       => '!=',
        'lt'       => '<',
        'lte'      => '<=',
        'gt'       => '>',
        'gte'      => '>=',
        'like'     => 'LIKE',
        'not_like' => 'NOT LIKE',
        'in'       => 'IN',
        'is_null'  => 'IS NULL',
        'not_null' => 'IS NOT NULL',
    ];

    /** Alias of the key column, selected for masking when it was not asked for. */
    private const MASK_KEY_ALIAS = 'mcpmate_mask_key';

    /**
     * Read rows of $table, an allowed table (see Tables::find()).
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     * @throws DbInspectException
     */
    public static function run(string $table, array $spec): array
    {
        global $wpdb;

        $columns = Tables::columns($table);
        $primary = array_column(array_filter($columns, static fn (array $c) => $c['key'] === 'PRI'), 'name');
        $isMaria = stripos((string) $wpdb->db_server_info(), 'mariadb') !== false;
        $query   = self::build($table, array_column($columns, 'name'), $primary, $spec, $isMaria);

        $suppressed = DB::suppressErrors(true);
        $rows       = DB::getResults($query['sql'], $query['args'], ARRAY_A);
        $error      = DB::lastError();
        DB::suppressErrors($suppressed);

        if ($error !== '') {
            throw self::queryFailed(esc_html($error));
        }

        return ['table' => $table] + self::present($rows, $query);
    }

    /**
     * Build the SELECT for $spec.
     *
     * @param string[]             $tableColumns All columns of $table (from SHOW COLUMNS).
     * @param string[]             $primaryKey   Primary key columns, in order.
     * @param array<string, mixed> $spec         Validated REST params.
     * @return array{sql: string, args: array<int, int|string>, columns: string[], omitted: string[], mask: array{key: string, value: string, alias: string}|null, limit: int, offset: int}
     * @throws DbInspectException
     */
    public static function build(string $table, array $tableColumns, array $primaryKey, array $spec, bool $mariadb = false): array
    {
        self::assertIdentifier($table);
        array_map([self::class, 'assertIdentifier'], $tableColumns);

        [$columns, $omitted] = self::selectColumns($tableColumns, $spec['columns'] ?? []);

        $pair         = Sensitive::keyValuePair($tableColumns);
        $where        = [];
        $args         = [];
        $touchesValue = false;

        $filters = $spec['where'] ?? [];
        if (!is_array($filters) || count($filters) > self::MAX_FILTERS) {
            throw self::reject(
                /* translators: %d: maximum number of filters. */
                sprintf(esc_html__('Use at most %d filters.', 'mcpmate'), (int) self::MAX_FILTERS)
            );
        }

        foreach (array_values($filters) as $i => $filter) {
            [$sql, $filterArgs, $column] = self::filter($filter, $i, $tableColumns);
            $where[]      = $sql;
            $args         = array_merge($args, $filterArgs);
            $touchesValue = $touchesValue || ($pair !== null && $column === $pair['value']);
        }

        $orderBy = isset($spec['order_by']) && $spec['order_by'] !== ''
            ? [(string) $spec['order_by']]
            : array_values(array_filter($primaryKey, static fn (string $c) => !Sensitive::isProtectedColumn($c)));
        foreach ($orderBy as $column) {
            self::assertReadable($column, $tableColumns, 'order_by');
            $touchesValue = $touchesValue || ($pair !== null && $column === $pair['value']);
        }
        $direction = strtolower((string) ($spec['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // Filtering or sorting on a value column would reveal secret rows by
        // which rows match, so those rows are left out.
        if ($touchesValue) {
            $exclude = Sensitive::excludeSensitiveKeysSql($pair['key']);
            $where[] = $exclude['sql'];
            $args    = array_merge($args, $exclude['args']);
        }

        $select = array_map(static fn (string $c) => "`{$c}`", $columns);
        $mask   = null;
        if ($pair !== null && in_array($pair['value'], $columns, true)) {
            $mask = $pair + ['alias' => in_array($pair['key'], $columns, true) ? $pair['key'] : self::MASK_KEY_ALIAS];
            if ($mask['alias'] === self::MASK_KEY_ALIAS) {
                $select[] = "`{$pair['key']}` AS `" . self::MASK_KEY_ALIAS . '`';
            }
        }

        $limit  = max(1, min(self::MAX_LIMIT, (int) ($spec['limit'] ?? self::DEFAULT_LIMIT)));
        $offset = max(0, min(self::MAX_OFFSET, (int) ($spec['offset'] ?? 0)));

        $sql = $mariadb
            ? 'SET STATEMENT max_statement_time=' . (self::TIMEOUT_MS / 1000) . ' FOR SELECT '
            : 'SELECT /*+ MAX_EXECUTION_TIME(' . self::TIMEOUT_MS . ') */ ';
        $sql .= implode(', ', $select) . " FROM `{$table}`";

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        if ($orderBy) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(static fn (string $c) => "`{$c}` {$direction}", $orderBy));
        }

        // One extra row tells present() whether there is a next page.
        $sql   .= ' LIMIT %d OFFSET %d';
        $args[] = $limit + 1;
        $args[] = $offset;

        return [
            'sql'     => $sql,
            'args'    => $args,
            'columns' => $columns,
            'omitted' => $omitted,
            'mask'    => $mask,
            'limit'   => $limit,
            'offset'  => $offset,
        ];
    }

    /**
     * Shape the rows of a built query: mask secret key/value rows, clip long
     * or binary cells, and work out the next page.
     *
     * @param array<int, array<string, mixed>> $rows  Rows from the query (limit + 1 at most).
     * @param array<string, mixed>             $query Result of build().
     * @return array<string, mixed>
     */
    public static function present(array $rows, array $query): array
    {
        $hasMore = count($rows) > $query['limit'];
        $rows    = array_slice($rows, 0, $query['limit']);
        $mask    = $query['mask'];
        $masked  = 0;
        $clipped = 0;

        foreach ($rows as $i => $row) {
            if ($mask !== null && Sensitive::isSensitiveKey($row[$mask['alias']] ?? null)) {
                $row[$mask['value']] = Sensitive::REDACTED;
                $masked++;
            }

            unset($row[self::MASK_KEY_ALIAS]);

            foreach ($row as $column => $value) {
                if (!is_string($value)) {
                    continue;
                }

                if (!mb_check_encoding($value, 'UTF-8')) {
                    $row[$column] = sprintf('[binary: %d bytes]', strlen($value));
                    $clipped++;
                } elseif (mb_strlen($value, 'UTF-8') > self::MAX_CELL_LENGTH) {
                    $row[$column] = mb_substr($value, 0, self::MAX_CELL_LENGTH, 'UTF-8') . '…';
                    $clipped++;
                }
            }

            $rows[$i] = $row;
        }

        return [
            'columns'           => $query['columns'],
            'rows'              => $rows,
            'count'             => count($rows),
            'limit'             => $query['limit'],
            'offset'            => $query['offset'],
            'has_more'          => $hasMore,
            'next_offset'       => $hasMore ? $query['offset'] + $query['limit'] : null,
            'protected_columns' => $query['omitted'],
            'masked_rows'       => $masked,
            'clipped_cells'     => $clipped,
        ];
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * Columns to select, and the protected columns left out of a default select.
     *
     * @param string[] $tableColumns
     * @param mixed    $requested
     * @return array{0: string[], 1: string[]}
     */
    private static function selectColumns(array $tableColumns, $requested): array
    {
        $protected = array_values(array_filter($tableColumns, [Sensitive::class, 'isProtectedColumn']));

        if (!is_array($requested) || $requested === []) {
            return [array_values(array_diff($tableColumns, $protected)), $protected];
        }

        $columns = [];
        foreach ($requested as $column) {
            $column = is_string($column) ? $column : '';
            self::assertReadable($column, $tableColumns, 'columns');
            $columns[] = $column;
        }

        return [array_values(array_unique($columns)), []];
    }

    /**
     * SQL for one filter.
     *
     * @param mixed    $filter
     * @param string[] $tableColumns
     * @return array{0: string, 1: array<int, string>, 2: string}
     */
    private static function filter($filter, int $index, array $tableColumns): array
    {
        if (!is_array($filter)) {
            throw self::reject(
                /* translators: %d: filter position, starting at 0. */
                sprintf(esc_html__('Filter %d must be an object with column, op and value.', 'mcpmate'), (int) $index)
            );
        }

        $column = isset($filter['column']) && is_string($filter['column']) ? $filter['column'] : '';
        self::assertReadable($column, $tableColumns, 'where');

        $op = isset($filter['op']) ? (string) $filter['op'] : 'eq';
        if (!isset(self::OPERATORS[$op])) {
            throw self::reject(
                /* translators: %s: operator name. */
                sprintf(esc_html__('Unknown operator "%s".', 'mcpmate'), esc_html(sanitize_key($op))),
                esc_html(DbInspectException::INVALID),
                400,
                /* translators: %s: comma-separated operator names. */
                sprintf(esc_html__('Use one of: %s.', 'mcpmate'), esc_html(implode(', ', array_keys(self::OPERATORS))))
            );
        }

        $sql = self::OPERATORS[$op];

        if ($op === 'is_null' || $op === 'not_null') {
            return ["`{$column}` {$sql}", [], $column];
        }

        if ($op === 'in') {
            $values = $filter['values'] ?? null;
            if (!is_array($values) || $values === [] || count($values) > self::MAX_IN_VALUES) {
                throw self::reject(
                    /* translators: %d: maximum number of values. */
                    sprintf(esc_html__('"in" needs a values list of 1 to %d items.', 'mcpmate'), (int) self::MAX_IN_VALUES)
                );
            }

            $values = array_map([self::class, 'value'], array_values($values));

            return ["`{$column}` IN (" . implode(', ', array_fill(0, count($values), '%s')) . ')', $values, $column];
        }

        if (!array_key_exists('value', $filter)) {
            throw self::reject(
                /* translators: %s: operator name. */
                sprintf(esc_html__('"%s" needs a value.', 'mcpmate'), esc_html($op))
            );
        }

        return ["`{$column}` {$sql} %s", [self::value($filter['value'])], $column];
    }

    /**
     * @param mixed $value
     */
    private static function value($value): string
    {
        if (!is_scalar($value) || mb_strlen((string) $value, 'UTF-8') > self::MAX_VALUE_LENGTH) {
            throw self::reject(
                /* translators: %d: maximum length in characters. */
                sprintf(esc_html__('Filter values must be strings or numbers of at most %d characters.', 'mcpmate'), (int) self::MAX_VALUE_LENGTH)
            );
        }

        return (string) $value;
    }

    /**
     * $column must exist in the table and not be protected.
     *
     * @param string[] $tableColumns
     */
    private static function assertReadable(string $column, array $tableColumns, string $param): void
    {
        if (!in_array($column, $tableColumns, true)) {
            throw self::reject(
                /* translators: 1: request parameter, 2: column name. */
                sprintf(esc_html__('%1$s: unknown column "%2$s".', 'mcpmate'), esc_html($param), esc_html(preg_replace('/[^A-Za-z0-9_$]/', '', $column))),
                esc_html(DbInspectException::UNKNOWN_COLUMN),
                400,
                esc_html__('GET /db/tables/{table}/schema lists the columns.', 'mcpmate')
            );
        }

        if (Sensitive::isProtectedColumn($column)) {
            throw self::reject(
                /* translators: %s: column name. */
                sprintf(esc_html__('Column "%s" is protected and cannot be read, filtered or sorted on.', 'mcpmate'), esc_html($column)),
                esc_html(DbInspectException::PROTECTED_COLUMN),
                400,
                esc_html__('Leave it out of columns, where and order_by.', 'mcpmate')
            );
        }
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match(Tables::IDENTIFIER, $name)) {
            throw self::reject(esc_html__('The table has a name that cannot be read safely.', 'mcpmate'), esc_html(DbInspectException::FAILED), 500);
        }
    }

    /**
     * The exception for a rejected request; DbController returns it as JSON.
     */
    private static function reject(string $message, string $code = DbInspectException::INVALID, int $status = 400, string $hint = ''): DbInspectException
    {
        return new DbInspectException(esc_html($message), esc_html($code), (int) $status, esc_html($hint));
    }

    private static function queryFailed(string $error): DbInspectException
    {
        // MySQL: "maximum statement execution time exceeded"; MariaDB: "max_statement_time exceeded".
        if (stripos($error, 'execution time') !== false || stripos($error, 'max_statement_time') !== false) {
            return new DbInspectException(
                esc_html__('The query took too long and was stopped.', 'mcpmate'),
                esc_html(DbInspectException::TIMEOUT),
                504,
                esc_html__('Filter on an indexed column, select fewer columns or lower the limit.', 'mcpmate')
            );
        }

        // The database error is not returned: it can echo the query and its values.
        return new DbInspectException(esc_html__('The database query failed.', 'mcpmate'), esc_html(DbInspectException::FAILED), 500);
    }
}
