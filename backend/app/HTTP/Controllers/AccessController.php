<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Connection\AccessPause;

/**
 * Pause and resume AI access (docs/ui.md B3). AJAX routes (nonce + admin):
 * bearer tokens never set a user on admin-ajax, so no AI app can resume
 * its own access. Both are audited (AccessPause).
 */
final class AccessController
{
    public function show(Request $request): Response
    {
        return Response::success(['access' => AccessPause::summary()]);
    }

    public function pause(Request $request): Response
    {
        AccessPause::pause(get_current_user_id());

        return Response::success([
            'message' => __('AI access paused.', 'mcpmate'),
            'access'  => AccessPause::summary(),
        ]);
    }

    public function resume(Request $request): Response
    {
        AccessPause::resume(get_current_user_id());

        return Response::success([
            'message' => __('AI access resumed.', 'mcpmate'),
            'access'  => AccessPause::summary(),
        ]);
    }
}
