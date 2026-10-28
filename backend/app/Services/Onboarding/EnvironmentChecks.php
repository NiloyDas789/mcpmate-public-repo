<?php

namespace McpMate\Services\Onboarding;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Connection\Connection;
use McpMate\Services\OAuth\OAuthServer;
use McpMate\Services\OAuth\OAuthUrls;
use McpMate\Services\OAuth\Probe;
use McpMate\Services\Onboarding\Checks\AdminCheck;
use McpMate\Services\Onboarding\Checks\AuthHeaderCheck;
use McpMate\Services\Onboarding\Checks\CachingCheck;
use McpMate\Services\Onboarding\Checks\Check;
use McpMate\Services\Onboarding\Checks\EnvironmentCheck;
use McpMate\Services\Onboarding\Checks\ExecutionTimeCheck;
use McpMate\Services\Onboarding\Checks\HttpsCheck;
use McpMate\Services\Onboarding\Checks\McpEndpointCheck;
use McpMate\Services\Onboarding\Checks\PermalinksCheck;
use McpMate\Services\Onboarding\Checks\VersionsCheck;
use McpMate\Services\Onboarding\Checks\WellKnownCheck;

/**
 * Runs every site check in one request (admin-ajax `onboarding/checks`;
 * setup step 2 and the Dashboard's Site checks, docs/mcp-in-plugin.md §10).
 *
 * Gathers the facts once and hands each check what it needs. The network
 * calls are the site's requests to its own public address (its tunnel when
 * one is set), as AI apps make them: the MCP URL without a token, the
 * Authorization header probe, and each discovery document twice (the second
 * time for the caching check). They share one time budget (LoopbackProbe).
 * A check that throws becomes a warning row; the run never fails as a whole.
 */
final class EnvironmentChecks
{
    /** The MCP request an AI app sends first; without a token it gets the 401 that starts sign-in. */
    private const INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"mcpmate-site-check","version":"1"}}}';

    /** Seconds for all of the site's requests to itself. */
    public const LOOPBACK_BUDGET = 25;

    /**
     * @return array{checks: array<int, array<string, mixed>>, summary: array{pass: int, warn: int, fail: int}}
     */
    public static function run(): array
    {
        $rows = [];

        foreach (self::checks() as $check) {
            $rows[] = self::runOne($check);
        }

        return ['checks' => $rows, 'summary' => self::summarize($rows)];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{pass: int, warn: int, fail: int}
     */
    public static function summarize(array $rows): array
    {
        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0];

        foreach ($rows as $row) {
            if (isset($summary[$row['status']])) {
                ++$summary[$row['status']];
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public static function runOne(Check $check): array
    {
        try {
            $result = $check->run();
        } catch (\Throwable $e) {
            $result = [
                'status'  => Check::WARN,
                'title'   => $check::ID,
                'message' => __('This check couldn\'t run. You can continue; connecting an AI app tests the connection for real.', 'mcpmate'),
                'fix'     => '',
                'details' => [],
            ];
        }

        return ['id' => $check::ID] + $result;
    }

    // ──────────────────────────────────────────────
    //  Facts
    // ──────────────────────────────────────────────

    /**
     * @return Check[]
     */
    private static function checks(): array
    {
        $homeUrl    = home_url('/');
        $publicUrl  = self::configuredTunnel();
        $host       = (string) wp_parse_url($homeUrl, PHP_URL_HOST);
        $hostClass  = HostClassifier::classify($host, wp_get_environment_type(), (bool) get_option('blog_public'));
        $active     = self::activePlugins();
        $server     = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';
        $permalinks = new PermalinksCheck((string) get_option('permalink_structure'));
        $paths      = self::paths();
        $loopback   = new LoopbackProbe(self::LOOPBACK_BUDGET);

        // The MCP URL without a token: the request every AI app makes first.
        $mcp   = $loopback->request('POST', OAuthUrls::resource(), ['Accept' => 'application/json, text/event-stream'], [], self::INITIALIZE);
        $reach = LoopbackDiagnosis::diagnose($mcp);

        $auth = ['error' => 'skipped', 'status' => 0, 'headers' => [], 'body' => ''];
        if ($permalinks->isPretty()) {
            $value = Probe::start();
            $auth  = $loopback->request('POST', OAuthUrls::endpoint(OAuthServer::PROBE), ['Authorization' => 'Bearer ' . $value], ['probe' => $value]);
            Probe::finish();
        }

        $discovery  = $permalinks->isPretty() ? self::discovery($loopback) : ['results' => [], 'hits' => [], 'tested' => false, 'cloudflare' => false];
        $cloudflare = LoopbackDiagnosis::isCloudflare($mcp['headers']) || $discovery['cloudflare'];
        $caches     = CachePlugins::detect($active);
        if ($cloudflare) {
            $caches[] = 'Cloudflare';
        }

        // The tunnel is B when WordPress accepts it, so the MCP request went through it.
        $tunnel = $publicUrl !== '' && Connection::publicUrl() !== '' ? $reach : null;
        $user   = wp_get_current_user();

        return [
            new HttpsCheck($homeUrl, $hostClass),
            $permalinks,
            new McpEndpointCheck($reach, SecurityPlugins::detect($active), $cloudflare, $publicUrl !== '', $paths, $server),
            new AuthHeaderCheck($auth, $permalinks->isPretty(), $server),
            new WellKnownCheck($discovery['results'], $permalinks->isPretty(), (string) wp_parse_url($homeUrl, PHP_URL_PATH), $server),
            new CachingCheck($discovery['hits'], $discovery['tested'], array_values(array_unique($caches)), $paths),
            new ExecutionTimeCheck((string) ini_get('max_execution_time'), (string) ini_get('disable_functions')),
            new VersionsCheck((string) get_bloginfo('version'), PHP_VERSION),
            new AdminCheck((string) $user->user_login, self::roleName($user)),
            new EnvironmentCheck($hostClass, Connection::isLocal(), $homeUrl, $publicUrl, $tunnel),
        ];
    }

    /**
     * Each discovery address, fetched and compared with what this site
     * serves; then the ones that answered, fetched again for cache headers.
     *
     * @return array{results: array<string, array{url: string, result: string}>, hits: string[], tested: bool, cloudflare: bool}
     */
    private static function discovery(LoopbackProbe $loopback): array
    {
        $results    = [];
        $answers    = [];
        $cloudflare = false;

        foreach (self::discoveryUrls() as $id => $url) {
            $response      = $loopback->request('GET', $url, ['Accept' => 'application/json']);
            $answers[$id]  = $response;
            $results[$id]  = ['url' => $url, 'result' => self::judge($id, $response)];
            $cloudflare    = $cloudflare || LoopbackDiagnosis::isCloudflare($response['headers']);
        }

        $hits   = [];
        $tested = false;

        foreach ($results as $id => $result) {
            if ($result['result'] !== WellKnownCheck::OK) {
                continue;
            }

            $again    = $loopback->request('GET', $result['url'], ['Accept' => 'application/json']);
            $evidence = CachingCheck::hitEvidence($answers[$id]['headers']);

            if ($again['error'] === '') {
                $tested   = true;
                $evidence = $evidence !== '' ? $evidence : CachingCheck::hitEvidence($again['headers']);
            }

            if ($evidence !== '') {
                $hits[] = self::pathOf($result['url']) . ' (' . $evidence . ')';
            }
        }

        return ['results' => $results, 'hits' => $hits, 'tested' => $tested, 'cloudflare' => $cloudflare];
    }

    /**
     * The discovery addresses of docs/mcp-in-plugin.md §4.2, by WellKnownCheck id.
     * Path-inserted ones are at the root of the host, which is not this
     * WordPress when it lives in a folder.
     *
     * @return array<string, string>
     */
    private static function discoveryUrls(): array
    {
        $issuer   = OAuthUrls::issuer();
        $origin   = self::origin($issuer);
        $resource = self::pathOf(OAuthUrls::resource());
        $issuerAt = self::pathOf($issuer);

        return [
            'resource_metadata' => OAuthUrls::endpoint('resource-metadata'),
            'server_appended'   => $issuer . '/.well-known/oauth-authorization-server',
            'resource_inserted' => $origin . '/.well-known/oauth-protected-resource' . $resource,
            'resource_root'     => $origin . '/.well-known/oauth-protected-resource',
            'server_inserted'   => $origin . '/.well-known/oauth-authorization-server' . $issuerAt,
            'openid_inserted'   => $origin . '/.well-known/openid-configuration' . $issuerAt,
        ];
    }

    /**
     * Whether $response is this site's document for discovery address $id.
     *
     * @param array{error: string, status: int, headers: array<string, string>, body: string} $response
     */
    private static function judge(string $id, array $response): string
    {
        if ($response['error'] !== '') {
            return WellKnownCheck::UNTESTED;
        }

        $document = $response['status'] === 200 ? json_decode($response['body'], true) : null;

        if (!is_array($document)) {
            return WellKnownCheck::WRONG;
        }

        $matches = strpos($id, 'resource') === 0
            ? ($document['resource'] ?? null) === OAuthUrls::resource()
            : ($document['issuer'] ?? null) === OAuthUrls::issuer();

        return $matches ? WellKnownCheck::OK : WellKnownCheck::WRONG;
    }

    /**
     * The addresses a firewall or cache rule names: the MCP URL, everything
     * under the issuer, and the discovery documents.
     *
     * @return string[]
     */
    private static function paths(): array
    {
        return [
            self::pathOf(OAuthUrls::resource()),
            self::pathOf(OAuthUrls::issuer()) . '/',
            '/.well-known/oauth-',
            '/.well-known/openid-configuration',
        ];
    }

    private static function origin(string $url): string
    {
        $parts = wp_parse_url($url);
        $port  = isset($parts['port']) ? ':' . $parts['port'] : '';

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $port;
    }

    /**
     * $url without its origin: the path and any query.
     */
    private static function pathOf(string $url): string
    {
        return (string) substr($url, strlen(self::origin($url)));
    }

    /**
     * MCPMATE_PUBLIC_URL as configured, even when Connection::publicUrl()
     * rejects it, so a stopped tunnel reads "doesn't answer", not "set one up".
     */
    private static function configuredTunnel(): string
    {
        $url = defined('MCPMATE_PUBLIC_URL') ? constant('MCPMATE_PUBLIC_URL') : '';

        return is_string($url) ? untrailingslashit(esc_url_raw(trim($url), ['http', 'https'])) : '';
    }

    /**
     * @return string[] Active plugin files, network-activated ones included.
     */
    private static function activePlugins(): array
    {
        $plugins = (array) get_option('active_plugins', []);

        if (is_multisite()) {
            $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }

        return array_values(array_filter($plugins, 'is_string'));
    }

    private static function roleName(\WP_User $user): string
    {
        $role  = (string) (array_values($user->roles)[0] ?? '');
        $names = wp_roles()->role_names;

        return $role !== '' && isset($names[$role]) ? translate_user_role($names[$role]) : '';
    }
}
