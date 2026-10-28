<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Per-IP limits for the public OAuth endpoints (docs/mcp-in-plugin.md §4.3,
 * T8): a fixed window counted in a transient, which a persistent object
 * cache keeps in memory. Approximate under parallel requests, which is
 * enough to stop a flood.
 *
 * Only REMOTE_ADDR counts: a forwarded-for header is the client's word.
 */
final class RateLimit
{
    /**
     * Count one request from $ip in $bucket.
     *
     * @return int 0 when allowed, else the seconds until the window ends (Retry-After).
     */
    public static function hit(string $bucket, string $ip, int $limit, int $window): int
    {
        $now    = time();
        $start  = $now - ($now % $window);
        $key    = Config::withPrefix('rl_' . md5($bucket . '|' . ($ip !== '' ? $ip : 'unknown') . '|' . $start));
        $count  = (int) get_transient($key);
        $remain = max(1, $start + $window - $now);

        if ($count >= $limit) {
            return $remain;
        }

        set_transient($key, $count + 1, $remain);

        return 0;
    }
}
