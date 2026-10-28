<?php

namespace McpMate\Services\SearchReplace;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safe handling of PHP-serialized strings stored in the database.
 *
 * decode() never instantiates a class other than stdClass: any other object
 * becomes a `__PHP_Incomplete_Class` stub, so no `__wakeup()` /
 * `__destruct()` code runs (no PHP object injection). serialize() writes a
 * stub back with its original class name and properties.
 *
 * Pure PHP; no WordPress functions, so it is unit-tested directly.
 */
final class Serialized
{
    /** Deepest nesting decode() accepts. */
    public const MAX_DEPTH = 64;

    /**
     * Strict check modelled on core's is_serialized(), without trimming:
     * whitespace around the value means it is not one serialized value.
     */
    public static function isSerialized(string $value): bool
    {
        if ($value === 'N;') {
            return true;
        }

        if (strlen($value) < 4 || $value[1] !== ':') {
            return false;
        }

        $last = substr($value, -1);
        if ($last !== ';' && $last !== '}') {
            return false;
        }

        switch ($value[0]) {
            case 's':
                return substr($value, -2) === '";' && (bool) preg_match('/^s:\d+:"/', $value);
            case 'a':
            case 'O':
            case 'C':
                return (bool) preg_match('/^[aOC]:\d+:/', $value) && $last === '}';
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match('/^[bid]:[0-9.E+-]+;$/D', $value);
            default:
                return false;
        }
    }

    /**
     * Unserialize without running class code.
     *
     * @return array{ok: bool, value: mixed} ok is false for corrupt input.
     */
    public static function decode(string $value): array
    {
        if ($value === 'b:0;') {
            return ['ok' => true, 'value' => false];
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Only stdClass may be instantiated; corrupt values must not print a notice into the REST response.
        $decoded = @unserialize($value, ['allowed_classes' => ['stdClass'], 'max_depth' => self::MAX_DEPTH]);

        return ['ok' => $decoded !== false, 'value' => $decoded];
    }

    /**
     * @param mixed $value
     */
    public static function encode($value): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Writes a value back in the format it was stored in.
        return serialize($value);
    }
}
