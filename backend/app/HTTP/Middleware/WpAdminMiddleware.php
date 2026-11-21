<?php

namespace MCPMate\HTTP\Middleware;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\HTTP\Request\Request;
use MCPMate\HTTP\Response;
use MCPMate\Services\Connection\RestGuard;
use MCPMate\Services\Mcp\McpContext;

/**
 * Accepts only a site admin's own request from wp-admin (alias `wpAdmin`):
 * never a request an MCP tool dispatched, whatever its grant's user may do.
 *
 * For REST routes that change what AI apps may do (POST /tools) or show
 * the site admin's own overview (GET /stats). A cookie request already
 * needs core's `X-WP-Nonce`. Use it next to the route's `can:` spec; it
 * does not check a capability itself.
 */
final class WpAdminMiddleware
{
    /**
     * @return Response|bool
     */
    public function handle(Request $request)
    {
        if (!McpContext::active()) {
            return true;
        }

        return Response::error(
            __('Only a site admin in wp-admin can do this.', 'mcpmate'),
            403,
            RestGuard::ROUTE_NOT_ALLOWED
        );
    }
}
