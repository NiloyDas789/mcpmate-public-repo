<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Connection\RestGuard;

/**
 * GET /ping — a check that the plugin's REST API answers (the setup
 * checks' loopback pings it without credentials). No MCP tool reaches it.
 *
 * Uses the default capability (manage_options): the response discloses the
 * PHP version, which core only shows to administrators (Site Health).
 * `auth` says how the request authenticated (RestGuard::authMethod()).
 */
final class PingController extends RestController
{
    public function ping(Request $request): Response
    {
        $user = wp_get_current_user();

        return $this->ok([
            'plugin_version' => Config::VERSION,
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'site_url'       => Config::get('SITE_URL'),
            'user'           => [
                'login' => $user->user_login,
                'roles' => array_values($user->roles),
            ],
            'auth'           => RestGuard::authMethod(),
        ]);
    }
}
