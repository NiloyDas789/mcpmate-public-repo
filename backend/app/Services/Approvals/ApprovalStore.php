<?php

namespace McpMate\Services\Approvals;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Hooks\Hooks;
use McpMate\Services\AuditLog\Retention;

/**
 * Rows of `{prefix}mcpmate_approvals`.
 *
 * Every status change is one conditional UPDATE, so when two requests race
 * (two clicks on Approve, Approve against Reject) exactly one of them wins:
 *
 *   pending ──decide()──▶ approved ──finish()──▶ executed
 *           ──decide()──▶ rejected
 *           ──expireStale()──▶ expired   (or past expires_at when read)
 *
 * Nothing moves a row out of approved/rejected/expired/executed, so an
 * approval runs at most once.
 */
final class ApprovalStore
{
    public const TABLE = 'approvals';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_EXECUTED = 'executed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_EXECUTED,
    ];

    public const MAX_PER_PAGE = 100;

    /** Rows are deleted this many days after they were created (the audit log keeps the record). */
    public const DEFAULT_RETENTION_DAYS = 30;

    public static function table(): string
    {
        return Config::withDBPrefix(self::TABLE);
    }

    /**
     * Expire and purge on the audit log's daily cron event.
     */
    public static function register(): void
    {
        Hooks::addAction(Retention::HOOK, [self::class, 'purge']);
    }

    /**
     * Current UTC time in the table's datetime format.
     */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    // ──────────────────────────────────────────────
    //  Reads
    // ──────────────────────────────────────────────

    /**
     * Requests still waiting for a site admin: pending and not yet expired
     * (read-only, unlike `paginate()`, which marks stale rows first).
     */
    public static function countPending(): int
    {
        $table = self::table();

        return (int) DB::getVar(
            "SELECT COUNT(*) FROM `{$table}` WHERE status = %s AND expires_at > %s",
            [self::STATUS_PENDING, self::now()]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $table = self::table();
        $row   = DB::getRow("SELECT * FROM `{$table}` WHERE id = %d", [$id], ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * One page, newest first. Pending rows past their expiry are marked
     * expired first, so a `pending` filter only shows live requests.
     *
     * @param string $status One of STATUSES, or '' for all.
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function paginate(string $status, int $page, int $perPage): array
    {
        self::expireStale();

        $table   = self::table();
        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset  = ($page - 1) * $perPage;

        if (in_array($status, self::STATUSES, true)) {
            $total = (int) DB::getVar("SELECT COUNT(*) FROM `{$table}` WHERE status = %s", [$status]);
            $rows  = DB::getResults(
                "SELECT * FROM `{$table}` WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
                [$status, $perPage, $offset],
                ARRAY_A
            );
        } else {
            $total = (int) DB::getVar("SELECT COUNT(*) FROM `{$table}`");
            $rows  = DB::getResults(
                "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d",
                [$perPage, $offset],
                ARRAY_A
            );
        }

        return ['rows' => $rows, 'total' => $total];
    }

    // ──────────────────────────────────────────────
    //  Writes
    // ──────────────────────────────────────────────

    /**
     * @param array{token_hash: string, created_by: int, grant_id?: string, request_id: string, tool: string, action: string, target: string,
     *              args_json: string, preview_json: string, expires_at: string} $row
     * @return int|false The new ID, or false on a database error.
     */
    public static function insert(array $row)
    {
        return DB::insert(
            self::table(),
            [
                'token_hash'   => $row['token_hash'],
                'created_at'   => self::now(),
                'created_by'   => $row['created_by'],
                'grant_id'     => $row['grant_id'] ?? '',
                'request_id'   => $row['request_id'],
                'tool'         => $row['tool'],
                'action'       => $row['action'],
                'target'       => $row['target'],
                'args_json'    => $row['args_json'],
                'preview_json' => $row['preview_json'],
                'status'       => self::STATUS_PENDING,
                'expires_at'   => $row['expires_at'],
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Move a pending, unexpired row to approved or rejected.
     *
     * @return bool True only for the one caller whose UPDATE changed the row.
     */
    public static function decide(int $id, string $status, int $userId): bool
    {
        if ($status !== self::STATUS_APPROVED && $status !== self::STATUS_REJECTED) {
            return false;
        }

        $table = self::table();
        $now   = self::now();

        $changed = DB::query(
            "UPDATE `{$table}` SET status = %s, decided_by = %d, decided_at = %s WHERE id = %d AND status = %s AND expires_at > %s",
            [$status, $userId, $now, $id, self::STATUS_PENDING, $now]
        );

        return $changed === 1;
    }

    /**
     * Store the outcome of an approved row and mark it executed.
     *
     * @param array<string, mixed> $result
     */
    public static function finish(int $id, array $result): bool
    {
        $table = self::table();
        $json  = wp_json_encode($result);

        $changed = DB::query(
            "UPDATE `{$table}` SET status = %s, executed_at = %s, result_json = %s WHERE id = %d AND status = %s",
            [self::STATUS_EXECUTED, self::now(), $json === false ? '{}' : $json, $id, self::STATUS_APPROVED]
        );

        return $changed === 1;
    }

    /**
     * Mark pending rows past their expiry as expired.
     *
     * @return int Rows changed.
     */
    public static function expireStale(): int
    {
        $table   = self::table();
        $changed = DB::query(
            "UPDATE `{$table}` SET status = %s WHERE status = %s AND expires_at <= %s",
            [self::STATUS_EXPIRED, self::STATUS_PENDING, self::now()]
        );

        return is_int($changed) ? $changed : 0;
    }

    /**
     * Daily cron: expire stale rows, then delete rows older than the
     * retention window (filter `MCPMATE_approvals_retention_days`;
     * below 1 keeps every row).
     *
     * @return int Rows deleted.
     */
    public static function purge(): int
    {
        self::expireStale();

        $days = (int) Hooks::applyFilter(Config::withPrefix('approvals_retention_days'), self::DEFAULT_RETENTION_DAYS);
        if ($days < 1) {
            return 0;
        }

        $table   = self::table();
        $deleted = DB::query(
            "DELETE FROM `{$table}` WHERE created_at < %s",
            [gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)]
        );

        return is_int($deleted) ? $deleted : 0;
    }
}
