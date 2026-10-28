<?php

namespace McpMate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal .env file loader.
 *
 * Reads key=value pairs from a flat file, auto-prefixes each key with
 * Config::VAR_PREFIX, and stores them in $_ENV. Values are cast to their
 * natural PHP type (int, float, bool, or string).
 */
final class Dotenv
{
    public static function load(string $path = ''): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and lines without =
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            // Strip inline comments
            $commentPos = strpos($line, ' #');
            if ($commentPos !== false) {
                $line = (string) substr($line, 0, $commentPos);
            }

            [$name, $value] = explode('=', trim($line), 2);

            $name  = Config::VAR_PREFIX . trim($name);
            $value = self::castValue(trim($value));

            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
            }
        }

        return true;
    }

    /**
     * Cast string values to their natural PHP types.
     *
     * @return mixed
     */
    private static function castValue(string $value)
    {
        if (is_numeric($value)) {
            return $value + 0;
        }

        switch (strtolower($value)) {
            case 'true':
                return true;
            case 'false':
                return false;
            case 'null':
                return null;
            default:
                return $value;
        }
    }
}
