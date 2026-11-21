<?php

namespace MCPMate\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Console\Registrar;
use MCPMate\Extensions\Features;
use MCPMate\Hooks\Hooks;
use MCPMate\HTTP\RequestType;
use MCPMate\HTTP\RestEnvelope;
use MCPMate\HTTP\Router\Router;
use MCPMate\Plugin;
use MCPMate\Services\Approvals\ApprovalStore;
use MCPMate\Services\AuditLog\Retention;
use MCPMate\Services\Connection\GrantRevoker;
use MCPMate\Services\Connection\Grants;
use MCPMate\Services\OAuth\AuthorizationRequests;
use MCPMate\Services\OAuth\Clients;
use MCPMate\Services\OAuth\OAuthRoutes;
use MCPMate\Services\OAuth\Tokens;

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

        // `wp mcpmate`: only while WP-CLI runs.
        Registrar::register();
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

            // MCPMate Pro's routes, on this router: its tools map them in ToolCatalog.
            foreach (Features::all() as $feature) {
                $feature->registerRestRoutes();
            }

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
