<?php

namespace McpMate\HTTP\Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTTP request abstraction.
 *
 * AJAX requests merge POST, GET, and JSON body into a single data store.
 * REST requests are built with fromRest(), which uses the WP_REST_Request
 * params (already validated/sanitized by the route's `args` schema).
 * Provides get(), has(), and magic property access for convenience.
 */
final class Request
{
    private array $data;

    private ?\WP_REST_Request $restRequest = null;

    /**
     * @param array<string, mixed>|null $data Request data; null reads the superglobals.
     */
    public function __construct(?array $data = null)
    {
        if ($data !== null) {
            $this->data = $data;

            return;
        }

        // WordPress applies wp_magic_quotes() to $_POST/$_GET, adding slashes.
        // wp_unslash() removes those before we store or JSON-decode any values.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Nonce/capability checks run in middleware before controller dispatch.
        $this->data = array_merge(
            wp_unslash($_GET),
            wp_unslash($_POST)
        );
        // phpcs:enable

        // Also try to decode JSON body (for REST API requests)
        $contentType = isset($_SERVER['CONTENT_TYPE'])
            ? sanitize_text_field(wp_unslash($_SERVER['CONTENT_TYPE']))
            : '';
        if (str_contains($contentType, 'application/json')) {
            $rawBody = file_get_contents('php://input');
            if ($rawBody) {
                $json = json_decode($rawBody, true);
                if (is_array($json)) {
                    $this->data = array_merge($this->data, $json);
                }
            }
        }
    }

    /**
     * Build a Request from a WP_REST_Request (URL, query, body and JSON params).
     */
    public static function fromRest(\WP_REST_Request $wpRequest): self
    {
        $request              = new self($wpRequest->get_params());
        $request->restRequest = $wpRequest;

        return $request;
    }

    /**
     * The underlying WP_REST_Request, or null for AJAX requests.
     */
    public function restRequest(): ?\WP_REST_Request
    {
        return $this->restRequest;
    }

    /**
     * Get a value from the request data.
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Check if a key exists in the request data.
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]) && $this->data[$key] !== '';
    }

    /**
     * Get all request data.
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Magic property getter — allows $request->_ajax_nonce syntax.
     *
     * @return mixed
     */
    public function __get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Magic isset check.
     */
    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }
}
