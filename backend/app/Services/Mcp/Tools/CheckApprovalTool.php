<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Approvals\Approvals;
use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\McpContext;
use McpMate\Services\Mcp\Shape;
use McpMate\Services\Mcp\SiteSchemas;
use McpMate\Services\Mcp\ToolResult;
use McpMate\Services\Mcp\Url;

/**
 * check_approval (cloud checkApproval.ts). The approval row records who
 * asked and through which connection, so the lookup needs no token
 * (docs/mcp-in-plugin.md §7.3): another user or grant gets the same
 * "not found". It only reads; approving happens in wp-admin alone.
 */
final class CheckApprovalTool extends Tool
{
    private const RESULT_BYTES = 16000;

    public function run(\stdClass $args): ToolResult
    {
        $id   = (int) $args->approval_id;
        // No route: McpAudit writes the read row (and the refusal when not found).
        $view = Approvals::forGrant($id, McpContext::userId(), McpContext::grantId());

        if ($view === null) {
            return ToolResult::error(
                'approval_unknown',
                'Approval #' . $id . ' was not requested on this site through this connection.',
                null,
                'Check the approval_id. If the operation is still wanted, ask the user, then run the command again.'
            );
        }

        return self::out(self::format($this->siteId, $this->siteUrl, Shape::parse(SiteSchemas::approval(), Js::fromPhp($view))));
    }

    /**
     * @param array<string, mixed> $approval
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, string $siteUrl, array $approval): array
    {
        $status = $approval['status'];
        $link   = $status === 'pending' ? Url::approvalLink($siteUrl, $approval['approval_url'] ?? null, $approval['id']) : null;

        $structured = [
            'site_id'      => $siteId,
            'approval_id'  => $approval['id'],
            'status'       => $status,
            'action'       => C::oneLine($approval['action'], 100),
            'target'       => C::oneLine($approval['target'], 191),
            'expires_at'   => $approval['expires_at'],
            'decided_at'   => $approval['decided_at'],
            'executed_at'  => $approval['executed_at'],
            'approval_url' => $link,
            'result'       => $status === 'executed' ? C::fit($approval['result'] ?? null, self::RESULT_BYTES) : null,
        ];

        $what = 'Approval #' . $approval['id'] . ' (' . $structured['action'] . ($structured['target'] === '' ? '' : ' on ' . $structured['target']) . ')';

        switch ($status) {
            case 'pending':
                $text = C::finish(
                    [$what . ' is still waiting for a site admin (expires ' . ($approval['expires_at'] ?? 'soon') . ').', 'Approval link: ' . ($link ?? '')],
                    Url::pendingNextStep($approval['id'])
                );
                break;
            case 'approved':
                $text = C::finish([$what . ' was approved and is running.'], 'Check again in a few seconds for the result.');
                break;
            case 'executed':
                $text = C::finish(
                    [
                        $what . ' was approved and has run (at ' . ($approval['executed_at'] ?? 'an unknown time') . '). Its result:',
                        C::siteData('operation result', implode("\n", C::render($structured['result']))),
                    ],
                    'Tell the user the outcome. If the result reports an error, the change may not have happened.'
                );
                break;
            case 'rejected':
                $text = C::finish([$what . ' was rejected by a site admin. Nothing changed.'], 'Tell the user. Do not request it again unless they ask you to.');
                break;
            default:
                $text = C::finish(
                    [$what . ' expired before a site admin decided. Nothing changed.'],
                    'Ask the user whether to request it again; if so, run the command again for a new approval.'
                );
        }

        return ['text' => $text, 'structured' => $structured];
    }
}
