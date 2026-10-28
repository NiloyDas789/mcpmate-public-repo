<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;

/**
 * get_audit_log → GET audit-log (cloud getAuditLog.ts).
 */
final class GetAuditLogTool extends Tool
{
    public function run(\stdClass $args): ToolResult
    {
        $filters = isset($args->filters) ? (array) $args->filters : [];

        if (isset($filters['from'], $filters['to']) && substr($filters['from'], 0, 10) > substr($filters['to'], 0, 10)) {
            return ToolResult::invalidInput('filters.from is after filters.to.');
        }

        $reply = $this->client->auditLog($filters + ['page' => $args->page, 'per_page' => $args->per_page]);

        return self::out(self::format($this->siteId, $reply['data']));
    }

    /**
     * @param array<string, mixed> $log
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, array $log): array
    {
        $entries = array_map(static fn (array $item): array => [
            'id'         => $item['id'],
            'created_at' => $item['created_at'],
            'user_login' => $item['user_login'],
            'tool'       => $item['tool'],
            'action'     => $item['action'],
            'target'     => $item['target'],
            'result'     => $item['result'],
            'message'    => C::oneLine($item['message'], 200),
            'args'       => self::argsSummary($item['args'] ?? null),
            'request_id' => $item['request_id'],
        ], $log['items']);
        $nextPage = $log['page'] < $log['total_pages'] ? $log['page'] + 1 : null;

        $structured = [
            'site_id'     => $siteId,
            'entries'     => $entries,
            'total'       => $log['total'],
            'page'        => $log['page'],
            'per_page'    => $log['per_page'],
            'total_pages' => $log['total_pages'],
            'next_page'   => $nextPage,
        ];

        if ($entries === []) {
            $text = $log['total'] === 0
                ? 'The audit log has no entries matching these filters.'
                : 'Page ' . $log['page'] . ' is past the end (' . $log['total'] . ' entries, ' . $log['total_pages'] . ' pages).';

            return ['text' => C::finish([$text], $log['total'] === 0 ? null : 'Call again with page=1.'), 'structured' => $structured];
        }

        $first  = ($log['page'] - 1) * $log['per_page'] + 1;
        $header = 'Audit log, newest first: entries ' . $first . '–' . ($first + count($entries) - 1) . ' of ' . $log['total'] . ' (page ' . $log['page'] . ' of ' . $log['total_pages'] . ').';
        $lines  = array_map(static function (array $entry): string {
            $parts  = array_filter([
                '#' . $entry['id'] . ' ' . $entry['created_at'],
                $entry['user_login'] ?? '(unknown user)',
                $entry['tool'] . ($entry['action'] === '' ? '' : ' ' . $entry['action']),
                $entry['target'] === '' ? '' : 'target=' . $entry['target'],
                '→ ' . $entry['result'],
            ], static fn (string $part): bool => $part !== '');
            $detail = array_filter([
                $entry['message'] === '' ? '' : '  ' . $entry['message'],
                $entry['args'] === '' ? '' : '  args: ' . $entry['args'],
            ], static fn (string $line): bool => $line !== '');

            return implode("\n", array_merge([implode(' ', $parts)], $detail));
        }, $entries);

        $next = $nextPage === null ? null : 'For older entries, call get_audit_log again with page=' . $nextPage . ' (same filters).';

        return [
            'text'       => C::finish([$header, C::siteData('audit log entries', implode("\n", $lines))], $next, 'Use a smaller per_page or narrower filters.'),
            'structured' => $structured,
        ];
    }

    /**
     * The entry's arguments on one line; the route already redacted secrets in them.
     *
     * @param mixed $args
     */
    private static function argsSummary($args): string
    {
        if ($args === null || ((is_array($args) || $args instanceof \stdClass) && Js::size($args) === 0)) {
            return '';
        }

        return C::oneLine(Js::stringify($args), 200);
    }
}
