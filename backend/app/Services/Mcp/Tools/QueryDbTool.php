<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;

/**
 * query_db → GET db/tables, …/{table}/schema, …/{table}/rows (cloud
 * queryDb.ts). Never SQL text (rule 6).
 */
final class QueryDbTool extends Tool
{
    public const MAX_ROW_LIMIT = 100;

    /** Tables listed per call; the rest are reached with offset. */
    public const TABLES_PER_PAGE = 100;

    /** Characters of each cell shown in the text table. */
    private const CELL_CHARS = 120;

    private const TEXT_ROWS_BYTES = 17000;

    private const STRUCTURED_BYTES = 18000;

    public function run(\stdClass $args): ToolResult
    {
        $problem = self::inputProblem($args);
        if ($problem !== null) {
            return ToolResult::invalidInput($problem);
        }

        if ($args->action === 'tables') {
            return self::out(self::formatTables($this->siteId, $this->client->dbTables()['data'], $args->offset));
        }

        if ($args->action === 'schema') {
            return self::out(self::formatSchema($this->siteId, $this->client->dbSchema($args->table)['data']));
        }

        $where = null;
        if (isset($args->where)) {
            $where = array_map(static fn (\stdClass $filter): array => \McpMate\Services\Mcp\InternalClient::defined([
                'column' => $filter->column,
                'op'     => $filter->op,
                'value'  => $filter->value ?? null,
                'values' => $filter->values ?? null,
            ]), $args->where);
        }

        $reply = $this->client->dbRows($args->table, [
            'columns'  => $args->columns ?? null,
            'where'    => $where,
            'order_by' => $args->order_by ?? null,
            'order'    => $args->order ?? null,
            'limit'    => $args->limit,
            'offset'   => $args->offset,
        ]);

        return self::out(self::formatRows($this->siteId, $reply['data']));
    }

    private static function inputProblem(\stdClass $input): ?string
    {
        if ($input->action !== 'tables' && !isset($input->table)) {
            return 'action "' . $input->action . '" needs a table.';
        }

        if ($input->action !== 'rows' && (isset($input->columns) || isset($input->where) || isset($input->order_by) || isset($input->order))) {
            return 'columns, where, order_by and order apply only to action "rows".';
        }

        foreach ($input->where ?? [] as $filter) {
            $has = static fn (string $key): bool => property_exists($filter, $key);

            if ($filter->op === 'in' && !$has('values')) {
                return 'Filter on ' . $filter->column . ': "in" needs values.';
            }

            if ($filter->op !== 'in' && $has('values')) {
                return 'Filter on ' . $filter->column . ': values is only for "in"; use value.';
            }

            if (($filter->op === 'is_null' || $filter->op === 'not_null') && $has('value')) {
                return 'Filter on ' . $filter->column . ': "' . $filter->op . '" takes no value.';
            }

            if (!in_array($filter->op, ['in', 'is_null', 'not_null'], true) && !$has('value')) {
                return 'Filter on ' . $filter->column . ': "' . $filter->op . '" needs a value.';
            }
        }

        return null;
    }

    private static function kb(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? number_format($bytes / 1024 / 1024, 1, '.', '') . ' MB' : (int) ceil($bytes / 1024) . ' KB';
    }

    /**
     * @param array<string, mixed> $data
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function formatTables(string $siteId, array $data, int $offset): array
    {
        $tables     = $data['tables'];
        $page       = array_slice($tables, $offset, self::TABLES_PER_PAGE);
        $nextOffset = $offset + count($page) < count($tables) ? $offset + count($page) : null;
        $structured = ['site_id' => $siteId, 'action' => 'tables', 'tables' => $page, 'total_tables' => count($tables), 'next_offset' => $nextOffset];
        $lines      = array_map(
            static fn (array $t): string => $t['name'] . '  ' . $t['engine'] . '  ~' . $t['rows_estimate'] . ' rows  '
                . self::kb($t['data_bytes']) . ' data  ' . self::kb($t['index_bytes']) . ' index',
            $page
        );
        $header     = $tables === []
            ? 'No tables are readable on this site.'
            : count($tables) . ' readable tables' . (count($tables) > count($page) ? ', showing ' . ($offset + 1) . '–' . ($offset + count($page)) : '') . ' (row counts are estimates):';
        $next       = $nextOffset === null ? 'Describe a table with action "schema" before reading rows from it.' : 'More tables: call again with action "tables" and offset=' . $nextOffset . '.';

        return ['text' => C::finish(array_merge([$header], $page === [] ? [] : [C::siteData('table list', implode("\n", $lines))]), $next), 'structured' => $structured];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function formatSchema(string $siteId, array $data): array
    {
        $structured = ['site_id' => $siteId, 'action' => 'schema', 'table' => $data['table']['name'], 'schema' => C::fit($data, self::STRUCTURED_BYTES), 'next_offset' => null];
        $columns    = array_map(static fn (array $c): string => implode(' ', array_filter([
            '- ' . $c['name'] . ' ' . $c['type'],
            $c['nullable'] ? 'null' : 'not null',
            $c['key'],
            $c['default'] === null ? '' : 'default=' . ($c['default'] === '' ? "''" : C::oneLine($c['default'], 60)),
            $c['extra'],
            $c['protected'] ? '(protected: never returned)' : '',
        ], static fn (string $part): bool => $part !== '')), $data['columns']);
        $indexes    = array_map(static fn (array $i): string => '- ' . $i['name'] . ($i['unique'] ? ' unique' : '') . ' (' . implode(', ', $i['columns']) . ')', $data['indexes']);
        $table      = $data['table'];
        $body       = array_merge(
            [
                $table['name'] . ': ' . $table['engine'] . ', ~' . $table['rows_estimate'] . ' rows, primary key ' . ($data['primary_key'] === [] ? 'none' : implode(', ', $data['primary_key'])),
                'Columns:',
            ],
            $columns,
            $indexes === [] ? [] : array_merge(['Indexes:'], $indexes),
            $data['masking'] === null ? [] : ['Masking: ' . $data['masking']['value_column'] . ' is masked when ' . $data['masking']['key_column'] . ' looks secret.']
        );

        return [
            'text'       => C::finish([C::siteData('table schema', implode("\n", $body))], 'Read rows with action "rows", choosing columns and filters from this schema.'),
            'structured' => $structured,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function formatRows(string $siteId, array $data): array
    {
        $cell = static function ($value): string {
            if ($value === null) {
                return 'NULL';
            }

            return str_replace('|', '\\|', C::oneLine(is_string($value) ? $value : Js::stringify($value), self::CELL_CHARS));
        };

        $rows   = $data['rows'];
        $lines  = [];
        foreach ($rows as $row) {
            $lines[] = implode(' | ', array_map(static fn (string $column): string => $cell(Js::get($row, $column)), $data['columns']));
        }
        $pruned = C::prune($rows, ['items' => self::MAX_ROW_LIMIT, 'keys' => 100, 'chars' => 500, 'depth' => 3]);

        // Text and structured content show the same leading rows, as many as fit in both budgets;
        // next_offset then continues right after the last row shown.
        $shown = count($rows);
        while ($shown > 0 && (strlen(implode("\n", array_slice($lines, 0, $shown))) > self::TEXT_ROWS_BYTES || strlen(Js::stringify(array_slice($pruned, 0, $shown))) > self::STRUCTURED_BYTES)) {
            $shown = (int) floor($shown * 0.8);
        }
        $nextOffset = $shown < count($rows) ? $data['offset'] + $shown : $data['next_offset'];

        $structured = [
            'site_id'           => $siteId,
            'action'            => 'rows',
            'table'             => $data['table'],
            'columns'           => $data['columns'],
            'rows'              => array_slice($pruned, 0, $shown),
            'protected_columns' => $data['protected_columns'],
            'masked_rows'       => $data['masked_rows'],
            'clipped_cells'     => $data['clipped_cells'],
            'next_offset'       => $nextOffset,
        ];

        $range = $shown === 0
            ? 'no rows'
            : 'rows ' . ($data['offset'] + 1) . '–' . ($data['offset'] + $shown) . ($nextOffset === null ? '' : ' (more available)')
                . ($shown < count($rows) ? '; ' . (count($rows) - $shown) . ' more were fetched but left out to stay under 20 KB' : '');
        $notes = array_filter([
            $data['protected_columns'] !== [] ? 'Protected columns left out: ' . implode(', ', $data['protected_columns']) . '.' : '',
            $data['masked_rows'] > 0 ? C::plural($data['masked_rows'], 'row') . ' with masked secret values.' : '',
            $shown > 0 ? 'Cells are cut to ' . self::CELL_CHARS . ' characters here.' : '',
        ], static fn (string $note): bool => $note !== '');
        $header = $data['table'] . ': ' . $range . '.' . ($notes === [] ? '' : ' ' . implode(' ', $notes));
        $table  = implode("\n", array_merge([implode(' | ', $data['columns'])], array_slice($lines, 0, $shown)));
        $next   = $nextOffset === null ? null : 'More rows: call again with the same arguments and offset=' . $nextOffset . '.';

        return [
            'text'       => C::finish(array_merge([$header], $shown === 0 ? [] : [C::siteData('table rows', $table)]), $next, 'Ask for fewer columns or a smaller limit.'),
            'structured' => $structured,
        ];
    }
}
