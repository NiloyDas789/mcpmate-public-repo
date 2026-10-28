<?php

namespace McpMate\HTTP\Middleware;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;

/**
 * Verifies the WordPress AJAX nonce on every protected request.
 */
final class NonceCheckerMiddleware
{
    /**
     * @return Response|bool
     */
    public function handle(Request $request)
    {
        $nonce = sanitize_text_field(wp_unslash((string) $request->get('_ajax_nonce', '')));

        if (
            !$request->has('_ajax_nonce')
            || !wp_verify_nonce($nonce, Config::withPrefix('nonce'))
        ) {
            return Response::error(__('Invalid nonce token.', 'mcpmate'), 403, 'mcpmate_invalid_nonce');
        }

        return true;
    }
}
