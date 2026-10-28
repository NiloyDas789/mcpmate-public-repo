<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whole-folder operations on themes: copy a theme into a draft, zip a theme
 * as a backup, compare a folder with a backup, restore a backup, and list
 * what a draft changes.
 *
 * Every operation sees the same files (scan()): regular files only.
 * Symbolic links are never followed or copied, and version-control and
 * node_modules folders are left out. A theme over MAX_FILES files or
 * MAX_BYTES is refused before anything is written.
 *
 * Pure PHP plus ZipArchive (no WordPress), so it is unit-tested against
 * real temp folders.
 */
final class ThemeFolder
{
    public const SKIP_DIRS = ['.git', '.svn', '.hg', 'node_modules'];

    public const SKIP_FILES = ['.DS_Store', 'Thumbs.db'];

    public const MAX_FILES = 5000;

    /** 100 MiB. */
    public const MAX_BYTES = 104857600;

    /** Paths listed per category in diff(); the counts are always complete. */
    public const DIFF_LIMIT = 200;

    /**
     * The files of $dir, relative path => absolute path, sorted.
     *
     * @return array{files: array<string, string>, bytes: int, skipped: int}
     * @throws ThemeDraftException 413 when the folder is over the limits.
     */
    public static function scan(string $dir): array
    {
        $dir     = rtrim(str_replace('\\', '/', $dir), '/');
        $files   = [];
        $bytes   = 0;
        $skipped = 0;

        $filter = static function (\SplFileInfo $file) use (&$skipped): bool {
            if ($file->isLink()) {
                $skipped++;

                return false;
            }

            if ($file->isDir()) {
                return !in_array($file->getFilename(), self::SKIP_DIRS, true);
            }

            return !in_array($file->getFilename(), self::SKIP_FILES, true);
        };

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), $filter)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $path                                    = str_replace('\\', '/', $file->getPathname());
            $files[substr($path, strlen($dir) + 1)] = $path;
            $bytes                                  += (int) $file->getSize();

            if (count($files) > self::MAX_FILES || $bytes > self::MAX_BYTES) {
                throw new ThemeDraftException(
                    sprintf(
                        /* translators: 1: maximum number of files, 2: maximum size in MB. */
                        esc_html__('The theme is too large to copy (more than %1$d files or %2$d MB).', 'mcpmate'),
                        (int) self::MAX_FILES,
                        (int) (self::MAX_BYTES / 1048576)
                    ),
                    esc_html(ThemeDraftException::THEME_TOO_LARGE),
                    413
                );
            }
        }

        ksort($files, SORT_STRING);

        return ['files' => $files, 'bytes' => $bytes, 'skipped' => $skipped];
    }

    /**
     * Copy the theme in $source to the new folder $dest.
     *
     * @return array{files: int, bytes: int, skipped: int}
     * @throws ThemeDraftException When $dest exists or a copy fails (the partial copy is removed).
     */
    public static function copy(string $source, string $dest): array
    {
        $scan = self::scan($source);

        self::createFolder($dest);

        try {
            foreach ($scan['files'] as $relative => $path) {
                $target = $dest . '/' . $relative;
                $parent = dirname($target);

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Inside the new draft folder; WP_Filesystem needs credentials REST requests do not have.
                if ((!is_dir($parent) && !mkdir($parent, 0755, true)) || !copy($path, $target)) {
                    throw self::filesystemError(esc_html__('A theme file could not be copied into the draft.', 'mcpmate'));
                }
            }
        } catch (ThemeDraftException $e) {
            self::removeTree($dest);

            throw $e;
        }

        return ['files' => count($scan['files']), 'bytes' => $scan['bytes'], 'skipped' => $scan['skipped']];
    }

    // ──────────────────────────────────────────────
    //  Backups (zip)
    // ──────────────────────────────────────────────

    public static function canZip(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Zip the files of $dir into the new file $zipPath, relative to $dir.
     *
     * @return array{files: int, bytes: int}
     * @throws ThemeDraftException 500 when the zip cannot be written.
     */
    public static function zip(string $dir, string $zipPath): array
    {
        $scan = self::scan($dir);
        $zip  = self::openZip($zipPath, \ZipArchive::CREATE | \ZipArchive::EXCL);

        foreach ($scan['files'] as $relative => $path) {
            if (!$zip->addFile($path, $relative)) {
                $zip->close();
                self::deleteFile($zipPath);

                throw self::backupError();
            }
        }

        // An empty archive is not written at all; a theme always has style.css.
        if (!$zip->close() || !is_file($zipPath)) {
            self::deleteFile($zipPath);

            throw self::backupError();
        }

        return ['files' => count($scan['files']), 'bytes' => $scan['bytes']];
    }

    /**
     * True when $dir holds exactly the files in the zip, byte for byte (CRC-32 and size).
     *
     * @throws ThemeDraftException When the zip cannot be read or has unsafe entries.
     */
    public static function matchesZip(string $dir, string $zipPath): bool
    {
        $entries = self::zipEntries($zipPath);
        $files   = self::scan($dir)['files'];

        if (array_keys($entries) !== array_keys($files)) {
            return false;
        }

        foreach ($files as $relative => $path) {
            if (
                filesize($path) !== $entries[$relative]['size']
                || hash_file('crc32b', $path) !== sprintf('%08x', $entries[$relative]['crc'] & 0xFFFFFFFF)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Unpack the zip into the new folder $dest. Every entry name is checked
     * first (no absolute paths, `..`, backslashes or NUL bytes).
     *
     * @return int The number of files restored.
     * @throws ThemeDraftException When $dest exists, an entry is unsafe or extraction fails.
     */
    public static function extract(string $zipPath, string $dest): int
    {
        $entries = self::zipEntries($zipPath);

        self::createFolder($dest);

        $zip = self::openZip($zipPath, 0);
        $ok  = $zip->extractTo($dest);
        $zip->close();

        if (!$ok) {
            self::removeTree($dest);

            throw self::filesystemError(esc_html__('The backup could not be unpacked.', 'mcpmate'));
        }

        return count($entries);
    }

    /**
     * Entry name => size and CRC-32, for a zip whose names are all safe.
     *
     * @return array<string, array{size: int, crc: int}>
     * @throws ThemeDraftException
     */
    public static function zipEntries(string $zipPath): array
    {
        $zip     = self::openZip($zipPath, 0);
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string) $stat['name'] : '';

            if (substr($name, -1) === '/') {
                continue;
            }

            if (
                $name === ''
                || $name[0] === '/'
                || strpos($name, '\\') !== false
                || strpos($name, "\0") !== false
                || preg_match('#^[A-Za-z]:#', $name)
                || in_array('..', explode('/', $name), true)
            ) {
                $zip->close();

                throw new ThemeDraftException(
                    esc_html__('The backup contains an unsafe file name and was not used.', 'mcpmate'),
                    esc_html(ThemeDraftException::BACKUP_FAILED),
                    500
                );
            }

            $entries[$name] = ['size' => (int) $stat['size'], 'crc' => (int) $stat['crc']];
        }

        $zip->close();
        ksort($entries, SORT_STRING);

        return $entries;
    }

    // ──────────────────────────────────────────────
    //  Diff
    // ──────────────────────────────────────────────

    /**
     * What $to changes compared with $from: files added, modified and removed.
     *
     * @return array{added: string[], modified: string[], removed: string[], counts: array{added: int, modified: int, removed: int, unchanged: int}}
     */
    public static function diff(string $from, string $to): array
    {
        $before = self::scan($from)['files'];
        $after  = self::scan($to)['files'];

        $added    = array_keys(array_diff_key($after, $before));
        $removed  = array_keys(array_diff_key($before, $after));
        $modified = [];

        foreach (array_intersect_key($after, $before) as $relative => $path) {
            if (filesize($path) !== filesize($before[$relative]) || hash_file('sha1', $path) !== hash_file('sha1', $before[$relative])) {
                $modified[] = (string) $relative;
            }
        }

        $unchanged = count($after) - count($added) - count($modified);

        return [
            'added'    => array_slice(array_map('strval', $added), 0, self::DIFF_LIMIT),
            'modified' => array_slice($modified, 0, self::DIFF_LIMIT),
            'removed'  => array_slice(array_map('strval', $removed), 0, self::DIFF_LIMIT),
            'counts'   => [
                'added'     => count($added),
                'modified'  => count($modified),
                'removed'   => count($removed),
                'unchanged' => $unchanged,
            ],
        ];
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * Remove a folder this request created and then failed to fill. Links
     * are removed, never followed. Not for existing themes (rule 3).
     */
    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir() && !$file->isLink()) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes a partial copy this request created.
                rmdir($file->getPathname());
            } else {
                self::deleteFile($file->getPathname());
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes a partial copy this request created.
        rmdir($dir);
    }

    private static function deleteFile(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Only files this class just wrote (partial copies, failed zips).
            unlink($path);
        }
    }

    /**
     * @throws ThemeDraftException When $dir exists or cannot be created.
     */
    private static function createFolder(string $dir): void
    {
        if (file_exists($dir) || is_link($dir)) {
            throw self::filesystemError(esc_html__('The target theme folder already exists.', 'mcpmate'));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- A new theme folder in the themes directory; WP_Filesystem needs credentials REST requests do not have.
        if (!mkdir($dir, 0755)) {
            throw self::filesystemError(esc_html__('The theme folder could not be created. Check that the themes directory is writable.', 'mcpmate'));
        }
    }

    /**
     * @throws ThemeDraftException
     */
    private static function openZip(string $path, int $flags): \ZipArchive
    {
        if (!self::canZip()) {
            throw new ThemeDraftException(
                esc_html__('PHP\'s zip extension is not available, so the theme cannot be backed up.', 'mcpmate'),
                esc_html(ThemeDraftException::BACKUP_FAILED),
                500,
                esc_html__('Ask the host to enable the PHP zip extension (ZipArchive).', 'mcpmate')
            );
        }

        $zip = new \ZipArchive();

        if ($zip->open($path, $flags) !== true) {
            throw self::backupError();
        }

        return $zip;
    }

    private static function backupError(): ThemeDraftException
    {
        return new ThemeDraftException(
            esc_html__('The theme backup could not be written or read.', 'mcpmate'),
            esc_html(ThemeDraftException::BACKUP_FAILED),
            500,
            esc_html__('Check that the uploads directory is writable.', 'mcpmate')
        );
    }

    private static function filesystemError(string $message): ThemeDraftException
    {
        return new ThemeDraftException(esc_html($message), esc_html(ThemeDraftException::FILESYSTEM), 500);
    }
}
