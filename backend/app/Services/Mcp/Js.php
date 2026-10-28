<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JavaScript semantics the tool port needs to produce the cloud's output
 * byte for byte (docs/mcp-in-plugin.md §7.1): JSON values as JSON.parse()
 * makes them, JSON.stringify(), string lengths and slices in UTF-16 code
 * units, and `\s` / trim() with JavaScript's whitespace. Pure: no
 * WordPress calls.
 *
 * JSON values are PHP lists for arrays and stdClass for objects, so an
 * empty object stays `{}`. Associative arrays built in PHP count as
 * objects too (they are never empty where that matters).
 */
final class Js
{
    /** JavaScript's `\s`: ASCII whitespace plus the Unicode spaces and line terminators. */
    public const SPACE = '\t\n\x{000B}\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE;

    // ──────────────────────────────────────────────
    //  Values
    // ──────────────────────────────────────────────

    /**
     * $value as JSON.parse() would give it after a JSON round trip: objects
     * as stdClass with JavaScript's key order (integer-like keys first),
     * JsonSerializable resolved, integral floats as integers.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function fromPhp($value)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON.stringify()'s exact output; wp_json_encode() adds its own checks. Pure, used without WordPress.
        $json = json_encode($value, self::JSON_FLAGS);

        return $json === false ? null : self::decode($json);
    }

    /**
     * @return mixed The decoded JSON, objects as stdClass in JavaScript's key order.
     */
    public static function decode(string $json)
    {
        return self::normalize(json_decode($json, false, 512, JSON_BIGINT_AS_STRING));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize($value)
    {
        if (is_array($value)) {
            return array_map([self::class, 'normalize'], $value);
        }

        if ($value instanceof \stdClass) {
            $object = new \stdClass();

            foreach (self::entries($value) as [$key, $item]) {
                $object->{$key} = self::normalize($item);
            }

            return $object;
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 9007199254740992) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    public static function isList($value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }

    /**
     * A JSON object: stdClass, or a non-empty associative array.
     *
     * @param mixed $value
     */
    public static function isObject($value): bool
    {
        return $value instanceof \stdClass || (is_array($value) && $value !== [] && !self::isList($value));
    }

    /**
     * Object.entries(): [key, value] pairs, integer-like keys first in
     * ascending order, then the rest in insertion order.
     *
     * @param \stdClass|array<array-key, mixed> $object
     * @return array<int, array{0: string, 1: mixed}>
     */
    public static function entries($object): array
    {
        $indexes = [];
        $others  = [];

        foreach ((is_array($object) ? $object : get_object_vars($object)) as $key => $item) {
            $key = (string) $key;

            if (preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $key) && (int) $key < 4294967295) {
                $indexes[(int) $key] = [$key, $item];
            } else {
                $others[] = [$key, $item];
            }
        }

        ksort($indexes);

        return array_merge(array_values($indexes), $others);
    }

    /**
     * Object.keys().length for objects, .length for lists.
     *
     * @param mixed $value
     */
    public static function size($value): int
    {
        if ($value instanceof \stdClass) {
            return count(get_object_vars($value));
        }

        return is_array($value) ? count($value) : 0;
    }

    /**
     * A property of a JSON object (stdClass or array), or $default.
     *
     * @param mixed $object
     * @param mixed $default
     * @return mixed
     */
    public static function get($object, string $key, $default = null)
    {
        if ($object instanceof \stdClass) {
            return property_exists($object, $key) ? $object->{$key} : $default;
        }

        return is_array($object) && array_key_exists($key, $object) ? $object[$key] : $default;
    }

    /**
     * An object from pairs; stdClass, so an empty one encodes as `{}`.
     *
     * @param array<int, array{0: string, 1: mixed}> $entries
     */
    public static function fromEntries(array $entries): \stdClass
    {
        $object = new \stdClass();

        foreach ($entries as [$key, $item]) {
            $object->{$key} = $item;
        }

        return $object;
    }

    // ──────────────────────────────────────────────
    //  JSON.stringify
    // ──────────────────────────────────────────────

    /**
     * JSON.stringify($value): no escaped slashes or Unicode, integral
     * floats as integers, `null` for anything JSON cannot hold.
     *
     * @param mixed $value
     */
    public static function stringify($value): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- As above.
        $json = json_encode(self::forJson($value), self::JSON_FLAGS);

        return $json === false ? 'null' : $json;
    }

    /**
     * typeof $value, as the cloud's fit() names a value too large to show.
     *
     * @param mixed $value
     */
    public static function typeOf($value): string
    {
        if (is_string($value)) {
            return 'string';
        }

        if (is_int($value) || is_float($value)) {
            return 'number';
        }

        return is_bool($value) ? 'boolean' : 'object';
    }

    /**
     * String(number).
     *
     * @param int|float $number
     */
    public static function number($number): string
    {
        if (is_int($number)) {
            return (string) $number;
        }

        return self::stringify($number);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function forJson($value)
    {
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return floor($value) === $value && abs($value) < 9007199254740992 ? (int) $value : $value;
        }

        if (is_array($value)) {
            return array_map([self::class, 'forJson'], $value);
        }

        if ($value instanceof \stdClass) {
            $object = new \stdClass();

            foreach (self::entries($value) as [$key, $item]) {
                $object->{$key} = self::forJson($item);
            }

            return $object;
        }

        return $value;
    }

    // ──────────────────────────────────────────────
    //  Strings (UTF-16 lengths, JavaScript whitespace)
    // ──────────────────────────────────────────────

    /**
     * .length: UTF-16 code units.
     */
    public static function length(string $text): int
    {
        $length = mb_strlen($text, 'UTF-8');

        if (strlen($text) === $length || !preg_match('/[\x{10000}-\x{10FFFF}]/u', $text)) {
            return $length;
        }

        return $length + (int) preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);
    }

    /**
     * .slice(0, $units): the first $units UTF-16 code units. A surrogate
     * pair cut in half is dropped.
     */
    public static function slice(string $text, int $units): string
    {
        if ($units <= 0) {
            return '';
        }

        if (!preg_match('/[\x{10000}-\x{10FFFF}]/u', $text)) {
            return mb_substr($text, 0, $units, 'UTF-8');
        }

        $out = '';
        foreach ((array) preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $width = strlen((string) $char) === 4 ? 2 : 1;

            if ($units < $width) {
                break;
            }

            $out   .= $char;
            $units -= $width;
        }

        return $out;
    }

    /**
     * .trim() with JavaScript's whitespace.
     */
    public static function trim(string $text): string
    {
        return (string) preg_replace('/^[' . self::SPACE . ']+|[' . self::SPACE . ']+$/u', '', $text);
    }

    /**
     * .trimStart().
     */
    public static function trimStart(string $text): string
    {
        return (string) preg_replace('/^[' . self::SPACE . ']+/u', '', $text);
    }

    /**
     * Whether $text has JavaScript whitespace at the start or the end.
     */
    public static function hasEdgeSpace(string $text): bool
    {
        return (bool) preg_match('/^[' . self::SPACE . ']|[' . self::SPACE . ']$/u', $text);
    }

    /**
     * .split($separator).length - 1.
     */
    public static function occurrences(string $text, string $needle): int
    {
        return substr_count($text, $needle);
    }
}
