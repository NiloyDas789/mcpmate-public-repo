<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A route's answer does not have the shape its tool expects.
 */
final class ShapeException extends \RuntimeException
{
}
