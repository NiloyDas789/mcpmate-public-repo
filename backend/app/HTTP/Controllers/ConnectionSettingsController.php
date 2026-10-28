<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Connection\Connection;
use McpMate\Services\OAuth\AuthorizationRequests;
use McpMate\Services\OAuth\OAuthConsent;

/**
 * The MCP URL and the AI-app approval screen.
 *
 * Approve and reject take `request`, an authorization request of the
 * plugin's own OAuth server (docs/mcp-in-plugin.md §4.4).
 *
 * AJAX routes (nonce + admin). Bearer tokens never set a user on
 * admin-ajax, so an AI client cannot approve its own connection.
 */
final class ConnectionSettingsController
{
    public function status(Request $request): Response
    {
        return Response::success(['connection' => Connection::summary()]);
    }

    public function approve(Request $request): Response
    {
        $readOnly = filter_var($request->get('read_only', false), FILTER_VALIDATE_BOOLEAN);
        $result   = OAuthConsent::approve(self::authorizationRequest($request), get_current_user_id(), $readOnly);

        return is_wp_error($result) ? self::error($result) : Response::success($result);
    }

    public function reject(Request $request): Response
    {
        $result = OAuthConsent::deny(self::authorizationRequest($request));

        return is_wp_error($result) ? self::error($result) : Response::success($result);
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * The `request` field: an authorization request ID, or '' when it is
     * missing or malformed (refused as invalid).
     */
    private static function authorizationRequest(Request $request): string
    {
        $id = $request->get('request', '');

        return is_string($id) && AuthorizationRequests::isId($id) ? $id : '';
    }

    private static function error(\WP_Error $error): Response
    {
        $data   = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

        return Response::error($error->get_error_message(), $status, (string) $error->get_error_code());
    }
}
