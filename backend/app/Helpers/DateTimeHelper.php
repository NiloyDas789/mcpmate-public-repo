<?php

namespace McpMate\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Date/time utilities using WordPress settings.
 */
final class DateTimeHelper
{
    /**
     * Return the WordPress timezone string.
     *
     * Returns the named timezone (e.g. "America/New_York") when set,
     * or falls back to a UTC offset string like "+05:30".
     */
    public static function timezoneString(): string
    {
        $tzstring = get_option('timezone_string');

        if ($tzstring) {
            return $tzstring;
        }

        $offset  = (float) get_option('gmt_offset');
        $hours   = (int) $offset;
        $minutes = (int) round(($offset - $hours) * 60);

        return sprintf('%+03d:%02d', $hours, abs($minutes));
    }
}
