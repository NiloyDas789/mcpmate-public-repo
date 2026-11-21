<?php

namespace MCPMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\HTTP\Request\Request;
use MCPMate\HTTP\Response;
use MCPMate\Services\DbInspect\DbInspectSettings;

/**
 * Admin settings: whether the /db REST routes may be used.
 *
 * AJAX routes (nonce + admin), used by the Settings page.
 */
final class DbInspectController
{
    public function show(Request $request): Response
    {
        return Response::success(['enabled' => DbInspectSettings::isEnabled()]);
    }

    public function update(Request $request): Response
    {
        // FormData sends booleans as "true" / "false".
        $enabled = filter_var($request->get('enabled', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($enabled === null) {
            return Response::error(__('The setting is invalid.', 'mcpmate'), 400, 'mcpmate_invalid_setting');
        }

        return Response::success([
            'message' => __('Database inspection setting saved.', 'mcpmate'),
            'enabled' => DbInspectSettings::update($enabled),
        ]);
    }
}
