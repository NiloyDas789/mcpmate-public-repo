<?php

namespace McpMate\Services\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Block markup with every delimiter written the way serialize_blocks() writes it.
 *
 * serialize_blocks(parse_blocks($x)) differs from $x whenever a delimiter is
 * written loosely: `wp:core/paragraph`, spaces in the attribute JSON, an empty
 * block with an opener and a closer. WordPress reads those correctly, so they
 * are not errors. Comparing serialize_blocks() output with this text instead
 * leaves only the differences that change the content: an unclosed block, a
 * closer naming another block, attribute JSON that does not decode.
 *
 * Delimiters are found with WP_Block_Parser's own pattern, so the n-th opener
 * here is the n-th block parse_blocks() returns, in document order.
 */
final class CanonicalMarkup
{
    /** WP_Block_Parser::next_token()'s pattern (wp-includes/class-wp-block-parser.php). */
    public const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

    private string $original;

    private string $text = '';

    /**
     * Where each piece of the canonical text came from:
     * [canonical offset, original offset, copied verbatim].
     *
     * @var array<int, array{0: int, 1: int, 2: bool}>
     */
    private array $segments = [];

    /** @var int[] Original offset of every opener (void or not). */
    private array $openers = [];

    private int $lineOffset = 0;

    private int $line = 1;

    public function __construct(string $content)
    {
        $this->original = $content;

        preg_match_all(self::DELIMITER, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $cursor = 0;
        $count  = count($matches);

        for ($i = 0; $i < $count; $i++) {
            $match = $matches[$i];
            $start = $match[0][1];
            $end   = $start + strlen($match[0][0]);

            $this->append((string) substr($content, $cursor, $start - $cursor), $cursor, true);

            $name     = self::name($match);
            $isCloser = self::group($match, 'closer') !== '';

            if ($isCloser) {
                $canonical = '<!-- /wp:' . $name . ' -->';
            } else {
                $this->openers[] = $start;
                $canonical       = self::opener($match, $name);
                $next            = $matches[$i + 1] ?? null;

                // serialize_block() writes a block without content as void.
                if (
                    $canonical !== null
                    && self::group($match, 'void') === ''
                    && $next !== null
                    && $next[0][1] === $end
                    && self::group($next, 'closer') !== ''
                    && self::name($next) === $name
                ) {
                    $canonical = substr($canonical, 0, -3) . '/-->';
                    $end      += strlen($next[0][0]);
                    $i++;
                }
            }

            // Attribute JSON that does not decode stays as written: it cannot round-trip.
            $this->append($canonical ?? $match[0][0], $start, false);
            $cursor = $end;
        }

        $this->append((string) substr($content, $cursor), $cursor, true);
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * Original offsets of the block openers, in document order.
     *
     * @return int[]
     */
    public function openers(): array
    {
        return $this->openers;
    }

    /**
     * Map an offset in text() back to the original content. Inside a
     * rewritten delimiter, that is where the delimiter starts.
     */
    public function originalOffset(int $offset): int
    {
        $low  = 0;
        $high = count($this->segments) - 1;
        $hit  = null;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->segments[$mid][0] <= $offset) {
                $hit = $this->segments[$mid];
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        if ($hit === null) {
            return 0;
        }

        return $hit[2] ? $hit[1] + ($offset - $hit[0]) : $hit[1];
    }

    /**
     * 1-based line of an original offset. Cheapest when called with
     * increasing offsets.
     */
    public function line(int $offset): int
    {
        $offset = max(0, min($offset, strlen($this->original)));

        if ($offset < $this->lineOffset) {
            $this->lineOffset = 0;
            $this->line       = 1;
        }

        $this->line      += substr_count($this->original, "\n", $this->lineOffset, $offset - $this->lineOffset);
        $this->lineOffset = $offset;

        return $this->line;
    }

    /**
     * Offset of the first byte where $a and $b differ, or -1 when they are equal.
     */
    public static function firstDifference(string $a, string $b): int
    {
        $length = min(strlen($a), strlen($b));
        $offset = 0;

        while ($offset < $length) {
            $chunk = min(4096, $length - $offset);
            if (substr($a, $offset, $chunk) !== substr($b, $offset, $chunk)) {
                break;
            }
            $offset += $chunk;
        }

        while ($offset < $length && $a[$offset] === $b[$offset]) {
            $offset++;
        }

        return $offset === $length && strlen($a) === strlen($b) ? -1 : $offset;
    }

    /**
     * Up to $length bytes of $text from $start, moved to UTF-8 character
     * boundaries (mbstring is not guaranteed).
     */
    public static function excerpt(string $text, int $start, int $length): string
    {
        $size  = strlen($text);
        $start = max(0, min($start, $size));

        while ($start > 0 && $start < $size && (ord($text[$start]) & 0xC0) === 0x80) {
            $start--;
        }

        $end = min($size, $start + $length);
        while ($end > $start && $end < $size && (ord($text[$end]) & 0xC0) === 0x80) {
            $end--;
        }

        return (string) substr($text, $start, $end - $start);
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    private function append(string $piece, int $originalOffset, bool $verbatim): void
    {
        if ($piece === '') {
            return;
        }

        $this->segments[] = [strlen($this->text), $originalOffset, $verbatim];
        $this->text      .= $piece;
    }

    /**
     * The name as serialize_block() writes it: without the `core/` namespace.
     *
     * @param array<int|string, array{0: string, 1: int}> $match
     */
    private static function name(array $match): string
    {
        $namespace = self::group($match, 'namespace');

        return ($namespace === '' || $namespace === 'core/' ? '' : $namespace) . self::group($match, 'name');
    }

    /**
     * @param array<int|string, array{0: string, 1: int}> $match
     * @return string|null Null when the attribute JSON does not decode.
     */
    private static function opener(array $match, string $name): ?string
    {
        $close = self::group($match, 'void') !== '' ? '/-->' : '-->';
        $attrs = self::group($match, 'attrs');

        if ($attrs === '') {
            return '<!-- wp:' . $name . ' ' . $close;
        }

        $decoded = json_decode($attrs, true);
        if (!is_array($decoded)) {
            return null;
        }

        $serialized = $decoded === [] ? '' : serialize_block_attributes($decoded) . ' ';

        return '<!-- wp:' . $name . ' ' . $serialized . $close;
    }

    /**
     * A named group's text, or '' when it did not take part in the match.
     *
     * @param array<int|string, array{0: string, 1: int}> $match
     */
    private static function group(array $match, string $name): string
    {
        return isset($match[$name]) && $match[$name][1] !== -1 ? $match[$name][0] : '';
    }
}
