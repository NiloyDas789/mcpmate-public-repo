<?php

namespace McpMate\Services\Connection;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Hooks\Hooks;
use McpMate\Services\OAuth\OAuthUrls;
use McpMate\Services\OAuth\Pkce;
use McpMate\Services\OAuth\Tokens;

/**
 * Grants (`{prefix}mcpmate_grants`): one approval of one AI client by
 * one WordPress user on this site. An MCP call's access token names its
 * grant, and the call runs as that grant's user.
 *
 *   pending ──its OAuth code exchanged──▶ active
 *   pending | active ──revoke()──▶ revoked   (kept 30 days as a tombstone for the audit trail)
 *
 * The grant is denied from the moment it is revoked, with every code and
 * token issued from it. Every status change is one conditional UPDATE, and
 * nothing moves a grant out of `revoked`.
 */
final class Grants
{
    public const TABLE = 'grants';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const REASON_ADMIN = 'admin';

    public const REASON_USER_DELETED = 'user_deleted';

    /** A used refresh token came back (docs/mcp-in-plugin.md §4.5). */
    public const REASON_REFRESH_REUSE = 'refresh_reuse';

    /** An authorization code was presented twice (§4.4). */
    public const REASON_CODE_REUSE = 'code_reuse';

    /** A site admin revoked the grant's OAuth client (Registered clients). */
    public const REASON_CLIENT_REVOKED = 'client_revoked';

    /** Access levels (docs/ui.md B2): every tool that is on; reads only; the tools a site admin chose. */
    public const ACCESS_FULL = 'full';

    public const ACCESS_READ_ONLY = 'read_only';

    public const ACCESS_CUSTOM = 'custom';

    public const ACCESSES = [self::ACCESS_FULL, self::ACCESS_READ_ONLY, self::ACCESS_CUSTOM];

    /** Active (and pending) grants allowed per site; filter `MCPMATE_grant_limit`. */
    public const DEFAULT_LIMIT = 25;

    /** A pending grant never used within this many seconds is deleted (the browser was closed before the callback). */
    public const PENDING_TTL = 900;

    /** Revoked grants are deleted this many days after they were revoked. */
    public const TOMBSTONE_DAYS = 30;

    /** last_used_at is written at most once per this many seconds. */
    private const TOUCH_INTERVAL = 60;

    private const LIST_LIMIT = 200;

    public static function table(): string
    {
        return Config::withDBPrefix(self::TABLE);
    }

    public static function limit(): int
    {
        return (int) Hooks::applyFilter(Config::withPrefix('grant_limit'), self::DEFAULT_LIMIT);
    }

    // ──────────────────────────────────────────────
    //  Reads
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $grantId): ?array
    {
        if (!self::isGrantId($grantId)) {
            return null;
        }

        $table = self::table();
        $row   = DB::getRow("SELECT * FROM `{$table}` WHERE grant_id = %s", [$grantId], ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public static function countLive(): int
    {
        $table = self::table();

        return (int) DB::getVar(
            "SELECT COUNT(*) FROM `{$table}` WHERE status IN (%s, %s)",
            [self::STATUS_PENDING, self::STATUS_ACTIVE]
        );
    }

    /**
     * Every grant on record, tombstones included (the onboarding migration
     * marks setup completed on a site that already had one).
     */
    public static function countAll(): int
    {
        $table = self::table();

        return (int) DB::getVar("SELECT COUNT(*) FROM `{$table}`");
    }

    /**
     * Every grant still on record (live ones first, then tombstones), newest
     * first, formatted for the Connected AI apps page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $table = self::table();
        $rows  = DB::getResults(
            "SELECT * FROM `{$table}` ORDER BY (status = %s) ASC, id DESC LIMIT %d",
            [self::STATUS_REVOKED, self::LIST_LIMIT],
            ARRAY_A
        );

        return array_map([self::class, 'format'], $rows);
    }

    // ──────────────────────────────────────────────
    //  Writes
    // ──────────────────────────────────────────────

    /**
     * A new pending grant for $userId. `client_key` names the client of the
     * plugin's own OAuth server.
     *
     * @param array{id: string, name: string, vouched_by: string, redirect_host: string, client_key?: string} $client
     * @param string[]                                                                                       $scopes
     * @return array<string, mixed>|null The row, or null on a database error.
     */
    public static function create(int $userId, array $client, array $scopes): ?array
    {
        $grantId = 'g_' . Pkce::b64url(random_bytes(16));

        $row = [
            'grant_id'      => $grantId,
            'status'        => self::STATUS_PENDING,
            'wp_user_id'    => $userId,
            'client_id'     => mb_substr($client['id'], 0, 2048, 'UTF-8'),
            'client_name'   => mb_substr($client['name'], 0, 100, 'UTF-8'),
            'vouched_by'    => mb_substr($client['vouched_by'], 0, 255, 'UTF-8'),
            'redirect_host' => mb_substr($client['redirect_host'], 0, 255, 'UTF-8'),
            'scopes'        => implode(' ', $scopes),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ];
        $format = ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s'];

        if (isset($client['client_key']) && $client['client_key'] !== '') {
            $row['client_key'] = $client['client_key'];
            $format[]          = '%s';
        }

        $inserted = DB::insert(self::table(), $row, $format);

        return $inserted === false ? null : self::find($grantId);
    }

    /**
     * An MCP call used $grantId: pending becomes active, and
     * last_used_at moves at most once a minute.
     */
    public static function markUsed(string $grantId): void
    {
        $table = self::table();
        $now   = gmdate('Y-m-d H:i:s');

        DB::query(
            "UPDATE `{$table}` SET status = %s, last_used_at = %s WHERE grant_id = %s AND (status = %s OR (status = %s AND (last_used_at IS NULL OR last_used_at < %s)))",
            [
                self::STATUS_ACTIVE,
                $now,
                $grantId,
                self::STATUS_PENDING,
                self::STATUS_ACTIVE,
                gmdate('Y-m-d H:i:s', time() - self::TOUCH_INTERVAL),
            ]
        );
    }

    /**
     * The grant's OAuth code was exchanged: pending becomes active. A
     * revoked grant stays revoked.
     */
    public static function activate(string $grantId): void
    {
        $table = self::table();

        DB::query(
            "UPDATE `{$table}` SET status = %s WHERE grant_id = %s AND status = %s",
            [self::STATUS_ACTIVE, $grantId, self::STATUS_PENDING]
        );
    }

    /**
     * Claim the grant's first call (setup wizard, step 6): sets
     * first_call_at once. One conditional UPDATE, so of two parallel first
     * calls only one wins.
     *
     * @return bool True only for the call that set it.
     */
    public static function claimFirstCall(string $grantId): bool
    {
        $table = self::table();

        return DB::query(
            "UPDATE `{$table}` SET first_call_at = %s WHERE grant_id = %s AND first_call_at IS NULL",
            [gmdate('Y-m-d H:i:s'), $grantId]
        ) === 1;
    }

    /**
     * Revoke one live grant, and every OAuth code and token issued from it.
     *
     * @return bool True only when this call revoked it.
     */
    public static function revoke(string $grantId, int $by, string $reason): bool
    {
        $table = self::table();

        $changed = DB::query(
            "UPDATE `{$table}` SET status = %s, revoked_at = %s, revoked_by = %d, revoke_reason = %s WHERE grant_id = %s AND status <> %s",
            [self::STATUS_REVOKED, gmdate('Y-m-d H:i:s'), $by, $reason, $grantId, self::STATUS_REVOKED]
        );

        if ($changed !== 1) {
            return false;
        }

        Tokens::revokeGrant($grantId);

        return true;
    }

    /**
     * Revoke every live grant, or every live grant of $userId, or of the
     * OAuth client $clientKey.
     *
     * @return string[] The grant IDs this call revoked.
     */
    public static function revokeAll(int $by, string $reason, ?int $userId = null, ?string $clientKey = null): array
    {
        $table = self::table();
        $sql   = "SELECT grant_id FROM `{$table}` WHERE status <> %s";
        $args  = [self::STATUS_REVOKED];

        if ($userId !== null) {
            $sql   .= ' AND wp_user_id = %d';
            $args[] = $userId;
        }

        if ($clientKey !== null) {
            $sql   .= ' AND client_key = %s';
            $args[] = $clientKey;
        }

        $revoked = [];
        foreach (DB::getResults($sql, $args, ARRAY_A) as $row) {
            if (self::revoke((string) $row['grant_id'], $by, $reason)) {
                $revoked[] = (string) $row['grant_id'];
            }
        }

        return $revoked;
    }

    /**
     * Change a live grant's access level (a site admin in wp-admin only,
     * never over REST). Full and Custom carry `site:write`, Read-only only
     * `site:read`; Custom also stores its tool list, the others clear it.
     * The caller checks $tools against the catalog.
     *
     * @param string[] $tools Custom only.
     * @return bool False when the grant is unknown or revoked.
     */
    public static function updateAccess(string $grantId, string $access, array $tools = []): bool
    {
        $grant = self::find($grantId);

        if ($grant === null || $grant['status'] === self::STATUS_REVOKED || !in_array($access, self::ACCESSES, true)) {
            return false;
        }

        $scopes = $access === self::ACCESS_READ_ONLY
            ? [OAuthUrls::SCOPE_READ]
            : [OAuthUrls::SCOPE_READ, OAuthUrls::SCOPE_WRITE];

        $table = self::table();
        $list  = $access === self::ACCESS_CUSTOM ? (string) wp_json_encode(array_values(array_unique($tools))) : null;

        $changed = DB::query(
            "UPDATE `{$table}` SET scopes = %s, tools = " . ($list === null ? 'NULL' : '%s') . ' WHERE grant_id = %s AND status <> %s',
            array_merge([implode(' ', $scopes)], $list === null ? [] : [$list], [$grantId, self::STATUS_REVOKED])
        );

        // 0 rows when the access was already this; false on a database error.
        return is_int($changed);
    }

    /**
     * Daily cron: delete pending grants never used, and tombstones past
     * TOMBSTONE_DAYS.
     *
     * @return int Rows deleted.
     */
    public static function purge(): int
    {
        $table   = self::table();
        $deleted = DB::query(
            "DELETE FROM `{$table}` WHERE (status = %s AND created_at < %s) OR (status = %s AND revoked_at < %s)",
            [
                self::STATUS_PENDING,
                gmdate('Y-m-d H:i:s', time() - self::PENDING_TTL),
                self::STATUS_REVOKED,
                gmdate('Y-m-d H:i:s', time() - self::TOMBSTONE_DAYS * DAY_IN_SECONDS),
            ]
        );

        return is_int($deleted) ? $deleted : 0;
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    public static function isGrantId(string $value): bool
    {
        return (bool) preg_match('/^g_[A-Za-z0-9_-]{22}$/D', $value);
    }

    /**
     * @param array<string, mixed> $row
     * @return string[]
     */
    public static function scopes(array $row): array
    {
        return array_values(array_filter(explode(' ', (string) ($row['scopes'] ?? ''))));
    }

    /**
     * The grant's own tool list (Custom access), or null for every tool
     * that is on. A list that does not decode is an empty list: the grant
     * then reaches no tool, never every tool.
     *
     * @param array<string, mixed> $row
     * @return string[]|null
     */
    public static function tools(array $row): ?array
    {
        // The column exists once the 0.6.0 migration ran.
        if (!isset($row['tools']) || $row['tools'] === '') {
            return null;
        }

        $tools = json_decode((string) $row['tools'], true);

        return is_array($tools) ? array_values(array_filter($tools, 'is_string')) : [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function access(array $row): string
    {
        if (self::tools($row) !== null) {
            return self::ACCESS_CUSTOM;
        }

        return in_array(OAuthUrls::SCOPE_WRITE, self::scopes($row), true) ? self::ACCESS_FULL : self::ACCESS_READ_ONLY;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function format(array $row): array
    {
        $user = get_userdata((int) $row['wp_user_id']);

        return [
            'grant_id'      => (string) $row['grant_id'],
            'status'        => (string) $row['status'],
            'client_name'   => (string) $row['client_name'],
            'client_id'     => (string) $row['client_id'],
            'vouched_by'    => (string) $row['vouched_by'],
            'redirect_host' => (string) $row['redirect_host'],
            'scopes'        => self::scopes($row),
            'access'        => self::access($row),
            'tools'         => self::tools($row),
            'user'          => [
                'id'           => (int) $row['wp_user_id'],
                'login'        => $user ? $user->user_login : null,
                'display_name' => $user ? $user->display_name : null,
            ],
            'created_at'    => self::iso($row['created_at']),
            'last_used_at'  => self::iso($row['last_used_at']),
            'first_call_at' => self::iso($row['first_call_at'] ?? null),
            'revoked_at'    => self::iso($row['revoked_at']),
            'revoke_reason' => $row['revoke_reason'] !== null ? (string) $row['revoke_reason'] : null,
        ];
    }

    /**
     * @param mixed $datetime
     */
    private static function iso($datetime): ?string
    {
        return is_string($datetime) && $datetime !== '' ? str_replace(' ', 'T', $datetime) . 'Z' : null;
    }
}
