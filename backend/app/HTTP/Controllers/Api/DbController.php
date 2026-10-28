<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\ErrorPresenter;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\DbInspect\DbInspectException;
use McpMate\Services\DbInspect\DbInspectSettings;
use McpMate\Services\DbInspect\RowQuery;
use McpMate\Services\DbInspect\Sensitive;
use McpMate\Services\DbInspect\Tables;

/**
 * Read-only database inspection, without SQL from the client:
 *
 *   GET /db/tables                     allowed tables with size estimates
 *   GET /db/tables/{table}/schema      columns (protected ones marked) and indexes
 *   GET /db/tables/{table}/rows?…      rows, see RowQuery for the parameters
 *
 * Order of checks: manage_options (route) → a site admin enabled database
 * inspection (DbInspectSettings) → 403; the table is allowed (Tables) →
 * 404; columns, filters and sort pass RowQuery → 400. Only then is the
 * prepared SELECT run.
 *
 * Every call is audited, reads included (tool `db`, target `table:<name>`).
 */
final class DbController extends RestController
{
    protected const TOOL = 'db';

    protected const AUDIT_READS = true;

    public function tables(Request $request): Response
    {
        $disabled = $this->ensureEnabled();
        if ($disabled) {
            return $disabled;
        }

        return $this->ok(['tables' => array_values(Tables::allowed())]);
    }

    public function schema(Request $request, string $table): Response
    {
        $this->auditTarget('table', $table);

        $denied = $this->ensureEnabled();
        if ($denied) {
            return $denied;
        }

        $status = Tables::find($table);
        if ($status === null) {
            return $this->tableNotFound();
        }

        try {
            $columns = Tables::columns($table);
            $indexes = Tables::indexes($table);
        } catch (DbInspectException $e) {
            return $this->fromException($e);
        }

        $pair = Sensitive::keyValuePair(array_column($columns, 'name'));

        return $this->ok([
            'table'       => $status,
            'primary_key' => array_column(array_filter($columns, static fn (array $c) => $c['key'] === 'PRI'), 'name'),
            'columns'     => $columns,
            'indexes'     => $indexes,
            'masking'     => $pair === null ? null : [
                'key_column'   => $pair['key'],
                'value_column' => $pair['value'],
                'key_contains' => Sensitive::SENSITIVE_KEY_PARTS,
            ],
        ]);
    }

    public function rows(Request $request, string $table): Response
    {
        $this->auditTarget('table', $table);

        $denied = $this->ensureEnabled();
        if ($denied) {
            return $denied;
        }

        if (Tables::find($table) === null) {
            return $this->tableNotFound();
        }

        try {
            return $this->ok(RowQuery::run($table, [
                'columns'  => $request->get('columns', []),
                'where'    => $request->get('where', []),
                'order_by' => $request->get('order_by', ''),
                'order'    => $request->get('order', 'asc'),
                'limit'    => $request->get('limit', RowQuery::DEFAULT_LIMIT),
                'offset'   => $request->get('offset', 0),
            ]));
        } catch (DbInspectException $e) {
            return $this->fromException($e);
        }
    }

    // ──────────────────────────────────────────────
    //  REST args
    // ──────────────────────────────────────────────

    /**
     * REST `args` schema for schema().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tableArgs(): array
    {
        return [
            'table' => [
                'type'        => 'string',
                'description' => 'Full table name, as listed by GET /db/tables.',
                'required'    => true,
                'pattern'     => '^[A-Za-z0-9_$]{1,64}$',
            ],
        ];
    }

    /**
     * REST `args` schema for rows().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function rowsArgs(): array
    {
        $identifier = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_$]{1,64}$'];
        $value      = ['type' => 'string', 'maxLength' => RowQuery::MAX_VALUE_LENGTH];

        return self::tableArgs() + [
            'columns'  => [
                'type'        => 'array',
                'description' => 'Columns to return. Default: every column that is not protected.',
                'items'       => $identifier,
                'maxItems'    => 100,
            ],
            'where'    => [
                'type'        => 'array',
                'description' => 'Filters, joined with AND: [{ column, op, value }] or [{ column, op: "in", values }].',
                'maxItems'    => RowQuery::MAX_FILTERS,
                'items'       => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'column' => $identifier + ['required' => true],
                        'op'     => ['type' => 'string', 'enum' => array_keys(RowQuery::OPERATORS), 'default' => 'eq'],
                        'value'  => $value,
                        'values' => ['type' => 'array', 'items' => $value, 'maxItems' => RowQuery::MAX_IN_VALUES],
                    ],
                ],
            ],
            'order_by' => $identifier + ['description' => 'Sort column. Default: the primary key.'],
            'order'    => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
            'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => RowQuery::MAX_LIMIT, 'default' => RowQuery::DEFAULT_LIMIT],
            'offset'   => ['type' => 'integer', 'minimum' => 0, 'maximum' => RowQuery::MAX_OFFSET, 'default' => 0],
        ];
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    private function ensureEnabled(): ?Response
    {
        if (DbInspectSettings::isEnabled()) {
            return null;
        }

        return $this->error(
            __('Database inspection is not enabled on this site.', 'mcpmate'),
            403,
            'mcpmate_db_inspect_disabled',
            __('A site admin can enable it under McpMate → Tools → Core (Database).', 'mcpmate')
        );
    }

    private function tableNotFound(): Response
    {
        return $this->error(
            __('Table not found.', 'mcpmate'),
            404,
            'mcpmate_db_table_not_found',
            __('GET /db/tables lists the tables that can be read.', 'mcpmate')
        );
    }

    private function fromException(DbInspectException $e): Response
    {
        return $this->error(ErrorPresenter::plain($e), $e->status(), $e->errorCode(), ErrorPresenter::text($e->hint()));
    }
}
