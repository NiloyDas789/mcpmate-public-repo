<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\Settings\Preferences;

/**
 * Audit rows for MCP calls that reach no route (docs/mcp-in-plugin.md
 * §7.1, item 9): calls the endpoint refused (Pause, invalid input, the
 * policy layer, the read-only scope, the block markup gate), local tools
 * (skills, stock photos), and the rate limit. A call that reached a route
 * is audited by RestController::dispatch() instead, with its grant.
 *
 * Rows have tool `mcp`, the MCP tool as action and mcp_tool, and result
 * `error` with the refusal's code as message. Arguments are kept short
 * (strings cut to 200 characters) and redacted.
 */
final class McpAudit
{
    public const TOOL = 'mcp';

    private const ARG_LENGTH = 200;

    private string $tool;

    private \stdClass $args;

    private string $kind = Logger::KIND_WRITE;

    private string $message = '';

    public function __construct(string $tool, \stdClass $args)
    {
        $this->tool = $tool;
        $this->args = $args;
    }

    /**
     * The call's class from the policy layer: reads are logged only while
     * Settings' "Also log read calls" is on.
     */
    public function classify(string $class): void
    {
        $this->kind = $class === Policy::READ ? Logger::KIND_READ : Logger::KIND_WRITE;
    }

    public function failed(string $message): void
    {
        $this->message = $message;
    }

    /**
     * Log a refusal and hand the result back.
     */
    public function refused(ToolResult $result, string $code = ''): ToolResult
    {
        $this->write(Logger::RESULT_ERROR, 'refused: ' . ($code !== '' ? $code : $result->errorCode()), 0.0);

        return $result;
    }

    /**
     * Log a call that ran without reaching a route; a route's own row covers the rest.
     */
    public function finish(ToolResult $result, bool $routeReached, float $started): ToolResult
    {
        if ($routeReached && $this->message === '') {
            return $result;
        }

        if ($result->isError) {
            $this->write(Logger::RESULT_ERROR, $this->message !== '' ? $this->message : $result->errorCode(), $started);
        } elseif ($this->kind === Logger::KIND_WRITE || Preferences::logReads()) {
            $this->write($result->isPending() ? Logger::RESULT_PENDING : Logger::RESULT_OK, '', $started);
        }

        return $result;
    }

    private function write(string $result, string $message, float $started): void
    {
        Logger::log([
            'tool'        => self::TOOL,
            'action'      => $this->tool,
            'args'        => self::shortArgs(Logger::redact(json_decode(Js::stringify($this->args), true))),
            'result'      => $result,
            'message'     => $message,
            'request_id'  => McpContext::requestId(),
            'kind'        => $this->kind,
            'mcp_tool'    => $this->tool,
            'duration_ms' => $started > 0 ? (int) round((microtime(true) - $started) * 1000) : 0,
        ]);
    }

    /**
     * One `rate_limited` row per grant per minute: refused calls come only
     * from authenticated grants, but a client loop should not fill the log.
     */
    public static function rateLimited(): void
    {
        $key = Config::withPrefix('mcp_rl_' . md5(McpContext::grantId()));

        if (get_transient($key) !== false) {
            return;
        }

        set_transient($key, 1, 60);

        Logger::log([
            'tool'       => self::TOOL,
            'action'     => 'rate_limited',
            'result'     => Logger::RESULT_ERROR,
            'message'    => 'rate_limited',
            'request_id' => McpContext::requestId(),
        ]);
    }

    /**
     * The grant's first call, for the setup wizard (one row per grant).
     */
    public static function firstCall(string $method): void
    {
        Logger::log([
            'tool'       => 'connection',
            'action'     => 'first_call',
            'target'     => 'grant:' . McpContext::grantId(),
            'args'       => ['method' => 'POST', 'route' => 'mcp', 'rpc' => $method],
            'result'     => Logger::RESULT_OK,
            'request_id' => McpContext::requestId(),
        ]);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function shortArgs($value)
    {
        if (is_string($value) && mb_strlen($value, 'UTF-8') > self::ARG_LENGTH) {
            return mb_substr($value, 0, self::ARG_LENGTH, 'UTF-8') . '…';
        }

        return is_array($value) ? array_map([self::class, 'shortArgs'], $value) : $value;
    }
}
