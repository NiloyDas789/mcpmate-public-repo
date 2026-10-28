<?php

namespace McpMate\Services\Stats;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\ChangeHistory\ChangeStore;
use McpMate\Services\Settings\Preferences;
use McpMate\Services\Tools\ToolRegistry;

/**
 * The Dashboard's numbers (GET /stats, docs/ui.md §5.1): tool calls and
 * success rate over the last 24 hours, tools turned on, and this week's
 * changes by object type. Cached for CACHE_SECONDS in a transient.
 *
 * Tool calls are audit rows of MCP calls (`auth` oauth) that reached a
 * tool's handler (`mcp_tool` set). Refused requests never reach a handler, so
 * they are not counted (known gap 5), and reads count only while "Also
 * log read calls" is on.
 */
final class Stats
{
    public const CACHE_SECONDS = 300;

    private const TRANSIENT = 'stats';

    /**
     * @return array<string, mixed>
     */
    public static function summary(): array
    {
        $cached = get_transient(Config::withPrefix(self::TRANSIENT));

        if (is_array($cached)) {
            return $cached;
        }

        $summary = self::compute();
        set_transient(Config::withPrefix(self::TRANSIENT), $summary, self::CACHE_SECONDS);

        return $summary;
    }

    public static function forget(): void
    {
        delete_transient(Config::withPrefix(self::TRANSIENT));
    }

    /**
     * @return array<string, mixed>
     */
    private static function compute(): array
    {
        $table = Logger::table();
        $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $rows = DB::getResults(
            "SELECT kind, result, COUNT(*) AS n FROM `{$table}` WHERE created_at >= %s AND auth = %s AND mcp_tool <> '' GROUP BY kind, result",
            [$since, 'oauth'],
            ARRAY_A
        );

        $counts = ['read' => 0, 'write' => 0, Logger::RESULT_OK => 0, Logger::RESULT_ERROR => 0, Logger::RESULT_PENDING => 0];
        foreach ($rows as $row) {
            $n = (int) $row['n'];

            if (isset($counts[(string) $row['kind']])) {
                $counts[(string) $row['kind']] += $n;
            }

            if (isset($counts[(string) $row['result']])) {
                $counts[(string) $row['result']] += $n;
            }
        }

        $decided = $counts[Logger::RESULT_OK] + $counts[Logger::RESULT_ERROR];
        $byType  = ChangeStore::countsByType(gmdate('Y-m-d H:i:s', time() - WEEK_IN_SECONDS));

        return [
            'calls_24h'    => [
                'total'   => $counts['read'] + $counts['write'],
                'reads'   => $counts['read'],
                'writes'  => $counts['write'],
                'ok'      => $counts[Logger::RESULT_OK],
                'error'   => $counts[Logger::RESULT_ERROR],
                'pending' => $counts[Logger::RESULT_PENDING],
            ],
            // ok / (ok + error); pending approvals are left out. Null with no decided calls.
            'success_rate_24h' => $decided > 0 ? round($counts[Logger::RESULT_OK] / $decided, 4) : null,
            'tools'            => ToolRegistry::counts(),
            'changes_week'     => [
                'total'   => array_sum($byType),
                'by_type' => (object) $byType,
            ],
            'log_reads'        => Preferences::logReads(),
            'change_history'   => Preferences::changeHistory(),
            'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }
}
