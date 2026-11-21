<?php

namespace MCPMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\HTTP\Request\Request;
use MCPMate\HTTP\Response;
use MCPMate\Services\Uninstall\UninstallReview;

/**
 * "Before you uninstall" (Settings → Advanced, `?review=uninstall`; P21).
 * Admin-ajax only (nonce + admin): `uninstall/summary` says what Remove all
 * data on uninstall deletes and what stays, `uninstall/export {kind, after,
 * until}` gives the audit log or change history a page at a time by ID (the
 * first call fixes `until` and writes an audit row). Nothing is deleted or
 * written here.
 */
final class UninstallReviewController
{
    public function summary(Request $request): Response
    {
        return Response::success(UninstallReview::summary());
    }

    public function export(Request $request): Response
    {
        $kind = sanitize_key((string) $request->get('kind', ''));

        if (!in_array($kind, UninstallReview::KINDS, true)) {
            return Response::error(__('Choose the audit log or change history to export.', 'mcpmate'), 400, 'mcpmate_invalid_export');
        }

        return Response::success(UninstallReview::export($kind, absint($request->get('after', 0)), absint($request->get('until', 0))));
    }
}
