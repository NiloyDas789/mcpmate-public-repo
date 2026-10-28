<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Publishing a draft and rolling it back. Both change the live site, so
 * both run only from an approved request (ApprovedThemeChange); the REST
 * routes call the plan*() methods for the dry run and the approval preview.
 *
 * Publish:
 * 1. The draft is an unpublished, valid theme that meets WordPress and PHP
 *    requirements, and PHP can write zips.
 * 2. The live theme's folder (the child theme, if one is active) is zipped
 *    to uploads (ThemeBackups).
 * 3. The live theme's Customizer settings (`theme_mods_<stylesheet>`) are
 *    copied to the draft, so menus, logo and colours survive the switch.
 * 4. switch_theme() to the draft.
 *
 * Rollback, from a backup that has not been restored yet:
 * 1. The live theme is still the draft that backup was taken for; anything
 *    else means someone has switched themes since, and nothing is changed.
 * 2. The zip still has its recorded SHA-256.
 * 3. The backed-up theme's folder is restored: unpacked if it is missing,
 *    left alone if it matches the zip, otherwise renamed aside to
 *    `<stylesheet>-replaced-<UTC time>` (never deleted) and unpacked.
 * 4. Customizer settings are copied back, then switch_theme() to it. The
 *    draft becomes a draft again and can be changed and published anew.
 */
final class ThemePublisher
{
    // ──────────────────────────────────────────────
    //  Publish
    // ──────────────────────────────────────────────

    /**
     * What publishing $record would do. Changes nothing.
     *
     * @param array<string, mixed>|null $record
     * @return array<string, mixed>
     * @throws ThemeDraftException
     */
    public static function planPublish(?array $record): array
    {
        $directory = Drafts::workingDirectory($record);
        /** @var array<string, mixed> $record */
        $draft = Drafts::validTheme($record);
        self::checkRequirements($draft->get_stylesheet());
        $live = self::liveTheme();

        if (!ThemeFolder::canZip()) {
            throw new ThemeDraftException(
                esc_html__('PHP\'s zip extension is not available, so the live theme cannot be backed up and the draft cannot be published.', 'mcpmate'),
                esc_html(ThemeDraftException::BACKUP_FAILED),
                500,
                esc_html__('Ask the host to enable the PHP zip extension (ZipArchive).', 'mcpmate')
            );
        }

        ThemeBackups::directory();

        return [
            'operation'      => 'publish',
            'draft'          => Drafts::describe($record),
            'live_theme'     => self::summary($live),
            'source_changed' => $live->get_stylesheet() !== $record['source'],
            'changes'        => ThemeFolder::diff($live->get_stylesheet_directory(), $directory),
            'steps'          => [
                sprintf('Back up the live theme "%s" to a zip in uploads/mcpmate/theme-backups/.', $live->get_stylesheet()),
                sprintf('Copy its Customizer settings to "%s".', $record['slug']),
                sprintf('Switch the live site to "%s".', $record['slug']),
            ],
        ];
    }

    /**
     * Back up the live theme and switch to the draft. Runs once, from an
     * approval; every check of planPublish() runs again first.
     *
     * @param array<string, mixed>|null $record
     * @return array<string, mixed>
     * @throws ThemeDraftException
     */
    public static function publish(?array $record, int $approvedBy, int $requestedBy): array
    {
        $plan = self::planPublish($record);
        /** @var array<string, mixed> $record */
        $live   = self::liveTheme();
        $backup = ThemeBackups::create($live, [
            'draft_id'     => (string) $record['id'],
            'published'    => (string) $record['slug'],
            'requested_by' => $requestedBy,
            'approved_by'  => $approvedBy,
        ]);

        $modsCopied = self::copyThemeMods($live->get_stylesheet(), (string) $record['slug']);

        self::switchTo((string) $record['slug']);

        Drafts::update((string) $record['id'], [
            'status'       => Drafts::STATUS_PUBLISHED,
            'published_at' => gmdate('Y-m-d H:i:s'),
            'backup_id'    => $backup['id'],
        ]);

        return [
            'operation'         => 'publish',
            'published'         => (string) $record['slug'],
            'previous'          => self::summary($live),
            'backup'            => ThemeBackups::describe($backup),
            'theme_mods_copied' => $modsCopied,
            'changes'           => $plan['changes']['counts'],
        ];
    }

    // ──────────────────────────────────────────────
    //  Rollback
    // ──────────────────────────────────────────────

    /**
     * What restoring $backup would do. Changes nothing.
     *
     * @param array<string, mixed>|null $backup Null: the newest unrestored backup.
     * @return array<string, mixed>
     * @throws ThemeDraftException
     */
    public static function planRollback(?array $backup): array
    {
        $backup = self::restorable($backup);
        $zip    = ThemeBackups::verifiedPath($backup);
        $live   = self::liveTheme();

        if ($live->get_stylesheet() !== $backup['published']) {
            throw new ThemeDraftException(
                sprintf(
                    /* translators: 1: live theme folder name, 2: draft theme folder name. */
                    esc_html__('The live theme is "%1$s", not "%2$s", which this backup was taken for. Nothing was changed.', 'mcpmate'),
                    esc_html($live->get_stylesheet()),
                    esc_html((string) $backup['published'])
                ),
                esc_html(ThemeDraftException::ROLLBACK_STALE),
                409,
                esc_html__('Someone switched themes after the publish. Ask a site admin to choose the theme in Appearance → Themes.', 'mcpmate')
            );
        }

        $target = self::restoreTarget($backup);
        $folder = 'unchanged';
        $aside  = null;

        if (!file_exists($target)) {
            $folder = 'missing';
        } elseif (!is_dir($target) || is_link($target) || !ThemeFolder::matchesZip($target, $zip)) {
            $folder = 'changed';
            $aside  = self::asideName($backup);
        }

        return [
            'operation'      => 'rollback',
            'backup'         => ThemeBackups::describe($backup),
            'live_theme'     => self::summary($live),
            'restore'        => (string) $backup['stylesheet'],
            'folder'         => $folder,
            'moved_aside_to' => $aside,
            'steps'          => array_values(array_filter([
                $folder === 'missing' ? sprintf('Unpack the backup to themes/%s.', $backup['stylesheet']) : '',
                $folder === 'changed' ? sprintf('Rename the current themes/%1$s to themes/%2$s (kept, not deleted) and unpack the backup in its place.', $backup['stylesheet'], (string) $aside) : '',
                sprintf('Copy the Customizer settings back from "%s".', $live->get_stylesheet()),
                sprintf('Switch the live site to "%s".', $backup['stylesheet']),
            ])),
        ];
    }

    /**
     * Restore $backup and switch back to it. Runs once, from an approval.
     *
     * @param array<string, mixed>|null $backup
     * @return array<string, mixed>
     * @throws ThemeDraftException
     */
    public static function rollback(?array $backup, int $approvedBy): array
    {
        $plan   = self::planRollback($backup);
        $backup = self::restorable($backup);
        $zip    = ThemeBackups::verifiedPath($backup);
        $target = self::restoreTarget($backup);
        $live   = self::liveTheme();

        $aside = $plan['folder'] === 'changed' ? dirname($target) . '/' . $plan['moved_aside_to'] : '';

        // phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- Keeps the current folder (rule 3: nothing is deleted) while the backup takes its place.
        if ($aside !== '' && !rename($target, $aside)) {
            throw new ThemeDraftException(esc_html__('The current theme folder could not be moved aside. Nothing was changed.', 'mcpmate'), esc_html(ThemeDraftException::FILESYSTEM), 500);
        }

        if ($plan['folder'] !== 'unchanged') {
            try {
                ThemeFolder::extract($zip, $target);
            } catch (ThemeDraftException $e) {
                if ($aside !== '') {
                    rename($aside, $target);
                }

                throw $e;
            }
        }
        // phpcs:enable

        wp_clean_themes_cache();
        $theme = wp_get_theme((string) $backup['stylesheet']);

        if (!$theme->exists() || $theme->errors() !== false) {
            throw new ThemeDraftException(
                esc_html__('The restored theme is not valid, so the live theme was not switched.', 'mcpmate'),
                esc_html(ThemeDraftException::THEME_INVALID),
                500,
                esc_html__('Ask a site admin to choose a theme in Appearance → Themes.', 'mcpmate')
            );
        }

        self::checkRequirements($theme->get_stylesheet());
        $modsCopied = self::copyThemeMods($live->get_stylesheet(), $theme->get_stylesheet());
        self::switchTo($theme->get_stylesheet());

        ThemeBackups::update((string) $backup['id'], ['restored_at' => gmdate('Y-m-d H:i:s'), 'restored_by' => $approvedBy]);

        $draftId = (string) ($backup['draft_id'] ?? '');
        if ($draftId !== '' && Drafts::find($draftId) !== null) {
            Drafts::update($draftId, ['status' => Drafts::STATUS_DRAFT]);
        }

        return [
            'operation'         => 'rollback',
            'restored'          => $theme->get_stylesheet(),
            'previous'          => self::summary($live),
            'folder'            => $plan['folder'],
            'moved_aside_to'    => $plan['moved_aside_to'],
            'theme_mods_copied' => $modsCopied,
            'backup'            => ThemeBackups::describe((array) ThemeBackups::find((string) $backup['id'])),
        ];
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed>|null $backup
     * @return array<string, mixed>
     * @throws ThemeDraftException
     */
    private static function restorable(?array $backup): array
    {
        $backup = $backup ?? ThemeBackups::latestUnrestored();

        if ($backup === null) {
            throw new ThemeDraftException(
                esc_html__('There is no theme backup to roll back to.', 'mcpmate'),
                esc_html(ThemeDraftException::BACKUP_NOT_FOUND),
                404,
                esc_html__('Backups are made when a draft is published. GET /theme-draft lists them.', 'mcpmate')
            );
        }

        if (($backup['restored_at'] ?? null) !== null) {
            throw new ThemeDraftException(
                esc_html__('This backup has already been restored.', 'mcpmate'),
                esc_html(ThemeDraftException::ROLLBACK_STALE),
                409,
                esc_html__('GET /theme-draft shows the backups and which theme is live.', 'mcpmate')
            );
        }

        return $backup;
    }

    /**
     * @param array<string, mixed> $backup
     */
    private static function restoreTarget(array $backup): string
    {
        $root = (string) ($backup['theme_root'] ?? '');

        if ($root === '' || !is_dir($root)) {
            $root = str_replace('\\', '/', get_theme_root((string) $backup['stylesheet']));
        }

        return $root . '/' . $backup['stylesheet'];
    }

    /**
     * @param array<string, mixed> $backup
     */
    private static function asideName(array $backup): string
    {
        return $backup['stylesheet'] . '-replaced-' . gmdate('Ymd-His');
    }

    /**
     * @throws ThemeDraftException 409 when the live theme is missing.
     */
    private static function liveTheme(): \WP_Theme
    {
        $live = wp_get_theme(get_option('stylesheet'));

        if (!$live->exists()) {
            throw new ThemeDraftException(
                esc_html__('The live theme\'s folder is missing, so it cannot be backed up.', 'mcpmate'),
                esc_html(ThemeDraftException::THEME_INVALID),
                409,
                esc_html__('Ask a site admin to check Appearance → Themes.', 'mcpmate')
            );
        }

        return $live;
    }

    /**
     * switch_theme() calls wp_die() on unmet requirements; check them first.
     *
     * @throws ThemeDraftException 409
     */
    private static function checkRequirements(string $stylesheet): void
    {
        $requirements = validate_theme_requirements($stylesheet);

        if (is_wp_error($requirements)) {
            throw new ThemeDraftException(
                esc_html(wp_strip_all_tags($requirements->get_error_message())),
                esc_html(ThemeDraftException::THEME_INVALID),
                409,
                esc_html__('The theme\'s "Requires at least" or "Requires PHP" header is not met by this site.', 'mcpmate')
            );
        }
    }

    /**
     * @throws ThemeDraftException 500 when the switch did not take effect.
     */
    private static function switchTo(string $stylesheet): void
    {
        switch_theme($stylesheet);

        if (get_option('stylesheet') !== $stylesheet) {
            throw new ThemeDraftException(
                esc_html__('WordPress did not switch the theme.', 'mcpmate'),
                esc_html(ThemeDraftException::THEME_INVALID),
                500,
                esc_html__('Another plugin may have blocked the switch. Ask a site admin to check Appearance → Themes.', 'mcpmate')
            );
        }
    }

    /**
     * Copy the Customizer settings of $from to $to (before the switch, so
     * switch_theme() keeps them).
     */
    private static function copyThemeMods(string $from, string $to): bool
    {
        $mods = get_option('theme_mods_' . $from);

        if (!is_array($mods)) {
            return false;
        }

        update_option('theme_mods_' . $to, $mods);

        return true;
    }

    /**
     * @return array{stylesheet: string, template: string, name: string, version: string}
     */
    private static function summary(\WP_Theme $theme): array
    {
        return [
            'stylesheet' => $theme->get_stylesheet(),
            'template'   => $theme->get_template(),
            'name'       => (string) $theme->get('Name'),
            'version'    => (string) $theme->get('Version'),
        ];
    }
}
