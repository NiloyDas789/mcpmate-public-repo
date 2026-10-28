<?php

namespace McpMate\Services\Connection;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Hooks\Hooks;
use McpMate\Services\AuditLog\Logger;

/**
 * Revokes grants and audits each one: a site admin's Revoke and Revoke all
 * on Connections, a revoked OAuth client's grants, and a deleted user's. The grant is denied from
 * that moment, with every OAuth code and token issued from it (Grants).
 */
final class GrantRevoker
{
    public static function register(): void
    {
        Hooks::addAction('deleted_user', [self::class, 'userDeleted']);
    }

    /**
     * Revoke one grant as $by (a WordPress user, 0 for the system).
     *
     * @return bool True when this call revoked it.
     */
    public static function revoke(string $grantId, int $by, string $reason): bool
    {
        if (!Grants::revoke($grantId, $by, $reason)) {
            return false;
        }

        self::audit($grantId, $reason);

        return true;
    }

    /**
     * Revoke every live grant (Revoke all).
     *
     * @return string[] The grants revoked.
     */
    public static function revokeAll(int $by, string $reason): array
    {
        $revoked = Grants::revokeAll($by, $reason);

        foreach ($revoked as $grantId) {
            self::audit($grantId, $reason);
        }

        return $revoked;
    }

    /**
     * Revoke every live grant of one OAuth client (Registered clients → Revoke).
     *
     * @return string[] The grants revoked.
     */
    public static function revokeClient(string $clientKey, int $by): array
    {
        $revoked = Grants::revokeAll($by, Grants::REASON_CLIENT_REVOKED, null, $clientKey);

        foreach ($revoked as $grantId) {
            self::audit($grantId, Grants::REASON_CLIENT_REVOKED);
        }

        return $revoked;
    }

    /**
     * `deleted_user`: the user's grants die with them.
     *
     * @param int|string $userId
     */
    public static function userDeleted($userId): void
    {
        Grants::revokeAll(0, Grants::REASON_USER_DELETED, (int) $userId);
    }

    private static function audit(string $grantId, string $reason): void
    {
        Logger::log([
            'tool'     => 'connection',
            'action'   => 'revoke',
            'target'   => 'grant:' . $grantId,
            'args'     => ['reason' => $reason],
            'result'   => Logger::RESULT_OK,
            'grant_id' => $grantId,
        ]);
    }
}
