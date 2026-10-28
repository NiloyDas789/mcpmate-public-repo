<?php

namespace McpMate\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates a remote URL before the plugin downloads from it (SSRF guard).
 *
 * Accepted: an absolute http(s) URL on port 80 or 443 whose host is a
 * dotted DNS name or an IP literal, without credentials, whitespace, control
 * characters or backslashes. Every address the host resolves to must be
 * public: one private, loopback, link-local, CGNAT, documentation,
 * multicast or reserved answer rejects them all, and IPv4-mapped IPv6
 * addresses are judged by their IPv4 part. Numeric host spellings that some
 * resolvers read as IPv4 (`2130706433`, `0x7f.1`, `0177.0.0.1`, `127.1`) are
 * rejected outright.
 *
 * The result carries the addresses that were checked, so the fetcher can pin
 * the connection to them (MediaFetcher) and DNS cannot answer differently
 * between the check and the connection.
 */
final class RemoteUrl
{
    public const ERROR_INVALID = 'invalid_url';

    public const ERROR_SCHEME = 'scheme_not_allowed';

    public const ERROR_PORT = 'port_not_allowed';

    public const ERROR_UNRESOLVED = 'unresolved';

    public const ERROR_BLOCKED = 'blocked_address';

    public const MAX_LENGTH = 2048;

    public const ALLOWED_PORTS = [80, 443];

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** Special-purpose IPv4 ranges (IANA registry); none of them is a public host. */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** Special-purpose IPv6 ranges. NAT64 and 6to4 can embed any IPv4 address, private ones included. */
    private const BLOCKED_V6 = [
        '::/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /**
     * @param callable|null $resolver fn(string $host): string[] — the host's
     *                                addresses. Defaults to DNS (A and AAAA).
     * @return array{url: string, host: string, port: int, addresses: string[], error: string}
     *         `error` is one of the ERROR_* codes, or '' on success.
     */
    public static function resolve(string $input, ?callable $resolver = null): array
    {
        $input = trim($input);

        if ($input === '' || strlen($input) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F\\\\]/', $input)) {
            return self::fail(self::ERROR_INVALID);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, also used without WordPress; the input is checked above.
        $parts = parse_url($input);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return self::fail(self::ERROR_INVALID);
        }

        $scheme = strtolower($parts['scheme']);

        if (!isset(self::DEFAULT_PORTS[$scheme])) {
            return self::fail(self::ERROR_SCHEME);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::fail(self::ERROR_INVALID);
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : self::DEFAULT_PORTS[$scheme];

        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            return self::fail(self::ERROR_PORT);
        }

        $host    = rtrim(strtolower($parts['host']), '.');
        $literal = (string) preg_replace('/^\[(.*)\]$/', '$1', $host);

        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            $addresses = [$literal];
        } elseif (self::isHostname($host)) {
            $addresses = $resolver !== null ? (array) $resolver($host) : self::lookup($host);
            $addresses = array_values(array_unique(array_filter($addresses, 'is_string')));

            if ($addresses === []) {
                return self::fail(self::ERROR_UNRESOLVED);
            }
        } else {
            return self::fail(self::ERROR_INVALID);
        }

        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                return self::fail(self::ERROR_BLOCKED);
            }
        }

        $authority = strpos($literal, ':') !== false ? '[' . $literal . ']' : $host;
        $url       = $scheme . '://' . $authority;

        if (isset($parts['port'])) {
            $url .= ':' . $port;
        }

        $url .= isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }

        return ['url' => $url, 'host' => $literal, 'port' => $port, 'addresses' => $addresses, 'error' => ''];
    }

    /**
     * True for a syntactically valid IPv4 or IPv6 address outside every
     * blocked range. Anything that is not an IP address is not public.
     */
    public static function isPublicAddress(string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }

        $binary = inet_pton($address);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d): judged as the IPv4 address it reaches.
            if (substr($binary, 0, 12) === str_repeat("\0", 10) . "\xFF\xFF") {
                $binary = (string) substr($binary, 12);
            } else {
                return !self::inRanges($binary, self::BLOCKED_V6);
            }
        }

        return !self::inRanges($binary, self::BLOCKED_V4);
    }

    /**
     * The URL without its query string and fragment, for messages and the
     * audit log: signed URLs carry their credentials in the query.
     */
    public static function redactQuery(string $url): string
    {
        $cut = strcspn($url, '?#');

        return $cut < strlen($url) ? substr($url, 0, $cut) . '?[redacted]' : $url;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * A dotted DNS name of ASCII labels. Single labels (`localhost`,
     * `intranet`) and all-numeric or hex spellings of IPv4 are not names.
     */
    private static function isHostname(string $host): bool
    {
        if (strlen($host) > 253 || strpos($host, '.') === false) {
            return false;
        }

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        if (!preg_match('/^(?:' . $label . '\.)+' . $label . '$/D', $host)) {
            return false;
        }

        // `127.1`, `0177.0.0.1`, `0x7f.0.0.1`: inet_aton() reads these as addresses.
        return !preg_match('/^(?:0x[0-9a-f]*|[0-9]+)(?:\.(?:0x[0-9a-f]*|[0-9]+))*$/D', $host);
    }

    /**
     * The host's A and AAAA records.
     *
     * @return string[]
     */
    private static function lookup(string $host): array
    {
        $addresses = gethostbynamel($host . '.');
        $addresses = is_array($addresses) ? $addresses : [];

        if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
            $records = @dns_get_record($host . '.', DNS_AAAA); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup only means no AAAA records.

            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return $addresses;
    }

    /**
     * @param string[] $ranges CIDR blocks of the same family as $binary.
     */
    private static function inRanges(string $binary, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network, $prefix] = explode('/', $range);
            $net                = inet_pton($network);

            if ($net === false || strlen($net) !== strlen($binary)) {
                continue;
            }

            $prefix = (int) $prefix;
            $bytes  = intdiv($prefix, 8);
            $bits   = $prefix % 8;

            if (substr($binary, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }

            if ($bits === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $bits)) & 0xFF;

            if ((ord($binary[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{url: string, host: string, port: int, addresses: string[], error: string}
     */
    private static function fail(string $error): array
    {
        return ['url' => '', 'host' => '', 'port' => 0, 'addresses' => [], 'error' => $error];
    }
}
