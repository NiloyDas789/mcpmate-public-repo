<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;

/**
 * list_abilities → GET abilities (cloud listAbilities.ts).
 */
final class ListAbilitiesTool extends Tool
{
    /** Abilities per page. Schemas can be large, so a page stays small enough for every schema to fit. */
    public const PER_PAGE = 8;

    private const INPUT_SCHEMA_BYTES = 1200;

    private const OUTPUT_SCHEMA_BYTES = 300;

    private const DESCRIPTION_CHARS = 300;

    public function run(\stdClass $args): ToolResult
    {
        $filters = ['search' => $args->search ?? null, 'category' => $args->category ?? null];
        $reply   = $this->client->abilities($filters + ['page' => $args->page, 'per_page' => self::PER_PAGE]);

        return self::out(self::format($this->siteId, $reply['data'], $filters));
    }

    /**
     * @param array<string, mixed>       $page
     * @param array<string, string|null> $filters
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, array $page, array $filters = []): array
    {
        $abilities = array_map(static fn (array $ability): array => [
            'name'          => C::oneLine($ability['name'], 200),
            'label'         => C::oneLine($ability['label'], 100),
            'description'   => C::oneLine($ability['description'], self::DESCRIPTION_CHARS),
            'category'      => C::oneLine($ability['category'], 100),
            'risk'          => $ability['risk'],
            'enabled'       => $ability['enabled'],
            'annotations'   => $ability['annotations'],
            'input_schema'  => C::fit($ability['input_schema'] ?? null, self::INPUT_SCHEMA_BYTES),
            'output_schema' => C::fit($ability['output_schema'] ?? null, self::OUTPUT_SCHEMA_BYTES),
        ], array_slice($page['abilities'], 0, self::PER_PAGE));

        $structured = ['site_id' => $siteId, 'total' => $page['total'], 'page' => $page['page'], 'total_pages' => $page['total_pages'], 'abilities' => $abilities];
        $filtered   = ($filters['search'] ?? null) !== null || ($filters['category'] ?? null) !== null;

        if ($abilities === []) {
            $none = $filtered ? 'No abilities match these filters.' : 'This site has no abilities that plugins expose over the REST API.';

            return [
                'text'       => C::finish(
                    [$page['page'] > 1 ? 'Page ' . $page['page'] . ' is past the last page (' . $page['total_pages'] . ').' : $none],
                    $filtered ? 'Try a broader search, or no filters.' : null
                ),
                'structured' => $structured,
            ];
        }

        $lines = [];
        foreach ($abilities as $ability) {
            $lines[] = '- ' . $ability['name'] . ' — ' . $ability['label'] . ' [' . $ability['risk'] . ', ' . ($ability['enabled'] ? 'enabled' : 'disabled') . ']';
            $lines[] = '  ' . $ability['description'];
            $lines[] = '  input: ' . ($ability['input_schema'] === null ? 'none (leave input out)' : Js::stringify($ability['input_schema']));
        }

        $disabled = count(array_filter($abilities, static fn (array $ability): bool => !$ability['enabled']));
        $next     = null;

        if ($page['page'] < $page['total_pages']) {
            $next = 'There are more: call list_abilities with page ' . ($page['page'] + 1) . ($filtered ? ' and the same filters' : '') . '.';
        } elseif ($disabled > 0) {
            // docs/mcp-in-plugin.md §7.4, E1: the Tools page holds the switches now.
            $next = 'Disabled abilities cannot run until a site admin enables them under McpMate → Tools → Abilities.';
        }

        $text = C::finish(
            [
                $page['total'] . ' ' . ($page['total'] === 1 ? 'ability' : 'abilities') . ' (page ' . $page['page'] . ' of ' . max($page['total_pages'], 1) . ').'
                    . ' Destructive ones wait for a site admin’s approval on every run.',
                C::siteData('abilities', implode("\n", $lines)),
            ],
            $next,
            'Narrow the list with search or category.'
        );

        return ['text' => $text, 'structured' => $structured];
    }
}
