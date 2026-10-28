<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The file tools for one draft folder: list, read, write, find-and-replace
 * and delete.
 *
 * Every path goes through DraftPath (lexical check, extension allowlist,
 * realpath containment, no symbolic links) before the filesystem is
 * touched, and every write goes through CodeLint first: a file that fails
 * is never saved. Writes go to a temporary file in the same folder and are
 * renamed into place, so a preview never loads half a file, and PHP's
 * opcode cache is told about the change.
 *
 * Responses name files by their path inside the draft only.
 */
final class DraftFiles
{
    /** Largest file write() and replace() save (512 KiB). */
    public const MAX_WRITE_BYTES = 524288;

    /** Largest file read() opens (2 MiB); it is returned in pages. */
    public const MAX_READ_FILE_BYTES = 2097152;

    /** Bytes of content per read() page, well inside RestEnvelope::MAX_BYTES after JSON escaping. */
    public const READ_PAGE_BYTES = 65536;

    public const MAX_ENTRIES = 2000;

    private string $root;

    /**
     * @throws ThemeDraftException 404 when the folder is gone.
     */
    public function __construct(string $directory)
    {
        $this->root = DraftPath::root($directory);
    }

    // ──────────────────────────────────────────────
    //  Reading
    // ──────────────────────────────────────────────

    /**
     * Files (and, unless recursive, folders) under $path, sorted.
     *
     * @return array{path: string, recursive: bool, entries: array<int, array<string, mixed>>, count: int, truncated: bool}
     * @throws ThemeDraftException
     */
    public function list(string $path, bool $recursive): array
    {
        $relative = DraftPath::normalize($path);
        $dir      = DraftPath::resolve($this->root, $relative);

        if (!is_dir($dir)) {
            throw new ThemeDraftException(
                /* translators: %s: file path inside the draft theme. */
                sprintf(esc_html__('"%s" is a file; give a folder to list.', 'mcpmate'), esc_html($relative)),
                esc_html(ThemeDraftException::INVALID_PATH),
                400
            );
        }

        $filter = static function (\SplFileInfo $file): bool {
            $name = $file->getFilename();

            return !$file->isLink()
                && $name[0] !== '.'
                && !in_array($name, ThemeFolder::SKIP_DIRS, true);
        };

        $inner    = new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), $filter);
        $iterator = $recursive ? new \RecursiveIteratorIterator($inner) : $inner;

        $entries   = [];
        $truncated = false;

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (count($entries) >= self::MAX_ENTRIES) {
                $truncated = true;
                break;
            }

            $entryPath = DraftPath::relative($this->root, $file->getPathname());
            $isDir     = $file->isDir();

            $entries[] = [
                'path'     => $entryPath,
                'type'     => $isDir ? 'dir' : 'file',
                'size'     => $isDir ? null : (int) $file->getSize(),
                'modified' => gmdate('Y-m-d\TH:i:s\Z', (int) $file->getMTime()),
                'editable' => !$isDir && self::isEditable($entryPath),
            ];
        }

        usort($entries, static fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return [
            'path'      => $relative,
            'recursive' => $recursive,
            'entries'   => $entries,
            'count'     => count($entries),
            'truncated' => $truncated,
        ];
    }

    /**
     * One page of a file, whole lines from $line on, up to READ_PAGE_BYTES.
     * A single line longer than that (minified code) is cut and `clipped`.
     *
     * @return array<string, mixed>
     * @throws ThemeDraftException
     */
    public function read(string $path, int $line = 1): array
    {
        [$relative] = self::filePath($path);
        $file       = $this->existingFile($relative);
        $size       = (int) filesize($file);

        if ($size > self::MAX_READ_FILE_BYTES) {
            throw self::tooLarge(esc_html($relative), (int) self::MAX_READ_FILE_BYTES);
        }

        $content = self::get($file);
        $lines   = self::lines($content);
        $total   = count($lines);
        $start   = max(1, $line);

        if ($start > max(1, $total)) {
            throw new ThemeDraftException(
                /* translators: 1: requested line, 2: number of lines in the file. */
                sprintf(esc_html__('Line %1$d is past the end of the file (%2$d lines).', 'mcpmate'), (int) $start, (int) $total),
                esc_html(ThemeDraftException::INVALID_PATH),
                400
            );
        }

        $page    = '';
        $end     = $start - 1;
        $clipped = false;

        for ($i = $start - 1; $i < $total; $i++) {
            if ($page !== '' && strlen($page) + strlen($lines[$i]) > self::READ_PAGE_BYTES) {
                break;
            }

            if (strlen($lines[$i]) > self::READ_PAGE_BYTES) {
                $page   .= self::clip($lines[$i], self::READ_PAGE_BYTES);
                $clipped = true;
                $end     = $i + 1;
                break;
            }

            $page .= $lines[$i];
            $end   = $i + 1;
        }

        return [
            'path'        => $relative,
            'size'        => $size,
            'sha256'      => hash('sha256', $content),
            'total_lines' => $total,
            'start_line'  => $start,
            'end_line'    => $end,
            'content'     => $page,
            'clipped'     => $clipped,
            'has_more'    => $end < $total,
            'next_line'   => $end < $total ? $end + 1 : null,
        ];
    }

    // ──────────────────────────────────────────────
    //  Writing
    // ──────────────────────────────────────────────

    /**
     * Create or replace a file with $content, after CodeLint.
     *
     * @return array{path: string, size: int, sha256: string, created: bool}
     * @throws ThemeDraftException
     */
    public function write(string $path, string $content): array
    {
        [$relative, $ext] = self::filePath($path);

        if (strlen($content) > self::MAX_WRITE_BYTES) {
            throw self::tooLarge(esc_html($relative), (int) self::MAX_WRITE_BYTES);
        }

        CodeLint::check($relative, $ext, $content);

        $target  = DraftPath::resolveForWrite($this->root, $relative);
        $created = !file_exists($target);

        self::put($target, $content);

        return ['path' => $relative, 'size' => strlen($content), 'sha256' => hash('sha256', $content), 'created' => $created];
    }

    /**
     * Replace the one exact occurrence of $find with $replace. Zero or
     * several occurrences change nothing. The result goes through CodeLint.
     *
     * @return array{path: string, size: int, sha256: string, line: int}
     * @throws ThemeDraftException
     */
    public function replace(string $path, string $find, string $replace): array
    {
        [$relative, $ext] = self::filePath($path);
        $file             = $this->existingFile($relative);

        if ($find === '') {
            throw new ThemeDraftException(
                esc_html__('find must not be empty.', 'mcpmate'),
                esc_html(ThemeDraftException::FIND_NOT_FOUND),
                400
            );
        }

        if ((int) filesize($file) > self::MAX_WRITE_BYTES) {
            throw self::tooLarge(esc_html($relative), (int) self::MAX_WRITE_BYTES);
        }

        $content  = self::get($file);
        $position = strpos($content, $find);

        if ($position === false) {
            throw new ThemeDraftException(
                /* translators: %s: file path inside the draft theme. */
                sprintf(esc_html__('The text to find does not occur in "%s". Nothing was changed.', 'mcpmate'), esc_html($relative)),
                esc_html(ThemeDraftException::FIND_NOT_FOUND),
                400,
                esc_html__('find must match exactly, including whitespace, indentation and line endings. Read the file again and copy the text from it.', 'mcpmate')
            );
        }

        // From position + 1, so overlapping occurrences ("aa" in "aaa") also count.
        if (strpos($content, $find, $position + 1) !== false) {
            throw new ThemeDraftException(
                sprintf(
                    /* translators: 1: file path inside the draft theme, 2: number of occurrences. */
                    esc_html__('The text to find occurs %2$d times in "%1$s"; it must occur exactly once. Nothing was changed.', 'mcpmate'),
                    esc_html($relative),
                    (int) max(2, substr_count($content, $find))
                ),
                esc_html(ThemeDraftException::FIND_AMBIGUOUS),
                400,
                esc_html__('Include more of the surrounding lines in find so that it matches one place only.', 'mcpmate')
            );
        }

        $updated = substr_replace($content, $replace, $position, strlen($find));

        if (strlen($updated) > self::MAX_WRITE_BYTES) {
            throw self::tooLarge(esc_html($relative), (int) self::MAX_WRITE_BYTES);
        }

        CodeLint::check($relative, $ext, $updated);
        self::put($file, $updated);

        return [
            'path'   => $relative,
            'size'   => strlen($updated),
            'sha256' => hash('sha256', $updated),
            'line'   => substr_count($content, "\n", 0, $position) + 1,
        ];
    }

    /**
     * Delete one file of the draft (only the draft: the live theme is a
     * different folder). style.css cannot be deleted; the draft would stop
     * being a theme.
     *
     * @return array{path: string, deleted: bool}
     * @throws ThemeDraftException
     */
    public function delete(string $path): array
    {
        [$relative] = self::filePath($path);

        if ($relative === 'style.css') {
            throw new ThemeDraftException(
                esc_html__('style.css cannot be deleted: it is what makes the folder a theme.', 'mcpmate'),
                esc_html(ThemeDraftException::PROTECTED_FILE),
                400,
                esc_html__('Edit it instead.', 'mcpmate')
            );
        }

        $file = $this->existingFile($relative);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A realpath-checked file in the draft copy, never the live theme.
        if (!unlink($file)) {
            throw new ThemeDraftException(esc_html__('The file could not be deleted.', 'mcpmate'), esc_html(ThemeDraftException::FILESYSTEM), 500);
        }

        self::invalidateOpcache($file);

        return ['path' => $relative, 'deleted' => true];
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * True when the file tools accept $relative (a path from list()).
     */
    public static function isEditable(string $relative): bool
    {
        try {
            self::filePath($relative);

            return true;
        } catch (ThemeDraftException $e) {
            return false;
        }
    }

    /**
     * The normalized path and extension of a file path.
     *
     * @return array{0: string, 1: string}
     * @throws ThemeDraftException
     */
    private static function filePath(string $path): array
    {
        $relative = DraftPath::normalize($path);

        if ($relative === '') {
            throw new ThemeDraftException(
                esc_html__('Give the path of a file in the draft.', 'mcpmate'),
                esc_html(ThemeDraftException::INVALID_PATH),
                400
            );
        }

        return [$relative, DraftPath::extension($relative)];
    }

    /**
     * @throws ThemeDraftException
     */
    private function existingFile(string $relative): string
    {
        $file = DraftPath::resolve($this->root, $relative);

        if (!is_file($file)) {
            throw new ThemeDraftException(
                /* translators: %s: file path inside the draft theme. */
                sprintf(esc_html__('"%s" is a folder, not a file.', 'mcpmate'), esc_html($relative)),
                esc_html(ThemeDraftException::NOT_A_FILE),
                400
            );
        }

        return $file;
    }

    /**
     * $content split after each "\n"; a final newline does not start an extra line.
     *
     * @return string[]
     */
    private static function lines(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $lines = preg_split('/(?<=\n)/', $content);
        if (!is_array($lines)) {
            return [$content];
        }

        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * The first $bytes of $text, without a cut-off UTF-8 sequence at the end.
     */
    private static function clip(string $text, int $bytes): string
    {
        $clipped = (string) substr($text, 0, $bytes);

        for ($i = 0; $i < 3 && $clipped !== '' && !preg_match('//u', $clipped); $i++) {
            $clipped = (string) substr($clipped, 0, -1);
        }

        return $clipped;
    }

    /**
     * @throws ThemeDraftException
     */
    private static function get(string $file): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A realpath-checked local file in the draft.
        $content = file_get_contents($file);

        if ($content === false) {
            throw new ThemeDraftException(esc_html__('The file could not be read.', 'mcpmate'), esc_html(ThemeDraftException::FILESYSTEM), 500);
        }

        return $content;
    }

    /**
     * Write through a temporary file in the same folder, then rename it over $target.
     *
     * @throws ThemeDraftException
     */
    private static function put(string $target, string $content): void
    {
        $temp = dirname($target) . '/.mcpmate-' . bin2hex(random_bytes(6)) . '.tmp';

        // phpcs:disable WordPress.WP.AlternativeFunctions -- Draft files in a realpath-checked folder; WP_Filesystem needs credentials REST requests do not have.
        $written = file_put_contents($temp, $content, LOCK_EX);

        if ($written !== strlen($content)) {
            if (is_file($temp)) {
                unlink($temp);
            }

            throw new ThemeDraftException(esc_html__('The file could not be written.', 'mcpmate'), esc_html(ThemeDraftException::FILESYSTEM), 500);
        }

        chmod($temp, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);

        if (!rename($temp, $target)) {
            unlink($temp);

            throw new ThemeDraftException(esc_html__('The file could not be written.', 'mcpmate'), esc_html(ThemeDraftException::FILESYSTEM), 500);
        }
        // phpcs:enable

        self::invalidateOpcache($target);
    }

    /**
     * A preview must run the new code, even where the opcode cache does not check timestamps.
     */
    private static function invalidateOpcache(string $file): void
    {
        if (substr($file, -4) === '.php' && function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    private static function tooLarge(string $relative, int $limit): ThemeDraftException
    {
        return new ThemeDraftException(
            sprintf(
                /* translators: 1: file path inside the draft theme, 2: size limit in KB. */
                esc_html__('"%1$s" is larger than %2$d KB.', 'mcpmate'),
                esc_html($relative),
                (int) ($limit / 1024)
            ),
            esc_html(ThemeDraftException::TOO_LARGE),
            413
        );
    }
}
