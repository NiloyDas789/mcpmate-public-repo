<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks a tool's arguments against its inputSchema (docs/mcp-tools.json)
 * and fills in defaults, as the cloud's SDK did with the zod schemas they
 * were made from. Covers exactly the JSON Schema keywords those schemas
 * use; InputSchemaTest fails when a new one appears. Pure.
 *
 * Issues read like zod's (`path: message`), so the model sees the same
 * kind of answer as before.
 */
final class InputSchema
{
    /** Every keyword the tool schemas use; anything else is a test failure, never silently ignored. */
    public const KEYWORDS = [
        '$schema', 'description', 'type', 'properties', 'required', 'additionalProperties', 'propertyNames',
        'default', 'minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'exclusiveMinimum',
        'enum', 'const', 'items', 'minItems', 'maxItems', 'anyOf', 'oneOf',
    ];

    /** @var string[] */
    private array $issues = [];

    /**
     * Validate $value; returns it with defaults filled in, or the issues.
     *
     * @param mixed    $value
     * @param \stdClass $schema
     * @return array{ok: true, value: mixed}|array{ok: false, issues: string[]}
     */
    public static function check($value, \stdClass $schema): array
    {
        $checker = new self();
        $result  = $checker->walk($value, $schema, []);

        return $checker->issues === [] ? ['ok' => true, 'value' => $result] : ['ok' => false, 'issues' => $checker->issues];
    }

    /**
     * @param mixed            $value
     * @param array<int, string|int> $path
     * @return mixed
     */
    private function walk($value, \stdClass $schema, array $path)
    {
        if (isset($schema->anyOf) || isset($schema->oneOf)) {
            return $this->union($value, $schema, $path);
        }

        if (isset($schema->const) && $value !== $schema->const) {
            $this->issue($path, 'Invalid input: expected ' . Js::stringify($schema->const));

            return $value;
        }

        if (isset($schema->enum) && !in_array($value, $schema->enum, true)) {
            $this->issue($path, 'Invalid option: expected one of ' . implode('|', array_map([Js::class, 'stringify'], $schema->enum)));

            return $value;
        }

        if (!isset($schema->type)) {
            return $value;
        }

        if (!$this->hasType($value, (string) $schema->type)) {
            $this->issue($path, 'Invalid input: expected ' . ($schema->type === 'integer' ? 'int' : $schema->type) . ', received ' . self::typeName($value));

            return $value;
        }

        switch ($schema->type) {
            case 'string':
                $this->string($value, $schema, $path);
                return $value;
            case 'integer':
            case 'number':
                $this->number($value, $schema, $path);
                return $value;
            case 'array':
                return $this->items($value, $schema, $path);
            case 'object':
                return $this->object($value, $schema, $path);
            default:
                return $value;
        }
    }

    /**
     * @param mixed                  $value
     * @param array<int, string|int> $path
     * @return mixed
     */
    private function union($value, \stdClass $schema, array $path)
    {
        $options = $schema->anyOf ?? $schema->oneOf;
        $matches = [];

        foreach ($options as $option) {
            $result = self::check($value, $option);

            if ($result['ok']) {
                $matches[] = $result['value'];
            }
        }

        if ($matches === [] || (isset($schema->oneOf) && count($matches) > 1)) {
            $this->issue($path, 'Invalid input');

            return $value;
        }

        return $matches[0];
    }

    /**
     * @param array<int, string|int> $path
     */
    private function string(string $value, \stdClass $schema, array $path): void
    {
        $length = Js::length($value);

        if (isset($schema->minLength) && $length < $schema->minLength) {
            $this->issue($path, 'Too small: expected string to have >=' . $schema->minLength . ' characters');
        }

        if (isset($schema->maxLength) && $length > $schema->maxLength) {
            $this->issue($path, 'Too big: expected string to have <=' . $schema->maxLength . ' characters');
        }

        if (isset($schema->pattern) && !self::matches((string) $schema->pattern, $value)) {
            $this->issue($path, 'Invalid string: must match pattern /' . $schema->pattern . '/');
        }
    }

    /**
     * @param int|float              $value
     * @param array<int, string|int> $path
     */
    private function number($value, \stdClass $schema, array $path): void
    {
        if (isset($schema->minimum) && $value < $schema->minimum) {
            $this->issue($path, 'Too small: expected number to be >=' . Js::number($schema->minimum));
        }

        if (isset($schema->exclusiveMinimum) && $value <= $schema->exclusiveMinimum) {
            $this->issue($path, 'Too small: expected number to be >' . Js::number($schema->exclusiveMinimum));
        }

        if (isset($schema->maximum) && $value > $schema->maximum) {
            $this->issue($path, 'Too big: expected number to be <=' . Js::number($schema->maximum));
        }
    }

    /**
     * @param mixed[]                $value
     * @param array<int, string|int> $path
     * @return mixed[]
     */
    private function items(array $value, \stdClass $schema, array $path): array
    {
        if (isset($schema->minItems) && count($value) < $schema->minItems) {
            $this->issue($path, 'Too small: expected array to have >=' . $schema->minItems . ' items');
        }

        if (isset($schema->maxItems) && count($value) > $schema->maxItems) {
            $this->issue($path, 'Too big: expected array to have <=' . $schema->maxItems . ' items');

            return $value;
        }

        if (!isset($schema->items) || !$schema->items instanceof \stdClass) {
            return $value;
        }

        foreach ($value as $index => $item) {
            $value[$index] = $this->walk($item, $schema->items, array_merge($path, [$index]));
        }

        return $value;
    }

    /**
     * @param array<int, string|int> $path
     */
    private function object(\stdClass $value, \stdClass $schema, array $path): \stdClass
    {
        $properties = isset($schema->properties) ? get_object_vars($schema->properties) : [];
        $out        = new \stdClass();
        $unknown    = [];

        foreach ($properties as $name => $property) {
            if (property_exists($value, $name)) {
                $out->{$name} = $this->walk($value->{$name}, $property, array_merge($path, [$name]));
            } elseif (property_exists($property, 'default')) {
                $out->{$name} = $property->default;
            } elseif (in_array($name, $schema->required ?? [], true)) {
                $type = isset($property->type) ? (string) $property->type : 'value';
                $this->issue(array_merge($path, [$name]), 'Invalid input: expected ' . ($type === 'integer' ? 'int' : $type) . ', received undefined');
            }
        }

        foreach (Js::entries($value) as [$name, $item]) {
            if (array_key_exists($name, $properties)) {
                continue;
            }

            $additional = $schema->additionalProperties ?? true;

            if ($additional === false) {
                $unknown[] = $name;
                continue;
            }

            if (isset($schema->propertyNames) && $schema->propertyNames instanceof \stdClass) {
                $this->walk($name, $schema->propertyNames, array_merge($path, [$name]));
            }

            $out->{$name} = $additional instanceof \stdClass ? $this->walk($item, $additional, array_merge($path, [$name])) : $item;
        }

        if ($unknown !== []) {
            $quoted = implode(', ', array_map(static fn (string $key): string => '"' . $key . '"', $unknown));
            $this->issue($path, (count($unknown) === 1 ? 'Unrecognized key: ' : 'Unrecognized keys: ') . $quoted);
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private function hasType($value, string $type): bool
    {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'integer':
                return is_int($value);
            case 'number':
                return is_int($value) || is_float($value);
            case 'boolean':
                return is_bool($value);
            case 'null':
                return $value === null;
            case 'array':
                return is_array($value);
            case 'object':
                return $value instanceof \stdClass;
            default:
                return false;
        }
    }

    /**
     * @param mixed $value
     */
    private static function typeName($value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_float($value)) {
            return 'number';
        }

        return Js::typeOf($value);
    }

    /**
     * A JSON Schema (ECMAScript) pattern, unanchored, on a UTF-8 string.
     */
    public static function matches(string $pattern, string $value): bool
    {
        return preg_match('~' . str_replace('~', '\~', $pattern) . '~Du', $value) === 1;
    }

    /**
     * @param array<int, string|int> $path
     */
    private function issue(array $path, string $message): void
    {
        $this->issues[] = $path === [] ? $message : implode('.', $path) . ': ' . $message;
    }
}
