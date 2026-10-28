<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks a theme file before it is saved. A failing file is never written.
 *
 * PHP:
 * 1. Syntax: token_get_all() with TOKEN_PARSE runs PHP's own parser on the
 *    code (nothing is executed or included). A ParseError is reported with
 *    its line.
 * 2. Lint pass over the tokens, for mistakes that parse but break the site
 *    when the file loads:
 *    - a UTF-8 byte order mark, or any output (text outside <?php ?>) in the
 *      theme's functions.php: it is sent before headers and corrupts REST
 *      and AJAX responses;
 *    - short open tags (`<?` without `php`): with short_open_tag off the
 *      code is printed as text;
 *    - a function, class, interface, trait or enum declared twice at the
 *      top level of the file ("Cannot redeclare", a fatal error).
 *
 * JSON (theme.json, block.json): must decode.
 *
 * style.css at the draft root: must keep its `Theme Name:` header, or
 * WordPress no longer sees the folder as a theme.
 *
 * Pure PHP, so it is unit-tested without WordPress.
 */
final class CodeLint
{
    /** WordPress reads theme headers from the first 8 KiB of style.css. */
    private const HEADER_BYTES = 8192;

    /**
     * @param string $relative Normalized path inside the draft.
     * @param string $ext      From DraftPath::extension().
     * @throws ThemeDraftException 400 with the line in the message.
     */
    public static function check(string $relative, string $ext, string $content): void
    {
        if (strpos($content, "\0") !== false) {
            throw self::fail(
                esc_html(ThemeDraftException::LINT_FAILED),
                /* translators: %s: file path inside the draft theme. */
                sprintf(esc_html__('"%s" contains NUL bytes; only text files can be written.', 'mcpmate'), esc_html($relative)),
                ''
            );
        }

        if ($ext === 'php') {
            self::php($relative, $content);
        } elseif ($ext === 'json') {
            self::json($relative, $content);
        } elseif ($relative === 'style.css') {
            self::styleHeader($content);
        }
    }

    // ──────────────────────────────────────────────
    //  PHP
    // ──────────────────────────────────────────────

    /**
     * @throws ThemeDraftException
     */
    public static function php(string $relative, string $code): void
    {
        if (strncmp($code, "\xEF\xBB\xBF", 3) === 0) {
            throw self::lintError(esc_html($relative), 1, esc_html__('the file starts with a UTF-8 byte order mark, which PHP sends as output before any headers. Save it without a BOM.', 'mcpmate'));
        }

        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (\CompileError $e) {
            // ParseError extends CompileError; both carry the line PHP stopped at.
            throw self::fail(
                esc_html(ThemeDraftException::PHP_SYNTAX),
                sprintf(
                    /* translators: 1: file path inside the draft theme, 2: line number, 3: PHP's parse error message. */
                    esc_html__('PHP syntax error in "%1$s" on line %2$d: %3$s', 'mcpmate'),
                    esc_html($relative),
                    (int) $e->getLine(),
                    esc_html($e->getMessage())
                ),
                /* translators: %d: line number. */
                sprintf(esc_html__('Fix line %d (or the line before it) and send the file again. Nothing was saved.', 'mcpmate'), (int) $e->getLine())
            );
        }

        self::lintTokens($relative, $tokens);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @throws ThemeDraftException
     */
    private static function lintTokens(string $relative, array $tokens): void
    {
        $isFunctionsFile = $relative === 'functions.php';
        $enum            = defined('T_ENUM') ? (int) constant('T_ENUM') : -1;
        $declarations    = [T_FUNCTION => 'function', T_CLASS => 'class', T_INTERFACE => 'interface', T_TRAIT => 'trait'];
        if ($enum !== -1) {
            $declarations[$enum] = 'enum';
        }

        $depth     = 0;
        $topDepth  = 0;
        $namespace = '';
        $declared  = [];
        $line      = 1;
        $count     = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                    if ($depth < $topDepth) {
                        $topDepth  = 0;
                        $namespace = '';
                    }
                }
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                // "{$var}" and "${var}" inside strings close with a plain '}'.
                $depth++;
            } elseif ($id === T_OPEN_TAG) {
                if (strtolower(substr($text, 0, 5)) !== '<?php') {
                    throw self::lintError(esc_html($relative), (int) $line, esc_html__('short open tag "<?"; use "<?php".', 'mcpmate'));
                }
            } elseif ($id === T_INLINE_HTML) {
                if ($isFunctionsFile) {
                    throw self::lintError(
                        esc_html($relative),
                        (int) $line,
                        esc_html__('text outside the PHP tags is output on every request, which breaks REST and AJAX. Remove it, before "<?php" and after "?>".', 'mcpmate')
                    );
                }

                if (preg_match('/<\?(?!php|=|xml)/i', $text)) {
                    throw self::lintError(esc_html($relative), (int) $line, esc_html__('short open tag "<?"; use "<?php". With short_open_tag off the code is printed as text.', 'mcpmate'));
                }
            } elseif ($id === T_NAMESPACE) {
                [$name, $braced, $isDeclaration] = self::namespaceAt($tokens, $i);
                if ($isDeclaration) {
                    $namespace = $name === '' ? '' : strtolower($name) . '\\';
                    $topDepth  = $braced ? $depth + 1 : 0;
                }
            } elseif (isset($declarations[$id]) && $depth === $topDepth) {
                $name = self::declaredName($tokens, $i);
                if ($name === null) {
                    continue;
                }

                // Classes, interfaces, traits and enums share one symbol table.
                $kind = $id === T_FUNCTION ? 'function' : 'class';
                $key  = $kind . ':' . $namespace . strtolower($name);

                if (isset($declared[$key])) {
                    throw self::lintError(
                        esc_html($relative),
                        (int) $line,
                        sprintf(
                            /* translators: 1: "function", "class", etc., 2: symbol name, 3: line number. */
                            esc_html__('%1$s "%2$s" is declared twice in this file (first on line %3$d); PHP stops with "Cannot redeclare".', 'mcpmate'),
                            esc_html($declarations[$id]),
                            esc_html($name),
                            (int) $declared[$key]
                        )
                    );
                }

                $declared[$key] = $line;
            }
        }
    }

    /**
     * The name declared by the keyword at $i, or null for closures,
     * anonymous classes, `Foo::class` and `use function` imports.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function declaredName(array $tokens, int $i): ?string
    {
        $previous = self::significant($tokens, $i, -1);
        if ($previous !== null && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_DOUBLE_COLON, T_NEW, T_USE, T_OBJECT_OPERATOR], true)) {
            return null;
        }

        $next = self::significant($tokens, $i, 1);

        // `function &name()`. PHP 8.1+ tokenizes this `&` as an array token.
        if ($next !== null && (is_array($tokens[$next]) ? $tokens[$next][1] : $tokens[$next]) === '&') {
            $next = self::significant($tokens, $next, 1);
        }

        return $next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING ? $tokens[$next][1] : null;
    }

    /**
     * Name of the namespace declared at $i, whether it opens a block, and
     * whether it is a declaration at all (`namespace\foo()` is an expression).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: string, 1: bool, 2: bool}
     */
    private static function namespaceAt(array $tokens, int $i): array
    {
        $name  = '';
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if ($token === ';' || $token === '{') {
                return [$name, $token === '{', true];
            }

            if (!is_array($token)) {
                return ['', false, false];
            }

            if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            if ($token[0] === T_NS_SEPARATOR && $name === '') {
                return ['', false, false];
            }

            $name .= $token[1];
        }

        return ['', false, false];
    }

    /**
     * Index of the nearest token before (-1) or after (1) $i that is not
     * whitespace or a comment.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function significant(array $tokens, int $i, int $step): ?int
    {
        for ($j = $i + $step; isset($tokens[$j]); $j += $step) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  JSON and style.css
    // ──────────────────────────────────────────────

    /**
     * @throws ThemeDraftException
     */
    public static function json(string $relative, string $content): void
    {
        json_decode($content);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw self::fail(
                esc_html(ThemeDraftException::JSON_INVALID),
                sprintf(
                    /* translators: 1: file path inside the draft theme, 2: JSON error message. */
                    esc_html__('"%1$s" is not valid JSON: %2$s.', 'mcpmate'),
                    esc_html($relative),
                    esc_html(json_last_error_msg())
                ),
                esc_html__('Send the whole file as valid JSON. Nothing was saved.', 'mcpmate')
            );
        }
    }

    /**
     * @throws ThemeDraftException
     */
    public static function styleHeader(string $content): void
    {
        // Same pattern as core's get_file_data().
        if (!preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*Theme Name:(.*)$/mi', substr($content, 0, self::HEADER_BYTES), $match) || trim($match[1]) === '') {
            throw self::fail(
                esc_html(ThemeDraftException::LINT_FAILED),
                esc_html__('style.css must keep its "Theme Name:" header in the first 8 KB, or WordPress no longer sees the draft as a theme.', 'mcpmate'),
                esc_html__('Keep the comment block at the top of style.css. Nothing was saved.', 'mcpmate')
            );
        }
    }

    private static function lintError(string $relative, int $line, string $problem): ThemeDraftException
    {
        return self::fail(
            ThemeDraftException::LINT_FAILED,
            sprintf(
                /* translators: 1: file path inside the draft theme, 2: line number, 3: what is wrong. */
                __('PHP lint error in "%1$s" on line %2$d: %3$s', 'mcpmate'),
                $relative,
                $line,
                $problem
            ),
            /* translators: %d: line number. */
            sprintf(__('Fix line %d and send the file again. Nothing was saved.', 'mcpmate'), $line)
        );
    }

    private static function fail(string $code, string $message, string $hint): ThemeDraftException
    {
        return new ThemeDraftException(esc_html($message), esc_html($code), 400, esc_html($hint));
    }
}
