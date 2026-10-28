<?php

namespace McpMate\Services\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\SiteContext\BlockTree;

/**
 * Checks block markup before anything saves it (POST /blocks/validate).
 *
 * Errors (content that would break or lose data in the editor):
 * - `unknown_block`: a block name this site has not registered (server side);
 * - `invalid_attributes_json`: a delimiter whose attribute JSON does not decode;
 * - `invalid_attribute`: a value that fails the block type's attribute schema
 *   (rest_validate_value_from_schema(), as core checks it before rendering);
 * - `malformed_delimiter`: a `<!-- wp:` comment the parser could not read;
 * - `round_trip_mismatch`: serialize_blocks(parse_blocks($x)) is not $x, once
 *   delimiters are written canonically (CanonicalMarkup);
 * - `invalid_utf8`: the content is not UTF-8 (nothing else is checked).
 *
 * Warnings (valid, but probably not what was meant):
 * - `unknown_attribute`: not in the block type's schema, so the editor drops it;
 * - `sourced_attribute`: the editor reads it from the block's HTML and ignores
 *   the delimiter's value;
 * - `invalid_parent` / `invalid_ancestor` / `not_allowed_here`: the block
 *   type's `parent`, `ancestor` or its parent's `allowed_blocks`;
 * - `loose_html`: HTML outside any block (the editor shows a Classic block).
 *
 * Each issue has a `path` (`core/group[0] > core/paragraph[2]`: names and
 * 0-based positions among siblings, whitespace between blocks not counted),
 * the 1-based `line` of the block's opener when known, and a `hint`. What the
 * editor's JavaScript save() would output cannot be checked here, so markup
 * that passes can still show "This block contains unexpected or invalid
 * content" when its inner HTML differs from what the block saves.
 */
final class BlockValidator
{
    public const MAX_CONTENT_BYTES = 1048576;

    /** Issues listed per kind (errors, warnings); the counts include the rest. */
    public const MAX_ISSUES = 100;

    /** A comment that starts like a delimiter. Valid ones never survive parsing. */
    private const STRAY_DELIMITER = '/<!--\s*\/?\s*wp:[^>]{0,200}>?/i';

    /** Unknown names are matched to registered ones at most this many edits away. */
    private const SUGGESTION_DISTANCE = 3;

    /** How markup is quoted in hints. */
    private const QUOTE = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private string $content;

    private \WP_Block_Type_Registry $registry;

    private CanonicalMarkup $markup;

    /** @var int[] */
    private array $openers;

    private int $opener = 0;

    /** Original offset of the last block visited, for locating loose markup. */
    private int $cursor = 0;

    private int $blocks = 0;

    /** @var array{errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>} */
    private array $issues = ['errors' => [], 'warnings' => []];

    /** @var array{errors: int, warnings: int} */
    private array $counts = ['errors' => 0, 'warnings' => 0];

    private function __construct(string $content, \WP_Block_Type_Registry $registry)
    {
        $this->content  = $content;
        $this->registry = $registry;
        $this->markup   = new CanonicalMarkup($content);
        $this->openers  = $this->markup->openers();
    }

    /**
     * @return array{valid: bool, blocks: int, error_count: int, warning_count: int,
     *               errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>,
     *               issues_truncated: bool}
     */
    public static function validate(string $content): array
    {
        $validator = new self($content, \WP_Block_Type_Registry::get_instance());

        if (preg_match('//u', $content) !== 1) {
            $validator->error(
                'invalid_utf8',
                __('The content is not valid UTF-8.', 'mcpmate'),
                '',
                null,
                null,
                __('Send the content as UTF-8 text.', 'mcpmate')
            );

            return $validator->result();
        }

        $parsed = parse_blocks($content);

        $validator->walk($parsed, null, [], '');
        $validator->checkRoundTrip($parsed);

        return $validator->result();
    }

    // ──────────────────────────────────────────────
    //  Blocks
    // ──────────────────────────────────────────────

    /**
     * @param array<int, mixed> $blocks
     * @param string[] $ancestors
     */
    private function walk(array $blocks, ?\WP_Block_Type $parent, array $ancestors, string $prefix): void
    {
        $position = 0;

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : null;
            $html = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';

            if ($name === null) {
                if (trim($html) !== '') {
                    $this->checkLooseHtml($html, $prefix . 'core/freeform[' . $position . ']');
                    $position++;
                }
                continue;
            }

            $offset = $this->openers[$this->opener] ?? null;
            $this->opener++;
            $this->blocks++;

            if ($offset !== null) {
                $this->cursor = $offset;
            }

            $path = $prefix . $name . '[' . $position . ']';
            $line = $offset !== null ? $this->markup->line($offset) : null;
            $type = $this->registry->get_registered($name);
            $position++;

            if ($type instanceof \WP_Block_Type) {
                $this->checkAttributes($type, $block['attrs'] ?? null, $path, $line);
                $this->checkPlacement($type, $parent, $ancestors, $path, $line);
            } else {
                $this->unknownBlock($name, $path, $line);
            }

            $this->checkStrayDelimiter($html, $path, $name, $line);

            $inner = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : [];
            $this->walk($inner, $type instanceof \WP_Block_Type ? $type : null, array_merge($ancestors, [$name]), $path . ' > ');
        }
    }

    private function unknownBlock(string $name, string $path, ?int $line): void
    {
        $suggestion = $this->closestName($name);

        $hint = $suggestion !== null
            /* translators: %s: a registered block name. */
            ? sprintf(__('Did you mean "%s"?', 'mcpmate'), $suggestion) . ' '
            : '';
        $hint .= __('GET /blocks/types lists the registered blocks. A block registered only in JavaScript is not visible to this check.', 'mcpmate');

        $this->error(
            'unknown_block',
            /* translators: %s: block name. */
            sprintf(__('No block named "%s" is registered on this site.', 'mcpmate'), $name),
            $path,
            $name,
            $line,
            $hint
        );
    }

    /**
     * @param mixed $attrs parse_blocks() attrs: null when the JSON did not decode.
     */
    private function checkAttributes(\WP_Block_Type $type, $attrs, string $path, ?int $line): void
    {
        if (!is_array($attrs)) {
            $this->error(
                'invalid_attributes_json',
                __('The block\'s attributes are not a valid JSON object, so WordPress ignores all of them.', 'mcpmate'),
                $path,
                $type->name,
                $line,
                __('Write the attributes as one JSON object with double-quoted keys and strings, followed by a space: <!-- wp:name {"key":"value"} -->.', 'mcpmate')
            );

            return;
        }

        if (!is_array($type->attributes)) {
            return;
        }

        foreach ($attrs as $key => $value) {
            $key        = (string) $key;
            $definition = $type->attributes[$key] ?? null;
            $label      = BlockTree::clip($key, 100);

            if (!is_array($definition)) {
                $this->warning(
                    'unknown_attribute',
                    /* translators: 1: attribute name, 2: block name. */
                    sprintf(__('"%1$s" is not an attribute of %2$s, so the editor drops it.', 'mcpmate'), $label, $type->name),
                    $path,
                    $type->name,
                    $line,
                    /* translators: %s: comma-separated attribute names. */
                    sprintf(__('Its attributes are: %s.', 'mcpmate'), BlockTree::clip(implode(', ', array_keys($type->attributes)), 500)),
                    $label
                );
                continue;
            }

            if (isset($definition['source'])) {
                $this->warning(
                    'sourced_attribute',
                    /* translators: %s: attribute name. */
                    sprintf(__('"%s" is read from the block\'s HTML, so the value in the block comment is ignored.', 'mcpmate'), $label),
                    $path,
                    $type->name,
                    $line,
                    __('Put the value in the block\'s HTML and leave it out of the comment.', 'mcpmate'),
                    $label
                );
                continue;
            }

            $schema = AttributeSchema::forValidation($definition);
            if ($schema === null) {
                continue;
            }

            $valid = rest_validate_value_from_schema($value, $schema, $label);
            if (is_wp_error($valid)) {
                $this->error(
                    'invalid_attribute',
                    BlockTree::clip($valid->get_error_message(), 300),
                    $path,
                    $type->name,
                    $line,
                    trim(AttributeSchema::expected($definition) . ' ' . __('WordPress drops an invalid value when it renders the block.', 'mcpmate')),
                    $label
                );
            }
        }
    }

    /**
     * @param string[] $ancestors
     */
    private function checkPlacement(\WP_Block_Type $type, ?\WP_Block_Type $parent, array $ancestors, string $path, ?int $line): void
    {
        $parentName = $ancestors === [] ? null : end($ancestors);

        if (is_array($type->parent) && $type->parent !== [] && !in_array($parentName, $type->parent, true)) {
            $this->warning(
                'invalid_parent',
                /* translators: %s: block name. */
                sprintf(__('%s only works directly inside certain blocks.', 'mcpmate'), $type->name),
                $path,
                $type->name,
                $line,
                /* translators: %s: comma-separated block names. */
                sprintf(__('Put it directly inside: %s.', 'mcpmate'), implode(', ', $type->parent))
            );
        }

        if (is_array($type->ancestor) && $type->ancestor !== [] && array_intersect($ancestors, $type->ancestor) === []) {
            $this->warning(
                'invalid_ancestor',
                /* translators: %s: block name. */
                sprintf(__('%s only works somewhere inside certain blocks.', 'mcpmate'), $type->name),
                $path,
                $type->name,
                $line,
                /* translators: %s: comma-separated block names. */
                sprintf(__('Put it inside one of: %s.', 'mcpmate'), implode(', ', $type->ancestor))
            );
        }

        // allowed_blocks is a block type property since WordPress 6.5 (?? keeps older versions quiet).
        $allowed = $parent !== null ? ($parent->allowed_blocks ?? null) : null;
        if (is_array($allowed) && $allowed !== [] && !in_array($type->name, $allowed, true)) {
            $this->warning(
                'not_allowed_here',
                /* translators: 1: parent block name, 2: block name. */
                sprintf(__('%1$s does not allow %2$s inside it.', 'mcpmate'), (string) $parentName, $type->name),
                $path,
                $type->name,
                $line,
                /* translators: %s: comma-separated block names. */
                sprintf(__('It allows: %s.', 'mcpmate'), implode(', ', $allowed))
            );
        }
    }

    private function checkLooseHtml(string $html, string $path): void
    {
        $offset = strpos($this->content, $html, $this->cursor);
        $line   = $offset === false ? null : $this->markup->line($offset);

        if ($this->checkStrayDelimiter($html, $path, null, $line)) {
            return;
        }

        $this->warning(
            'loose_html',
            __('This HTML is outside any block; the editor shows it as a Classic block.', 'mcpmate'),
            $path,
            null,
            $line,
            /* translators: %s: start of the HTML. */
            sprintf(__('Wrap it in a block, e.g. <!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->. It starts: %s', 'mcpmate'), BlockTree::clip(trim($html), 80))
        );
    }

    /**
     * A `<!-- wp:` comment left in a block's own HTML is one the parser could
     * not read (uppercase name, no space before `-->`, unquoted JSON…).
     *
     * @return bool Whether one was found.
     */
    private function checkStrayDelimiter(string $html, string $path, ?string $name, ?int $line): bool
    {
        if (preg_match(self::STRAY_DELIMITER, $html, $match) !== 1) {
            return false;
        }

        $offset = strpos($this->content, $match[0], $this->cursor);
        if ($offset !== false) {
            $line = $this->markup->line($offset);
        }

        $this->error(
            'malformed_delimiter',
            /* translators: %s: the comment as written. */
            sprintf(__('WordPress cannot read this block comment and keeps it as plain HTML: %s', 'mcpmate'), BlockTree::clip($match[0], 120)),
            $path,
            $name,
            $line,
            __('Block comments look like <!-- wp:name {"key":"value"} --> … <!-- /wp:name -->, or <!-- wp:name /--> for a block without content.', 'mcpmate')
                . ' ' . __('Use a lowercase name, the attributes as one JSON object, and a space before -->.', 'mcpmate')
                . ' ' . __('A closing comment without its opener makes WordPress keep everything after it as plain HTML.', 'mcpmate')
        );

        return true;
    }

    // ──────────────────────────────────────────────
    //  Round trip
    // ──────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $parsed
     */
    private function checkRoundTrip(array $parsed): void
    {
        $serialized = serialize_blocks($parsed);
        if ($serialized === $this->content) {
            return;
        }

        $canonical = $this->markup->text();
        $at        = CanonicalMarkup::firstDifference($canonical, $serialized);
        if ($at === -1) {
            return;
        }

        $line   = $this->markup->line($this->markup->originalOffset($at));
        $before = CanonicalMarkup::excerpt($canonical, max(0, $at - 40), min(40, $at));
        $here   = CanonicalMarkup::excerpt($canonical, $at, 60);
        $saved  = CanonicalMarkup::excerpt($serialized, $at, 60);
        $ending = __('(end of content)', 'mcpmate');

        $hint = $before === ''
            ? ''
            /* translators: %s: the markup just before the difference. */
            : sprintf(__('After %s:', 'mcpmate'), wp_json_encode($before, self::QUOTE)) . ' ';
        $hint .= sprintf(
            /* translators: 1: the markup as written, 2: the markup as WordPress saves it. */
            __('written %1$s, but WordPress saves %2$s. Usually a block that is not closed, a closing comment that names another block, or attribute JSON that does not decode.', 'mcpmate'),
            $here === '' ? $ending : wp_json_encode($here, self::QUOTE),
            $saved === '' ? $ending : wp_json_encode($saved, self::QUOTE)
        );

        $this->error(
            'round_trip_mismatch',
            /* translators: %d: line number. */
            sprintf(__('WordPress would save this markup differently, starting on line %d.', 'mcpmate'), $line),
            '',
            null,
            $line,
            $hint
        );
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    private function closestName(string $name): ?string
    {
        $best     = null;
        $distance = self::SUGGESTION_DISTANCE + 1;
        $needle   = substr($name, 0, 255);

        foreach (array_keys($this->registry->get_all_registered()) as $candidate) {
            $candidate = (string) $candidate;
            $edits     = levenshtein($needle, substr($candidate, 0, 255));
            if ($edits < $distance) {
                $best     = $candidate;
                $distance = $edits;
            }
        }

        return $best;
    }

    private function error(string $code, string $message, string $path, ?string $block, ?int $line, string $hint, ?string $attribute = null): void
    {
        $this->add('errors', $code, $message, $path, $block, $line, $hint, $attribute);
    }

    private function warning(string $code, string $message, string $path, ?string $block, ?int $line, string $hint, ?string $attribute = null): void
    {
        $this->add('warnings', $code, $message, $path, $block, $line, $hint, $attribute);
    }

    private function add(string $kind, string $code, string $message, string $path, ?string $block, ?int $line, string $hint, ?string $attribute): void
    {
        $this->counts[$kind]++;

        if (count($this->issues[$kind]) >= self::MAX_ISSUES) {
            return;
        }

        $this->issues[$kind][] = [
            'code'      => $code,
            'message'   => $message,
            'path'      => BlockTree::clip($path, 500),
            'block'     => $block,
            'attribute' => $attribute,
            'line'      => $line,
            'hint'      => $hint,
        ];
    }

    /**
     * @return array{valid: bool, blocks: int, error_count: int, warning_count: int,
     *               errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>,
     *               issues_truncated: bool}
     */
    private function result(): array
    {
        return [
            'valid'            => $this->counts['errors'] === 0,
            'blocks'           => $this->blocks,
            'error_count'      => $this->counts['errors'],
            'warning_count'    => $this->counts['warnings'],
            'errors'           => $this->issues['errors'],
            'warnings'         => $this->issues['warnings'],
            'issues_truncated' => $this->counts['errors'] > count($this->issues['errors'])
                || $this->counts['warnings'] > count($this->issues['warnings']),
        ];
    }
}
