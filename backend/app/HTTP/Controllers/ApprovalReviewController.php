<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\ErrorPresenter;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Approvals\ApprovalException;
use McpMate\Services\Approvals\Approvals;
use McpMate\Services\Approvals\ApprovalStore;

/**
 * Admin review of approval requests (the Approvals page).
 *
 * AJAX routes (nonce + admin): admin-ajax only accepts the logged-in
 * browser session, and a bearer token never sets a user there (McpRoute),
 * so the AI client cannot approve its own requests.
 */
final class ApprovalReviewController
{
    public const DEFAULT_PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $status = sanitize_key((string) $request->get('status', ''));
        $page   = max(1, absint($request->get('page', 1)));

        if ($status !== '' && !in_array($status, ApprovalStore::STATUSES, true)) {
            return Response::error(__('Unknown approval status.', 'mcpmate'), 400, 'mcpmate_invalid_status');
        }

        $result = Approvals::list($status, $page, self::DEFAULT_PER_PAGE);

        return Response::success([
            'items'    => $result['items'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => self::DEFAULT_PER_PAGE,
        ]);
    }

    public function show(Request $request): Response
    {
        $approval = Approvals::get(absint($request->get('id', 0)));

        if ($approval === null) {
            return Response::error(__('Approval request not found.', 'mcpmate'), 404, ApprovalException::NOT_FOUND);
        }

        return Response::success(['approval' => $approval]);
    }

    public function approve(Request $request): Response
    {
        return $this->decide($request, true);
    }

    public function reject(Request $request): Response
    {
        return $this->decide($request, false);
    }

    private function decide(Request $request, bool $approve): Response
    {
        $id = absint($request->get('id', 0));

        try {
            $approval = $approve
                ? Approvals::approve($id, get_current_user_id())
                : Approvals::reject($id, get_current_user_id());
        } catch (ApprovalException $e) {
            return Response::error(ErrorPresenter::plain($e), $e->status(), $e->errorCode());
        }

        return Response::success(['approval' => $approval]);
    }
}
