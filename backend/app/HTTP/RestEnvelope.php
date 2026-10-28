<?php

namespace McpMate\HTTP;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Hooks\Hooks;
use McpMate\HTTP\Controllers\Api\RestController;
use McpMate\Services\Mcp\McpContext;

/**
 * The JSON envelope for every response under the MCP namespace (REST_NS).
 *
 *   { "ok": true,  "data": {...}, "error": null,                             "request_id": "…", "truncated": false }
 *   { "ok": false, "data": null,  "error": {"code", "message", "hint"},      "request_id": "…", "truncated": false }
 *
 * The envelope is applied by REST filters rather than by controllers, so core
 * errors that never reach a controller (400 invalid params, 401/403 from the
 * permission_callback, refused Basic credentials) get the same shape.
 * The request ID is also sent as the `X-Request-Id` header, and `data` is cut
 * down to fit MAX_BYTES of JSON, with `truncated: true` when that happens.
 */
final class RestEnvelope
{
    /** Hard cap on the JSON-encoded envelope, in bytes (256 KiB). */
    public const MAX_BYTES = 262144;

    public const REQUEST_ID_HEADER = 'X-Request-Id';

    /**
     * Request IDs handed out before the response is enveloped, keyed by request.
     *
     * @var \SplObjectStorage<\WP_REST_Request, string>|null
     */
    private static ?\SplObjectStorage $requestIds = null;

    // ──────────────────────────────────────────────
    //  Envelope builders
    // ──────────────────────────────────────────────

    /**
     * Success envelope, with `data` truncated to fit $maxBytes.
     *
     * @param mixed $data
     * @return array{ok: true, data: mixed, error: null, request_id: string, truncated: bool}
     */
    public static function success($data, string $requestId, int $maxBytes = self::MAX_BYTES): array
    {
        $envelope = [
            'ok'         => true,
            'data'       => $data,
            'error'      => null,
            'request_id' => $requestId,
            'truncated'  => false,
        ];

        if (self::jsonSize($envelope) <= $maxBytes) {
            return $envelope;
        }

        $envelope['data']      = null;
        $envelope['truncated'] = true;

        // Room left for `data` once the rest of the envelope is counted (minus the `null` placeholder).
        $budget = $maxBytes - (self::jsonSize($envelope) - 4);

        $envelope['data'] = self::shrink(self::normalize($data), $budget);

        return $envelope;
    }

    /**
     * Error envelope. An empty hint is sent as null.
     *
     * @return array{ok: false, data: null, error: array{code: string, message: string, hint: ?string}, request_id: string, truncated: false}
     */
    public static function failure(string $code, string $message, string $hint, string $requestId): array
    {
        return [
            'ok'         => false,
            'data'       => null,
            'error'      => [
                'code'    => $code,
                'message' => $message,
                'hint'    => $hint !== '' ? $hint : null,
            ],
            'request_id' => $requestId,
            'truncated'  => false,
        ];
    }

    public static function newRequestId(): string
    {
        return wp_generate_uuid4();
    }

    /**
     * The ID this request's envelope and `X-Request-Id` header will carry.
     * Code that runs inside the request (the audit log) uses it so its
     * records match what the client saw.
     */
    public static function requestId(\WP_REST_Request $request): string
    {
        if (self::$requestIds === null) {
            self::$requestIds = new \SplObjectStorage();
        }

        if (!self::$requestIds->contains($request)) {
            // Every route one MCP call reaches shares that call's ID, so its audit and change rows line up.
            $incoming                   = McpContext::requestId();
            self::$requestIds[$request] = $incoming !== '' ? $incoming : self::newRequestId();
        }

        return self::$requestIds[$request];
    }

    /**
     * Whether a REST route belongs to the MCP namespace (the namespace index itself is core's).
     */
    public static function isOwnRoute(string $route): bool
    {
        return str_starts_with($route, '/' . RestController::NAMESPACE . '/');
    }

    // ──────────────────────────────────────────────
    //  WordPress REST filters
    // ──────────────────────────────────────────────

    public static function register(): void
    {
        Hooks::addFilter('rest_request_after_callbacks', [self::class, 'wrapHandlerResult'], 10, 3);
        Hooks::addFilter('rest_post_dispatch', [self::class, 'wrapUnhandledError'], 10, 3);
    }

    /**
     * Envelope the result of a matched route: the controller response, or the
     * WP_Error from arg validation or the permission_callback. Runs for HTTP
     * requests and for internal rest_do_request() calls alike.
     *
     * @param mixed $response
     * @param mixed $handler
     * @return mixed
     */
    public static function wrapHandlerResult($response, $handler, \WP_REST_Request $request)
    {
        if (!self::isOwnRoute($request->get_route())) {
            self::forgetRequestId($request);

            return $response;
        }

        return self::toRestResponse($response, self::takeRequestId($request));
    }

    /**
     * Envelope errors raised before a route was matched, such as a failed
     * Basic-credentials check or an unknown route under the namespace.
     *
     * @param mixed $response
     * @param mixed $server
     * @return mixed
     */
    public static function wrapUnhandledError($response, $server, \WP_REST_Request $request)
    {
        if (
            !$response instanceof \WP_REST_Response
            || $response->get_status() < 400
            || isset($response->get_headers()[self::REQUEST_ID_HEADER])
            || !self::isOwnRoute($request->get_route())
        ) {
            return $response;
        }

        return self::toRestResponse($response, self::takeRequestId($request));
    }

    /**
     * The request's ID, released: the response is final once it is enveloped.
     */
    private static function takeRequestId(\WP_REST_Request $request): string
    {
        $requestId = self::requestId($request);
        self::forgetRequestId($request);

        return $requestId;
    }

    private static function forgetRequestId(\WP_REST_Request $request): void
    {
        if (self::$requestIds !== null) {
            self::$requestIds->detach($request);
        }
    }

    /**
     * @param mixed $result WP_Error, WP_REST_Response or raw data.
     */
    private static function toRestResponse($result, string $requestId): \WP_REST_Response
    {
        if (is_wp_error($result)) {
            $data     = $result->get_error_data();
            $body     = [
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'data'    => $data,
            ];
            $status   = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            $response = new \WP_REST_Response(null, $status);
        } else {
            $response = rest_ensure_response($result);
            $body     = $response->get_data();
            $status   = $response->get_status();
        }

        if ($status >= 400) {
            [$code, $message, $hint] = self::errorFields($body, $status);
            $response->set_data(self::failure($code, $message, $hint, $requestId));
        } else {
            $response->set_data(self::success($body, $requestId));
        }

        $response->header(self::REQUEST_ID_HEADER, $requestId);

        return $response;
    }

    /**
     * Pull code, message and hint out of a WP_Error-shaped body. Everything
     * else in it (`data.params`, `additional_errors`, …) is dropped.
     *
     * @param mixed $body
     * @return array{0: string, 1: string, 2: string}
     */
    private static function errorFields($body, int $status): array
    {
        $body = is_array($body) ? $body : [];
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

        $code    = isset($body['code']) && is_string($body['code']) && $body['code'] !== ''
            ? $body['code']
            : Response::DEFAULT_ERROR_CODE;
        $message = isset($body['message']) && is_string($body['message']) && $body['message'] !== ''
            ? $body['message']
            : __('The request could not be completed.', 'mcpmate');

        return [$code, $message, self::hintFor($data, $status)];
    }

    /**
     * @param array<string, mixed> $data WP_Error data.
     */
    private static function hintFor(array $data, int $status): string
    {
        if (isset($data['hint']) && is_string($data['hint']) && $data['hint'] !== '') {
            return $data['hint'];
        }

        // rest_invalid_param: `params` maps each bad param to its reason.
        if (isset($data['params']) && is_array($data['params'])) {
            $reasons = [];

            foreach ($data['params'] as $param => $reason) {
                if (is_string($param) && is_string($reason)) {
                    $reasons[] = $reason;
                }
            }

            if ($reasons !== []) {
                return implode(' ', $reasons);
            }
        }

        if ($status === 401) {
            return __('Connect this site under McpMate → Connection, then add its MCP URL to your AI app.', 'mcpmate');
        }

        return '';
    }

    // ──────────────────────────────────────────────
    //  Size cap
    // ──────────────────────────────────────────────

    /**
     * Shrink a JSON-compatible value so its encoding fits $budget bytes.
     *
     * Lists keep their leading items; objects keep their keys and shrink the
     * largest member first (so pagination fields like `total` survive);
     * strings are cut. Returns null when nothing fits.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function shrink($value, int $budget)
    {
        if (self::jsonSize($value) <= $budget) {
            return $value;
        }

        if (is_string($value)) {
            return self::shrinkString($value, $budget);
        }

        if (is_array($value)) {
            return self::isList($value)
                ? self::shrinkList($value, $budget)
                : self::shrinkObject($value, $budget);
        }

        return null;
    }

    private static function shrinkString(string $value, int $budget): ?string
    {
        if ($budget < 2) {
            return null;
        }

        // Longest prefix (in characters, so UTF-8 stays valid) whose JSON fits.
        $low  = 0;
        $high = mb_strlen($value, 'UTF-8');

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);

            if (self::jsonSize(mb_substr($value, 0, $mid, 'UTF-8')) <= $budget) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return mb_substr($value, 0, $low, 'UTF-8');
    }

    /**
     * @param array<int, mixed> $value
     * @return array<int, mixed>|null
     */
    private static function shrinkList(array $value, int $budget): ?array
    {
        if ($budget < 2) {
            return null;
        }

        $kept = [];
        $used = 2; // []

        foreach ($value as $item) {
            $comma = $kept === [] ? 0 : 1;
            $size  = self::jsonSize($item);

            if ($used + $comma + $size <= $budget) {
                $kept[] = $item;
                $used  += $comma + $size;
                continue;
            }

            // Cut into an item only when it is the first one; otherwise keep whole items.
            if ($kept === []) {
                $shrunk = self::shrink($item, $budget - $used);

                if ($shrunk !== null) {
                    $kept[] = $shrunk;
                }
            }

            break;
        }

        return $kept;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>|null
     */
    private static function shrinkObject(array $value, int $budget): ?array
    {
        if ($budget < 2) {
            return null;
        }

        $sizes = [];
        foreach ($value as $key => $member) {
            $sizes[$key] = self::jsonSize($member);
        }

        // Each pass either fits the largest member into the room left, or drops it.
        while ($value !== []) {
            $total = self::objectSize($value, $sizes);

            if ($total <= $budget) {
                break;
            }

            $largest = array_keys($sizes, max($sizes), true)[0];
            $room    = $budget - ($total - $sizes[$largest]);
            $shrunk  = $room > 0 ? self::shrink($value[$largest], $room) : null;

            if ($shrunk === null) {
                unset($value[$largest], $sizes[$largest]);
                continue;
            }

            $value[$largest] = $shrunk;
            $sizes[$largest] = self::jsonSize($shrunk);
        }

        return $value;
    }

    /**
     * Encoded size of an object from its members' sizes: {"k":v,"k2":v2}.
     *
     * @param array<array-key, mixed> $value
     * @param array<array-key, int>   $sizes
     */
    private static function objectSize(array $value, array $sizes): int
    {
        $size = 2 + max(0, count($value) - 1);

        foreach (array_keys($value) as $key) {
            $size += self::jsonSize((string) $key) + 1 + $sizes[$key];
        }

        return $size;
    }

    /**
     * Turn objects (stdClass, JsonSerializable) into plain arrays and scalars.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize($value)
    {
        $json = wp_json_encode($value);

        return $json === false ? null : json_decode($json, true);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Bytes of JSON WordPress will send for $value (same encoder and flags).
     *
     * @param mixed $value
     */
    private static function jsonSize($value): int
    {
        $json = wp_json_encode($value);

        return $json === false ? PHP_INT_MAX : strlen($json);
    }
}
