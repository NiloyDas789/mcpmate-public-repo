<?php

namespace McpMate\Services\AuditLog;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Database\Connection as DB;
use McpMate\Services\Connection\Grants;

/**
 * Reads the audit log, one page at a time: newest first, or by duration.
 */
final class AuditLogQuery
{
    public const MAX_PER_PAGE = 100;

    /** Sortable columns (`order_by`); ties keep the newest row first. */
    public const ORDER_BY = ['created_at', 'duration_ms'];

    /**
     * @param array{id?: int, tool?: string, mcp_tool?: string, kind?: string, result?: string, grant_id?: string, user_id?: int, search?: string, from?: string, to?: string} $filters
     *        `from` / `to` are inclusive UTC bounds in `Y-m-d H:i:s`; `search` matches part of
     *        the tool, MCP tool, action, target or message.
     * @param string $orderBy One of ORDER_BY; anything else sorts by date.
     * @param string $order   `asc` or `desc`.
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function paginate(array $filters, int $page, int $perPage, string $orderBy = 'created_at', string $order = 'desc'): array
    {
        $table   = Logger::table();
        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $dir     = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        // Both parts come from fixed strings, never from the request.
        $sort = $orderBy === 'duration_ms' ? "duration_ms {$dir}, id DESC" : "id {$dir}";

        [$where, $values] = self::where($filters);

        $countSql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        $total    = (int) DB::getVar($countSql, $values);

        $rows = DB::getResults(
            "SELECT * FROM `{$table}` WHERE {$where} ORDER BY {$sort} LIMIT %d OFFSET %d",
            array_merge($values, [$perPage, ($page - 1) * $perPage]),
            ARRAY_A
        );

        return [
            'items' => self::format($rows),
            'total' => $total,
        ];
    }

    /**
     * The `connection` / `first_call` row of $grantId (RestController writes
     * at most one per grant), for the setup wizard's last step.
     *
     * @return array{method: string, route: string, result: string, message: string, at: string, audit_id: int}|null
     */
    public static function firstCall(string $grantId): ?array
    {
        $table = Logger::table();
        $row   = DB::getRow(
            "SELECT * FROM `{$table}` WHERE tool = %s AND action = %s AND target = %s ORDER BY id ASC LIMIT 1",
            ['connection', 'first_call', 'grant:' . $grantId],
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        $args = json_decode((string) ($row['args_json'] ?? ''), true);

        return [
            'method'   => is_array($args) ? (string) ($args['method'] ?? '') : '',
            'route'    => is_array($args) ? (string) ($args['route'] ?? '') : '',
            'result'   => (string) $row['result'],
            'message'  => (string) $row['message'],
            'at'       => str_replace(' ', 'T', (string) $row['created_at']) . 'Z',
            'audit_id' => (int) $row['id'],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function where(array $filters): array
    {
        $clauses = ['1=1'];
        $values  = [];

        $columns = [
            'id'       => 'id = %d',
            'tool'     => 'tool = %s',
            'mcp_tool' => 'mcp_tool = %s',
            'kind'     => 'kind = %s',
            'result'   => 'result = %s',
            'grant_id' => 'grant_id = %s',
            'user_id'  => 'user_id = %d',
            'from'     => 'created_at >= %s',
            'to'       => 'created_at <= %s',
        ];

        foreach ($columns as $filter => $clause) {
            if (isset($filters[$filter]) && $filters[$filter] !== '' && $filters[$filter] !== 0) {
                $clauses[] = $clause;
                $values[]  = $filters[$filter];
            }
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            global $wpdb;

            $like      = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
            $clauses[] = '(tool LIKE %s OR mcp_tool LIKE %s OR action LIKE %s OR target LIKE %s OR message LIKE %s)';
            array_push($values, $like, $like, $like, $like, $like);
        }

        return [implode(' AND ', $clauses), $values];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function format(array $rows): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'user_id')))));

        if ($userIds !== []) {
            cache_users($userIds);
        }

        $clients = [];

        return array_map(static function (array $row) use (&$clients): array {
            $userId  = (int) $row['user_id'];
            $user    = $userId > 0 ? get_userdata($userId) : false;
            $args    = isset($row['args_json']) ? json_decode((string) $row['args_json'], true) : null;
            $grantId = (string) ($row['grant_id'] ?? '');

            if ($grantId !== '' && !array_key_exists($grantId, $clients)) {
                $grant             = Grants::find($grantId);
                $clients[$grantId] = $grant !== null ? (string) $grant['client_name'] : null;
            }

            return [
                'id'          => (int) $row['id'],
                'created_at'  => str_replace(' ', 'T', (string) $row['created_at']) . 'Z',
                'user_id'     => $userId,
                'user_login'  => $user ? $user->user_login : null,
                'request_id'  => (string) $row['request_id'],
                'tool'        => (string) $row['tool'],
                'action'      => (string) $row['action'],
                'target'      => (string) $row['target'],
                'args'        => $args,
                'result'      => (string) $row['result'],
                'message'     => (string) $row['message'],
                'grant_id'    => $grantId,
                // The AI app's name; null for a grant that is gone (or no grant).
                'client_name' => $grantId !== '' ? $clients[$grantId] : null,
                'auth'        => (string) ($row['auth'] ?? ''),
                'kind'        => (string) ($row['kind'] ?? ''),
                'mcp_tool'    => (string) ($row['mcp_tool'] ?? ''),
                'duration_ms' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            ];
        }, $rows);
    }
}
