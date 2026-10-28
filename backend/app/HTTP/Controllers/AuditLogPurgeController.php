<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\AuditLog\Retention;

/**
 * "Remove entries older than 90 days now" on the Audit log (docs/ui.md
 * §5.4.1, B9). AJAX routes (nonce + admin).
 *
 * Both run Retention's own query with the configured window, so nothing
 * else can be deleted: no row is picked by hand, and rows inside the
 * window stay. `audit-log/purge` writes an audit row with the count.
 */
final class AuditLogPurgeController
{
    public function preview(Request $request): Response
    {
        return Response::success(['count' => Retention::expired()] + self::window());
    }

    public function purge(Request $request): Response
    {
        $removed = Retention::purge();

        Logger::log([
            'tool'   => 'audit_log',
            'action' => 'purge',
            'target' => 'audit_log',
            'args'   => ['removed' => $removed] + self::window(),
            'result' => Logger::RESULT_OK,
        ]);

        return Response::success(['removed' => $removed] + self::window());
    }

    /**
     * The window in days (below 1: every row is kept) and its cutoff.
     *
     * @return array{days: int, older_than: string|null}
     */
    private static function window(): array
    {
        $days = Retention::days();

        return [
            'days'       => $days,
            // UTC, ISO 8601.
            'older_than' => $days > 0 ? gmdate('Y-m-d\TH:i:s\Z', time() - $days * DAY_IN_SECONDS) : null,
        ];
    }
}
