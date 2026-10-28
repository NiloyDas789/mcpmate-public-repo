<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Elementor\ElementorPages;
use McpMate\Services\Elementor\ElementorSettings;

/**
 * Admin settings: whether the /elementor REST routes may be used.
 *
 * AJAX routes (nonce + admin), used by the Settings page.
 */
final class ElementorSettingsController
{
    public function show(Request $request): Response
    {
        return Response::success([
            'enabled'   => ElementorSettings::isEnabled(),
            'available' => ElementorPages::isAvailable(),
        ]);
    }

    public function update(Request $request): Response
    {
        // FormData sends booleans as "true" / "false".
        $enabled = filter_var($request->get('enabled', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($enabled === null) {
            return Response::error(__('The setting is invalid.', 'mcpmate'), 400, 'mcpmate_invalid_setting');
        }

        return Response::success([
            'message'   => __('Elementor setting saved.', 'mcpmate'),
            'enabled'   => ElementorSettings::update($enabled),
            'available' => ElementorPages::isAvailable(),
        ]);
    }
}
