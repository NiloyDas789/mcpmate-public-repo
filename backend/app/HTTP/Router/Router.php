<?php

namespace McpMate\HTTP\Router;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Controllers\Api\RestController;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Connection\AccessPause;
use McpMate\Services\Connection\RestGuard;
use McpMate\Services\Mcp\McpContext;
use McpMate\Services\Tools\ToolCatalog;
use McpMate\Services\Tools\ToolSettings;

/**
 * Route registration and dispatch engine.
 *
 * Supports two request types:
 *  - ajax : registers WordPress wp_ajax_* hooks
 *  - api  : registers WordPress REST API routes via register_rest_route()
 *
 * Both are default-deny: a route without middleware, or with a middleware
 * alias that is not registered, is rejected before the controller runs.
 *
 * Middleware specs are `alias` or `alias:param1,param2`; the params are passed
 * to the middleware's handle() after the Request (e.g. `can:edit_posts`).
 */
final class Router
{
    private string $type;
    private string $prefix;
    private string $version;

    /** @var array<int, array{method: string, path: string, handler: array, middlewares: string[], args: array}> */
    private array $routes = [];

    /** @var array<string, class-string> */
    private array $middlewareMap = [];

    public function __construct(string $type, string $prefix, string $version)
    {
        $this->type    = $type;
        $this->prefix  = $prefix;
        $this->version = $version;

        Route::setRouter($this);
    }

    /**
     * Register available middleware classes (name => class).
     *
     * @param array<string, class-string> $middlewares
     */
    public function setMiddlewares(array $middlewares): void
    {
        $this->middlewareMap = $middlewares;
    }

    /**
     * Add a route definition to the collection.
     */
    public function addRoute(string $method, string $path, array $handler): RouteEntry
    {
        $index          = count($this->routes);
        $this->routes[] = [
            'method'      => $method,
            'path'        => $path,
            'handler'     => $handler,
            'middlewares' => [],
            'args'        => [],
        ];

        return new RouteEntry($this, $index);
    }

    /**
     * Open a route group.
     *
     * Snapshot the current route count, execute the closure (which registers
     * routes), then return a RouteGroup that knows which routes were added.
     */
    public function group(\Closure $fn): RouteGroup
    {
        $startIndex = count($this->routes);
        $fn();
        $endIndex = count($this->routes);

        return new RouteGroup($this, $startIndex, $endIndex);
    }

    /**
     * Attach middleware names to routes in a specific index range.
     *
     * @param string[] $names
     */
    public function applyMiddlewaresToRange(int $start, int $end, array $names): void
    {
        for ($i = $start; $i < $end; $i++) {
            if (isset($this->routes[$i])) {
                $this->routes[$i]['middlewares'] = array_merge(
                    $this->routes[$i]['middlewares'],
                    $names
                );
            }
        }
    }

    /**
     * Attach middleware to a single route by index.
     *
     * @param string[] $names
     */
    public function applyMiddlewaresToIndex(int $index, array $names): void
    {
        if (isset($this->routes[$index])) {
            $this->routes[$index]['middlewares'] = array_merge(
                $this->routes[$index]['middlewares'],
                $names
            );
        }
    }

    /**
     * Set the REST `args` schema for a single route by index.
     *
     * @param array<string, array<string, mixed>> $args
     */
    public function applyArgsToIndex(int $index, array $args): void
    {
        if (isset($this->routes[$index])) {
            $this->routes[$index]['args'] = $args;
        }
    }

    /**
     * Register all collected routes with WordPress.
     */
    public function register(): void
    {
        if ($this->type === 'ajax') {
            $this->registerAjaxRoutes();
        } elseif ($this->type === 'api') {
            $this->registerRestRoutes();
        }
    }

    // ──────────────────────────────────────────────
    //  AJAX Registration
    // ──────────────────────────────────────────────

    private function registerAjaxRoutes(): void
    {
        // Read the current AJAX action to support dynamic path-param routes.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Only reads 'action' for route matching; NonceCheckerMiddleware verifies the nonce before dispatch.
        $currentAction = isset($_POST['action'])
            ? sanitize_text_field(wp_unslash($_POST['action']))
            : '';
        // phpcs:enable

        foreach ($this->routes as $route) {
            if ($this->pathHasParams($route['path'])) {
                // Dynamic route: only register if the current request matches
                $params = $this->matchPathParams($currentAction, $this->prefix . $route['path']);
                if ($params !== null) {
                    $actionName     = $currentAction;
                    $capturedRoute  = $route;
                    $capturedParams = $params;

                    add_action('wp_ajax_' . $actionName, function () use ($capturedRoute, $capturedParams) {
                        $this->dispatch($capturedRoute, $capturedParams);
                    });
                }
            } else {
                // Static route
                $actionName    = $this->prefix . $route['path'];
                $capturedRoute = $route;

                add_action('wp_ajax_' . $actionName, function () use ($capturedRoute) {
                    $this->dispatch($capturedRoute, []);
                });
            }
        }
    }

    // ──────────────────────────────────────────────
    //  REST API Registration
    // ──────────────────────────────────────────────

    private function registerRestRoutes(): void
    {
        $namespace = $this->prefix . ($this->version ? '/' . $this->version : '');

        foreach ($this->routes as $route) {
            $restPath      = $this->pathToRestPattern($route['path']);
            $capturedRoute = $route;

            register_rest_route($namespace, '/' . $restPath, [
                'methods'             => $route['method'],
                'args'                => $route['args'],
                'callback'            => function (\WP_REST_Request $wpRequest) use ($capturedRoute) {
                    return $this->dispatchRest($capturedRoute, $wpRequest);
                },
                'permission_callback' => function (\WP_REST_Request $wpRequest) use ($capturedRoute) {
                    return $this->authorizeRestRequest($capturedRoute, $wpRequest);
                },
            ]);
        }
    }

    /**
     * Authorize REST route access (the route's permission_callback).
     *
     * Secure by default: REST routes are denied unless they explicitly declare
     * middleware. Route middleware can include a special `public` marker to
     * allow unauthenticated access (no route under REST_NS uses it).
     *
     * Denials return 401 for anonymous requests and 403 for logged-in users
     * (via rest_authorization_required_code()). A middleware hint is passed on
     * as `data.hint` for 403s only; it describes what a signed-in user lacks.
     *
     * Every request while an MCP call runs is checked against the matched
     * route first (authorizeMcp()), so default-deny stays in one place.
     *
     * @return bool|\WP_Error
     */
    private function authorizeRestRequest(array $route, \WP_REST_Request $wpRequest)
    {
        $middlewares = $route['middlewares'] ?? [];

        if (McpContext::active()) {
            $mcp = $this->authorizeMcp($route, $wpRequest);

            if ($mcp !== true) {
                return $mcp;
            }
        }

        if (in_array('public', $middlewares, true)) {
            return true;
        }

        $result = $this->runMiddlewares($middlewares, Request::fromRest($wpRequest));

        if ($result === true) {
            return true;
        }

        $status = $result->getStatusCode();

        if ($status === 401 || $status === 403) {
            $status = rest_authorization_required_code();
        }

        $data = ['status' => $status];

        if ($status !== 401 && $result->getHint() !== '') {
            $data['hint'] = $result->getHint();
        }

        return new \WP_Error(
            $result->getCode() ?: 'rest_forbidden',
            $result->getMessage() ?: __('Sorry, you are not allowed to do that.', 'mcpmate'),
            $data
        );
    }

    /**
     * Route, tool and scope checks while an MCP call runs
     * (docs/mcp-in-plugin.md §6.1): only the requests its tool dispatched
     * (McpContext::dispatch(), object identity) reach a route, and only a
     * route of a tool that is on and in the grant's list; without
     * site:write, only reads. The route's own middleware then runs as the
     * grant's user.
     *
     * @return true|\WP_Error
     */
    private function authorizeMcp(array $route, \WP_REST_Request $wpRequest)
    {
        $tool = ToolCatalog::toolForRoute($route['method'], $route['path']);

        if (!McpContext::owns($wpRequest) || $tool === null) {
            return RestGuard::error(RestGuard::ROUTE_NOT_ALLOWED, 403);
        }

        if (AccessPause::isPaused()) {
            return RestGuard::error(RestGuard::ACCESS_PAUSED, 403);
        }

        if (!ToolSettings::isEnabled($tool)) {
            return RestGuard::error(RestGuard::TOOL_DISABLED, 403);
        }

        if (!McpContext::allowsTool($tool)) {
            return RestGuard::error(RestGuard::TOOL_NOT_ALLOWED, 403);
        }

        [$controllerClass, $method] = $route['handler'];

        $readsOnly = is_subclass_of($controllerClass, RestController::class)
            ? $controllerClass::readsOnly($method, Request::fromRest($wpRequest))
            : in_array(strtoupper($wpRequest->get_method()), ['GET', 'HEAD'], true);

        return $readsOnly || McpContext::canWrite() ? true : RestGuard::error(RestGuard::INSUFFICIENT_SCOPE, 403);
    }

    // ──────────────────────────────────────────────
    //  Middleware
    // ──────────────────────────────────────────────

    /**
     * Run a middleware chain. Returns true when every middleware passes, or the
     * first denial Response. An empty chain or an unknown alias is a denial.
     *
     * @param string[] $middlewares
     * @return Response|true
     */
    private function runMiddlewares(array $middlewares, Request $request)
    {
        $denied = Response::error(__('Sorry, you are not allowed to do that.', 'mcpmate'), 403, 'rest_forbidden');

        if (empty($middlewares)) {
            return $denied;
        }

        foreach ($middlewares as $spec) {
            if ($spec === 'public') {
                continue;
            }

            [$name, $params] = $this->parseMiddlewareSpec($spec);

            $middlewareClass = $this->middlewareMap[$name] ?? null;
            if (!$middlewareClass || !class_exists($middlewareClass) || !method_exists($middlewareClass, 'handle')) {
                return $denied;
            }

            // Extra arguments are ignored by handle() methods that do not declare them.
            $result = (new $middlewareClass())->handle($request, ...$params);

            if ($result instanceof Response) {
                return $result;
            }

            if ($result !== true) {
                return $denied;
            }
        }

        return true;
    }

    /**
     * Split `alias:param1,param2` into the alias and its params.
     *
     * @return array{0: string, 1: string[]}
     */
    private function parseMiddlewareSpec(string $spec): array
    {
        $parts = explode(':', $spec, 2);

        if (!isset($parts[1]) || $parts[1] === '') {
            return [$parts[0], []];
        }

        return [$parts[0], array_map('trim', explode(',', $parts[1]))];
    }

    // ──────────────────────────────────────────────
    //  Dispatch — AJAX
    // ──────────────────────────────────────────────

    private function dispatch(array $route, array $pathParams): void
    {
        $request = new Request();

        $result = $this->runMiddlewares($route['middlewares'], $request);

        if ($result instanceof Response) {
            $result->send();

            return;
        }

        // Invoke controller method
        [$controllerClass, $method] = $route['handler'];
        $controller = new $controllerClass();
        $response   = $controller->{$method}($request, ...$pathParams);

        $response->send();
    }

    // ──────────────────────────────────────────────
    //  Dispatch — REST API
    // ──────────────────────────────────────────────

    private function dispatchRest(array $route, \WP_REST_Request $wpRequest): \WP_REST_Response
    {
        $request = Request::fromRest($wpRequest);

        // Extract URL path params from WP_REST_Request (in declaration order)
        $pathParams = array_values($wpRequest->get_url_params());

        [$controllerClass, $method] = $route['handler'];
        $controller = new $controllerClass();

        // MCP controllers go through dispatch(), which writes the audit log.
        $response = $controller instanceof RestController
            ? $controller->dispatch($method, $request, $pathParams, (string) ToolCatalog::toolForRoute($route['method'], $route['path']))
            : $controller->{$method}($request, ...$pathParams);

        return $response->toRestResponse();
    }

    // ──────────────────────────────────────────────
    //  Path Utilities
    // ──────────────────────────────────────────────

    private function pathHasParams(string $path): bool
    {
        return str_contains($path, '{');
    }

    /**
     * Try to match a concrete action string against a parameterised pattern.
     *
     * Example:
     *   pattern = "MCPMATE_items/{id}"
     *   action  = "MCPMATE_items/42"
     *   returns = ['42']
     *
     * @return string[]|null  Extracted values, or null if no match.
     */
    private function matchPathParams(string $action, string $pattern): ?array
    {
        // Split on {param} placeholders, quote each literal segment, then rejoin
        // with a capture group. preg_quote must NOT run on the whole pattern
        // first, because it would escape { and } making them unmatchable.
        $parts  = preg_split('/\{[^}]+\}/', $pattern);
        $quoted = array_map(fn (string $p) => preg_quote($p, '#'), $parts);
        $regex  = implode('([^/]+)', $quoted);

        if (!preg_match('#^' . $regex . '$#', $action, $matches)) {
            return null;
        }

        array_shift($matches); // Remove full-match entry

        return $matches;
    }

    /**
     * Convert "items/{id}" → "items/(?P<id>[^/]+)" for REST routes.
     */
    private function pathToRestPattern(string $path): string
    {
        return preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
    }
}
