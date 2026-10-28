<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Theme drafts: copies of the active theme the AI edits instead of the live
 * one.
 *
 * create() copies the active theme's own folder (the child theme when one
 * is active; its parent is shared, not copied) to
 * `<theme root>/<active>-draft-<id>`, where `<id>` is 8 random hex
 * characters. The records live in the `MCPMATE_theme_draft_records`
 * option, keyed by ID.
 *
 * A draft is usable by the file tools and previews only while it is not the
 * live theme: once published (or activated by hand in wp-admin) it refuses
 * every change, so nothing written through the draft routes reaches the live
 * site without an approved publish. A rollback makes it a draft again.
 */
final class Drafts
{
    public const OPTION = 'theme_draft_records';

    /** Unpublished drafts per site. */
    public const MAX_DRAFTS = 5;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const ID_PATTERN = '^[a-f0-9]{8}$';

    // ──────────────────────────────────────────────
    //  Records
    // ──────────────────────────────────────────────

    /**
     * Every draft record, newest first.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $records = Config::getOption(self::OPTION, []);
        if (!is_array($records)) {
            return [];
        }

        $records = array_filter($records, static fn ($record) => is_array($record) && isset($record['id'], $record['slug']));
        uasort($records, static fn (array $a, array $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $records;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        if (!preg_match('/' . self::ID_PATTERN . '/D', $id)) {
            return null;
        }

        return self::all()[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public static function update(string $id, array $changes): void
    {
        $records = self::all();

        if (isset($records[$id])) {
            $records[$id] = array_merge($records[$id], $changes);
            Config::updateOption(self::OPTION, $records, false);
        }
    }

    // ──────────────────────────────────────────────
    //  Creating
    // ──────────────────────────────────────────────

    /**
     * Copy the active theme into a new draft.
     *
     * @return array<string, mixed> The new record plus `copied` (file count, bytes, links skipped).
     * @throws ThemeDraftException
     */
    public static function create(int $userId): array
    {
        $theme = wp_get_theme(get_option('stylesheet'));

        if (!$theme->exists() || $theme->errors() !== false) {
            throw new ThemeDraftException(
                esc_html__('The active theme has errors and cannot be copied.', 'mcpmate'),
                esc_html(ThemeDraftException::THEME_INVALID),
                409,
                esc_html__('Fix the active theme in Appearance → Themes first.', 'mcpmate')
            );
        }

        $open = array_filter(
            self::all(),
            static fn (array $record) => $record['status'] === self::STATUS_DRAFT && is_dir(self::directory($record))
        );

        if (count($open) >= self::MAX_DRAFTS) {
            throw new ThemeDraftException(
                /* translators: %d: maximum number of drafts. */
                sprintf(esc_html__('This site already has %d theme drafts.', 'mcpmate'), (int) self::MAX_DRAFTS),
                esc_html(ThemeDraftException::DRAFT_LIMIT),
                409,
                esc_html__('Keep working on an existing draft (GET /theme-draft lists them), or ask a site admin to delete old drafts in Appearance → Themes.', 'mcpmate')
            );
        }

        $root = str_replace('\\', '/', (string) $theme->get_theme_root());

        if (!wp_is_writable($root)) {
            throw new ThemeDraftException(
                esc_html__('The themes directory is not writable, so no draft can be created.', 'mcpmate'),
                esc_html(ThemeDraftException::FILESYSTEM),
                500
            );
        }

        $id   = bin2hex(random_bytes(4));
        $base = (string) preg_replace('/-draft-[a-f0-9]{8}$/', '', $theme->get_stylesheet());
        $slug = $base . '-draft-' . $id;

        $copied = ThemeFolder::copy($theme->get_stylesheet_directory(), $root . '/' . $slug);

        wp_clean_themes_cache();

        $record = [
            'id'           => $id,
            'slug'         => $slug,
            'theme_root'   => $root,
            'source'       => $theme->get_stylesheet(),
            'source_name'  => $theme->get('Name'),
            'parent'       => $theme->parent() !== false ? $theme->get_template() : null,
            'status'       => self::STATUS_DRAFT,
            'created_by'   => $userId,
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'published_at' => null,
            'backup_id'    => null,
        ];

        $records      = self::all();
        $records[$id] = $record;
        Config::updateOption(self::OPTION, $records, false);

        return $record + ['copied' => $copied];
    }

    // ──────────────────────────────────────────────
    //  Checks
    // ──────────────────────────────────────────────

    /**
     * Absolute path of the draft's folder. Never sent to clients.
     *
     * @param array<string, mixed> $record
     */
    public static function directory(array $record): string
    {
        $root = (string) ($record['theme_root'] ?? '');

        if ($root === '' || !is_dir($root)) {
            $root = str_replace('\\', '/', get_theme_root((string) $record['slug']));
        }

        return $root . '/' . $record['slug'];
    }

    /**
     * True when the draft is the live theme or its parent, whatever its record says.
     *
     * @param array<string, mixed> $record
     */
    public static function isLive(array $record): bool
    {
        // The options, not get_stylesheet(): a preview request filters that.
        return in_array($record['slug'], [get_option('stylesheet'), get_option('template')], true);
    }

    /**
     * The draft's folder, if the file tools and previews may use it.
     *
     * @param array<string, mixed>|null $record
     * @throws ThemeDraftException 404 unknown or gone, 409 live.
     */
    public static function workingDirectory(?array $record): string
    {
        if ($record === null) {
            throw self::notFound();
        }

        if ($record['status'] !== self::STATUS_DRAFT || self::isLive($record)) {
            throw new ThemeDraftException(
                esc_html__('This draft is the live theme now, so it can no longer be changed through the draft tools.', 'mcpmate'),
                esc_html(ThemeDraftException::DRAFT_PUBLISHED),
                409,
                esc_html__('Create a new draft with POST /theme-draft and make further changes there.', 'mcpmate')
            );
        }

        $directory = self::directory($record);

        if (!is_dir($directory)) {
            throw self::notFound();
        }

        return $directory;
    }

    /**
     * The draft as a valid, installed theme.
     *
     * @param array<string, mixed> $record
     * @throws ThemeDraftException 409 when WordPress reports errors for it.
     */
    public static function validTheme(array $record): \WP_Theme
    {
        wp_clean_themes_cache();
        $theme  = wp_get_theme((string) $record['slug']);
        $errors = $theme->errors();

        if (!$theme->exists() || $errors !== false) {
            throw new ThemeDraftException(
                sprintf(
                    /* translators: %s: WordPress's description of the theme error. */
                    esc_html__('The draft is not a valid theme: %s', 'mcpmate'),
                    $errors instanceof \WP_Error ? esc_html($errors->get_error_message()) : esc_html__('its folder is missing.', 'mcpmate')
                ),
                esc_html(ThemeDraftException::DRAFT_INVALID),
                409,
                esc_html__('Fix style.css (its Theme Name and Template headers) in the draft.', 'mcpmate')
            );
        }

        return $theme;
    }

    public static function notFound(): ThemeDraftException
    {
        return new ThemeDraftException(
            esc_html__('Theme draft not found.', 'mcpmate'),
            esc_html(ThemeDraftException::DRAFT_NOT_FOUND),
            404,
            esc_html__('GET /theme-draft lists the drafts; POST /theme-draft creates one.', 'mcpmate')
        );
    }

    // ──────────────────────────────────────────────
    //  Views
    // ──────────────────────────────────────────────

    /**
     * The record as clients see it (no absolute server paths).
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function describe(array $record): array
    {
        $directory = self::directory($record);
        $exists    = is_dir($directory);
        $live      = self::isLive($record);

        return [
            'id'           => (string) $record['id'],
            'slug'         => (string) $record['slug'],
            'status'       => $live ? self::STATUS_PUBLISHED : (string) $record['status'],
            'live'         => $live,
            'exists'       => $exists,
            'folder'       => 'themes/' . $record['slug'],
            'source'       => ['stylesheet' => (string) $record['source'], 'name' => (string) $record['source_name']],
            'parent'       => $record['parent'] !== null ? (string) $record['parent'] : null,
            'created_by'   => (int) $record['created_by'],
            'created_at'   => self::isoDate((string) $record['created_at']),
            'published_at' => self::isoDate((string) ($record['published_at'] ?? '')),
            'backup_id'    => $record['backup_id'] !== null ? (string) $record['backup_id'] : null,
        ];
    }

    /**
     * `Y-m-d H:i:s` (UTC) → `Y-m-dTH:i:sZ`; empty → null.
     */
    public static function isoDate(string $value): ?string
    {
        return $value === '' ? null : str_replace(' ', 'T', $value) . 'Z';
    }
}
