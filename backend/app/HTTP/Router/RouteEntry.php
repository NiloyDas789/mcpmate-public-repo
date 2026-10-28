<?php

namespace McpMate\HTTP\Router;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Represents a single registered route.
 * Holds a reference back to the Router so that chaining ->middleware()
 * on a single route applies middleware only to that specific route.
 */
final class RouteEntry
{
    private Router $router;
    private int $index;

    public function __construct(Router $router, int $index)
    {
        $this->router = $router;
        $this->index  = $index;
    }

    /**
     * Attach middleware to this individual route (chainable).
     *
     * @return static
     */
    public function middleware(string ...$names)
    {
        $this->router->applyMiddlewaresToIndex($this->index, $names);

        return $this;
    }

    /**
     * Declare the REST `args` schema for this route (chainable).
     *
     * WordPress validates and sanitizes each arg before the permission and
     * route callbacks run; the controller reads the cleaned values via
     * Request::get(). Ignored for AJAX routes.
     *
     * @param array<string, array<string, mixed>> $args register_rest_route() args.
     * @return static
     */
    public function args(array $args)
    {
        $this->router->applyArgsToIndex($this->index, $args);

        return $this;
    }
}
