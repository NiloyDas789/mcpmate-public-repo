<?php

namespace McpMate\HTTP\Router;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Represents a group of routes that share the same middleware.
 *
 * Usage in route files:
 *   Route::group(function(): void {
 *       Route::post('dashboard', [...]);
 *   })->middleware('nonce', 'isAdmin');
 */
final class RouteGroup
{
    private Router $router;
    private int $startIndex;
    private int $endIndex;

    public function __construct(Router $router, int $startIndex, int $endIndex)
    {
        $this->router     = $router;
        $this->startIndex = $startIndex;
        $this->endIndex   = $endIndex;
    }

    /**
     * Apply one or more middleware names to all routes registered within this group.
     *
     * @return static
     */
    public function middleware(string ...$names)
    {
        $this->router->applyMiddlewaresToRange($this->startIndex, $this->endIndex, $names);

        return $this;
    }
}
