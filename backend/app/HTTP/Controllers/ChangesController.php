<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\ChangeHistory\ChangeCapture;
use McpMate\Services\ChangeHistory\ChangeStore;
use McpMate\Services\Connection\Grants;

/**
 * Change history (docs/ui.md §5.4.2, B7). AJAX routes (nonce + admin)
 * only: snapshots can hold post content and personal data, so they never
 * reach the MCP surface.
 *
 * `changes/list` filters: object_type, change_action (admin-ajax keeps
 * `action` for itself), mcp_tool, grant_id,
 * request_id, audit_id, user_id, from / to (YYYY-MM-DD or RFC 3339, UTC,
 * inclusive), search (object label or key); page, per_page (≤ 100).
 * `changes/show {id}` adds `before`, `after` and `diff` (ChangeDiff).
 */
final class ChangesController
{
    public const DEFAULT_PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $filters = [];

        foreach (['object_type' => ['object_type', ChangeCapture::TYPES], 'change_action' => ['action', ChangeStore::ACTIONS]] as $key => [$filter, $allowed]) {
            $value = (string) $request->get($key, '');

            if ($value !== '' && !in_array($value, $allowed, true)) {
                return self::invalid($key);
            }

            $filters[$filter] = $value;
        }

        foreach (['mcp_tool' => '/^[a-z0-9_]{1,60}$/', 'grant_id' => '/^g_[A-Za-z0-9_-]{22}$/', 'request_id' => '/^[A-Za-z0-9._:-]{1,36}$/'] as $key => $pattern) {
            $value = (string) $request->get($key, '');

            if ($value !== '' && !preg_match($pattern, $value)) {
                return self::invalid($key);
            }

            $filters[$key] = $value;
        }

        $filters['audit_id'] = absint($request->get('audit_id', 0));
        $filters['user_id']  = absint($request->get('user_id', 0));
        $filters['search']   = sanitize_text_field((string) $request->get('search', ''));

        foreach (['from' => false, 'to' => true] as $key => $endOfDay) {
            $value = (string) $request->get($key, '');
            $date  = self::parseDate($value, $endOfDay);

            if ($value !== '' && $date === null) {
                return self::invalid($key);
            }

            $filters[$key] = $date ?? '';
        }

        $page    = max(1, absint($request->get('page', 1)));
        $perPage = max(1, min(ChangeStore::MAX_PER_PAGE, absint($request->get('per_page', self::DEFAULT_PER_PAGE))));
        $result  = ChangeStore::paginate($filters, $page, $perPage);

        return Response::success([
            'items'       => $result['items'],
            'total'       => $result['total'],
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($result['total'] / $perPage),
        ]);
    }

    public function show(Request $request): Response
    {
        $change = ChangeStore::find(absint($request->get('id', 0)));

        if ($change === null) {
            return Response::error(__('That change was not found.', 'mcpmate'), 404, 'mcpmate_change_not_found');
        }

        return Response::success(['change' => $change]);
    }

    private static function invalid(string $key): Response
    {
        return Response::error(
            /* translators: %s: filter name, e.g. object_type. */
            sprintf(__('The "%s" filter is invalid.', 'mcpmate'), $key),
            400,
            'mcpmate_invalid_filter'
        );
    }

    /**
     * YYYY-MM-DD (start or end of that UTC day) or an RFC 3339 date-time,
     * as `Y-m-d H:i:s` UTC; null when empty or invalid.
     */
    private static function parseDate(string $value, bool $endOfDay): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00') : null;
        }

        $timestamp = rest_parse_date($value);

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
