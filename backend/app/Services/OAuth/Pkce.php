<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proof Key for Code Exchange (RFC 7636), S256 only: `plain` is refused,
 * as OAuth 2.1 and the MCP authorization spec require.
 *
 * Pure: no WordPress calls.
 */
final class Pkce
{
    public const METHOD = 'S256';

    /** base64url of a SHA-256: always 43 characters. */
    public static function isChallenge(string $challenge): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge);
    }

    /** 43 to 128 unreserved characters (RFC 7636 §4.1). */
    public static function isVerifier(string $verifier): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier);
    }

    public static function challenge(string $verifier): string
    {
        return self::b64url(hash('sha256', $verifier, true));
    }

    /**
     * Whether $verifier is the one $challenge was made from. Constant time.
     */
    public static function verify(string $verifier, string $challenge): bool
    {
        if (!self::isVerifier($verifier) || !self::isChallenge($challenge)) {
            return false;
        }

        return hash_equals($challenge, self::challenge($verifier));
    }

    public static function b64url(string $bytes): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of random bytes and hashes (RFC 7636 wire format).
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
