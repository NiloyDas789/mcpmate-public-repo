<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Paths inside a theme draft folder.
 *
 * A client path goes through two checks before any file is touched:
 *
 * 1. normalize(): lexical. Relative, `/`-separated, at most MAX_DEPTH
 *    segments of letters, digits and ` _@+.-`. No `..`, `.`, empty
 *    segments, backslashes, NUL bytes or hidden names (`.htaccess`,
 *    `.user.ini`). Files also need an allowlisted, lowercase extension
 *    (extension()), and no inner extension a web server might execute
 *    (`x.php.txt`).
 * 2. resolve() / resolveForWrite(): every existing component is resolved
 *    with realpath() and must stay inside the draft's own realpath.
 *    Symbolic links are refused outright, wherever they point.
 *
 * Pure PHP (no WordPress), so it is unit-tested against a real temp folder.
 */
final class DraftPath
{
    /** File types the file tools read, write and delete. */
    public const EXTENSIONS = ['php', 'css', 'js', 'json', 'html', 'svg', 'txt', 'md'];

    public const MAX_LENGTH = 255;

    public const MAX_DEPTH = 12;

    /** Inner extensions Apache's AddHandler would still run as PHP. */
    private const EXECUTABLE = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps'];

    private const SEGMENT = '/^[A-Za-z0-9_@+][A-Za-z0-9 _@+.-]*$/D';

    /**
     * The canonical relative form of $path: '' for the draft root.
     *
     * @throws ThemeDraftException 400 for anything but a plain relative path.
     */
    public static function normalize(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path === '.' || $path === '/') {
            return '';
        }

        if (strlen($path) > self::MAX_LENGTH || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            throw self::invalid();
        }

        if ($path[0] === '/' || preg_match('#^[A-Za-z]:#', $path)) {
            throw new ThemeDraftException(
                esc_html__('Paths are relative to the draft theme folder.', 'mcpmate'),
                esc_html(ThemeDraftException::INVALID_PATH),
                400,
                esc_html__('Use a path like "functions.php" or "assets/css/main.css".', 'mcpmate')
            );
        }

        // A trailing slash names a directory: "assets/" is "assets".
        $segments = explode('/', rtrim($path, '/'));

        if (count($segments) > self::MAX_DEPTH) {
            throw self::invalid();
        }

        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new ThemeDraftException(
                    esc_html__('The path must not contain "." or ".." segments.', 'mcpmate'),
                    esc_html(ThemeDraftException::OUTSIDE_DRAFT),
                    400,
                    esc_html__('Name files relative to the draft theme folder; nothing outside it can be reached.', 'mcpmate')
                );
            }

            // Trailing dots and spaces are dropped by some filesystems, so "a.php." could become "a.php".
            if (!preg_match(self::SEGMENT, $segment) || substr($segment, -1) === '.' || substr($segment, -1) === ' ') {
                throw self::invalid();
            }
        }

        return implode('/', $segments);
    }

    /**
     * The file's extension, once its name passes the allowlist.
     *
     * @param string $relative A normalized, non-empty path.
     * @throws ThemeDraftException 400 when the type is not allowlisted.
     */
    public static function extension(string $relative): string
    {
        $name  = basename($relative);
        $parts = explode('.', $name);
        $ext   = count($parts) > 1 ? (string) array_pop($parts) : '';

        if ($ext === '' || !in_array($ext, self::EXTENSIONS, true)) {
            throw new ThemeDraftException(
                /* translators: %s: file path inside the draft theme. */
                sprintf(esc_html__('"%s" is not a file type the theme file tools handle.', 'mcpmate'), esc_html($relative)),
                esc_html(ThemeDraftException::FILE_TYPE),
                400,
                /* translators: %s: comma-separated file extensions. */
                sprintf(esc_html__('Allowed extensions (lowercase): %s.', 'mcpmate'), esc_html(implode(', ', self::EXTENSIONS)))
            );
        }

        array_shift($parts);
        foreach ($parts as $inner) {
            if (in_array(strtolower($inner), self::EXECUTABLE, true)) {
                throw new ThemeDraftException(
                    /* translators: %s: file path inside the draft theme. */
                    sprintf(esc_html__('"%s" has a script extension inside its name.', 'mcpmate'), esc_html($relative)),
                    esc_html(ThemeDraftException::FILE_TYPE),
                    400,
                    esc_html__('Name PHP files with a single .php extension.', 'mcpmate')
                );
            }
        }

        return $ext;
    }

    /**
     * The realpath of a draft folder, the root every other path is checked against.
     *
     * @throws ThemeDraftException 404 when the folder is gone.
     */
    public static function root(string $directory): string
    {
        $real = realpath($directory);

        if ($real === false || !is_dir($real) || is_link($directory)) {
            throw new ThemeDraftException(
                esc_html__('The draft theme folder no longer exists.', 'mcpmate'),
                esc_html(ThemeDraftException::DRAFT_NOT_FOUND),
                404,
                esc_html__('Create a new draft with POST /theme-draft.', 'mcpmate')
            );
        }

        return self::slashes($real);
    }

    /**
     * The absolute path of an existing file or directory inside $root.
     *
     * @param string $root     From root().
     * @param string $relative From normalize().
     * @throws ThemeDraftException 404 missing, 400 outside the draft.
     */
    public static function resolve(string $root, string $relative): string
    {
        $path = $root;

        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $path .= '/' . $segment;

            if (is_link($path)) {
                throw self::outside(esc_html($relative));
            }
        }

        $real = realpath($path);

        if ($real === false) {
            throw new ThemeDraftException(
                /* translators: %s: file path inside the draft theme. */
                sprintf(esc_html__('"%s" does not exist in the draft.', 'mcpmate'), esc_html($relative)),
                esc_html(ThemeDraftException::NOT_FOUND),
                404,
                esc_html__('List the draft\'s files to see what exists.', 'mcpmate')
            );
        }

        $real = self::slashes($real);

        if (!self::isInside($root, $real)) {
            throw self::outside(esc_html($relative));
        }

        return $real;
    }

    /**
     * The absolute path to write $relative to, creating missing parent
     * directories inside $root. Each directory is realpath-checked as it is
     * reached or created; the file itself may not exist yet.
     *
     * @param string $root     From root().
     * @param string $relative From normalize(), not empty.
     * @throws ThemeDraftException 400 outside the draft or not a directory, 500 mkdir failed.
     */
    public static function resolveForWrite(string $root, string $relative): string
    {
        $segments = explode('/', $relative);
        $file     = (string) array_pop($segments);
        $dir      = $root;

        foreach ($segments as $segment) {
            $next = $dir . '/' . $segment;

            if (is_link($next)) {
                throw self::outside(esc_html($relative));
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Inside the realpath-checked draft folder; WP_Filesystem needs credentials REST requests do not have.
            if (!file_exists($next) && !mkdir($next, 0755) && !is_dir($next)) {
                throw new ThemeDraftException(
                    esc_html__('A folder could not be created in the draft.', 'mcpmate'),
                    esc_html(ThemeDraftException::FILESYSTEM),
                    500
                );
            }

            if (!is_dir($next)) {
                throw new ThemeDraftException(
                    /* translators: %s: file path inside the draft theme. */
                    sprintf(esc_html__('A parent of "%s" is a file, not a folder.', 'mcpmate'), esc_html($relative)),
                    esc_html(ThemeDraftException::INVALID_PATH),
                    400
                );
            }

            $real = realpath($next);
            if ($real === false || !self::isInside($root, self::slashes($real))) {
                throw self::outside(esc_html($relative));
            }

            $dir = self::slashes($real);
        }

        $target = $dir . '/' . $file;

        if (is_link($target)) {
            throw self::outside(esc_html($relative));
        }

        if (is_dir($target)) {
            throw new ThemeDraftException(
                /* translators: %s: file path inside the draft theme. */
                sprintf(esc_html__('"%s" is a folder.', 'mcpmate'), esc_html($relative)),
                esc_html(ThemeDraftException::NOT_A_FILE),
                400
            );
        }

        if (file_exists($target)) {
            $real = realpath($target);

            if ($real === false || !self::isInside($root, self::slashes($real))) {
                throw self::outside(esc_html($relative));
            }
        }

        return $target;
    }

    /**
     * True when $path is $root or below it. Both must be realpaths.
     */
    public static function isInside(string $root, string $path): bool
    {
        $root = rtrim(self::slashes($root), '/');
        $path = self::slashes($path);

        return $path === $root || strpos($path, $root . '/') === 0;
    }

    /**
     * The path relative to $root, for responses (never an absolute server path).
     */
    public static function relative(string $root, string $path): string
    {
        $root = rtrim(self::slashes($root), '/');
        $path = self::slashes($path);

        return $path === $root ? '' : (string) substr($path, strlen($root) + 1);
    }

    private static function slashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function invalid(): ThemeDraftException
    {
        return new ThemeDraftException(
            esc_html__('The path is not a valid theme file path.', 'mcpmate'),
            esc_html(ThemeDraftException::INVALID_PATH),
            400,
            sprintf(
                /* translators: %d: maximum path length. */
                esc_html__('Use letters, digits, spaces and _ @ + . - in names, "/" between folders, no hidden (dot) names, at most %d characters.', 'mcpmate'),
                (int) self::MAX_LENGTH
            )
        );
    }

    private static function outside(string $relative): ThemeDraftException
    {
        return new ThemeDraftException(
            /* translators: %s: file path inside the draft theme. */
            sprintf(esc_html__('"%s" resolves outside the draft theme folder.', 'mcpmate'), esc_html($relative)),
            esc_html(ThemeDraftException::OUTSIDE_DRAFT),
            400,
            esc_html__('Symbolic links are not followed; only files inside the draft can be reached.', 'mcpmate')
        );
    }
}
