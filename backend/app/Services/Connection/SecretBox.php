<?php

namespace McpMate\Services\Connection;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Seals small secrets at rest (the site owner's Unsplash key) with
 * sodium_crypto_secretbox (libsodium, or the sodium_compat copy WordPress
 * bundles) under sealingKey().
 */
final class SecretBox
{
    private const SEALING_CONTEXT = 'mcpmate connection key';

    /**
     * base64url(nonce || secretbox($plaintext)).
     */
    public static function seal(string $plaintext, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::b64urlEncode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    /**
     * The plaintext, or null when $sealed is malformed or does not open
     * with $key (the salts or MCPMATE_SECRET_KEY changed).
     */
    public static function open(string $sealed, string $key): ?string
    {
        $raw = self::b64urlDecode($sealed);

        if ($raw === null || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        try {
            $plaintext = sodium_crypto_secretbox_open(
                (string) substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * The 32-byte sealing key: from MCPMATE_SECRET_KEY in wp-config.php
     * when defined, otherwise from the site's auth salt.
     */
    public static function sealingKey(): string
    {
        $constant = defined('MCPMATE_SECRET_KEY') ? constant('MCPMATE_SECRET_KEY') : '';

        if (is_string($constant) && $constant !== '') {
            return hash('sha256', $constant, true);
        }

        return hash_hmac('sha256', self::SEALING_CONTEXT, wp_salt('auth'), true);
    }

    private static function b64urlEncode(string $bytes): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of sealed bytes.
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Null for anything that is not unpadded base64url.
     */
    private static function b64urlDecode(string $value): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/D', $value) || strlen($value) % 4 === 1) {
            return null;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64url of sealed bytes.
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
