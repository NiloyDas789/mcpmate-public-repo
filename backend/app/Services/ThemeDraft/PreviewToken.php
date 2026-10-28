<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The signed, expiring token in a draft preview link
 * (`?MCPMATE_preview=<token>`).
 *
 * Format: `<draft id>.<expiry, unix time>.<HMAC-SHA256>`. The MAC covers a
 * purpose string, the draft ID, the draft's folder name and the expiry, under
 * a key from the site's salts (ThemePreview::key()). Nothing is stored: a
 * token stops working when it expires, when the draft is published, or when
 * the salts change.
 *
 * Pure PHP (the key is passed in), so it is unit-tested without WordPress.
 */
final class PreviewToken
{
    public const DEFAULT_TTL = 3600;

    public const MIN_TTL = 300;

    /** 24 hours. */
    public const MAX_TTL = 86400;

    private const FORMAT = '/^([a-f0-9]{8})\.([0-9]{10})\.([a-f0-9]{64})$/D';

    private const PURPOSE = 'mcpmate-theme-preview';

    public static function create(string $draftId, string $slug, int $expires, string $key): string
    {
        return $draftId . '.' . $expires . '.' . self::sign($draftId, $slug, $expires, $key);
    }

    /**
     * The draft ID and expiry of a well-formed token, before its signature is
     * checked; null for anything else.
     *
     * @return array{id: string, expires: int, signature: string}|null
     */
    public static function parse(string $token): ?array
    {
        if (!preg_match(self::FORMAT, $token, $match)) {
            return null;
        }

        return ['id' => $match[1], 'expires' => (int) $match[2], 'signature' => $match[3]];
    }

    /**
     * Constant-time check of a parsed token against the draft it names.
     *
     * @param array{id: string, expires: int, signature: string} $parsed
     */
    public static function isValid(array $parsed, string $slug, string $key, int $now): bool
    {
        if ($parsed['expires'] <= $now || $parsed['expires'] > $now + self::MAX_TTL || $key === '') {
            return false;
        }

        return hash_equals(self::sign($parsed['id'], $slug, $parsed['expires'], $key), $parsed['signature']);
    }

    /**
     * $ttl seconds clamped to MIN_TTL…MAX_TTL.
     */
    public static function ttl(int $ttl): int
    {
        return max(self::MIN_TTL, min(self::MAX_TTL, $ttl));
    }

    private static function sign(string $draftId, string $slug, int $expires, string $key): string
    {
        return hash_hmac('sha256', self::PURPOSE . "\n" . $draftId . "\n" . $slug . "\n" . $expires, $key);
    }
}
