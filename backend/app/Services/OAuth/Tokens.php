<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;

/**
 * Authorization codes, access tokens and refresh tokens
 * (`{prefix}mcpmate_oauth_tokens`, docs/mcp-in-plugin.md §4.4–4.5).
 *
 * Each value is 32 random bytes, handed out once and stored only as its
 * SHA-256: a copy of the database holds nothing that can be replayed.
 * Codes and refresh tokens are single use, claimed with one conditional
 * UPDATE (claim()), so of two parallel uses only one wins. Every refresh
 * token of a grant shares one `family_id` (access tokens carry it too), and
 * the whole family is revoked at once when one of them is reused.
 */
final class Tokens
{
    public const TABLE = 'oauth_tokens';

    public const KIND_CODE = 'code';

    public const KIND_ACCESS = 'access';

    public const KIND_REFRESH = 'refresh';

    public const ACCESS_PREFIX = 'mcpm_at_';

    public const REFRESH_PREFIX = 'mcpm_rt_';

    /** Seconds an authorization code stays valid. */
    public const CODE_TTL = 60;

    public static function table(): string
    {
        return Config::withDBPrefix(self::TABLE);
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /** 32 random bytes, base64url (43 characters). */
    public static function random(): string
    {
        return Pkce::b64url(random_bytes(32));
    }

    /** A new refresh token family, 16 random bytes (22 characters). */
    public static function newFamily(): string
    {
        return Pkce::b64url(random_bytes(16));
    }

    // ──────────────────────────────────────────────
    //  Issue
    // ──────────────────────────────────────────────

    /**
     * A code for an approved request, bound to its client, redirect URI,
     * challenge, resource, scopes and grant.
     *
     * @param string[] $scopes
     * @return string|null The code, or null on a database error.
     */
    public static function issueCode(string $grantId, string $clientKey, array $scopes, string $resource, string $challenge, string $redirectUri): ?string
    {
        $code = self::random();

        return self::insert($code, self::KIND_CODE, [
            'grant_id'       => $grantId,
            'client_key'     => $clientKey,
            'scopes'         => implode(' ', $scopes),
            'resource'       => $resource,
            'code_challenge' => $challenge,
            'redirect_uri'   => $redirectUri,
        ], time() + self::CODE_TTL) ? $code : null;
    }

    /**
     * @param string[] $scopes
     */
    public static function issueAccess(string $grantId, string $clientKey, string $familyId, array $scopes, string $resource, int $expiresAt): ?string
    {
        $token = self::ACCESS_PREFIX . self::random();

        return self::insert($token, self::KIND_ACCESS, [
            'grant_id'   => $grantId,
            'client_key' => $clientKey,
            'family_id'  => $familyId,
            'scopes'     => implode(' ', $scopes),
            'resource'   => $resource,
        ], $expiresAt) ? $token : null;
    }

    /**
     * @param string[] $scopes
     */
    public static function issueRefresh(string $grantId, string $clientKey, string $familyId, ?string $parentHash, array $scopes, string $resource, int $expiresAt): ?string
    {
        $token = self::REFRESH_PREFIX . self::random();

        return self::insert($token, self::KIND_REFRESH, [
            'grant_id'    => $grantId,
            'client_key'  => $clientKey,
            'family_id'   => $familyId,
            'parent_hash' => $parentHash,
            'scopes'      => implode(' ', $scopes),
            'resource'    => $resource,
        ], $expiresAt) ? $token : null;
    }

    // ──────────────────────────────────────────────
    //  Read and use
    // ──────────────────────────────────────────────

    /**
     * The row of $value if it is a $kind, whatever its state; the caller
     * checks expiry, use and revocation.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $value, string $kind): ?array
    {
        if ($value === '' || strlen($value) > 200) {
            return null;
        }

        $table = self::table();
        $row   = DB::getRow(
            "SELECT * FROM `{$table}` WHERE token_hash = %s AND kind = %s",
            [self::hash($value), $kind],
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Mark a code or refresh token used.
     *
     * @return bool True only for the call that used it.
     */
    public static function claim(string $hash): bool
    {
        $table = self::table();

        return DB::query(
            "UPDATE `{$table}` SET used_at = %s WHERE token_hash = %s AND used_at IS NULL AND revoked_at IS NULL",
            [gmdate('Y-m-d H:i:s'), $hash]
        ) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isExpired(array $row): bool
    {
        return strtotime((string) $row['expires_at'] . ' UTC') <= time();
    }

    /**
     * @param array<string, mixed> $row
     * @return string[]
     */
    public static function scopes(array $row): array
    {
        return array_values(array_filter(explode(' ', (string) $row['scopes'])));
    }

    // ──────────────────────────────────────────────
    //  Revoke
    // ──────────────────────────────────────────────

    public static function revoke(string $hash): void
    {
        $table = self::table();

        DB::query(
            "UPDATE `{$table}` SET revoked_at = %s WHERE token_hash = %s AND revoked_at IS NULL",
            [gmdate('Y-m-d H:i:s'), $hash]
        );
    }

    /**
     * Every access and refresh token of one family.
     */
    public static function revokeFamily(string $familyId): void
    {
        $table = self::table();

        DB::query(
            "UPDATE `{$table}` SET revoked_at = %s WHERE family_id = %s AND revoked_at IS NULL",
            [gmdate('Y-m-d H:i:s'), $familyId]
        );
    }

    /**
     * Every code and token of a grant (the grant was revoked).
     */
    public static function revokeGrant(string $grantId): void
    {
        $table = self::table();

        DB::query(
            "UPDATE `{$table}` SET revoked_at = %s WHERE grant_id = %s AND revoked_at IS NULL",
            [gmdate('Y-m-d H:i:s'), $grantId]
        );
    }

    /**
     * Daily cron: delete expired codes and tokens. A used refresh token
     * stays until its family expires, so a replay is recognized (and
     * revokes the grant) for as long as the family could be used.
     *
     * @return int Rows deleted.
     */
    public static function purge(): int
    {
        $table   = self::table();
        $deleted = DB::query("DELETE FROM `{$table}` WHERE expires_at < %s", [gmdate('Y-m-d H:i:s')]);

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @param array<string, string|null> $fields
     */
    private static function insert(string $value, string $kind, array $fields, int $expiresAt): bool
    {
        $now  = gmdate('Y-m-d H:i:s');
        $data = [
            'token_hash' => self::hash($value),
            'kind'       => $kind,
            'created_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
        ] + array_filter($fields, static fn ($field): bool => $field !== null);

        return DB::insert(self::table(), $data, array_fill(0, count($data), '%s')) !== false;
    }
}
