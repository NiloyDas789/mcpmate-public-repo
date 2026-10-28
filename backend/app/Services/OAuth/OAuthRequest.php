<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One request to an OAuth endpoint, read once from PHP's globals
 * (fromGlobals()) or built by a test.
 *
 * Values are kept exactly as sent (only unslashed): codes, verifiers and
 * redirect URIs must compare byte for byte, so each endpoint validates its
 * own fields against strict patterns instead of sanitizing them.
 */
final class OAuthRequest
{
    public string $method;

    /** @var array<string, mixed> */
    public array $query;

    /** @var array<string, mixed> Form fields (application/x-www-form-urlencoded). */
    public array $form;

    public string $body;

    /** @var array<string, string> Lowercase names. */
    public array $headers;

    public string $ip;

    public bool $secure;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $form
     * @param array<string, string> $headers
     */
    public function __construct(string $method, array $query = [], array $form = [], array $headers = [], string $body = '', string $ip = '', bool $secure = true)
    {
        $this->method  = strtoupper($method);
        $this->query   = $query;
        $this->form    = $form;
        $this->body    = $body;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->ip      = $ip;
        $this->secure  = $secure;
    }

    public static function fromGlobals(): self
    {
        // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- OAuth requests come from AI apps, not wp-admin forms; each endpoint validates every field it reads against a strict pattern.
        $query  = (array) wp_unslash($_GET);
        $form   = (array) wp_unslash($_POST);
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) wp_unslash($_SERVER['REQUEST_METHOD']) : 'GET';
        $ip     = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        // phpcs:enable

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (is_string($name) && is_string($value) && strpos($name, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', (string) substr($name, 5)))] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = sanitize_text_field(wp_unslash($_SERVER['CONTENT_TYPE']));
        }

        $authorization = BearerAuth::authorizationHeader();
        if ($authorization !== '') {
            $headers['authorization'] = $authorization;
        }

        $body = '';
        if ($method === 'POST' && stripos($headers['content-type'] ?? '', 'application/json') === 0) {
            $body = (string) file_get_contents('php://input', false, null, 0, ClientRegistration::MAX_BODY_BYTES + 1);
        }

        return new self($method, $query, $form, $headers, $body, filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '', is_ssl());
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * A query parameter as a string; '' when missing or not a string.
     */
    public function queryValue(string $name): string
    {
        return isset($this->query[$name]) && is_string($this->query[$name]) ? $this->query[$name] : '';
    }

    /**
     * A form field as a string; '' when missing or not a string.
     */
    public function formValue(string $name): string
    {
        return isset($this->form[$name]) && is_string($this->form[$name]) ? $this->form[$name] : '';
    }

    /**
     * The JSON body as an object, or null.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) && ($decoded === [] || array_keys($decoded) !== range(0, count($decoded) - 1)) ? $decoded : null;
    }
}
