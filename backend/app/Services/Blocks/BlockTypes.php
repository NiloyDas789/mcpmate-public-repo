<?php

namespace McpMate\Services\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\SiteContext\BlockTree;

/**
 * The block types registered on the server (GET /blocks/types), with what a
 * client needs to write them: attribute schemas, where they may be placed,
 * and whether the block renders on the server (dynamic blocks save no HTML).
 *
 * Blocks registered only in JavaScript are not in the server registry, so
 * they are not listed, and BlockValidator reports them as unknown.
 */
final class BlockTypes
{
    public const MAX_DESCRIPTION_LENGTH = 300;

    /**
     * Registered block types, sorted by name.
     *
     * @param string $namespace Only names in this namespace (`core`, `woocommerce`…); '' for all.
     * @param string $search    Only names or titles containing this text (case-insensitive); '' for all.
     * @return array<int, array<string, mixed>>
     */
    public static function all(string $namespace = '', string $search = ''): array
    {
        $types = \WP_Block_Type_Registry::get_instance()->get_all_registered();
        ksort($types);

        $out = [];
        foreach ($types as $type) {
            if (!self::matches($type, $namespace, $search)) {
                continue;
            }

            $out[] = self::describe($type);
        }

        return $out;
    }

    /**
     * Number of registered block types per namespace.
     *
     * @return array<string, int>
     */
    public static function namespaces(): array
    {
        $counts = [];
        foreach (array_keys(\WP_Block_Type_Registry::get_instance()->get_all_registered()) as $name) {
            $namespace          = self::namespaceOf((string) $name);
            $counts[$namespace] = ($counts[$namespace] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(\WP_Block_Type $type): array
    {
        $attributes = [];
        foreach (is_array($type->attributes) ? $type->attributes : [] as $name => $definition) {
            if (is_array($definition)) {
                $attributes[(string) $name] = (object) AttributeSchema::describe($definition);
            }
        }

        return [
            'name'           => $type->name,
            'title'          => (string) $type->title,
            'description'    => BlockTree::clip(wp_strip_all_tags((string) $type->description), self::MAX_DESCRIPTION_LENGTH),
            'category'       => is_string($type->category) ? $type->category : null,
            'parent'         => self::names($type->parent),
            'ancestor'       => self::names($type->ancestor),
            // A block type property since WordPress 6.5 (?? keeps older versions quiet).
            'allowed_blocks' => self::names($type->allowed_blocks ?? null),
            'dynamic'        => $type->is_dynamic(),
            'attributes'     => (object) $attributes,
        ];
    }

    public static function namespaceOf(string $name): string
    {
        $slash = strpos($name, '/');

        return $slash === false ? '' : (string) substr($name, 0, $slash);
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    private static function matches(\WP_Block_Type $type, string $namespace, string $search): bool
    {
        if ($namespace !== '' && self::namespaceOf($type->name) !== $namespace) {
            return false;
        }

        if ($search === '') {
            return true;
        }

        return stripos($type->name, $search) !== false || stripos((string) $type->title, $search) !== false;
    }

    /**
     * @param mixed $names
     * @return string[]|null
     */
    private static function names($names): ?array
    {
        if (!is_array($names) || $names === []) {
            return null;
        }

        return array_values(array_filter($names, 'is_string'));
    }
}
