<?php

namespace McpMate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Supplies locale data to the React frontend.
 *
 * PHP strings resolve through the gettext catalogs WordPress loads itself
 * for a wordpress.org-hosted plugin (language packs in
 * `WP_LANG_DIR/plugins`), so there is no load_plugin_textdomain() call. The
 * SPA cannot read those, so the JavaScript strings from the same catalog are
 * compiled into a per-locale PHP array under `/languages/frontend/` and
 * handed to the bundle through `wp_localize_script`, where
 * `@wordpress/i18n` picks them up.
 *
 * Both artifacts are generated from one source of truth — see `pnpm i18n`.
 */
final class I18n
{
    /**
     * Text domain. Must match the `Text Domain` plugin header and every
     * `__()` call site in PHP and TypeScript alike.
     */
    public const DOMAIN = 'mcpmate';

    public const LANGUAGES_DIR = 'languages';

    /**
     * Jed-formatted locale data for the SPA, or null when the source strings
     * already are the correct output (English) or no catalog was compiled.
     *
     * @return array<string, mixed>|null
     */
    public static function frontendLocaleData(): ?array
    {
        $locale = self::currentLocale();

        if ($locale === '' || str_starts_with($locale, 'en_US')) {
            return null;
        }

        $file = self::localeDataPath($locale);

        if ($file === null) {
            return null;
        }

        $data = include $file;

        return is_array($data) && $data !== [] ? $data : null;
    }

    /**
     * Resolve the compiled locale-data file, falling back from a regional
     * variant to its base language (`de_CH` -> `de`).
     */
    private static function localeDataPath(string $locale): ?string
    {
        $dir = Config::get('ROOT_DIR') . self::LANGUAGES_DIR . '/frontend/';

        foreach (self::localeCandidates($locale) as $candidate) {
            $path = $dir . $candidate . '.php';

            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function localeCandidates(string $locale): array
    {
        $candidates = [$locale];
        $base       = strtok($locale, '_');

        if (is_string($base) && $base !== $locale) {
            $candidates[] = $base;
        }

        return $candidates;
    }

    /**
     * The locale translations should be rendered in.
     *
     * Mirrors WordPress core: the admin honours a user's personal language
     * setting, which can differ from the site locale.
     */
    public static function currentLocale(): string
    {
        return function_exists('determine_locale') ? determine_locale() : get_locale();
    }
}
