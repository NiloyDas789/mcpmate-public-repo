<?php

namespace McpMate\Services\SiteContext;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects page builders and SEO plugins, for the whole site and for one post.
 *
 * Pure functions over plain data (plugin slugs, theme slugs, post meta and
 * block names), so the rules can be tested without WordPress. A plugin slug
 * is its folder name (`elementor` for `elementor/elementor.php`); theme slugs
 * are compared case-insensitively (`Divi`).
 */
final class Detector
{
    /** In a `meta` signal: any non-empty value counts. */
    public const ANY_VALUE = '*';

    /**
     * Page builders. `plugins`/`themes` are site-level signals; `meta` (key →
     * expected value) and `blocks` (block-name prefixes) mark a post built with it.
     *
     * @var array<string, array{name: string, plugins: string[], themes: string[], meta: array<string, string>, blocks: string[]}>
     */
    public const BUILDERS = [
        'elementor'      => [
            'name'    => 'Elementor',
            'plugins' => ['elementor', 'elementor-pro'],
            'themes'  => [],
            'meta'    => ['_elementor_edit_mode' => 'builder'],
            'blocks'  => [],
        ],
        'divi'           => [
            'name'    => 'Divi',
            'plugins' => ['divi-builder'],
            'themes'  => ['divi', 'extra'],
            'meta'    => ['_et_pb_use_builder' => 'on'],
            'blocks'  => ['divi/'],
        ],
        'bricks'         => [
            'name'    => 'Bricks',
            'plugins' => [],
            'themes'  => ['bricks'],
            'meta'    => ['_bricks_editor_mode' => 'bricks', '_bricks_page_content_2' => self::ANY_VALUE],
            'blocks'  => [],
        ],
        'beaver_builder' => [
            'name'    => 'Beaver Builder',
            'plugins' => ['bb-plugin', 'beaver-builder-lite-version', 'bb-theme-builder'],
            'themes'  => ['bb-theme'],
            'meta'    => ['_fl_builder_enabled' => '1'],
            'blocks'  => [],
        ],
        'breakdance'     => [
            'name'    => 'Breakdance',
            'plugins' => ['breakdance'],
            'themes'  => [],
            'meta'    => ['_breakdance_data' => self::ANY_VALUE],
            'blocks'  => [],
        ],
        'seedprod'       => [
            'name'    => 'SeedProd',
            'plugins' => ['coming-soon', 'seedprod-coming-soon-pro-5'],
            'themes'  => [],
            'meta'    => ['_seedprod_page' => self::ANY_VALUE, '_seedprod_edited_with_seedprod' => self::ANY_VALUE],
            'blocks'  => [],
        ],
        'kadence'        => [
            'name'    => 'Kadence',
            'plugins' => ['kadence-blocks', 'kadence-blocks-pro', 'kadence-pro'],
            'themes'  => ['kadence'],
            'meta'    => [],
            'blocks'  => ['kadence/'],
        ],
        'generatepress'  => [
            'name'    => 'GeneratePress',
            'plugins' => ['gp-premium', 'generateblocks', 'generateblocks-pro'],
            'themes'  => ['generatepress'],
            'meta'    => [],
            'blocks'  => ['generateblocks/', 'generatepress/'],
        ],
    ];

    /**
     * SEO plugins (site-level plugin signals only).
     *
     * @var array<string, array{name: string, plugins: string[]}>
     */
    public const SEO_PLUGINS = [
        'yoast'             => ['name' => 'Yoast SEO', 'plugins' => ['wordpress-seo', 'wordpress-seo-premium']],
        'rank_math'         => ['name' => 'Rank Math', 'plugins' => ['seo-by-rank-math', 'seo-by-rank-math-pro']],
        'aioseo'            => ['name' => 'All in One SEO', 'plugins' => ['all-in-one-seo-pack', 'all-in-one-seo-pack-pro']],
        'seopress'          => ['name' => 'SEOPress', 'plugins' => ['wp-seopress', 'wp-seopress-pro']],
        'the_seo_framework' => ['name' => 'The SEO Framework', 'plugins' => ['autodescription']],
        'slim_seo'          => ['name' => 'Slim SEO', 'plugins' => ['slim-seo']],
        'smartcrawl'        => ['name' => 'SmartCrawl', 'plugins' => ['smartcrawl-seo']],
        'squirrly'          => ['name' => 'Squirrly SEO', 'plugins' => ['squirrly-seo']],
    ];

    // ──────────────────────────────────────────────
    //  Site level
    // ──────────────────────────────────────────────

    /**
     * Page builders present on the site, in BUILDERS order.
     *
     * @param array<string, string> $activePlugins Plugin slug → version.
     * @param array<string, string> $activeThemes  Theme slug → version (stylesheet and template).
     * @return array<int, array{id: string, name: string, version: string, sources: string[]}>
     */
    public static function siteBuilders(array $activePlugins, array $activeThemes): array
    {
        return self::match(self::BUILDERS, $activePlugins, $activeThemes);
    }

    /**
     * Active SEO plugins, in SEO_PLUGINS order. More than one usually means a conflict.
     *
     * @param array<string, string> $activePlugins Plugin slug → version.
     * @return array<int, array{id: string, name: string, version: string, sources: string[]}>
     */
    public static function seoPlugins(array $activePlugins): array
    {
        return self::match(self::SEO_PLUGINS, $activePlugins, []);
    }

    /**
     * Slug of a plugin file: its folder, or the file name for single-file plugins.
     */
    public static function pluginSlug(string $pluginFile): string
    {
        $dir = dirname($pluginFile);

        return $dir === '.' ? basename($pluginFile, '.php') : $dir;
    }

    // ──────────────────────────────────────────────
    //  Post level
    // ──────────────────────────────────────────────

    /**
     * The builder a post was made with: the first meta signal in BUILDERS
     * order, else the first block-name prefix found. Null when none match
     * (plain block editor or classic content).
     *
     * @param array<string, string> $meta       Meta key → first value.
     * @param string[]              $blockNames Every block name in the post.
     * @return array{id: string, name: string, signal: string}|null
     */
    public static function postBuilder(array $meta, array $blockNames): ?array
    {
        foreach (self::BUILDERS as $id => $builder) {
            foreach ($builder['meta'] as $key => $expected) {
                $value = isset($meta[$key]) ? (string) $meta[$key] : '';

                if ($value !== '' && ($expected === self::ANY_VALUE || $value === $expected)) {
                    return ['id' => $id, 'name' => $builder['name'], 'signal' => 'meta:' . $key];
                }
            }
        }

        foreach (self::BUILDERS as $id => $builder) {
            foreach ($builder['blocks'] as $prefix) {
                foreach ($blockNames as $name) {
                    if (str_starts_with($name, $prefix)) {
                        return ['id' => $id, 'name' => $builder['name'], 'signal' => 'block:' . $name];
                    }
                }
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @param array<string, array{name: string, plugins: string[], themes?: string[]}> $definitions
     * @param array<string, string> $activePlugins
     * @param array<string, string> $activeThemes
     * @return array<int, array{id: string, name: string, version: string, sources: string[]}>
     */
    private static function match(array $definitions, array $activePlugins, array $activeThemes): array
    {
        $themes = array_change_key_case($activeThemes, CASE_LOWER);
        $found  = [];

        foreach ($definitions as $id => $definition) {
            $sources = [];
            $version = '';

            foreach ($definition['plugins'] as $slug) {
                if (array_key_exists($slug, $activePlugins)) {
                    $sources[] = 'plugin:' . $slug;
                    $version   = $version !== '' ? $version : (string) $activePlugins[$slug];
                }
            }

            foreach ($definition['themes'] ?? [] as $slug) {
                if (array_key_exists($slug, $themes)) {
                    $sources[] = 'theme:' . $slug;
                    $version   = $version !== '' ? $version : (string) $themes[$slug];
                }
            }

            if ($sources !== []) {
                $found[] = [
                    'id'      => $id,
                    'name'    => $definition['name'],
                    'version' => $version,
                    'sources' => $sources,
                ];
            }
        }

        return $found;
    }
}
