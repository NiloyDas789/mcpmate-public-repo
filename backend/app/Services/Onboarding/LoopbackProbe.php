<?php

namespace McpMate\Services\Onboarding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The site's requests to itself, for the setup checks (docs/mcp-in-plugin.md
 * §10): the MCP URL without a token, the Authorization header probe and the
 * discovery documents, all at this site's public address (its tunnel when
 * MCPMATE_PUBLIC_URL is set), the way AI apps reach it.
 *
 * One probe is one run of the checks, with a time budget. After the first
 * request that cannot connect (many hosts block loopback), every later one
 * answers the same error at once, so a blocked host costs one timeout, not
 * one per request. No cookies are sent and redirects are not followed;
 * certificate checks follow `https_local_ssl_verify`, as Site Health's
 * loopback does.
 */
final class LoopbackProbe
{
    /** Seconds one request may take. */
    public const REQUEST_TIMEOUT = 8;

    /** Error code of a request skipped because the budget is spent. */
    public const OUT_OF_TIME = 'mcpmate_loopback_budget';

    private float $deadline;

    private string $failed = '';

    public function __construct(int $budgetSeconds)
    {
        $this->deadline = microtime(true) + $budgetSeconds;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $form A form-encoded body (POST).
     * @return array{error: string, status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $url, array $headers = [], array $form = [], string $json = ''): array
    {
        if ($this->failed !== '') {
            return self::failure($this->failed);
        }

        $left = $this->deadline - microtime(true);

        if ($left < 1) {
            return self::failure(self::OUT_OF_TIME);
        }

        $args = [
            'method'              => $method,
            'headers'             => $headers,
            'cookies'             => [],
            'timeout'             => min(self::REQUEST_TIMEOUT, (int) floor($left)),
            'redirection'         => 0,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, as WP_Site_Health::can_perform_loopback() uses it.
            'sslverify'           => (bool) apply_filters('https_local_ssl_verify', false),
            'limit_response_size' => 65536,
        ];

        if ($form !== []) {
            $args['body'] = $form;
        } elseif ($json !== '') {
            $args['body']                    = $json;
            $args['headers']['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->failed = $response->get_error_code() ?: 'http_request_failed';

            return self::failure($this->failed);
        }

        $answer = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $answer[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return [
            'error'   => '',
            'status'  => (int) wp_remote_retrieve_response_code($response),
            'headers' => $answer,
            'body'    => (string) wp_remote_retrieve_body($response),
        ];
    }

    /**
     * @return array{error: string, status: int, headers: array<string, string>, body: string}
     */
    private static function failure(string $code): array
    {
        return ['error' => $code, 'status' => 0, 'headers' => [], 'body' => ''];
    }
}
