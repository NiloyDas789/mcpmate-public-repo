<?php

namespace McpMate\HTTP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request type detection — identifies whether the current request
 * is an AJAX call, a REST API call, or an admin page request.
 */
final class RequestType
{
    public const AJAX  = 'ajax';
    public const API   = 'api';
    public const ADMIN = 'admin';

    public static function is(string $type): bool
    {
        switch ($type) {
            case self::AJAX:
                return wp_doing_ajax();
            case self::API:
                return defined('REST_REQUEST') && REST_REQUEST;
            case self::ADMIN:
                return is_admin();
            default:
                return false;
        }
    }
}
