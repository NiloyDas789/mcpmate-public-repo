<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\HTTP\RestEnvelope;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\ChangeHistory\ChangeCapture;
use McpMate\Services\Mcp\McpContext;
use McpMate\Services\Settings\Preferences;
use McpMate\Utils\Capabilities;

/**
 * Base class for the MCP REST controllers (namespace REST_NS, `mcpmate/v1`).
 *
 * Permissions: each controller method needs one capability, looked up in
 * CAPABILITIES and defaulting to DEFAULT_CAPABILITY. Declare the route with
 * the matching middleware so the router checks it in permission_callback:
 *
 *   Route::get('ping', [PingController::class, 'ping'])
 *       ->middleware(PingController::permission('ping'));
 *
 * Object-level checks (`edit_post`, $id) run inside the handler via authorize().
 *
 * Responses: return ok() / error(). RestEnvelope wraps every response under
 * the namespace as { ok, data, error: { code, message, hint }, request_id,
 * truncated }, adds the `X-Request-Id` header and enforces the size cap.
 *
 * Audit log: the router calls dispatch(), which writes one audit row for
 * every POST/PUT/PATCH/DELETE request after the handler returns (result
 * `ok` or `error`, from the Response), except handlers listed in
 * READ_HANDLERS. Reads (`kind` read) are logged for MCP calls while
 * "Also log read calls" is on (Preferences), with short args only;
 * controllers whose reads are sensitive set AUDIT_READS to log every GET
 * request. Each row has the handler's duration and the route's MCP tool.
 * A write also records what it changed (ChangeCapture), linked to its
 * audit row (an MCP call's grant comes from McpContext, which also records
 * the grant's first call). Handlers only add detail: the object
 * acted on with auditTarget('post', $id), the operation with auditAction(),
 * and pendingApproval() for work that waits for a site admin.
 */
abstract class RestController
{
    public const NAMESPACE = Config::SLUG . '/v' . Config::API_VERSION;

    public const DEFAULT_CAPABILITY = 'manage_options';

    /**
     * Capability required by each handler, keyed by method name. Methods not
     * listed require DEFAULT_CAPABILITY.
     *
     * @var array<string, string>
     */
    protected const CAPABILITIES = [];

    /** Requests with these methods are written to the audit log. */
    public const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** True: every request to this controller is audited, reads included. */
    protected const AUDIT_READS = false;

    /**
     * Handlers that only read even though their route is a POST (a secret
     * sent in the body instead of the URL). Not audited unless AUDIT_READS.
     *
     * @var string[]
     */
    protected const READ_HANDLERS = [];

    /**
     * Audit-log `tool` for this controller. Empty derives it from the class
     * name: PostsController → `posts`, AuditLogController → `audit_log`.
     */
    protected const TOOL = '';

    private string $auditAction = '';

    private string $auditTarget = '';

    private ?string $auditResult = null;

    private string $auditMessage = '';

    private string $mcpTool = '';

    private float $startedAt = 0.0;

    /** Longest string kept in the args of a read row, in characters. */
    private const READ_ARG_LENGTH = 200;

    // ──────────────────────────────────────────────
    //  Dispatch
    // ──────────────────────────────────────────────

    /**
     * Router entry point: run $method, then write the audit row for a write
     * request (any request when AUDIT_READS is true, and an MCP call's read
     * while read logging is on). A write's changes are captured around the
     * handler and stored with its audit row.
     *
     * A handler that throws is logged as `error` (exception class only, never
     * its message) and the exception is rethrown.
     *
     * @param array<int, mixed> $urlParams
     * @param string            $mcpTool  The route's tool (ToolCatalog), from the router.
     */
    final public function dispatch(string $method, Request $request, array $urlParams = [], string $mcpTool = ''): Response
    {
        $this->auditAction  = '';
        $this->auditTarget  = '';
        $this->auditResult  = null;
        $this->auditMessage = '';
        $this->mcpTool      = $mcpTool;
        $this->startedAt    = microtime(true);

        $reads = static::readsOnly($method, $request);

        if (!static::AUDIT_READS && $reads) {
            $response = $this->{$method}($request, ...$urlParams);

            [$result, $message] = $this->outcome($response);

            if (McpContext::active() && Preferences::logReads()) {
                $this->writeAudit($method, $request, $result, $message, Logger::KIND_READ);
            }

            return $response;
        }

        $kind = $reads ? Logger::KIND_READ : Logger::KIND_WRITE;

        if (!$reads) {
            ChangeCapture::begin();
        }

        try {
            $response = $this->{$method}($request, ...$urlParams);
        } catch (\Throwable $e) {
            $auditId = $this->writeAudit($method, $request, Logger::RESULT_ERROR, 'Unhandled ' . get_class($e), $kind);

            if (!$reads) {
                $this->storeChanges($request, $auditId);
            }

            throw $e;
        }

        [$result, $message] = $this->outcome($response);

        $auditId = $this->writeAudit($method, $request, $result, $message, $kind);

        if (!$reads) {
            $this->storeChanges($request, $auditId);
        }

        return $response;
    }

    /**
     * End the change capture this write started, linking its rows to the
     * audit row.
     *
     * @param int|false $auditId
     */
    private function storeChanges(Request $request, $auditId): void
    {
        $restRequest = $request->restRequest();

        ChangeCapture::end([
            'audit_id'   => is_int($auditId) ? $auditId : 0,
            'request_id' => $restRequest ? RestEnvelope::requestId($restRequest) : '',
            'user_id'    => get_current_user_id(),
            'grant_id'   => McpContext::grantId(),
            'tool'       => static::tool(),
            'mcp_tool'   => $this->mcpTool,
        ]);
    }

    /**
     * The audit result and message for the handler's response.
     *
     * @return array{0: string, 1: string}
     */
    private function outcome(Response $response): array
    {
        if ($this->auditResult !== null) {
            $result = $this->auditResult;
        } else {
            $result = $response->isSuccess() ? Logger::RESULT_OK : Logger::RESULT_ERROR;
        }

        $message = $this->auditMessage;
        if ($message === '' && !$response->isSuccess()) {
            $message = $response->getCode() . ': ' . $response->getMessage();
        }

        return [$result, $message];
    }

    /**
     * Audit-log `tool` name for this controller.
     */
    public static function tool(): string
    {
        if (static::TOOL !== '') {
            return static::TOOL;
        }

        $parts = explode('\\', static::class);
        $name  = (string) preg_replace('/Controller$/', '', end($parts));

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /**
     * Whether this call of $method only reads: a GET/HEAD request, or a
     * handler in READ_HANDLERS. Reads are not audited (unless AUDIT_READS),
     * and an MCP call needs only the `site:read` scope for them.
     * Override for a handler whose request says what it does (POST /proxy).
     */
    public static function readsOnly(string $method, Request $request): bool
    {
        $restRequest = $request->restRequest();

        if ($restRequest === null || !in_array(strtoupper($restRequest->get_method()), self::WRITE_METHODS, true)) {
            return true;
        }

        return in_array($method, static::READ_HANDLERS, true);
    }

    /**
     * @return int|false The audit row's ID, or false when it was not written.
     */
    private function writeAudit(string $method, Request $request, string $result, string $message, string $kind)
    {
        $restRequest = $request->restRequest();
        $args        = static::auditArgs($request->all());

        return Logger::log([
            'tool'        => static::tool(),
            'action'      => $this->auditAction !== '' ? $this->auditAction : $method,
            'target'      => $this->auditTarget,
            'args'        => $kind === Logger::KIND_READ && !static::AUDIT_READS ? self::shortArgs($args) : $args,
            'result'      => $result,
            'message'     => $message,
            'request_id'  => $restRequest ? RestEnvelope::requestId($restRequest) : '',
            'kind'        => $kind,
            'mcp_tool'    => $this->mcpTool,
            'duration_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
        ]);
    }

    /**
     * A read row keeps the query's keys and short values only.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function shortArgs($value)
    {
        if (is_string($value) && mb_strlen($value, 'UTF-8') > self::READ_ARG_LENGTH) {
            return mb_substr($value, 0, self::READ_ARG_LENGTH, 'UTF-8') . '…';
        }

        return is_array($value) ? array_map([self::class, 'shortArgs'], $value) : $value;
    }

    // ──────────────────────────────────────────────
    //  Audit detail (for handlers)
    // ──────────────────────────────────────────────

    /**
     * The request args as the audit row stores them, before Logger::redact().
     * Override to leave out bulky values (file contents) or secrets that do
     * not sit under a secret-looking key (signed URLs).
     *
     * @param array<array-key, mixed> $args
     * @return array<array-key, mixed>
     */
    protected static function auditArgs(array $args): array
    {
        return $args;
    }

    /**
     * Store $action as the audit `action` instead of the handler method name,
     * for handlers that run several operations (e.g. `plugin.list` via /cli).
     * Logger keeps only `A-Za-z0-9_.:-` in actions.
     */
    protected function auditAction(string $action): void
    {
        $this->auditAction = $action;
    }

    /**
     * Name the object this write acts on, stored as `target` (e.g. `post:123`).
     *
     * @param int|string $id
     */
    protected function auditTarget(string $type, $id): void
    {
        $this->auditTarget = $type . ':' . $id;
    }

    /**
     * Accepted, but waiting for a site admin to approve it: 202, logged as
     * `pending_approval` with $message.
     *
     * @param array<string, mixed> $data
     */
    protected function pendingApproval(string $message, array $data = []): Response
    {
        $this->auditResult  = Logger::RESULT_PENDING;
        $this->auditMessage = $message;

        return $this->ok($data, 202);
    }

    // ──────────────────────────────────────────────
    //  Permissions
    // ──────────────────────────────────────────────

    /**
     * Capability required to call $method.
     */
    public static function capabilityFor(string $method): string
    {
        return static::CAPABILITIES[$method] ?? self::DEFAULT_CAPABILITY;
    }

    /**
     * Router middleware spec for a route handled by $method, e.g. `can:manage_options`.
     */
    public static function permission(string $method): string
    {
        return 'can:' . static::capabilityFor($method);
    }

    /**
     * Re-check a capability inside a handler, usually object-level:
     *
     *   if ($denied = $this->authorize('edit_post', $id)) {
     *       return $denied;
     *   }
     *
     * @param mixed ...$args Object ID(s) for meta capabilities.
     * @return Response|null Null when allowed; otherwise a 401 (anonymous) or 403 error.
     */
    protected function authorize(string $capability, ...$args): ?Response
    {
        if ($capability !== '' && Capabilities::check($capability, ...$args)) {
            return null;
        }

        if (!is_user_logged_in()) {
            return $this->error(
                __('Authentication required.', 'mcpmate'),
                401,
                'mcpmate_unauthorized'
            );
        }

        return $this->error(
            __('Sorry, you are not allowed to do that.', 'mcpmate'),
            403,
            'mcpmate_forbidden'
        );
    }

    // ──────────────────────────────────────────────
    //  Responses
    // ──────────────────────────────────────────────

    /**
     * Success response; becomes the envelope's `data`.
     *
     * @param array<string, mixed>|array<int, mixed> $data
     */
    protected function ok(array $data = [], int $status = 200): Response
    {
        return Response::success($data)->httpStatus($status);
    }

    /**
     * Error response; becomes the envelope's `error`.
     *
     * @param string $code Stable `mcpmate_*` code.
     * @param string $hint Optional next step for the caller (no secrets).
     */
    protected function error(string $message, int $status, string $code, string $hint = ''): Response
    {
        return Response::error($message, $status, $code)->withHint($hint);
    }
}
