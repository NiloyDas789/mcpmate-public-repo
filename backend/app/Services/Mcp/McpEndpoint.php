<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\Connection\AccessPause;
use McpMate\Services\Connection\Grants;
use McpMate\Services\Connection\RestGuard;
use McpMate\Services\OAuth\BearerAuth;
use McpMate\Services\OAuth\OAuthRequest;
use McpMate\Services\OAuth\OAuthResponse;
use McpMate\Services\OAuth\OAuthServer;
use McpMate\Services\OAuth\OAuthUrls;
use McpMate\Services\OAuth\RateLimit;
use McpMate\Services\Settings\Preferences;

/**
 * The MCP endpoint R (docs/mcp-in-plugin.md §3.3): one JSON-RPC message
 * per POST, answered with one application/json body, never a stream and
 * never a session.
 *
 * Order: CORS preflight and Origin (DNS rebinding), transport (rule 8), the
 * bearer token (401 with WWW-Authenticate), the grant's per-minute limit
 * (429), the HTTP method (405), the body size (413), then JSON-RPC. A
 * request whose `params._meta` names a protocol version is the stateless
 * 2026-07-28 revision (header cross-checks, `server/discover`); anything
 * else is served the 2025 way (`initialize` without a session id). Shapes
 * follow the SDK the cloud pinned (@modelcontextprotocol/server 2.4.0).
 *
 * tools/call runs the call behind the same guards the cloud had: the
 * grant's tool list, Pause AI access, the input schema, the policy layer
 * and the read-only scope, then the tool, whose routes keep every check of
 * their own (McpContext::dispatch()). Calls stopped before a route are
 * audited here (McpAudit).
 */
final class McpEndpoint
{
    public const MODERN_VERSION = '2026-07-28';

    /** 2025-era versions served statelessly; an unknown one negotiates the first. */
    public const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05', '2024-10-07'];

    public const SERVER_NAME = 'mcpmate';

    /** The SDK's default bound. */
    public const MAX_BODY_BYTES = 4194304;

    private const META_VERSION = 'io.modelcontextprotocol/protocolVersion';

    private const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

    private const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    /** Allowed requests per minute and grant when nothing is set. */
    public const DEFAULT_RATE_LIMIT = 120;

    private const ALLOW_HEADERS = 'Authorization, Content-Type, MCP-Protocol-Version, Mcp-Method, Mcp-Name, Mcp-Param-*';

    public static function handle(OAuthRequest $request): OAuthResponse
    {
        $origin = $request->header('origin');
        $cors   = $origin === '' ? [] : self::corsHeaders($origin);

        if ($origin !== '' && $cors === []) {
            return self::rpcError(403, null, -32000, 'Forbidden: this Origin may not use the MCP endpoint.');
        }

        if ($request->method === 'OPTIONS') {
            return OAuthResponse::empty(204)->withHeaders($cors);
        }

        $response = self::serve($request);
        McpContext::reset();

        return $response->withHeaders($cors);
    }

    private static function serve(OAuthRequest $request): OAuthResponse
    {
        if (!OAuthServer::transportAllowed($request)) {
            return OAuthResponse::error(400, 'insecure_transport', __('McpMate accepts connections only over HTTPS.', 'mcpmate'));
        }

        $authorization = $request->header('authorization');
        $auth          = BearerAuth::authenticate($authorization);

        if (!$auth['ok']) {
            $sent = stripos($authorization, 'bearer ') === 0;

            return OAuthResponse::error(401, 'invalid_token', $sent ? 'The access token is not valid for this site.' : 'An access token is required.', [
                'WWW-Authenticate' => BearerAuth::challenge($sent),
            ]);
        }

        wp_set_current_user($auth['user_id']);
        McpContext::begin($auth['grant'], $auth['scopes'], wp_generate_uuid4());

        $wait = RateLimit::hit('mcp_grant', McpContext::grantId(), self::rateLimit(), 60);
        if ($wait > 0) {
            McpAudit::rateLimited();

            return self::rpcError(429, null, -32000, 'Too many calls from this app: at most ' . self::rateLimit() . ' per minute. Wait about ' . $wait . ' seconds before the next call.', [
                'code'        => 'rate_limited',
                'retry_after' => $wait,
            ])->withHeaders(['Retry-After' => (string) $wait]);
        }

        if ($request->method !== 'POST') {
            return self::rpcError(405, null, -32000, 'Method not allowed.')->withHeaders(['Allow' => 'POST']);
        }

        if (strlen($request->body) > self::MAX_BODY_BYTES) {
            return self::rpcError(413, null, -32000, 'Payload Too Large: the request body is over 4 MiB.');
        }

        if (stripos($request->header('content-type'), 'application/json') !== 0) {
            return self::rpcError(415, null, -32000, 'Unsupported Media Type: Content-Type must be application/json.');
        }

        $message = json_decode($request->body, false, 512, JSON_BIGINT_AS_STRING);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::rpcError(400, null, -32700, 'Parse error: Invalid JSON');
        }

        if (is_array($message)) {
            return self::rpcError(400, null, -32600, 'Bad Request: batch requests are not supported.');
        }

        if (!$message instanceof \stdClass || ($message->jsonrpc ?? null) !== '2.0') {
            return self::rpcError(400, null, -32600, 'Bad Request: the request body is not a valid JSON-RPC message');
        }

        $message = Js::decode($request->body);

        if (!isset($message->method)) {
            // A response to a request this server never sends: accepted, ignored.
            return OAuthResponse::empty(202);
        }

        if (!is_string($message->method) || (property_exists($message, 'params') && !$message->params instanceof \stdClass)) {
            return self::rpcError(400, $message->id ?? null, -32600, 'Bad Request: the request body is not a valid JSON-RPC message');
        }

        $meta = isset($message->params->_meta) && $message->params->_meta instanceof \stdClass ? $message->params->_meta : null;

        return $meta !== null && property_exists($meta, self::META_VERSION)
            ? self::modern($request, $message, $meta)
            : self::legacy($request, $message);
    }

    // ──────────────────────────────────────────────
    //  2026-07-28 (stateless, self-contained requests)
    // ──────────────────────────────────────────────

    private static function modern(OAuthRequest $request, \stdClass $message, \stdClass $meta): OAuthResponse
    {
        $id      = $message->id ?? null;
        $version = $meta->{self::META_VERSION};
        $version = is_string($version) ? $version : Js::stringify($version);

        $mismatch = self::headerMismatch($request, $message, $version);
        if ($mismatch !== null) {
            return self::rpcError(400, $id, -32020, 'Bad Request: the request headers and body disagree: ' . $mismatch[1], ['mismatch' => ['header' => $mismatch[0], 'body' => $mismatch[1]]]);
        }

        if ($version !== self::MODERN_VERSION) {
            return self::rpcError(400, $id, -32022, 'Unsupported protocol version: ' . $version, ['supported' => [self::MODERN_VERSION], 'requested' => $version]);
        }

        if (!property_exists($meta, self::META_CAPABILITIES)) {
            return self::rpcError(
                400,
                $id,
                -32602,
                'Invalid _meta envelope for protocol revision ' . self::MODERN_VERSION . ': ' . self::META_CAPABILITIES . ': missing',
                ['envelope' => ['key' => self::META_CAPABILITIES, 'problem' => 'missing']]
            );
        }

        if (!property_exists($message, 'id')) {
            return OAuthResponse::empty(202);
        }

        $serverInfo = [self::META_SERVER_INFO => self::serverInfo()];

        switch ($message->method) {
            case 'server/discover':
                return self::rpcResult($id, [
                    'supportedVersions' => [self::MODERN_VERSION],
                    'capabilities'      => ['tools' => new \stdClass()],
                    'instructions'      => ToolRegistry::instructions(),
                    'resultType'        => 'complete',
                    'ttlMs'             => 0,
                    'cacheScope'        => 'private',
                    '_meta'             => $serverInfo,
                ]);
            case 'tools/list':
                self::recordFirstCall('tools/list');

                return self::rpcResult($id, ['tools' => ToolRegistry::visible(), 'resultType' => 'complete', 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => $serverInfo]);
            case 'tools/call':
                return self::toolsCall($id, $message->params ?? new \stdClass(), static fn (array $result): array => $result + ['resultType' => 'complete', '_meta' => $serverInfo]);
            default:
                return self::rpcError(404, $id, -32601, 'Method not found');
        }
    }

    /**
     * The first header that disagrees with the body: [header value, why].
     *
     * @return array{0: string, 1: string}|null
     */
    private static function headerMismatch(OAuthRequest $request, \stdClass $message, string $version): ?array
    {
        $header = $request->header('mcp-protocol-version');
        if ($header !== $version) {
            $body = 'the body envelope names protocol version ' . $version . ' but the '
                . ($header === '' ? 'required MCP-Protocol-Version header is absent' : 'MCP-Protocol-Version header names ' . $header);

            return [$header === '' ? '(missing)' : $header, $body];
        }

        $method = $request->header('mcp-method');
        if ($method !== $message->method) {
            $body = 'the body names method ' . $message->method . ' but the ' . ($method === '' ? 'required Mcp-Method header is absent' : 'Mcp-Method header names ' . $method);

            return [$method === '' ? '(missing)' : $method, $body];
        }

        if ($message->method === 'tools/call') {
            $name   = $message->params->name ?? null;
            $header = $request->header('mcp-name');

            if (is_string($name) && $header !== $name) {
                $body = 'the body carries params.name=' . Js::stringify($name) . ' but the ' . ($header === '' ? 'required Mcp-Name header is absent' : 'Mcp-Name header names ' . $header);

                return [$header === '' ? '(missing)' : $header, $body];
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  2025 era (initialize, then each request on its own)
    // ──────────────────────────────────────────────

    private static function legacy(OAuthRequest $request, \stdClass $message): OAuthResponse
    {
        $header = $request->header('mcp-protocol-version');

        if ($header !== '' && $message->method !== 'initialize' && !in_array($header, self::LEGACY_VERSIONS, true)) {
            return self::rpcError(400, null, -32000, 'Bad Request: Unsupported protocol version: ' . $header . ' (supported versions: ' . implode(', ', self::LEGACY_VERSIONS) . ')');
        }

        if (!property_exists($message, 'id')) {
            // notifications/initialized, notifications/cancelled: nothing to remember.
            return OAuthResponse::empty(202);
        }

        $id = $message->id;

        switch ($message->method) {
            case 'initialize':
                $requested = $message->params->protocolVersion ?? null;

                return self::rpcResult($id, [
                    'protocolVersion' => is_string($requested) && in_array($requested, self::LEGACY_VERSIONS, true) ? $requested : self::LEGACY_VERSIONS[0],
                    'capabilities'    => ['tools' => new \stdClass()],
                    'serverInfo'      => self::serverInfo(),
                    'instructions'    => ToolRegistry::instructions(),
                ]);
            case 'ping':
                return self::rpcResult($id, new \stdClass());
            case 'tools/list':
                self::recordFirstCall('tools/list');

                return self::rpcResult($id, ['tools' => ToolRegistry::visible()]);
            case 'tools/call':
                return self::toolsCall($id, $message->params ?? new \stdClass(), static fn (array $result): array => $result);
            default:
                return self::rpcError(200, $id, -32601, 'Method not found');
        }
    }

    // ──────────────────────────────────────────────
    //  tools/call
    // ──────────────────────────────────────────────

    /**
     * @param mixed $id
     * @param callable(array<string, mixed>): array<string, mixed> $wrap Adds the era's result fields.
     */
    private static function toolsCall($id, \stdClass $params, callable $wrap): OAuthResponse
    {
        $name = $params->name ?? null;

        if (!is_string($name) || !ToolRegistry::isVisible($name)) {
            // Tools outside this grant's list are not callable, and look like any unknown tool.
            return self::rpcError(200, $id, -32602, 'Tool ' . (is_string($name) ? $name : Js::stringify($name)) . ' not found');
        }

        $arguments = $params->arguments ?? new \stdClass();
        if (!$arguments instanceof \stdClass) {
            return self::rpcError(200, $id, -32602, 'Invalid params: arguments must be an object');
        }

        self::recordFirstCall('tools/call');

        return self::rpcResult($id, $wrap(self::call($name, $arguments)->toArray()));
    }

    /**
     * Run one tool behind the guards, and audit it when no route did.
     */
    public static function call(string $name, \stdClass $arguments): ToolResult
    {
        $started = microtime(true);
        $routes  = McpContext::dispatchCount();
        $audit   = new McpAudit($name, $arguments);

        if (AccessPause::isPaused()) {
            $paused = RestGuard::error(RestGuard::ACCESS_PAUSED, 403);
            $data   = (array) $paused->get_error_data();

            return $audit->refused(ToolResult::fromSite(new SiteError(RestGuard::ACCESS_PAUSED, $paused->get_error_message(), (string) ($data['hint'] ?? ''), 403)));
        }

        $checked = InputSchema::check($arguments, ToolRegistry::definition($name)->inputSchema);

        if (!$checked['ok']) {
            // As the SDK answers invalid arguments: a tool result, not a JSON-RPC error.
            return $audit->refused(ToolResult::invalidArguments($name, $checked['issues']), 'invalid_arguments');
        }

        $args     = $checked['value'];
        $decision = Policy::classify($name, (array) $args);
        $audit->classify($decision['class']);

        if (!$decision['allowed']) {
            return $audit->refused(ToolResult::error(
                'policy_refused',
                'Refused by McpMate: ' . $decision['reason'],
                $decision['hint'],
                'Do not retry this call, and do not rephrase it to get around the refusal. Tell the user what was refused and why.'
            ));
        }

        if ($decision['class'] !== Policy::READ && !McpContext::canWrite()) {
            return $audit->refused(ToolResult::error(
                'insufficient_scope',
                'This app was approved to read the site only, and this call would change it.',
                null,
                'Do not retry. Tell the user; to make changes they must reconnect the app and approve it in wp-admin without "Read only".'
            ));
        }

        if (function_exists('set_time_limit') && !in_array('set_time_limit', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            // One call is one PHP request: give uploads, Elementor saves and theme publishes room (§10.5).
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- MCP tool calls (media downloads, Elementor saves, theme publish, search-replace) can exceed the default max_execution_time; some hosts forbid raising it, and the call goes ahead either way.
            @set_time_limit(120);
        }

        $client = new InternalClient();

        try {
            $result = ToolRegistry::handler($name, $client)->run($args);
        } catch (SiteError $e) {
            $result = ToolResult::fromSite($e);
        } catch (\Throwable $e) {
            $result = ToolResult::internal();
            $audit->failed('Unhandled ' . get_class($e));
        }

        return $audit->finish($result, McpContext::dispatchCount() > $routes, $started);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * @return array{name: string, version: string}
     */
    public static function serverInfo(): array
    {
        return ['name' => self::SERVER_NAME, 'version' => Config::VERSION];
    }

    public static function rateLimit(): int
    {
        $limit = (int) Preferences::get('rate_limit');

        return $limit > 0 ? $limit : self::DEFAULT_RATE_LIMIT;
    }

    /**
     * The CORS headers for an allowed Origin; [] when it is not allowed.
     * Allowed: the site's own origins and MCPMATE_ALLOWED_ORIGINS
     * (constant or .env, comma-separated); the filter can only add.
     *
     * @return array<string, string>
     */
    public static function corsHeaders(string $origin): array
    {
        if (!in_array(strtolower(rtrim($origin, '/')), self::allowedOrigins(), true)) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin'   => $origin,
            'Access-Control-Allow-Methods'  => 'GET, POST, DELETE, OPTIONS',
            'Access-Control-Allow-Headers'  => self::ALLOW_HEADERS,
            'Access-Control-Expose-Headers' => 'WWW-Authenticate, Retry-After',
            'Access-Control-Max-Age'        => '600',
        ];
    }

    /**
     * @return string[] Lowercase origins.
     */
    public static function allowedOrigins(): array
    {
        $configured = defined('MCPMATE_ALLOWED_ORIGINS') ? (string) constant('MCPMATE_ALLOWED_ORIGINS') : (string) Config::getEnv('ALLOWED_ORIGINS');
        $origins    = array_filter(array_map('trim', explode(',', $configured)));

        foreach ([home_url(), site_url(), OAuthUrls::base()] as $url) {
            $parts = wp_parse_url($url);

            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origins[] = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }

        $filtered = apply_filters(Config::withPrefix('allowed_origins'), []); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed by Config::withPrefix().
        $origins  = array_merge($origins, is_array($filtered) ? array_filter($filtered, 'is_string') : []);

        return array_values(array_unique(array_map(static fn (string $origin): string => strtolower(rtrim($origin, '/')), $origins)));
    }

    /**
     * The grant's first call (tools/list or tools/call) writes one
     * `connection` / `first_call` row; the setup wizard waits for it.
     */
    private static function recordFirstCall(string $method): void
    {
        $grant = Grants::find(McpContext::grantId());

        if ($grant === null || !array_key_exists('first_call_at', $grant) || $grant['first_call_at'] !== null || !Grants::claimFirstCall(McpContext::grantId())) {
            return;
        }

        McpAudit::firstCall($method);
    }

    /**
     * @param mixed                        $id
     * @param array<string, mixed>|\stdClass $result
     */
    private static function rpcResult($id, $result): OAuthResponse
    {
        return self::json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * @param mixed                     $id
     * @param array<string, mixed>|null $data
     */
    private static function rpcError(int $status, $id, int $code, string $message, ?array $data = null): OAuthResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return self::json($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(int $status, array $body): OAuthResponse
    {
        return OAuthResponse::raw($status, Js::stringify($body), ['Content-Type' => 'application/json']);
    }
}
