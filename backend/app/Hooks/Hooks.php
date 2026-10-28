<?php

namespace McpMate\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin static wrapper around WordPress hook functions.
 */
final class Hooks
{
    /**
     * @param callable|mixed[] $callback
     */
    public static function addAction(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        add_action($hook, $callback, $priority, $acceptedArgs);
    }

    /**
     * @param callable|mixed[] $callback
     */
    public static function addFilter(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        add_filter($hook, $callback, $priority, $acceptedArgs);
    }

    /**
     * @param mixed ...$args
     */
    public static function doAction(string $hook, ...$args): void
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Callers pass prefixed hook names via Config::withPrefix().
        do_action($hook, ...$args);
    }

    /**
     * @param mixed $value
     * @param mixed ...$args
     * @return mixed
     */
    public static function applyFilter(string $hook, $value, ...$args)
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Callers pass prefixed hook names via Config::withPrefix().
        return apply_filters($hook, $value, ...$args);
    }

    /**
     * @param callable|mixed[] $callback
     */
    public static function removeAction(string $hook, $callback, int $priority = 10): bool
    {
        return remove_action($hook, $callback, $priority);
    }

    /**
     * @param callable|mixed[] $callback
     */
    public static function removeFilter(string $hook, $callback, int $priority = 10): bool
    {
        return remove_filter($hook, $callback, $priority);
    }
}
