<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Services\Connection\Grants;

/**
 * OAuth clients (`{prefix}mcpmate_oauth_clients`, docs/mcp-in-plugin.md
 * §4.3): ones that registered themselves (DCR, `kind` dcr) and cached
 * Client ID Metadata Documents (`kind` cimd, the `client_id` is the
 * document's URL). Rows are keyed by the SHA-256 of the client_id; a DCR
 * client secret is stored only as its SHA-256.
 *
 * A site admin can revoke a client (Connections → Registered clients): its
 * grants are revoked with every token, and it is refused at authorize, token
 * and approve until they allow it again. A client nobody approved (never
 * used, no grant) is deleted after a day by the daily Retention cron; a
 * revoked self-registered one after REVOKED_DAYS (its client_id can never
 * come back); a revoked metadata document stays, so its URL stays refused.
 */
final class Clients
{
    public const TABLE = 'oauth_clients';

    public const KIND_DCR = 'dcr';

    public const KIND_CIMD = 'cimd';

    public const AUTH_NONE = 'none';

    public const AUTH_POST = 'client_secret_post';

    public const AUTH_BASIC = 'client_secret_basic';

    public const AUTH_METHODS = [self::AUTH_NONE, self::AUTH_POST, self::AUTH_BASIC];

    /** Self-registered clients per site; new registrations are refused above it. */
    public const MAX_DCR = 200;

    /** Seconds before a client nobody approved is deleted. */
    public const UNUSED_TTL = DAY_IN_SECONDS;

    /** Days a revoked self-registered client is kept. */
    public const REVOKED_DAYS = 30;

    /** Rows the Registered clients table lists. */
    private const LIST_LIMIT = 300;

    public static function table(): string
    {
        return Config::withDBPrefix(self::TABLE);
    }

    public static function key(string $clientId): string
    {
        return hash('sha256', $clientId);
    }

    // ──────────────────────────────────────────────
    //  Reads
    // ──────────────────────────────────────────────

    /**
     * The stored client for $clientId; a CIMD document is not fetched here
     * (resolve() does that).
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $clientId): ?array
    {
        if ($clientId === '' || strlen($clientId) > RedirectUris::MAX_LENGTH) {
            return null;
        }

        $client = self::findByKey(self::key($clientId));

        // The key is a hash: make sure it is this client_id.
        return $client !== null && hash_equals((string) $client['client_id'], $clientId) ? $client : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByKey(string $clientKey): ?array
    {
        $table = self::table();
        $row   = DB::getRow("SELECT * FROM `{$table}` WHERE client_key = %s", [$clientKey], ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        $uris                 = json_decode((string) $row['redirect_uris'], true);
        $row['redirect_uris'] = is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];

        return $row;
    }

    /**
     * The client for an authorization request: a registered one, or a
     * metadata document from the cache or fetched now.
     *
     * @return array{client: array<string, mixed>|null, error: string}
     */
    public static function resolve(string $clientId): array
    {
        if (!ClientMetadata::isDocumentUrl($clientId)) {
            $client = self::find($clientId);

            if ($client === null || $client['kind'] !== self::KIND_DCR) {
                return ['client' => null, 'error' => __('This AI app is not registered with this site.', 'mcpmate')];
            }

            return self::isRevoked($client) ? self::blocked() : ['client' => $client, 'error' => ''];
        }

        $cached = self::find($clientId);

        if ($cached !== null && self::isRevoked($cached)) {
            return self::blocked();
        }

        if ($cached !== null && $cached['kind'] === self::KIND_CIMD && strtotime((string) $cached['cache_until'] . ' UTC') > time()) {
            return ['client' => $cached, 'error' => ''];
        }

        $fetched = ClientMetadata::fetch($clientId);

        if ($fetched['error'] !== '') {
            return ['client' => null, 'error' => $fetched['error']];
        }

        $client = self::saveDocument($clientId, $fetched['metadata'], $fetched['cache_seconds']);

        return $client !== null
            ? ['client' => $client, 'error' => '']
            : ['client' => null, 'error' => __('The AI app\'s details could not be saved.', 'mcpmate')];
    }

    /**
     * Every client on record, live ones first, newest first, formatted for
     * Connections → Registered clients.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $table  = self::table();
        $grants = Grants::table();
        $rows   = DB::getResults(
            "SELECT c.client_key, c.client_id, c.kind, c.client_name, c.client_uri, c.redirect_uris, c.created_at, c.last_used_at, c.revoked_at,
                (SELECT COUNT(*) FROM `{$grants}` g WHERE g.client_key = c.client_key AND g.status <> %s) AS live_grants,
                (SELECT COUNT(*) FROM `{$grants}` g WHERE g.client_key = c.client_key) AS all_grants
             FROM `{$table}` c ORDER BY (c.revoked_at IS NULL) DESC, c.created_at DESC LIMIT %d",
            [Grants::STATUS_REVOKED, self::LIST_LIMIT],
            ARRAY_A
        );

        return array_map([self::class, 'format'], $rows);
    }

    /**
     * @param array<string, mixed> $row A client row, with `live_grants` and `all_grants` when listed.
     * @return array<string, mixed>
     */
    public static function format(array $row): array
    {
        $uris  = is_array($row['redirect_uris']) ? $row['redirect_uris'] : json_decode((string) $row['redirect_uris'], true);
        $hosts = [];

        foreach (is_array($uris) ? $uris : [] as $uri) {
            $host = is_string($uri) ? RedirectUris::host($uri) : '';
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        $live = (int) ($row['live_grants'] ?? 0);

        if (self::isRevoked($row)) {
            $status = 'revoked';
        } elseif ($live > 0) {
            $status = 'connected';
        } elseif ((int) ($row['all_grants'] ?? 0) === 0 && $row['last_used_at'] === null) {
            $status = 'unused';
        } else {
            $status = 'not_connected';
        }

        return [
            'client_key'     => (string) $row['client_key'],
            'client_id'      => (string) $row['client_id'],
            'kind'           => (string) $row['kind'],
            'client_name'    => (string) $row['client_name'],
            'verified_host'  => $row['kind'] === self::KIND_CIMD ? strtolower((string) wp_parse_url((string) $row['client_id'], PHP_URL_HOST)) : null,
            'redirect_hosts' => array_values(array_unique($hosts)),
            'live_grants'    => $live,
            'status'         => $status,
            'created_at'     => self::iso($row['created_at']),
            'last_used_at'   => self::iso($row['last_used_at']),
            'revoked_at'     => self::iso($row['revoked_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $client
     */
    public static function isRevoked(array $client): bool
    {
        return isset($client['revoked_at']) && is_string($client['revoked_at']) && $client['revoked_at'] !== '';
    }

    public static function isClientKey(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/D', $value);
    }

    public static function countDcr(): int
    {
        $table = self::table();

        return (int) DB::getVar("SELECT COUNT(*) FROM `{$table}` WHERE kind = %s", [self::KIND_DCR]);
    }

    /**
     * How the approval screen and the grant name the client's vouching:
     * `cimd:<host>` (its metadata document's host) or `dcr` (not verified).
     *
     * @param array<string, mixed> $client
     */
    public static function vouchedBy(array $client): string
    {
        if ($client['kind'] !== self::KIND_CIMD) {
            return self::KIND_DCR;
        }

        return 'cimd:' . strtolower((string) wp_parse_url((string) $client['client_id'], PHP_URL_HOST));
    }

    /**
     * Whether $secret is the client's secret. Public clients have none.
     *
     * @param array<string, mixed> $client
     */
    public static function checkSecret(array $client, string $secret): bool
    {
        return is_string($client['secret_hash']) && $client['secret_hash'] !== '' && $secret !== ''
            && hash_equals((string) $client['secret_hash'], hash('sha256', $secret));
    }

    // ──────────────────────────────────────────────
    //  Writes
    // ──────────────────────────────────────────────

    /**
     * Store a self-registered client (metadata already checked).
     *
     * @param array{client_id: string, client_name: string, client_uri: string, redirect_uris: string[], token_endpoint_auth_method: string} $metadata
     * @return array<string, mixed>|null
     */
    public static function createDcr(array $metadata, ?string $secret): ?array
    {
        $inserted = DB::insert(
            self::table(),
            [
                'client_key'    => self::key($metadata['client_id']),
                'client_id'     => $metadata['client_id'],
                'kind'          => self::KIND_DCR,
                'client_name'   => $metadata['client_name'],
                'client_uri'    => $metadata['client_uri'],
                'redirect_uris' => (string) wp_json_encode($metadata['redirect_uris'], JSON_UNESCAPED_SLASHES),
                'auth_method'   => $metadata['token_endpoint_auth_method'],
                'secret_hash'   => $secret !== null ? hash('sha256', $secret) : '',
                'metadata_json' => (string) wp_json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $inserted === false ? null : self::findByKey(self::key($metadata['client_id']));
    }

    /**
     * Store or refresh a fetched metadata document (already checked).
     *
     * @param array{client_id: string, client_name: string, client_uri: string, redirect_uris: string[]} $metadata
     * @return array<string, mixed>|null
     */
    public static function saveDocument(string $url, array $metadata, int $cacheSeconds): ?array
    {
        $table = self::table();
        $now   = time();

        DB::query(
            "INSERT INTO `{$table}` (client_key, client_id, kind, client_name, client_uri, redirect_uris, auth_method, secret_hash, metadata_json, fetched_at, cache_until, created_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, '', %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE client_name = VALUES(client_name), client_uri = VALUES(client_uri), redirect_uris = VALUES(redirect_uris),
                 metadata_json = VALUES(metadata_json), fetched_at = VALUES(fetched_at), cache_until = VALUES(cache_until)",
            [
                self::key($url),
                $url,
                self::KIND_CIMD,
                $metadata['client_name'],
                $metadata['client_uri'],
                (string) wp_json_encode($metadata['redirect_uris'], JSON_UNESCAPED_SLASHES),
                self::AUTH_NONE,
                (string) wp_json_encode($metadata, JSON_UNESCAPED_SLASHES),
                gmdate('Y-m-d H:i:s', $now),
                gmdate('Y-m-d H:i:s', $now + $cacheSeconds),
                gmdate('Y-m-d H:i:s', $now),
            ]
        );

        return self::find($url);
    }

    /**
     * Refuse the client from now on (a site admin, Registered clients). The
     * caller revokes its grants (GrantRevoker::revokeClient()).
     *
     * @return bool True only when this call revoked it.
     */
    public static function revoke(string $clientKey): bool
    {
        $table = self::table();

        return DB::query(
            "UPDATE `{$table}` SET revoked_at = %s WHERE client_key = %s AND revoked_at IS NULL",
            [gmdate('Y-m-d H:i:s'), $clientKey]
        ) === 1;
    }

    /**
     * Allow a revoked client again. Its grants stay revoked: the app is
     * approved again like a new one.
     *
     * @return bool True only when this call allowed it.
     */
    public static function restore(string $clientKey): bool
    {
        $table = self::table();

        return DB::query(
            "UPDATE `{$table}` SET revoked_at = NULL WHERE client_key = %s AND revoked_at IS NOT NULL",
            [$clientKey]
        ) === 1;
    }

    /**
     * The client was approved or exchanged a token: it is in use. At most
     * once a minute.
     */
    public static function touch(string $clientKey): void
    {
        $table = self::table();

        DB::query(
            "UPDATE `{$table}` SET last_used_at = %s WHERE client_key = %s AND (last_used_at IS NULL OR last_used_at < %s)",
            [gmdate('Y-m-d H:i:s'), $clientKey, gmdate('Y-m-d H:i:s', time() - MINUTE_IN_SECONDS)]
        );
    }

    /**
     * Daily cron: delete clients nobody approved (never used, no grant,
     * older than UNUSED_TTL, not revoked), and self-registered clients
     * revoked more than REVOKED_DAYS ago.
     *
     * @return int Rows deleted.
     */
    public static function purge(): int
    {
        $table   = self::table();
        $grants  = Grants::table();
        $deleted = DB::query(
            "DELETE FROM `{$table}` WHERE (revoked_at IS NULL AND last_used_at IS NULL AND created_at < %s
                    AND NOT EXISTS (SELECT 1 FROM `{$grants}` g WHERE g.client_key = `{$table}`.client_key))
                OR (kind = %s AND revoked_at < %s)",
            [
                gmdate('Y-m-d H:i:s', time() - self::UNUSED_TTL),
                self::KIND_DCR,
                gmdate('Y-m-d H:i:s', time() - self::REVOKED_DAYS * DAY_IN_SECONDS),
            ]
        );

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @return array{client: null, error: string}
     */
    private static function blocked(): array
    {
        return ['client' => null, 'error' => __('A site admin revoked this AI app on this site.', 'mcpmate')];
    }

    /**
     * @param mixed $datetime
     */
    private static function iso($datetime): ?string
    {
        return is_string($datetime) && $datetime !== '' ? str_replace(' ', 'T', $datetime) . 'Z' : null;
    }
}
