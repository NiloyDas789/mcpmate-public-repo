<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;

/**
 * list_block_types → GET blocks/types (cloud listBlockTypes.ts).
 */
final class ListBlockTypesTool extends Tool
{
    /** Block types per page. Core blocks carry 20–40 attributes each, so pages stay small. */
    public const PER_PAGE = 10;

    private const ATTRIBUTES_TEXT_CHARS = 1200;

    private const ATTRIBUTES_STRUCTURED_BYTES = 1200;

    private const DESCRIPTION_CHARS = 200;

    private const NAMESPACES_SHOWN = 40;

    public function run(\stdClass $args): ToolResult
    {
        $filters = ['namespace' => $args->namespace ?? null, 'search' => $args->search ?? null];
        $reply   = $this->client->blockTypes($filters + ['page' => $args->page, 'per_page' => self::PER_PAGE]);

        return self::out(self::format($this->siteId, $reply['data'], $filters));
    }

    /**
     * @param array<string, mixed>       $page
     * @param array<string, string|null> $filters
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, array $page, array $filters = []): array
    {
        $namespaces = Js::fromEntries(array_slice(Js::entries($page['namespaces']), 0, self::NAMESPACES_SHOWN));
        $types      = array_slice($page['block_types'], 0, self::PER_PAGE);
        $blockTypes = array_map(static fn (array $type): array => [
            'name'           => C::oneLine($type['name'], 200),
            'title'          => C::oneLine($type['title'], 100),
            'description'    => C::oneLine($type['description'], self::DESCRIPTION_CHARS),
            'category'       => $type['category'],
            'parent'         => $type['parent'],
            'ancestor'       => $type['ancestor'],
            'allowed_blocks' => $type['allowed_blocks'],
            'dynamic'        => $type['dynamic'],
            'attributes'     => C::fit($type['attributes'], self::ATTRIBUTES_STRUCTURED_BYTES),
        ], $types);

        $structured = ['site_id' => $siteId, 'total' => $page['total'], 'page' => $page['page'], 'total_pages' => $page['total_pages'], 'namespaces' => $namespaces, 'block_types' => $blockTypes];

        $namespaceLine = 'Namespaces: ' . implode(', ', array_map(static fn (array $entry): string => C::oneLine($entry[0], 50) . ' (' . $entry[1] . ')', Js::entries($namespaces))) . '.';
        $filtered      = ($filters['namespace'] ?? null) !== null || ($filters['search'] ?? null) !== null;

        if ($blockTypes === []) {
            $none = $filtered ? 'No block types match these filters.' : 'This site has no block types registered on the server.';

            return [
                'text'       => C::finish(
                    [$page['page'] > 1 ? 'Page ' . $page['page'] . ' is past the last page (' . $page['total_pages'] . ').' : $none, C::siteData('namespaces', $namespaceLine)],
                    $filtered ? 'Try another namespace from the list, a broader search, or no filters.' : null
                ),
                'structured' => $structured,
            ];
        }

        $lines = [];
        foreach ($types as $type) {
            $place = array_values(array_filter([
                $type['parent'] === null ? null : 'directly inside ' . implode(' or ', $type['parent']),
                $type['ancestor'] === null ? null : 'somewhere inside ' . implode(' or ', $type['ancestor']),
                $type['allowed_blocks'] === null ? null : 'allows only ' . implode(', ', $type['allowed_blocks']),
            ], static fn (?string $part): bool => $part !== null));
            $attributes = array_map(static fn (array $entry): string => self::attributeText($entry[0], $entry[1]), Js::entries($type['attributes']));

            $lines[] = '- ' . C::oneLine($type['name'], 200) . ' — ' . C::oneLine($type['title'], 100) . ($type['dynamic'] ? ' [dynamic: write it as void <!-- wp:… /-->]' : '');
            if ($type['description'] !== '') {
                $lines[] = '  ' . C::oneLine($type['description'], self::DESCRIPTION_CHARS);
            }
            if ($place !== []) {
                $lines[] = '  placement: ' . C::oneLine(implode('; ', $place), 400);
            }
            $lines[] = '  attributes: ' . ($attributes === [] ? 'none' : C::oneLine(implode('; ', $attributes), self::ATTRIBUTES_TEXT_CHARS));
        }

        $more = $page['page'] < $page['total_pages'];
        $text = C::finish(
            [
                $page['total'] . ' block ' . ($page['total'] === 1 ? 'type' : 'types') . ' (page ' . $page['page'] . ' of ' . max($page['total_pages'], 1) . ').',
                C::siteData('block types', implode("\n", array_merge([$namespaceLine], $lines))),
            ],
            $more ? 'There are more: call list_block_types with page ' . ($page['page'] + 1) . ($filtered ? ' and the same filters' : '') . '.' : null,
            'Narrow the list with namespace or search.'
        );

        return ['text' => $text, 'structured' => $structured];
    }

    /**
     * `level: number = 2`, `align: string (left|center|right)`, `content: rich-text from HTML (rich-text at p)`.
     *
     * @param array<string, mixed>|\stdClass $attribute
     */
    public static function attributeText(string $name, $attribute): string
    {
        $attribute = (array) $attribute;
        $type      = !array_key_exists('type', $attribute) ? 'any' : (is_array($attribute['type']) ? implode('|', $attribute['type']) : $attribute['type']);
        $values    = !array_key_exists('enum', $attribute) ? '' : ' (' . implode('|', array_map([Js::class, 'stringify'], $attribute['enum'])) . ')';
        $fallback  = !array_key_exists('default', $attribute) ? '' : ' = ' . Js::stringify($attribute['default']);
        $source    = '';

        if (array_key_exists('source', $attribute)) {
            $parts  = [$attribute['selector'] ?? null, array_key_exists('attribute', $attribute) ? '@' . $attribute['attribute'] : null];
            $at     = implode(' ', array_filter($parts, static fn ($part): bool => $part !== null));
            $source = ' from HTML (' . $attribute['source'] . ($at === '' ? '' : ' at ' . $at) . ')';
        }

        return C::oneLine($name, 100) . ': ' . $type . $values . $fallback . $source;
    }
}
