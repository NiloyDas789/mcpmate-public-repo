<?php

namespace McpMate\Services\Approvals;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The secret that lets the requester poll an approval.
 *
 * 32 random bytes, sent to the caller once as 64 hex characters. Only the
 * SHA-256 of the token is stored; a 256-bit random value needs no slow hash.
 */
final class ApprovalToken
{
    public const BYTES = 32;

    /** Shape of a token as the caller sends it back. */
    public const PATTERN = '^[a-f0-9]{64}$';

    /**
     * A new token (64 lowercase hex characters).
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }

    /**
     * The value stored in `token_hash`.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Constant-time check of $token against a stored hash.
     */
    public static function matches(string $token, string $storedHash): bool
    {
        if (!preg_match('/' . self::PATTERN . '/D', $token) || strlen($storedHash) !== 64) {
            return false;
        }

        return hash_equals($storedHash, self::hash($token));
    }
}
