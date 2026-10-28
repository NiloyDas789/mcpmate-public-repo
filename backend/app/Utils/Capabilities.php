<?php

namespace McpMate\Utils;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress capability checker.
 */
final class Capabilities
{
    /**
     * @param mixed ...$args
     */
    public static function check(string $capability, ...$args): bool
    {
        return current_user_can($capability, ...$args);
    }
}
