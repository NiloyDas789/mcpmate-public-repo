<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Zip backups of the live theme, taken just before a draft is published.
 *
 * Files go to `uploads/mcpmate/theme-backups/` as
 * `<stylesheet>-<UTC time>-<16 random hex>.zip`. The uploads folder is served
 * by the web server, so the folder also gets an index.php and an .htaccess
 * that denies access (Apache), and the random part keeps names unguessable
 * where .htaccess is ignored (nginx). Responses name the file, never its URL.
 *
 * Records (newest first, at most MAX_RECORDS) live in the
 * `MCPMATE_theme_backups` option with the zip's SHA-256, which is
 * checked again before a rollback uses it. Old zips are never deleted.
 */
final class ThemeBackups
{
    public const OPTION = 'theme_backups';

    public const MAX_RECORDS = 50;

    public const ID_PATTERN = '^[a-f0-9]{12}$';

    private const FOLDER = 'mcpmate/theme-backups';

    // ──────────────────────────────────────────────
    //  Records
    // ──────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>> Newest first.
     */
    public static function all(): array
    {
        $records = Config::getOption(self::OPTION, []);

        return is_array($records)
            ? array_values(array_filter($records, static fn ($record) => is_array($record) && isset($record['id'], $record['file'])))
            : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $record) {
            if ($record['id'] === $id) {
                return $record;
            }
        }

        return null;
    }

    /**
     * The newest backup that has not been restored yet.
     *
     * @return array<string, mixed>|null
     */
    public static function latestUnrestored(): ?array
    {
        foreach (self::all() as $record) {
            if (($record['restored_at'] ?? null) === null) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public static function update(string $id, array $changes): void
    {
        $records = self::all();

        foreach ($records as $i => $record) {
            if ($record['id'] === $id) {
                $records[$i] = array_merge($record, $changes);
            }
        }

        Config::updateOption(self::OPTION, $records, false);
    }

    // ──────────────────────────────────────────────
    //  Creating and reading
    // ──────────────────────────────────────────────

    /**
     * Zip the folder of $theme and record it.
     *
     * @param array<string, mixed> $context Stored with the record (draft ID, users).
     * @return array<string, mixed> The new record.
     * @throws ThemeDraftException 500 when the backup cannot be written.
     */
    public static function create(\WP_Theme $theme, array $context): array
    {
        $dir  = self::directory();
        $name = sprintf('%s-%s-%s.zip', $theme->get_stylesheet(), gmdate('Ymd-His'), bin2hex(random_bytes(8)));
        $path = $dir . '/' . $name;

        $zipped = ThemeFolder::zip($theme->get_stylesheet_directory(), $path);

        $record = [
            'id'          => bin2hex(random_bytes(6)),
            'file'        => self::FOLDER . '/' . $name,
            'sha256'      => (string) hash_file('sha256', $path),
            'stylesheet'  => $theme->get_stylesheet(),
            'template'    => $theme->get_template(),
            'name'        => $theme->get('Name'),
            'version'     => $theme->get('Version'),
            'theme_root'  => str_replace('\\', '/', (string) $theme->get_theme_root()),
            'files'       => $zipped['files'],
            'bytes'       => $zipped['bytes'],
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'restored_at' => null,
        ] + $context;

        $records = self::all();
        array_unshift($records, $record);
        Config::updateOption(self::OPTION, array_slice($records, 0, self::MAX_RECORDS), false);

        return $record;
    }

    /**
     * Absolute path of a backup's zip, once its checksum still matches.
     *
     * @param array<string, mixed> $record
     * @throws ThemeDraftException 409 when the file is missing or changed.
     */
    public static function verifiedPath(array $record): string
    {
        $path = Config::get('UPLOAD_BASE_DIR') . '/' . $record['file'];

        if (!is_file($path) || !hash_equals((string) $record['sha256'], (string) hash_file('sha256', $path))) {
            throw new ThemeDraftException(
                esc_html__('The backup file is missing or has been changed since it was made, so it was not used.', 'mcpmate'),
                esc_html(ThemeDraftException::BACKUP_FAILED),
                409,
                esc_html__('Restore the previous theme by hand in Appearance → Themes.', 'mcpmate')
            );
        }

        return $path;
    }

    /**
     * The backups folder in uploads, created with its access guards.
     *
     * @throws ThemeDraftException 500 when it cannot be created.
     */
    public static function directory(): string
    {
        $dir = str_replace('\\', '/', (string) Config::get('UPLOAD_BASE_DIR')) . '/' . self::FOLDER;

        if (!wp_mkdir_p($dir) || !wp_is_writable($dir)) {
            throw new ThemeDraftException(
                esc_html__('The backup folder in uploads could not be created.', 'mcpmate'),
                esc_html(ThemeDraftException::BACKUP_FAILED),
                500,
                esc_html__('Check that the uploads directory is writable.', 'mcpmate')
            );
        }

        $guards = [
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
        ];

        foreach ($guards as $file => $content) {
            if (!file_exists($dir . '/' . $file)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Access guard in the plugin's own uploads folder.
                file_put_contents($dir . '/' . $file, $content);
            }
        }

        return $dir;
    }

    /**
     * The record as clients see it: the file's name without its random part
     * (on nginx that part is what keeps the zip from being downloaded by
     * URL), never its path or URL.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function describe(array $record): array
    {
        return [
            'id'          => (string) $record['id'],
            'file'        => (string) preg_replace('/-[a-f0-9]{16}\.zip$/', '-….zip', basename((string) $record['file'])),
            'stylesheet'  => (string) $record['stylesheet'],
            'name'        => (string) $record['name'],
            'version'     => (string) $record['version'],
            'files'       => (int) $record['files'],
            'bytes'       => (int) $record['bytes'],
            'draft_id'    => isset($record['draft_id']) ? (string) $record['draft_id'] : null,
            'published'   => isset($record['published']) ? (string) $record['published'] : null,
            'created_at'  => Drafts::isoDate((string) $record['created_at']),
            'restored_at' => Drafts::isoDate((string) ($record['restored_at'] ?? '')),
        ];
    }
}
