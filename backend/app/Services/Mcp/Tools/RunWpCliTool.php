<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;
use McpMate\Services\Mcp\Url;

/**
 * run_wp_cli → POST cli (cloud runWpCli.ts). The policy layer has already
 * refused what McpMate never runs (Policy::classifyCliCommand()).
 */
final class RunWpCliTool extends Tool
{
    /** Budgets for the command's result: text rendering and structured copy. */
    private const RESULT_TEXT_BYTES = 16000;

    private const RESULT_STRUCTURED_BYTES = 16000;

    public function run(\stdClass $args): ToolResult
    {
        $reply = $this->client->cli($args->command, $args->dry_run ?? null);

        return self::out(self::format($this->siteId, $this->siteUrl, $reply['data']));
    }

    /**
     * @param array<string, mixed> $outcome The route's answer and `pending` (202).
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, string $siteUrl, array $outcome): array
    {
        $command = C::oneLine($outcome['command'], 100);
        $risk    = C::oneLine($outcome['risk'], 20);

        if ($outcome['pending']) {
            $link       = Url::approvalLink($siteUrl, $outcome['approval_url'], $outcome['approval_id']);
            $structured = [
                'site_id'  => $siteId,
                'status'   => 'pending_approval',
                'command'  => $command,
                'risk'     => $risk,
                'dry_run'  => false,
                'result'   => null,
                'approval' => [
                    'approval_id'  => $outcome['approval_id'],
                    'approval_url' => $link,
                    'expires_at'   => $outcome['expires_at'],
                    'preview'      => C::fit($outcome['preview'] ?? null, self::RESULT_STRUCTURED_BYTES),
                ],
            ];
            $text       = C::finish([
                '"' . $command . '" (' . $risk . ") is waiting for a site admin's approval: approval #" . $outcome['approval_id']
                    . ', expires ' . $outcome['expires_at'] . '. Nothing has changed yet.',
                'Approval link: ' . $link,
                'What it would do:',
                C::siteData('preview of the command', implode("\n", C::render(C::fit($outcome['preview'] ?? null, self::RESULT_TEXT_BYTES)))),
            ], Url::pendingNextStep($outcome['approval_id']));

            return ['text' => $text, 'structured' => $structured];
        }

        $result     = $outcome['result'] ?? null;
        $structured = [
            'site_id'  => $siteId,
            'status'   => 'ok',
            'command'  => $command,
            'risk'     => $risk,
            'dry_run'  => $outcome['dry_run'],
            'result'   => C::fit($result, self::RESULT_STRUCTURED_BYTES),
            'approval' => null,
        ];
        $shown      = C::fit($result, self::RESULT_TEXT_BYTES);
        $summarized = Js::stringify($shown) !== Js::stringify($result);
        $nextPage   = self::pageAfter($result);

        $next = null;
        if ($nextPage !== null) {
            $next = 'There are more results: run the same command with --paged=' . $nextPage . '.';
        } elseif ($summarized) {
            $next = 'The result was summarized ("… more" markers). Narrow it with the command’s flags (e.g. --status, --fields, --per_page) to see the rest.';
        } elseif ($outcome['dry_run'] && $risk === 'destructive') {
            $next = 'This was a preview. To make the change, run it again without dry_run; it will then wait for a site admin’s approval.';
        } elseif ($outcome['dry_run']) {
            $next = 'This was a preview. Run it again without dry_run to make the change.';
        }

        $text = C::finish(
            ['Ran "' . $command . '" (' . $risk . ($outcome['dry_run'] ? ', dry run: nothing changed' : '') . ').', C::siteData('command result', implode("\n", C::render($shown)))],
            $next
        );

        return ['text' => $text, 'structured' => $structured];
    }

    /**
     * The next page of a paginated list result (`page` / `total_pages`), if there is one.
     *
     * @param mixed $result
     */
    private static function pageAfter($result): ?int
    {
        if (!Js::isObject($result)) {
            return null;
        }

        $page  = Js::get($result, 'page');
        $total = Js::get($result, 'total_pages');

        return (is_int($page) || is_float($page)) && (is_int($total) || is_float($total)) && $page < $total ? (int) $page + 1 : null;
    }
}
