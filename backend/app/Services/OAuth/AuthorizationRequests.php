<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;

/**
 * Authorization requests waiting for a site admin
 * (`{prefix}mcpmate_oauth_requests`, docs/mcp-in-plugin.md §4.4).
 *
 * `GET I/authorize` stores one after checking it, and sends the browser to
 * `admin.php?page=mcpmate&connect=<id>`. The ID is 32 random bytes;
 * only its SHA-256 is stored. A request lives 10 minutes and is used once
 * (approved or denied), claimed with one conditional UPDATE.
 */
final class AuthorizationRequests
{
    public const TABLE = 'oauth_requests';

    /** Seconds a request waits for the admin. */
    public const TTL = 600;

    public static function table(): string
    {
        return Config::withDBPrefix(self::TABLE);
    }

    /** The shape of a request ID in `?connect=`. */
    public static function isId(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/D', $value);
    }

    /**
     * @param string[] $scopes
     * @return string|null The request ID, or null on a database error.
     */
    public static function store(string $clientKey, string $redirectUri, string $state, string $challenge, array $scopes, string $resource): ?string
    {
        $id  = Tokens::random();
        $now = time();

        $inserted = DB::insert(
            self::table(),
            [
                'request_hash'   => Tokens::hash($id),
                'client_key'     => $clientKey,
                'redirect_uri'   => $redirectUri,
                'state'          => $state,
                'code_challenge' => $challenge,
                'scopes'         => implode(' ', $scopes),
                'resource'       => $resource,
                'created_at'     => gmdate('Y-m-d H:i:s', $now),
                'expires_at'     => gmdate('Y-m-d H:i:s', $now + self::TTL),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $inserted === false ? null : $id;
    }

    /**
     * The row of $id whatever its state; the caller checks use and expiry.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        if (!self::isId($id)) {
            return null;
        }

        $table = self::table();
        $row   = DB::getRow("SELECT * FROM `{$table}` WHERE request_hash = %s", [Tokens::hash($id)], ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Use the request once.
     *
     * @return bool True only for the call that used it while it was still valid.
     */
    public static function claim(string $id): bool
    {
        $table = self::table();
        $now   = gmdate('Y-m-d H:i:s');

        return DB::query(
            "UPDATE `{$table}` SET used_at = %s WHERE request_hash = %s AND used_at IS NULL AND expires_at > %s",
            [$now, Tokens::hash($id), $now]
        ) === 1;
    }

    /**
     * Daily cron: delete expired requests.
     *
     * @return int Rows deleted.
     */
    public static function purge(): int
    {
        $table   = self::table();
        $deleted = DB::query("DELETE FROM `{$table}` WHERE expires_at < %s", [gmdate('Y-m-d H:i:s')]);

        return is_int($deleted) ? $deleted : 0;
    }
}
