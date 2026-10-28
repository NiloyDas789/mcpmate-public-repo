<?php

namespace McpMate\HTTP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTTP response builder for AJAX and REST API responses.
 *
 * Supports both WordPress AJAX (wp_send_json_success/error) and
 * REST API (WP_REST_Response) contexts.
 *
 * Error bodies carry a stable machine-readable `code` next to the human
 * `message`. REST errors use the WP_Error shape: {code, message, data: {status}}.
 */
final class Response
{
    private array $data = [];
    private string $message = '';
    private bool $success = true;
    private int $statusCode = 200;
    private string $code = '';
    private string $hint = '';

    /** @var array<string, scalar> Extra fields for an AJAX error body. */
    private array $details = [];

    public const DEFAULT_ERROR_CODE = 'mcpmate_error';

    private function __construct()
    {
    }

    /**
     * Create a success response.
     *
     * @return static
     */
    public static function success(array $data = [])
    {
        $response          = new static();
        $response->success = true;
        $response->data    = $data;

        return $response;
    }

    /**
     * Create an error response.
     *
     * @param string $code Machine-readable error code, e.g. `mcpmate_invalid_status`.
     * @return static
     */
    public static function error(string $message, int $statusCode = 400, string $code = self::DEFAULT_ERROR_CODE)
    {
        $response             = new static();
        $response->success    = false;
        $response->message    = $message;
        $response->statusCode = $statusCode;
        $response->code       = $code;

        return $response;
    }

    /**
     * Set the HTTP status code (chainable).
     *
     * @return static
     */
    public function httpStatus(int $code)
    {
        $this->statusCode = $code;

        return $this;
    }

    /**
     * Attach a short, actionable hint to an error (chainable).
     *
     * REST error bodies carry it as `data.hint`; the REST envelope exposes it
     * as `error.hint`.
     *
     * @return static
     */
    public function withHint(string $hint)
    {
        $this->hint = $hint;

        return $this;
    }

    /**
     * Extra fields for an admin-ajax error body, next to `code` and
     * `message` (chainable). Never secrets.
     *
     * @param array<string, scalar|array<string, string>> $details
     * @return static
     */
    public function withDetails(array $details)
    {
        $this->details = $details;

        return $this;
    }

    /**
     * Send the response.
     *
     * For REST API requests: returns a WP_REST_Response.
     * For AJAX requests: sends JSON via wp_send_json_* and exits.
     */
    public function send(): void
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            // For REST context, this should not be called directly —
            // the router wraps the response. We still send valid JSON.
            if ($this->success) {
                wp_send_json($this->data, $this->statusCode);
            } else {
                wp_send_json($this->errorBody(), $this->statusCode);
            }
        }

        if ($this->statusCode !== 200) {
            status_header($this->statusCode);
        }

        if ($this->success) {
            wp_send_json_success($this->data);
        } else {
            wp_send_json_error(['code' => $this->code, 'message' => $this->message] + $this->details, $this->statusCode);
        }
    }

    /**
     * Convert to a WP_REST_Response (used by the REST router).
     */
    public function toRestResponse(): \WP_REST_Response
    {
        if ($this->success) {
            return new \WP_REST_Response($this->data, $this->statusCode ?: 200);
        }

        return new \WP_REST_Response($this->errorBody(), $this->statusCode ?: 400);
    }

    /**
     * WP_Error-shaped error body for REST responses.
     *
     * @return array{code: string, message: string, data: array{status: int, hint?: string}}
     */
    private function errorBody(): array
    {
        $data = ['status' => $this->statusCode ?: 400];

        if ($this->hint !== '') {
            $data['hint'] = $this->hint;
        }

        return [
            'code'    => $this->code,
            'message' => $this->message,
            'data'    => $data,
        ];
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getHint(): string
    {
        return $this->hint;
    }
}
