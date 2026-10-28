<?php

namespace McpMate\HTTP\Middleware;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Response;
use McpMate\Utils\Capabilities;

/**
 * Ensures the current user has `manage_options` capability.
 */
final class AdminCheckerMiddleware
{
    /**
     * @return Response|bool
     */
    public function handle()
    {
        if (!Capabilities::check('manage_options')) {
            return Response::error(
                __('Access denied: administrator privileges required.', 'mcpmate'),
                403,
                'mcpmate_forbidden'
            );
        }

        return true;
    }
}
