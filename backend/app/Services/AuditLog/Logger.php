<?php

namespace McpMate\Services\AuditLog;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Services\Connection\RestGuard;
use McpMate\Services\Mcp\McpContext;

/**
 * Writes the append-only audit log (`{prefix}mcpmate_audit_log`).
 *
 * The log can only be appended to: there is no update or delete here, and
 * nothing else in the plugin changes rows except Retention, which removes
 * rows past the retention window. RestController::dispatch() calls log() for
 * every write request, so handlers do not log themselves.
 *
 * Arguments are passed through redact() before they are stored.
 */
final class Logger
{
    public const TABLE = 'audit_log';

    public const RESULT_OK = 'ok';

    public const RESULT_ERROR = 'error';

    public const RESULT_PENDING = 'pending_approval';

    public const RESULTS = [self::RESULT_OK, self::RESULT_ERROR, self::RESULT_PENDING];

    /** `kind`: a call that only read, or one that wrote (or tried to). */
    public const KIND_READ = 'read';

    public const KIND_WRITE = 'write';

    public const KINDS = [self::KIND_READ, self::KIND_WRITE];

    /** Replaces the value of every key matching SENSITIVE_KEY. */
    public const REDACTED = '[redacted]';

    /** Keys whose values are never stored, at any depth (case-insensitive). */
    public const SENSITIVE_KEY = '/password|secret|token|key|authorization|signature|proof|code_verifier|^code$/i';

    /** Longest string kept in args_json, in characters; longer strings are cut. */
    public const MAX_STRING_LENGTH = 2000;

    /** Column sizes, in characters. */
    private const LIMITS = [
        'request_id' => 36,
        'tool'       => 100,
        'action'     => 100,
        'target'     => 191,
        'message'    => 1000,
        'grant_id'   => 40,
        'auth'       => 12,
        'mcp_tool'   => 60,
    ];

    public static function table(): string
    {
        return Config::withDBPrefix(self::TABLE);
    }

    /**
     * Append one row. `grant_id` and `auth` default to the current request's
     * (McpContext, RestGuard): the grant of an MCP call, and how the
     * request authenticated (`oauth` for the MCP endpoint's bearer token).
     *
     * `kind` defaults to write; `mcp_tool` is the ToolCatalog name of the
     * route (never a client header); `duration_ms` is the handler's time.
     *
     * @param array{tool: string, action: string, result: string, target?: string, args?: array<array-key, mixed>, message?: string,
     *              request_id?: string, user_id?: int, grant_id?: string, auth?: string, kind?: string, mcp_tool?: string,
     *              duration_ms?: int|null} $entry
     * @return int|false The new row ID, or false when the row was not written
     *                   (unknown result, or a database error).
     */
    public static function log(array $entry)
    {
        $result = $entry['result'];

        if (!in_array($result, self::RESULTS, true)) {
            return false;
        }

        return DB::insert(
            self::table(),
            [
                'created_at' => gmdate('Y-m-d H:i:s'),
                'user_id'    => $entry['user_id'] ?? get_current_user_id(),
                'request_id' => self::clean($entry['request_id'] ?? '', 'request_id'),
                'tool'       => self::clean($entry['tool'], 'tool'),
                'action'     => self::clean($entry['action'], 'action'),
                'target'     => self::clean($entry['target'] ?? '', 'target'),
                'args_json'  => self::encodeArgs($entry['args'] ?? []),
                'result'     => $result,
                'message'    => self::clean($entry['message'] ?? '', 'message'),
                'grant_id'   => self::clean($entry['grant_id'] ?? McpContext::grantId(), 'grant_id'),
                'auth'        => self::clean($entry['auth'] ?? RestGuard::authMethod(), 'auth'),
                'kind'        => in_array($entry['kind'] ?? '', self::KINDS, true) ? $entry['kind'] : self::KIND_WRITE,
                'mcp_tool'    => self::clean($entry['mcp_tool'] ?? '', 'mcp_tool'),
                'duration_ms' => isset($entry['duration_ms']) ? max(0, (int) $entry['duration_ms']) : null,
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );
    }

    /**
     * Replace the value of every key matching SENSITIVE_KEY with REDACTED,
     * at any depth. Objects are turned into arrays first; list indexes are
     * never treated as keys to redact.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function redact($value)
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = is_string($key) && preg_match(self::SENSITIVE_KEY, $key)
                ? self::REDACTED
                : self::redact($item);
        }

        return $value;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @param array<array-key, mixed> $args
     */
    private static function encodeArgs(array $args): ?string
    {
        $json = wp_json_encode(self::clip(self::redact($args)));

        return $json === false ? null : $json;
    }

    /**
     * Cut every string longer than MAX_STRING_LENGTH (post bodies, mostly).
     *
     * @param mixed $value
     * @return mixed
     */
    private static function clip($value)
    {
        if (is_string($value) && mb_strlen($value, 'UTF-8') > self::MAX_STRING_LENGTH) {
            return mb_substr($value, 0, self::MAX_STRING_LENGTH, 'UTF-8') . '…';
        }

        if (is_array($value)) {
            return array_map([self::class, 'clip'], $value);
        }

        return $value;
    }

    /**
     * Single-line text cut to the column size. tool, action, request_id,
     * grant_id and auth are identifiers and keep only [A-Za-z0-9_.:-]
     * (actions also keep `/`, for proxied routes).
     *
     * @param mixed $value
     */
    private static function clean($value, string $column): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        if ($column === 'action') {
            $value = (string) preg_replace('#[^A-Za-z0-9_.:/\-]#', '', $value);
        } elseif (in_array($column, ['tool', 'request_id', 'grant_id', 'auth', 'mcp_tool'], true)) {
            $value = (string) preg_replace('/[^A-Za-z0-9_.:\-]/', '', $value);
        } else {
            $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        }

        return mb_substr($value, 0, self::LIMITS[$column], 'UTF-8');
    }
}
