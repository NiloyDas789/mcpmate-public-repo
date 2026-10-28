<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No cache serves stored copies of the sign-in addresses (docs/mcp-in-plugin.md
 * §10.3, check 5). Every OAuth and MCP answer says `no-store`, but a page
 * cache or CDN can be set to ignore that; a stored 401 or discovery document
 * from another address breaks sign-in. The discovery documents are fetched
 * twice, and the second answer's cache headers are read.
 */
final class CachingCheck extends Check
{
    public const ID = 'caching';

    /** @var string[] */
    private array $hits;

    private bool $tested;

    /** @var string[] */
    private array $caches;

    /** @var string[] */
    private array $paths;

    /**
     * @param string[] $hits   Evidence of a cached answer (hitEvidence()), one per address.
     * @param bool     $tested Whether any second request was answered.
     * @param string[] $caches Active page-cache plugins and CDNs, by name.
     * @param string[] $paths  The addresses to exclude from caching.
     */
    public function __construct(array $hits, bool $tested, array $caches, array $paths)
    {
        $this->hits   = $hits;
        $this->tested = $tested;
        $this->caches = $caches;
        $this->paths  = $paths;
    }

    public function run(): array
    {
        $title = __('Caches don\'t store sign-in answers', 'mcpmate');

        if ($this->hits !== []) {
            return self::result(
                self::FAIL,
                $title,
                __('A cache answered with a stored copy of an address AI apps sign in with, so apps can get an out-of-date or wrong answer.', 'mcpmate'),
                $this->caches !== []
                    /* translators: %s: comma-separated names of cache plugins or CDNs. */
                    ? sprintf(__('In %s, add these addresses to the list of pages never to cache, then clear the cache:', 'mcpmate'), implode(', ', $this->caches))
                    : __('In your cache plugin, host or CDN, add these addresses to the list of pages never to cache, then clear the cache:', 'mcpmate'),
                [
                    ['text' => '', 'code' => implode("\n", $this->paths)],
                    ['text' => implode('; ', $this->hits)],
                ]
            );
        }

        $exclusions = [];
        if ($this->caches !== []) {
            $exclusions[] = [
                /* translators: %s: comma-separated names of cache plugins or CDNs. */
                'text' => sprintf(__('%s is active. Keep these addresses on its list of pages never to cache:', 'mcpmate'), implode(', ', $this->caches)),
                'code' => implode("\n", $this->paths),
            ];
        }

        if (!$this->tested) {
            return self::result(
                $this->caches !== [] ? self::WARN : self::INFO,
                $title,
                __('We couldn\'t test this from your server itself.', 'mcpmate'),
                '',
                $exclusions
            );
        }

        return self::result(self::PASS, $title, __('No cache answered for WordPress.', 'mcpmate'), '', $exclusions);
    }

    /**
     * What in a response's headers shows it came from a cache, or ''.
     *
     * @param array<string, string> $headers Lowercase names.
     */
    public static function hitEvidence(array $headers): string
    {
        if ((int) ($headers['age'] ?? 0) > 0) {
            return 'age: ' . $headers['age'];
        }

        foreach (['cf-cache-status', 'x-cache', 'x-cache-status', 'x-litespeed-cache', 'x-proxy-cache', 'x-sg-cache', 'x-kinsta-cache', 'x-nginx-cache', 'x-wp-cf-super-cache'] as $name) {
            $value = strtolower((string) ($headers[$name] ?? ''));

            if ($value !== '' && strpos($value, 'hit') !== false) {
                return $name . ': ' . $headers[$name];
            }
        }

        // Varnish puts two request IDs in X-Varnish when it answered from its cache.
        if (preg_match('/^\s*\d+\s+\d+\s*$/', (string) ($headers['x-varnish'] ?? '')) === 1) {
            return 'x-varnish: ' . $headers['x-varnish'];
        }

        return '';
    }
}
