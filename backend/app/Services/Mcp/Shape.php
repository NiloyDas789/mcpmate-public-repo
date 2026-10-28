<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded parsing of a route's answer before a tool formats it (the cloud
 * parsed every answer with a zod schema in src/sites/client.ts). A schema
 * is a callable `fn(mixed $value): mixed` built here; it returns the parsed
 * value or throws ShapeException. Objects keep only their schema's keys,
 * in schema order, and long strings are cut, so the formatters see exactly
 * what the cloud's saw.
 *
 * Values are JSON values (Js::fromPhp()). Parsed objects are associative
 * arrays; records and unknown values stay stdClass, so an empty one is `{}`.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages become JSON tool output or admin-ajax JSON, never HTML.
final class Shape
{
    /** What Js::get() returns for a key the input does not have. */
    private const MISSING = "\0missing";

    /**
     * Parse $value with $schema.
     *
     * @param mixed $value
     * @return mixed
     * @throws ShapeException
     */
    public static function parse(callable $schema, $value)
    {
        return $schema($value);
    }

    // ──────────────────────────────────────────────
    //  Scalars
    // ──────────────────────────────────────────────

    /**
     * A string cut to $max UTF-16 code units (`text(max)` in the cloud).
     */
    public static function text(int $max): callable
    {
        return static function ($value) use ($max) {
            self::expect(is_string($value), 'string');

            return Js::slice($value, $max);
        };
    }

    /**
     * A string of at most $max code units, refused when longer.
     */
    public static function string(?int $max = null): callable
    {
        return static function ($value) use ($max) {
            self::expect(is_string($value) && ($max === null || Js::length($value) <= $max), 'string');

            return $value;
        };
    }

    public static function regex(string $pattern): callable
    {
        return static function ($value) use ($pattern) {
            self::expect(is_string($value) && preg_match($pattern, $value) === 1, 'string matching ' . $pattern);

            return $value;
        };
    }

    /**
     * An integer, at least $min when given.
     */
    public static function int(?int $min = null): callable
    {
        return static function ($value) use ($min) {
            self::expect(is_int($value) && ($min === null || $value >= $min), 'integer');

            return $value;
        };
    }

    /** A non-negative integer (`count`). */
    public static function count(): callable
    {
        return self::int(0);
    }

    public static function positive(): callable
    {
        return self::int(1);
    }

    public static function bool(): callable
    {
        return static function ($value) {
            self::expect(is_bool($value), 'boolean');

            return $value;
        };
    }

    /**
     * @param mixed $literal
     */
    public static function literal($literal): callable
    {
        return static function ($value) use ($literal) {
            self::expect($value === $literal, 'literal');

            return $value;
        };
    }

    /**
     * @param array<int, string> $values
     */
    public static function enum(array $values): callable
    {
        return static function ($value) use ($values) {
            self::expect(is_string($value) && in_array($value, $values, true), 'one of ' . implode(', ', $values));

            return $value;
        };
    }

    /**
     * Anything; a missing key stays missing.
     */
    public static function unknown(): callable
    {
        return new ShapeOptional(static function ($value) {
            return $value;
        });
    }

    // ──────────────────────────────────────────────
    //  Modifiers
    // ──────────────────────────────────────────────

    public static function nullable(callable $schema): callable
    {
        return static function ($value) use ($schema) {
            return $value === null ? null : $schema($value);
        };
    }

    /** A key that may be missing (left out of the result, as JSON.stringify drops undefined). */
    public static function optional(callable $schema): callable
    {
        return new ShapeOptional($schema);
    }

    /** Missing (left out) or null. */
    public static function nullish(callable $schema): callable
    {
        return self::optional(self::nullable($schema));
    }

    /**
     * The first schema that accepts the value.
     *
     * @param callable[] $schemas
     */
    public static function union(array $schemas): callable
    {
        return static function ($value) use ($schemas) {
            foreach ($schemas as $schema) {
                try {
                    return $schema($value);
                } catch (ShapeException $e) {
                    continue;
                }
            }

            throw new ShapeException('no union member matched');
        };
    }

    public static function transform(callable $schema, callable $transform): callable
    {
        return static function ($value) use ($schema, $transform) {
            return $transform($schema($value));
        };
    }

    // ──────────────────────────────────────────────
    //  Containers
    // ──────────────────────────────────────────────

    /**
     * An object with exactly these keys (others dropped), in this order.
     *
     * @param array<string, callable> $shape
     */
    public static function object(array $shape): callable
    {
        return static function ($value) use ($shape) {
            self::expect(Js::isObject($value), 'object');

            $out = [];
            foreach ($shape as $key => $schema) {
                $item = Js::get($value, $key, self::MISSING);

                if ($item === self::MISSING) {
                    self::expect($schema instanceof ShapeOptional, $key);
                    continue;
                }

                $out[$key] = $schema($item);
            }

            return $out;
        };
    }

    /**
     * A list of at most $max items.
     */
    public static function lst(callable $item, int $max): callable
    {
        return static function ($value) use ($item, $max) {
            self::expect(is_array($value) && Js::isList($value) && count($value) <= $max, 'list of at most ' . $max);

            return array_map($item, $value);
        };
    }

    /**
     * A record: keys of at most $keyMax characters, values parsed by $item,
     * at most $maxKeys entries (null: any number). Stays a stdClass.
     */
    public static function record(callable $item, ?int $maxKeys = null, ?int $keyMax = null): callable
    {
        return static function ($value) use ($item, $maxKeys, $keyMax) {
            self::expect($value instanceof \stdClass || Js::isObject($value), 'record');

            $entries = Js::entries($value);
            self::expect($maxKeys === null || count($entries) <= $maxKeys, 'at most ' . $maxKeys . ' keys');

            $out = new \stdClass();
            foreach ($entries as [$key, $entry]) {
                self::expect($keyMax === null || Js::length($key) <= $keyMax, 'key');
                $out->{$key} = $item($entry);
            }

            return $out;
        };
    }

    private static function expect(bool $ok, string $what): void
    {
        if (!$ok) {
            throw new ShapeException('expected ' . $what);
        }
    }
}
// phpcs:enable
