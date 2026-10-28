<?php

namespace McpMate\Services\ChangeHistory;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Services\Connection\Grants;

/**
 * The change history table (`{prefix}mcpmate_changes`): one row per
 * object a plugin write changed, with its snapshot before and after
 * (ChangeCapture), linked to the audit row (`audit_id`) and the request
 * (`request_id`). Rows are written once and removed only by age
 * (Retention, change_retention_days).
 *
 * Read through admin-ajax only (ChangesController): snapshots can hold
 * personal data and post content, and never reach the MCP surface.
 */
final class ChangeStore
{
    public const TABLE = 'changes';

    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_TRASH = 'trash';

    public const ACTION_DELETE = 'delete';

    public const ACTIONS = [self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_TRASH, self::ACTION_DELETE];

    /** Largest snapshot kept, in bytes of JSON; longer strings in it are cut first. */
    public const MAX_SNAPSHOT_BYTES = 524288;

    public const MAX_PER_PAGE = 100;

    /** Rows deleted per query, so a large backlog does not hold one long lock. */
    private const BATCH = 1000;

    public static function table(): string
    {
        return Config::withDBPrefix(self::TABLE);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array{audit_id?: int, request_id?: string, user_id?: int, grant_id?: string, mcp_tool?: string, tool?: string} $context
     */
    public static function insert(string $type, int $id, string $key, ?array $before, ?array $after, array $context): bool
    {
        [$beforeJson, $beforeCut] = self::encode($before);
        [$afterJson, $afterCut]   = self::encode($after);

        return DB::insert(
            self::table(),
            [
                'created_at'   => gmdate('Y-m-d H:i:s'),
                'audit_id'     => (int) ($context['audit_id'] ?? 0),
                'request_id'   => substr((string) preg_replace('/[^A-Za-z0-9._:-]/', '', (string) ($context['request_id'] ?? '')), 0, 36),
                'user_id'      => (int) ($context['user_id'] ?? get_current_user_id()),
                'grant_id'     => substr((string) ($context['grant_id'] ?? ''), 0, 40),
                'tool'         => substr((string) ($context['tool'] ?? ''), 0, 100),
                'mcp_tool'     => substr((string) ($context['mcp_tool'] ?? ''), 0, 60),
                'action'       => self::action($type, $before, $after),
                'object_type'  => $type,
                'object_id'    => $id,
                'object_key'   => mb_substr($key, 0, 191, 'UTF-8'),
                'object_label' => mb_substr(ChangeSnapshots::label($type, $id, $key, $before, $after), 0, 255, 'UTF-8'),
                'before_json'  => $beforeJson,
                'after_json'   => $afterJson,
                'truncated'    => $beforeCut || $afterCut ? 1 : 0,
                'bytes'        => strlen((string) $beforeJson) + strlen((string) $afterJson),
            ],
            ['%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d']
        ) !== false;
    }

    /**
     * create (no before), delete (no after), trash (a status that became
     * `trash`), else update.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function action(string $type, ?array $before, ?array $after): string
    {
        if ($before === null) {
            return self::ACTION_CREATE;
        }

        if ($after === null) {
            return self::ACTION_DELETE;
        }

        $statusBefore = (string) ($before['status'] ?? '');
        $statusAfter  = (string) ($after['status'] ?? '');

        return $statusAfter === 'trash' && $statusBefore !== 'trash' ? self::ACTION_TRASH : self::ACTION_UPDATE;
    }

    // ──────────────────────────────────────────────
    //  Reads
    // ──────────────────────────────────────────────

    /**
     * One page of rows, newest first, without the snapshots.
     *
     * @param array<string, mixed> $filters object_type, action, mcp_tool, grant_id, request_id, audit_id, user_id,
     *                                      from / to (inclusive, `Y-m-d H:i:s` UTC), search (label or key).
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function paginate(array $filters, int $page, int $perPage): array
    {
        $table   = self::table();
        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        [$where, $values] = self::where($filters);

        $countSql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        $total    = (int) DB::getVar($countSql, $values);

        $rows = DB::getResults(
            "SELECT id, created_at, audit_id, request_id, user_id, grant_id, tool, mcp_tool, action, object_type, object_id, object_key, object_label, truncated, bytes
             FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            array_merge($values, [$perPage, ($page - 1) * $perPage]),
            ARRAY_A
        );

        return ['items' => self::format($rows), 'total' => $total];
    }

    /**
     * One row with both snapshots and the diff between them.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $table = self::table();
        $row   = DB::getRow("SELECT * FROM `{$table}` WHERE id = %d", [$id], ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        $item   = self::format([$row])[0];
        $before = json_decode((string) $row['before_json'], true);
        $after  = json_decode((string) $row['after_json'], true);

        $item['before'] = is_array($before) ? $before : null;
        $item['after']  = is_array($after) ? $after : null;
        $item['diff']   = ChangeDiff::diff($item['before'], $item['after']);

        return $item;
    }

    /**
     * Rows per object type since $since (`Y-m-d H:i:s` UTC).
     *
     * @return array<string, int>
     */
    public static function countsByType(string $since): array
    {
        $table = self::table();
        $rows  = DB::getResults(
            "SELECT object_type, COUNT(*) AS n FROM `{$table}` WHERE created_at >= %s GROUP BY object_type",
            [$since],
            ARRAY_A
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['object_type']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Delete rows older than $days days (Retention's daily cron).
     *
     * @return int Rows deleted.
     */
    public static function purge(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $table   = self::table();
        $cutoff  = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $deleted = 0;

        do {
            $count = DB::query("DELETE FROM `{$table}` WHERE created_at < %s LIMIT %d", [$cutoff, self::BATCH]);

            if (!is_int($count)) {
                break;
            }

            $deleted += $count;
        } while ($count === self::BATCH);

        return $deleted;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * JSON of a snapshot, its longest strings cut until it fits MAX_SNAPSHOT_BYTES.
     *
     * @param array<string, mixed>|null $snapshot
     * @return array{0: string|null, 1: bool} JSON (null for no snapshot), and whether it was cut.
     */
    public static function encode(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [null, false];
        }

        $json = (string) wp_json_encode($snapshot);
        $cut  = false;

        for ($i = 0; strlen($json) > self::MAX_SNAPSHOT_BYTES && $i < 64; $i++) {
            if (!self::halveLongest($snapshot)) {
                break;
            }

            $cut  = true;
            $json = (string) wp_json_encode($snapshot);
        }

        if (strlen($json) > self::MAX_SNAPSHOT_BYTES) {
            // Too many small values to cut down: keep a note instead.
            return [(string) wp_json_encode(['_mcpmate_truncated' => strlen($json)]), true];
        }

        return [$json, $cut];
    }

    /**
     * Cut the longest string in $value (any depth) to half its length.
     *
     * @param array<array-key, mixed> $value
     */
    private static function halveLongest(array &$value): bool
    {
        $path    = [];
        $longest = 0;

        $walk = static function (array $node, array $at) use (&$walk, &$path, &$longest): void {
            foreach ($node as $key => $item) {
                if (is_array($item)) {
                    $walk($item, array_merge($at, [$key]));
                } elseif (is_string($item) && strlen($item) > $longest) {
                    $longest = strlen($item);
                    $path    = array_merge($at, [$key]);
                }
            }
        };
        $walk($value, []);

        if ($longest < 64) {
            return false;
        }

        // phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $ref writes into $value by reference.
        $ref = &$value;
        foreach ($path as $key) {
            $ref = &$ref[$key];
        }
        $ref = mb_strcut((string) $ref, 0, intdiv($longest, 2), 'UTF-8') . '…';
        // phpcs:enable

        return true;
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
            'object_type' => 'object_type = %s',
            'action'      => 'action = %s',
            'mcp_tool'    => 'mcp_tool = %s',
            'grant_id'    => 'grant_id = %s',
            'request_id'  => 'request_id = %s',
            'audit_id'    => 'audit_id = %d',
            'user_id'     => 'user_id = %d',
            'from'        => 'created_at >= %s',
            'to'          => 'created_at <= %s',
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
            $clauses[] = '(object_label LIKE %s OR object_key LIKE %s)';
            $values[]  = $like;
            $values[]  = $like;
        }

        return [implode(' AND ', $clauses), $values];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function format(array $rows): array
    {
        $clients = [];

        return array_map(static function (array $row) use (&$clients): array {
            $userId  = (int) $row['user_id'];
            $user    = $userId > 0 ? get_userdata($userId) : false;
            $grantId = (string) $row['grant_id'];

            if ($grantId !== '' && !array_key_exists($grantId, $clients)) {
                $grant             = Grants::find($grantId);
                $clients[$grantId] = $grant !== null ? (string) $grant['client_name'] : null;
            }

            return [
                'id'           => (int) $row['id'],
                'created_at'   => str_replace(' ', 'T', (string) $row['created_at']) . 'Z',
                'audit_id'     => (int) $row['audit_id'],
                'request_id'   => (string) $row['request_id'],
                'user_id'      => $userId,
                'user_login'   => $user ? $user->user_login : null,
                'grant_id'     => $grantId,
                'client_name'  => $grantId !== '' ? $clients[$grantId] : null,
                'tool'         => (string) $row['tool'],
                'mcp_tool'     => (string) $row['mcp_tool'],
                'action'       => (string) $row['action'],
                'object_type'  => (string) $row['object_type'],
                'object_id'    => (int) $row['object_id'],
                'object_key'   => (string) $row['object_key'],
                'object_label' => (string) $row['object_label'],
                'truncated'    => (bool) (int) $row['truncated'],
                'bytes'        => (int) $row['bytes'],
            ];
        }, $rows);
    }
}
