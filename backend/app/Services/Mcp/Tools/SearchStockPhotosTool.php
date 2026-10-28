<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\Stock\StockPhotoException;
use McpMate\Services\Mcp\Stock\Unsplash;
use McpMate\Services\Mcp\ToolResult;

/**
 * search_stock_photos → the Unsplash API with the site owner's key (cloud
 * searchStockPhotos.ts). Changes nothing on the site.
 */
final class SearchStockPhotosTool extends Tool
{
    private const MAX_PAGE = 100;

    private const STRUCTURED_BYTES = 18000;

    public function run(\stdClass $args): ToolResult
    {
        $stock = Unsplash::fromSettings();

        if ($stock === null) {
            return self::unavailable();
        }

        $query = Js::trim($args->query);
        if ($query === '') {
            return ToolResult::invalidInput('query must not be empty.');
        }

        try {
            $found = $stock->search($query, $args->page, $args->per_page, $args->orientation ?? null);
        } catch (StockPhotoException $e) {
            return self::stockError($e);
        }

        return self::out(self::format($query, $args->page, $args->per_page, $found));
    }

    /**
     * @param array{total: int, totalPages: int, results: array<int, array<string, mixed>>} $found
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $query, int $page, int $perPage, array $found): array
    {
        $nextPage   = $page < $found['totalPages'] && $page < self::MAX_PAGE ? $page + 1 : null;
        $results    = array_map([self::class, 'photoOutput'], $found['results']);
        $structured = [
            'query'       => $query,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $found['total'],
            'total_pages' => $found['totalPages'],
            'next_page'   => $nextPage,
            'results'     => self::fitResults($results),
        ];

        if ($results === []) {
            return [
                'text'       => C::finish(['No Unsplash photos match "' . C::oneLine($query, 100) . '"' . ($page > 1 ? ' on page ' . $page : '') . '.'], 'Try fewer or more general words.'),
                'structured' => $structured,
            ];
        }

        $lines = [];
        foreach ($results as $i => $photo) {
            $label   = $photo['alt'] !== '' ? $photo['alt'] : ($photo['description'] !== '' ? $photo['description'] : 'untitled');
            $lines[] = (($page - 1) * $perPage + $i + 1) . '. ' . $photo['stock_photo_id'] . ': ' . $label . ' (' . $photo['width'] . '×' . $photo['height'] . ')';
            $lines[] = '   preview: ' . $photo['preview_url'];
            $lines[] = '   credit: ' . $photo['attribution']['text'] . ' (' . $photo['photographer']['profile_url'] . ')';
        }

        $text = C::finish(
            [
                'Unsplash photos for "' . C::oneLine($query, 100) . '": ' . $found['total'] . ' found, page ' . $page . ' of ' . $found['totalPages'] . '.',
                C::siteData('Unsplash search results', implode("\n", $lines)),
            ],
            'Show the user a few previews with their credit and let them pick. Then call upload_media with the chosen stock_photo_id'
                . ($nextPage === null ? '.' : '; for more photos call search_stock_photos again with page ' . $nextPage . '.')
        );

        return ['text' => $text, 'structured' => $structured];
    }

    /**
     * @param array<string, mixed> $photo
     * @return array<string, mixed>
     */
    public static function photoOutput(array $photo): array
    {
        return [
            'stock_photo_id' => $photo['id'],
            'description'    => $photo['description'],
            'alt'            => $photo['alt'],
            'width'          => $photo['width'],
            'height'         => $photo['height'],
            'color'          => $photo['color'],
            'preview_url'    => $photo['previewUrl'],
            'image_url'      => $photo['imageUrl'],
            'photographer'   => ['name' => $photo['photographer']['name'], 'profile_url' => $photo['photographer']['profileUrl']],
            'unsplash_url'   => $photo['unsplashUrl'],
            'attribution'    => $photo['attribution'],
        ];
    }

    /**
     * Whole results only, never pruned: a photo without its attribution must not be shown.
     *
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private static function fitResults(array $results): array
    {
        while ($results !== [] && strlen(Js::stringify($results)) > self::STRUCTURED_BYTES) {
            array_pop($results);
        }

        return $results;
    }

    public static function unavailable(): ToolResult
    {
        return ToolResult::error(
            'stock_photos_unavailable',
            'Stock photo search is not set up on this site.',
            null,
            'Tell the user: a site admin can add an Unsplash key (McpMate → Tools → Integrations, Stock photos).'
                . ' To add an image anyway, ask them for a public image URL and pass it to upload_media as url.'
        );
    }

    public static function stockError(StockPhotoException $error): ToolResult
    {
        $next = [
            StockPhotoException::UNAVAILABLE => 'Tell the user stock photos are unavailable right now. To add an image anyway, ask them for a public image URL and pass it to upload_media as url.',
            StockPhotoException::NOT_FOUND   => 'Search again with search_stock_photos and use one of its stock_photo_ids.',
            StockPhotoException::FAILED      => 'Try again in a minute; do not retry in a loop.',
        ];

        return ToolResult::error($error->errorCode(), $error->getMessage(), $error->hint(), $next[$error->errorCode()] ?? null);
    }
}
