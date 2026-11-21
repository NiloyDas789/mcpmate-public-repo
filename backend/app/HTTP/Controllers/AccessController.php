<?php

namespace MCPMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\HTTP\Request\Request;
use MCPMate\HTTP\Response;
use MCPMate\Services\Connection\AccessPause;
use MCPMate\Services\Connection\ReadMode;

/**
 * Pause and resume AI access (docs/ui.md B3), and read mode (P21). AJAX
 * routes (nonce + admin): bearer tokens never set a user on admin-ajax, so
 * no AI app can resume its own access or turn read mode off. Each change
 * is audited (AccessPause, ReadMode).
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

    public function readMode(Request $request): Response
    {
        return Response::success(['read_mode' => ReadMode::summary()]);
    }

    public function readModeOn(Request $request): Response
    {
        if (!ReadMode::on(get_current_user_id()) && !ReadMode::isOn()) {
            return Response::error(__('Read mode could not be saved. Try again.', 'mcpmate'), 500, 'mcpmate_read_mode_not_saved');
        }

        return Response::success([
            'message'   => __('Read mode is on. AI apps can read the site but not change it.', 'mcpmate'),
            'read_mode' => ReadMode::summary(),
        ]);
    }

    public function readModeOff(Request $request): Response
    {
        if (!ReadMode::off(get_current_user_id()) && ReadMode::isOn()) {
            return Response::error(__('Read mode could not be turned off. Try again.', 'mcpmate'), 500, 'mcpmate_read_mode_not_saved');
        }

        return Response::success([
            'message'   => __('Read mode is off.', 'mcpmate'),
            'read_mode' => ReadMode::summary(),
        ]);
    }
}
