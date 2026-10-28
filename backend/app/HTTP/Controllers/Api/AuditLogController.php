<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\AuditLog\AuditLogQuery;
use McpMate\Services\AuditLog\Logger;

/**
 * GET /audit-log — one page of the audit log, newest first (or sorted by
 * `order_by` / `order`), narrowed by the filters in indexArgs().
 *
 * Uses the default capability (manage_options): rows hold every user's
 * request arguments.
 */
final class AuditLogController extends RestController
{
    public const DEFAULT_PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $from = self::parseDate((string) $request->get('from', ''), false);
        $to   = self::parseDate((string) $request->get('to', ''), true);

        if ($from !== null && $to !== null && $from > $to) {
            return $this->error(
                __('The date range is invalid.', 'mcpmate'),
                400,
                'mcpmate_invalid_date_range',
                __('"from" must be on or before "to".', 'mcpmate')
            );
        }

        $page    = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', self::DEFAULT_PER_PAGE);

        $result = AuditLogQuery::paginate(
            [
                'id'       => (int) $request->get('id', 0),
                'tool'     => (string) $request->get('tool', ''),
                'mcp_tool' => (string) $request->get('mcp_tool', ''),
                'kind'     => (string) $request->get('kind', ''),
                'result'   => (string) $request->get('result', ''),
                'grant_id' => (string) $request->get('grant_id', ''),
                'user_id'  => (int) $request->get('user_id', 0),
                'search'   => sanitize_text_field((string) $request->get('search', '')),
                'from'     => $from ?? '',
                'to'       => $to ?? '',
            ],
            $page,
            $perPage,
            (string) $request->get('order_by', 'created_at'),
            (string) $request->get('order', 'desc')
        );

        return $this->ok([
            'items'       => $result['items'],
            'total'       => $result['total'],
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($result['total'] / $perPage),
        ]);
    }

    /**
     * REST `args` schema for index().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function indexArgs(): array
    {
        return [
            'page'     => [
                'type'    => 'integer',
                'minimum' => 1,
                'default' => 1,
            ],
            'per_page' => [
                'type'    => 'integer',
                'minimum' => 1,
                'maximum' => AuditLogQuery::MAX_PER_PAGE,
                'default' => self::DEFAULT_PER_PAGE,
            ],
            'id'       => [
                'type'        => 'integer',
                'minimum'     => 1,
                'description' => 'One row by its ID.',
            ],
            'tool'     => [
                'type'    => 'string',
                'pattern' => '^[A-Za-z0-9_.:\-]{1,100}$',
            ],
            'mcp_tool' => [
                'type'        => 'string',
                'pattern'     => '^[a-z0-9_]{1,60}$',
                'description' => 'Rows of one MCP tool (e.g. elementor_save).',
            ],
            'kind'     => [
                'type' => 'string',
                'enum' => Logger::KINDS,
            ],
            'user_id'  => [
                'type'    => 'integer',
                'minimum' => 1,
            ],
            'search'   => [
                'type'        => 'string',
                'maxLength'   => 100,
                'description' => 'Part of the tool, MCP tool, action, target or message.',
            ],
            'order_by' => [
                'type'    => 'string',
                'enum'    => AuditLogQuery::ORDER_BY,
                'default' => 'created_at',
            ],
            'order'    => [
                'type'    => 'string',
                'enum'    => ['asc', 'desc'],
                'default' => 'desc',
            ],
            'result'   => [
                'type' => 'string',
                'enum' => Logger::RESULTS,
            ],
            'grant_id' => [
                'type'    => 'string',
                'pattern' => '^g_[A-Za-z0-9_-]{22}$',
            ],
            'from'     => [
                'type'              => 'string',
                'description'       => 'Earliest row (UTC), as YYYY-MM-DD or an RFC 3339 date-time. Inclusive.',
                'validate_callback' => [self::class, 'validateDate'],
            ],
            'to'       => [
                'type'              => 'string',
                'description'       => 'Latest row (UTC), as YYYY-MM-DD (whole day) or an RFC 3339 date-time. Inclusive.',
                'validate_callback' => [self::class, 'validateDate'],
            ],
        ];
    }

    /**
     * `validate_callback` for the date filters.
     *
     * @param mixed $value
     * @return true|\WP_Error
     */
    public static function validateDate($value, \WP_REST_Request $request, string $param)
    {
        if (is_string($value) && self::parseDate($value, false) !== null) {
            return true;
        }

        return new \WP_Error(
            'rest_invalid_param',
            /* translators: %s: query parameter name, e.g. from. */
            sprintf(__('%s must be a date (YYYY-MM-DD) or an RFC 3339 date-time.', 'mcpmate'), $param),
            ['status' => 400]
        );
    }

    /**
     * YYYY-MM-DD (start or end of that UTC day) or an RFC 3339 date-time,
     * as `Y-m-d H:i:s` UTC. Null for an empty or invalid value.
     */
    private static function parseDate(string $value, bool $endOfDay): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        $timestamp = rest_parse_date($value);

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
