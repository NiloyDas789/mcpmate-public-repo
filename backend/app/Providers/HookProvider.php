<?php

namespace McpMate\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Hooks\Hooks;
use McpMate\HTTP\RequestType;
use McpMate\HTTP\RestEnvelope;
use McpMate\HTTP\Router\Router;
use McpMate\Plugin;
use McpMate\Services\Approvals\ApprovalStore;
use McpMate\Services\AuditLog\Retention;
use McpMate\Services\Connection\GrantRevoker;
use McpMate\Services\Connection\Grants;
use McpMate\Services\OAuth\AuthorizationRequests;
use McpMate\Services\OAuth\Clients;
use McpMate\Services\OAuth\OAuthRoutes;
use McpMate\Services\OAuth\Tokens;

/**
 * Registers AJAX routes, REST API routes, and WordPress hooks.
 *
 * Route files are loaded lazily — AJAX routes are only included during
 * AJAX requests, and REST routes during REST API requests.
 */
final class HookProvider
{
    private string $backendPath;

    public function __construct()
    {
        $this->backendPath = Config::get('BASEDIR') . DIRECTORY_SEPARATOR;

        $this->loadAjaxRoutes();
        Hooks::addAction('rest_api_init', [$this, 'loadApiRoutes']);
        RestEnvelope::register();
        Retention::register();
        ApprovalStore::register();
        Hooks::addAction(Retention::HOOK, [Grants::class, 'purge']);
        Hooks::addAction(Retention::HOOK, [Tokens::class, 'purge']);
        Hooks::addAction(Retention::HOOK, [AuthorizationRequests::class, 'purge']);
        Hooks::addAction(Retention::HOOK, [Clients::class, 'purge']);
        GrantRevoker::register();
        OAuthRoutes::register();

        if (Config::getEnv('CLI_ACTIVE')) {
            $cliPath = __DIR__ . '/../../../cli/RegisterCommands.php';
            if (is_readable($cliPath)) {
                include_once $cliPath;
            }
        }
    }

    /**
     * Register REST API routes (fires on `rest_api_init`).
     *
     * Not gated on REST_REQUEST: internal requests via rest_do_request()
     * (block-editor preloading, tests) also need the routes registered.
     */
    public function loadApiRoutes(): void
    {
        $apiFile = $this->backendPath . 'hooks' . DIRECTORY_SEPARATOR . 'api.php';

        if (is_readable($apiFile)) {
            $router = new Router(RequestType::API, Config::SLUG, 'v' . Config::API_VERSION);
            $router->setMiddlewares(Plugin::instance()->middlewares());

            include $apiFile;
            $router->register();
        }
    }

    /**
     * Register AJAX routes (only during AJAX requests).
     */
    private function loadAjaxRoutes(): void
    {
        $ajaxFile = $this->backendPath . 'hooks' . DIRECTORY_SEPARATOR . 'ajax.php';

        if (RequestType::is(RequestType::AJAX) && is_readable($ajaxFile)) {
            $router = new Router(RequestType::AJAX, Config::VAR_PREFIX, '');
            $router->setMiddlewares(Plugin::instance()->middlewares());

            include $ajaxFile;
            $router->register();
        }
    }
}
