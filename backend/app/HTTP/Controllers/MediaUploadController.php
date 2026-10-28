<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Media\MediaSettings;

/**
 * Admin settings: whether the POST /media REST route may be used, and
 * whether it accepts SVG files.
 *
 * AJAX routes (nonce + admin), used by the Settings page and the setup wizard.
 */
final class MediaUploadController
{
    public function show(Request $request): Response
    {
        return Response::success(self::values());
    }

    /**
     * `{enabled?, svg?}`: either switch, or both. At least one is required.
     */
    public function update(Request $request): Response
    {
        // FormData sends booleans as "true" / "false".
        $enabled = self::flag($request, 'enabled');
        $svg     = self::flag($request, 'svg');

        if ($enabled === false || $svg === false || ($enabled === null && $svg === null)) {
            return Response::error(__('The setting is invalid.', 'mcpmate'), 400, 'mcpmate_invalid_setting');
        }

        if ($enabled !== null) {
            MediaSettings::update($enabled['value']);
        }

        if ($svg !== null) {
            MediaSettings::updateSvg($svg['value']);
        }

        return Response::success(['message' => __('Media upload setting saved.', 'mcpmate')] + self::values());
    }

    /**
     * @return array{enabled: bool, svg_enabled: bool}
     */
    private static function values(): array
    {
        return [
            'enabled'     => MediaSettings::isEnabled(),
            'svg_enabled' => MediaSettings::svgEnabled(),
        ];
    }

    /**
     * Null when $key was not sent, false when it is not a boolean.
     *
     * @return array{value: bool}|false|null
     */
    private static function flag(Request $request, string $key)
    {
        $raw = $request->get($key, null);

        if ($raw === null) {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value === null ? false : ['value' => $value];
    }
}
