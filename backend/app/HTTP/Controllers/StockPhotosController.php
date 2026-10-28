<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\Mcp\Stock\StockPhotoSettings;
use McpMate\Services\Stats\Stats;

/**
 * Tools → Integrations, Stock photos (docs/mcp-in-plugin.md §6.1, §8): the
 * site owner's Unsplash access key and app name. The key is write-only:
 * nothing returns it, only whether one is set.
 *
 * AJAX routes (nonce + admin).
 */
final class StockPhotosController
{
    public function show(Request $request): Response
    {
        return Response::success(StockPhotoSettings::summary());
    }

    /**
     * `{access_key, app_name}`; an empty access_key removes the key.
     */
    public function update(Request $request): Response
    {
        $key  = trim((string) $request->get('access_key', ''));
        $name = sanitize_text_field((string) $request->get('app_name', ''));

        try {
            StockPhotoSettings::update($key, $name);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400, 'mcpmate_invalid_setting');
        }

        Logger::log([
            'tool'   => 'settings',
            'action' => $key === '' ? 'stock_photos_remove' : 'stock_photos_update',
            'target' => 'stock_photos',
            'args'   => $key === '' ? [] : ['app_name' => $name],
            'result' => Logger::RESULT_OK,
        ]);
        Stats::forget();

        return Response::success(['message' => __('Stock photo settings saved.', 'mcpmate')] + StockPhotoSettings::summary());
    }
}
