<?php

namespace McpMate\Services\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Block attribute definitions (block.json `attributes`) as JSON Schema.
 *
 * Core validates attributes with rest_validate_value_from_schema() before a
 * block renders (WP_Block_Type::prepare_attributes_for_render()). Attribute
 * definitions are not strictly JSON Schema, though: `rich-text` is a type
 * (6.5+), and `source`, `selector` or `role` sit next to the schema keywords.
 * forValidation() keeps only what that function understands, so a definition
 * it cannot read is skipped instead of tripping _doing_it_wrong().
 */
final class AttributeSchema
{
    /** The types rest_validate_value_from_schema() accepts. */
    public const JSON_TYPES = ['array', 'object', 'string', 'number', 'integer', 'boolean', 'null'];

    /** Attribute types that are stored as another JSON type. */
    private const TYPE_ALIASES = ['rich-text' => 'string'];

    /** Keywords copied as they are when their value has the expected PHP type. */
    private const SCALAR_KEYWORDS = [
        'minimum'          => 'is_numeric',
        'maximum'          => 'is_numeric',
        'exclusiveMinimum' => 'is_bool',
        'exclusiveMaximum' => 'is_bool',
        'multipleOf'       => 'is_numeric',
        'minLength'        => 'is_int',
        'maxLength'        => 'is_int',
        'pattern'          => 'is_string',
        'format'           => 'is_string',
        'minItems'         => 'is_int',
        'maxItems'         => 'is_int',
        'uniqueItems'      => 'is_bool',
        'minProperties'    => 'is_int',
        'maxProperties'    => 'is_int',
    ];

    /** Enum values listed by describe() and in hints. */
    public const MAX_ENUM_VALUES = 50;

    /** Defaults larger than this (as JSON) are left out of describe(). */
    public const MAX_DEFAULT_BYTES = 200;

    /**
     * The checkable part of an attribute definition, or null when it has no
     * type rest_validate_value_from_schema() knows.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>|null
     */
    public static function forValidation(array $definition): ?array
    {
        $types = self::types($definition['type'] ?? null);
        if ($types === null) {
            return null;
        }

        $schema = ['type' => count($types) === 1 ? $types[0] : $types];

        if (isset($definition['enum']) && is_array($definition['enum'])) {
            $schema['enum'] = array_values($definition['enum']);
        }

        foreach (self::SCALAR_KEYWORDS as $keyword => $check) {
            if (isset($definition[$keyword]) && $check($definition[$keyword])) {
                $schema[$keyword] = $definition[$keyword];
            }
        }

        // Draft 4 `required` lists property names; draft 3 marks the property itself.
        if (isset($definition['required']) && (is_bool($definition['required']) || self::isListOfStrings($definition['required']))) {
            $schema['required'] = $definition['required'];
        }

        // A tuple (a list of schemas) is not something core validates.
        if (isset($definition['items']) && is_array($definition['items']) && !self::isList($definition['items'])) {
            $items = self::forValidation($definition['items']);
            if ($items !== null) {
                $schema['items'] = $items;
            }
        }

        if (isset($definition['properties']) && is_array($definition['properties'])) {
            $properties = [];
            foreach ($definition['properties'] as $name => $property) {
                $property = is_array($property) ? self::forValidation($property) : null;
                if (is_string($name) && $property !== null) {
                    $properties[$name] = $property;
                }
            }

            if ($properties !== []) {
                $schema['properties'] = $properties;
            }
        }

        if (isset($definition['additionalProperties'])) {
            $additional = $definition['additionalProperties'];
            if (is_bool($additional)) {
                $schema['additionalProperties'] = $additional;
            } elseif (is_array($additional) && ($additional = self::forValidation($additional)) !== null) {
                $schema['additionalProperties'] = $additional;
            }
        }

        return $schema;
    }

    /**
     * What a client needs to write the attribute: type, allowed values,
     * default, and for sourced attributes where in the HTML the value lives.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public static function describe(array $definition): array
    {
        $out = [];

        $type = $definition['type'] ?? null;
        if (is_string($type) || self::isListOfStrings($type)) {
            $out['type'] = $type;
        }

        if (isset($definition['enum']) && is_array($definition['enum'])) {
            $out['enum'] = array_slice(array_values($definition['enum']), 0, self::MAX_ENUM_VALUES);
        }

        if (array_key_exists('default', $definition)) {
            $json = wp_json_encode($definition['default']);
            if (is_string($json) && strlen($json) <= self::MAX_DEFAULT_BYTES) {
                $out['default'] = $definition['default'];
            }
        }

        foreach (['source', 'selector', 'attribute'] as $key) {
            if (isset($definition[$key]) && is_string($definition[$key])) {
                $out[$key] = $definition[$key];
            }
        }

        return $out;
    }

    /**
     * "Expected …" text for a hint: the type and the allowed values.
     *
     * @param array<string, mixed> $definition
     */
    public static function expected(array $definition): string
    {
        $type = $definition['type'] ?? null;
        $type = is_array($type) ? implode(' or ', array_filter($type, 'is_string')) : (is_string($type) ? $type : '');

        if (isset($definition['enum']) && is_array($definition['enum'])) {
            $values = array_map(
                static fn ($value): string => (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                array_slice(array_values($definition['enum']), 0, self::MAX_ENUM_VALUES)
            );

            /* translators: %s: comma-separated list of JSON values. */
            return sprintf(__('Expected one of: %s.', 'mcpmate'), implode(', ', $values));
        }

        if ($type === '') {
            return '';
        }

        /* translators: %s: a JSON type such as "integer" or "string or null". */
        return sprintf(__('Expected a JSON %s.', 'mcpmate'), $type);
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @param mixed $type
     * @return string[]|null
     */
    private static function types($type): ?array
    {
        if (is_string($type)) {
            $type = [$type];
        }

        if (!is_array($type) || $type === []) {
            return null;
        }

        $types = [];
        foreach ($type as $one) {
            $one = is_string($one) ? (self::TYPE_ALIASES[$one] ?? $one) : null;

            // One unknown type makes the whole definition uncheckable.
            if (!in_array($one, self::JSON_TYPES, true)) {
                return null;
            }

            $types[] = $one;
        }

        return array_values(array_unique($types));
    }

    /**
     * @param mixed $value
     */
    private static function isList($value): bool
    {
        return is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    }

    /**
     * @param mixed $value
     */
    private static function isListOfStrings($value): bool
    {
        return is_array($value) && $value !== [] && self::isList($value) && count(array_filter($value, 'is_string')) === count($value);
    }
}
