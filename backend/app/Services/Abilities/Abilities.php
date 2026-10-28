<?php

namespace McpMate\Services\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The registered abilities McpMate can see: WordPress's Abilities API
 * (core since 6.9), limited to abilities whose author exposed them over
 * HTTP (`meta.show_in_rest`, which `meta.public` seeds since 7.1). An
 * ability kept out of REST does not exist for McpMate, even when enabled.
 */
final class Abilities
{
    /**
     * Whether this WordPress has the Abilities API (6.9+, or the feature plugin).
     */
    public static function isSupported(): bool
    {
        return function_exists('wp_get_abilities') && class_exists('WP_Ability');
    }

    /**
     * Every ability exposed over REST, keyed and sorted by name. Empty when
     * the API is missing.
     *
     * @return array<string, \WP_Ability>
     */
    public static function all(): array
    {
        if (!self::isSupported()) {
            return [];
        }

        $abilities = [];
        foreach (wp_get_abilities() as $ability) {
            if ($ability->get_meta_item('show_in_rest', false) === true) {
                $abilities[$ability->get_name()] = $ability;
            }
        }
        ksort($abilities);

        return $abilities;
    }

    public static function find(string $name): ?\WP_Ability
    {
        // Not wp_get_ability(): it reports an unknown name with _doing_it_wrong().
        return self::all()[$name] ?? null;
    }

    public static function risk(\WP_Ability $ability): string
    {
        return AbilityRisk::classify($ability->get_name(), self::annotations($ability));
    }

    /**
     * What a caller needs to choose and call an ability. Schemas are the
     * ability's own JSON Schema; an empty one is null.
     *
     * @return array<string, mixed>
     */
    public static function describe(\WP_Ability $ability, bool $withSchemas = true): array
    {
        $described = [
            'name'        => $ability->get_name(),
            'label'       => $ability->get_label(),
            'description' => $ability->get_description(),
            'category'    => $ability->get_category(),
            'risk'        => self::risk($ability),
            'enabled'     => AbilitySettings::isEnabled($ability->get_name()),
            'annotations' => self::annotations($ability),
        ];

        if ($withSchemas) {
            $described['input_schema']  = $ability->get_input_schema() !== [] ? $ability->get_input_schema() : null;
            $described['output_schema'] = $ability->get_output_schema() !== [] ? $ability->get_output_schema() : null;
        }

        return $described;
    }

    /**
     * Whether a described ability matches the list filters: $search
     * (case-insensitive, in the name, label or description) and $category
     * (exact). Empty filters match everything.
     *
     * @param array{name: string, label: string, description: string, category: string} $described
     */
    public static function matches(array $described, string $search, string $category): bool
    {
        if ($category !== '' && $described['category'] !== $category) {
            return false;
        }

        if ($search === '') {
            return true;
        }

        $haystack = $described['name'] . "\n" . $described['label'] . "\n" . $described['description'];

        return mb_stripos($haystack, $search, 0, 'UTF-8') !== false;
    }

    /**
     * The three annotations WordPress defines, each true, false or null (unknown).
     *
     * @return array{readonly: bool|null, destructive: bool|null, idempotent: bool|null}
     */
    private static function annotations(\WP_Ability $ability): array
    {
        $annotations = $ability->get_meta_item('annotations', []);
        $annotations = is_array($annotations) ? $annotations : [];
        $flag        = static fn ($value) => is_bool($value) ? $value : null;

        return [
            'readonly'    => $flag($annotations['readonly'] ?? null),
            'destructive' => $flag($annotations['destructive'] ?? null),
            'idempotent'  => $flag($annotations['idempotent'] ?? null),
        ];
    }
}
