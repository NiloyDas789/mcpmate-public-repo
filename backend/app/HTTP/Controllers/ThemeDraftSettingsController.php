<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\ThemeDraft\ThemeDraftSettings;

/**
 * Admin settings: whether the /theme-draft REST routes and preview links
 * may be used.
 *
 * AJAX routes (nonce + admin), used by the Settings page.
 */
final class ThemeDraftSettingsController
{
    public function show(Request $request): Response
    {
        return Response::success(['enabled' => ThemeDraftSettings::isEnabled()]);
    }

    public function update(Request $request): Response
    {
        // FormData sends booleans as "true" / "false".
        $enabled = filter_var($request->get('enabled', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($enabled === null) {
            return Response::error(__('The setting is invalid.', 'mcpmate'), 400, 'mcpmate_invalid_setting');
        }

        return Response::success([
            'message' => __('Theme drafts setting saved.', 'mcpmate'),
            'enabled' => ThemeDraftSettings::update($enabled),
        ]);
    }
}
