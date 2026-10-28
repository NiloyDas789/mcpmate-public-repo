<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Approvals\ApprovalException;
use McpMate\Services\Approvals\Approvals;
use McpMate\Services\Approvals\ApprovalToken;

/**
 * POST /approvals/{id}/status { token } — the requester polls an approval.
 *
 * POST so the token travels in the body, never in a URL or access log. It
 * only reads, so it is not audited (READ_HANDLERS); the decision and the
 * execution are audited when they happen in wp-admin.
 *
 * The route needs edit_posts (the lowest capability that can create an
 * approval). The handler then answers only the user who created the
 * approval and only with the right token; anything else is the same 404,
 * so IDs cannot be probed.
 *
 * There is deliberately no REST route that approves or rejects: decisions
 * are made by a logged-in admin in wp-admin (nonce-checked AJAX), where a
 * bearer token never sets a user (McpRoute).
 */
final class ApprovalsController extends RestController
{
    protected const CAPABILITIES = [
        'status' => 'edit_posts',
    ];

    protected const READ_HANDLERS = ['status'];

    /**
     * @param int|string $id
     */
    public function status(Request $request, $id): Response
    {
        $approval = Approvals::forRequester((int) $id, (string) $request->get('token', ''), get_current_user_id());

        if ($approval === null) {
            return $this->error(
                __('Approval request not found.', 'mcpmate'),
                404,
                ApprovalException::NOT_FOUND,
                __('Use the approval_id and approval_token returned with the 202 response, from the same WordPress account.', 'mcpmate')
            );
        }

        return $this->ok($approval);
    }

    /**
     * REST `args` schema for status().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function statusArgs(): array
    {
        return [
            'id'    => [
                'type'     => 'integer',
                'minimum'  => 1,
                'required' => true,
            ],
            'token' => [
                'type'        => 'string',
                'description' => 'The approval_token from the 202 pending_approval response.',
                'required'    => true,
                'pattern'     => ApprovalToken::PATTERN,
            ],
        ];
    }
}
