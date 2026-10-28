<?php

namespace McpMate\Services\Onboarding;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Media\RemoteUrl;

/**
 * Where a site runs, from its host name and environment (pure).
 *
 * - local: WP_ENVIRONMENT_TYPE `local`, a local-only host name, a loopback
 *   or private IP literal, or a name that resolves only to private addresses.
 * - staging: environment type `staging`/`development`, a known staging host
 *   pattern, or "Discourage search engines" (a hint only).
 * - public: anything else.
 *
 * The name lists live here, deliberately without a filter (setup open
 * question 13); they will go stale and need updating by hand.
 */
final class HostClassifier
{
    public const LOCAL = 'local';

    public const STAGING = 'staging';

    public const PUBLIC = 'public';

    private const LOCAL_SUFFIXES = ['.localhost', '.local', '.test', '.ddev.site', '.lndo.site'];

    private const STAGING_PREFIXES = ['staging.', 'stg.', 'dev.'];

    private const STAGING_SUFFIXES = ['.wpengine.com', '.kinsta.cloud', '.pantheonsite.io', '.flywheelsites.com', '.instawp.xyz'];

    /**
     * @param callable(string): string[]|null $resolver Host → IP addresses; null uses gethostbynamel().
     */
    public static function classify(string $host, string $environmentType, bool $blogPublic, ?callable $resolver = null): string
    {
        $host = rtrim(strtolower($host), '.');

        if ($environmentType === 'local' || self::isLocalHost($host, $resolver)) {
            return self::LOCAL;
        }

        if (in_array($environmentType, ['staging', 'development'], true) || self::isStagingHost($host) || !$blogPublic) {
            return self::STAGING;
        }

        return self::PUBLIC;
    }

    /**
     * @param callable(string): string[]|null $resolver
     */
    public static function isLocalHost(string $host, ?callable $resolver = null): bool
    {
        if ($host === '' || $host === 'localhost') {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        $literal = (string) preg_replace('/^\[(.*)\]$/', '$1', $host);

        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            return !RemoteUrl::isPublicAddress($literal);
        }

        $addresses = $resolver !== null ? (array) $resolver($host) : self::lookup($host);
        $addresses = array_values(array_filter($addresses, 'is_string'));

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (RemoteUrl::isPublicAddress($address)) {
                return false;
            }
        }

        return true;
    }

    public static function isStagingHost(string $host): bool
    {
        foreach (self::STAGING_PREFIXES as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return true;
            }
        }

        foreach (self::STAGING_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private static function lookup(string $host): array
    {
        $addresses = gethostbynamel($host);

        return is_array($addresses) ? $addresses : [];
    }
}
