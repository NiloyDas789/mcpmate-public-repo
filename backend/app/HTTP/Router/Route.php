<?php

namespace McpMate\HTTP\Router;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static facade for route registration.
 *
 * The active Router instance is injected by the Router constructor.
 * Route methods delegate to the router and return chainable objects
 * for attaching middleware.
 */
final class Route
{
    private static ?Router $router = null;

    /**
     * Set the active Router instance (called by Router::__construct).
     */
    public static function setRouter(Router $router): void
    {
        self::$router = $router;
    }

    /**
     * Register a POST route.
     */
    public static function post(string $path, array $handler): RouteEntry
    {
        return self::$router->addRoute('POST', $path, $handler);
    }

    /**
     * Register a GET route.
     */
    public static function get(string $path, array $handler): RouteEntry
    {
        return self::$router->addRoute('GET', $path, $handler);
    }

    /**
     * Register a PUT route.
     */
    public static function put(string $path, array $handler): RouteEntry
    {
        return self::$router->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a PATCH route.
     */
    public static function patch(string $path, array $handler): RouteEntry
    {
        return self::$router->addRoute('PATCH', $path, $handler);
    }

    /**
     * Register a DELETE route.
     */
    public static function delete(string $path, array $handler): RouteEntry
    {
        return self::$router->addRoute('DELETE', $path, $handler);
    }

    /**
     * Open a route group (all routes within share any middleware applied to the group).
     */
    public static function group(\Closure $fn): RouteGroup
    {
        return self::$router->group($fn);
    }
}
