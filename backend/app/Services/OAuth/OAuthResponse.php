<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What an OAuth endpoint answers: JSON, a redirect, an error page or an
 * empty body. Built by the endpoints, sent by OAuthRoutes, read by tests.
 *
 * Every response carries the no-cache headers of docs/mcp-in-plugin.md
 * §4.8, so no page cache or CDN serves one to anybody else.
 */
final class OAuthResponse
{
    /** §4.8: every OAuth response, whatever cache sits in front of the site. */
    public const NO_CACHE_HEADERS = [
        'Cache-Control'             => 'no-store, private',
        'Pragma'                    => 'no-cache',
        'Vary'                      => 'Authorization, Origin',
        'CDN-Cache-Control'         => 'no-store',
        'Surrogate-Control'         => 'no-store',
        'X-LiteSpeed-Cache-Control' => 'no-cache',
    ];

    /** Discovery, register, token and revoke: readable by browser-based clients, never with cookies. */
    public const CORS_HEADERS = [
        'Access-Control-Allow-Origin'  => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, MCP-Protocol-Version',
    ];

    private int $status;

    /** @var array<string, string> */
    private array $headers;

    private string $body;

    /**
     * @param array<string, string> $headers
     */
    private function __construct(int $status, array $headers, string $body)
    {
        $this->status  = $status;
        $this->headers = $headers + self::NO_CACHE_HEADERS;
        $this->body    = $body;
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): self
    {
        return new self($status, $headers + ['Content-Type' => 'application/json'], (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * A body already encoded (the MCP endpoint's JSON-RPC answers).
     *
     * @param array<string, string> $headers
     */
    public static function raw(int $status, string $body, array $headers = []): self
    {
        return new self($status, $headers, $body);
    }

    /**
     * An OAuth error body (RFC 6749 §5.2): `{error, error_description}`.
     *
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $error, string $description, array $headers = []): self
    {
        return self::json($status, ['error' => $error, 'error_description' => $description], $headers);
    }

    public static function redirect(string $url): self
    {
        return new self(302, ['Location' => $url], '');
    }

    /**
     * A plain error page, for authorize errors that must not redirect (the
     * client or its redirect URI is not known to be good yet).
     */
    public static function page(int $status, string $error, string $message): self
    {
        $title = __('This connection request can\'t be used', 'mcpmate');
        $html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . esc_html($title) . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;line-height:1.5">'
            . '<h1 style="font-size:1.5rem">' . esc_html($title) . '</h1>'
            . '<p>' . esc_html($message) . '</p>'
            . '<p>' . esc_html__('Start connecting again from your AI app.', 'mcpmate') . '</p>'
            . '<p style="color:#646970"><code>' . esc_html($error) . '</code></p></body></html>';

        return new self($status, [
            'Content-Type'            => 'text/html; charset=utf-8',
            'X-Frame-Options'         => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'",
            'Referrer-Policy'         => 'no-referrer',
        ], $html);
    }

    public static function empty(int $status = 200): self
    {
        return new self($status, [], '');
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $copy          = clone $this;
        $copy->headers = $headers + $this->headers;

        return $copy;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $name): string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * The JSON body decoded, or null.
     *
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            status_header($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode(), or a page whose every value went through esc_html().
        echo $this->body;
    }
}
