<?php

namespace MCPMate\Console\Commands;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Printing for the `wp mcpmate` commands.
 */
final class Output
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string[]                         $fields
     */
    public static function items(array $rows, array $fields, string $format): void
    {
        if ($format === 'table' || $format === 'csv') {
            // Tables and CSV take scalars only.
            $rows = array_map(static fn (array $row): array => array_map([self::class, 'scalar'], $row), $rows);
        }

        \WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    /**
     * @param mixed $value
     */
    public static function scalar($value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
    }
}
